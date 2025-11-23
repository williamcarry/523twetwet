<?php

namespace App\Controller\Api;

use App\Entity\Product;
use App\Entity\ProductLogisticsPaymentInfo;
use App\Entity\ProductShipping;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Message\MultiProductOrderProcessingMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Repository\ProductRepository;
use App\Service\QiniuUploadService;
use App\Service\FinancialCalculatorService;
use App\Service\ProductPriceCalculatorService;
use App\Service\RsaCryptoService;
use App\Service\SiteConfigService;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

/**
 * 商品详情API控制器
 * 对接 ItemDetailPage.vue 页面的所有数据需求
 */
#[Route('/shop/api/item-detail', name: 'api_shop_item_detail_')]
class ItemDetailController extends AbstractController
{
     /**
     * 从Redis获取数据
     * 
     * @param string $key Redis键名
     * @return array|null 解析后的数据数组，如果获取失败或数据不存在则返回null
     */
    private function getFromRedis(string $key): ?array
    {
        try {
            // 检查Redis扩展是否可用
            if (!extension_loaded('redis')) {
                return null;
            }
            
            // 检查Redis类是否存在
            if (!class_exists('\Redis')) {
                return null;
            }
            
            // 创建Redis连接
            /** @var \Redis $redis */
            $redis = new \Redis();
            
            // 从环境变量读取Redis配置
            $redisUrl = $_ENV['REDIS_KHUMFG'] ?? 'redis://localhost:6379';
            $parsedUrl = parse_url($redisUrl);
            
            $host = $parsedUrl['host'] ?? 'localhost';
            $port = $parsedUrl['port'] ?? 6379;
            $password = isset($parsedUrl['pass']) ? urldecode($parsedUrl['pass']) : null;
            
            // 连接到Redis服务器
            $redis->connect($host, $port);
            // 如果有密码，需要认证
            if ($password) {
                $redis->auth($password);
            }
            
            // 获取数据
            $data = $redis->get($key);
            $redis->close();
            
            // 如果数据存在，解析JSON
            if ($data !== false) {
                $decodedData = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decodedData;
                }
            }
            
            return null;
        } catch (\Exception $e) {
            // Redis连接或操作失败，返回null
            return null;
        }
    }

    /**
     * 获取商品详情
     * 完整对接 ItemDetailPage.vue 的数据需求
     */
    #[Route('/product/{id}', name: 'product', methods: ['GET'])]
    public function getProductDetail(
        string $id, 
        Request $request,
        EntityManagerInterface $entityManager,
        ProductRepository $productRepository,
        QiniuUploadService $qiniuService,
        SiteConfigService $siteConfigService
    ): JsonResponse {
        // 从请求头获取语言参数
        $lang = $request->headers->get('Accept-Language', 'zh-CN');
        // 从 Redis 获取菜单分类数据（三级嵌套结构）
        $homeMenuData = $this->getFromRedis('homemenu');
        $categories = $homeMenuData['categories'] ?? [];

        // 从数据库获取商品数据，只获取状态为 approved 的已上架商品
        $product = $productRepository->findOneBy([
            'id' => $id,
            'status' => 'approved'
        ]);
        
        if (!$product) {
            return new JsonResponse([
                'success' => false,
                'message' => '商品不存在或未上架',
                'messageEn' => 'Product not found or not available'
            ], 404);
        }

        // 获取主图（带签名）
        $mainImageUrl = '';
        if ($product->getMainImage()) {
            $mainImageUrl = $qiniuService->getPrivateUrl($product->getMainImage());
        }
        
        // 获取副图数组（主图 + 可作为主图的详情图，最多10张）
        $images = $this->formatImages($product, $qiniuService);
        
        // 获取所有详情图（已签名）
        $detailImages = $this->formatDetailImages($product, $qiniuService);

        // 获取库存信息（汇总所有区域的库存）
        $totalStock = 0;
        $warehouseType = '';
        foreach ($product->getShippings() as $shipping) {
            $totalStock += $shipping->getAvailableStock();
            if (empty($warehouseType)) {
                $warehouseType = $shipping->getWarehouseName();
            }
        }

        // 构建多区域配置数据（价格、库存、满减）
        $regionConfigs = $this->buildRegionConfigs($product);
        
        // 获取首个发货区域的默认数据
        $shippingRegions = $product->getShippingRegions() ?? [];
        $primaryRegion = !empty($shippingRegions) ? $shippingRegions[0] : null;
        $primaryData = $primaryRegion && isset($regionConfigs[$primaryRegion]) 
            ? $regionConfigs[$primaryRegion] 
            : [
                'price' => ['originalPrice' => '0.0000', 'sellingPrice' => '0.0000', 'currency' => 'CNY', 'discountRate' => null],
                'stock' => 0,
                'discountRule' => null
            ];
        
        $currency = $primaryData['price']['currency'] ?? 'CNY';
        $originalPrice = $primaryData['price']['originalPrice'] ?? '0.0000';
        $sellingPrice = $primaryData['price']['sellingPrice'] ?? '0.0000';
        $basePrice = (float)$sellingPrice;

        // 组装商品数据（完整对接前端需求）
        $productData = [
            // 基本信息
            'id' => $product->getId(),
            'status' => $product->getStatus(),
            'sku' => $product->getSku(),
            'spu' => $product->getSpu(),
            'title' => $product->getTitle(),
            'titleEn' => $product->getTitleEn(),
            'publishDate' => $product->getPublishDate() ? $product->getPublishDate()->format('Y-m-d') : '',
            
            // 分类信息（用于面包屑导航）- 返回完整的分类路径
            'category1' => null,
            'category2' => null,
            'category3' => null,
            
            // 图片信息（已签名）
            'mainImage' => $mainImageUrl,
            'images' => $images,
            'detailImages' => $detailImages,
            
            // 默认显示数据（首个发货区域）
            'stock' => $primaryData['stock'],
            'currency' => $currency,
            'originalPrice' => $originalPrice,
            'sellingPrice' => $sellingPrice,
            'basePrice' => $basePrice,
            
            // 满减活动信息（首个发货区域）
            'enableDiscount' => $primaryData['discountRule'] !== null,
            'minAmount' => $primaryData['discountRule'] ? (float)$primaryData['discountRule']['minAmount'] : null,
            'discountAmount' => $primaryData['discountRule'] ? (float)$primaryData['discountRule']['discountAmount'] : null,
            
            // 支持的服务类型
            'supportDropship' => $product->isSupportDropship() ? 1 : 0,
            'supportWholesale' => $product->isSupportWholesale() ? 1 : 0,
            'supportCircle_buy' => $product->isSupportCircleBuy() ? 1 : 0,
            'supportSelf_pickup' => $product->isSupportSelfPickup() ? 1 : 0,
            
            // 发货区域信息（JSON数组）
            'shippingRegion' => $shippingRegions,
            
            // 多区域配置数据（价格、库存、满减）
            'regionConfigs' => $regionConfigs,
            
            // 商品尺寸和重量
            'length' => $product->getLength() ? (float)$product->getLength() : null,
            'width' => $product->getWidth() ? (float)$product->getWidth() : null,
            'height' => $product->getHeight() ? (float)$product->getHeight() : null,
            'weight' => $product->getWeight() ? (float)$product->getWeight() : null,
            
            // 详细信息
            'richContent' => $product->getRichContent() ?? '',
            
            // 相关商品推荐（同二级分类下销量最高的6个商品）
            'relatedProducts' => $this->getRelatedProducts($product, $productRepository, $qiniuService)
        ];

        // 填充分类信息（一级、二级、三级）
        $subcategory = $product->getSubcategory();
        if ($subcategory) {
            // 二级分类
            $productData['category2'] = [
                'id' => $subcategory->getId(),
                'name' => $subcategory->getTitle(),
                'nameEn' => $subcategory->getTitleEn()
            ];
            
            // 一级分类
            $category = $subcategory->getCategory();
            if ($category) {
                $productData['category1'] = [
                    'id' => $category->getId(),
                    'name' => $category->getTitle(),
                    'nameEn' => $category->getTitleEn()
                ];
            }
        }
        
        // 三级分类
        $item = $product->getItem();
        if ($item) {
            $productData['category3'] = [
                'id' => $item->getId(),
                'name' => $item->getTitle(),
                'nameEn' => $item->getTitleEn()
            ];
        }

        // 获取物流与支付信息（最新一条记录）
        $logisticsPaymentInfo = $entityManager
            ->getRepository(ProductLogisticsPaymentInfo::class)
            ->findOneBy([], ['id' => 'DESC']);

        // 获取网站货币符号
        $siteCurrency = $siteConfigService->getConfigValue('site_currency') ?? 'USD';

        return new JsonResponse([
            'success' => true,
            'product' => $productData,
            'plinfo' => [
                'id' => $logisticsPaymentInfo ? $logisticsPaymentInfo->getId() : null,
                'content' => $logisticsPaymentInfo ? $logisticsPaymentInfo->getContent() : '',
                'contentEN' => $logisticsPaymentInfo ? $logisticsPaymentInfo->getContentEn() : ''
            ],
            'categories' => $categories,
            'siteCurrency' => $siteCurrency  // 网站货币符号
        ]);
    }

    /**
     * 格式化商品副图列表（主图 + 可作为主图的详情图，最多10张）
     * 第1张：主图（mainImage）
     * 其余：从 detailImages 中筛选 canBeMain=true 的图片
     * 所有图片URL都经过七牛云签名
     */
    private function formatImages(Product $product, QiniuUploadService $qiniuService): array
    {
        $images = [];
        $imageId = 0;

        // 第1张：主图（必须有）
        if ($product->getMainImage()) {
            $images[] = [
                'id' => $imageId++,
                'url' => $qiniuService->getPrivateUrl($product->getMainImage()),
                'alt' => $product->getTitle() ?? ''
            ];
        }

        // 后续图片：从详情图中筛选 canBeMain=true 的图片
        $detailImages = $product->getDetailImages();
        if ($detailImages && is_array($detailImages)) {
            foreach ($detailImages as $detailImage) {
                // 只添加 canBeMain=true 的图片
                if (isset($detailImage['canBeMain']) && $detailImage['canBeMain'] === true) {
                    if (isset($detailImage['key']) && !empty($detailImage['key'])) {
                        $images[] = [
                            'id' => $imageId++,
                            'url' => $qiniuService->getPrivateUrl($detailImage['key']),
                            'alt' => $product->getTitle() ?? ''
                        ];
                    }
                }

                // 最多10张图片（包括主图）
                if ($imageId >= 10) {
                    break;
                }
            }
        }

        return $images;
    }

    /**
     * 获取相关商品推荐
     * 规则：同二级分类下，销量最高的7个商品（排除当前商品）
     */
    private function getRelatedProducts(
        Product $currentProduct,
        ProductRepository $productRepository,
        QiniuUploadService $qiniuService
    ): array {
        $subcategory = $currentProduct->getSubcategory();
        if (!$subcategory) {
            return [];
        }

        // 查询同二级分类下的商品，按销量排序
        $relatedProductEntities = $productRepository->createQueryBuilder('p')
            ->where('p.subcategory = :subcategory')
            ->andWhere('p.status = :status')
            ->andWhere('p.id != :currentId')
            ->setParameter('subcategory', $subcategory)
            ->setParameter('status', 'approved')
            ->setParameter('currentId', $currentProduct->getId())
            ->orderBy('p.salesCount', 'DESC')
            ->setMaxResults(7)
            ->getQuery()
            ->getResult();

        $relatedProducts = [];
        foreach ($relatedProductEntities as $product) {
            // 获取缩略图（如果没有缩略图，使用主图）
            $thumbnailKey = $product->getThumbnailImage() ?: $product->getMainImage();
            $thumbnailUrl = $thumbnailKey ? $qiniuService->getPrivateUrl($thumbnailKey) : '';

            $relatedProducts[] = [
                'id' => $product->getId(),
                'title' => $product->getTitle(),
                'titleEn' => $product->getTitleEn(),
                'thumbnailImage' => $thumbnailUrl
            ];
        }

        return $relatedProducts;
    }

    /**
     * 格式化所有详情图（返回签名后的URL）
     * 用于商品详情页面底部的详细信息图片展示
     */
    private function formatDetailImages(Product $product, QiniuUploadService $qiniuService): array
    {
        $detailImagesData = [];
        $detailImages = $product->getDetailImages();
        
        if ($detailImages && is_array($detailImages)) {
            foreach ($detailImages as $detailImage) {
                if (isset($detailImage['key']) && !empty($detailImage['key'])) {
                    $detailImagesData[] = [
                        'key' => $detailImage['key'],
                        'url' => $qiniuService->getPrivateUrl($detailImage['key']),
                        'canBeMain' => $detailImage['canBeMain'] ?? false
                    ];
                }
            }
        }
        
        return $detailImagesData;
    }
    
    /**
     * 构建多区域配置数据
     * 返回格式: ['CN' => ['price' => [...], 'stock' => 100, 'discountRule' => [...], 'shippingAddress' => '...', 'returnAddress' => '...'], ...]
     * 
     * 注意：由于同一区域可能有多个业务类型的价格（dropship、wholesale），
     * 这里只返回第一个找到的价格配置作为默认显示。
     * 前端会根据用户选择的业务类型来获取对应的价格。
     */
    private function buildRegionConfigs(Product $product): array
    {
        $shippingRegions = $product->getShippingRegions() ?? [];
        $regionConfigs = [];
        
        foreach ($shippingRegions as $region) {
            // 获取该区域的所有价格信息（可能有多个业务类型）
            $regionPrices = [];
            foreach ($product->getPrices() as $price) {
                if ($price->getRegion() === $region && $price->isActive()) {
                    $regionPrices[] = $price;
                }
            }
            
            // 如果没有价格配置，跳过该区域
            if (empty($regionPrices)) {
                continue;
            }
            
            // 优先使用 dropship 类型的价格，如果没有则使用第一个价格
            $regionPrice = null;
            foreach ($regionPrices as $price) {
                if ($price->getBusinessType() === 'dropship') {
                    $regionPrice = $price;
                    break;
                }
            }
            // 如果没有 dropship 类型，使用第一个价格
            if (!$regionPrice) {
                $regionPrice = $regionPrices[0];
            }
            
            // 获取该区域的库存和地址信息（从shippings表）
            $regionStock = 0;
            $shippingAddress = null;
            $returnAddress = null;
            $shippingPrice = null;
            $additionalPrice = null;  // 续件运费
            foreach ($product->getShippings() as $shipping) {
                if ($shipping->getRegion() === $region) {
                    $regionStock = $shipping->getAvailableStock() ?? 0;
                    $shippingAddress = $shipping->getShippingAddress();
                    $returnAddress = $shipping->getReturnAddress();
                    $shippingPrice = $shipping->getShippingPrice();
                    $additionalPrice = $shipping->getAdditionalPrice();  // 获取续件运费
                    break;
                }
            }
            
            // 获取该区域的满减规则
            $regionDiscountRule = $product->getDiscountRuleByRegion($region);
            $discountRuleData = null;
            if ($regionDiscountRule && $regionDiscountRule->isCurrentlyValid()) {
                $discountRuleData = [
                    'minAmount' => $regionDiscountRule->getMinAmount(),
                    'discountAmount' => $regionDiscountRule->getDiscountAmount(),
                    'description' => $regionDiscountRule->getDescription(),
                    'currency' => $regionDiscountRule->getCurrency()
                ];
            }
            
            // 获取会员折扣信息（memberDiscount是JSON格式）
            $memberDiscounts = $regionPrice ? $regionPrice->getMemberDiscount() : null;
            
            // 将会员折扣转换为前端需要的格式：数组形式，每个等级包含 vipLevel、price、discount
            $vipPrices = [];
            if ($memberDiscounts && $regionPrice) {
                $baseSellingPrice = (float)$regionPrice->getSellingPrice();
                $currency = $regionPrice->getCurrency();
                
                // 遍历所有6个会员等级（0-5）
                for ($vipLevel = 0; $vipLevel <= 5; $vipLevel++) {
                    $memberDiscountRate = isset($memberDiscounts[(string)$vipLevel]) 
                        ? (float)$memberDiscounts[(string)$vipLevel] 
                        : 0.0;
                    
                    // 计算会员价格：售价 - (售价 × 会员折扣率)
                    $vipPrice = $baseSellingPrice - ($baseSellingPrice * $memberDiscountRate);
                    
                    // 计算折扣：(1 - 会员折扣率) × 10
                    $discount = (1 - $memberDiscountRate) * 10;
                    
                    $vipPrices[] = [
                        'vipLevel' => $vipLevel,
                        'price' => number_format($vipPrice, 2, '.', ''),
                        'discount' => number_format($discount, 1, '.', ''),  // 保留1位小数
                        'currency' => $currency
                    ];
                }
            }
            
            // 构建该区域的配置
            $regionConfigs[$region] = [
                'price' => [
                    'originalPrice' => $regionPrice ? $regionPrice->getOriginalPrice() : null,
                    'sellingPrice' => $regionPrice ? $regionPrice->getSellingPrice() : null,
                    'discountRate' => $regionPrice ? $regionPrice->getDiscountRate() : null,
                    'currency' => $regionPrice ? $regionPrice->getCurrency() : 'USD',
                    'businessType' => $regionPrice ? $regionPrice->getBusinessType() : 'dropship',  // 添加业务类型字段
                    'vipPrices' => $vipPrices,  // 添加会员价格数组
                ],
                'stock' => $regionStock,
                'minOrderQty' => $regionPrice ? ($regionPrice->getMinWholesaleQuantity() ?? 1) : 1,  // 最小起订量
                'shipping' => [
                    'shippingPrice' => $shippingPrice,      // 首件运费
                    'additionalPrice' => $additionalPrice,  // 续件运费
                ],
                'discountRule' => $discountRuleData,
                'shippingAddress' => $shippingAddress,
                'returnAddress' => $returnAddress,
            ];
        }
        
        return $regionConfigs;
    }

    /**
     * 计算商品价格明细（用于立即购买弹窗显示）
     * 使用与订单处理完全一致的价格计算逻辑
     * 
     * 注意：此接口需要用户登录，以获取正确的会员折扣
     * 
     * 安全措施：
     * 1. 用户认证 - RequireAuth 确保用户已登录
     * 2. API签名 - RequireSignature 防止参数篡改
     * 3. RSA加密 - 敏感数据加密传输
     */
    #[Route('/calculate-price', name: 'calculate_price', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature(message: '价格计算请求签名验证失败', messageEn: 'Price calculation request signature verification failed', checkNonce: true)]
    public function calculatePrice(
        Request $request,
        RsaCryptoService $rsaCrypto,
        EntityManagerInterface $entityManager,
        ProductRepository $productRepository,
        ProductPriceCalculatorService $priceCalculator,
        FinancialCalculatorService $financialCalculator,
        LoggerInterface $logger
    ): JsonResponse {
        // 获取当前登录用户（必须登录）
        /** @var Customer|null $user */
        $user = $this->getUser();
        
        // 调试日志：检查用户是否已认证
        $logger->info('[PriceCalculation] 用户认证状态', [
            'user_is_null' => $user === null,
            'user_id' => $user ? $user->getId() : 'NULL',
            'user_class' => $user ? get_class($user) : 'NULL'
        ]);
        
        try {
            // 获取请求数据
            $requestData = json_decode($request->getContent(), true);
            
            // 解密数据
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $rsaCrypto->decryptObject($requestData['encryptedPayload']);
                } else {
                    $data = $requestData;
                    if (isset($data['productId'])) {
                        $data['productId'] = (int)$rsaCrypto->decrypt($data['productId']);
                    }
                }
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '请求失败，请重试',
                    'messageEn' => 'Request failed, please try again'
                ], 400);  // 解密失败是业务错误，返回 400
            }
            
            // 验证必须参数
            $productId = $data['productId'] ?? null;
            $region = $data['region'] ?? null;
            $quantity = $data['quantity'] ?? null;
            $businessType = $data['businessType'] ?? 'dropship';
            $shippingMethod = $data['shippingMethod'] ?? 'STANDARD_SHIPPING';  // 获取物流方式
            // dd();
            if (!$productId || !$region || !$quantity) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '缺少必须参数',
                    'messageEn' => 'Missing required parameters'
                ], 400);
            }
            
         
            // 如果用户未登录，返回错误
            if (!$user) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '请先登录后再查看价格明细',
                    'messageEn' => 'Please log in to view price details'
                ], 401);  // ✅ 正确使用 401：这是认证失败场景（未登录无法查看价格）
            }
            
            // 获取用户VIP等级
            $userVipLevel = $user->getVipLevel();
            
            // 查询商品（使用QueryBuilder加载关联数据）
            $product = $productRepository->createQueryBuilder('p')
                ->leftJoin('p.prices', 'prices')
                ->addSelect('prices')
                ->leftJoin('p.shippings', 'shippings')
                ->addSelect('shippings')
                ->leftJoin('p.discountRules', 'discountRules')
                ->addSelect('discountRules')
                ->where('p.id = :productId')
                ->andWhere('p.status = :status')
                ->setParameter('productId', $productId)
                ->setParameter('status', 'approved')
                ->getQuery()
                ->getOneOrNullResult();
            
            if (!$product) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '商品不存在或已下架',
                    'messageEn' => 'Product not found or unavailable'
                ], 404);
            }
            
            // 验证区域
            $shippingRegions = $product->getShippingRegions() ?? [];
            if (!in_array($region, $shippingRegions)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '选择的区域不在发货范围内',
                    'messageEn' => 'Selected region is not available'
                ], 400);
            }
            
            // 获取价格信息（匹配区域和业务类型）
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
                return new JsonResponse([
                    'success' => false,
                    'message' => '该区域暂无价格信息',
                    'messageEn' => 'No price information for this region'
                ], 404);
            }
            
            // 获取运费信息
            $regionShipping = null;
            foreach ($product->getShippings() as $shipping) {
                if ($shipping->getRegion() === $region) {
                    $regionShipping = $shipping;
                    break;
                }
            }
            
            // 计算总运费（只有标准物流才收取运费，自提运费为0）
            $shippingPrice = '0';  // 默认为0（自提）
            $totalShippingFee = '0';
            
            if ($shippingMethod === 'STANDARD_SHIPPING' && $regionShipping) {
                $shippingPrice = (string)$regionShipping->getShippingPrice();
                $totalShippingFee = $shippingPrice;
                
                if ($quantity > 1) {
                    $additionalPrice = (string)($regionShipping->getAdditionalPrice() ?? '0');
                    if ($priceCalculator->pricesEqual($additionalPrice, '0') === false) {
                        $additionalCount = $quantity - 1;
                        $additionalShippingFee = $priceCalculator->calculateSubtotal($additionalPrice, $additionalCount);
                        $totalShippingFee = $priceCalculator->addShippingFee($shippingPrice, $additionalShippingFee);
                    }
                }
            }
            
            // 获取会员折扣率
            $sellingPrice = (string)$regionPrice->getSellingPrice();
            $memberDiscounts = $regionPrice->getMemberDiscount();
            $memberDiscountRate = 0;
            
            if ($memberDiscounts && isset($memberDiscounts[(string)$userVipLevel])) {
                $memberDiscountRate = (float)$memberDiscounts[(string)$userVipLevel];
            }
            
            // 调试日志：详细输出会员折扣信息
            $logger->info('[PriceCalculation] 价格计算参数', [
                'userVipLevel' => $userVipLevel,
                'sellingPrice' => $sellingPrice,
                'memberDiscountRate' => $memberDiscountRate,
                'memberDiscountRate_type' => gettype($memberDiscountRate),
                'memberDiscountRate_gt_zero' => ($memberDiscountRate > 0),
                'quantity' => $quantity,
                'memberDiscounts' => $memberDiscounts,
                'memberDiscounts_raw_value' => isset($memberDiscounts[(string)$userVipLevel]) ? $memberDiscounts[(string)$userVipLevel] : 'NOT_SET'
            ]);
            
            // 获取满减信息
            $regionDiscountRule = $product->getDiscountRuleByRegion($region);
            $minAmount = null;
            $discountAmount = null;
            if ($regionDiscountRule && $regionDiscountRule->isCurrentlyValid()) {
                $minAmount = (string)$regionDiscountRule->getMinAmount();
                $discountAmount = (string)$regionDiscountRule->getDiscountAmount();
            }
            
            // 使用价格计算服务计算总价（与MultiProductOrderProcessingMessageHandler完全一致）
            $priceResult = $priceCalculator->calculateTotalPrice([
                'sellingPrice' => $sellingPrice,
                'memberDiscountRate' => $memberDiscountRate,
                'quantity' => $quantity,
                'minAmount' => $minAmount,
                'discountAmount' => $discountAmount,
                'shippingFee' => $totalShippingFee,
                'shippingMethod' => $shippingMethod,  // 使用从前端传入的物流方式
                'currency' => $regionPrice->getCurrency()
            ]);
            
            // 调试日志
            $logger->info('[PriceCalculation] 价格计算结果', $priceResult);
            
            // 补充价格明细：添加商品折扣项（如果有）
            $breakdown = $priceResult['breakdown'];
            $originalPrice = $regionPrice->getOriginalPrice();
            $discountRate = $regionPrice->getDiscountRate();
            
            // 如果有商品折扣率（原价 > 售价 且有折扣率）
            if ($originalPrice && $discountRate && (float)$originalPrice > (float)$sellingPrice) {
                // 计算商品折扣金额 = (原价 - 售价) × 数量
                $productDiscountAmount = $financialCalculator->multiply(
                    $financialCalculator->subtract($originalPrice, $sellingPrice),
                    (string)$quantity
                );
                
                // 只有折扣金额大于0时才添加明细项
                if ((float)$productDiscountAmount > 0) {
                    // 在breakdown数组开头插入商品折扣项
                    array_unshift($breakdown, [
                        'label' => sprintf('商品折扣 %.1f%%', (float)$discountRate),
                        'amount' => '-' . $productDiscountAmount,
                        'currency' => $regionPrice->getCurrency()
                    ]);
                }
            }
            
            // 修正会员折扣：确保金额乘以数量，并在标签后显示折扣率
            if ($memberDiscountRate > 0) {
                // 遍历breakdown，找到会员折扣项并修改
                foreach ($breakdown as &$item) {
                    // 判断是否是会员折扣项（通过label包含"会员"关键字）
                    if (isset($item['label']) && strpos($item['label'], '会员') !== false) {
                        // 计算会员折扣金额 = 售价 × 会员折扣率 × 数量
                        $memberDiscountAmount = $financialCalculator->multiply(
                            $financialCalculator->multiply($sellingPrice, (string)$memberDiscountRate),
                            (string)$quantity
                        );
                        
                        // 计算会员折扣百分比显示 = 会员折扣率 × 100
                        $memberDiscountPercent = $memberDiscountRate * 100;
                        
                        // 更新标签和金额
                        $item['label'] = sprintf('会员折扣 %.1f%%', $memberDiscountPercent);
                        $item['amount'] = '-' . $memberDiscountAmount;
                        break;
                    }
                }
                unset($item); // 解除引用
            }
            
            // 返回计算结果
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'totalPrice' => $priceResult['totalPrice'],
                    'displayPrice' => $priceResult['displayPrice'],
                    'subtotal' => $priceResult['subtotal'],
                    'breakdown' => $breakdown,
                    'currency' => $regionPrice->getCurrency()
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

    /**
     * 支付确认接口（异步处理版本 - 极速响应）
     * 只做最基本的参数验证，立即发送到消息队列并返回订单号
     * 所有业务逻辑（验证商品、库存、价格、创建订单、扣库存、扣余额）在消息队列中异步处理
     * 
     * 安全措施：
     * 1. 用户认证 - RequireAuth 确保用户已登录
     * 2. API签名 - RequireSignature 防止参数篡改和重放攻击
     * 3. RSA加密 - 敏感数据加密传输
     * 4. 异步处理 - 使用Symfony Messenger异步处理所有业务逻辑
     */
    #[Route('/confirm-payment', name: 'confirm_payment', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature(message: '支付请求签名验证失败，请重试', messageEn: 'Payment request signature verification failed, please try again', checkNonce: true)]
    public function confirmPayment(
        Request $request,
        RsaCryptoService $rsaCrypto,
        MessageBusInterface $messageBus
    ): JsonResponse {
        try {
            // 获取请求数据
            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象（更安全，参数名和值都被加密）
            try {
                if (isset($requestData['encryptedPayload'])) {
                    // 解密整个对象
                    $data = $rsaCrypto->decryptObject($requestData['encryptedPayload']);
                } else {
                    // 向下兼容：如果没有encryptedPayload，尝试解密单个字段
                    $data = $requestData;
                    if (isset($data['productId'])) {
                        $decrypted = $rsaCrypto->decrypt($data['productId']);
                        $data['productId'] = (int)$decrypted;
                    }
                    if (isset($data['totalPrice'])) {
                        $decrypted = $rsaCrypto->decrypt($data['totalPrice']);
                        $data['totalPrice'] = $decrypted;
                    }
                    if (isset($data['paymentMethod'])) {
                        $data['paymentMethod'] = $rsaCrypto->decrypt($data['paymentMethod']);
                    }
                    if (isset($data['customerId']) && $data['customerId'] !== null) {
                        $decrypted = $rsaCrypto->decrypt($data['customerId']);
                        $data['customerId'] = (int)$decrypted;
                    }
                }
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '请求失败，请重新登录后再试',
                    'messageEn' => 'Request failed, please log in again and try'
                ], 400);  // 解密失败是业务错误，返回 400
            }
            // dd($data);
            // 基本参数验证（不查数据库）
            $productId = $data['productId'] ?? null;
            $region = $data['region'] ?? null;
            $quantity = $data['quantity'] ?? null;
            $paymentMethod = $data['paymentMethod'] ?? null;
            $frontendTotalPrice = $data['totalPrice'] ?? null;
            $shippingMethod = $data['shippingMethod'] ?? ProductShipping::STANDARD_SHIPPING;
            $customerId = $data['customerId'] ?? null;
            $frontendOrderNo = $data['orderNo'] ?? null; // 前端生成的订单号
            $businessType = $data['businessType'] ?? 'dropship'; // 业务类型，默认为 dropship
            $addressId = $data['addressId'] ?? null; // 收货地址ID
            // dd($data);
            // 验证必须参数（支付方式可以为空，等待订单生成后再填写）
            if (!$productId || !$region || !$quantity || $frontendTotalPrice === null || !$customerId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '缺少必须参数',
                    'messageEn' => 'Missing required parameters'
                ], 400);
            }
            
            // 验证物流方式是否合法
            $validShippingMethods = [ProductShipping::STANDARD_SHIPPING, ProductShipping::SELF_PICKUP];
            if (!in_array($shippingMethod, $validShippingMethods, true)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '物流方式无效',
                    'messageEn' => 'Invalid shipping method'
                ], 400);
            }
            
            // 使用前端传入的订单号，如果没有则生成一个
            $orderNo = $frontendOrderNo ?: ('ORD' . date('Ymd') . strtoupper(substr(uniqid(), -6)));
            
            // 立即发送到消息队列
            $messageBus->dispatch(new MultiProductOrderProcessingMessage(
                $orderNo,
                [
                    'customer_id' => $customerId,
                    'business_type' => $businessType, // 添加业务类型字段
                    'items' => [
                        [
                            'product_id' => $productId,
                            'quantity' => $quantity,
                            'region' => $region,
                            'business_type' => $businessType, // 添加业务类型字段
                            'total_price' => $frontendTotalPrice,
                            'shipping_method' => $shippingMethod, // 添加物流方式字段
                        ]
                    ],
                    'total_amount' => $frontendTotalPrice,
                    'payment_method' => $paymentMethod,
                    'shipping_method' => $shippingMethod,
                    'address_id' => $addressId, // 传递地址ID
                ]
            ));
            
            // 记录日志
            error_log("[OrderProcessing] 订单消息已发送到队列: {$orderNo}");
            
            // 立即返回订单号给前端
            return new JsonResponse([
                'success' => true,
                'message' => '订单创建成功，正在处理中...',
                'messageEn' => 'Order created successfully, processing...',
                'data' => [
                    'orderNo' => $orderNo,
                    'status' => 'processing', // 处理中
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
