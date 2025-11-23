<?php

namespace App\Controller\Api;

use App\Repository\ProductRepository;
use App\Service\QiniuUploadService;
use App\Service\ProductDataFormatterService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 新品页面API控制器
 * 提供新品页面所需的所有数据，包括：
 * 1. 本周强推产品（销量前5）
 * 2. 分类及每个分类的热销产品
 * 3. 带筛选和分页的产品列表
 */
#[Route('/shop/api/new-page', name: 'api_shop_new_page_')]
class NewPageController extends AbstractController
{
    private ProductDataFormatterService $dataFormatter;
    private SiteConfigService $siteConfigService;

    public function __construct(
        ProductDataFormatterService $dataFormatter,
        SiteConfigService $siteConfigService
    ) {
        $this->dataFormatter = $dataFormatter;
        $this->siteConfigService = $siteConfigService;
    }
    /**
     * 获取所有NewPage所需数据（一条API完成）
     * 
     * @param Request $request
     * @param ProductRepository $productRepository
     * @param QiniuUploadService $qiniuService
     * @return JsonResponse
     */
    #[Route('/data', name: 'data', methods: ['GET'])]
    public function getData(
        Request $request,
        ProductRepository $productRepository,
        QiniuUploadService $qiniuService
    ): JsonResponse {
        try {
            // 获取参数
            $page = $request->query->getInt('page', 1);
            $pageSize = $request->query->getInt('pageSize', 20);
            $categoryId = $request->query->get('categoryId');
            $stockMin = $request->query->get('stockMin');
            $stockMax = $request->query->get('stockMax');
            $priceMin = $request->query->get('priceMin');
            $priceMax = $request->query->get('priceMax');
            $daysNew = $request->query->get('daysNew');
            $shippingType = $request->query->get('shippingType', 'all');
            $tradeMode = $request->query->get('tradeMode', 'all');
            $sortBy = $request->query->get('sortBy', 'newest');

            // 1. 获取热销新品轮播图产品（销量最好的5款）
            $hotSlideItems = $productRepository->findHotSlideItemsForNewPage();
            $hotSlideData = [];
            foreach ($hotSlideItems as $item) {
                $imageUrl = $this->getSignedImageUrl($qiniuService, $item['thumbnailImage']);
                // 使用ProductDataFormatterService格式化多区域数据
                $hotSlideData[] = $this->dataFormatter->formatProductForFrontend(
                    $this->productToArray($item, $productRepository),
                    $imageUrl
                );
            }

            // 2. 从Redis获取分类数据
            $homeMenuData = $this->getFromRedis('homemenu');
            $categoriesForNav = $homeMenuData['categories'] ?? [];
            
            // 为所有分类添加image字段（如果没有）
            foreach ($categoriesForNav as &$category) {
                // 为每个分类添加默认图片（如果没有）
                if (!isset($category['image'])) {
                    $category['image'] = '/frondend/images/menuCategory/default.jpg';
                }
            }
            unset($category);
            
            // 3. 获取前6个分类下的热销产品（8款）用于热销新品区域
            $categoriesWithProducts = [];
            $top6Categories = array_slice($categoriesForNav, 0, 6);
            foreach ($top6Categories as $category) {
                $products = $productRepository->findTopProductsByCategoryForNewPage($category['id'], 8);
                
                $categoryProducts = [];
                foreach ($products as $product) {
                    $imageUrl = $this->getSignedImageUrl($qiniuService, $product['thumbnailImage']);
                    // 使用ProductDataFormatterService格式化多区域数据
                    $categoryProducts[] = $this->dataFormatter->formatProductForFrontend(
                        $this->productToArray($product, $productRepository),
                        $imageUrl
                    );
                }
                
                $categoriesWithProducts[] = [
                    'id' => $category['id'],
                    'title' => $category['title'],
                    'titleEn' => $category['titleEn'] ?? '',
                    'products' => $categoryProducts
                ];
            }

            // 4. 获取过滤和分页的产品列表
            $criteria = [
                'categoryId' => $categoryId,
                'stockMin' => $stockMin,
                'stockMax' => $stockMax,
                'priceMin' => $priceMin,
                'priceMax' => $priceMax,
                'daysNew' => $daysNew,
                'shippingType' => $shippingType,
                'tradeMode' => $tradeMode,
                'sortBy' => $sortBy,
            ];
            
            $productsResult = $productRepository->findFilteredProductsForNewPage($criteria, $page, $pageSize);
            
            $productsData = [];
            foreach ($productsResult['data'] as $product) {
                $imageUrl = $this->getSignedImageUrl($qiniuService, $product['thumbnailImage']);
                // 使用ProductDataFormatterService格式化多区域数据
                $productsData[] = $this->dataFormatter->formatProductForFrontend(
                    $this->productToArray($product, $productRepository),
                    $imageUrl
                );
            }
            
            // 获取网站货币符号
            $siteCurrency = $this->siteConfigService->getConfigValue('site_currency') ?? 'USD';

            return $this->json([
                'success' => true,
                'data' => [
                    'hotSlideItems' => $hotSlideData,
                    'categoriesWithProducts' => $categoriesWithProducts,
                    'categories' => $categoriesForNav,
                    'products' => [
                        'items' => $productsData,
                        'totalPages' => $productsResult['totalPages'],
                        'page' => $productsResult['page'],
                        'total' => $productsResult['total'],
                    ],
                    'siteCurrency' => $siteCurrency // 网站货币符号
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取图片签名URL
     * 处理空值和完整URL的情况
     * 
     * @param QiniuUploadService $qiniuService
     * @param string|null $imageKey
     * @return string
     */
    private function getSignedImageUrl(QiniuUploadService $qiniuService, ?string $imageKey): string
    {
        if (empty($imageKey)) {
            return '';
        }

        // 如果已经是完整URL，提取key
        if (str_starts_with($imageKey, 'http')) {
            $parts = parse_url($imageKey);
            $imageKey = ltrim($parts['path'] ?? '', '/');
            $imageKey = preg_replace('/\?.*$/', '', $imageKey);
        }

        // 生成签名URL
        return $qiniuService->getPrivateUrl($imageKey);
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

    /**
     * 将原始商品数据转换为 ProductDataFormatterService 所需的数组格式
     * 
     * @param array $productData 原始商品数据
     * @param ProductRepository $productRepository
     * @return array
     */
    private function productToArray(array $productData, ProductRepository $productRepository): array
    {
        // 获取完整的商品实体（包含关联数据）
        $product = $productRepository->find($productData['id']);
        
        if (!$product) {
            return [];
        }

        $result = [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'spu' => $product->getSpu(),
            'title' => $product->getTitle(),
            'titleEn' => $product->getTitleEn(),
            'thumbnailImage' => $product->getThumbnailImage(),
            'viewCount' => $product->getViewCount(),
            'shippingRegions' => $product->getShippingRegions() ?? [],
            'prices' => [],
            'shippings' => []
        ];

        // 添加价格信息
        foreach ($product->getPrices() as $price) {
            if ($price->isActive()) {
                $result['prices'][] = [
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
                $result['shippings'][] = [
                    'region' => $shipping->getRegion(),
                    'availableStock' => $shipping->getAvailableStock() ?? 0
                ];
            }
        }

        return $result;
    }
}
