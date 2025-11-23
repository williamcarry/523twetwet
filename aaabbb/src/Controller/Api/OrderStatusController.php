<?php

namespace App\Controller\Api;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 订单状态查询控制器
 * 提供订单状态查询接口，配合Mercure实时推送使用
 */
#[Route('/shop/api/order', name: 'api_shop_order_')]
class OrderStatusController extends AbstractController
{
    /**
     * 根据订单号查询订单状态
     */
    #[Route('/status/{orderNo}', name: 'status', methods: ['GET'])]
    public function getOrderStatus(string $orderNo, OrderRepository $orderRepository): JsonResponse
    {
        // 查询订单
        $order = $orderRepository->findOneBy(['orderNo' => $orderNo]);
        
        // 检查订单是否存在
        if (!$order) {
            return new JsonResponse([
                'success' => false,
                'message' => '订单不存在'
            ], 404);
        }
        
        // 订单状态映射
        $orderStatusMap = [
            'pending_payment' => '待支付',
            'paid' => '已支付',
            'shipped' => '已发货',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'refunding' => '退款中',
            'refunded' => '已退款'
        ];
        
        $paymentStatusMap = [
            'unpaid' => '未支付',
            'paid' => '已支付',
            'refunded' => '已退款'
        ];
        
        $shippingStatusMap = [
            'unshipped' => '未发货',
            'shipped' => '已发货',
            'received' => '已收货'
        ];
        
        // 获取订单明细
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'product_id' => $item->getProduct() ? $item->getProduct()->getId() : null,
                'product_sku' => $item->getProductSku(),
                'product_title' => $item->getProductTitle(),
                'product_thumbnail' => $item->getProductThumbnail(),
                'unit_price' => $item->getUnitPrice(),
                'quantity' => $item->getQuantity(),
                'subtotal' => $item->getSubtotalAmount()
            ];
        }
        
        return new JsonResponse([
            'success' => true,
            'data' => [
                'order_id' => $order->getId(),
                'order_no' => $order->getOrderNo(),
                'order_status' => $order->getAggregatedOrderStatus(),
                'order_status_text' => $orderStatusMap[$order->getAggregatedOrderStatus()] ?? '未知状态',
                'payment_status' => $order->getPaymentStatus(),
                'payment_status_text' => $paymentStatusMap[$order->getPaymentStatus()] ?? '未知',
                'shipping_status' => $order->getAggregatedShippingStatus(),
                'shipping_status_text' => $shippingStatusMap[$order->getAggregatedShippingStatus()] ?? '未知',
                'total_amount' => $order->getTotalAmount(),
                'paid_amount' => $order->getPaidAmount(),
                'payment_method' => $order->getPaymentMethod(),
                'items' => $items,
                'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
                'payment_time' => $order->getPaymentTime() ? $order->getPaymentTime()->format('Y-m-d H:i:s') : null,
                'updated_at' => $order->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        ]);
    }
}
