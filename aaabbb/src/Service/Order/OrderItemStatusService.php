<?php

namespace App\Service\Order;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\BalanceHistory;
use App\Entity\CustomerMonthlyStats;
use App\Repository\BalanceHistoryRepository;
use App\Repository\OrderItemRepository;
use App\Repository\CustomerMonthlyStatsRepository;
use App\Repository\CustomerRepository;
use App\Repository\SiteConfigRepository;
use App\Service\BalanceHistoryService;
use App\Service\FinancialCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 订单项状态服务
 * 
 * 功能说明：
 * 1. 统一管理订单项的状态转换
 * 2. 处理退款流程（支持部分退款）
 * 3. 管理供应商余额的冻结和解冻
 * 4. 退款给会员、恢复库存
 * 5. 验证状态转换的合法性
 * 
 * 状态流转：
 * pending_payment（待支付）→ paid（已支付）→ shipped（已发货）→ completed（已完成）
 *                                                                    ↓（7天内）
 *                                                            refunding（退款中）→ refunded（已退款）
 * 
 * 任意状态都可以 → cancelled（已取消）
 */
class OrderItemStatusService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderItemRepository $orderItemRepository,
        private BalanceHistoryRepository $balanceHistoryRepository,
        private BalanceHistoryService $balanceHistoryService,
        private FinancialCalculatorService $financialCalculator,
        private LoggerInterface $logger,
        private CustomerMonthlyStatsRepository $monthlyStatsRepository,
        private CustomerRepository $customerRepository,
        private SiteConfigRepository $siteConfigRepository
    ) {
    }

    /**
     * 确认支付
     * 
     * 业务说明：
     * - 状态转换：pending_payment → paid
     * - 供应商余额：冻结货款（balanceFrozen += 订单金额）
     * - 余额记录：type='order_frozen'
     * 
     * @param OrderItem $orderItem 订单项
     * @throws \Exception 如果状态不正确
     */
    public function confirmPayment(OrderItem $orderItem): void
    {
        // 验证当前状态
        if ($orderItem->getOrderStatus() !== 'pending_payment') {
            throw new \Exception('只有待支付状态的订单才能确认支付');
        }

        // 更新订单状态
        $orderItem->setOrderStatus('paid');

        // 冻结供应商余额
        $this->freezeSupplierBalance($orderItem);

        // 保存
        $this->entityManager->flush();

        $this->logger->info('订单已确认支付', [
            'order_item_id' => $orderItem->getId(),
            'amount' => $orderItem->getSubtotalAmount()
        ]);
    }

    /**
     * 标记已发货
     * 
     * 业务说明：
     * - 状态转换：paid → shipped
     * - 记录物流信息
     * 
     * @param OrderItem $orderItem 订单项
     * @param array $logisticsInfo 物流信息 ['company' => '顺丰', 'tracking_no' => 'SF123456']
     * @throws \Exception 如果状态不正确
     */
    public function markAsShipped(OrderItem $orderItem, array $logisticsInfo): void
    {
        // 验证状态
        if (!$this->canShip($orderItem)) {
            throw new \Exception('当前订单状态不允许发货');
        }

        // 更新状态
        $orderItem->setOrderStatus('shipped');
        $orderItem->setShippedTime(new \DateTime());

        // 记录物流信息
        if (isset($logisticsInfo['company'])) {
            $orderItem->setLogisticsCompany($logisticsInfo['company']); // JSON格式
        }
        if (isset($logisticsInfo['tracking_no'])) {
            $orderItem->setLogisticsNo($logisticsInfo['tracking_no']);
        }

        $this->entityManager->flush();

        $this->logger->info('订单已发货', [
            'order_item_id' => $orderItem->getId(),
            'logistics' => $logisticsInfo
        ]);
    }

    /**
     * 确认收货
     * 
     * 业务说明：
     * - 状态转换：shipped → completed
     * - 余额变更：不改变供应商任何余额（资金保持在冻结状态等待结算）
     * - 余额记录：不产生余额记录
     * - 记录收货时间（用于计算7天退货期）
     * - 设置可结算时间（收货时间 + 8天）
     * - 设置is_settled为false（等待定时任务结算）
     * - VIP升级：根据当月累计消费金额自动升级VIP等级
     * 
     * @param OrderItem $orderItem 订单项
     * @throws \Exception 如果状态不正确
     */
    public function confirmReceived(OrderItem $orderItem): void
    {
        // 验证状态
        if (!$this->canReceive($orderItem)) {
            throw new \Exception('当前订单状态不允许确认收货');
        }

        // 获取当前时间
        $receivedTime = new \DateTime();
        
        // 计算可结算时间（收货后8天）
        $settlementTime = clone $receivedTime;
        $settlementTime->modify('+8 days');

        // 更新订单状态
        $orderItem->setOrderStatus('completed');
        $orderItem->setReceivedTime($receivedTime);
        $orderItem->setSettlementTime($settlementTime);
        $orderItem->setIsSettled(false); // 设置为未结算，等待定时任务处理

        // 注意：确认收货时不改变供应商任何余额，也不产生余额记录
        // 资金仍在待结算状态，等待定时任务到达可结算时间后进行结算

        $this->entityManager->flush();

        // ==================== VIP升级逻辑开始 ====================
        // 参考文档：zreadme\VIP下载规则.md 中的 "3.4 订单确认收货流程（VIP升级）"
        try {
            // 1. 获取客户ID和订单金额
            $customer = $orderItem->getOrder()->getCustomer();
            if (!$customer) {
                $this->logger->warning('VIP升级失败：未找到客户信息', [
                    'order_item_id' => $orderItem->getId()
                ]);
                return; // 如果找不到客户，跳过VIP升级，不影响订单收货流程
            }
            
            $customerId = $customer->getId();
            $subtotalAmount = (float)$orderItem->getSubtotalAmount(); // 订单总金额（含运费）
            $shippingFee = (float)$orderItem->getShippingFee(); // 运费
            $orderAmount = $subtotalAmount - $shippingFee; // 纯商品金额（不含运费）
            
            // 2. 获取当前年月
            $currentYear = (int)date('Y');
            $currentMonth = (int)date('n');
            
            // 3. 获取或创建当月统计记录
            // 如果当月记录不存在，会自动创建一条新记录
            $monthlyStats = $this->getOrCreateMonthlyStats($customerId, $currentYear, $currentMonth);
            
            // 4. 累加订单金额和订单数（只累加商品金额，不包含运费）
            $monthlyStats->setTotalOrderAmount(
                $monthlyStats->getTotalOrderAmount() + $orderAmount
            );
            $monthlyStats->setTotalOrders($monthlyStats->getTotalOrders() + 1);
            
            // 4.1. 累加客户的永久消费金额（consumption_amount）
            $customer->addConsumptionAmount($orderAmount);
            
            $this->logger->info('VIP升级-累加消费金额', [
                'customer_id' => $customerId,
                'order_amount' => $orderAmount,
                'monthly_total' => $monthlyStats->getTotalOrderAmount(),
                'consumption_amount' => $customer->getConsumptionAmount()
            ]);
            
            // 5. 根据累计消费金额和当前VIP等级计算应有的VIP等级
            $currentVipLevel = $customer->getVipLevel();
            $newVipLevel = $this->calculateVipLevel(
                $monthlyStats->getTotalOrderAmount(),
                $currentVipLevel
            );
            
            // 6. 只有当新等级高于当前等级时才更新（VIP等级只升不降）
            if ($newVipLevel > $currentVipLevel) {
                $customer->setVipLevel($newVipLevel);
                
                // 记录VIP升级日志
                $this->logger->info('VIP等级升级', [
                    'customer_id' => $customerId,
                    'from_level' => $currentVipLevel,
                    'to_level' => $newVipLevel,
                    'monthly_amount' => $monthlyStats->getTotalOrderAmount(),
                    'order_item_id' => $orderItem->getId()
                ]);
            }
            
            // 7. 保存所有更改
            $this->entityManager->flush();
            
        } catch (\Exception $e) {
            // VIP升级失败不影响订单收货流程，只记录错误日志
            $this->logger->error('VIP升级失败', [
                'order_item_id' => $orderItem->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        // ==================== VIP升级逻辑结束 ====================

        $this->logger->info('订单已确认收货', [
            'order_item_id' => $orderItem->getId(),
            'received_time' => $receivedTime->format('Y-m-d H:i:s'),
            'settlement_time' => $settlementTime->format('Y-m-d H:i:s'),
            'note' => '资金保持在待结算状态，等待定时任务结算'
        ]);
    }

    /**
     * 取消订单
     * 
     * 业务说明：
     * - 状态转换：任意状态 → cancelled
     * - 如果已支付，释放供应商冻结余额并退款给会员
     * - 退款金额：商品金额（不含运费，运费由买家承担）
     * - 余额记录：type='order_refund'（供应商）和 type='order_refund'（会员）
     * 
     * @param OrderItem $orderItem 订单项
     * @param string $reason 取消原因
     * @throws \Exception 如果状态不允许取消
     */
    public function cancelOrder(OrderItem $orderItem, string $reason): void
    {
        // 验证状态
        if (!$this->canCancel($orderItem)) {
            throw new \Exception('当前订单状态不允许取消');
        }

        $previousStatus = $orderItem->getOrderStatus();

        // 更新状态
        $orderItem->setOrderStatus('cancelled');
        // 注意：取消原因可以记录在refundReason字段或通过日志记录
        // $orderItem->setRefundReason($reason); // 如果需要保存取消原因

        // 如果已支付，需要释放供应商冻结余额并退款给会员
        if (in_array($previousStatus, ['paid', 'shipped'])) {
            $this->releaseSupplierFrozenBalance($orderItem);
            $this->refundToCustomerForCancel($orderItem);
        }

        $this->entityManager->flush();

        $this->logger->info('订单已取消', [
            'order_item_id' => $orderItem->getId(),
            'reason' => $reason,
            'previous_status' => $previousStatus
        ]);
    }

    /**
     * 申请退款
     * 
     * 业务说明：
     * - 状态转换：completed → refunding
     * - 退款状态：none → applying
     * - 检查：completed状态必须在收货后7天内
     * - 前提：只支持 completed（已确认收货）状态
     * 
     * 支持的退款场景：
     * 1. 已确认收货（completed）：买家在7天内申请退款，供应商同意后从待结算金额扣除
     * 
     * 不支持的场景：
     * - 已支付未发货（paid）：应使用“取消订单”功能，直接取消并退款
     * - 已发货未收货（shipped）：应先确认收货或联系供应商处理
     * - 待支付（pending_payment）：直接取消订单即可
     * 
     * @param OrderItem $orderItem 订单项
     * @param string $reason 退款原因
     * @param float|null $amount 退款金额（null表示全额退款）
     * @throws \Exception 如果不满足退款条件
     */
    public function applyRefund(OrderItem $orderItem, string $reason, ?float $amount = null): array
    {
        // 验证订单状态：只支持 completed（已确认收货）
        if ($orderItem->getOrderStatus() !== 'completed') {
            $currentStatus = $this->getStatusText($orderItem->getOrderStatus());
            if ($orderItem->getOrderStatus() === 'paid') {
                return [
                    'success' => false,
                    'message' => '当前状态为已支付未发货，请使用“取消订单”功能直接取消并退款。',
                    'messageEn' => 'For paid but unshipped orders, please use "Cancel Order" function'
                ];
            }
            return [
                'success' => false,
                'message' => "当前状态不支持退款（当前状态：{$currentStatus}）。只有已确认收货的订单才能申请退款。",
                'messageEn' => 'Only received orders can apply for refund (Current status: ' . $currentStatus . ')'
            ];
        }
        
        // 验证退款状态
        if ($orderItem->getRefundStatus() !== 'none') {
            $currentRefundStatus = $orderItem->getRefundStatus();
            
            if ($currentRefundStatus === 'rejected') {
                return [
                    'success' => false,
                    'message' => '该订单的退款申请已被拒绝，不可再次申请退款（rejected 为最终状态）',
                    'messageEn' => 'Previous refund request was rejected, cannot apply again (rejected is final status)'
                ];
            }
            
            return [
                'success' => false,
                'message' => '该订单已经申请过退款，不能重复申请（当前退款状态：' . $currentRefundStatus . '）',
                'messageEn' => 'Refund already requested, cannot apply again (Current status: ' . $currentRefundStatus . ')'
            ];
        }

        // 检查7天退货期（仅对已确认收货的订单检查）
        if (!$orderItem->isWithinReturnPeriod()) {
            $deadline = $orderItem->getReturnDeadline()->format('Y-m-d H:i:s');
            return [
                'success' => false,
                'message' => "已超过退货期限（截止时间：{$deadline}），无法申请退款",
                'messageEn' => "Return period has expired (Deadline: {$deadline})"
            ];
        }

        // 计算默认退款金额：商品金额（不含运费）
        $subtotalAmount = (float)$orderItem->getSubtotalAmount(); // 用户支付总额（含运费）
        $shippingFee = (float)$orderItem->getShippingFee(); // 运费
        $productAmount = $this->financialCalculator->subtract((string)$subtotalAmount, (string)$shippingFee); // 纯商品金额
        
        // 默认全额退款（只退商品金额，不退运费）
        $refundAmount = $amount ?? (float)$productAmount;

        // 验证退款金额（不能超过商品金额）
        if ($refundAmount > (float)$productAmount) {
            return [
                'success' => false,
                'message' => '退款金额不能超过商品金额（运费不退）',
                'messageEn' => 'Refund amount cannot exceed product amount (shipping fee non-refundable)'
            ];
        }

        // 更新状态
        $orderItem->setOrderStatus('refunding');
        $orderItem->setRefundStatus('applying');
        $orderItem->setRefundReason($reason);
        $orderItem->setRefundAmount((string)$refundAmount);
        $orderItem->setRefundApplyingTime(new \DateTime()); // 记录退款申请时间

        $this->entityManager->flush();

        $this->logger->info('申请退款', [
            'order_item_id' => $orderItem->getId(),
            'reason' => $reason,
            'amount' => $refundAmount
        ]);
        
        return [
            'success' => true,
            'message' => '退货申请已提交，请等待商家审核',
            'messageEn' => 'Refund request submitted successfully, waiting for merchant review'
        ];
    }

    /**
     * 同意退款
     * 
     * 业务说明：
     * - 退款状态：applying → approved
     * - 不触发任何余额变更，只记录状态和批准时间
     * - 实际退款操作由 completeRefund() 方法执行
     * 
     * 设计理由：
     * - approved 状态仅用于流程跟踪，不影响余额
     * - 只有 completed 状态才执行实际的资金操作
     * - 符合退款流程设计规范
     * 
     * @param OrderItem $orderItem 订单项
     * @throws \Exception 如果状态不正确
     */
    public function approveRefund(OrderItem $orderItem): void
    {
        if ($orderItem->getRefundStatus() !== 'applying') {
            throw new \Exception('只有申请中的退款才能审核');
        }

        // 设置为已同意状态，并记录批准时间
        $orderItem->setRefundStatus('approved');
        $orderItem->setRefundApprovedTime(new \DateTime()); // 记录批准时间
        
        // 注意：不执行任何余额操作，余额和余额记录全不变
        // 实际退款由 completeRefund() 方法在 completed 状态时执行
        
        $this->entityManager->flush();

        $this->logger->info('退款已同意（等待后续完成操作）', [
            'order_item_id' => $orderItem->getId(),
            'refund_approved_time' => $orderItem->getRefundApprovedTime()->format('Y-m-d H:i:s'),
            'note' => '仅更新状态，不影响余额'
        ]);
    }

    /**
     * 拒绝退款
     * 
     * 业务说明：
     * - 退款状态：applying → rejected（最终状态，不可逆）
     * - 订单状态：refunding → completed（恢复已完成状态）
     * - 记录拒绝原因到 refundRejectReason 字段
     * - 记录退款完成时间（表示退款流程已结束）
     * - 重要：拒绝后不可再次申请退款，rejected 是终态
     * 
     * @param OrderItem $orderItem 订单项
     * @param string $reason 拒绝原因
     * @throws \Exception 如果状态不正确
     */
    public function rejectRefund(OrderItem $orderItem, string $reason): void
    {
        if ($orderItem->getRefundStatus() !== 'applying') {
            throw new \Exception('只有申请中的退款才能拒绝');
        }

        $now = new \DateTime();
        
        $orderItem->setRefundStatus('rejected');
        $orderItem->setRefundRejectReason($reason); // 使用专用字段记录拒绝原因
        $orderItem->setRefundRejectedTime($now); // 记录拒绝时间
        $orderItem->setRefundTime($now); // 记录退款流程完成时间
        $orderItem->setOrderStatus('completed'); // 恢复已完成状态

        $this->entityManager->flush();

        $this->logger->info('退款已拒绝', [
            'order_item_id' => $orderItem->getId(),
            'reason' => $reason,
            'refund_rejected_time' => $now->format('Y-m-d H:i:s'),
            'refund_time' => $now->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * 完成退款（整合OrderRefundService功能）
     * 
     * 业务说明：
     * - 状态转换：refunding → refunded
     * - 退款状态：approved 或 buyer_shipped → completed
     * - 供应商余额：解冻冻结余额
     * - 会员余额：增加退款金额
     * - 商品库存：恢复退款数量
     * - 订单主表：更新支付状态
     * - 余额记录：type='order_refund'
     * 
     * @param OrderItem $orderItem 订单项
     * @param int|null $refundQuantity 退款数量（null表示全部退款）
     * @throws \Exception 如果状态不正确
     */
    public function completeRefund(OrderItem $orderItem, ?int $refundQuantity = null): void
    {
        // 验证退款状态：必须是 approved（商家已同意）或 buyer_shipped（买家已发货）
        if (!in_array($orderItem->getRefundStatus(), ['approved', 'buyer_shipped'])) {
            $currentStatus = $orderItem->getRefundStatus();
            throw new \Exception("当前退款状态不允许完成退款（当前状态：{$currentStatus}，需要：approved 或 buyer_shipped）");
        }

        try {
            $this->entityManager->beginTransaction();
            
            $order = $orderItem->getOrder();
            $customer = $order->getCustomer();
            $supplier = $orderItem->getSupplier();
            
            // 验证退款数量
            $totalQuantity = $orderItem->getQuantity();
            $refundQty = $refundQuantity ?? $totalQuantity;
            
            if ($refundQty <= 0 || $refundQty > $totalQuantity) {
                throw new \Exception('退款数量无效');
            }
            
            // 1. 计算退款金额
            $refundAmount = $this->calculateRefundAmount($orderItem, $refundQty);
            
            $this->logger->info('[订单退款] 开始处理退款', [
                'order_no' => $order->getOrderNo(),
                'order_item_id' => $orderItem->getId(),
                'refund_quantity' => $refundQty,
                'refund_amount' => $refundAmount
            ]);
            
            // 2. 退款给会员
            $this->refundToCustomer($customer, $refundAmount, $order->getOrderNo(), $orderItem->getId());
            
            // 3. 解冻供应商余额
            $supplierRefundAmount = $this->calculateSupplierRefundAmount($orderItem, $refundQty);
            $this->unfreezeSupplierBalanceForRefund($supplier, $supplierRefundAmount, $order->getOrderNo(), $orderItem->getId(), $orderItem);
            
            // 4. 恢复库存
            $this->restoreStock($orderItem, $refundQty);
            
            // 5. 扣减当月消费金额（从 customer_monthly_stats 表的 total_order_amount 中减去退款金额）
            try {
                $customerId = $customer->getId();
                $currentYear = (int)date('Y');
                $currentMonth = (int)date('n');
                
                // 查找当月统计记录
                $monthlyStats = $this->monthlyStatsRepository->findOneBy([
                    'customerId' => $customerId,
                    'year' => $currentYear,
                    'month' => $currentMonth
                ]);
                
                if ($monthlyStats) {
                    // 扣减退款金额，确保扣减后金额不小于零
                    $currentAmount = $monthlyStats->getTotalOrderAmount();
                    $newAmount = max(0.00, $currentAmount - (float)$refundAmount);
                    $monthlyStats->setTotalOrderAmount($newAmount);
                    
                    $this->logger->info('[订单退款] 扣减当月消费金额', [
                        'customer_id' => $customerId,
                        'year' => $currentYear,
                        'month' => $currentMonth,
                        'refund_amount' => $refundAmount,
                        'before_amount' => $currentAmount,
                        'after_amount' => $newAmount
                    ]);
                } else {
                    $this->logger->warning('[订单退款] 未找到当月统计记录，无法扣减消费金额', [
                        'customer_id' => $customerId,
                        'year' => $currentYear,
                        'month' => $currentMonth
                    ]);
                }
                
                // 5.1. 扣减客户的永久消费金额（consumption_amount）
                $currentConsumption = $customer->getConsumptionAmount();
                $newConsumption = max(0.00, $currentConsumption - (float)$refundAmount);
                $customer->setConsumptionAmount($newConsumption);
                
                $this->logger->info('[订单退款] 扣减永久消费金额', [
                    'customer_id' => $customerId,
                    'refund_amount' => $refundAmount,
                    'before_consumption' => $currentConsumption,
                    'after_consumption' => $newConsumption
                ]);
            } catch (\Exception $e) {
                // 扣减消费金额失败不影响退款流程，只记录错误日志
                $this->logger->error('[订单退款] 扣减当月消费金额失败', [
                    'customer_id' => $customerId,
                    'error' => $e->getMessage()
                ]);
            }
            
            // 6. 更新订单项状态
            $orderItem->setOrderStatus('refunded');
            $orderItem->setRefundStatus('completed');
            $orderItem->setRefundAmount($refundAmount);
            $orderItem->setRefundQuantity($refundQty);
            $orderItem->setRefundTime(new \DateTime());
            
            // 7. 更新订单主表状态
            $this->updateOrderPaymentStatus($order);
            
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('[订单退款] 退款已完成', [
                'order_item_id' => $orderItem->getId(),
                'refund_amount' => $refundAmount,
                'refund_quantity' => $refundQty
            ]);
            
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            
            $this->logger->error('[订单退款] 退款处理失败', [
                'order_item_id' => $orderItem->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \Exception('退款处理失败：' . $e->getMessage());
        }
    }

    // ==================== 状态验证方法 ====================

    /**
     * 检查是否可以发货
     */
    public function canShip(OrderItem $orderItem): bool
    {
        return $orderItem->getOrderStatus() === 'paid';
    }

    /**
     * 检查是否可以确认收货
     */
    public function canReceive(OrderItem $orderItem): bool
    {
        return $orderItem->getOrderStatus() === 'shipped';
    }

    /**
     * 检查是否可以退款
     * 
     * 条件：
     * 1. 订单状态为已完成
     * 2. 在7天退货期内
     * 3. 未发起过退款
     */
    public function canRefund(OrderItem $orderItem): bool
    {
        return $orderItem->getOrderStatus() === 'completed'
            && $orderItem->getRefundStatus() === 'none'
            && $orderItem->isWithinReturnPeriod();
    }

    /**
     * 检查是否可以取消
     * 
     * 条件：
     * 1. 只有待支付（pending_payment）和已支付（paid）状态可以取消
     * 2. 已发货（shipped）、已完成（completed）的订单不能取消
     * 3. 已退款中（refunding）、已退款（refunded）、已取消（cancelled）也不能取消
     * 
     * 业务规则：
     * - 待支付：可以取消，直接取消即可
     * - 已支付未发货：可以取消，需释放供应商冻结余额
     * - 已发货：不能取消，应等待收货或联系供应商处理
     * - 已完成：不能取消，如需退货请走退款流程
     */
    public function canCancel(OrderItem $orderItem): bool
    {
        $status = $orderItem->getOrderStatus();
        // 只有待支付和已支付未发货的订单可以取消
        return in_array($status, ['pending_payment', 'paid']);
    }

    // ==================== 供应商余额处理（私有方法）====================

    /**
     * 冻结供应商余额（新版：增加待结算金额）
     * 
     * 说明：订单支付后，直接增加供应商待结算金额，而不是冻结余额
     * 增加金额：供应商收入（supplier_income = 纯商品金额 - 佣金）
     * 操作：pendingSettlementAmount += supplier_income
     * 记录：type='order_frozen'（订单支付增加待结算）和 type='commission'（扫除佣金）
     */
    private function freezeSupplierBalance(OrderItem $orderItem): void
    {
        $supplier = $orderItem->getSupplier();
        $amount = (float)$orderItem->getSupplierIncome(); // 使用供应商收入，不是subtotalAmount
        $commissionAmount = (float)$orderItem->getCommissionAmount(); // 佣金金额

        // 记录变更前的余额
        $balanceBefore = (float)$supplier->getBalance();
        $frozenBalanceBefore = (float)$supplier->getBalanceFrozen();
        $pendingBefore = (float)($supplier->getPendingSettlementAmount() ?? '0.00');

        // 增加供应商待结算金额（而不是冻结余额）
        $supplier->setPendingSettlementAmount((string)($pendingBefore + $amount));

        // 记录余额变更：增加待结算金额
        $this->recordBalanceHistoryWithPending(
            $supplier,
            'order_frozen',
            0.0, // 可用余额不变
            "订单支付增加待结算金额（订单号：{$orderItem->getOrder()->getOrderNo()}，供应商收入：{$amount}元）",
            $orderItem->getOrder()->getOrderNo(),
            $orderItem->getId(),
            $balanceBefore,
            $frozenBalanceBefore,
            $pendingBefore,
            $pendingBefore + $amount, // 待结算后金额
            $amount // 待结算变化金额（正数）
        );
        
        // 记录余额变更：扫除佣金（单独一条记录）
        if ($commissionAmount > 0) {
            // 佣金记录时的余额状态（已增加待结算后）
            $balanceAfterPending = (float)$supplier->getBalance();
            $frozenBalanceAfterPending = (float)$supplier->getBalanceFrozen();
            $pendingAfter = (float)$supplier->getPendingSettlementAmount();
            
            $this->recordBalanceHistoryWithPending(
                $supplier,
                'commission',
                -$commissionAmount, // 负数表示扫除
                "网站佣金（订单号：{$orderItem->getOrder()->getOrderNo()}，佣金金额：{$commissionAmount}元）",
                $orderItem->getOrder()->getOrderNo(),
                $orderItem->getId(),
                $balanceAfterPending,
                $frozenBalanceAfterPending,
                $pendingAfter,
                $pendingAfter,
                0.0 // 待结算金额不变
            );
        }

        $this->logger->info('供应商待结算金额已增加', [
            'supplier_id' => $supplier->getId(),
            'amount' => $amount,
            'commission_amount' => $commissionAmount,
            'pending_settlement_amount' => $supplier->getPendingSettlementAmount()
        ]);
    }

    /**
     * 释放供应商待结算金额
     * 
     * 说明：订单取消时，减少待结算金额（因为订单支付时增加的是待结算金额）
     * 释放金额：供应商收入（supplier_income = 纯商品金额 - 佣金）
     * 操作：pendingSettlementAmount -= supplier_income
     * 记录：type='order_refund'，包含待结算金额变化
     */
    private function releaseSupplierFrozenBalance(OrderItem $orderItem): void
    {
        $supplier = $orderItem->getSupplier();
        $amount = (float)$orderItem->getSupplierIncome(); // 使用供应商收入，不是subtotalAmount

        // 记录变更前的余额
        $balanceBefore = (float)$supplier->getBalance();
        $frozenBalanceBefore = (float)$supplier->getBalanceFrozen();
        $pendingBefore = (float)($supplier->getPendingSettlementAmount() ?? '0.00');

        // 减少待结算金额（因为订单支付时增加的是待结算金额）
        $newPending = $pendingBefore - $amount;
        $supplier->setPendingSettlementAmount((string)$newPending);

        // 记录余额变更（包含待结算金额变化）
        $this->recordBalanceHistoryWithPending(
            $supplier,
            'order_refund',
            0.0, // 可用余额没有变化
            "订单取消减少待结算金额（订单号：{$orderItem->getOrder()->getOrderNo()}，减少金额：{$amount}元）",
            $orderItem->getOrder()->getOrderNo(), // 关联订单号
            $orderItem->getId(), // 订单项ID
            $balanceBefore,
            $frozenBalanceBefore,
            $pendingBefore,
            $newPending,
            -$amount // 待结算金额变化量（负数表示减少）
        );

        $this->logger->info('供应商待结算金额已减少（订单取消）', [
            'supplier_id' => $supplier->getId(),
            'amount' => $amount,
            'balance' => $supplier->getBalance(),
            'frozen_balance' => $supplier->getBalanceFrozen(),
            'pending_settlement_before' => $pendingBefore,
            'pending_settlement_after' => $newPending
        ]);
    }

    /**
     * 处理供应商退款
     * 
     * 说明：退款时需要从供应商可用余额中扣除供应商收入部分
     * 注意：退款金额 = 用户支付金额（含运费），但供应商只需退供应商收入部分（不含运费和佣金）
     * 计算：供应商退款金额 = 退款金额 × (供应商收入 / 用户支付金额)
     * 操作：balance -= 供应商退款金额
     * 记录：type='order_refund'
     */
    private function processSupplierRefund(OrderItem $orderItem): void
    {
        $supplier = $orderItem->getSupplier();
        $refundAmount = (float)$orderItem->getRefundAmount(); // 用户退款金额（含运费）
        $subtotalAmount = (float)$orderItem->getSubtotalAmount(); // 用户支付金额（含运费）
        $supplierIncome = (float)$orderItem->getSupplierIncome(); // 供应商收入（不含运费和佣金）
        
        // 计算供应商实际需要退的金额
        // 供应商退款金额 = 退款金额 × (供应商收入 / 用户支付金额)
        $supplierRefundAmount = $subtotalAmount > 0 
            ? $refundAmount * ($supplierIncome / $subtotalAmount)
            : 0;
        
        // 记录变更前的余额
        $balanceBefore = (float)$supplier->getBalance();
        $frozenBalanceBefore = (float)$supplier->getBalanceFrozen();
        
        // 扣减供应商余额
        $supplier->setBalance((string)($balanceBefore - $supplierRefundAmount));

        // 记录余额变更
        $this->recordBalanceHistory(
            $supplier,
            'order_refund',
            -$supplierRefundAmount,
            "订单退款扣减货款（订单号：{$orderItem->getOrder()->getOrderNo()}，用户退款：{$refundAmount}元，供应商退款：{$supplierRefundAmount}元）",
            $orderItem->getOrder()->getOrderNo(), // 关联订单号
            $orderItem->getId(), // 订单项ID
            $balanceBefore,
            $frozenBalanceBefore
        );

        $this->logger->info('供应商退款已处理', [
            'supplier_id' => $supplier->getId(),
            'user_refund_amount' => $refundAmount,
            'supplier_refund_amount' => $supplierRefundAmount,
            'supplier_income' => $supplierIncome,
            'subtotal_amount' => $subtotalAmount,
            'remaining_balance' => $supplier->getBalance()
        ]);
    }

    /**
     * 记录余额变更历史
     * 
     * @param Supplier $supplier 供应商对象
     * @param string $type 变更类型（order_frozen/order_confirmed/order_refund/commission）
     * @param float $amount 变更金额
     * @param string $description 描述
     * @param string|null $referenceId 关联业务ID（如订单号）
     * @param int|null $orderItemId 订单项ID
     * @param float|null $balanceBefore 变更前余额（如果为null则取当前值）
     * @param float|null $frozenBalanceBefore 变更前冻结余额（如果为null则取当前值）
     */
    private function recordBalanceHistory(
        $supplier, 
        string $type, 
        float $amount, 
        string $description, 
        ?string $referenceId = null, 
        ?int $orderItemId = null,
        ?float $balanceBefore = null,
        ?float $frozenBalanceBefore = null
    ): void {
        $history = new BalanceHistory();
        $history->setUserType('supplier');
        $history->setUserId($supplier->getId());
        $history->setType($type);
        $history->setAmount((string)$amount);
        $history->setDescription($description);
        $history->setReferenceId($referenceId); // 设置关联的订单ID或其他业务ID
        $history->setOrderItemId($orderItemId); // 设置订单项ID
        
        // 获取当前余额（变更后的值）
        $currentBalance = (float)$supplier->getBalance();
        $currentFrozen = (float)$supplier->getBalanceFrozen();
        
        // 如果没有传入变更前的值，使用当前值（这种情况理论上不应该发生）
        if ($balanceBefore === null) {
            $balanceBefore = $currentBalance;
        }
        if ($frozenBalanceBefore === null) {
            $frozenBalanceBefore = $currentFrozen;
        }
        
        // 设置变更前的余额
        $history->setBalanceBefore((string)$balanceBefore);
        $history->setFrozenBalanceBefore((string)$frozenBalanceBefore);
        
        // 设置变更后的余额（直接使用当前值）
        $history->setBalanceAfter((string)$currentBalance);
        $history->setFrozenBalanceAfter((string)$currentFrozen);
        
        // 计算冻结余额变化量
        $frozenChange = $currentFrozen - $frozenBalanceBefore;
        $history->setFrozenAmount((string)$frozenChange);
        
        $history->setCreatedAt(new \DateTime());
        
        $this->entityManager->persist($history);
    }

    /**
     * 记录余额变更历史（包含待结算金额）
     * 
     * @param Supplier $supplier 供应商对象
     * @param string $type 变更类型（order_frozen/order_confirmed/order_refund/commission）
     * @param float $amount 变更金额
     * @param string $description 描述
     * @param string|null $referenceId 关联业务ID（如订单号）
     * @param int|null $orderItemId 订单项ID
     * @param float|null $balanceBefore 变更前余额（如果为null则取当前值）
     * @param float|null $frozenBalanceBefore 变更前冻结余额（如果为null则取当前值）
     * @param float|null $pendingBefore 变更前待结算金额（如果为null则取当前值）
     * @param float|null $pendingAfter 变更后待结算金额（如果为null则取当前值）
     * @param float|null $pendingChange 待结算金额变化量（如果为null则取当前值）
     */
    private function recordBalanceHistoryWithPending(
        $supplier, 
        string $type, 
        float $amount, 
        string $description, 
        ?string $referenceId = null, 
        ?int $orderItemId = null,
        ?float $balanceBefore = null,
        ?float $frozenBalanceBefore = null,
        ?float $pendingBefore = null,
        ?float $pendingAfter = null,
        ?float $pendingChange = null
    ): void {
        $history = new BalanceHistory();
        $history->setUserType('supplier');
        $history->setUserId($supplier->getId());
        $history->setType($type);
        $history->setAmount((string)$amount);
        $history->setDescription($description);
        $history->setReferenceId($referenceId); // 设置关联的订单ID或其他业务ID
        $history->setOrderItemId($orderItemId); // 设置订单项ID
        
        // 获取当前余额（变更后的值）
        $currentBalance = (float)$supplier->getBalance();
        $currentFrozen = (float)$supplier->getBalanceFrozen();
        $currentPending = (float)($supplier->getPendingSettlementAmount() ?? '0.00');
        
        // 如果没有传入变更前的值，使用当前值（这种情况理论上不应该发生）
        if ($balanceBefore === null) {
            $balanceBefore = $currentBalance;
        }
        if ($frozenBalanceBefore === null) {
            $frozenBalanceBefore = $currentFrozen;
        }
        if ($pendingBefore === null) {
            $pendingBefore = $currentPending;
        }
        
        // 设置变更前的余额
        $history->setBalanceBefore((string)$balanceBefore);
        $history->setFrozenBalanceBefore((string)$frozenBalanceBefore);
        $history->setPendingSettlementBefore((string)$pendingBefore);
        
        // 设置变更后的余额（直接使用当前值）
        $history->setBalanceAfter((string)$currentBalance);
        $history->setFrozenBalanceAfter((string)$currentFrozen);
        $history->setPendingSettlementAfter((string)$currentPending);
        
        // 计算冻结余额变化量
        $frozenChange = $currentFrozen - $frozenBalanceBefore;
        $history->setFrozenAmount((string)$frozenChange);
        
        // 计算待结算金额变化量
        if ($pendingChange === null) {
            $pendingChange = $currentPending - $pendingBefore;
        }
        $history->setPendingSettlementAmount((string)$pendingChange);
        
        $history->setCreatedAt(new \DateTime());
        
        $this->entityManager->persist($history);
    }

    // ==================== 辅助方法 ====================

    /**
     * 获取订单状态的中文描述
     */
    public function getStatusText(string $status): string
    {
        return match ($status) {
            'pending_payment' => '待支付',
            'paid' => '已支付',
            'shipped' => '已发货',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'refunding' => '退款中',
            'refunded' => '已退款',
            default => '未知状态'
        };
    }

    /**
     * 获取退款状态的中文描述
     */
    public function getRefundStatusText(string $refundStatus): string
    {
        return match ($refundStatus) {
            'none' => '无退款',
            'applying' => '申请中',
            'approved' => '已同意',
            'buyer_shipped' => '买家已发货',
            'completed' => '已完成',
            'rejected' => '已拒绝',
            default => '未知状态'
        };
    }

    /**
     * 获取当前状态下可以转换到的下一状态列表
     * 
     * @return array 例如：['paid' => '确认支付', 'cancelled' => '取消订单']
     */
    public function getNextPossibleStatuses(OrderItem $orderItem): array
    {
        $currentStatus = $orderItem->getOrderStatus();
        $actions = [];

        switch ($currentStatus) {
            case 'pending_payment':
                $actions['paid'] = '确认支付';
                $actions['cancelled'] = '取消订单';
                break;

            case 'paid':
                $actions['shipped'] = '标记发货';
                $actions['cancelled'] = '取消订单';
                break;

            case 'shipped':
                $actions['completed'] = '确认收货';
                $actions['cancelled'] = '取消订单';
                break;

            case 'completed':
                if ($this->canRefund($orderItem)) {
                    $actions['refunding'] = '申请退款';
                }
                break;

            case 'refunding':
                if ($orderItem->getRefundStatus() === 'applying') {
                    $actions['approved'] = '同意退款';
                    $actions['rejected'] = '拒绝退款';
                } elseif ($orderItem->getRefundStatus() === 'approved') {
                    $actions['refunded'] = '完成退款';
                } elseif ($orderItem->getRefundStatus() === 'buyer_shipped') {
                    $actions['refunded'] = '确认收货并完成退款';
                }
                break;
        }

        return $actions;
    }

    // ==================== 退款相关方法（整合自OrderRefundService）====================

    /**
     * 计算退款金额
     * 
     * 计算规则：
     * - 全部退款：退商品金额（不含运费，运费由买家承担）
     * - 部分退款：按比例退款（不退运费）
     * 
     * 重要说明：
     * - 运费属于买家自己承担的物流成本，退货时不退运费
     * - 这样可以避免供应商余额不足时出现负值
     * - 供应商只需退商品金额，不需要承担运费
     * 
     * @param OrderItem $orderItem
     * @param int $refundQuantity
     * @return string 退款金额
     */
    private function calculateRefundAmount(OrderItem $orderItem, int $refundQuantity): string
    {
        $totalQuantity = $orderItem->getQuantity();
        $subtotalAmount = $orderItem->getSubtotalAmount();
        $shippingFee = $orderItem->getShippingFee();
        
        // 计算纯商品金额（不含运费）
        $productAmount = $this->financialCalculator->subtract($subtotalAmount, $shippingFee);
        
        if ($refundQuantity === $totalQuantity) {
            // 全部退款：只退商品金额，不退运费（运费由买家承担）
            return $productAmount;
        } else {
            // 部分退款：按比例退款（不退运费）
            $unitPrice = $this->financialCalculator->divide($productAmount, (string)$totalQuantity);
            return $this->financialCalculator->multiply($unitPrice, (string)$refundQuantity);
        }
    }

    /**
     * 计算供应商需要解冻的金额
     * 
     * 供应商收入 = 商品金额 - 佣金
     * 退款时只解冻供应商收入部分，佣金不退
     * 
     * @param OrderItem $orderItem
     * @param int $refundQuantity
     * @return string 供应商解冻金额
     */
    private function calculateSupplierRefundAmount(OrderItem $orderItem, int $refundQuantity): string
    {
        $totalQuantity = $orderItem->getQuantity();
        $supplierIncome = $orderItem->getSupplierIncome();
        
        if ($refundQuantity === $totalQuantity) {
            // 全部退款：解冻全部供应商收入
            return $supplierIncome;
        } else {
            // 部分退款：按比例解冻
            $unitIncome = $this->financialCalculator->divide($supplierIncome, (string)$totalQuantity);
            return $this->financialCalculator->multiply($unitIncome, (string)$refundQuantity);
        }
    }

    /**
     * 退款给会员
     * 
     * 操作：
     * 1. 增加会员可用余额
     * 2. 记录余额变动历史
     * 
     * @param mixed $customer 会员对象
     * @param string $amount 退款金额
     * @param string $orderNo 订单号
     * @param int $orderItemId 订单项ID
     */
    private function refundToCustomer($customer, string $amount, string $orderNo, int $orderItemId): void
    {
        // 获取旧余额（转成字符串以便使用 bcmath）
        $oldBalance = (string)$customer->getBalance();
        $newBalance = $this->financialCalculator->add($oldBalance, $amount);
        
        // 更新会员余额（转成 float 类型）
        $customer->setBalance((float)$newBalance);
        
        // 记录余额历史
        $this->balanceHistoryService->createBalanceHistory(
            'customer',
            $customer->getId(),
            $oldBalance,
            $newBalance,
            $amount,
            (string)$customer->getFrozenBalance(),
            (string)$customer->getFrozenBalance(),
            '0.00',
            'order_refund',
            "订单退款：{$orderNo} (订单项ID:{$orderItemId})",
            $orderNo,
            $orderItemId
        );
        
        $this->logger->info('[订单退款] 已退款给会员', [
            'customer_id' => $customer->getId(),
            'amount' => $amount,
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance
        ]);
    }

    /**
     * 取消订单时退款给会员
     * 
     * 业务说明：
     * - 退款金额：商品金额（不含运费，运费由买家承担）
     * - 计算公式：退款金额 = subtotalAmount - shippingFee
     * 
     * 操作：
     * 1. 增加会员可用余额
     * 2. 记录余额变动历史
     * 
     * @param OrderItem $orderItem 订单项
     */
    private function refundToCustomerForCancel(OrderItem $orderItem): void
    {
        $order = $orderItem->getOrder();
        $customer = $order->getCustomer();
        $subtotalAmount = (string)$orderItem->getSubtotalAmount();
        $shippingFee = (string)$orderItem->getShippingFee();
        
        // 计算退款金额：商品金额（不含运费）
        $refundAmount = $this->financialCalculator->subtract($subtotalAmount, $shippingFee);
        
        // 获取旧余额
        $oldBalance = (string)$customer->getBalance();
        $newBalance = $this->financialCalculator->add($oldBalance, $refundAmount);
        
        // 更新会员余额（转成 float 类型）
        $customer->setBalance((float)$newBalance);
        
        // 记录余额历史
        $this->balanceHistoryService->createBalanceHistory(
            'customer',
            $customer->getId(),
            $oldBalance,
            $newBalance,
            $refundAmount,
            (string)$customer->getFrozenBalance(),
            (string)$customer->getFrozenBalance(),
            '0.00',
            'order_refund',
            "订单取消并退款（订单号：{$order->getOrderNo()}，商品金额：{$refundAmount}元，运费不退）",
            $order->getOrderNo(),
            $orderItem->getId()
        );
        
        $this->logger->info('[订单取消] 已退款给会员', [
            'customer_id' => $customer->getId(),
            'order_no' => $order->getOrderNo(),
            'order_item_id' => $orderItem->getId(),
            'subtotal_amount' => $subtotalAmount,
            'shipping_fee' => $shippingFee,
            'refund_amount' => $refundAmount,
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance
        ]);
    }

    /**
     * 解冻/扣减供应商余额（退款专用）
     * 
     * 业务逻辑：
     * - 已确认收货后退款（completed → refunding → refunded）
     *   操作：从供应商待结算金额扣除退款金额
     *   原因：确认收货后资金仍在待结算状态（8天冷静期），还未给供应商结算
     *   说明：只有已确认收货的订单才能申请退款，已支付未发货的订单应使用"取消订单"功能
     * 
     * @param mixed $supplier 供应商对象
     * @param string $amount 扣除金额
     * @param string $orderNo 订单号
     * @param int $orderItemId 订单项ID
     * @param OrderItem $orderItem 订单项对象（用于判断状态）
     */
    private function unfreezeSupplierBalanceForRefund($supplier, string $amount, string $orderNo, int $orderItemId, OrderItem $orderItem): void
    {
        $oldBalance = $supplier->getBalance();
        $oldFrozen = $supplier->getBalanceFrozen() ?? '0.00';
        $oldPending = (float)($supplier->getPendingSettlementAmount() ?? '0.00');
        
        // 退款时从待结算金额扣除（因为确认收货时资金仍在待结算状态）
        $description = "订单退款（买家已确认收货，从供应商待结算金额扣除）：{$orderNo} (订单项ID:{$orderItemId})";
        
        $newPending = $oldPending - (float)$amount;
        $supplier->setPendingSettlementAmount((string)$newPending);
        $newBalance = $oldBalance;
        $newFrozen = $oldFrozen;
        
        $balanceChange = 0.0;
        $frozenChange = 0.0;
        $pendingChange = -(float)$amount;
        
        $this->logger->info('[订单退款] 已确认收货后退款，从供应商待结算金额扣除', [
            'supplier_id' => $supplier->getId(),
            'amount' => $amount,
            'balance' => $oldBalance,
            'frozen_balance' => $oldFrozen,
            'old_pending' => $oldPending,
            'new_pending' => $newPending,
            'pending_change' => $pendingChange,
            'current_status' => $orderItem->getOrderStatus()
        ]);
        
        // 记录余额历史（包含待结算金额变化）
        $this->recordBalanceHistoryWithPending(
            $supplier,
            'order_refund',
            $balanceChange,
            $description,
            $orderNo,
            $orderItemId,
            (float)$oldBalance,
            (float)$oldFrozen,
            $oldPending,
            $newPending,
            $pendingChange
        );
    }

    /**
     * 恢复库存
     * 
     * 操作：
     * 1. 找到对应区域的库存记录
     * 2. 增加可用库存
     * 
     * @param OrderItem $orderItem
     * @param int $quantity 恢复数量
     */
    private function restoreStock(OrderItem $orderItem, int $quantity): void
    {
        $product = $orderItem->getProduct();
        $region = $orderItem->getShippingRegion();
        
        foreach ($product->getShippings() as $shipping) {
            if ($shipping->getRegion() === $region) {
                $currentStock = $shipping->getAvailableStock();
                $newStock = $currentStock + $quantity;
                $shipping->setAvailableStock($newStock);
                
                $this->logger->info('[订单退款] 已恢复库存', [
                    'product_id' => $product->getId(),
                    'region' => $region,
                    'quantity' => $quantity,
                    'old_stock' => $currentStock,
                    'new_stock' => $newStock
                ]);
                
                break;
            }
        }
    }

    /**
     * 更新订单主表支付状态
     * 
     * 规则：
     * - 所有订单项都退款 => Order.payment_status = 'refunded'
     * - 部分订单项退款 => Order.payment_status = 'partial_refund'
     * - 没有订单项退款 => Order.payment_status = 'paid'
     * 
     * @param Order $order
     */
    private function updateOrderPaymentStatus(Order $order): void
    {
        $totalItems = count($order->getItems());
        $refundedItems = 0;
        
        foreach ($order->getItems() as $item) {
            if ($item->getOrderStatus() === 'refunded') {
                $refundedItems++;
            }
        }
        
        $oldStatus = $order->getPaymentStatus();
        
        if ($refundedItems === $totalItems) {
            // 全部退款
            $order->setPaymentStatus('refunded');
        } elseif ($refundedItems > 0) {
            // 部分退款
            $order->setPaymentStatus('partial_refund');
        } else {
            // 没有退款（保持原状态）
            $order->setPaymentStatus('paid');
        }
        
        $this->logger->info('[订单退款] 已更新订单主表状态', [
            'order_id' => $order->getId(),
            'order_no' => $order->getOrderNo(),
            'old_status' => $oldStatus,
            'new_status' => $order->getPaymentStatus(),
            'total_items' => $totalItems,
            'refunded_items' => $refundedItems
        ]);
    }

    /**
     * 批量退款（退款整个订单的所有订单项）
     * 
     * @param Order $order
     * @param string $refundReason
     * @return array
     */
    public function refundEntireOrder(Order $order, string $refundReason): array
    {
        $results = [];
        $successCount = 0;
        $failCount = 0;
        
        foreach ($order->getItems() as $orderItem) {
            if ($orderItem->getOrderStatus() === 'refunded') {
                // 已退款，跳过
                continue;
            }
            
            try {
                // 先设置为approved状态，然后调用完成退款
                $orderItem->setRefundStatus('approved');
                $orderItem->setRefundReason($refundReason);
                $this->completeRefund($orderItem);
                
                $results[] = [
                    'success' => true,
                    'order_item_id' => $orderItem->getId()
                ];
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'order_item_id' => $orderItem->getId(),
                    'message' => $e->getMessage()
                ];
                $failCount++;
            }
        }
        
        return [
            'success' => $failCount === 0,
            'message' => "成功退款 {$successCount} 个订单项，失败 {$failCount} 个",
            'total' => count($results),
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'details' => $results
        ];
    }

    // ==================== VIP升级辅助方法 ====================

    /**
     * 获取或创建月度统计记录
     * 
     * 功能说明：
     * - 查询当月记录，如果不存在则自动创建
     * - 新记录的所有统计字段初始化为 0
     * - 用于VIP升级功能中累计当月消费金额
     * 
     * 参考文档：zreadme\VIP下载规则.md
     * 
     * @param int $customerId 客户ID
     * @param int $year 年份（如：2025）
     * @param int $month 月份（1-12）
     * @return CustomerMonthlyStats 月度统计记录
     */
    private function getOrCreateMonthlyStats(int $customerId, int $year, int $month): CustomerMonthlyStats
    {
        // 先查找当月记录
        $monthlyStats = $this->monthlyStatsRepository->findOneBy([
            'customerId' => $customerId,
            'statsYear' => $year,
            'statsMonth' => $month
        ]);
        
        // 如果不存在，创建新记录
        if (!$monthlyStats) {
            $monthlyStats = new CustomerMonthlyStats();
            $monthlyStats->setCustomerId($customerId);
            $monthlyStats->setStatsYear($year);
            $monthlyStats->setStatsMonth($month);
            $monthlyStats->setDownloadUsed(0);         // 下载次数初始化为0
            $monthlyStats->setTotalOrderAmount(0.00);  // 订单金额初始化为0
            $monthlyStats->setTotalOrders(0);          // 订单数初始化为0
            
            $this->entityManager->persist($monthlyStats);
            $this->entityManager->flush();
            
            $this->logger->info('创建月度统计记录', [
                'customer_id' => $customerId,
                'year' => $year,
                'month' => $month
            ]);
        }
        
        return $monthlyStats;
    }

    /**
     * 计算VIP等级
     * 
     * 升级规则（参考：zreadme\VIP下载规则.md）：
     * - 普通会员（VIP0）：当月消费 >= VIP1的configValue → 升级为 VIP1
     * - VIP1 会员：当月消费 >= VIP2的configValue → 升级为 VIP2
     * - VIP2 会员：当月消费 >= VIP3的configValue → 升级为 VIP3
     * - VIP3 会员：当月消费 >= VIP4的configValue → 升级为 VIP4
     * - VIP4 会员：当月消费 >= VIP5的configValue → 升级为 VIP5
     * 
     * 重要说明：
     * - VIP等级只升不降（即使消费金额不足也不会降级）
     * - 升级条件基于site_config表的configValue字段（升级所需金额）
     * - 每次只能升一级，不能跳级升级
     * 
     * @param float $monthlyOrderAmount 当月订单总金额（累计）
     * @param int $currentVipLevel 当前VIP等级（0-5）
     * @return int 新的VIP等级（0-5）
     */
    private function calculateVipLevel(float $monthlyOrderAmount, int $currentVipLevel): int
    {
        // 获取各VIP等级的升级门槛配置
        // configKey格式：VIP1、VIP2、VIP3、VIP4、VIP5
        // configValue：升级到该等级所需的月消费金额
        $vipConfigs = [];
        for ($i = 1; $i <= 5; $i++) {
            $config = $this->siteConfigRepository->findOneBy(['configKey' => 'VIP' . $i]);
            if ($config) {
                $vipConfigs[$i] = (float)$config->getConfigValue();
            }
        }
        
        // 每次只能升一级，根据当前等级判断是否达到下一级的条件
        $newVipLevel = $currentVipLevel;
        
        // 根据当前VIP等级判断是否可以升到下一级
        switch ($currentVipLevel) {
            case 0: // 普通会员
                // 判断是否可以升到VIP1（每次只能升一级）
                if (isset($vipConfigs[1]) && $monthlyOrderAmount >= $vipConfigs[1]) {
                    $newVipLevel = 1;
                }
                break;
                
            case 1: // VIP1会员
                // 判断是否可以升到VIP2（每次只能升一级）
                if (isset($vipConfigs[2]) && $monthlyOrderAmount >= $vipConfigs[2]) {
                    $newVipLevel = 2;
                }
                break;
                
            case 2: // VIP2会员
                // 判断是否可以升到VIP3（每次只能升一级）
                if (isset($vipConfigs[3]) && $monthlyOrderAmount >= $vipConfigs[3]) {
                    $newVipLevel = 3;
                }
                break;
                
            case 3: // VIP3会员
                // 判断是否可以升到VIP4（每次只能升一级）
                if (isset($vipConfigs[4]) && $monthlyOrderAmount >= $vipConfigs[4]) {
                    $newVipLevel = 4;
                }
                break;
                
            case 4: // VIP4会员
                // 判断是否可以升到VIP5（每次只能升一级）
                if (isset($vipConfigs[5]) && $monthlyOrderAmount >= $vipConfigs[5]) {
                    $newVipLevel = 5;
                }
                break;
                
            case 5: // VIP5会员（最高等级）
                // 已经是最高等级，无需判断
                $newVipLevel = 5;
                break;
                
            default:
                // 未知等级，保持原等级
                $newVipLevel = $currentVipLevel;
                break;
        }
        
        // 记录升级判断日志
        if ($newVipLevel > $currentVipLevel) {
            $this->logger->info('VIP等级升级判断', [
                'monthly_amount' => $monthlyOrderAmount,
                'current_level' => $currentVipLevel,
                'new_level' => $newVipLevel,
                'vip_configs' => $vipConfigs
            ]);
        }
        
        return $newVipLevel;
    }
}
