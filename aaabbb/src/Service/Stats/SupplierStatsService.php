<?php

namespace App\Service\Stats;

use App\Repository\OrderItemRepository;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Service\Stats\CacheStatsService;
use App\Service\Stats\StatsHelper;

/**
 * 供应商统计服务
 */
class SupplierStatsService
{
    public function __construct(
        private OrderItemRepository $orderItemRepository,
        private ProductRepository $productRepository,
        private SupplierRepository $supplierRepository,
        private CacheStatsService $cache
    ) {}

    /**
     * 获取供应商首页数据概览
     */
    public function getDashboardOverview(int $supplierId): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_dashboard', ['supplier_id' => $supplierId]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        // 获取今天的数据
        $todayOrderCount = $this->orderItemRepository->getTodayOrderCount($supplierId);
        $todaySales = $this->orderItemRepository->getTodaySales($supplierId);
        $pendingShipmentCount = $this->orderItemRepository->getPendingShipmentCount($supplierId);
        
        // 获取昨天的数据用于计算增长率
        $yesterdayOrderCount = $this->orderItemRepository->getYesterdayOrderCount($supplierId);
        $yesterdaySales = $this->orderItemRepository->getYesterdaySales($supplierId);
        $yesterdayPendingShipmentCount = $this->orderItemRepository->getYesterdayPendingShipmentCount($supplierId);
        
        // 计算增长率
        $orderGrowth = $this->calculateGrowthRate($todayOrderCount, $yesterdayOrderCount);
        $salesGrowth = $this->calculateGrowthRate($todaySales, $yesterdaySales);
        $shipmentGrowth = $this->calculateGrowthRate($pendingShipmentCount, $yesterdayPendingShipmentCount);

        $data = [
            'todayOrderCount' => $todayOrderCount,
            'todaySales' => $todaySales,
            'pendingShipmentCount' => $pendingShipmentCount,
            'pendingSettlementAmount' => $this->getPendingSettlementAmount($supplierId),
            'accountBalance' => $this->getAccountBalance($supplierId),
            'orderGrowth' => $orderGrowth,
            'salesGrowth' => $salesGrowth,
            'shipmentGrowth' => $shipmentGrowth,
        ];

        $this->cache->setHot($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取供应商销售统计
     */
    public function getSalesStats(int $supplierId, int $days = 30): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_sales', ['supplier_id' => $supplierId, 'days' => $days]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = [
            'orderCountTrend' => $this->orderItemRepository->getOrderCountTrend($supplierId, $days),
            'salesTrend' => $this->orderItemRepository->getSalesTrend($supplierId, $days),
            'avgOrderAmountTrend' => $this->orderItemRepository->getAvgOrderAmountTrend($supplierId, $days),
            'productSalesRanking' => $this->orderItemRepository->getProductSalesRanking($supplierId, 10),
            'orderStatusDistribution' => $this->orderItemRepository->getOrderStatusDistribution($supplierId),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取供应商财务统计
     */
    public function getFinancialStats(int $supplierId): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_financial', ['supplier_id' => $supplierId]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $monthlyStats = $this->orderItemRepository->getMonthlyFinancialStats($supplierId);
        
        $data = [
            'monthly' => $monthlyStats,
            'last12Months' => $this->orderItemRepository->getLast12MonthsIncomeTrend($supplierId),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取供应商商品统计
     */
    public function getProductStats(int $supplierId): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_product', ['supplier_id' => $supplierId]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = [
            'totalCount' => $this->productRepository->getSupplierProductCount($supplierId),
            'activeCount' => $this->productRepository->getSupplierActiveProductCount($supplierId),
            'viewRanking' => $this->productRepository->getSupplierProductViewRanking($supplierId, 10),
            'favoriteRanking' => $this->productRepository->getSupplierProductFavoriteRanking($supplierId, 10),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取供应商退款统计
     */
    public function getRefundStats(int $supplierId): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_refund', ['supplier_id' => $supplierId]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $monthlyRefundStats = $this->orderItemRepository->getMonthlyRefundStats($supplierId);
        
        $data = [
            'monthlyRefundOrderCount' => $monthlyRefundStats['refund_order_count'],
            'monthlyRefundAmount' => $monthlyRefundStats['total_refund_amount'],
            'monthlyRefundRate' => $this->orderItemRepository->getMonthlyRefundRate($supplierId),
            'refundReasonStats' => $this->orderItemRepository->getRefundReasonStats($supplierId),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 计算增长率
     * 
     * @param float $current 当前值
     * @param float $previous 之前的值
     * @return float 增长率（百分比）
     */
    private function calculateGrowthRate(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        
        return round((($current - $previous) / $previous) * 100, 2);
    }

    /**
     * 获取待结算金额
     */
    private function getPendingSettlementAmount(int $supplierId): float
    {
        $supplier = $this->supplierRepository->find($supplierId);
        return $supplier ? $supplier->getPendingSettlementAmount() : 0.0;
    }

    /**
     * 获取已结算金额
     */
    private function getSettledAmount(int $supplierId): float
    {
        $conn = $this->orderItemRepository->getConnection();
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM settlement WHERE supplier_id = :supplierId AND status = 'settled'";
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['supplierId' => $supplierId]);
        return (float) $result->fetchOne();
    }

    /**
     * 获取账户余额
     */
    private function getAccountBalance(int $supplierId): float
    {
        $supplier = $this->supplierRepository->find($supplierId);
        return $supplier ? $supplier->getBalance() : 0.0;
    }

    /**
     * 获取销售趋势
     */
    public function getSalesTrend(int $supplierId, string $period): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_sales_trend', [
            'supplier_id' => $supplierId,
            'period' => $period,
        ]);

        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $dateRange = StatsHelper::getDateRange($period);
        $data = $this->orderItemRepository->getSalesTrendByDateRange(
            $supplierId,
            $dateRange['start'],
            $dateRange['end']
        );

