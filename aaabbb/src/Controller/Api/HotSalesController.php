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

#[Route('/shop/api/hot-sales', name: 'api_shop_hot_sales_')]
class HotSalesController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private QiniuUploadService $qiniuService;
    private ProductDataFormatterService $formatterService;
    private SiteConfigService $siteConfigService;

    public function __construct(
        EntityManagerInterface $entityManager, 
        QiniuUploadService $qiniuService,
        ProductDataFormatterService $formatterService,
        SiteConfigService $siteConfigService
    ) {
        $this->entityManager = $entityManager;
        $this->qiniuService = $qiniuService;
        $this->formatterService = $formatterService;
        $this->siteConfigService = $siteConfigService;
    }

    /**
     * 获取热销排行榜数据
     */
    #[Route('/rankings', name: 'rankings', methods: ['GET'])]
    public function getRankings(Request $request): JsonResponse
    {
        // 获取查询参数
        $categoryId = $request->query->get('categoryId');
        $subcategoryId = $request->query->get('subcategoryId');
        
        // 构建查询
        $qb = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p');
        $qb->where('p.status = :status')
           ->setParameter('status', 'approved');
        
        // 根据分类筛选
        if ($categoryId !== null) {
            $qb->andWhere('p.category = :categoryId')
               ->setParameter('categoryId', $categoryId);
        }
        if ($subcategoryId !== null) {
            $qb->andWhere('p.subcategory = :subcategoryId')
               ->setParameter('subcategoryId', $subcategoryId);
        }
        
        $allProducts = $qb->getQuery()->getResult();
        
        // 按不同维度排序并选出前10个商品，确保不重复
        $usedProductIds = [];
        $hotRankings = $this->getTopUniqueProducts($allProducts, 'viewCount', 10, $usedProductIds);
        $favoriteRankings = $this->getTopUniqueProducts($allProducts, 'favoriteCount', 10, $usedProductIds);
        $downloadRankings = $this->getTopUniqueProducts($allProducts, 'downloadCount', 10, $usedProductIds);
        $publishRankings = $this->getTopUniqueProducts($allProducts, 'publishCount', 10, $usedProductIds);
        
        // 从Redis获取homemenu数据
        $homeMenuData = $this->getFromRedis('homemenu');
        
        // 获取网站货币符号
        $siteCurrency = $this->siteConfigService->getConfigValue('site_currency') ?? 'USD';
        
        // 格式化数据
        $responseData = [
            'hotRankings' => $this->formatProducts($hotRankings),
            'favoriteRankings' => $this->formatProducts($favoriteRankings),
            'downloadRankings' => $this->formatProducts($downloadRankings),
            'publishRankings' => $this->formatProducts($publishRankings),
            'categories' => $homeMenuData['categories'] ?? [],
            'siteCurrency' => $siteCurrency // 网站货币符号
        ];
        
        return $this->json($responseData);
    }

    /**
     * 获取商品列表（支持分页）
     */
    #[Route('/products', name: 'products', methods: ['GET'])]
    public function getProducts(Request $request): JsonResponse
    {
        // 获取查询参数
        $categoryId = $request->query->get('categoryId');
        $subcategoryId = $request->query->get('subcategoryId');
        $page = $request->query->getInt('page', 1);
        $limit = 18; // 每页 18个商品
        
        // 构建筛选条件
        $criteria = ['status' => 'approved'];
        
        // 根据分类筛选
        if ($categoryId !== null && $categoryId > 0) {
            $criteria['categoryId'] = $categoryId;
        }
        if ($subcategoryId !== null && $subcategoryId > 0) {
            $criteria['subcategoryId'] = $subcategoryId;
        }
        
        // 使用Repository获取分页数据
        $productRepository = $this->entityManager->getRepository(Product::class);
        $result = $productRepository->findPaginatedForFrontend($criteria, $page, $limit);
        
        // 使用ProductDataFormatterService格式化数据
        $products = [];
        foreach ($result['data'] as $productData) {
            // 生成签名的缩略图URL
            $thumbnailImageUrl = null;
            if (!empty($productData['thumbnailImage'])) {
                $thumbnailImageUrl = $this->qiniuService->getPrivateUrl($productData['thumbnailImage']);
            }
            
            // 使用 ProductDataFormatterService 构建商品数据
            $products[] = $this->formatterService->formatProductForFrontend($productData, $thumbnailImageUrl);
        }
        
        // 获取网站货币符号
        $siteCurrency = $this->siteConfigService->getConfigValue('site_currency') ?? 'USD';
        
        return $this->json([
            'products' => $products,
            'pagination' => [
                'currentPage' => $result['page'],
                'totalPages' => $result['totalPages'],
                'totalItems' => $result['total'],
                'itemsPerPage' => $result['limit'],
            ],
            'siteCurrency' => $siteCurrency // 网站货币符号
        ]);
    }

    /**
     * 按指定字段排序并获取前N个不重复的商品
     */
    private function getTopUniqueProducts(array $products, string $field, int $limit, array &$usedProductIds): array
    {
        // 按指定字段排序
        usort($products, function ($a, $b) use ($field) {
            $method = 'get' . ucfirst($field);
            return $b->$method() <=> $a->$method();
        });
        
        // 筛选出未使用过的商品
        $uniqueProducts = [];
        foreach ($products as $product) {
            if (!in_array($product->getId(), $usedProductIds) && count($uniqueProducts) < $limit) {
                $uniqueProducts[] = $product;
                $usedProductIds[] = $product->getId();
            }
        }
        
        return $uniqueProducts;
    }



    /**
     * 格式化商品数据（用于排行榜）
     */
    private function formatProducts(array $products): array
    {
        $formatted = [];
        foreach ($products as $product) {
            // 构建简化的productData结构（仅包含排行榜需要的字段）
            $productData = [
                'id' => $product->getId(),
                'sku' => $product->getSku(),
                'spu' => $product->getSpu(),
                'title' => $product->getTitle(),
                'titleEn' => $product->getTitleEn() ?: $product->getTitle(),
                'thumbnailImage' => $product->getThumbnailImage(),
                'shippingRegions' => $product->getShippingRegions() ?? [],
                // 排行榜特有字段
                'viewCount' => $product->getViewCount(),
                'favoriteCount' => $product->getFavoriteCount(),
                'downloadCount' => $product->getDownloadCount(),
                'publishCount' => $product->getPublishCount(),
                // 关联数据（用于ProductDataFormatterService）
                'shippings' => [],
                'prices' => []
            ];
            
            // 添加shippings数据
            foreach ($product->getShippings() as $shipping) {
                $productData['shippings'][] = [
                    'region' => $shipping->getRegion(),
                    'availableStock' => $shipping->getAvailableStock() ?? 0
                ];
            }
            
            // 添加prices数据
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
            
            // 生成缩略图URL
            $thumbnailImageUrl = null;
            if (!empty($productData['thumbnailImage'])) {
                $thumbnailImageUrl = $this->qiniuService->getPrivateUrl($productData['thumbnailImage']);
            }
            
            // 使用 ProductDataFormatterService 构建商品数据
            $formattedProduct = $this->formatterService->formatProductForFrontend($productData, $thumbnailImageUrl);
            
            // 添加排行榜特有字段
            $formattedProduct['heat'] = $product->getViewCount();
            $formattedProduct['favorites'] = $product->getFavoriteCount();
            $formattedProduct['downloads'] = $product->getDownloadCount();
            $formattedProduct['publishes'] = $product->getPublishCount();
            $formattedProduct['price'] = $formattedProduct['currency'] . ' ' . number_format((float)$formattedProduct['sellingPrice'], 2, '.', '');
            
            $formatted[] = $formattedProduct;
        }
        return $formatted;
    }

    /**
     * 从redis获取数据
     * 
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