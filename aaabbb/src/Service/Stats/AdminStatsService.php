<?php

namespace App\Service\Stats;

use App\Repository\OrderRepository;
use App\Repository\OrderItemRepository;
use App\Repository\CustomerRepository;
use App\Repository\SupplierRepository;
use App\Repository\ProductRepository;
use App\Service\Stats\CacheStatsService;
use App\Service\Stats\StatsHelper;
use App\Service\SiteConfigService;

/**
 * 管理后台统计服务
 */
class AdminStatsService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderItemRepository $orderItemRepository,
        private CustomerRepository $customerRepository,
        private SupplierRepository $supplierRepository,
        private ProductRepository $productRepository,
        private CacheStatsService $cache,
        private SiteConfigService $siteConfigService
    ) {}

    /**
     * 获取平台运营概览
     */
    public function getPlatformOverview(): array
    {
        $cacheKey = StatsHelper::generateCacheKey('platform_overview');
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $todayCommission = 0;
        $conn = $this->orderItemRepository->getConnection();
        $result = $conn->fetchAssociative(
            "SELECT SUM(commission_amount) as total 
             FROM order_item 
             WHERE DATE(created_at) = CURRENT_DATE() 
               AND order_status IN ('paid', 'shipped', 'completed')"
        );
        $todayCommission = round((float) ($result['total'] ?? 0), 2);

        $data = [
            'todayGMV' => $this->orderRepository->getTodayGMV(),
            'todayOrderCount' => $this->orderRepository->getTodayOrderCount(),
            'todayNewCustomers' => $this->customerRepository->getTodayNewCustomerCount(),
            'todayNewSuppliers' => $this->supplierRepository->getTodayNewSupplierCount(),
            'todayCommission' => $todayCommission,
            'currencySymbol' => $this->siteConfigService->getCurrencySymbol(),
        ];

        $this->cache->setHot($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取订单统计
     */
    public function getOrderStats(int $days = 30): array
    {
        $cacheKey = StatsHelper::generateCacheKey('order_stats', ['days' => $days]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = [
            'orderCountTrend' => $this->orderRepository->getOrderCountTrend($days),
            'gmvTrend' => $this->orderRepository->getGMVTrend($days),
            'avgOrderAmountTrend' => $this->orderRepository->getAvgOrderAmountTrend($days),
            'paymentSuccessRate' => $this->orderRepository->getPaymentSuccessRate($days),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取客户统计
     */
    public function getCustomerStats(int $days = 30): array
    {
        $cacheKey = StatsHelper::generateCacheKey('customer_stats', ['days' => $days]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = [
            'newCustomerTrend' => $this->customerRepository->getNewCustomerTrend($days),
            'customerTypeDistribution' => $this->customerRepository->getCustomerTypeDistribution(),
            'dau' => $this->customerRepository->getDAU(),
            'wau' => $this->customerRepository->getWAU(),
            'mau' => $this->customerRepository->getMAU(),
            'topConsumers' => $this->customerRepository->getTopConsumers(100),
            'vipLevelDistribution' => $this->customerRepository->getVIPLevelDistribution(),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取供应商统计
     */
    public function getSupplierStats(int $days = 30): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_stats', ['days' => $days]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = [
            'totalSuppliers' => $this->supplierRepository->getTotalSupplierCount(),
            'newSupplierTrend' => $this->supplierRepository->getNewSupplierTrend($days),
            'supplierTypeDistribution' => $this->supplierRepository->getSupplierTypeDistribution(),
            'pendingAuditCount' => $this->supplierRepository->getPendingAuditCount(),
            'membershipTypeDistribution' => $this->supplierRepository->getMembershipTypeDistribution(),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取供应商月销量排行榜
     */
    public function getSupplierMonthlyRanking(string $month, int $page = 1, int $limit = 100, string $sortBy = 'sales'): array
    {
        $cacheKey = StatsHelper::generateCacheKey('supplier_ranking', ['month' => $month, 'page' => $page, 'limit' => $limit, 'sortBy' => $sortBy]);
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            // 检查缓存格式
            if (is_array($data) && isset($data['list'])) {
                // 新格式，确保有货币符号
                if (!isset($data['currencySymbol'])) {
                    $data['currencySymbol'] = $this->siteConfigService->getCurrencySymbol();
                }
                return $data;
            } elseif (is_array($data) && !isset($data['list'])) {
                // 旧格式（直接是数组），转换为新格式
                return [
                    'list' => $data,
                    'currencySymbol' => $this->siteConfigService->getCurrencySymbol(),
                ];
            }
        }

        $ranking = $this->supplierRepository->getMonthlySupplierRanking($month, $limit, $sortBy);
        
        $data = [
            'list' => $ranking,
            'currencySymbol' => $this->siteConfigService->getCurrencySymbol(),
        ];

        $this->cache->setCold($cacheKey, $data);
        
        return $data;
    }

    /**
     * 搜索供应商统计信息
     */
    public function searchSupplierStats(string $username, string $month): ?array
    {
        $supplier = $this->supplierRepository->searchByUsername($username);
        if (!$supplier) {
            return null;
        }

        $supplierId = $supplier['id'];
        
        $conn = $this->orderItemRepository->getConnection();
        list($year, $monthNum) = explode('-', $month);

        $monthlyStats = $conn->fetchAssociative(
            "SELECT 
                COUNT(DISTINCT order_id) as order_count,
                COALESCE(SUM(supplier_income), 0) as total_sales,
                COALESCE(SUM(commission_amount), 0) as commission,
                COALESCE(SUM(supplier_income), 0) as net_income
            FROM order_item
            WHERE supplier_id = :supplierId
              AND YEAR(created_at) = :year
              AND MONTH(created_at) = :month
              AND order_status IN ('paid', 'shipped', 'completed')",
            ['supplierId' => $supplierId, 'year' => $year, 'month' => $monthNum]
        );

        $last12MonthsTrend = $this->orderItemRepository->getLast12MonthsIncomeTrend($supplierId);
        
        $hotProducts = $conn->fetchAllAssociative(
            "SELECT 
                p.id, p.title as name, p.sku,
                SUM(oi.quantity) as sales_count,
                SUM(oi.supplier_income) as sales_amount
            FROM order_item oi
            INNER JOIN product p ON p.id = oi.product_id
            WHERE oi.supplier_id = :supplierId
              AND YEAR(oi.created_at) = :year
              AND MONTH(oi.created_at) = :month
              AND oi.order_status IN ('paid', 'shipped', 'completed')
            GROUP BY p.id
            ORDER BY sales_count DESC
            LIMIT 5",
            ['supplierId' => $supplierId, 'year' => $year, 'month' => $monthNum]
        );

        return [
            'supplier' => $supplier,
            'monthlyStats' => $monthlyStats,
            'last12MonthsTrend' => $last12MonthsTrend,
            'hotProducts' => $hotProducts,
            'currencySymbol' => $this->siteConfigService->getCurrencySymbol(),
        ];
    }

    /**
     * 获取商品统计
     */
    public function getProductStats(): array
    {
        $cacheKey = StatsHelper::generateCacheKey('product_stats');
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $data = [
            'totalProducts' => $this->productRepository->getTotalProductCount(),
            'activeProducts' => $this->productRepository->getActiveProductCount(),
            'topProductsBySalesCount' => $this->productRepository->getTopProductsBySalesCount(100),
            'topProductsBySalesAmount' => $this->productRepository->getTopProductsBySalesAmount(100),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    /**
     * 获取财务统计
     */
    public function getFinancialStats(): array
    {
        $cacheKey = StatsHelper::generateCacheKey('financial_stats');
        
        $data = $this->cache->get($cacheKey);
        if ($data !== null) {
            return $data;
        }

        $monthlyGMV = $this->orderRepository->getMonthlyGMV();
        
        $conn = $this->orderItemRepository->getConnection();
        $result = $conn->fetchAssociative(
            "SELECT SUM(commission_amount) as total 
             FROM order_item 
             WHERE YEAR(created_at) = YEAR(CURRENT_DATE())
               AND MONTH(created_at) = MONTH(CURRENT_DATE())
               AND order_status IN ('paid', 'shipped', 'completed')"
        );
        $monthlyCommission = round((float) ($result['total'] ?? 0), 2);

        $data = [
            'monthlyGMV' => $monthlyGMV,
            'monthlyCommission' => $monthlyCommission,
            'totalCustomerBalance' => $this->customerRepository->getTotalBalance(),
            'totalCustomerFrozenBalance' => $this->customerRepository->getTotalFrozenBalance(),
            'totalSupplierPendingSettlement' => $this->supplierRepository->getTotalPendingSettlement(),
        ];

        $this->cache->setWarm($cacheKey, $data);
        
        return $data;
    }

    public function getOrdersTrend(string $period): array
    {
        $dateRange = StatsHelper::getDateRange($period);
        $days = $dateRange['start']->diff($dateRange['end'])->days;
        return $this->getOrderStats($days)['orderCountTrend'];
    }

    public function getPaymentSuccessRate(): array
    {
        return ['rate' => $this->orderRepository->getPaymentSuccessRate(30)];
    }

    public function getCustomersTrend(string $period): array
    {
        $dateRange = StatsHelper::getDateRange($period);
        $days = $dateRange['start']->diff($dateRange['end'])->days;
        return $this->getCustomerStats($days)['newCustomerTrend'];
    }

    public function getCustomerTypeDistribution(): array
    {
        return $this->customerRepository->getCustomerTypeDistribution();
    }

    public function getCustomerActivity(): array
    {
        return [
            'dau' => $this->customerRepository->getDAU(),
            'wau' => $this->customerRepository->getWAU(),
            'mau' => $this->customerRepository->getMAU(),
        ];
    }

    public function getTopSpenders(int $limit): array
    {
        return $this->customerRepository->getTopConsumers($limit);
    }

    public function getVipDistribution(): array
    {
        return $this->customerRepository->getVIPLevelDistribution();
    }

    public function getSuppliersOverview(): array
    {
        return $this->getSupplierStats(30);
    }

    public function getSuppliersTrend(string $period): array
    {
        $dateRange = StatsHelper::getDateRange($period);
        $days = $dateRange['start']->diff($dateRange['end'])->days;
        return $this->getSupplierStats($days)['newSupplierTrend'];
    }

    public function getSupplierTypeDistribution(): array
    {
        return $this->supplierRepository->getSupplierTypeDistribution();
    }

    public function getSupplierMemberDistribution(): array
    {
        return $this->supplierRepository->getMembershipTypeDistribution();
    }

    public function getProductsOverview(): array
    {
        return $this->getProductStats();
    }

    public function getTopSalesProducts(int $limit): array
    {
        return $this->productRepository->getTopProductsBySalesCount($limit);
    }

    public function getTopRevenueProducts(int $limit): array
    {
        return $this->productRepository->getTopProductsBySalesAmount($limit);
    }

    public function getFinanceOverview(): array
    {
        return $this->getFinancialStats();
    }
}