        $this->cache->setWarm($cacheKey, $data);
        return $data;
    }

    /**
     * 按品类获取销售统计
     */
    public function getSalesByCategory(int $supplierId, string $period): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_sales_category', [
            'supplier_id' => $supplierId,
            'period' => $period,
        ]);

        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $dateRange = StatsHelper::getDateRange($period);
        $conn = $this->orderItemRepository->getConnection();
        
        $sql = "
            SELECT mc.title as category_name, 
                   SUM(oi.subtotal_amount) as sales_amount,
                   COUNT(DISTINCT oi.order_id) as order_count,
                   SUM(oi.quantity) as quantity
            FROM order_item oi
            INNER JOIN product p ON oi.product_id = p.id
            INNER JOIN menu_category mc ON p.category_id = mc.id
            WHERE oi.supplier_id = :supplierId
              AND oi.created_at >= :startDate
              AND oi.created_at < :endDate
              AND oi.order_status != 'cancelled'
            GROUP BY mc.id, mc.title
            ORDER BY sales_amount DESC
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'supplierId' => $supplierId,
            'startDate' => $dateRange['start']->format('Y-m-d H:i:s'),
            'endDate' => $dateRange['end']->format('Y-m-d H:i:s'),
        ]);

        $data = $result->fetchAllAssociative();
        $this->cache->setWarm($cacheKey, $data);
        return $data;
    }

    /**
     * 按地区获取销售统计
     */
    public function getSalesByRegion(int $supplierId, string $period): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_sales_region', [
            'supplier_id' => $supplierId,
            'period' => $period,
        ]);

        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $dateRange = StatsHelper::getDateRange($period);
        $conn = $this->orderItemRepository->getConnection();
        
        $sql = "
            SELECT o.province,
                   SUM(oi.subtotal_amount) as sales_amount,
                   COUNT(DISTINCT oi.order_id) as order_count
            FROM order_item oi
            INNER JOIN \"order\" o ON oi.order_id = o.id
            WHERE oi.supplier_id = :supplierId
              AND oi.created_at >= :startDate
              AND oi.created_at < :endDate
              AND oi.order_status != 'cancelled'
            GROUP BY o.province
            ORDER BY sales_amount DESC
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'supplierId' => $supplierId,
            'startDate' => $dateRange['start']->format('Y-m-d H:i:s'),
            'endDate' => $dateRange['end']->format('Y-m-d H:i:s'),
        ]);

        $data = $result->fetchAllAssociative();
        $this->cache->setWarm($cacheKey, $data);
        return $data;
    }

    /**
     * 获取结算统计
     */
    public function getSettlementStats(int $supplierId): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_settlement', ['supplier_id' => $supplierId]);

        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = [
            'pendingAmount' => $this->getPendingSettlementAmount($supplierId),
            'settledAmount' => $this->getSettledAmount($supplierId),
            'accountBalance' => $this->getAccountBalance($supplierId),
        ];

        $this->cache->setWarm($cacheKey, $data);
        return $data;
    }

    /**
     * 获取财务趋势
     */
    public function getFinanceTrend(int $supplierId, string $period): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_finance_trend', [
            'supplier_id' => $supplierId,
            'period' => $period,
        ]);

        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $months = [];
        $now = new \DateTime();
        for ($i = 11; $i >= 0; $i--) {
            $month = clone $now;
            $month->modify("-{$i} months");
            $months[] = $month->format('Y-m');
        }

        $conn = $this->orderItemRepository->getConnection();
        $sql = "
            SELECT DATE_FORMAT(oi.created_at, '%Y-%m') as month,
                   SUM(oi.subtotal_amount) as revenue,
                   SUM(oi.commission_amount) as commission,
                   SUM(oi.subtotal_amount - oi.commission_amount) as net_income
            FROM order_item oi
            WHERE oi.supplier_id = :supplierId
              AND oi.order_status NOT IN ('cancelled', 'refunded')
              AND oi.created_at >= :startDate
            GROUP BY month
            ORDER BY month ASC
        ";

        $startDate = clone $now;
        $startDate->modify('-12 months');

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'supplierId' => $supplierId,
            'startDate' => $startDate->format('Y-m-01 00:00:00'),
        ]);

        $data = $result->fetchAllAssociative();
        $this->cache->setWarm($cacheKey, $data);
        return $data;
    }

    /**
     * 获取商品概览
     */
    public function getProductsOverview(int $supplierId): array
    {
        return $this->getProductStats($supplierId);
    }

    /**
     * 获取商品排行
     */
    public function getProductsRanking(int $supplierId, string $period, string $type = 'sales', int $limit = 10): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_products_ranking', [
            'supplier_id' => $supplierId,
            'period' => $period,
            'type' => $type,
            'limit' => $limit,
        ]);

        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = $this->orderItemRepository->getProductSalesRanking($supplierId, $limit);

        $this->cache->setWarm($cacheKey, $data);
        return $data;
    }

    /**
     * 获取退款概览
     */
    public function getRefundOverview(int $supplierId): array
    {
        return $this->getRefundStats($supplierId);
    }

    /**
     * 获取退款趋势
     */
    public function getRefundTrend(int $supplierId, string $period): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_refund_trend', [
            'supplier_id' => $supplierId,
            'period' => $period,
        ]);

        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $dateRange = StatsHelper::getDateRange($period);
        $data = $this->orderItemRepository->getRefundTrendByDateRange(
            $supplierId,
            $dateRange['start'],
            $dateRange['end']
        );

        $this->cache->setWarm($cacheKey, $data);
        return $data;
    }
}
