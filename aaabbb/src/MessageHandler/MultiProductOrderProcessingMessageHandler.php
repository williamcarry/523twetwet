<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Message\MultiProductOrderProcessingMessage;
use App\Repository\OrderRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProductRepository;
use App\Repository\CustomerAddressRepository;
use App\Service\BalanceHistoryService;
use App\Service\FinancialCalculatorService;
use App\Service\ProductPriceCalculatorService;
use App\Service\SupplierCommissionService;
use App\Service\Order\OrderItemStatusService;
use App\Service\MercureMessageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * 多商品订单处理消息处理器
 * 异步处理：验证所有商品、验证库存、验证价格、计算价格、创建订单（包含多个订单项）、扣库存、扣余额
 */
#[AsMessageHandler]
class MultiProductOrderProcessingMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CustomerRepository $customerRepository,
        private ProductRepository $productRepository,
        private CustomerAddressRepository $addressRepository,
        private BalanceHistoryService $balanceHistoryService,
        private FinancialCalculatorService $financialCalculator,
        private ProductPriceCalculatorService $priceCalculator,
        private SupplierCommissionService $commissionService,
        private OrderItemStatusService $orderItemStatusService,
        private LoggerInterface $logger,
        private HubInterface $hub,
        private MercureMessageService $mercureMessageService
    ) {
    }

    public function __invoke(MultiProductOrderProcessingMessage $message): void
    {
        // 【最外层异常捕获】确保任何错误都不会导致 Worker 崩溃或卡死
        try {
            $this->processOrder($message);
        } catch (\Throwable $e) {
            $orderNo = $message->getOrderNo();
            
            $this->logger->critical('[MultiProductOrderProcessing] 订单处理发生严重错误', [
                'order_no' => $orderNo,
                'error_type' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            try {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '系统错误，请联系客服处理',
                    'messageEn' => 'System error, please contact customer service'
                ]);
            } catch (\Throwable $pushError) {
                $this->logger->error('[MultiProductOrderProcessing] 推送失败状态时发生错误', [
                    'order_no' => $orderNo,
                    'push_error' => $pushError->getMessage()
                ]);
            }
            
            return;
        }
    }
    
    /**
     * 处理订单的核心逻辑
     */
    private function processOrder(MultiProductOrderProcessingMessage $message): void
    {
        $orderNo = $message->getOrderNo();
        $orderData = $message->getOrderData();
        
        $this->logger->info('[MultiProductOrderProcessing] ✅ 开始处理多商品订单', [
            'order_no' => $orderNo,
            'items_count' => count($orderData['items'] ?? []),
            'timestamp' => date('Y-m-d H:i:s'),
            'process_id' => getmypid()
        ]);
        
        // ✅ 事件驱动方案：无需等待前端订阅
        // 前端通过 /api/mercure/ready 异步通知后端已就绪
        // 消息队列会自动处理顺序，Mercure 会缓存消息
        $this->logger->info('[MultiProductOrderProcessing] 🚀 采用事件驱动方案，立即开始处理', [
            'order_no' => $orderNo
        ]);
        
        // 立即发送初始状态推送
        $this->logger->info('[MultiProductOrderProcessing] 📤 准备发送初始状态', [
            'order_no' => $orderNo
        ]);
        $this->publishUpdate($orderNo, [
            'status' => 'processing',
            'step' => 'validating',
            'message' => '正在验证订单信息...',
            'messageEn' => 'Validating order information...',
            'items_count' => count($orderData['items'] ?? [])
        ]);
        
        $this->logger->info('[MultiProductOrderProcessing] 📨 初始状态已推送', [
            'order_no' => $orderNo
        ]);
        
        // 验证消息数据完整性
        if (!isset($orderData['customer_id']) || !isset($orderData['items']) || empty($orderData['items'])) {
            $this->logger->error('[MultiProductOrderProcessing] 消息数据不完整', [
                'order_no' => $orderNo,
                'order_data' => $orderData
            ]);
            $this->publishUpdate($orderNo, [
                'status' => 'failed',
                'step' => 'error',
                'message' => '订单数据不完整，请重新下单',
                'messageEn' => 'Incomplete order data, please place order again'
            ]);
            return;
        }
        
        try {
            // 步骤1: 验证会员（优化：减少不必要的状态推送）
            $customer = $this->customerRepository->find($orderData['customer_id']);
            if (!$customer) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '会员信息无效',
                    'messageEn' => 'Invalid member information'
                ]);
                return;
            }
            
            if (!$customer->isActive()) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '您的账号已被禁用，请联系客服',
                    'messageEn' => 'Your account has been disabled'
                ]);
                return;
            }
            
            // 步骤2: 验证所有商品并计算价格（优化：合并验证步骤，减少推送）
            $validatedItems = [];
            $totalOrderAmount = '0.00';
            $supplierIds = [];
            
            foreach ($orderData['items'] as $index => $itemData) {
                $itemNo = $index + 1;
                
                // 验证单个商品
                $validationResult = $this->validateAndCalculateItem(
                    $itemData,
                    $customer,
                    $orderNo,
                    $itemNo
                );
                
                if (!$validationResult['success']) {
                    // 构建详细的商品信息，使用商品ID代替标题
                    $productId = $validationResult['product_id'] ?? 'unknown';
                    $productInfo = "商品ID: {$productId}";
                    $region = $validationResult['region'] ?? '';
                    
                    $this->publishUpdate($orderNo, [
                        'status' => 'failed',
                        'step' => 'error',
                        'message' => "{$productInfo}，区域: {$region} {$validationResult['message']}",
                        'messageEn' => "Product {$itemNo} validation failed"
                    ]);
                    return;
                }
                
                $validatedItems[] = $validationResult['item'];
                $totalOrderAmount = bcadd($totalOrderAmount, $validationResult['item']['calculated_price'], 2);
                
                // 收集供应商ID
                $supplierId = $validationResult['item']['supplier']->getId();
                if (!in_array($supplierId, $supplierIds)) {
                    $supplierIds[] = $supplierId;
                }
            }
            
            // 步骤3: 验证总金额（优化：去除中间状态推送）
            //前端传入的总金额（如果有）
            $frontendTotal = isset($orderData['total_amount']) ? 
                $this->priceCalculator->formatPrice((string)$orderData['total_amount']) : null;
            
            if ($frontendTotal && !$this->priceCalculator->pricesEqual($totalOrderAmount, $frontendTotal)) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '订单总额验证失败，请刷新页面后重试',
                    'messageEn' => 'Total amount validation failed',
                    'debug' => [
                        'frontend_total' => $frontendTotal,
                        'backend_total' => $totalOrderAmount,
                        'difference' => $this->priceCalculator->formatPrice((float)$frontendTotal - (float)$totalOrderAmount)
                    ]
                ]);
                return;
            }
            
            // ===================================================================
            // 步骤4: 检查余额（已废弃，不再在订单创建时检查和扣减余额）
            // 现在订单创建时支付方式为空，等待订单生成后用户选择支付方式
            // 如果选择余额支付，则在 OrderController::updatePaymentMethod 中检查和扣减余额
            // ===================================================================
            // if ((float)$customer->getBalance() < (float)$totalOrderAmount) {
            //     $this->publishUpdate($orderNo, [
            //         'status' => 'failed',
            //         'step' => 'error',
            //         'message' => '余额不足，无法完成支付',
            //         'messageEn' => 'Insufficient balance',
            //         'debug' => [
            //             'required' => $totalOrderAmount,
            //             'available' => $customer->getBalance()
            //         ]
            //     ]);
            //     return;
            // }
            
            // 步骤5: 开始数据库事务 - 创建订单、扣库存、扣余额（优化：只在关键步骤推送）
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'creating_order',
                'message' => '正在处理订单...',
                'messageEn' => 'Processing order...'
            ]);
            
            $this->entityManager->getConnection()->beginTransaction();
            
            try {
                // 创建订单主表
                $order = $this->createOrder($orderNo, $customer, $orderData, $totalOrderAmount, $supplierIds);
                $this->entityManager->persist($order);
                
                // 创建所有订单项（传递订单的 businessType）
                foreach ($validatedItems as $validatedItem) {
                    $orderItem = $this->createOrderItem($order, $validatedItem, $order->getBusinessType());
                    $order->addItem($orderItem); // 将订单项添加到订单的items集合中
                    $this->entityManager->persist($orderItem);
                }
                
                // 计算并设置订单级别的运费和折扣金额
                $this->calculateOrderTotals($order);
                
                // 一次性保存所有数据（订单、订单项、运费、折扣）
                $this->entityManager->flush();
                
                // 扣减库存
                $this->deductStock($validatedItems, $orderNo);
                
                // ===================================================================
                // 扣除余额（已废弃，不再在订单创建时扣减余额）
                // 现在订单创建时支付方式为空，等待订��生成后用户选择支付方式
                // 如果选择余额支付，则在 OrderController::updatePaymentMethod 中扣减余额
                // ===================================================================
                // $this->deductBalance($customer, $totalOrderAmount, $orderNo);
                
                // ===================================================================
                // 处理支付和冻结供应商余额（已废弃，不再在订单创建时处理）
                // 现在订单创建时支付状态为unpaid，等待用户选择支付方式后再处理
                // 如果选择余额支付，则在 OrderController::updatePaymentMethod 中调用 confirmPayment
                // ===================================================================
                // foreach ($order->getItems() as $orderItem) {
                //     $this->orderItemStatusService->confirmPayment($orderItem);
                // }
                
                // ===================================================================
                // 更新订单支付状态（已废弃，不再在订单创建时设置为已支付）
                // 现在订单创建时支付状态为unpaid，等待用户选择支付方式后再更新
                // ===================================================================
                // $order->setPaymentStatus('paid');
                // $order->setPaymentTime(new \DateTime());
                
                // 提交事务
                $this->entityManager->flush();
                $this->entityManager->getConnection()->commit();
                
                // 成功完成
                $this->publishUpdate($orderNo, [
                    'status' => 'success',
                    'step' => 'completed',
                    'message' => '订单创建成功！',
                    'messageEn' => 'Order created successfully!',
                    'order' => [
                        'order_no' => $orderNo,
                        'order_id' => $order->getId(),
                        'amount' => $order->getTotalAmount(),
                        'items_count' => count($validatedItems),
                        'suppliers_count' => count($supplierIds),
                        'status' => 'unpaid',  // 订单状态为未支付
                        'payment_status' => 'unpaid'  // 支付状态为未支付
                    ]
                ]);
                
                $this->logger->info('[MultiProductOrderProcessing] 订单创建成功（未支付）', [
                    'order_no' => $orderNo,
                    'customer_id' => $customer->getId(),
                    'amount' => $totalOrderAmount,
                    'items_count' => count($validatedItems),
                    'suppliers' => $supplierIds,
                    'payment_status' => 'unpaid'
                ]);
                
            } catch (\Exception $e) {
                // 回滚事务
                $this->entityManager->getConnection()->rollBack();
                
                $this->logger->error('[MultiProductOrderProcessing] 订单事务处理失败', [
                    'order_no' => $orderNo,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '订单处理失败：' . $e->getMessage(),
                    'messageEn' => 'Order processing failed'
                ]);
                
                return;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('[MultiProductOrderProcessing] 订���处理失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->publishUpdate($orderNo, [
                'status' => 'failed',
                'step' => 'error',
                'message' => $e->getMessage(),
                'messageEn' => $e->getMessage()
            ]);
        }
    }
    
    // 后续方法将在下一部分添加...
    
    /**
     * 验证并计算单个商品项
     */
    private function validateAndCalculateItem(array $itemData, $customer, string $orderNo, int $itemNo): array
    {
        // 检查必要字段
        if (!isset($itemData['product_id']) || !isset($itemData['quantity']) || !isset($itemData['region'])) {
            return [
                'success' => false,
                'product_id' => $itemData['product_id'] ?? 'unknown',
                'product_title' => '未知商品',
                'region' => $itemData['region'] ?? 'unknown',
                'message' => '验证失败：商品数据不完整'
            ];
        }
        
        $productId = $itemData['product_id'];
        $region = $itemData['region'];
        $quantity = $itemData['quantity'];
        
        // 验证商品（使用QueryBuilder加载关联数据）
        $product = $this->productRepository->createQueryBuilder('p')
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
            return [
                'success' => false,
                'product_id' => $productId,
                'product_title' => '商品ID: ' . $productId,
                'region' => $region,
                'message' => '验证失败：商品不存在或已下架'
            ];
        }
        
        // 验证区域
        $shippingRegions = $product->getShippingRegions() ?? [];
        if (!in_array($region, $shippingRegions)) {
            return [
                'success' => false,
                'product_id' => $productId,
                'product_title' => $product->getTitle(),
                'region' => $region,
                'message' => '验证失败：选择的区域不在发货范围内'
            ];
        }
        
        // 获取业务类型（从前端传入，默认为 dropship）
        $businessType = $itemData['business_type'] ?? 'dropship';
        
        // 获取价格信息（需要匹配区域和业务类型）
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
            // 业务类型翻译
            $businessTypeLabel = $businessType === 'dropship' ? '一件代发' : (
                $businessType === 'wholesale' ? '批发' : $businessType
            );
            
            // 记录详细的调试信息
            $this->logger->error('[MultiProductOrderProcessing] 该区域暂无价格信息', [
                'order_no' => $orderNo,
                'item_no' => $itemNo,
                'product_id' => $productId,
                'product_title' => $product->getTitle(),
                'requested_region' => $region,
                'requested_business_type' => $businessType,
                'available_prices' => array_map(function($price) {
                    return [
                        'region' => $price->getRegion(),
                        'business_type' => $price->getBusinessType(),
                        'is_active' => $price->isActive(),
                        'selling_price' => $price->getSellingPrice()
                    ];
                }, $product->getPrices()->toArray()),
                'shipping_regions' => $product->getShippingRegions()
            ]);
            
            return [
                'success' => false,
                'product_id' => $productId,
                'product_title' => $product->getTitle(),
                'region' => $region,
                'message' => "暂无价格信息 业务类型 {$businessTypeLabel}"
            ];
        }
        
        // 验证库存
        $regionStock = 0;
        $shippingPrice = '0';
        $regionShipping = null;
        
        // 获取物流方式（从前端传入，默认为标准物流）
        $shippingMethod = $itemData['shipping_method'] ?? 'STANDARD_SHIPPING';
        
        foreach ($product->getShippings() as $shipping) {
            if ($shipping->getRegion() === $region) {
                $regionStock = $shipping->getAvailableStock() ?? 0;
                // 只有当物流方式为标准物流时才收取运费
                if ($shippingMethod === 'STANDARD_SHIPPING') {
                    $shippingPrice = (string)$shipping->getShippingPrice();
                } else {
                    // 自提时运费为0
                    $shippingPrice = '0';
                }
                $regionShipping = $shipping;
                break;
            }
        }
        
        // 计算总运费（只有标准物流才计算运费）
        $totalShippingFee = $shippingPrice;
        if ($shippingMethod === 'STANDARD_SHIPPING' && $regionShipping && $quantity > 1) {
            $additionalPrice = (string)($regionShipping->getAdditionalPrice() ?? '0');
            if ($this->priceCalculator->pricesEqual($additionalPrice, '0') === false && 
                $this->priceCalculator->formatPrice($additionalPrice) !== '0.00') {
                $additionalCount = $quantity - 1;
                $additionalShippingFee = $this->priceCalculator->calculateSubtotal($additionalPrice, $additionalCount);
                $totalShippingFee = $this->priceCalculator->addShippingFee($shippingPrice, $additionalShippingFee);
            }
        }
        
        if ($regionStock < $quantity) {
            return [
                'success' => false,
                'product_id' => $productId,
                'product_title' => $product->getTitle(),
                'region' => $region,
                'message' => '验证失败：库存不足'
            ];
        }
        
        // 验证最小起订量
        $minOrderQty = $regionPrice->getMinWholesaleQuantity() ?? 1;
        if ($quantity < $minOrderQty) {
            return [
                'success' => false,
                'product_id' => $productId,
                'product_title' => $product->getTitle(),
                'region' => $region,
                'message' => "验证失败：最小起订数量为 {$minOrderQty}"
            ];
        }
        
        // 计算价格
        $userVipLevel = $customer->getVipLevel();
        $sellingPrice = (string)$regionPrice->getSellingPrice();
        $memberDiscounts = $regionPrice->getMemberDiscount();
        $memberDiscountRate = 0;
        
        if ($memberDiscounts && isset($memberDiscounts[(string)$userVipLevel])) {
            $memberDiscountRate = (float)$memberDiscounts[(string)$userVipLevel];
        }
        
        // 获取满减信息
        $regionDiscountRule = $product->getDiscountRuleByRegion($region);
        $minAmount = null;
        $discountAmount = null;
        if ($regionDiscountRule && $regionDiscountRule->isCurrentlyValid()) {
            $minAmount = (string)$regionDiscountRule->getMinAmount();
            $discountAmount = (string)$regionDiscountRule->getDiscountAmount();
        }
        
        // 使用价格计算服务计算总价
        $priceResult = $this->priceCalculator->calculateTotalPrice([
            'sellingPrice' => $sellingPrice,
            'memberDiscountRate' => $memberDiscountRate,
            'quantity' => $quantity,
            'minAmount' => $minAmount,
            'discountAmount' => $discountAmount,
            'shippingFee' => $totalShippingFee,
            'shippingMethod' => $shippingMethod,  // 使用从前端传入的物流方式
            'currency' => $regionPrice->getCurrency()
        ]);
        
        $calculatedPrice = $priceResult['totalPrice'];
        
        // 如果前端提供了价格，验证价格
        if (isset($itemData['total_price'])) {
            $frontendPrice = $this->priceCalculator->formatPrice((string)$itemData['total_price']);
            
            // 记录详细的价格对比信息
            $this->logger->info('[MultiProductOrderProcessing] 价格验证对比', [
                'order_no' => $orderNo,
                'item_no' => $itemNo,
                'product_id' => $productId,
                'product_title' => $product->getTitle(),
                'region' => $region,
                'business_type' => $businessType,
                'quantity' => $quantity,
                'frontend_price' => $frontendPrice,
                'backend_calculated_price' => $calculatedPrice,
                'price_breakdown' => $priceResult,
                'selling_price' => $sellingPrice,
                'member_discount_rate' => $memberDiscountRate,
                'shipping_fee' => $totalShippingFee,
                'shipping_method' => $shippingMethod
            ]);
            
            if (!$this->priceCalculator->pricesEqual($calculatedPrice, $frontendPrice)) {
                $this->logger->error('[MultiProductOrderProcessing] 价格验证失败', [
                    'order_no' => $orderNo,
                    'item_no' => $itemNo,
                    'product_id' => $productId,
                    'frontend_price' => $frontendPrice,
                    'backend_price' => $calculatedPrice,
                    'difference' => bccomp($frontendPrice, $calculatedPrice, 2)
                ]);
                
                return [
                    'success' => false,
                    'product_id' => $productId,
                    'product_title' => $product->getTitle(),
                    'region' => $region,
                    'message' => '验证失败：价格验证失败'
                ];
            }
        }
        
        // 返回验证成功的商品信息
        return [
            'success' => true,
            'item' => [
                'product' => $product,
                'quantity' => $quantity,
                'region' => $region,
                'business_type' => $businessType,
                'shipping_method' => $shippingMethod,  // 添加物流方式
                'regionPrice' => $regionPrice,
                'regionShipping' => $regionShipping,
                'totalShippingFee' => $totalShippingFee,
                'calculated_price' => $calculatedPrice,
                'price_result' => $priceResult,
                'member_discount_rate' => $memberDiscountRate,
                'supplier' => $product->getSupplier(),
                'original_price' => $regionPrice->getOriginalPrice() ?? $sellingPrice,
                'selling_price' => $sellingPrice,
            ]
        ];
    }
    
    /**
     * 创建订单主表
     */
    private function createOrder(string $orderNo, $customer, array $orderData, string $totalAmount, array $supplierIds): Order
    {
        $order = new Order();
        
        // ✅ 修复：使用前端传入的订单号（覆盖构造函数自动生成的订单号）
        // 这样可以确保前后端订单号一致，前端可以立即用订单号查询订单状态
        $order->setOrderNo($orderNo);
        
        $order->setCustomer($customer);
        $order->setTotalAmount($totalAmount);
        $order->setPaidAmount($totalAmount);
        $order->setPaymentStatus('unpaid');
        $order->setPaymentMethod($orderData['payment_method'] ?? 'balance');
        $order->setShippingMethod($orderData['shipping_method'] ?? 'STANDARD_SHIPPING');
        
        // 设置业务类型（从前端传入，默认为 dropship）
        $order->setBusinessType($orderData['business_type'] ?? 'dropship');
        
        // 处理收货地址
        if (isset($orderData['address_id']) && $orderData['address_id']) {
            // 从数据库查询地址信息
            $address = $this->addressRepository->find($orderData['address_id']);
            if ($address && $address->getCustomer()->getId() === $customer->getId()) {
                // 验证地址属于当前用户
                $order->setReceiverName($address->getReceiverName() ?? '待补充');
                $order->setReceiverPhone($address->getReceiverPhone() ?? '待补充');
                $order->setReceiverAddress($address->getReceiverAddress() ?? '待补充');
                $order->setReceiverZipcode($address->getReceiverZipcode() ?? ''); // 保存邮编
                
                $this->logger->info('[订单地址] 使用用户地址', [
                    'order_no' => $orderNo,
                    'address_id' => $orderData['address_id'],
                    'receiver_name' => $address->getReceiverName(),
                    'receiver_zipcode' => $address->getReceiverZipcode()
                ]);
            } else {
                // 地址不存在或不属于当前用户，使用默认值
                $order->setReceiverName('待补充');
                $order->setReceiverPhone('待补充');
                $order->setReceiverAddress('待补充');
                $order->setReceiverZipcode(''); // 邮编为空
                
                $this->logger->warning('[订单地址] 地址ID无效', [
                    'order_no' => $orderNo,
                    'address_id' => $orderData['address_id']
                ]);
            }
        } else {
            // 没有传递地址ID，使用默认值
            $order->setReceiverName($orderData['receiver_name'] ?? '待补充');
            $order->setReceiverPhone($orderData['receiver_phone'] ?? '待补充');
            $order->setReceiverAddress($orderData['receiver_address'] ?? '待补充');
            $order->setReceiverZipcode($orderData['receiver_zipcode'] ?? ''); // 保存邮编
            
            $this->logger->info('[订单地址] 未提供地址ID', [
                'order_no' => $orderNo
            ]);
        }
        
        // 设置供应商ID列表
        foreach ($supplierIds as $supplierId) {
            $order->addSupplierId($supplierId);
        }
        
        return $order;
    }
    
    /**
     * 创建订单项
     * 
     * @param Order $order 订单对象
     * @param array $itemData 商品项数据
     * @param string $businessType 业务类型（从订单中获取，保证订单项与订单一致）
     */
    private function createOrderItem(Order $order, array $itemData, string $businessType): OrderItem
    {
        // dd( $order,$itemData);
        $product = $itemData['product'];
        $quantity = $itemData['quantity'];
        $region = $itemData['region'];
        $regionPrice = $itemData['regionPrice'];
        $totalShippingFee = $itemData['totalShippingFee'];
        $priceResult = $itemData['price_result'];
        $memberDiscountRate = $itemData['member_discount_rate'];
        $originalPrice = $itemData['original_price'];
        $sellingPrice = $itemData['selling_price'];
        $supplier = $itemData['supplier'];
        
        $orderItem = new OrderItem();
        $orderItem->setOrder($order);
        // 生成订单详情的独立订单号（格式：ORD+年月日+微秒时间戳后6位+随机2位十六进制）
        // 每个订单详情都有唯一的订单号，用于退款等场景的准确定位
        // 使用微秒时间戳降低碰撞风险，适用于高并发场景
        $orderItem->generateOrderNo();
        $orderItem->setProduct($product);
        $orderItem->setProductSku($product->getSku());
        $orderItem->setProductTitle($product->getTitle());
        $orderItem->setProductTitleEn($product->getTitleEn());
        $orderItem->setProductThumbnail($product->getThumbnailImage() ?: $product->getMainImage());
        $orderItem->setShippingRegion($region);
        $orderItem->setBusinessType($businessType); // 使用订单的业务类型，保证订单项与订单一致
        $orderItem->setShippingMethod($itemData['shipping_method'] ?? 'STANDARD_SHIPPING'); // 保存物流方式
        $orderItem->setOrderStatus('pending_payment');
        
        // 设置供应商信息
        if ($supplier) {
            $orderItem->setSupplier($supplier);
            $orderItem->setSupplierName($supplier->getDisplayName());
        }
        
        // 计算折扣
        $productAmount = $this->priceCalculator->calculateSubtotal($originalPrice, $quantity);
        $productDiscountAmount = '0.00';
        if ($originalPrice !== $sellingPrice) {
            $originalTotal = $this->priceCalculator->calculateSubtotal($originalPrice, $quantity);
            $sellingTotal = $this->priceCalculator->calculateSubtotal($sellingPrice, $quantity);
            $productDiscountAmount = $this->priceCalculator->formatPrice(
                (float)$originalTotal - (float)$sellingTotal
            );
        }
        
        $memberDiscountAmount = '0.00';
        if ($memberDiscountRate > 0) {
            $sellingSubtotal = $this->priceCalculator->calculateSubtotal($sellingPrice, $quantity);
            $memberDiscountAmount = $this->priceCalculator->calculateMemberPrice($sellingSubtotal, $memberDiscountRate);
            $memberDiscountAmount = $this->priceCalculator->formatPrice(
                (float)$sellingSubtotal - (float)$memberDiscountAmount
            );
        }
        
        // 从 priceResult 中提取满减金额
        $fullReductionAmount = '0.00';
        if (isset($priceResult['breakdown']) && is_array($priceResult['breakdown'])) {
            foreach ($priceResult['breakdown'] as $item) {
                if (isset($item['label']) && $item['label'] === '满减') {
                    // breakdown中的amount格式为 "-10.00"，需要去掉负号
                    $fullReductionAmount = ltrim($item['amount'], '-');
                    break;
                }
            }
        }
        
        $totalDiscountAmount = $this->priceCalculator->formatPrice(
            (float)$productDiscountAmount + (float)$memberDiscountAmount + (float)$fullReductionAmount
        );
        
        // 设置折扣明细
        $orderItem->setDiscountDetails([
            'product_discount_amount' => $productDiscountAmount,
            'member_discount_rate' => $memberDiscountRate,
            'member_discount_amount' => $memberDiscountAmount,
            'full_reduction_amount' => $fullReductionAmount,
            'total_discount_amount' => $totalDiscountAmount
        ]);
        
        // 设置价格信息
        $orderItem->setUnitPrice($sellingPrice);
        $orderItem->setOriginalUnitPrice($originalPrice);
        $orderItem->setQuantity($quantity);
        $orderItem->setShippingFee($totalShippingFee);
        
        $paidAmount = $itemData['calculated_price'];
        $pureProductAmount = $this->priceCalculator->formatPrice(
            (float)$paidAmount - (float)$totalShippingFee
        );
        $actualUnitPrice = $this->priceCalculator->formatPrice(
            (float)$pureProductAmount / $quantity
        );
        $orderItem->setActualUnitPrice($actualUnitPrice);
        $orderItem->setSubtotalAmount($paidAmount);
        
        // 计算并设置佣金信��
        if ($supplier) {
            $commissionResult = $this->commissionService->calculateSupplierCommission(
                $supplier->getId(),
                $paidAmount,
                $totalShippingFee,
                $supplier
            );
            
            $orderItem->setCommissionRate($commissionResult['commission_rate']);
            $orderItem->setCommissionAmount($commissionResult['commission_amount']);
            $orderItem->setSupplierIncome($commissionResult['supplier_income']);
        }
        
        return $orderItem;
    }
    
    /**
     * 扣减库存
     */
    private function deductStock(array $validatedItems, string $orderNo): void
    {
        foreach ($validatedItems as $itemData) {
            $regionShipping = $itemData['regionShipping'];
            $quantity = $itemData['quantity'];
            
            if ($regionShipping) {
                $currentStock = $regionShipping->getAvailableStock();
                $regionShipping->setAvailableStock($currentStock - $quantity);
            }
        }
    }
    
    /**
     * 扣除余额
     */
    private function deductBalance($customer, string $totalAmount, string $orderNo): void
    {
        $oldBalance = $customer->getBalance();
        $newBalance = $this->financialCalculator->subtract($oldBalance, $totalAmount);
        $customer->setBalance($newBalance);
        
        // 记录余额变动历史
        $this->balanceHistoryService->createBalanceHistory(
            'customer',
            $customer->getId(),
            $oldBalance,
            $newBalance,
            (string)(-$totalAmount),
            $customer->getFrozenBalance(),
            $customer->getFrozenBalance(),
            '0.00',
            'order_payment',
            "多商品订单支付：{$orderNo}",
            $orderNo,
            null  // 多商品订单支付时，不关联单个订单项
        );
    }
    
    /**
     * 通过 Mercure 发布订单状态更新
     *
     * 改进：现在消息会同时保存到 Redis（可靠性） 和 Mercure（实时性）
     * - 如果前端已连接，Mercure 会立即推送消息
     * - 如果前端未连接，消息保存在 Redis，前端连接后可查询
     * - 这样解决了 Linux 高速执行导致的消息丢失问题
     */
    private function publishUpdate(string $orderNo, array $data): void
    {
        try {
            // 第一步：先存储消息到 Redis（确保不丢失）
            $stored = $this->mercureMessageService->publishMessage($orderNo, $data);

            if ($stored) {
                $this->logger->info('[MultiProductOrderProcessing] ✅ 消息存储到 Redis 成功', [
                    'order_no' => $orderNo,
                    'status' => $data['status'] ?? 'unknown'
                ]);
            } else {
                $this->logger->warning('[MultiProductOrderProcessing] ⚠️ 消息存储到 Redis 失败', [
                    'order_no' => $orderNo
                ]);
            }

            // 第二步：再推送到 Mercure（实时推送给已连接的前端）
            $topic = "https://example.com/orders/{$orderNo}";

            $this->logger->info('[MultiProductOrderProcessing] 📤 准备推送 Mercure 消息', [
                'order_no' => $orderNo,
                'topic' => $topic,
                'status' => $data['status'] ?? 'unknown',
                'step' => $data['step'] ?? 'unknown'
            ]);

            $update = new Update($topic, json_encode($data));
            $messageId = $this->hub->publish($update);

            $this->logger->info('[MultiProductOrderProcessing] ✅ Mercure 消息推送成功', [
                'order_no' => $orderNo,
                'message_id' => $messageId,
                'status' => $data['status'] ?? 'unknown',
                'step' => $data['step'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MultiProductOrderProcessing] ��� Mercure 推送失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * 计算并设置订单级别的运费、商品金额和折扣金额
     * 
     * @param Order $order 订单对象
     */
    private function calculateOrderTotals(Order $order): void
    {
        $totalShippingFee = '0.00';
        $totalProductAmount = '0.00';
        $totalDiscountAmount = '0.00';
        
        // 汇总所有订单项的运费、商品金额和折扣
        foreach ($order->getItems() as $orderItem) {
            // 计算总运费：所有订单项运费之和
            $totalShippingFee = $this->financialCalculator->add(
                $totalShippingFee,
                (string)$orderItem->getShippingFee()
            );
            
            // 计算商品总金额：原价 × 数量
            $productAmount = $this->financialCalculator->multiply(
                (string)$orderItem->getOriginalUnitPrice(),
                (string)$orderItem->getQuantity()
            );
            $totalProductAmount = $this->financialCalculator->add(
                $totalProductAmount,
                $productAmount
            );
            
            // 计算总折扣金额：汇总所有订单项的折扣
            $discountDetails = $orderItem->getDiscountDetails();
            if ($discountDetails && isset($discountDetails['total_discount_amount'])) {
                $totalDiscountAmount = $this->financialCalculator->add(
                    $totalDiscountAmount,
                    (string)$discountDetails['total_discount_amount']
                );
            }
        }
        
        // 设置到订单对象
        $order->setShippingFee($totalShippingFee);
        $order->setProductAmount($totalProductAmount);
        $order->setDiscountAmount($totalDiscountAmount);
        
        // 记录日志以便调试
        $this->logger->info('[MultiProductOrderProcessing] 订单金额计算完成', [
            'order_no' => $order->getOrderNo(),
            'product_amount' => $totalProductAmount,
            'shipping_fee' => $totalShippingFee,
            'discount_amount' => $totalDiscountAmount,
            'total_amount' => $order->getTotalAmount()
        ]);
    }
}
