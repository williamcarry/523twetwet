<?php

namespace App\Controller\Api\Supplier;

use App\Service\Stats\SupplierStatsService;
use App\Service\Stats\CacheStatsService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/supplier/stats', name: 'api_supplier_stats_')]
class StatsController extends AbstractController
{
    public function __construct(
        private SupplierStatsService $statsService,
        private CacheStatsService $cacheService,
        private SiteConfigService $siteConfigService
    ) {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->statsService->getDashboardOverview($supplier->getId());
        
        // 添加货币符号
        $data['currencySymbol'] = $this->siteConfigService->getCurrencySymbol();
        
        return $this->json($data);
    }

    #[Route('/sales/trend', name: 'sales_trend', methods: ['GET'])]
    public function salesTrend(Request $request): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', 'last_30_days');
        $data = $this->statsService->getSalesTrend($supplier->getId(), $period);
        
        // 添加货币符号到结果中
        $result = [
            'data' => $data,
            'currencySymbol' => $this->siteConfigService->getCurrencySymbol()
        ];
        
        return $this->json($result);
    }

    #[Route('/sales/category', name: 'sales_category', methods: ['GET'])]
    public function salesByCategory(Request $request): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', 'this_month');
        $data = $this->statsService->getSalesByCategory($supplier->getId(), $period);
        return $this->json($data);
    }

    #[Route('/sales/region', name: 'sales_region', methods: ['GET'])]
    public function salesByRegion(Request $request): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', 'this_month');
        $data = $this->statsService->getSalesByRegion($supplier->getId(), $period);
        return $this->json($data);
    }

    #[Route('/finance/settlement', name: 'finance_settlement', methods: ['GET'])]
    public function settlementStats(): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->statsService->getSettlementStats($supplier->getId());
        return $this->json($data);
    }

    #[Route('/finance/trend', name: 'finance_trend', methods: ['GET'])]
    public function financeTrend(Request $request): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', 'last_12_months');
        $data = $this->statsService->getFinanceTrend($supplier->getId(), $period);
        
        // 添加货币符号到结果中
        $result = [
            'data' => $data,
            'currencySymbol' => $this->siteConfigService->getCurrencySymbol()
        ];
        
        return $this->json($result);
    }

    #[Route('/products/overview', name: 'products_overview', methods: ['GET'])]
    public function productsOverview(): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->statsService->getProductsOverview($supplier->getId());
        return $this->json($data);
    }

    #[Route('/products/ranking', name: 'products_ranking', methods: ['GET'])]
    public function productsRanking(Request $request): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', 'this_month');
        $type = $request->query->get('type', 'sales');
        $limit = (int) $request->query->get('limit', 10);

        $data = $this->statsService->getProductsRanking($supplier->getId(), $period, $type, $limit);
        
        // 添加货币符号到结果中
        $result = [
            'data' => $data,
            'currencySymbol' => $this->siteConfigService->getCurrencySymbol()
        ];
        
        return $this->json($result);
    }

    #[Route('/refund/overview', name: 'refund_overview', methods: ['GET'])]
    public function refundOverview(): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->statsService->getRefundOverview($supplier->getId());
        return $this->json($data);
    }

    #[Route('/refund/trend', name: 'refund_trend', methods: ['GET'])]
    public function refundTrend(Request $request): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', 'last_30_days');
        $data = $this->statsService->getRefundTrend($supplier->getId(), $period);
        return $this->json($data);
    }

    /**
     * 清除统计缓存
     */
    #[Route('/cache/clear', name: 'cache_clear', methods: ['POST'])]
    public function clearCache(): JsonResponse
    {
        $supplier = $this->getUser();
        if (!$supplier) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

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
