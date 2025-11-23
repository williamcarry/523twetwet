<?php

namespace App\Controller\Api\Admin;

use App\Service\Stats\AdminStatsService;
use App\Service\Stats\CacheStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/stats', name: 'api_admin_stats_')]
class StatsController extends AbstractController
{
    public function __construct(
        private AdminStatsService $statsService,
        private CacheStatsService $cacheService
    ) {
    }

    #[Route('/platform/overview', name: 'platform_overview', methods: ['GET'])]
    public function platformOverview(): JsonResponse
    {
        $data = $this->statsService->getPlatformOverview();
        return $this->json($data);
    }

    #[Route('/orders/trend', name: 'orders_trend', methods: ['GET'])]
    public function ordersTrend(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'last_30_days');
        $data = $this->statsService->getOrdersTrend($period);
        return $this->json($data);
    }

    #[Route('/orders/payment-success-rate', name: 'orders_payment_success_rate', methods: ['GET'])]
    public function paymentSuccessRate(): JsonResponse
    {
        $data = $this->statsService->getPaymentSuccessRate();
        return $this->json($data);
    }

    #[Route('/customers/trend', name: 'customers_trend', methods: ['GET'])]
    public function customersTrend(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'last_30_days');
        $data = $this->statsService->getCustomersTrend($period);
        return $this->json($data);
    }

    #[Route('/customers/type-distribution', name: 'customers_type_distribution', methods: ['GET'])]
    public function customerTypeDistribution(): JsonResponse
    {
        $data = $this->statsService->getCustomerTypeDistribution();
        return $this->json($data);
    }

    #[Route('/customers/activity', name: 'customers_activity', methods: ['GET'])]
    public function customerActivity(): JsonResponse
    {
        $data = $this->statsService->getCustomerActivity();
        return $this->json($data);
    }

    #[Route('/customers/top-spenders', name: 'customers_top_spenders', methods: ['GET'])]
    public function topSpenders(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 100);
        $data = $this->statsService->getTopSpenders($limit);
        return $this->json($data);
    }

    #[Route('/customers/vip-distribution', name: 'customers_vip_distribution', methods: ['GET'])]
    public function vipDistribution(): JsonResponse
    {
        $data = $this->statsService->getVipDistribution();
        return $this->json($data);
    }

    #[Route('/suppliers/overview', name: 'suppliers_overview', methods: ['GET'])]
    public function suppliersOverview(): JsonResponse
    {
        $data = $this->statsService->getSuppliersOverview();
        return $this->json($data);
    }

    #[Route('/suppliers/trend', name: 'suppliers_trend', methods: ['GET'])]
    public function suppliersTrend(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'last_30_days');
        $data = $this->statsService->getSuppliersTrend($period);
        return $this->json($data);
    }

    #[Route('/suppliers/type-distribution', name: 'suppliers_type_distribution', methods: ['GET'])]
    public function supplierTypeDistribution(): JsonResponse
    {
        $data = $this->statsService->getSupplierTypeDistribution();
        return $this->json($data);
    }

    #[Route('/suppliers/member-distribution', name: 'suppliers_member_distribution', methods: ['GET'])]
    public function supplierMemberDistribution(): JsonResponse
    {
        $data = $this->statsService->getSupplierMemberDistribution();
        return $this->json($data);
    }

    #[Route('/suppliers/monthly-ranking', name: 'suppliers_monthly_ranking', methods: ['GET'])]
    public function monthlyRanking(Request $request): JsonResponse
    {
        $month = $request->query->get('month', date('Y-m'));
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 100);
        $sortBy = $request->query->get('sortBy', 'sales'); // 新增：排序方式
        
        $data = $this->statsService->getSupplierMonthlyRanking($month, $page, $limit, $sortBy);
        return $this->json($data);
    }

    #[Route('/suppliers/search', name: 'suppliers_search', methods: ['GET'])]
    public function searchSupplier(Request $request): JsonResponse
    {
        $username = $request->query->get('username');
        $month = $request->query->get('month', date('Y-m'));
        
        if (!$username) {
            return $this->json(['error' => 'Username is required'], 400);
        }
        
        $data = $this->statsService->searchSupplierStats($username, $month);
        
        if (!$data) {
            return $this->json(['error' => 'Supplier not found'], 404);
        }
        
        return $this->json($data);
    }

    #[Route('/products/overview', name: 'products_overview', methods: ['GET'])]
    public function productsOverview(): JsonResponse
    {
        $data = $this->statsService->getProductsOverview();
        return $this->json($data);
    }

    #[Route('/products/top-sales', name: 'products_top_sales', methods: ['GET'])]
    public function topSalesProducts(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 100);
        $data = $this->statsService->getTopSalesProducts($limit);
        return $this->json($data);
    }

    #[Route('/products/top-revenue', name: 'products_top_revenue', methods: ['GET'])]
    public function topRevenueProducts(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 100);
        $data = $this->statsService->getTopRevenueProducts($limit);
        return $this->json($data);
    }

    #[Route('/finance/overview', name: 'finance_overview', methods: ['GET'])]
    public function financeOverview(): JsonResponse
    {
        $data = $this->statsService->getFinanceOverview();
        return $this->json($data);
    }

    /**
     * 清除统计缓存
     */
    #[Route('/cache/clear', name: 'cache_clear', methods: ['POST'])]
    public function clearCache(): JsonResponse
    {
        try {
            // 清除所有stats:开头的缓存键
            $deletedCount = $this->cacheService->deleteByPrefix('stats:');
            
            return $this->json([
                'success' => true,
                'message' => "缓存清除成功，共清除 {$deletedCount} 个缓存键",
                'deletedCount' => $deletedCount
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '缓存清除失败: ' . $e->getMessage()
            ], 500);
        }
    }
}
