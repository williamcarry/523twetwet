<?php

namespace App\Controller\Api;

use App\Repository\ProductRepository;
use App\Service\QiniuUploadService;
use App\Service\ProductDataFormatterService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * 平台直发页面API控制器
 * 提供平台直发页面所需的所有数据，包括:
 * 1. 热卖推荐产品（销量前5）
 * 2. 一级分类导航
 * 3. 按分类筛选的产品列表（分页）
 */
#[Route('/shop/api/direct-delivery', name: 'api_shop_direct_delivery_')]
class DirectDeliveryController extends AbstractController
{
    /**
     * 获取所有DirectDeliveryPage所需数据（一条API完成）
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
        QiniuUploadService $qiniuService,
        ProductDataFormatterService $formatterService,
        SiteConfigService $siteConfigService
    ): JsonResponse {
        try {
            // 获取参数
            $page = $request->query->getInt('page', 1);
            $pageSize = $request->query->getInt('pageSize', 20);
            $categoryId = $request->query->get('categoryId');

            // 1. 获取热卖推荐产品（销量最好的5款）
            $hotRecommendItems = $productRepository->findTopSellingProductsForDirectDelivery(5);
            $hotRecommendData = [];
            foreach ($hotRecommendItems as $item) {
                $imageUrl = $this->getSignedImageUrl($qiniuService, $item['thumbnailImage']);
                // 使用 ProductDataFormatterService 格式化数据
                $formattedProduct = $formatterService->formatProductForFrontend($item, $imageUrl);
                $formattedProduct['mainImage'] = $imageUrl; // 兼容前端字段名
                $hotRecommendData[] = $formattedProduct;
            }

            // 2. 从Redis获取一级分类数据
            $homeMenuData = $this->getFromRedis('homemenu');
            $allCategories = $homeMenuData['categories'] ?? [];
            
            // 只保留一级分类（ID和标题）
            $categoriesForNav = [];
            foreach ($allCategories as $category) {
                $categoriesForNav[] = [
                    'id' => $category['id'],
                    'title' => $category['title'],
                    'titleEn' => $category['titleEn'] ?? '',
                    'image' => $category['image'] ?? '/frondend/images/menuCategory/default.jpg'
                ];
            }

            // 3. 获取指定分类的产品列表（分页）
            $productsResult = $productRepository->findProductsByCategoryForDirectDelivery(
                $categoryId,
                $page,
                $pageSize
            );
            
            $productsData = [];
            foreach ($productsResult['data'] as $product) {
                $imageUrl = $this->getSignedImageUrl($qiniuService, $product['thumbnailImage']);
                // 使用 ProductDataFormatterService 格式化数据
                $formattedProduct = $formatterService->formatProductForFrontend($product, $imageUrl);
                $formattedProduct['mainImage'] = $imageUrl; // 兼容前端字段名
                $productsData[] = $formattedProduct;
            }
            
            // 获取网站货币符号
            $siteCurrency = $siteConfigService->getConfigValue('site_currency') ?? 'USD';

            return $this->json([
                'success' => true,
                'data' => [
                    'hotRecommend' => $hotRecommendData,
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
}
