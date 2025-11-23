<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\ProductShipping;
use App\Message\OrderProcessingMessage;
use App\Repository\OrderRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProductRepository;
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
 * 订单处理消息处理器
 * 异步处理：验证商品、验证库存、验证价格、计算价格、创建订单、扣库存、扣余额
 */
#[AsMessageHandler]
class OrderProcessingMessageHandler
{
    private EntityManagerInterface $entityManager;
    private CustomerRepository $customerRepository;
    private ProductRepository $productRepository;
    private BalanceHistoryService $balanceHistoryService;
    private FinancialCalculatorService $financialCalculator;
    private ProductPriceCalculatorService $priceCalculator;
    private SupplierCommissionService $commissionService;
    private LoggerInterface $logger;
    private HubInterface $hub;
    private MercureMessageService $mercureMessageService;

    private OrderItemStatusService $orderItemStatusService;

    public function __construct(
        EntityManagerInterface $entityManager,
        CustomerRepository $customerRepository,
        ProductRepository $productRepository,
        BalanceHistoryService $balanceHistoryService,
        FinancialCalculatorService $financialCalculator,
        ProductPriceCalculatorService $priceCalculator,
        SupplierCommissionService $commissionService,
        OrderItemStatusService $orderItemStatusService,
        LoggerInterface $logger,
        HubInterface $hub,
        MercureMessageService $mercureMessageService
    ) {
        $this->entityManager = $entityManager;
        $this->customerRepository = $customerRepository;
        $this->productRepository = $productRepository;
        $this->balanceHistoryService = $balanceHistoryService;
        $this->financialCalculator = $financialCalculator;
        $this->priceCalculator = $priceCalculator;
        $this->commissionService = $commissionService;
        $this->orderItemStatusService = $orderItemStatusService;
        $this->logger = $logger;
        $this->hub = $hub;
        $this->mercureMessageService = $mercureMessageService;
    }

    public function __invoke(OrderProcessingMessage $message): void
    {
        // 【最外层异常捕获】确保任何错误都不会导致 Worker 崩溃或卡死
        try {
            $this->processOrder($message);
        } catch (\Throwable $e) {
            // 捕获所有错误和异常（包括 Error 和 Exception）
            $orderNo = $message->getOrderNo();
            
            $this->logger->critical('[OrderProcessing] 订单处理发生严重错误（最外层捕获）', [
                'order_no' => $orderNo,
                'error_type' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 尝试推送失败状态（如果推送也失败，至少日志已记录）
            try {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '系统错误，请联系客服处理',
                    'messageEn' => 'System error, please contact customer service'
                ]);
            } catch (\Throwable $pushError) {
                // Mercure 推送失败也不影响 Worker
                $this->logger->error('[OrderProcessing] 推送失败状态时发生错误', [
                    'order_no' => $orderNo,
                    'push_error' => $pushError->getMessage()
                ]);
            }
            
            // 不抛出任何异常，让 Worker 继续处理下一条消息
            return;
        }
    }
    
