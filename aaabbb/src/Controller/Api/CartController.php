<?php

namespace App\Controller\Api;

use App\Entity\Cart;
use App\Entity\Customer;
use App\Entity\Product;
use App\Repository\CartRepository;
use App\Repository\ProductRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\QiniuUploadService;
use App\Service\FinancialCalculatorService;
use App\Service\ProductPriceCalculatorService;
use App\Service\RsaCryptoService;
use App\Message\MultiProductOrderProcessingMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop/api/cart', name: 'api_cart_')]
class CartController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CartRepository $cartRepository,
        private ProductRepository $productRepository,
        private QiniuUploadService $qiniuUploadService,
        private FinancialCalculatorService $financialCalculator,
        private ProductPriceCalculatorService $priceCalculator,
        private RsaCryptoService $rsaCryptoService,
        private \App\Service\SiteConfigService $siteConfigService
    ) {
    }

    /**
     * 获取购物车列表
     */
    #[Route('/list', name: 'list', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function list(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }

        $businessType = $data['businessType'] ?? Cart::BUSINESS_TYPE_DROPSHIP;

        // 验证业务类型
        if (!in_array($businessType, [Cart::BUSINESS_TYPE_DROPSHIP, Cart::BUSINESS_TYPE_WHOLESALE])) {
            return $this->json([
                'success' => false,
                'message' => '无效的业务类型',
                'messageEn' => 'Invalid business type'
            ], 400);
        }

        $cartItems = $this->cartRepository->findBy([
            'customer' => $customer,
            'businessType' => $businessType
        ], ['createdAt' => 'DESC']);

        $data = [];
        foreach ($cartItems as $item) {
            $data[] = $this->formatCartItem($item);
        }

        // 计算订单汇总信息（包含费用明细）
        $orderSummary = $this->calculateOrderSummary($data, $customer);
        
        // 为每个商品添加详细价格信息
        $data = $this->enrichCartItemsWithPriceDetails($data, $customer, $orderSummary);

        // 获取网站货币符号
        $siteCurrency = $this->siteConfigService->getConfigValue('site_currency') ?? 'USD';

        return $this->json([
            'success' => true,
            'data' => $data,
            'summary' => $orderSummary,
            'siteCurrency' => $siteCurrency  // 网站货币符号
        ]);
    }

    /**
     * 添加商品到购物车
     */
    #[Route('/add', name: 'add', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function add(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }

        // 验证必填字段
        $requiredFields = ['productId', 'sku', 'region', 'quantity'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return $this->json([
                    'success' => false,
                    'message' => "缺少必填字段: {$field}",
                    'messageEn' => "Missing required field: {$field}"
                ], 400);
            }
        }

        // 查找商品
        $product = $this->productRepository->find($data['productId']);
        if (!$product) {
            return $this->json([
                'success' => false,
                'message' => '商品不存在',
                'messageEn' => 'Product not found'
            ], 404);
        }

        $businessType = $data['businessType'] ?? Cart::BUSINESS_TYPE_DROPSHIP;
        $quantity = max(1, (int)$data['quantity']);

        // 检查是否已存在相同的购物车项（同一商品不同区域算两个不同的项）
        $existingItem = $this->cartRepository->findOneBy([
            'customer' => $customer,
            'businessType' => $businessType,
            'product' => $product,
            'sku' => $data['sku'],
            'region' => $data['region']  // 必须包含区域字段
        ]);

        if ($existingItem) {
            // 如果已存在，更新数量
            $existingItem->setQuantity($existingItem->getQuantity() + $quantity);
            $existingItem->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => '商品已添加到购物车',
                'messageEn' => 'Product added to cart',
                'data' => $this->formatCartItem($existingItem)
            ]);
        }

        // 创建新的购物车项
        $cartItem = new Cart();
        $cartItem->setCustomer($customer);
        $cartItem->setBusinessType($businessType);
        $cartItem->setProduct($product);
        $cartItem->setSku($data['sku']);
        $cartItem->setRegion($data['region']);
        $cartItem->setQuantity($quantity);

        // 设置商品信息快照
        $cartItem->setProductName($product->getTitleEn() ?? $product->getTitle());
        $cartItem->setProductNameCn($product->getTitle());
        
        // 设置商品主图（优先使用缩略图，其次主图）
        if ($product->getThumbnailImage()) {
            $cartItem->setProductImage($product->getThumbnailImage());
        } elseif ($product->getMainImage()) {
            $cartItem->setProductImage($product->getMainImage());
        }

        // 设置价格和库存（这里需要根据实际业务逻辑获取）
        // 暂时使用商品的基础价格
        $cartItem->setSellingPrice($data['sellingPrice'] ?? '0.00');
        $cartItem->setOriginalPrice($data['originalPrice'] ?? null);
        $cartItem->setCurrency($data['currency'] ?? 'USD');
        $cartItem->setAvailableStock($data['availableStock'] ?? 0);

        // 默认选中且有效
        $cartItem->setIsSelected(true);
        $cartItem->setIsAvailable(true);

        $this->entityManager->persist($cartItem);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => '商品已添加到购物车',
            'messageEn' => 'Product added to cart',
            'data' => $this->formatCartItem($cartItem)
        ], 201);
    }

    /**
     * 更新购物车商品数量
     */
    #[Route('/update/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[RequireAuth]
    #[RequireSignature]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $cartItem = $this->cartRepository->find($id);

        if (!$cartItem) {
            return $this->json([
                'success' => false,
                'message' => '购物车项不存在',
                'messageEn' => 'Cart item not found'
            ], 404);
        }

        // 验证所有权
        if ($cartItem->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '无权操作此购物车项',
                'messageEn' => 'Unauthorized to modify this cart item'
            ], 403);
        }

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }

        // 更新数量
        if (isset($data['quantity'])) {
            $quantity = max(1, (int)$data['quantity']);
            
            // 校验最小起订量（从数据库实时获取）
            $product = $cartItem->getProduct();
            $minOrderQty = 1;
            
            if ($product) {
                foreach ($product->getPrices() as $price) {
                    if ($price->getRegion() === $cartItem->getRegion()) {
                        $minOrderQty = $price->getMinWholesaleQuantity() ?? 1;
                        break;
                    }
                }
            }
            
            // 如果数量小于最小起订量，返回错误
            if ($quantity < $minOrderQty) {
                return $this->json([
                    'success' => false,
                    'message' => "最小起订数量为：{$minOrderQty}",
                    'messageEn' => "Minimum order quantity is: {$minOrderQty}",
                    'minOrderQty' => $minOrderQty
                ], 400);
            }
            
            $cartItem->setQuantity($quantity);
        }

        // 更新选中状态
        if (isset($data['isSelected'])) {
            $cartItem->setIsSelected((bool)$data['isSelected']);
        }

        // 更新有效状态
        if (isset($data['isAvailable'])) {
            $cartItem->setIsAvailable((bool)$data['isAvailable']);
        }

        // 更新失效原因
        if (isset($data['unavailableReason'])) {
            $cartItem->setUnavailableReason($data['unavailableReason']);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => '购物车已更新',
            'messageEn' => 'Cart updated successfully',
            'data' => $this->formatCartItem($cartItem)
        ]);
    }

    /**
     * 批量更新购物车项选中状态
     */
    #[Route('/batch-select', name: 'batch_select', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function batchSelect(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }

        if (!isset($data['ids']) || !is_array($data['ids'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少购物车ID列表',
                'messageEn' => 'Missing cart item IDs'
            ], 400);
        }

        $isSelected = $data['isSelected'] ?? true;
        $updatedCount = 0;

        foreach ($data['ids'] as $id) {
            $cartItem = $this->cartRepository->find($id);
            if ($cartItem && $cartItem->getCustomer()->getId() === $customer->getId()) {
                $cartItem->setIsSelected((bool)$isSelected);
                $updatedCount++;
            }
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => "已更新 {$updatedCount} 个购物车项",
            'messageEn' => "Updated {$updatedCount} cart items",
            'data' => [
                'updatedCount' => $updatedCount
            ]
        ]);
    }

    /**
     * 删除购物车商品
     */
    #[Route('/delete/{id}', name: 'delete', methods: ['DELETE'])]
    #[RequireAuth]
    #[RequireSignature]
    public function delete(int $id): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $cartItem = $this->cartRepository->find($id);

        if (!$cartItem) {
            return $this->json([
                'success' => false,
                'message' => '购物车项不存在',
                'messageEn' => 'Cart item not found'
            ], 404);
        }

        // 验证所有权
        if ($cartItem->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '无权删除此购物车项',
                'messageEn' => 'Unauthorized to delete this cart item'
            ], 403);
        }

        $this->entityManager->remove($cartItem);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => '商品已从购物车移除',
            'messageEn' => 'Item removed from cart'
        ]);
    }

    /**
     * 批量删除购物车商品
     */
    #[Route('/batch-delete', name: 'batch_delete', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function batchDelete(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }

        if (!isset($data['ids']) || !is_array($data['ids'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少购物车ID列表',
                'messageEn' => 'Missing cart item IDs'
            ], 400);
        }

        $deletedCount = 0;

        foreach ($data['ids'] as $id) {
            $cartItem = $this->cartRepository->find($id);
            if ($cartItem && $cartItem->getCustomer()->getId() === $customer->getId()) {
                $this->entityManager->remove($cartItem);
                $deletedCount++;
            }
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => "已删除 {$deletedCount} 个购物车项",
            'messageEn' => "Deleted {$deletedCount} cart items",
            'data' => [
                'deletedCount' => $deletedCount
            ]
        ]);
    }

    /**
     * 清空购物车
     */
    #[Route('/clear', name: 'clear', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function clear(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }
        $businessType = $data['businessType'] ?? Cart::BUSINESS_TYPE_DROPSHIP;

        $cartItems = $this->cartRepository->findBy([
            'customer' => $customer,
            'businessType' => $businessType
        ]);

        $count = count($cartItems);

        foreach ($cartItems as $item) {
            $this->entityManager->remove($item);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => "已清空购物车，删除 {$count} 个商品",
            'messageEn' => "Cart cleared, {$count} items removed",
            'data' => [
                'deletedCount' => $count
            ]
        ]);
    }

    /**
     * 获取购物车统计信息
     */
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function summary(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $businessType = $request->query->get('businessType', Cart::BUSINESS_TYPE_DROPSHIP);

        $cartItems = $this->cartRepository->findBy([
            'customer' => $customer,
            'businessType' => $businessType
        ]);

        $totalItems = count($cartItems);
        $totalQuantity = 0;
        $selectedCount = 0;
        $selectedQuantity = 0;
        $totalAmount = '0.00';
        $selectedAmount = '0.00';

        foreach ($cartItems as $item) {
            $totalQuantity += $item->getQuantity();
            
            $itemSubtotal = (string)$item->getSubtotal();
            $totalAmount = $this->financialCalculator->add($totalAmount, $itemSubtotal);
            
            if ($item->isSelected()) {
                $selectedCount++;
                $selectedQuantity += $item->getQuantity();
                $selectedAmount = $this->financialCalculator->add($selectedAmount, $itemSubtotal);
            }
        }

        return $this->json([
            'success' => true,
            'data' => [
                'businessType' => $businessType,
                'totalItems' => $totalItems,
                'totalQuantity' => $totalQuantity,
                'selectedCount' => $selectedCount,
                'selectedQuantity' => $selectedQuantity,
                'totalAmount' => $this->financialCalculator->format($totalAmount),
                'selectedAmount' => $this->financialCalculator->format($selectedAmount),
                'currency' => $cartItems[0]->getCurrency() ?? 'USD'
            ]
        ]);
    }

    /**
     * 格式化购物车项数据
     */
    private function formatCartItem(Cart $item): array
    {
        // 处理商品图片URL - 如果不是完整URL，则生成签名URL
        $productImage = $item->getProductImage();
        if ($productImage && !str_starts_with($productImage, 'http')) {
            // 使用七牛云服务生成带签名的私有URL
            $productImage = $this->qiniuUploadService->getPrivateUrl($productImage);
        }
        
        // 获取最小起订量（根据区域和业务类型从product_price表获取）
        $minOrderQty = 1; // 默认值
        $product = $item->getProduct();
        if ($product) {
            foreach ($product->getPrices() as $price) {
                if ($price->getRegion() === $item->getRegion()) {
                    // 使用 min_wholesale_quantity 作为最小起订量
                    // 无论是一件代发还是批发，都使用该字段
                    $minOrderQty = $price->getMinWholesaleQuantity() ?? 1;
                    break;
                }
            }
        }
        
        return [
            'id' => $item->getId(),
            'businessType' => $item->getBusinessType(),
            'productId' => $item->getProduct()->getId(),
            'sku' => $item->getSku(),
            'region' => $item->getRegion(),
            'productName' => $item->getProductName(),
            'productNameCn' => $item->getProductNameCn(),
            'productImage' => $productImage,
            'sellingPrice' => $item->getSellingPrice(),
            'originalPrice' => $item->getOriginalPrice(),
            'currency' => $item->getCurrency(),
            'quantity' => $item->getQuantity(),
            'minOrderQty' => $minOrderQty, // 添加最小起订量字段
            'availableStock' => $item->getAvailableStock(),
            'isSelected' => $item->isSelected(),
            'isAvailable' => $item->isAvailable(),
            'unavailableReason' => $item->getUnavailableReason(),
            'subtotal' => $item->getSubtotal(),
            'hasEnoughStock' => $item->hasEnoughStock(),
            'createdAt' => $item->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updatedAt' => $item->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 计算订单汇总信息(包含费用明细)
     * 严格按照文档《网站价格及佣金计算.md》第1.2节的计算流程:
     * 1. 售价(已在商品详情页计算)
     * 2. 会员价 = 售价 - (售价 × 会员折扣率)
     * 3. 显示价格 = 会员已登录 ? 会员价 : 售价
     * 4. 小计 = 显示价格 × 数量
     * 5. 满减后金额 = 小计 - 满减金额
     * 6. 运费计算
     * 7. 最终支付价格 = 满减后金额 + 运费
     */
    /**
     * 计算订单汇总信息（使用与ItemDetailController完全一致的价格计算服务）
     */
    private function calculateOrderSummary(array $cartItems, Customer $customer): array
    {
        // 筛选出选中且有效的商品
        $selectedItems = array_filter($cartItems, fn($item) => $item['isSelected'] && $item['isAvailable']);
        
        if (empty($selectedItems)) {
            return [
                'totalItems' => 0,
                'totalQuantity' => 0,
                'selectedCount' => 0,
                'currency' => 'USD',
                'productAmount' => '0.00',
                'priceBreakdown' => [],
                'totalAmount' => '0.00'
            ];
        }
        
        $currency = $selectedItems[array_key_first($selectedItems)]['currency'] ?? 'USD';
        $vipLevel = $customer->getVipLevel() ?? 0;
        
        // 总计
        $totalAmount = '0.00';  // 最终支付金额
        $totalQuantity = 0;
        $totalProductAmount = '0.00';  // 商品总金额（原价×数量）
        
        // 价格明细汇总
        $totalProductDiscount = '0.00'; // 商品折扣总额
        $totalMemberDiscount = '0.00';  // 会员折扣总额
        $totalFullReduction = '0.00';   // 满减优惠总额
        $totalShipping = '0.00';        // 总运费
        
        // 逐个计算每个购物车项的价格
        foreach ($selectedItems as $item) {
            $productId = $item['productId'];
            $region = $item['region'];
            $quantity = (int)$item['quantity'];
            $totalQuantity += $quantity;
            
            // 从数据库获取商品的真实价格
            $product = $this->productRepository->createQueryBuilder('p')
                ->leftJoin('p.prices', 'prices')
                ->addSelect('prices')
                ->leftJoin('p.shippings', 'shippings')
                ->addSelect('shippings')
                ->leftJoin('p.discountRules', 'discountRules')
                ->addSelect('discountRules')
                ->where('p.id = :productId')
                ->setParameter('productId', $productId)
                ->getQuery()
                ->getOneOrNullResult();
            
            if (!$product) {
                continue;
            }
            
            // 获取价格信息（匹配区域和业务类型）
            $businessType = $item['businessType'] ?? 'dropship';
            $regionPrice = null;
            foreach ($product->getPrices() as $price) {
                if ($price->getRegion() === $region && 
                    $price->getBusinessType() === $businessType && 
                    $price->isActive()) {
                    $regionPrice = $price;
                    break;
                }
            }
            
            if (!$regionPrice) {
                continue;
            }
            
            // 获取运费信息
            $regionShipping = null;
            foreach ($product->getShippings() as $shipping) {
                if ($shipping->getRegion() === $region) {
                    $regionShipping = $shipping;
                    break;
                }
            }
            
            // 计算总运费
            $shippingPrice = $regionShipping ? (string)$regionShipping->getShippingPrice() : '0';
            $itemShippingFee = $shippingPrice;
            
            if ($regionShipping && $quantity > 1) {
                $additionalPrice = (string)($regionShipping->getAdditionalPrice() ?? '0');
                if ($this->priceCalculator->pricesEqual($additionalPrice, '0') === false) {
                    $additionalCount = $quantity - 1;
                    $additionalShippingFee = $this->priceCalculator->calculateSubtotal($additionalPrice, $additionalCount);
                    $itemShippingFee = $this->priceCalculator->addShippingFee($shippingPrice, $additionalShippingFee);
                }
            }
            
            $totalShipping = $this->financialCalculator->add($totalShipping, $itemShippingFee);
            
            // 获取会员折扣率
            $sellingPrice = (string)$regionPrice->getSellingPrice();
            $memberDiscounts = $regionPrice->getMemberDiscount();
            $memberDiscountRate = 0;
            
            if ($memberDiscounts && isset($memberDiscounts[(string)$vipLevel])) {
                $memberDiscountRate = (float)$memberDiscounts[(string)$vipLevel];
            }
            
            // 获取满减信息
            $regionDiscountRule = $product->getDiscountRuleByRegion($region);
            $minAmount = null;
            $discountAmount = null;
            if ($regionDiscountRule && $regionDiscountRule->isCurrentlyValid()) {
                $minAmount = (string)$regionDiscountRule->getMinAmount();
                $discountAmount = (string)$regionDiscountRule->getDiscountAmount();
            }
            
            // 使用价格计算服务计算总价（与ItemDetailController完全一致）
            $priceResult = $this->priceCalculator->calculateTotalPrice([
                'sellingPrice' => $sellingPrice,
                'memberDiscountRate' => $memberDiscountRate,
                'quantity' => $quantity,
                'minAmount' => $minAmount,
                'discountAmount' => $discountAmount,
                'shippingFee' => $itemShippingFee,
                'shippingMethod' => 'STANDARD_SHIPPING',
                'currency' => $regionPrice->getCurrency()
            ]);
            
            // 累加总金额（最终支付价格）
            $totalAmount = $this->financialCalculator->add($totalAmount, $priceResult['totalPrice']);
            
            // 累加商品折扣和商品总金额（原价×数量）
            $originalPrice = $regionPrice->getOriginalPrice();
            $discountRate = $regionPrice->getDiscountRate();
            
            // 计算商品总金额：原价 × 数量
            if ($originalPrice) {
                $itemProductAmount = $this->financialCalculator->multiply(
                    $originalPrice,
                    (string)$quantity
                );
                $totalProductAmount = $this->financialCalculator->add($totalProductAmount, $itemProductAmount);
            }
            
            // 计算商品折扣：(原价 - 售价) × 数量
            if ($originalPrice && $discountRate && (float)$originalPrice > (float)$sellingPrice) {
                $productDiscountAmount = $this->financialCalculator->multiply(
                    $this->financialCalculator->subtract($originalPrice, $sellingPrice),
                    (string)$quantity
                );
                if ((float)$productDiscountAmount > 0) {
                    $totalProductDiscount = $this->financialCalculator->add($totalProductDiscount, $productDiscountAmount);
                }
            }
            
            // 累加会员折扣
            if ($memberDiscountRate > 0) {
                $itemMemberDiscount = $this->financialCalculator->multiply(
                    $this->financialCalculator->multiply($sellingPrice, (string)$memberDiscountRate),
                    (string)$quantity
                );
                $totalMemberDiscount = $this->financialCalculator->add($totalMemberDiscount, $itemMemberDiscount);
            }
            
            // 累加满减优惠
            if ($discountAmount && (float)$discountAmount > 0) {
                // 计算商品小计（用于判断是否满足满减条件）
                $itemSubtotal = $priceResult['subtotal'];
                if ($this->financialCalculator->compare($itemSubtotal, $minAmount) >= 0) {
                    $totalFullReduction = $this->financialCalculator->add($totalFullReduction, $discountAmount);
                }
            }
        }
        
        // 构建价格明细
        $priceBreakdown = [];
        
        // 1. 商品折扣
        if ((float)$totalProductDiscount > 0) {
            $priceBreakdown[] = [
                'label' => '商品折扣',
                'amount' => '-' . $this->financialCalculator->format($totalProductDiscount),
                'currency' => $currency
            ];
        }
        
        // 2. 会员折扣
        if ((float)$totalMemberDiscount > 0) {
            $priceBreakdown[] = [
                'label' => '会员折扣',
                'amount' => '-' . $this->financialCalculator->format($totalMemberDiscount),
                'currency' => $currency
            ];
        }
        
        // 3. 满减优惠
        if ((float)$totalFullReduction > 0) {
            $priceBreakdown[] = [
                'label' => '满减优惠',
                'amount' => '-' . $this->financialCalculator->format($totalFullReduction),
                'currency' => $currency
            ];
        }
        
        // 4. 运费
        if ((float)$totalShipping > 0) {
            $priceBreakdown[] = [
                'label' => '运费',
                'amount' => $this->financialCalculator->format($totalShipping),
                'currency' => $currency
            ];
        }
        
        return [
            'totalItems' => count($selectedItems),
            'totalQuantity' => $totalQuantity,
            'selectedCount' => count($selectedItems),
            'currency' => $currency,
            'productAmount' => $this->financialCalculator->format($totalProductAmount),  // 商品总金额（原价×数量）
            'priceBreakdown' => $priceBreakdown,
            'totalAmount' => $this->financialCalculator->format($totalAmount)  // 最终支付金额
        ];
    }
    
    /**
     * 为每个购物车商品添加详细价格信息（会员折扣、商品折扣、满减、运费）
     */
    private function enrichCartItemsWithPriceDetails(array $cartItems, Customer $customer, array $orderSummary): array
    {
        $vipLevel = $customer->getVipLevel() ?? 0;
        $selectedItems = array_filter($cartItems, fn($item) => $item['isSelected'] && $item['isAvailable']);
        
        if (empty($selectedItems)) {
            return $cartItems;
        }
        
        $currency = $selectedItems[array_key_first($selectedItems)]['currency'] ?? 'USD';
        
        // 为每个商品添加价格详情
        $enrichedItems = [];
        foreach ($cartItems as $index => $item) {
            $productId = $item['productId'];
            $region = $item['region'];
            $quantity = (int)$item['quantity'];
            $sellingPrice = (string)$item['sellingPrice'];
            $originalPrice = $item['originalPrice'] ? (string)$item['originalPrice'] : null;
            
            // 1. 计算商品折扣（基于商品的discountRate，而非简单的原价-售价）
            $productDiscount = '0.00';
            $product = $this->productRepository->find($productId);
            if ($product) {
                // 从数据库获取该区域的价格配置
                $regionPrice = null;
                foreach ($product->getPrices() as $price) {
                    if ($price->getRegion() === $region) {
                        $regionPrice = $price;
                        break;
                    }
                }
                
                if ($regionPrice) {
                    $dbOriginalPrice = (string)$regionPrice->getOriginalPrice();
                    $discountRate = $regionPrice->getDiscountRate();
                    
                    // 如果有商品折扣率，计算商品折扣 = 原价 × 折扣率
                    if ($discountRate && $this->financialCalculator->compare($discountRate, '0') > 0) {
                        $productDiscount = $this->financialCalculator->multiply($dbOriginalPrice, (string)$discountRate);
                    }
                }
            }
            
            // 2. 计算会员折扣
            $memberDiscount = '0.00';
            if ($vipLevel > 0 && $product) {
                $regionPrice = null;
                foreach ($product->getPrices() as $price) {
                    if ($price->getRegion() === $region) {
                        $regionPrice = $price;
                        break;
                    }
                }
                
                if ($regionPrice) {
                    $memberDiscounts = $regionPrice->getMemberDiscount();
                    if ($memberDiscounts && isset($memberDiscounts[(string)$vipLevel])) {
                        $memberDiscountRate = (float)$memberDiscounts[(string)$vipLevel];
                        // 会员折扣基于售价计算
                        $dbSellingPrice = (string)$regionPrice->getSellingPrice();
                        $vipPrice = $this->priceCalculator->calculateMemberPrice($dbSellingPrice, $memberDiscountRate);
                        $memberDiscount = $this->financialCalculator->subtract($dbSellingPrice, $vipPrice);
                    }
                }
            }
            
            // 3. 计算该商品的满减优惠（每个商品单独计算）
            $itemFullReduction = '0.00';
            if ($item['isSelected'] && $product) {
                $regionPrice = null;
                foreach ($product->getPrices() as $price) {
                    if ($price->getRegion() === $region) {
                        $regionPrice = $price;
                        break;
                    }
                }
                
                if ($regionPrice) {
                    // 计算该商品的会员价
                    $sellingPrice = (string)$regionPrice->getSellingPrice();
                    $displayPrice = $sellingPrice;
                    
                    if ($vipLevel > 0) {
                        $memberDiscounts = $regionPrice->getMemberDiscount();
                        if ($memberDiscounts && isset($memberDiscounts[(string)$vipLevel])) {
                            $memberDiscountRate = (float)$memberDiscounts[(string)$vipLevel];
                            $displayPrice = $this->priceCalculator->calculateMemberPrice($sellingPrice, $memberDiscountRate);
                        }
                    }
                    
                    // 计算该商品的小计金额
                    $itemSubtotal = $this->priceCalculator->calculateSubtotal($displayPrice, $quantity);
                    
                    // 获取满减规则
                    $discountRule = $product->getDiscountRuleByRegion($region);
                    if ($discountRule && $discountRule->isCurrentlyValid()) {
                        $minAmount = (string)($discountRule->getMinAmount() ?? '0');
                        $discountAmount = (string)($discountRule->getDiscountAmount() ?? '0');
                        
                        // 判断该商品的小计金额是否达到满减门槛
                        if ($this->financialCalculator->compare($minAmount, '0.00') > 0 && 
                            $this->financialCalculator->compare($discountAmount, '0.00') > 0 && 
                            $this->financialCalculator->compare($itemSubtotal, $minAmount) >= 0) {
                            $itemFullReduction = $discountAmount;
                        }
                    }
                }
            }
            
            // 4. 运费（每个商品独立计算）
            $shippingFee = '0.00';
            if ($product) {
                $firstItemFee = '0.00';
                $additionalItemFee = '0.00';
                
                // 获取该商品在该区域的运费配置
                foreach ($product->getShippings() as $shipping) {
                    if ($shipping->getRegion() === $region) {
                        $firstItemFee = (string)($shipping->getShippingPrice() ?? '0.00');
                        $additionalItemFee = (string)($shipping->getAdditionalPrice() ?? '0.00');
                        break;
                    }
                }
                
                // 计算该商品的运费：首件 + 续件 × (数量 - 1)
                if ($quantity <= 1) {
                    $shippingFee = $firstItemFee;
                } else {
                    $additionalItems = $quantity - 1;
                    $additionalCost = $this->priceCalculator->calculateSubtotal($additionalItemFee, $additionalItems);
                    $shippingFee = $this->financialCalculator->add($firstItemFee, $additionalCost);
                }
            }
            
            // 添加到商品数据中
            $enrichedItems[] = array_merge($item, [
                'productDiscount' => $this->financialCalculator->format($productDiscount),
                'memberDiscount' => $this->financialCalculator->format($memberDiscount),
                'fullReduction' => $this->financialCalculator->format($itemFullReduction),
                'shippingFee' => $this->financialCalculator->format($shippingFee)
            ]);
        }
        
        return $enrichedItems;
    }

    /**
     * 购物车结算
     * 安全措施：
     * 1. 用户认证 - RequireAuth 防止未授权访问
     * 2. API签名 - RequireSignature 防止参数篡改和重放攻击
     * 3. RSA加密 - 敏感数据加密传输
     * 4. 异步处理 - 使用Symfony Messenger异步处理所有业务逻辑
     */
    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature(message: '结算请求签名验证失败，请重试', messageEn: 'Checkout request signature verification failed, please try again', checkNonce: true)]
    public function checkout(
        Request $request,
        RsaCryptoService $rsaCrypto,
        MessageBusInterface $messageBus
    ): JsonResponse {
        try {
            /** @var Customer $customer */
            $customer = $this->getUser();
            
            // 获取请求数据
            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象（更安全，参数名和值都被加密）
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $rsaCrypto->decryptObject($requestData['encryptedPayload']);
                } else {
                    // 向下兼容：如果没有encryptedPayload，尝试解密单个字段
                    $data = $requestData;
                    if (isset($data['paymentMethod'])) {
                        $data['paymentMethod'] = $rsaCrypto->decrypt($data['paymentMethod']);
                    }
                    if (isset($data['customerId']) && $data['customerId'] !== null) {
                        $decrypted = $rsaCrypto->decrypt($data['customerId']);
                        $data['customerId'] = (int)$decrypted;
                    }
                    if (isset($data['totalAmount'])) {
                        $decrypted = $rsaCrypto->decrypt($data['totalAmount']);
                        $data['totalAmount'] = $decrypted;
                    }
                }
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '请求失败，请重新登录后再试',
                    'messageEn' => 'Request failed, please log in again and try'
                ], 400);  // 解密失败是业务错误，返回 400
            }
            
            // 基本参数验证
            $businessType = $data['businessType'] ?? Cart::BUSINESS_TYPE_DROPSHIP;
            $items = $data['items'] ?? [];
            $paymentMethod = $data['paymentMethod'] ?? null;
            $totalAmount = $data['totalAmount'] ?? null;
            $currency = $data['currency'] ?? 'USD';
            $customerId = $data['customerId'] ?? $customer->getId();
            $frontendOrderNo = $data['orderNo'] ?? null;
            $addressId = $data['addressId'] ?? null; // 收货地址ID
            // dd($data);
            // 验证必须参数（支付方式可以为空，等待订单生成后再填写）
            if (empty($items) || $totalAmount === null || !$customerId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '缺少必须参数',
                    'messageEn' => 'Missing required parameters'
                ], 400);
            }
            
            // 验证业务类型
            if (!in_array($businessType, [Cart::BUSINESS_TYPE_DROPSHIP, Cart::BUSINESS_TYPE_WHOLESALE])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '无效的业务类型',
                    'messageEn' => 'Invalid business type'
                ], 400);
            }
            
            // 使用前端传入的订单号，如果没有则生成一个
            $orderNo = $frontendOrderNo ?: ('ORD' . date('Ymd') . strtoupper(substr(uniqid(), -6)));
            
            // 构建订单数据
            $orderItems = [];
            foreach ($items as $item) {
                $orderItems[] = [
                    'product_id' => $item['productId'],
                    'quantity' => $item['quantity'],
                    'region' => $item['region'],
                    'sku' => $item['sku'] ?? '',
                    'business_type' => $item['businessType'] ?? $businessType  // 从前端传入的业务类型
                ];
            }
            
            // 立即发送到消息队列
            $messageBus->dispatch(new MultiProductOrderProcessingMessage(
                $orderNo,
                [
                    'customer_id' => $customerId,
                    'business_type' => $businessType,
                    'items' => $orderItems,
                    'total_amount' => $totalAmount,
                    'currency' => $currency,
                    'payment_method' => $paymentMethod,
                    'address_id' => $addressId, // 传递地址ID
                ]
            ));
            
            // 记录日志
            error_log("[CartCheckout] 订单消息已发送到队列: {$orderNo}");
            
            // 立即返回订单号给前端
            return new JsonResponse([
                'success' => true,
                'message' => '订单创建成功，正在处理中...',
                'messageEn' => 'Order created successfully, processing...',
                'data' => [
                    'orderNo' => $orderNo,
                    'status' => 'processing',
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '系统错误：' . $e->getMessage(),
                'messageEn' => 'System error: ' . $e->getMessage()
            ], 500);
        }
    }
}
