<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Repository\LogisticsCompanyRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\RsaCryptoService;
use App\Service\QiniuUploadService;
use App\Service\FinancialCalculatorService;
use App\Service\BalanceHistoryService;
use App\Service\Order\OrderItemStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 订单API控制器
 * 
 * 路由规范：/shop/api/order
 * 安全规范：所有接口需要用户认证 + API签名验证 + RSA加密传输
 */
#[Route('/shop/api/order', name: 'api_order_')]
class OrderController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private LogisticsCompanyRepository $logisticsCompanyRepository,
        private RsaCryptoService $rsaCryptoService,
        private QiniuUploadService $qiniuUploadService,
        private FinancialCalculatorService $financialCalculator,
        private BalanceHistoryService $balanceHistoryService,
        private OrderItemStatusService $orderItemStatusService,
        private \App\Service\SiteConfigService $siteConfigService
    ) {
    }

    /**
     * 获取订单列表
     * 
     * 请求参数（加密后传输）：
     * - page: 页码（默认1）
     * - pageSize: 每页数量（默认20）
     * - status: 订单状态筛选（可选，pending_payment/paid/shipped/completed/cancelled等）
     * - businessType: 业务类型筛选（可选，dropship/wholesale）
     * - orderNo: 订单号搜索（可选）
     * 
     * 返回数据：订单列表（包含订单基本信息和订单项）
     */
    #[Route('/list', name: 'list', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function list(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();
        // dd($customer);
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

        // 调试日志：记录前端传递的参数
        error_log('订单列表查询参数: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        // 分页参数
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($data['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        // 最简单的查询：只查询当前用户的订单
        $qb = $this->orderRepository->createQueryBuilder('o')
            ->where('o.customer = :customer')
            ->setParameter('customer', $customer);

        // 添加订单号筛选
        if (!empty($data['orderNo'])) {
            $qb->andWhere('o.orderNo LIKE :orderNo')
                ->setParameter('orderNo', '%' . $data['orderNo'] . '%');
        }

        // 添加业务类型筛选（可选）
        // 注意：只有明确传递了businessType且不为'all'时才添加筛选条件
        if (!empty($data['businessType']) && $data['businessType'] !== 'all') {
            error_log("添加业务类型筛选: {$data['businessType']}");
            $qb->andWhere('o.businessType = :businessType')
                ->setParameter('businessType', $data['businessType']);
        }

        // 添加订单状态筛选（通过OrderItem状态）
        if (!empty($data['status']) && $data['status'] !== 'all') {
            error_log("添加订单状态筛选: {$data['status']}");
            $qb->leftJoin('o.items', 'oi')
               ->andWhere('oi.orderStatus = :orderStatus')
               ->setParameter('orderStatus', $data['status']);
        }

        // 调试：输出完整的DQL查询语句
        $dql = $qb->getDQL();
        $params = [];
        foreach ($qb->getParameters() as $param) {
            $params[$param->getName()] = $param->getValue();
        }
        error_log('DQL查询语句: ' . $dql);
        error_log('查询参数: ' . json_encode($params, JSON_UNESCAPED_UNICODE));

        // 先获取总数
        $total = (int) $qb->select('COUNT(DISTINCT o.id)')
            ->getQuery()
            ->getSingleScalarResult();
        
        error_log("查询到的订单总数: {$total}");

        // 再获取分页数据
        $qb->select('o')
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($pageSize);

        $orders = $qb->getQuery()->getResult();
        error_log("返回订单数量: " . count($orders));

        // 格式化订单数据
        $orderList = [];
        foreach ($orders as $order) {
            $orderList[] = $this->formatOrder($order);
        }

        return $this->json([
            'success' => true,
            'data' => [
                'list' => $orderList,
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => ceil($total / $pageSize)
                ]
            ]
        ]);
    }

    /**
     * 获取订单详情
     * 
     * 请求参数（加密后传输）：
     * - orderNo: 订单号
     * 
     * 返回数据：订单完整信息（包括所有订单项详情）
     */
    #[Route('/detail', name: 'detail', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function detail(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
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
        if (empty($data['orderNo'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少订单号参数',
                'messageEn' => 'Missing order number parameter'
            ], 400);
        }

        // 查询订单
        $order = $this->orderRepository->findOneBy([
            'orderNo' => $data['orderNo'],
            'customer' => $customer
        ]);

        if (!$order) {
            return $this->json([
                'success' => false,
                'message' => '订单不存在',
                'messageEn' => 'Order not found'
            ], 404);
        }

        return $this->json([
            'success' => true,
            'data' => $this->formatOrder($order, true) // true 表示包含完整详情
        ]);
    }

    /**
     * 获取订单统计
     * 
     * 返回数据：各状态订单数量统计
     */
    #[Route('/statistics', name: 'statistics', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function statistics(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }

        // 统计各状态订单数量（与列表查询保持一致，基于OrderItem状态）
        $statistics = [
            'all' => 0,
            'pending_payment' => 0,
            'paid' => 0,
            'shipped' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        // 获取所有订单的总数（需要考虑业务类型）
        $totalQb = $this->orderRepository->createQueryBuilder('o')
            ->select('COUNT(DISTINCT o.id)')
            ->where('o.customer = :customer')
            ->setParameter('customer', $customer);
        
        // 添加业务类型筛选（与列表查询保持一致）
        if (!empty($data['businessType'])) {
            $totalQb->andWhere('o.businessType = :businessType')
                ->setParameter('businessType', $data['businessType']);
        }
        
        $statistics['all'] = (int)$totalQb->getQuery()->getSingleScalarResult();

        // 统计各状态的订单数量（基于OrderItem状态，与列表查询一致）
        $statuses = ['pending_payment', 'paid', 'shipped', 'completed', 'cancelled'];
        
        foreach ($statuses as $status) {
            $qb = $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(DISTINCT o.id)')
                ->leftJoin('o.items', 'oi')
                ->where('o.customer = :customer')
                ->andWhere('oi.orderStatus = :status')
                ->setParameter('customer', $customer)
                ->setParameter('status', $status);
            
            // 添加业务类型筛选（与列表查询保持一致）
            if (!empty($data['businessType'])) {
                $qb->andWhere('o.businessType = :businessType')
                    ->setParameter('businessType', $data['businessType']);
            }
            
            $count = $qb->getQuery()->getSingleScalarResult();
            $statistics[$status] = (int)$count;
            
            // 调试日志
            error_log(sprintf('统计 %s 状态订单 (业务类型: %s): %d 个', 
                $status, 
                $data['businessType'] ?? 'all',
                $count
            ));
        }
        
        // 输出完整统计结果
        error_log('订单统计结果: ' . json_encode($statistics, JSON_UNESCAPED_UNICODE));

        return $this->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * 获取全部订单统计（不分业务类型）
     * 用于个人中心首页展示
     * 
     * 返回数据：各状态订单数量统计（所有业务类型）
     */
    #[Route('/statistics/all', name: 'statistics_all', methods: ['GET'])]
    #[RequireAuth]
    public function statisticsAll(): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        // 统计各状态订单数量（所有业务类型）
        $statistics = [
            'pending_payment' => 0,
            'paid' => 0,
            'shipped' => 0,
        ];

        // 统计待付款（pending_payment）
        $pendingPaymentCount = $this->orderRepository->createQueryBuilder('o')
            ->select('COUNT(DISTINCT o.id)')
            ->leftJoin('o.items', 'oi')
            ->where('o.customer = :customer')
            ->andWhere('oi.orderStatus = :status')
            ->setParameter('customer', $customer)
            ->setParameter('status', 'pending_payment')
            ->getQuery()
            ->getSingleScalarResult();
        $statistics['pending_payment'] = (int)$pendingPaymentCount;

        // 统计待发货（paid）
        $paidCount = $this->orderRepository->createQueryBuilder('o')
            ->select('COUNT(DISTINCT o.id)')
            ->leftJoin('o.items', 'oi')
            ->where('o.customer = :customer')
            ->andWhere('oi.orderStatus = :status')
            ->setParameter('customer', $customer)
            ->setParameter('status', 'paid')
            ->getQuery()
            ->getSingleScalarResult();
        $statistics['paid'] = (int)$paidCount;

        // 统计待收货（shipped）
        $shippedCount = $this->orderRepository->createQueryBuilder('o')
            ->select('COUNT(DISTINCT o.id)')
            ->leftJoin('o.items', 'oi')
            ->where('o.customer = :customer')
            ->andWhere('oi.orderStatus = :status')
            ->setParameter('customer', $customer)
            ->setParameter('status', 'shipped')
            ->getQuery()
            ->getSingleScalarResult();
        $statistics['shipped'] = (int)$shippedCount;

        return $this->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * 取消订单
     * 
     * 请求参数（加密后传输）：
     * - orderNo: 订单号
     * - reason: 取消原因
     */
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function cancel(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
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
        if (empty($data['orderNo'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少订单号参数',
                'messageEn' => 'Missing order number parameter'
            ], 400);
        }

        // 查询订单
        $order = $this->orderRepository->findOneBy([
            'orderNo' => $data['orderNo'],
            'customer' => $customer
        ]);

        if (!$order) {
            return $this->json([
                'success' => false,
                'message' => '订单不存在',
                'messageEn' => 'Order not found'
            ], 404);
        }

        // 检查所有订单项是否都可以取消（pending_payment 或 paid 状态）
        // 使用 OrderItemStatusService 的 canCancel 方法来判断
        foreach ($order->getItems() as $item) {
            if (!$this->orderItemStatusService->canCancel($item)) {
                $currentStatus = $item->getOrderStatus();
                return $this->json([
                    'success' => false,
                    'message' => "订单中存在无法取消的商品（当前状态：{$currentStatus}）",
                    'messageEn' => 'Some items cannot be cancelled'
                ], 400);
            }
        }

        // 取消所有订单项（使用服务处理，会自动处理退款）
        $cancelReason = $data['reason'] ?? '用户取消';
        try {
            foreach ($order->getItems() as $item) {
                $this->orderItemStatusService->cancelOrder($item, $cancelReason);
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
                'messageEn' => 'Cancel failed'
            ], 400);
        }

        // 主订单状态是聚合态，会根据订单项状态自动计算，这里只记录取消时间和原因
        $order->setCancelledTime(new \DateTime());
        $order->setCancelReason($cancelReason);

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => '订单已取消',
            'messageEn' => 'Order cancelled successfully',
            'data' => $this->formatOrder($order)
        ]);
    }

    /**
     * 取消订单项（单个商品）
     * 
     * 请求参数（加密后传输）：
     * - orderNo: 订单号
     * - itemId: 订单项ID
     * - reason: 取消原因
     */
    #[Route('/cancel-item', name: 'cancel_item', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function cancelItem(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
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
        if (empty($data['orderNo']) || empty($data['itemId'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少必要参数',
                'messageEn' => 'Missing required parameters'
            ], 400);
        }

        // 查询订单
        $order = $this->orderRepository->findOneBy([
            'orderNo' => $data['orderNo'],
            'customer' => $customer
        ]);

        if (!$order) {
            return $this->json([
                'success' => false,
                'message' => '订单不存在',
                'messageEn' => 'Order not found'
            ], 404);
        }

        // 查找订单项
        $item = null;
        foreach ($order->getItems() as $orderItem) {
            if ($orderItem->getId() == $data['itemId']) {
                $item = $orderItem;
                break;
            }
        }

        if (!$item) {
            return $this->json([
                'success' => false,
                'message' => '订单项不存在',
                'messageEn' => 'Order item not found'
            ], 404);
        }

        // 检查订单项状态是否可以取消（只有pending_payment和paid状态可以取消）
        if (!$this->orderItemStatusService->canCancel($item)) {
            return $this->json([
                'success' => false,
                'message' => '当前状态不允许取消',
                'messageEn' => 'Current status does not allow cancellation'
            ], 400);
        }

        // 使用服务取消订单项
        try {
            $this->orderItemStatusService->cancelOrder($item, $data['reason'] ?? '用户取消');
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'messageEn' => 'Cancel failed'
            ], 400);
        }

        // 主订单状态是聚合态，会根据订单项状态自动计算
        // 如果所有订单项都已取消，记录取消时间和原因
        $allCancelled = true;
        foreach ($order->getItems() as $orderItem) {
            if ($orderItem->getOrderStatus() !== 'cancelled') {
                $allCancelled = false;
                break;
            }
        }

        if ($allCancelled) {
            $order->setCancelledTime(new \DateTime());
            $order->setCancelReason($data['reason'] ?? '用户取消');
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => '商品已取消',
            'messageEn' => 'Item cancelled successfully',
            'data' => $this->formatOrder($order, true)
        ]);
    }

    /**
     * 确认收货
     * 
     * 请求参数（加密后传输）：
     * - orderNo: 订单号
     * - itemId: 订单项ID（可选，不传则确认整个订单）
     */
    #[Route('/confirm-receipt', name: 'confirm_receipt', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function confirmReceipt(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
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
        if (empty($data['orderNo'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少订单号参数',
                'messageEn' => 'Missing order number parameter'
            ], 400);
        }

        // 查询订单
        $order = $this->orderRepository->findOneBy([
            'orderNo' => $data['orderNo'],
            'customer' => $customer
        ]);

        if (!$order) {
            return $this->json([
                'success' => false,
                'message' => '订单不存在',
                'messageEn' => 'Order not found'
            ], 404);
        }

        $now = new \DateTime();

        // 如果指定了订单项ID，只确认该订单项
        if (!empty($data['itemId'])) {
            $item = null;
            foreach ($order->getItems() as $orderItem) {
                if ($orderItem->getId() == $data['itemId']) {
                    $item = $orderItem;
                    break;
                }
            }

            if (!$item) {
                return $this->json([
                    'success' => false,
                    'message' => '订单项不存在',
                    'messageEn' => 'Order item not found'
                ], 404);
            }

            // 检查状态（只有已发货状态可以确认收货）
            if ($item->getOrderStatus() !== 'shipped') {
                return $this->json([
                    'success' => false,
                    'message' => '订单项状态不允许确认收货',
                    'messageEn' => 'Order item status does not allow receipt confirmation'
                ], 400);
            }

            // 使用服务层方法确认收货，确保设置 settlementTime 和 isSettled 字段
            try {
                $this->orderItemStatusService->confirmReceived($item);
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'message' => '确认收货失败：' . $e->getMessage(),
                    'messageEn' => 'Confirm receipt failed'
                ], 400);
            }
        } else {
            // 确认整个订单
            foreach ($order->getItems() as $item) {
                if ($item->getOrderStatus() === 'shipped') {
                    // 使用服务层方法确认收货，确保设置 settlementTime 和 isSettled 字段
                    try {
                        $this->orderItemStatusService->confirmReceived($item);
                    } catch (\Exception $e) {
                        // 记录错误但继续处理其他订单项
                        // 可以在这里添加日志记录
                    }
                }
            }
        }

        // 如果所有订单项都已完成，更新订单完成时间
        $allCompleted = true;
        foreach ($order->getItems() as $item) {
            if ($item->getOrderStatus() !== 'completed') {
                $allCompleted = false;
                break;
            }
        }

        if ($allCompleted) {
            $order->setCompletedTime($now);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => '确认收货成功',
            'messageEn' => 'Receipt confirmed successfully',
            'data' => $this->formatOrder($order)
        ]);
    }

    /**
     * 格式化订单数据
     * 
     * @param Order $order 订单实体
     * @param bool $includeFullDetails 是否包含完整详情（默认false）
     * @return array
     */
    private function formatOrder(Order $order, bool $includeFullDetails = false): array
    {
        // 获取货币符号（从 site_config 表读取 configKey='site_currency' 的 configValue3）
        $currencySymbol = $this->siteConfigService->getConfigValue('site_currency');
        if ($currencySymbol) {
            $config = $this->entityManager->getRepository(\App\Entity\SiteConfig::class)->findOneBy(['configKey' => 'site_currency']);
            $currencySymbol = $config ? $config->getConfigValue3() : '$';
        }
        if (!$currencySymbol) {
            $currencySymbol = '$'; // 默认值
        }

        // 使用金融服务计算纯商品金额（不含运费）
        $pureProductAmount = $this->financialCalculator->subtract(
            (string)$order->getTotalAmount(),
            (string)$order->getShippingFee()
        );

        $formattedOrder = [
            // 订单标识
            'id' => $order->getId(),
            'orderNo' => $order->getOrderNo(),
            'businessType' => $order->getBusinessType(),
            
            // 货币符号
            'currencySymbol' => $currencySymbol,
            
            // 订单金额
            'totalAmount' => $order->getTotalAmount(),
            'productAmount' => $order->getProductAmount(),
            'pureProductAmount' => $pureProductAmount,  // 添加纯商品金额（totalAmount - shippingFee）
            'shippingFee' => $order->getShippingFee(),
            'discountAmount' => $order->getDiscountAmount(),
            'paidAmount' => $order->getPaidAmount(),
            'discountDetails' => $order->getDiscountDetails(),
            
            // 订单状态
            'paymentStatus' => $order->getPaymentStatus(),
            'aggregatedOrderStatus' => $order->getAggregatedOrderStatus(), // 聚合状态
            'aggregatedShippingStatus' => $order->getAggregatedShippingStatus(), // 物流聚合状态
            
            // 支付信息
            'paymentMethod' => $order->getPaymentMethod(),
            'shippingMethod' => $order->getShippingMethod(),
            'paymentTime' => $order->getPaymentTime()?->format('Y-m-d H:i:s'),
            'paymentTransactionId' => $order->getPaymentTransactionId(),
            
            // 时间信息
            'createdAt' => $order->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updatedAt' => $order->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'completedTime' => $order->getCompletedTime()?->format('Y-m-d H:i:s'),
            'cancelledTime' => $order->getCancelledTime()?->format('Y-m-d H:i:s'),
            'cancelReason' => $order->getCancelReason(),
            
            // 订单项数量
            'itemCount' => $order->getItems()->count(),
        ];

        // 列表视图：只包含第一个订单项的缩略信息
        if (!$includeFullDetails) {
            $items = $order->getItems();
            if (!$items->isEmpty()) {
                $firstItem = $items->first();
                $formattedOrder['firstItem'] = [
                    'productSku' => $firstItem->getProductSku(),
                    'productTitle' => $firstItem->getProductTitle(),
                    'productTitleEn' => $firstItem->getProductTitleEn(),
                    'productThumbnail' => $this->getImageUrl($firstItem->getProductThumbnail()),
                    'quantity' => $firstItem->getQuantity(),
                    'unitPrice' => $firstItem->getUnitPrice(),
                    'actualUnitPrice' => $firstItem->getActualUnitPrice(),  // 添加实际支付单价
                    'subtotalAmount' => $firstItem->getSubtotalAmount(),
                    'orderStatus' => $firstItem->getOrderStatus(),
                ];
                
                // 如果有多个商品，添加额外商品数量提示
                if ($items->count() > 1) {
                    $formattedOrder['additionalItemsCount'] = $items->count() - 1;
                }
            }
        } else {
            // 详情视图：包含完整收货信息和所有订单项
            $formattedOrder['receiverInfo'] = [
                'receiverName' => $order->getReceiverName(),
                'receiverPhone' => $order->getReceiverPhone(),
                'receiverAddress' => $order->getReceiverAddress(),
                'receiverZipcode' => $order->getReceiverZipcode(),
            ];
            
            // 添加客户信息（用于 Payoneer 支付）
            $customer = $order->getCustomer();
            if ($customer) {
                $formattedOrder['customer'] = [
                    'id' => $customer->getId(),
                    'username' => $customer->getUsername(),
                    'email' => $customer->getEmail(),
                    'realName' => $customer->getRealName(),
                ];
            }
            
            // 添加币种信息
            $formattedOrder['currency'] = $this->siteConfigService->getSiteCurrency();
            
            $formattedOrder['buyerMessage'] = $order->getBuyerMessage();
            $formattedOrder['supplierIds'] = $order->getSupplierIds();
            
            // 格式化所有订单项
            $items = [];
            foreach ($order->getItems() as $item) {
                $items[] = $this->formatOrderItem($item);
            }
            $formattedOrder['items'] = $items;
        }

        return $formattedOrder;
    }

    /**
     * 格式化订单项数据
     * 
     * @param \App\Entity\OrderItem $item 订单项实体
     * @return array
     */
    private function formatOrderItem($item): array
    {
        // 获取商品的退货地址（从 ProductShipping 表）
        $returnAddress = null;
        if ($item->getProduct()) {
            $shippingRegion = $item->getShippingRegion();
            foreach ($item->getProduct()->getShippings() as $shipping) {
                if ($shipping->getRegion() === $shippingRegion) {
                    $returnAddress = $shipping->getReturnAddress();
                    break;
                }
            }
        }
        
        return [
            // 订单项标识
            'id' => $item->getId(),
            
            // 商品信息（快照）
            'productId' => $item->getProduct()?->getId(),
            'productSku' => $item->getProductSku(),
            'productTitle' => $item->getProductTitle(),
            'productTitleEn' => $item->getProductTitleEn(),
            'productThumbnail' => $this->getImageUrl($item->getProductThumbnail()),
            'shippingRegion' => $item->getShippingRegion(),
            'businessType' => $item->getBusinessType(),
            'productSpecs' => $item->getProductSpecs(),
            
            // 供应商信息（快照）
            'supplierId' => $item->getSupplier()?->getId(),
            'supplierName' => $item->getSupplierName(),
            
            // 价格信息（快照）
            'unitPrice' => $item->getUnitPrice(),
            'originalUnitPrice' => $item->getOriginalUnitPrice(),
            'actualUnitPrice' => $item->getActualUnitPrice(),
            'quantity' => $item->getQuantity(),
            'shippingFee' => $item->getShippingFee(),
            'subtotalAmount' => $item->getSubtotalAmount(),
            'discountDetails' => $item->getDiscountDetails(),
            
            // 佣金信息
            'commissionRate' => $item->getCommissionRate(),
            'commissionAmount' => $item->getCommissionAmount(),
            'supplierIncome' => $item->getSupplierIncome(),
            
            // 订单项状态
            'orderStatus' => $item->getOrderStatus(),
            
            // 退款信息
            'refundStatus' => $item->getRefundStatus(),
            'refundQuantity' => $item->getRefundQuantity(),
            'refundAmount' => $item->getRefundAmount(),
            'refundReason' => $item->getRefundReason(),
            'refundRejectReason' => $item->getRefundRejectReason(),
            'refundTime' => $item->getRefundTime()?->format('Y-m-d H:i:s'),
            
            // 物流信息
            'logisticsCompany' => $item->getLogisticsCompany(),
            'logisticsNo' => $item->getLogisticsNo(),
            'shippedTime' => $item->getShippedTime()?->format('Y-m-d H:i:s'),
            'receivedTime' => $item->getReceivedTime()?->format('Y-m-d H:i:s'),
            
            // 退货物流信息（买家退货时填写）
            'refundLogisticsCompany' => $item->getRefundLogisticsCompany(),
            'refundLogisticsNo' => $item->getRefundLogisticsNo(),
            'refundBuyerShippedTime' => $item->getRefundBuyerShippedTime()?->format('Y-m-d H:i:s'),
            
            // 退货地址（商家同意退款后，买家需要退货到此地址）
            'returnAddress' => $returnAddress,
            
            // 结算信息
            'settlementTime' => $item->getSettlementTime()?->format('Y-m-d H:i:s'),
            'isSettled' => $item->getIsSettled(),
            'settledAt' => $item->getSettledAt()?->format('Y-m-d H:i:s'),
            
            // 退货期判断
            'isWithinReturnPeriod' => $item->isWithinReturnPeriod(),
            'returnDeadline' => $item->getReturnDeadline()?->format('Y-m-d H:i:s'),
            
            // 时间戳
            'createdAt' => $item->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updatedAt' => $item->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 获取图片URL（处理七牛云私有空间）
     * 
     * @param string|null $imageKey 图片key
     * @return string|null
     */
    private function getImageUrl(?string $imageKey): ?string
    {
        if (!$imageKey) {
            return null;
        }

        // 如果已经是完整URL，直接返回
        if (str_starts_with($imageKey, 'http')) {
            return $imageKey;
        }

        // 使用七牛云服务生成带签名的私有URL
        return $this->qiniuUploadService->getPrivateUrl($imageKey);
    }



    /**
     * 申请退货/退款
     * 
     * 请求参数（加密后传输）：
     * - orderNo: 订单号
     * - itemId: 订单项ID（可选，不传则申请整个订单退款）
     * - refundReason: 退款原因
     * - refundQuantity: 退款数量（可选，null表示全额退款）
     * - refundAmount: 退款金额（可选，null表示全额退款）
     */
    #[Route('/apply-refund', name: 'apply_refund', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function applyRefund(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
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
        if (empty($data['orderNo']) || empty($data['refundReason'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少必要参数',
                'messageEn' => 'Missing required parameters'
            ], 400);
        }

        // 查询订单
        $order = $this->orderRepository->findOneBy([
            'orderNo' => $data['orderNo'],
            'customer' => $customer
        ]);

        if (!$order) {
            return $this->json([
                'success' => false,
                'message' => '订单不存在',
                'messageEn' => 'Order not found'
            ], 404);
        }

        try {
            // 如果指定了订单项ID，只申请该订单项退款
            if (!empty($data['itemId'])) {
                $item = null;
                foreach ($order->getItems() as $orderItem) {
                    if ($orderItem->getId() == $data['itemId']) {
                        $item = $orderItem;
                        break;
                    }
                }

                if (!$item) {
                    return $this->json([
                        'success' => false,
                        'message' => '订单项不存在',
                        'messageEn' => 'Order item not found'
                    ], 404);
                }

                // 调用服务层申请退款
                $result = $this->orderItemStatusService->applyRefund(
                    $item,
                    $data['refundReason'],
                    isset($data['refundAmount']) ? (float)$data['refundAmount'] : null
                );
                
                // 如果失败，直接返回错误
                if (!$result['success']) {
                    return $this->json($result, 400);
                }

                return $this->json([
                    'success' => true,
                    'message' => $result['message'],
                    'messageEn' => $result['messageEn'],
                    'data' => $this->formatOrder($order, true)
                ]);
            } else {
                // 申请整个订单退款（所有订单项）
                $successCount = 0;
                $failedItems = [];

                foreach ($order->getItems() as $item) {
                    // 只处理已完成且未退款的订单项
                    if ($item->getOrderStatus() === 'completed' && $item->getRefundStatus() === 'none') {
                        $result = $this->orderItemStatusService->applyRefund(
                            $item,
                            $data['refundReason'],
                            null // 整个订单退款时，默认全额退款
                        );
                        
                        if ($result['success']) {
                            $successCount++;
                        } else {
                            $failedItems[] = [
                                'itemId' => $item->getId(),
                                'productTitle' => $item->getProductTitle(),
                                'error' => $result['message'],
                                'errorEn' => $result['messageEn']
                            ];
                        }
                    }
                }

                if ($successCount === 0) {
                    return $this->json([
                        'success' => false,
                        'message' => '没有符合条件的订单项可以申请退款',
                        'messageEn' => 'No items available for refund',
                        'failedItems' => $failedItems
                    ], 400);
                }

                return $this->json([
                    'success' => true,
                    'message' => "已成功申请 {$successCount} 个订单项退款" . (count($failedItems) > 0 ? "，" . count($failedItems) . "个失败" : ''),
                    'messageEn' => 'Refund request submitted successfully',
                    'data' => [
                        'successCount' => $successCount,
                        'failedItems' => $failedItems,
                        'order' => $this->formatOrder($order, true)
                    ]
                ]);
            }
        } catch (\Exception $e) {
            // 处理预期外的异常（数据库错误等）
            return $this->json([
                'success' => false,
                'message' => '系统错误：' . $e->getMessage(),
                'messageEn' => 'System error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 买家退货发货（填写退货物流信息）
     * 
     * 业务说明：
     * - 商家同意退款后，买家填写退货物流信息
     * - 前置条件：orderStatus = 'refunding' && refundStatus = 'approved'
     * - 更新退款状态为 'buyer_shipped'，记录退货物流信息和时间
     */
    #[Route('/ship-refund', name: 'ship_refund', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function shipRefund(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        // 解密和验证请求数据
        $requestData = json_decode($request->getContent(), true);
        if (isset($requestData['encryptedPayload'])) {
            $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
        } else {
            $data = $requestData;
        }
        
        // 验证必要参数
        if (empty($data['orderNo']) || empty($data['itemId']) || empty($data['logisticsCompany']) || empty($data['logisticsNo'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少必要参数',
                'messageEn' => 'Missing required parameters'
            ], 400);
        }
        
        // 验证物流公司数据结构
        if (!isset($data['logisticsCompany']['id']) || !isset($data['logisticsCompany']['name']) || !isset($data['logisticsCompany']['nameEn'])) {
            return $this->json([
                'success' => false,
                'message' => '物流公司数据格式错误',
                'messageEn' => 'Invalid logistics company data format'
            ], 400);
        }
        
        try {
            // 查询订单
            $order = $this->orderRepository->findOneBy([
                'orderNo' => $data['orderNo'],
                'customer' => $customer
            ]);
            
            if (!$order) {
                return $this->json([
                    'success' => false,
                    'message' => '订单不存在',
                    'messageEn' => 'Order not found'
                ], 404);
            }
            
            // 查找订单项
            $item = null;
            foreach ($order->getItems() as $orderItem) {
                if ($orderItem->getId() == $data['itemId']) {
                    $item = $orderItem;
                    break;
                }
            }
            
            if (!$item) {
                return $this->json([
                    'success' => false,
                    'message' => '订单项不存在',
                    'messageEn' => 'Order item not found'
                ], 404);
            }
            
            // 验证状态：必须是 refunding + approved
            if ($item->getOrderStatus() !== 'refunding' || $item->getRefundStatus() !== 'approved') {
                return $this->json([
                    'success' => false,
                    'message' => '当前状态不允许填写退货物流信息（必须商家已同意退款）',
                    'messageEn' => 'Current status does not allow shipping refund (merchant must approve first)'
                ], 400);
            }
            
            // 检查是否已经填写过物流信息
            if ($item->getRefundLogisticsNo()) {
                return $this->json([
                    'success' => false,
                    'message' => '已经填写过退货物流信息，不能重复提交',
                    'messageEn' => 'Refund shipping info already submitted'
                ], 400);
            }
            
            // 更新退款状态为 'buyer_shipped'
            $item->setRefundStatus('buyer_shipped');
            
            // 记录退货物流信息（使用前端传递的完整数据）
            $item->setRefundLogisticsCompany([
                'id' => $data['logisticsCompany']['id'],
                'name_zh' => $data['logisticsCompany']['name'],
                'name_en' => $data['logisticsCompany']['nameEn']
            ]);
            $item->setRefundLogisticsNo($data['logisticsNo']);
            $item->setRefundBuyerShippedTime(new \DateTime());
            
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '退货发货成功，等待商家确认收货',
                'messageEn' => 'Refund shipped successfully, waiting for merchant to confirm receipt',
                'data' => $this->formatOrder($order, true)
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '系统错误：' . $e->getMessage(),
                'messageEn' => 'System error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 搜索物流公司
     * 
     * 请求参数：
     * - keyword: 搜索关键词（搜索中文名或英文名）
     * 
     * 返回数据：符合条件的物流公司列表
     */
    #[Route('/search-logistics', name: 'search_logistics', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function searchLogistics(Request $request): JsonResponse
    {
        $requestData = json_decode($request->getContent(), true);
        
        // 解密请求数据
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }
        
        $keyword = $data['keyword'] ?? '';
        
        try {
            // 查询活跃的物流公司
            $qb = $this->logisticsCompanyRepository->createQueryBuilder('lc')
                ->where('lc.isActive = :active')
                ->setParameter('active', true)
                ->orderBy('lc.sortOrder', 'ASC')
                ->addOrderBy('lc.name', 'ASC');
            
            // 如果有搜索关键词，添加搜索条件
            if (!empty($keyword)) {
                $qb->andWhere('lc.name LIKE :keyword OR lc.nameEn LIKE :keyword')
                   ->setParameter('keyword', '%' . $keyword . '%');
            }
            
            // 限制返回数量
            $qb->setMaxResults(20);
            
            $companies = $qb->getQuery()->getResult();
            
            // 格式化数据
            $result = [];
            foreach ($companies as $company) {
                $result[] = [
                    'id' => $company->getId(),
                    'name' => $company->getName(),
                    'nameEn' => $company->getNameEn(),
                    'value' => $company->getName() . ' / ' . $company->getNameEn() // 用于显示和搜索
                ];
            }
            
            return $this->json([
                'success' => true,
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '搜索失败：' . $e->getMessage(),
                'messageEn' => 'Search failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更新订单支付方式
     * 
     * 请求参数（加密后传输）：
     * - orderNo: 订单号
     * - paymentMethod: 支付方式（balance/payoneer等）
     * 
     * 返回数据：更新结果
     */
    #[Route('/update-payment-method', name: 'update_payment_method', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature(message: '支付请求签名验证失败，请重试', messageEn: 'Payment request signature verification failed, please try again', checkNonce: true)]
    public function updatePaymentMethod(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重新登录后再试',
                'messageEn' => 'Request failed, please log in again and try'
            ], 400);
        }
        
        // 验证必须参数
        $orderNo = $data['orderNo'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? null;
        
        if (!$orderNo || !$paymentMethod) {
            return $this->json([
                'success' => false,
                'message' => '缺少必须参数',
                'messageEn' => 'Missing required parameters'
            ], 400);
        }
        
        try {
            // 查找订单
            $order = $this->orderRepository->findOneBy([
                'orderNo' => $orderNo,
                'customer' => $customer
            ]);
            
            if (!$order) {
                return $this->json([
                    'success' => false,
                    'message' => '订单不存在',
                    'messageEn' => 'Order not found'
                ], 404);
            }
            
            // 检查订单是否已经支付
            if ($order->getPaymentStatus() === 'paid') {
                return $this->json([
                    'success' => false,
                    'message' => '订单已经支付，无法重复支付',
                    'messageEn' => 'Order has already been paid'
                ], 400);
            }
            
            // 开启数据库事务
            $this->entityManager->getConnection()->beginTransaction();
            
            try {
                // 如果是余额支付，需要检查余额并扣减
                if ($paymentMethod === 'balance') {
                    $totalAmount = $order->getTotalAmount();
                    
                    // 检查余额是否足够
                    if ((float)$customer->getBalance() < (float)$totalAmount) {
                        $this->entityManager->getConnection()->rollBack();
                        return $this->json([
                            'success' => false,
                            'message' => '余额不足，无法完成支付',
                            'messageEn' => 'Insufficient balance',
                            'data' => [
                                'required' => $totalAmount,
                                'available' => $customer->getBalance()
                            ]
                        ], 400);
                    }
                    
                    // 扣减余额
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
                        "订单支付：{$orderNo}",
                        $orderNo,
                        null
                    );
                    
                    // 处理支付和冻结供应商余额
                    foreach ($order->getItems() as $orderItem) {
                        $this->orderItemStatusService->confirmPayment($orderItem);
                    }
                }
                
                // 更新支付方式
                $order->setPaymentMethod($paymentMethod);
                
                // 更新支付状态为已支付
                $order->setPaymentStatus('paid');
                $order->setPaymentTime(new \DateTime());
                
                $this->entityManager->flush();
                $this->entityManager->getConnection()->commit();
                
                return $this->json([
                    'success' => true,
                    'message' => '支付成功！',
                    'messageEn' => 'Payment successful!'
                ]);
                
            } catch (\Exception $e) {
                $this->entityManager->getConnection()->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '系统错误：' . $e->getMessage(),
                'messageEn' => 'System error: ' . $e->getMessage()
            ], 500);
        }
    }
}