    /**
     * 处理订单的核心逻辑
     */
    private function processOrder(OrderProcessingMessage $message): void
    {
        $orderNo = $message->getOrderNo();
        $orderData = $message->getOrderData();
        
        $this->logger->info('[OrderProcessing] 开始处理订单', [
            'order_no' => $orderNo,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        // ✅ 事件驱动方案：无需等待前端订阅
        // 前端通过 /api/mercure/ready 异步通知后端已就绪
        // 消息队列会自动处理顺序，Mercure 会缓存消息
        $this->logger->info('[OrderProcessing] 采用事件驱动方案，立即开始处理', [
            'order_no' => $orderNo
        ]);
        
        // 【兼容性处理】检查消息数据完��性，防止旧消息或异常消息导致 Worker 卡死
        if (!isset($orderData['customer_id']) || !isset($orderData['product_id']) || !isset($orderData['quantity'])) {
            $this->logger->error('[OrderProcessing] 消息数据不完整，跳过处理（可能是旧版本消息）', [
                'order_no' => $orderNo,
                'order_data' => $orderData
            ]);
            $this->publishUpdate($orderNo, [
                'status' => 'failed',
                'step' => 'error',
                'message' => '订单数据不完整，请重新下单',
                'messageEn' => 'Incomplete order data, please place order again'
            ]);
            // 直接返回，不抛出异常，避免阻塞队列
            return;
        }
        
        // 【全局异常捕获】确保任何异常都不会导致 Worker 卡死
        try {
            // 推送状态：开始处理
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'validating',
                'message' => '正在验证订单信息...',
                'messageEn' => 'Validating order information...'
            ]);
            
            // 步骤1: 验证会员
            $customer = $this->customerRepository->find($orderData['customer_id']);
            if (!$customer) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '会员信息无效',
                    'messageEn' => 'Invalid member information'
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '会员信息无效'
                ]);
                return;
            }
            
            if (!$customer->isActive()) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '您的账号已被禁用，请联系客服',
                    'messageEn' => 'Your account has been disabled, please contact customer service'
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '账号已禁用'
                ]);
                return;
            }
            
            // 步骤2: 验证商品
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'validating_product',
                'message' => '正在验证商品信息...',
                'messageEn' => 'Validating product information...'
            ]);
            
            $product = $this->productRepository->findOneBy([
                'id' => $orderData['product_id'],
                'status' => 'approved'
            ]);
            
            if (!$product) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '商品不存在或已下架',
                    'messageEn' => 'Product not found or unavailable'
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '商品不存在或已下架'
                ]);
                return;
            }
            
            // 步骤3: 验证区域
            $region = $orderData['region'];
            $shippingRegions = $product->getShippingRegions() ?? [];
            if (!in_array($region, $shippingRegions)) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '选择的区域不在发货范围内',
                    'messageEn' => 'Selected region is not in shipping range'
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '区域不在发货范围内'
                ]);
                return;
            }
            
            // 步骤4: 获取价格信息
            $regionPrice = null;
            foreach ($product->getPrices() as $price) {
                if ($price->getRegion() === $region && $price->isActive()) {
                    $regionPrice = $price;
                    break;
                }
            }
            
            if (!$regionPrice) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '该区域暂无价格信息',
                    'messageEn' => 'No price information available for this region'
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '该区域暂无价格信息'
                ]);
                return;
            }
            
            // 步骤5: 验证库存
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'checking_stock',
                'message' => '正在检查库存...',
                'messageEn' => 'Checking stock...'
            ]);
            
            $quantity = $orderData['quantity'];
            $regionStock = 0;
            $shippingPrice = '0';
            $regionShipping = null;
            
            // 【修复】使用正确的方法名 getShippings()（之前错误调用 getProductShippings()）
            try {
                $shippings = $product->getShippings();
            } catch (\Error $e) {
                // 兼容旧版本代码或代理类问题
                $this->logger->error('[OrderProcessing] 获取物流信息失败', [
                    'order_no' => $orderNo,
                    'error' => $e->getMessage()
                ]);
                // 【不抛出异常】直接推送失败状态并返回，避免阻塞队列
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '系统错误：无法获取商品物流信息',
                    'messageEn' => 'System error: unable to get product shipping info'
                ]);
                return;
            }
            
            foreach ($shippings as $shipping) {
                if ($shipping->getRegion() === $region) {
                    $regionStock = $shipping->getAvailableStock() ?? 0;
                    $shippingPrice = (string)$shipping->getShippingPrice();
                    $regionShipping = $shipping;
                    break;
                }
            }
            
            // 计算总运费：首件运费 + 续件运费 × (数量 - 1)
            $totalShippingFee = $shippingPrice;
            if ($regionShipping && $quantity > 1) {
                $additionalPrice = (string)($regionShipping->getAdditionalPrice() ?? '0');
                if ($this->priceCalculator->pricesEqual($additionalPrice, '0') === false && 
                    $this->priceCalculator->formatPrice($additionalPrice) !== '0.00') {
                    // 续件数量 = 总数量 - 1
                    $additionalCount = $quantity - 1;
                    // 续件总运费 = 续件运费 × 续件数量（使用金融计算服务）
                    $additionalShippingFee = $this->priceCalculator->calculateSubtotal($additionalPrice, $additionalCount);
                    // 总运费 = 首件运费 + 续件总运费（使用金融计算服务）
                    $totalShippingFee = $this->priceCalculator->addShippingFee($shippingPrice, $additionalShippingFee);
                }
            }
            
            if ($regionStock < $quantity) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '库存不足',
                    'messageEn' => 'Insufficient stock'
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '库存不足'
                ]);
                return;
            }
            
            // 验证最小起订量
            $minOrderQty = $regionPrice->getMinWholesaleQuantity() ?? 1;
            if ($quantity < $minOrderQty) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => "最小起订数量为:{$minOrderQty}",
                    'messageEn' => "Minimum order quantity is: {$minOrderQty}"
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '不满足最小起订量'
                ]);
                return;
            }
            
            // 步骤6: 计算价格
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'calculating_price',
                'message' => '正在计算价格...',
                'messageEn' => 'Calculating price...'
            ]);
            
            $userVipLevel = $customer->getVipLevel();
            $sellingPrice = (string)$regionPrice->getSellingPrice();
            $memberDiscounts = $regionPrice->getMemberDiscount();
            $memberDiscountRate = 0;
            
            // 获取会员折扣率
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
            
            // 使用价格计算服务计算总价（传入总运费）
            $priceResult = $this->priceCalculator->calculateTotalPrice([
                'sellingPrice' => $sellingPrice,
                'memberDiscountRate' => $memberDiscountRate,
                'quantity' => $quantity,
                'minAmount' => $minAmount,
                'discountAmount' => $discountAmount,
                'shippingFee' => $totalShippingFee,  // 使用总运费（已包含首件 + 续件）
                'shippingMethod' => $orderData['shipping_method'],
                'currency' => $regionPrice->getCurrency()
            ]);
            
            // 步骤7: 验证价格
            $backendTotalPrice = $priceResult['totalPrice'];
            $frontendPrice = $this->priceCalculator->formatPrice((string)$orderData['total_price']);
            
            // 构建详细的价格对比信息（成功和失败都返回）
            $priceDebugInfo = [
                'frontend_total' => $frontendPrice,
                'backend_total' => $backendTotalPrice,
                'difference' => $this->priceCalculator->formatPrice(
                    (float)$frontendPrice - (float)$backendTotalPrice
                ),
                'backend_breakdown' => [
                    'selling_price' => $sellingPrice,
                    'quantity' => $quantity,
                    'member_discount_rate' => $memberDiscountRate,
                    'display_price' => $priceResult['displayPrice'],
                    'subtotal' => $priceResult['subtotal'],
                    'shipping_fee' => $totalShippingFee,
                    'min_amount' => $minAmount,
                    'discount_amount' => $discountAmount,
                ]
            ];
            
            // 记录详细的价格对比信息
            $this->logger->info('[OrderProcessing] 价格验证对比', [
                'order_no' => $orderNo,
                'product_id' => $product->getId(),
                'product_title' => $product->getTitle(),
                'region' => $region,
                'business_type' => $businessType,
                'quantity' => $quantity,
                'frontend_price' => $frontendPrice,
                'backend_calculated_price' => $backendTotalPrice,
                'price_breakdown' => $priceResult,
                'selling_price' => $sellingPrice,
                'member_discount_rate' => $memberDiscountRate,
                'shipping_fee' => $totalShippingFee,
                'shipping_method' => $orderData['shipping_method']
            ]);
            
            if (!$this->priceCalculator->pricesEqual($backendTotalPrice, $frontendPrice)) {
                $this->logger->error('[OrderProcessing] 价格验证失败', [
                    'order_no' => $orderNo,
                    'product_id' => $product->getId(),
                    'frontend_price' => $frontendPrice,
                    'backend_price' => $backendTotalPrice,
                    'difference' => bccomp($frontendPrice, $backendTotalPrice, 2)
                ]);
                
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '价格验证失败，请刷新页面后重试',
                    'messageEn' => 'Price verification failed, please refresh and try again',
                    'debug' => $priceDebugInfo  // 添加调试信息
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '价格验证失败',
                    'price_debug' => $priceDebugInfo
                ]);
                return;
            }
            
            // 价格验证成功，也推送价格信息
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'price_verified',
                'message' => '价格验证成功',
                'messageEn' => 'Price verified successfully',
                'debug' => $priceDebugInfo  // 成功时也返回调试信息
            ]);
            
            // 步骤8: 检查余额
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'checking_balance',
                'message' => '正在检查余额...',
                'messageEn' => 'Checking balance...'
            ]);
            
            $paidAmount = $backendTotalPrice;
            if ((float)$customer->getBalance() < (float)$paidAmount) {
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '余额不足，无法完成支付',
                    'messageEn' => 'Insufficient balance'
                ]);
                $this->logger->warning('[OrderProcessing] 订单处理失败', [
                    'order_no' => $orderNo,
                    'reason' => '余额不足',
                    'required' => $paidAmount,
                    'available' => $customer->getBalance()
                ]);
                return;
            }
            
            // 步骤9: 开始数据库事务 - 创建订单、扣库存、扣余额
            $this->publishUpdate($orderNo, [
                'status' => 'processing',
                'step' => 'creating_order',
                'message' => '正在创建订单...',
                'messageEn' => 'Creating order...'
            ]);
            
            $this->entityManager->getConnection()->beginTransaction();
            
            try {
                // 创建订单实体
                $order = new Order();
                $order->setOrderNo($orderNo);
                $order->setCustomer($customer);
                $order->setTotalAmount($backendTotalPrice);
                $order->setPaidAmount($backendTotalPrice);
                $order->setPaymentStatus('unpaid');
                $order->setPaymentMethod($orderData['payment_method']);
                $order->setShippingMethod($orderData['shipping_method']);
                
                // 设置收货信息（暂时使用待补充）
                $order->setReceiverName('待补充');
                $order->setReceiverPhone('待补充');
                $order->setReceiverAddress('待补充');
                
                // 创建订单明细
                $orderItem = new OrderItem();
                $orderItem->setOrder($order);
                $orderItem->setProduct($product);
                $orderItem->setProductSku($product->getSku());
                $orderItem->setProductTitle($product->getTitle());
                $orderItem->setProductTitleEn($product->getTitleEn());
                $orderItem->setProductThumbnail($product->getThumbnailImage() ?: $product->getMainImage());
                $orderItem->setShippingRegion($region);  // 设置发货区域快照
                $orderItem->setOrderStatus('pending_payment');  // 设置订单项初始状态
                
                // 设置供应商信息
                $supplier = $product->getSupplier();
                if ($supplier) {
                    $orderItem->setSupplier($supplier);
                    $orderItem->setSupplierName($supplier->getDisplayName());
                    
                    // 将供应商ID添加到订单的supplier_ids列表中
                    $order->addSupplierId($supplier->getId());
                }
                
                // ==================== 先计算折扣金额 ====================
                // 1. 获取商品原价
                $originalPrice = $regionPrice->getOriginalPrice() ?? $sellingPrice;
                
                // 2. 商品总金额 = 原价 × 数量（基于原价计算，使用金融计算服务）
                $productAmount = $this->priceCalculator->calculateSubtotal($originalPrice, $quantity);
                
                // 3. 计算商品折扣金额（原价 - 售价）
                $productDiscountAmount = '0.00';
                if ($originalPrice !== $sellingPrice) {
                    // 商品折扣金额 = (原价 - 售价) × 数量（使用金融计算服务）
                    // 先计算原价总额和售价总额，再求差值，避免精度损失
                    $originalTotal = $this->priceCalculator->calculateSubtotal($originalPrice, $quantity);
                    $sellingTotal = $this->priceCalculator->calculateSubtotal($sellingPrice, $quantity);
                    $productDiscountAmount = $this->priceCalculator->formatPrice(
                        (float)$originalTotal - (float)$sellingTotal
                    );
                }
                
                // 3. 计算会员折扣金额（使用金融计算服务）
                $memberDiscountAmount = '0.00';
                if ($memberDiscountRate > 0) {
                    // 会员折扣金额 = 售价 × 数量 × 折扣率
                    $sellingSubtotal = $this->priceCalculator->calculateSubtotal($sellingPrice, $quantity);
                    $memberDiscountAmount = $this->priceCalculator->calculateMemberPrice($sellingSubtotal, $memberDiscountRate);
                    $memberDiscountAmount = $this->priceCalculator->formatPrice(
                        (float)$sellingSubtotal - (float)$memberDiscountAmount
                    );
                }
                
                // 4. 计算满减优惠金额（使用金融计算服务）
                $fullReductionAmount = '0.00';
                if ($minAmount !== null && $discountAmount !== null) {
                    // 计算打完会员折扣后的小计（用于判断是否达到满减门槛）
                    $subtotalAfterMemberDiscount = $this->priceCalculator->formatPrice(
                        (float)$productAmount - (float)$memberDiscountAmount
                    );
                    // 如果达到满减门槛，应用满减优惠
                    if ($this->priceCalculator->pricesEqual($subtotalAfterMemberDiscount, $minAmount) || 
                        (float)$subtotalAfterMemberDiscount > (float)$minAmount) {
                        $fullReductionAmount = $this->priceCalculator->formatPrice($discountAmount);
                    }
                }
                
                // 5. 总优惠金额 = 商品折扣 + 会员折扣 + 满减（使用金融计算服务）
                $totalDiscountAmount = $this->priceCalculator->formatPrice(
                    (float)$productDiscountAmount + (float)$memberDiscountAmount + (float)$fullReductionAmount
                );
                
                // 设置折扣明细（快照）
                $discountDetails = [
                    'product_discount_amount' => $productDiscountAmount,     // 商品折扣金额
                    'member_discount_rate' => $memberDiscountRate,           // 会员折扣率
                    'member_discount_amount' => $memberDiscountAmount,       // 会员折扣金额
                    'full_reduction_amount' => $fullReductionAmount,         // 满减金额
                    'total_discount_amount' => $totalDiscountAmount          // 总折扣金额
                ];
                $orderItem->setDiscountDetails($discountDetails);
                
                // 设置价格信息
                // unitPrice: 商品单价（ProductPrice.selling_price）
                $orderItem->setUnitPrice($sellingPrice);
                // originalUnitPrice: 商品原价（ProductPrice.original_price）
                $orderItem->setOriginalUnitPrice($originalPrice);
                $orderItem->setQuantity($quantity);
                
                // 计算商品总运费（首件 + 续件）
                $itemShippingFee = '0.00';
                if ($orderData['shipping_method'] === 'STANDARD_SHIPPING' && $regionShipping) {
                    $itemShippingFee = $totalShippingFee;
                }
                $orderItem->setShippingFee($itemShippingFee);
                
                // 计算单件实际支付价格（不含运费，使用金融计算服务）
                // actualUnitPrice = (支付总价 - 运费) / 数量
                $pureProductAmount = $this->priceCalculator->formatPrice(
                    (float)$paidAmount - (float)$itemShippingFee
                );
                $actualUnitPrice = $this->priceCalculator->formatPrice(
                    (float)$pureProductAmount / $quantity
                );
                $orderItem->setActualUnitPrice($actualUnitPrice);
                
                // 设置支付总价
                // subtotalAmount = actualUnitPrice × 数量 + 运费
                $orderItem->setSubtotalAmount($paidAmount);
                
                // 计算并设置佣金信息
                if ($supplier) {
                    $commissionResult = $this->commissionService->calculateSupplierCommission(
                        $supplier->getId(),
                        $paidAmount,        // 商品支付价格（含运费）
                        $itemShippingFee,   // 运费
                        $supplier           // 供应商实体
                    );
                    
                    // 设置佣金相关字段
                    $orderItem->setCommissionRate($commissionResult['commission_rate']);
                    $orderItem->setCommissionAmount($commissionResult['commission_amount']);
                    $orderItem->setSupplierIncome($commissionResult['supplier_income']);
                }
                
                // ==================== 计算订单金额 ====================
                // 运费（包含首件 + 续件）
                $shippingFee = '0.00';
                if ($orderData['shipping_method'] === 'STANDARD_SHIPPING' && $regionShipping) {
                    $shippingFee = $this->priceCalculator->formatPrice($totalShippingFee);  // 使用金融计算服务格式化
                }
                
                // 订单总金额 = 商品总金额（原价×数量） + 运费 - 所有折扣（使用金融计算服务）
                $totalAmount = $this->priceCalculator->addShippingFee($productAmount, $shippingFee);     // 先加运费
                $totalAmount = $this->priceCalculator->formatPrice(
                    (float)$totalAmount - (float)$totalDiscountAmount                                    // 再减所有折扣
                );
                
                // 设置订单金额字段
                $order->setProductAmount($this->priceCalculator->formatPrice($productAmount));              // 商品总金额（原价 × 数量）
                $order->setShippingFee($shippingFee);                                                       // 运费
                $order->setDiscountAmount($totalDiscountAmount);                                            // 优惠金额（商品折扣 + 会员折扣 + 满减，所有折扣总和）
                $order->setTotalAmount($totalAmount);                                                       // 订单总金额
                $order->setPaidAmount($totalAmount);                                                        // 实际支付金额
                
                // 设置订单折扣明细（汇总所有商品的折扣明细）
                $orderDiscountDetails = [
                    'product_discount_amount' => $productDiscountAmount,     // 商品折扣总和
                    'member_discount_amount' => $memberDiscountAmount,       // 会员折扣总和
                    'full_reduction_amount' => $fullReductionAmount,         // 满减总和
                    'total_discount_amount' => $totalDiscountAmount          // 总折扣金额
                ];
                $order->setDiscountDetails($orderDiscountDetails);
                
                // 保存订单
                $this->entityManager->persist($order);
                $this->entityManager->persist($orderItem);
                $this->entityManager->flush();
                
                // 步骤10: 扣减库存
                $this->publishUpdate($orderNo, [
                    'status' => 'processing',
                    'step' => 'deducting_stock',
                    'message' => '正在扣减库存...',
                    'messageEn' => 'Deducting stock...'
                ]);
                
                if ($regionShipping) {
                    $currentStock = $regionShipping->getAvailableStock();
                    $regionShipping->setAvailableStock($currentStock - $quantity);
                }
                
                // 步骤11: 扣除余额
                $this->publishUpdate($orderNo, [
                    'status' => 'processing',
                    'step' => 'deducting_balance',
                    'message' => '正在扣除余额...',
                    'messageEn' => 'Deducting balance...'
                ]);
                
                $oldBalance = $customer->getBalance();
                // 使用金融计算服务进行余额扣减，避免浮点数精度问题
                $newBalance = $this->financialCalculator->subtract($oldBalance, $paidAmount);
                $customer->setBalance($newBalance);
                
                // 记录余额变动历史
                $this->balanceHistoryService->createBalanceHistory(
                    'customer',
                    $customer->getId(),
                    $oldBalance,
                    $newBalance,
                    (string)(-$paidAmount),
                    $customer->getFrozenBalance(),
                    $customer->getFrozenBalance(),
                    '0.00',
                    'order_payment',
                    "订单支付：{$orderNo}",
                    $orderNo,
                    null  // 单商品订单支付时，可以不关联单个订单项
                );
                
                // 使用订单状态服务更新订单项状态为已支付，并冻结供应商余额
                $this->orderItemStatusService->confirmPayment($orderItem);
                
                // 更新订单支付状态
                $order->setPaymentStatus('paid');
                $order->setPaymentTime(new \DateTime());
                
                // 提交事务
                $this->entityManager->flush();
                $this->entityManager->getConnection()->commit();
                
                // 成功完成
                $this->publishUpdate($orderNo, [
                    'status' => 'success',
                    'step' => 'completed',
                    'message' => '订单处理成功！',
                    'messageEn' => 'Order processed successfully!',
                    'order' => [
                        'order_no' => $orderNo,
                        'order_id' => $order->getId(),
                        'amount' => $order->getPaidAmount(),
                        'status' => 'paid',
                        'payment_time' => $order->getPaymentTime()->format('Y-m-d H:i:s')
                    ]
                ]);
                
                $this->logger->info('[OrderProcessing] 订单处理成功', [
                    'order_no' => $orderNo,
                    'customer_id' => $customer->getId(),
                    'amount' => $paidAmount,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } catch (\Exception $e) {
                // 回滚事务
                $this->entityManager->getConnection()->rollBack();
                
                // 【不重新抛出异常】记录日志并推送失败状态，避免消息重试导致队列阻塞
                $this->logger->error('[OrderProcessing] 订单事务处理失败', [
                    'order_no' => $orderNo,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $this->publishUpdate($orderNo, [
                    'status' => 'failed',
                    'step' => 'error',
                    'message' => '订单处理失败：' . $e->getMessage(),
                    'messageEn' => 'Order processing failed: ' . $e->getMessage()
                ]);
                
                // 直接返回，不抛出异常，避免消息重试
                return;
            }
            
        } catch (\Error $e) {
            // 【捕获致命错误】如方法不存在、类型错误等（通常是代码兼容性问题）
            $this->logger->critical('[OrderProcessing] 订单处理发生致命错误（可能是代码兼容性问题）', [
                'order_no' => $orderNo,
                'error_type' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->publishUpdate($orderNo, [
                'status' => 'failed',
                'step' => 'error',
                'message' => '系统错误，请联系客服处理',
                'messageEn' => 'System error, please contact customer service'
            ]);
            
            // 不抛出异常，避免阻塞队列
            return;
            
        } catch (\Exception $e) {
            // 【捕获业务异常】如余额不足、库存不足等
            $this->logger->error('[OrderProcessing] 订单处理失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 推送失败状态
            $this->publishUpdate($orderNo, [
                'status' => 'failed',
                'step' => 'error',
                'message' => $e->getMessage(),
                'messageEn' => $e->getMessage()
            ]);
            
            // 业务错误不重试，直接返回
            return;
        }
    }
    
    /**
     * 【已废弃】等待前端建立 Mercure 订阅连接（旧的轮询方案）
     * 
     * 保留此方法是为了代码兼容性，但不再使用
     * 新方案采用完全事件驱动，无需等待
     * 
     * @deprecated 使用事件驱动方案替代
     */
    private function waitForSubscription(string $orderNo, int $maxWaitSeconds = 3, int $checkIntervalMs = 100): void
    {
        // 【已废弃】此方法不再使用
        // 事件驱动方案下，无需等待前端订阅
        $this->logger->info('[OrderProcessing] ⚠️ waitForSubscription 方法已废弃', [
            'order_no' => $orderNo
        ]);
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
            $this->mercureMessageService->publishMessage($orderNo, $data);

            // 第二步：再推送到 Mercure（实时推送给已连接的前端）
            $topic = "https://example.com/orders/{$orderNo}";

            $this->logger->info('[OrderProcessing] 准备推送 Mercure 消息', [
                'order_no' => $orderNo,
                'topic' => $topic,
                'status' => $data['status'] ?? 'unknown',
                'step' => $data['step'] ?? 'unknown'
            ]);

            $update = new Update(
                $topic,
                json_encode($data)
            );

            $messageId = $this->hub->publish($update);

            $this->logger->info('[OrderProcessing] Mercure 消息推送成功', [
                'order_no' => $orderNo,
                'message_id' => $messageId,
                'topic' => $topic,
                'status' => $data['status'] ?? 'unknown',
                'step' => $data['step'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[OrderProcessing] Mercure 推送失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
