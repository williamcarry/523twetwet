<?php

namespace App\Service\Order;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\LogisticsCompany;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 订单发货服务
 * 
 * 功能说明：
 * - 单个订单项发货
 * - 批量发货（一键全部发货）
 * - 更新物流信息
 * - 自动更新订单和订单项状态
 * 
 * 使用场景：
 * - 供应商后台发货操作
 * - 支持单独发货和批量发货
 */
class OrderShippingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}
    
    /**
     * 单个订单项发货
     * 
     * @param OrderItem $orderItem 订单项
     * @param array $shippingData 物流信息 [logistics_company_id, logistics_no]
     * @return array [success, message]
     */
    public function shipOrderItem(OrderItem $orderItem, array $shippingData): array
    {
        // 1. 验证订单项状态
        if (!in_array($orderItem->getOrderStatus(), ['paid'])) {
            return [
                'success' => false,
                'message' => '订单项状态不允许发货（仅已支付状态可发货）'
            ];
        }
        
        // 2. 验证物流信息
        if (empty($shippingData['logistics_company_id']) || empty($shippingData['logistics_no'])) {
            return [
                'success' => false,
                'message' => '物流公司和物流单号不能为空'
            ];
        }
        
        try {
            $this->entityManager->beginTransaction();
            
            $order = $orderItem->getOrder();
            
            // 3. 获取物流公司信息
            $logisticsCompany = $this->entityManager
                ->getRepository(LogisticsCompany::class)
                ->find($shippingData['logistics_company_id']);
            
            if (!$logisticsCompany) {
                $this->entityManager->rollback();
                return [
                    'success' => false,
                    'message' => '物流公司不存在'
                ];
            }
            
            // 4. 更新订单项物流信息和状态
            $orderItem->setLogisticsCompany([
                'id' => $logisticsCompany->getId(),
                'name_zh' => $logisticsCompany->getName(),
                'name_en' => $logisticsCompany->getNameEn()
            ]);
            $orderItem->setLogisticsNo($shippingData['logistics_no']);
            $orderItem->setShippedTime(new \DateTime());
            $orderItem->setOrderStatus('shipped');
            
            // 5. 订单项状态会自动影响订单主表的聚合状态（由前端计算）
            // 无需手动更新 Order.shippingStatus
            
            $this->entityManager->flush();
            $this->entityManager->commit();
            
            $this->logger->info('[OrderShipping] 订单项发货成功', [
                'order_no' => $order->getOrderNo(),
                'order_item_id' => $orderItem->getId(),
                'logistics_company' => $logisticsCompany->getName(),
                'logistics_no' => $shippingData['logistics_no']
            ]);
            
            return [
                'success' => true,
                'message' => '发货成功',
                'order_item_id' => $orderItem->getId(),
                'logistics_company' => [
                    'id' => $logisticsCompany->getId(),
                    'name_zh' => $logisticsCompany->getName(),
                    'name_en' => $logisticsCompany->getNameEn()
                ],
                'logistics_no' => $shippingData['logistics_no']
            ];
            
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            
            $this->logger->error('[OrderShipping] 订单项发货失败', [
                'order_item_id' => $orderItem->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => '发货失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 批量发货（一键全部发货）
     * 
     * @param Order $order 订单
     * @param array $shippingData 物流信息
     * @param int|null $supplierId 供应商ID（可选，用于只发货该供应商的商品）
     * @return array [success, message, shipped_count, failed_count, details]
     */
    public function shipAllOrderItems(Order $order, array $shippingData, ?int $supplierId = null): array
    {
        // 验证物流信息
        if (empty($shippingData['logistics_company_id']) || empty($shippingData['logistics_no'])) {
            return [
                'success' => false,
                'message' => '物流公司和物流单号不能为空'
            ];
        }
        
        $results = [];
        $shippedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        
        foreach ($order->getItems() as $orderItem) {
            // 如果指定了供应商ID，只发货该供应商的商品
            if ($supplierId !== null && $orderItem->getSupplier()->getId() !== $supplierId) {
                continue;
            }
            
            // 跳过已发货的订单项
            if ($orderItem->getOrderStatus() === 'shipped') {
                $skippedCount++;
                continue;
            }
            
            // 跳过非已支付状态的订单项
            if ($orderItem->getOrderStatus() !== 'paid') {
                $skippedCount++;
                continue;
            }
            
            // 发货
            $result = $this->shipOrderItem($orderItem, $shippingData);
            $results[] = $result;
            
            if ($result['success']) {
                $shippedCount++;
            } else {
                $failedCount++;
            }
        }
        
        $totalProcessed = $shippedCount + $failedCount;
        
        if ($totalProcessed === 0) {
            return [
                'success' => false,
                'message' => '没有可发货的订单项（已全部发货或状态不符合）',
                'shipped_count' => 0,
                'failed_count' => 0,
                'skipped_count' => $skippedCount
            ];
        }
        
        return [
            'success' => $failedCount === 0,
            'message' => "成功发货 {$shippedCount} 个订单项" . ($failedCount > 0 ? "，失败 {$failedCount} 个" : ""),
            'shipped_count' => $shippedCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'details' => $results
        ];
    }
    
    /**
     * 获取订单中未发货的订单项数量
     * 
     * @param Order $order
     * @param int|null $supplierId 供应商ID（可选）
     * @return int
     */
    public function getUnshippedItemsCount(Order $order, ?int $supplierId = null): int
    {
        $count = 0;
        
        foreach ($order->getItems() as $orderItem) {
            // 如果指定了供应商ID，只统计该供应商的商品
            if ($supplierId !== null && $orderItem->getSupplier()->getId() !== $supplierId) {
                continue;
            }
            
            // 只统计已支付但未发货的订单项
            if ($orderItem->getOrderStatus() === 'paid') {
                $count++;
            }
        }
        
        return $count;
    }
}
