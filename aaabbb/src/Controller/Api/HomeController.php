<?php

namespace App\Controller\Api;

use App\Repository\MenuCategoryRepository;
use App\Repository\HorizontalMenuRepository;
use App\Repository\ProductRepository;
use App\Service\QiniuUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Redis;

/**
 * 商城首页控制器
 * 
 * 提供商城首页所需的数据接口，主要为CategorySidebar.vue组件提供三级分类菜单数据
 */
#[Route('/shop/api/home', name: 'api_shop_home_')]
class HomeController extends AbstractController
{
    private MenuCategoryRepository $menuCategoryRepository;
    private HorizontalMenuRepository $horizontalMenuRepository;
    private ProductRepository $productRepository;

    public function __construct(
        MenuCategoryRepository $menuCategoryRepository,
        HorizontalMenuRepository $horizontalMenuRepository,
        ProductRepository $productRepository
    ) {
        $this->menuCategoryRepository = $menuCategoryRepository;
        $this->horizontalMenuRepository = $horizontalMenuRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * 获取首页三级分类菜单数据
     * 优先从Redis缓存读取，如果没有则从数据库读取
     * 数据结构与CategorySidebar.vue组件要求的格式保持一致
     * 
     * @return JsonResponse
     */
    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function getCategories(): JsonResponse
    {
        try {
            // 尝试从Redis获取数据
            $redisData = $this->getFromRedis('homemenu');
            if ($redisData !== null) {
                // 从Redis获取到数据后，也需要添加轮播图数据
                $sliders = $this->getSliders();
                $redisData['sliders'] = $sliders;
                
                return $this->json([
                    'success' => true,
                    'data' => $redisData
                ]);
            }
            // dd("ddd");
            // 如果Redis中没有数据，从数据库读取
            // 使用优化的查询方法一次性获取完整的分类树结构，按sortOrder升序排列
            $categories = $this->menuCategoryRepository->findCategoryTreeWithSubCategoriesAndItems();
            
            // 获取横向菜单数据
            $horizontalMenus = $this->horizontalMenuRepository->findAllGroupedByPosition();

            $categoryData = [];
            foreach ($categories as $category) {
                $categoryItem = [
                    'id' =>$category->getId(),
                    'title' => $category->getTitle(),
                    'titleEn' => $category->getTitleEn(),
                    'icon' => $category->getIcon(),
                    'href' => '#',
                    'children' => [],
                    'promotions' => []
                ];
                
                // 获取该分类下的所有启用的促销菜单
                $promotions = $category->getPromotions()->filter(function($promotion) {
                    return $promotion->isActive();
                })->toArray();
                
                // 按ID排序促销菜单
                usort($promotions, function($a, $b) {
                    return $a->getId() <=> $b->getId();
                });
                
                // 处理促销菜单数据
                foreach ($promotions as $promotion) {
                    $promotionData = [
                        'id' => $promotion->getId(),
                        'categoryId' =>$promotion->getCategoryId(),
                        'title' => $promotion->getTitle(),
                        'titleEn' => $promotion->getTitleEn(),
                        'imageUrl' => $promotion->getImageUrl() // 返回图片key，前端再获取签名URL
                    ];
                    $categoryItem['promotions'][] = $promotionData;
                }
                
                // 获取该分类下的所有启用的子分类，已按sortOrder排序
                $subcategories = $category->getSubcategories()->filter(function($subcategory) {
                    return $subcategory->isActive();
                })->toArray();
                
                // 确保按sortOrder排序
                usort($subcategories, function($a, $b) {
                    return $a->getSortOrder() <=> $b->getSortOrder();
                });
                
                foreach ($subcategories as $subcategory) {
                    $subcategoryItem = [
                        'id' => $subcategory->getId(),
                        'title' => $subcategory->getTitle(),
                        'titleEn' => $subcategory->getTitleEn(),
                        'items' => []
                    ];
                    
                    // 获取该子分类下的所有启用的菜单项，已按sortOrder排序
                    $items = $subcategory->getItems()->filter(function($item) {
                        return $item->isActive();
                    })->toArray();
                    
                    // 确保按sortOrder排序
                    usort($items, function($a, $b) {
                        return $a->getSortOrder() <=> $b->getSortOrder();
                    });
                    
                    foreach ($items as $item) {
                        $subcategoryItem['items'][] = [
                            'id' => $item->getId(),
                            'title' => $item->getTitle(),
                            'titleEn' => $item->getTitleEn(),
                            'href' => '#'
                        ];
                    }
                    
                    $categoryItem['children'][] = $subcategoryItem;
                }
                
                $categoryData[] = $categoryItem;
            }

            // 处理floor菜单中的产品数据
            if (isset($horizontalMenus['floor']) && is_array($horizontalMenus['floor'])) {
                $horizontalMenus['floor'] = $this->processFloorMenuProducts($horizontalMenus['floor']);
            }
            
            // 获取平台爆款数据
            $platformBoutique = $this->getPlatformBoutiqueProducts();
            
            // 获取轮播图数据
            $sliders = $this->getSliders();
            
            // dd($horizontalMenus);
            return $this->json([
                'success' => true,
                'data' => [
                    'categories' => $categoryData,
                    'horizontalMenus' => $horizontalMenus,
                    'platformBoutique' => $platformBoutique,
                    'sliders' => $sliders
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '获取分类数据失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 获取首页轮播图数据
     * 读取 public/frondend/images/slider 目录下的所有图片
     * 
     * @return array
     */
    private function getSliders(): array
    {
        try {
            $sliderDirectory = $this->getParameter('kernel.project_dir') . '/public/frondend/images/slider';
            
            // 如果目录不存在，返回空数组
            if (!is_dir($sliderDirectory)) {
                return [];
            }

            $sliders = [];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            $directory = new \DirectoryIterator($sliderDirectory);
            foreach ($directory as $fileInfo) {
                if ($fileInfo->isDot() || $fileInfo->isDir()) {
                    continue;
                }

                $extension = strtolower($fileInfo->getExtension());
                if (!in_array($extension, $allowedExtensions)) {
                    continue;
                }

                $sliders[] = [
                    'name' => $fileInfo->getFilename(),
                    'path' => '/frondend/images/slider/' . $fileInfo->getFilename(),
                    'url' => '/frondend/images/slider/' . $fileInfo->getFilename() // 为前端提供直接可用的URL
                ];
            }

            // 按文件名排序，确保顺序一致
            usort($sliders, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

            return $sliders;
        } catch (\Exception $e) {
            // 如果发生错误，返回空数组，不影响其他数据的返回
            return [];
        }
    }
    
    /**
     * 获取所有分类数据
     * 优先从Redis缓存读取，如果没有则返回空数组
     * 
     * @return JsonResponse
     */
    #[Route('/all-categories', name: 'all_categories', methods: ['GET'])]
    public function getAllCategories(): JsonResponse
    {
        try {
            // 尝试从Redis获取数据
            $homeMenuData = $this->getFromRedis('homemenu');
            $categories = $homeMenuData['categories'] ?? [];
            
            return $this->json([
                'success' => true,
                'data' => [
                    'categories' => $categories
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '获取分类数据失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 获取平台爆款产品数据
     * 根据平台标签查询销量最高的5个产品
     * 确保每个产品只出现在一个平台中（不重复）
     * 
     * @return array
     */
    private function getPlatformBoutiqueProducts(): array
    {
        // 定义平台标签（不区分大小写）
        $platformTags = [
            'amazon',
            'walmart',
            'ebay',
            'temu',
            'shein',
            'tiktok'
        ];
        
        $qiniuService = new QiniuUploadService();
        $platformData = [];
        $usedProductIds = []; // 记录已经使用过的商品ID
        
        foreach ($platformTags as $tag) {
            // 查询包含该标签的已上架商品，按销量降序
            // 排除已经被其他平台使用的商品
            $queryBuilder = $this->productRepository->createQueryBuilder('p')
                ->where('p.status = :status')
                ->andWhere('LOWER(p.tags) LIKE :tag')
                ->setParameter('status', 'approved')
                ->setParameter('tag', '%"' . strtolower($tag) . '"%')
                ->orderBy('p.salesCount', 'DESC');
            
            // 如果有已使用的商品ID，排除它们
            if (!empty($usedProductIds)) {
                $queryBuilder->andWhere('p.id NOT IN (:usedIds)')
                    ->setParameter('usedIds', $usedProductIds);
            }
            
            $products = $queryBuilder
                ->setMaxResults(5)
                ->getQuery()
                ->getResult();
            
            $productList = [];
            foreach ($products as $product) {
                // 记录该商品ID，防止被其他平台使用
                $usedProductIds[] = $product->getId();
                
                $thumbnailImage = $product->getThumbnailImage();
                $thumbnailImageUrl = '';
                
                if ($thumbnailImage) {
                    // 处理旧数据（如果存的是完整URL，提取key）
                    if (str_starts_with($thumbnailImage, 'http')) {
                        $parts = parse_url($thumbnailImage);
                        $thumbnailImage = ltrim($parts['path'] ?? '', '/');
                        $thumbnailImage = preg_replace('/\?.*$/', '', $thumbnailImage);
                    }
                    // 生成签名URL
                    $thumbnailImageUrl = $qiniuService->getPrivateUrl($thumbnailImage);
                }
                
                $productList[] = [
                    'id' => $product->getId(),
                    'title' => $product->getTitle(),
                    'titleEn' => $product->getTitleEn(),
                    'img' => $thumbnailImageUrl
                ];
            }
            
            $platformData[] = [
                'key' => $tag,
                'products' => $productList
            ];
        }
        
        return $platformData;
    }
    
    /**
     * 从Redis获取数据
     * 
     * @param string $key Redis键名
     * @return array|null 解析后的数据数组，如果获取失败或数据不存在则返回null
     */
    private function getFromRedis(string $key): ?array
    {
        try {
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
    
    /**
     * 处理楼层菜单中的产品数据
     * 
     * @param array $floorMenus 楼层菜单数组
     * @return array 处理后的楼层菜单数组
     */
    private function processFloorMenuProducts(array $floorMenus): array
    {
        // 收集所有需要查询的产品ID
        $productIds = [];
        foreach ($floorMenus as $menu) {
            if (isset($menu['productIds']) && is_array($menu['productIds'])) {
                $productIds = array_merge($productIds, $menu['productIds']);
            }
        }
        
        // 去重
        $productIds = array_unique($productIds);
        
        // 如果没有产品ID，直接返回原数组
        if (empty($productIds)) {
            return $floorMenus;
        }
        
        // 查询产品数据
        $products = $this->productRepository->findBy(['id' => $productIds]);
        
        // 创建产品ID到产品数据的映射
        $productMap = [];
        foreach ($products as $product) {
            $productMap[$product->getId()] = $product;
        }
        
        // 创建七牛云服务实例用于生成签名URL
        $qiniuService = new QiniuUploadService();
        
        // 处理每个楼层菜单
        foreach ($floorMenus as &$menu) {
            if (isset($menu['productIds']) && is_array($menu['productIds'])) {
                $menu['products'] = [];
                foreach ($menu['productIds'] as $productId) {
                    if (isset($productMap[$productId])) {
                        $product = $productMap[$productId];
                        $thumbnailImage = $product->getThumbnailImage();
                        
                        // 生成签名URL
                        $thumbnailImageUrl = '';
                        if ($thumbnailImage) {
                            // 处理旧数据（如果存的是完整URL，提取key）
                            if (str_starts_with($thumbnailImage, 'http')) {
                                $parts = parse_url($thumbnailImage);
                                $thumbnailImage = ltrim($parts['path'] ?? '', '/');
                                $thumbnailImage = preg_replace('/\?.*$/', '', $thumbnailImage);
                            }
                            // 生成签名URL
                            $thumbnailImageUrl = $qiniuService->getPrivateUrl($thumbnailImage);
                        }
                        
                        $menu['products'][] = [
                            'id' => $product->getId(),
                            'thumbnail_image' => $thumbnailImageUrl,
                            'title' => $product->getTitle(),
                            'titleEn' => $product->getTitleEn()
                        ];
                    }
                }
            }
        }
        
        return $floorMenus;
    }
    
    /**
     * 获取图片签名URL
     * 根据图片key生成七牛云签名URL
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/image-signed-url', name: 'image_signed_url', methods: ['POST'])]
    public function getImageSignedUrl(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $key = $data['key'] ?? '';
            
            if (empty($key)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '图片key不能为空'
                ], 400);
            }
            
            // 如果已经是完整URL，直接返回
            if (str_starts_with($key, 'http')) {
                return new JsonResponse([
                    'success' => true,
                    'url' => $key
                ]);
            }
            
            // 生成七牛云签名URL
            $qiniuService = new QiniuUploadService();
            $signedUrl = $qiniuService->getPrivateUrl($key);
            
            return new JsonResponse([
                'success' => true,
                'url' => $signedUrl
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取图片签名URL失败: ' . $e->getMessage()
            ], 500);
        }
    }
}