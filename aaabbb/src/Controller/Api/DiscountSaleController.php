<?php

namespace App\Controller\Api;

use App\Entity\Product;
use App\Service\QiniuUploadService;
use App\Service\ProductDataFormatterService;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 折扣销售控制器
 * 为折扣销售页面提供数据接口
 */
#[Route('/shop/api/discount-sale', name: 'api_shop_discount_sale_')]
class DiscountSaleController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private QiniuUploadService $qiniuService;
    private ProductDataFormatterService $dataFormatter;
    private SiteConfigService $siteConfigService;

    public function __construct(
        EntityManagerInterface $entityManager, 
        QiniuUploadService $qiniuService,
        ProductDataFormatterService $dataFormatter,
        SiteConfigService $siteConfigService
    ) {
        $this->entityManager = $entityManager;
        $this->qiniuService = $qiniuService;
        $this->dataFormatter = $dataFormatter;
        $this->siteConfigService = $siteConfigService;
    }

    /**
     * 获取折扣销售页面所有数据（一次性返回）
     * 包括：分类、今日折扣、限量供应、新品特惠
     */
    #[Route('/data', name: 'data', methods: ['GET'])]
    public function getData(): JsonResponse
    {
        try {
            // 从Redis获取分类数据
            $homeMenuData = $this->getFromRedis('homemenu');
            $categories = $homeMenuData['categories'] ?? [];

            // 获取今日折扣（12个）
            $qb1 = $this->entityManager->createQueryBuilder();
            $qb1->select('DISTINCT p')
                ->from(Product::class, 'p')
                ->leftJoin('p.prices', 'pr')
                ->where('p.status = :status')
                ->andWhere('pr.discountRate > 0')
                ->setParameter('status', 'approved')
                ->orderBy('p.updatedAt', 'DESC')
                ->setMaxResults(12);
            $todayDiscounts = $qb1->getQuery()->getResult();
            $todayDiscountIds = array_map(fn($p) => $p->getId(), $todayDiscounts);

            // 获取限量供应（12个，排除今日折扣）
            $qb2 = $this->entityManager->createQueryBuilder();
            $qb2->select('DISTINCT p')
                ->from(Product::class, 'p')
                ->leftJoin('p.prices', 'pr')
                ->where('p.status = :status')
                ->andWhere('pr.discountRate > 0')
                ->andWhere('p.isLimited = :isLimited')
                ->setParameter('status', 'approved')
                ->setParameter('isLimited', true)
                ->orderBy('p.updatedAt', 'DESC')
                ->setMaxResults(12);
            if (!empty($todayDiscountIds)) {
                $qb2->andWhere('p.id NOT IN (:excludeIds)')
                    ->setParameter('excludeIds', $todayDiscountIds);
            }
            $limitedSupply = $qb2->getQuery()->getResult();
            $limitedSupplyIds = array_map(fn($p) => $p->getId(), $limitedSupply);

            // 获取新品特惠（12个，排除今日折扣和限量供应）
            $excludeIds = array_merge($todayDiscountIds, $limitedSupplyIds);
            $qb3 = $this->entityManager->createQueryBuilder();
            $qb3->select('DISTINCT p')
                ->from(Product::class, 'p')
                ->leftJoin('p.prices', 'pr')
                ->where('p.status = :status')
                ->andWhere('pr.discountRate > 0')
                ->setParameter('status', 'approved')
                ->orderBy('p.updatedAt', 'DESC')
                ->setMaxResults(12);
            if (!empty($excludeIds)) {
                $qb3->andWhere('p.id NOT IN (:excludeIds)')
                    ->setParameter('excludeIds', $excludeIds);
            }
            $newLaunch = $qb3->getQuery()->getResult();

            // 格式化数据
            $formatProduct = function($product) {
                $thumbnailUrl = $this->qiniuService->getPrivateUrl($product->getThumbnailImage());
                return $this->dataFormatter->formatProductForFrontend(
                    $this->productToArray($product),
                    $thumbnailUrl
                );
            };
            
            // 获取网站货币符号
            $siteCurrency = $this->siteConfigService->getConfigValue('site_currency') ?? 'USD';

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'categories' => $categories,
                    'todayDiscounts' => array_map($formatProduct, $todayDiscounts),
                    'limitedSupply' => array_map($formatProduct, $limitedSupply),
                    'newLaunch' => array_map($formatProduct, $newLaunch),
                    'siteCurrency' => $siteCurrency // 网站货币符号
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取数据失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取分类菜单（三级联动）
     * 从Redis获取categories数据
     */
    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function getCategories(): JsonResponse
    {
        try {
            // 从Redis获取homemenu数据
            $homeMenuData = $this->getFromRedis('homemenu');
            $categories = $homeMenuData['categories'] ?? [];

            return new JsonResponse([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取分类失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 今日折扣精选
     * 查找 discountRate > 0 的商品，按 updatedAt 排序，最近的时间排在最前面，返回12个商品
     */
    #[Route('/today-discounts', name: 'today_discounts', methods: ['GET'])]
    public function getTodayDiscounts(): JsonResponse
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('DISTINCT p')
                ->from(Product::class, 'p')
                ->leftJoin('p.prices', 'pr')
                ->where('p.status = :status')
                ->andWhere('pr.discountRate > 0')
                ->setParameter('status', 'approved')
                ->orderBy('p.updatedAt', 'DESC')
                ->setMaxResults(12);

            $products = $qb->getQuery()->getResult();
            $data = [];

            foreach ($products as $product) {
                $thumbnailUrl = $this->qiniuService->getPrivateUrl($product->getThumbnailImage());
                $data[] = $this->dataFormatter->formatProductForFrontend(
                    $this->productToArray($product),
                    $thumbnailUrl
                );
            }

            return new JsonResponse([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取今日折扣失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 限量供应商品
     * 查找 discountRate > 0 且 isLimited = 1 的商品，
     * 按 updatedAt 排序，最近的时间排在最前面，
     * 排除今日折扣的所有商品ID，返回12个商品
     */
    #[Route('/limited-supply', name: 'limited_supply', methods: ['GET'])]
    public function getLimitedSupply(): JsonResponse
    {
        try {
            // 先获取今日折扣的商品ID
            $todayDiscountIds = $this->getTodayDiscountIds();

            // 查询限量供应商品
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('DISTINCT p')
                ->from(Product::class, 'p')
                ->leftJoin('p.prices', 'pr')
                ->where('p.status = :status')
                ->andWhere('pr.discountRate > 0')
                ->andWhere('p.isLimited = :isLimited')
                ->setParameter('status', 'approved')
                ->setParameter('isLimited', true)
                ->orderBy('p.updatedAt', 'DESC')
                ->setMaxResults(12);

            if (!empty($todayDiscountIds)) {
                $qb->andWhere('p.id NOT IN (:excludeIds)')
                    ->setParameter('excludeIds', $todayDiscountIds);
            }

            $products = $qb->getQuery()->getResult();
            $data = [];

            foreach ($products as $product) {
                $thumbnailUrl = $this->qiniuService->getPrivateUrl($product->getThumbnailImage());
                $data[] = $this->dataFormatter->formatProductForFrontend(
                    $this->productToArray($product),
                    $thumbnailUrl
                );
            }

            return new JsonResponse([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取限量供应商品失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 新品特惠
     * 查找 discountRate > 0 的商品，按 updatedAt 排序，最近的时间排在最前面，
     * 排除今日折扣和限量供应的所有商品ID，返回12个商品
     */
    #[Route('/new-launch', name: 'new_launch', methods: ['GET'])]
    public function getNewLaunch(): JsonResponse
    {
        try {
            // 获取今日折扣的商品ID
            $todayDiscountIds = $this->getTodayDiscountIds();

            // 获取限量供应的商品ID
            $limitedSupplyIds = $this->getLimitedSupplyIds($todayDiscountIds);

            // 合并排除的ID
            $excludeIds = array_merge($todayDiscountIds, $limitedSupplyIds);

            // 查询新品特惠商品
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('DISTINCT p')
                ->from(Product::class, 'p')
                ->leftJoin('p.prices', 'pr')
                ->where('p.status = :status')
                ->andWhere('pr.discountRate > 0')
                ->setParameter('status', 'approved')
                ->orderBy('p.updatedAt', 'DESC')
                ->setMaxResults(12);

            if (!empty($excludeIds)) {
                $qb->andWhere('p.id NOT IN (:excludeIds)')
                    ->setParameter('excludeIds', $excludeIds);
            }

            $products = $qb->getQuery()->getResult();
            $data = [];

            foreach ($products as $product) {
                $thumbnailUrl = $this->qiniuService->getPrivateUrl($product->getThumbnailImage());
                $data[] = $this->dataFormatter->formatProductForFrontend(
                    $this->productToArray($product),
                    $thumbnailUrl
                );
            }

            return new JsonResponse([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取新品特惠失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 折扣商品列表（带分页和筛选）
     * 使用两步查询模式确保分页准确性
     * 
     * 参数:
     * - page: 页码（从 1 开始）
     * - pageSize: 每页数量（默认 20）
     * - categoryId: 一级分类 ID
     * - subcategoryId: 二级分类 ID
     * - itemId: 三级分类 ID
     * - discountRange: 折扣范围
     * - priceMin: 最低价格
     * - priceMax: 最高价格
     * - sortBy: 排序方式
     */
    #[Route('/products', name: 'products', methods: ['GET'])]
    public function getProducts(Request $request): JsonResponse
    {
        try {
            // 获取参数
            $page = $request->query->getInt('page', 1);
            $pageSize = $request->query->getInt('pageSize', 20);
            $categoryId = $request->query->get('categoryId');
            $subcategoryId = $request->query->get('subcategoryId');
            $itemId = $request->query->get('itemId');
            $discountRange = $request->query->get('discountRange', 'all');
            $priceMin = $request->query->get('priceMin');
            $priceMax = $request->query->get('priceMax');
            $sortBy = $request->query->get('sortBy', 'viewCount');

            // ========================================
            // 第一步：获取符合条件的商品ID列表（分页）
            // ========================================
            $idsQb = $this->entityManager->createQueryBuilder();
            $idsQb->select('DISTINCT p.id')
                ->from(Product::class, 'p')
                ->leftJoin('p.prices', 'pr')
                ->where('p.status = :status')
                ->andWhere('pr.discountRate > 0')
                ->setParameter('status', 'approved');

            // 折扣范围筛选
            if ($discountRange === '0.01-0.1') {
                $idsQb->andWhere('pr.discountRate >= 0.01 AND pr.discountRate <= 0.1');
            } elseif ($discountRange === '0.1-0.2') {
                $idsQb->andWhere('pr.discountRate >= 0.1 AND pr.discountRate <= 0.2');
            } elseif ($discountRange === '0.2-0.5') {
                $idsQb->andWhere('pr.discountRate >= 0.2 AND pr.discountRate <= 0.5');
            } elseif ($discountRange === '0.5-1') {
                $idsQb->andWhere('pr.discountRate >= 0.5');
            }

            // 分类筛选
            if ($categoryId !== null && $categoryId != '0') {
                $idsQb->andWhere('p.category = :categoryId')
                    ->setParameter('categoryId', $categoryId);
            }
            if ($subcategoryId !== null && $subcategoryId != '0') {
                $idsQb->andWhere('p.subcategory = :subcategoryId')
                    ->setParameter('subcategoryId', $subcategoryId);
            }
            if ($itemId !== null && $itemId != '0') {
                $idsQb->andWhere('p.item = :itemId')
                    ->setParameter('itemId', $itemId);
            }

            // 价格筛选
            if ($priceMin !== null && $priceMin !== '') {
                $idsQb->andWhere('pr.sellingPrice >= :priceMin')
                    ->setParameter('priceMin', $priceMin);
            }
            if ($priceMax !== null && $priceMax !== '') {
                $idsQb->andWhere('pr.sellingPrice <= :priceMax')
                    ->setParameter('priceMax', $priceMax);
            }

            // 排序 - 使用原生SQL确保基于第一个区域数据
            $conn = $this->entityManager->getConnection();
            
            // 构建筛选条件SQL
            $whereClauses = ["p.status = 'approved'"];
            $params = [];
            
            // 折扣范围筛选（基于第一个区域）
            if ($discountRange === '0.01-0.1') {
                $whereClauses[] = "(SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, '$[0]')) AND pr.is_active = 1 LIMIT 1) BETWEEN 0.01 AND 0.1";
            } elseif ($discountRange === '0.1-0.2') {
                $whereClauses[] = "(SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, '$[0]')) AND pr.is_active = 1 LIMIT 1) BETWEEN 0.1 AND 0.2";
            } elseif ($discountRange === '0.2-0.5') {
                $whereClauses[] = "(SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, '$[0]')) AND pr.is_active = 1 LIMIT 1) BETWEEN 0.2 AND 0.5";
            } elseif ($discountRange === '0.5-1') {
                $whereClauses[] = "(SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, '$[0]')) AND pr.is_active = 1 LIMIT 1) >= 0.5";
            } else {
                $whereClauses[] = "(SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, '$[0]')) AND pr.is_active = 1 LIMIT 1) > 0";
            }
            
            // 分类筛选
            if ($categoryId !== null && $categoryId != '0') {
                $whereClauses[] = "p.category_id = :categoryId";
                $params['categoryId'] = $categoryId;
            }
            if ($subcategoryId !== null && $subcategoryId != '0') {
                $whereClauses[] = "p.subcategory_id = :subcategoryId";
                $params['subcategoryId'] = $subcategoryId;
            }
            if ($itemId !== null && $itemId != '0') {
                $whereClauses[] = "p.item_id = :itemId";
                $params['itemId'] = $itemId;
            }
            
            // 价格筛选（基于第一个区域）
            if ($priceMin !== null && $priceMin !== '') {
                $whereClauses[] = "(SELECT pr.selling_price FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, '$[0]')) AND pr.is_active = 1 LIMIT 1) >= :priceMin";
                $params['priceMin'] = $priceMin;
            }
            if ($priceMax !== null && $priceMax !== '') {
                $whereClauses[] = "(SELECT pr.selling_price FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, '$[0]')) AND pr.is_active = 1 LIMIT 1) <= :priceMax";
                $params['priceMax'] = $priceMax;
            }
            
            $whereSQL = implode(' AND ', $whereClauses);
            
            // 构建排序子查询
            $orderBySQL = '';
            if ($sortBy === 'viewCount') {
                $orderBySQL = 'p.view_count DESC, p.id DESC';
            } elseif ($sortBy === 'discount') {
                // 按折扣率降序排序（折扣率越大越靠前）
                $orderBySQL = '(SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, "$[0]")) AND pr.is_active = 1 LIMIT 1) DESC, p.id DESC';
            } elseif ($sortBy === 'price-asc') {
                // 按折扣后价格升序排序：售价 × (1 - 折扣率)
                $orderBySQL = '((SELECT pr.selling_price FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, "$[0]")) AND pr.is_active = 1 LIMIT 1) * (1 - COALESCE((SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, "$[0]")) AND pr.is_active = 1 LIMIT 1), 0))) ASC, p.id ASC';
            } elseif ($sortBy === 'price-desc') {
                // 按折扣后价格降序排序：售价 × (1 - 折扣率)
                $orderBySQL = '((SELECT pr.selling_price FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, "$[0]")) AND pr.is_active = 1 LIMIT 1) * (1 - COALESCE((SELECT pr.discount_rate FROM product_price pr WHERE pr.product_id = p.id AND pr.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, "$[0]")) AND pr.is_active = 1 LIMIT 1), 0))) DESC, p.id DESC';
            } elseif ($sortBy === 'stock-desc') {
                // 按库存降序排序
                $orderBySQL = '(SELECT sh.available_stock FROM product_shipping sh WHERE sh.product_id = p.id AND sh.region = JSON_UNQUOTE(JSON_EXTRACT(p.shipping_regions, "$[0]")) AND sh.is_active = 1 LIMIT 1) DESC, p.id DESC';
            } else {
                $orderBySQL = 'p.view_count DESC, p.id DESC';
            }
            
            // 获取总数
            $countSQL = "SELECT COUNT(DISTINCT p.id) FROM product p WHERE " . $whereSQL;
            $total = (int)$conn->fetchOne($countSQL, $params);
            
            // 分页查询ID列表
            $offset = ($page - 1) * $pageSize;
            // 注意：LIMIT 和 OFFSET 不能使用命名参数，需要直接拼接整数值
            $idsSQL = "SELECT DISTINCT p.id FROM product p WHERE " . $whereSQL . " ORDER BY " . $orderBySQL . " LIMIT " . (int)$pageSize . " OFFSET " . (int)$offset;
            
            $productIds = $conn->fetchFirstColumn($idsSQL, $params);

            // 如果没有符合条件的商品，直接返回空结果
            if (empty($productIds)) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'products' => [],
                        'pagination' => [
                            'page' => $page,
                            'pageSize' => $pageSize,
                            'total' => 0,
                            'totalPages' => 0
                        ]
                    ]
                ]);
            }

            // ========================================
            // 第二步：根据ID列表获取完整的商品数据
            // ========================================
            $productsQb = $this->entityManager->createQueryBuilder();
            $productsQb->select('p')
                ->from(Product::class, 'p')
                ->where('p.id IN (:productIds)')
                ->setParameter('productIds', $productIds);

            $products = $productsQb->getQuery()->getResult();
            
            // 根据第一步的ID顺序重新排列结果（解决Doctrine IN查询顺序不一致的问题）
            $orderedProducts = [];
            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product->getId()] = $product;
            }
            foreach ($productIds as $id) {
                if (isset($productMap[$id])) {
                    $orderedProducts[] = $productMap[$id];
                }
            }
            
            $data = [];
            foreach ($orderedProducts as $product) {
                $thumbnailUrl = $this->qiniuService->getPrivateUrl($product->getThumbnailImage());
                $data[] = $this->dataFormatter->formatProductForFrontend(
                    $this->productToArray($product),
                    $thumbnailUrl
                );
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'products' => $data,
                    'pagination' => [
                        'page' => $page,
                        'pageSize' => $pageSize,
                        'total' => $total,
                        'totalPages' => (int) ceil($total / $pageSize)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取商品列表失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取今日折扣的商品ID
     */
    private function getTodayDiscountIds(): array
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p.id')
            ->from(Product::class, 'p')
            ->leftJoin('p.prices', 'pr')
            ->where('p.status = :status')
            ->andWhere('pr.discountRate > 0')
            ->setParameter('status', 'approved')
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults(12)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn($row) => $row['id'], $result);
    }

    /**
     * 获取限量供应的商品ID（返回12个）
     */
    private function getLimitedSupplyIds(array $excludeIds): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT p.id')
            ->from(Product::class, 'p')
            ->leftJoin('p.prices', 'pr')
            ->where('p.status = :status')
            ->andWhere('pr.discountRate > 0')
            ->andWhere('p.isLimited = :isLimited')
            ->setParameter('status', 'approved')
            ->setParameter('isLimited', true)
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults(12);

        if (!empty($excludeIds)) {
            $qb->andWhere('p.id NOT IN (:excludeIds)')
                ->setParameter('excludeIds', $excludeIds);
        }

        $result = $qb->getQuery()->getScalarResult();
        return array_map(fn($row) => $row['id'], $result);
    }

    /**
     * 将Product实体转换为数组（用于ProductDataFormatterService）
     */
    private function productToArray(Product $product): array
    {
        $productData = [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'spu' => $product->getSpu(),
            'title' => $product->getTitle(),
            'titleEn' => $product->getTitleEn(),
            'thumbnailImage' => $product->getThumbnailImage(),
            'viewCount' => $product->getViewCount(),
            'tags' => $product->getTags() ?? [],
            'updatedAt' => $product->getUpdatedAt(),
            'shippingRegions' => $product->getShippingRegions() ?? [],
            'supportDropship' => $product->isSupportDropship(),
            'supportWholesale' => $product->isSupportWholesale(),
            'supportCircleBuy' => $product->isSupportCircleBuy(),
            'supportSelfPickup' => $product->isSupportSelfPickup(),
            'prices' => [],
            'shippings' => []
        ];

        // 添加价格信息
        foreach ($product->getPrices() as $price) {
            if ($price->isActive()) {
                $productData['prices'][] = [
                    'region' => $price->getRegion(),
                    'originalPrice' => $price->getOriginalPrice(),
                    'sellingPrice' => $price->getSellingPrice(),
                    'discountRate' => $price->getDiscountRate(),
                    'currency' => $price->getCurrency()
                ];
            }
        }

        // 添加运费和库存信息
        foreach ($product->getShippings() as $shipping) {
            if ($shipping->isActive()) {
                $productData['shippings'][] = [
                    'region' => $shipping->getRegion(),
                    'availableStock' => $shipping->getAvailableStock() ?? 0
                ];
            }
        }

        return $productData;
    }

    /**
     * 获取商品价格信息
     * @deprecated 使用ProductDataFormatterService替代
     */
    private function getProductPriceInfo(Product $product): array
    {
        // 获取商品的价格信息（优先获取一件代发的价格）
        $prices = $product->getPrices();
        $defaultPrice = [
            'currency' => 'CNY',
            'originalPrice' => '0.0000',
            'sellingPrice' => '0.0000',
            'discountRate' => '0.0000'
        ];

        if ($prices->isEmpty()) {
            return $defaultPrice;
        }

        // 优先查找一件代发业务类型的价格
        foreach ($prices as $price) {
            if ($price->getBusinessType() === 'dropship' && $price->isActive()) {
                return [
                    'currency' => $price->getCurrency(),
                    'originalPrice' => number_format((float)$price->getOriginalPrice(), 4, '.', ''),
                    'sellingPrice' => number_format((float)$price->getSellingPrice(), 4, '.', ''),
                    'discountRate' => number_format((float)$price->getDiscountRate(), 4, '.', '')
                ];
            }
        }

        // 如果没有一件代发价格，获取第一个激活的价格
        foreach ($prices as $price) {
            if ($price->isActive()) {
                return [
                    'currency' => $price->getCurrency(),
                    'originalPrice' => number_format((float)$price->getOriginalPrice(), 4, '.', ''),
                    'sellingPrice' => number_format((float)$price->getSellingPrice(), 4, '.', ''),
                    'discountRate' => number_format((float)$price->getDiscountRate(), 4, '.', '')
                ];
            }
        }

        return $defaultPrice;
    }


    /**
     * 从Redis获取数据
     * 从Redis获取homemenu数据
     * $homeMenuData = $this->getFromRedis('homemenu');
     * 'categories' => $homeMenuData['categories'] ?? []
     * @param string $key Redis键名
     * @return array|null 解析后的数据数组，如果获取失败或数据不存在则返回null
     */
    private function getFromRedis(string $key): ?array
    {
        try {
            // 检查Redis扩展是否可用
            if (!extension_loaded('redis')) {
                return null;
            }

            // 检查Redis类是否存在
            if (!class_exists('\Redis')) {
                return null;
            }

            // 创建Redis连接
            // @phpstan-ignore-line
            $redis = new \Redis(); 
            // 从环境变量读取Redis配置
            $redisUrl = $_ENV['REDIS_KHUMFG'] ?? 'redis://localhost:6379';
            $parsedUrl = parse_url($redisUrl);

            $host = $parsedUrl['host'] ?? 'localhost';
            $port = $parsedUrl['port'] ?? 6379;
            $password = isset($parsedUrl['pass']) ? urldecode($parsedUrl['pass']) : null;

            // 连接到Redis服务器
            $redis->connect($host, $port);
            // 如果有密码，需要认证
            if ($password) {
                $redis->auth($password);
            }

            // 获取数据
            $data = $redis->get($key);
            $redis->close();

            // 如果数据存在，解析JSON
            if ($data !== false) {
                $decodedData = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decodedData;
                }
            }

            return null;
        } catch (\Exception $e) {
            // Redis连接或操作失败，返回null
            return null;
        }
    }
}
