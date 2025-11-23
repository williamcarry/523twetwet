<?php

namespace App\Controller\Api;

use App\Repository\ProductRepository;
use App\Service\QiniuUploadService;
use App\Service\AliyunNlpService;
use App\Service\ProductDataFormatterService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop/api/product', name: 'api_shop_product_')]
class ProductController extends AbstractController
{
    #[Route('/allproduct', name: 'allproduct')]
    public function allProduct(
        Request $request, 
        ProductRepository $productRepository,
        ProductDataFormatterService $formatterService,
        SiteConfigService $siteConfigService
    ): JsonResponse {
        // 获取分页参数
        $page = $request->query->getInt('page', 1);
        $limit = 20; // 每页20个商品
        
        // 获取分类筛选参数
        $categoryId = $request->query->get('categoryId');
        $subcategoryId = $request->query->get('subcategoryId');
        $itemId = $request->query->get('itemId');
        
        // 获取条件1：上新时间筛选参数
        $newTime = $request->query->get('newTime'); // 7, 15, 30, 60
        
        // 获取条件2：发货类型筛选参数
        $shipment = $request->query->get('shipment'); // 0-平台发货, 1-供应商自发货
        
        // 获取条件3：交易模式筛选参数
        $saleMode = $request->query->get('saleMode'); // 0-一件代发, 1-批发, 2-圈货, 3-自提
        
        // 获取排序参数
        $sortField = $request->query->get('sortField', 'viewCount'); // viewCount, updatedAt, salesCount, price, downloadCount, stock
        $sortOrder = $request->query->get('sortOrder', 'DESC'); // ASC, DESC
        
        // 获取库存和价格筛选参数（独立条件）
        $stockMin = $request->query->get('stockMin');
        $stockMax = $request->query->get('stockMax');
        $priceMin = $request->query->get('priceMin');
        $priceMax = $request->query->get('priceMax');
        
        // 从Redis获取homemenu数据
        $homeMenuData = $this->getFromRedis('homemenu');
        
        // 处理分类筛选条件
        $criteria = ['status' => 'approved'];
        
        // 处理分类筛选逻辑
        if ($itemId) {
            $criteria['itemId'] = $itemId;
        } elseif ($subcategoryId) {
            $criteria['subcategoryId'] = $subcategoryId;
        } elseif ($categoryId) {
            $criteria['categoryId'] = $categoryId;
        }
        
        // 条件1：上新时间筛选（根据updated_at字段）
        if ($newTime) {
            $days = (int)$newTime;
            $date = new \DateTime();
            $date->modify("-{$days} days");
            $criteria['updatedAtAfter'] = $date;
        }
        
        // 条件2：发货类型筛选（根据商品标签）
        if ($shipment !== null && $shipment !== '') {
            if ($shipment === '0') {
                // 平台发货：tags包含"平台直发"
                $criteria['hasTag'] = '平台直发';
            } else {
                // 供应商自发货：tags不包含"平台直发"
                $criteria['notHasTag'] = '平台直发';
            }
        }
        
        // 条件3：交易模式筛选（OR关系）
        if ($saleMode !== null && $saleMode !== '') {
            switch ($saleMode) {
                case '0':
                    $criteria['supportDropship'] = true;
                    break;
                case '1':
                    $criteria['supportWholesale'] = true;
                    break;
                case '2':
                    $criteria['supportCircleBuy'] = true;
                    break;
                case '3':
                    $criteria['supportSelfPickup'] = true;
                    break;
            }
        }
        
        // 添加排序参数
        $criteria['sortField'] = $sortField;
        $criteria['sortOrder'] = strtoupper($sortOrder);
        
        // 添加库存和价格筛选参数（独立条件）
        if ($stockMin !== null && $stockMin !== '') {
            $criteria['stockMin'] = (int)$stockMin;
        }
        if ($stockMax !== null && $stockMax !== '') {
            $criteria['stockMax'] = (int)$stockMax;
        }
        if ($priceMin !== null && $priceMin !== '') {
            $criteria['priceMin'] = (float)$priceMin;
        }
        if ($priceMax !== null && $priceMax !== '') {
            $criteria['priceMax'] = (float)$priceMax;
        }
        
        // 使用仓库方法获取分页数据
        $result = $productRepository->findPaginatedForFrontend($criteria, $page, $limit);
        
        // 初始化七牛云服务
        $qiniuService = new QiniuUploadService();
        
        // 格式化返回数据
        $products = [];
        foreach ($result['data'] as $productData) {
            // 生成签名的缩略图URL
            $thumbnailImageUrl = null;
            if (!empty($productData['thumbnailImage'])) {
                $thumbnailImageUrl = $qiniuService->getPrivateUrl($productData['thumbnailImage']);
            }
            
            // 使用 ProductDataFormatterService 构建商品数据
            $products[] = $formatterService->formatProductForFrontend($productData, $thumbnailImageUrl);
        }
        
        // 获取网站货币符号
        $siteCurrency = $siteConfigService->getConfigValue('site_currency') ?? 'USD';
        
        return $this->json([
            'products' => $products,
            'pagination' => [
                'currentPage' => $result['page'],
                'totalPages' => $result['totalPages'],
                'totalItems' => $result['total'],
                'itemsPerPage' => $result['limit'],
            ],
            'categories' => $homeMenuData['categories'] ?? [], // 只返回分类数据供前端使用
            'siteCurrency' => $siteCurrency // 网站货币符号
        ]);
    }
    #[Route('/categories-allproduct', name: 'categories_allproduct')]
    public function categoriesAllProduct(
        Request $request, 
        ProductRepository $productRepository,
        ProductDataFormatterService $formatterService,
        SiteConfigService $siteConfigService
    ): JsonResponse {
        // 获取分页参数
        $page = $request->query->getInt('page', 1);
        $limit = 20; // 每页20个商品
        
        // 获取分类筛选参数
        $categoryId = $request->query->get('categoryId');
        $subcategoryId = $request->query->get('subcategoryId');
        $itemId = $request->query->get('itemId');
        
        // 获取条件1：上新时间筛选参数
        $newTime = $request->query->get('newTime'); // 7, 15, 30, 60
        
        // 获取条件2：发货类型筛选参数
        $shipment = $request->query->get('shipment'); // 0-平台发货, 1-供应商自发货
        
        // 获取条件3：交易模式筛选参数
        $saleMode = $request->query->get('saleMode'); // 0-一件代发, 1-批发, 2-圈货, 3-自提
        
        // 获取排序参数
        $sortField = $request->query->get('sortField', 'viewCount'); // viewCount, updatedAt, salesCount, price, downloadCount, stock
        $sortOrder = $request->query->get('sortOrder', 'DESC'); // ASC, DESC
        
        // 获取库存和价格筛选参数（独立条件）
        $stockMin = $request->query->get('stockMin');
        $stockMax = $request->query->get('stockMax');
        $priceMin = $request->query->get('priceMin');
        $priceMax = $request->query->get('priceMax');
        
        // 从Redis获取homemenu数据
        $homeMenuData = $this->getFromRedis('homemenu');
        
        // 处理分类筛选条件
        $criteria = ['status' => 'approved'];
        
        // 处理分类筛选逻辑
        if ($itemId) {
            $criteria['itemId'] = $itemId;
        } elseif ($subcategoryId) {
            $criteria['subcategoryId'] = $subcategoryId;
        } elseif ($categoryId) {
            $criteria['categoryId'] = $categoryId;
        }
        
        // 条件1：上新时间筛选（根据updated_at字段）
        if ($newTime) {
            $days = (int)$newTime;
            $date = new \DateTime();
            $date->modify("-{$days} days");
            $criteria['updatedAtAfter'] = $date;
        }
        
        // 条件2：发货类型筛选（根据商品标签）
        if ($shipment !== null && $shipment !== '') {
            if ($shipment === '0') {
                // 平台发货：tags包含"平台直发"
                $criteria['hasTag'] = '平台直发';
            } else {
                // 供应商自发货：tags不包含"平台直发"
                $criteria['notHasTag'] = '平台直发';
            }
        }
        
        // 条件3：交易模式筛选（OR关系）
        if ($saleMode !== null && $saleMode !== '') {
            switch ($saleMode) {
                case '0':
                    $criteria['supportDropship'] = true;
                    break;
                case '1':
                    $criteria['supportWholesale'] = true;
                    break;
                case '2':
                    $criteria['supportCircleBuy'] = true;
                    break;
                case '3':
                    $criteria['supportSelfPickup'] = true;
                    break;
            }
        }
        
        // 添加排序参数
        $criteria['sortField'] = $sortField;
        $criteria['sortOrder'] = strtoupper($sortOrder);
        
        // 添加库存和价格筛选参数（独立条件）
        if ($stockMin !== null && $stockMin !== '') {
            $criteria['stockMin'] = (int)$stockMin;
        }
        if ($stockMax !== null && $stockMax !== '') {
            $criteria['stockMax'] = (int)$stockMax;
        }
        if ($priceMin !== null && $priceMin !== '') {
            $criteria['priceMin'] = (float)$priceMin;
        }
        if ($priceMax !== null && $priceMax !== '') {
            $criteria['priceMax'] = (float)$priceMax;
        }
        
        // 使用仓库方法获取分页数据
        $result = $productRepository->findPaginatedForFrontend($criteria, $page, $limit);
        
        // 初始化七牛云服务
        $qiniuService = new QiniuUploadService();
        
        // 格式化返回数据
        $products = [];
        foreach ($result['data'] as $productData) {
            // 生成签名的缩略图URL
            $thumbnailImageUrl = null;
            if (!empty($productData['thumbnailImage'])) {
                $thumbnailImageUrl = $qiniuService->getPrivateUrl($productData['thumbnailImage']);
            }
            
            // 使用 ProductDataFormatterService 构建商品数据
            $products[] = $formatterService->formatProductForFrontend($productData, $thumbnailImageUrl);
        }
        
        // 获取网站货币符号
        $siteCurrency = $siteConfigService->getConfigValue('site_currency') ?? 'USD';
        
        return $this->json([
            'products' => $products,
            'pagination' => [
                'currentPage' => $result['page'],
                'totalPages' => $result['totalPages'],
                'totalItems' => $result['total'],
                'itemsPerPage' => $result['limit'],
            ],
            'categories' => $homeMenuData['categories'] ?? [], // 只返回分类数据供前端使用
            'siteCurrency' => $siteCurrency // 网站货币符号
        ]);
    }
    
    #[Route('/cross-bordere-commerce', name: 'cross_bordere_commerce')]
    public function CrossBorderEcommerce(
        Request $request,
        ProductRepository $productRepository,
        ProductDataFormatterService $formatterService,
        SiteConfigService $siteConfigService
    ): JsonResponse {
        // 获取分页参数
        $page = $request->query->getInt('page', 1);
        $limit = 20; // 每页20个商品
        
        // 获取分类筛选参数
        $categoryId = $request->query->get('categoryId');
        $subcategoryId = $request->query->get('subcategoryId');
        $itemId = $request->query->get('itemId');
        
        // ===== 核心功能：获取平台参数 =====
        // 平台值：amazon, walmart, ebay, temu, shein, tiktok
        // 默认为amazon
        $platform = $request->query->get('platform', 'amazon');
        
        // 获取条件1：上新时间筛选参数
        $newTime = $request->query->get('newTime'); // 7, 15, 30, 60
        
        // 获取条件2：发货类型筛选参数
        $shipment = $request->query->get('shipment'); // 0-平台发货, 1-供应商自发货
        
        // 获取条件3：交易模式筛选参数
        $saleMode = $request->query->get('saleMode'); // 0-一件代发, 1-批发, 2-圈货, 3-自提
        
        // 获取排序参数
        $sortField = $request->query->get('sortField', 'viewCount'); // viewCount, updatedAt, salesCount, price, downloadCount, stock
        $sortOrder = $request->query->get('sortOrder', 'DESC'); // ASC, DESC
        
        // 获取库存和价格筛选参数（独立条件）
        $stockMin = $request->query->get('stockMin');
        $stockMax = $request->query->get('stockMax');
        $priceMin = $request->query->get('priceMin');
        $priceMax = $request->query->get('priceMax');
        
        // 从Redis获取homemenu数据
        $homeMenuData = $this->getFromRedis('homemenu');
        
        // 处理分类筛选条件
        $criteria = ['status' => 'approved'];
        
        // ===== 核心功能：添加平台标签筛选 =====
        // 只显示 tags 中包含该平台名称的商品
        if ($platform) {
            $criteria['platformTag'] = $platform;
        }
        
        // 处理分类筛选逻辑
        if ($itemId) {
            $criteria['itemId'] = $itemId;
        } elseif ($subcategoryId) {
            $criteria['subcategoryId'] = $subcategoryId;
        } elseif ($categoryId) {
            $criteria['categoryId'] = $categoryId;
        }
        
        // 条件1：上新时间筛选（根据updated_at字段）
        if ($newTime) {
            $days = (int)$newTime;
            $date = new \DateTime();
            $date->modify("-{$days} days");
            $criteria['updatedAtAfter'] = $date;
        }
        
        // 条件2：发货类型筛选（根据商品标签）
        if ($shipment !== null && $shipment !== '') {
            if ($shipment === '0') {
                // 平台发货：tags包含"平台直发"
                $criteria['hasTag'] = '平台直发';
            } else {
                // 供应商自发货：tags不包含"平台直发"
                $criteria['notHasTag'] = '平台直发';
            }
        }
        
        // 条件3：交易模式筛选（OR关系）
        if ($saleMode !== null && $saleMode !== '') {
            switch ($saleMode) {
                case '0':
                    $criteria['supportDropship'] = true;
                    break;
                case '1':
                    $criteria['supportWholesale'] = true;
                    break;
                case '2':
                    $criteria['supportCircleBuy'] = true;
                    break;
                case '3':
                    $criteria['supportSelfPickup'] = true;
                    break;
            }
        }
        
        // 添加排序参数
        $criteria['sortField'] = $sortField;
        $criteria['sortOrder'] = strtoupper($sortOrder);
        
        // 添加库存和价格筛选参数（独立条件）
        if ($stockMin !== null && $stockMin !== '') {
            $criteria['stockMin'] = (int)$stockMin;
        }
        if ($stockMax !== null && $stockMax !== '') {
            $criteria['stockMax'] = (int)$stockMax;
        }
        if ($priceMin !== null && $priceMin !== '') {
            $criteria['priceMin'] = (float)$priceMin;
        }
        if ($priceMax !== null && $priceMax !== '') {
            $criteria['priceMax'] = (float)$priceMax;
        }
        
        // ===== 使用跨境电商专用方法获取分页数据 =====
        $result = $productRepository->findPaginatedForCrossBorderEcommerce($criteria, $page, $limit);
        // dd($result);
        // 初始化七牛云服务
        $qiniuService = new QiniuUploadService();
        
        // 使用 ProductDataFormatterService 格式化数据
        $products = [];
        foreach ($result['data'] as $productData) {
            // 生成缩略图签名URL
            $thumbnailImageUrl = null;
            if (!empty($productData['thumbnailImage'])) {
                $thumbnailImageUrl = $qiniuService->getPrivateUrl($productData['thumbnailImage']);
            }
            
            // 使用服务格式化商品数据（包含多区域配置）
            $products[] = $formatterService->formatProductForFrontend(
                $productData,
                $thumbnailImageUrl
            );
        }
        
        // 获取网站货币符号
        $siteCurrency = $siteConfigService->getConfigValue('site_currency') ?? 'USD';
        
        return $this->json([
            'products' => $products,
            'pagination' => [
                'currentPage' => $result['page'],
                'totalPages' => $result['totalPages'],
                'totalItems' => $result['total'],
                'itemsPerPage' => $result['limit'],
            ],
            'categories' => $homeMenuData['categories'] ?? [], // 只返回分类数据供前端使用
            'siteCurrency' => $siteCurrency // 网站货币符号
        ]);
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
    
    /**
     * 商品搜索API - 使用中文分词
     * 搜索商品的中文标题、英文标题和标签
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(
        Request $request, 
        ProductRepository $productRepository,
        AliyunNlpService $nlpService,
        ProductDataFormatterService $formatterService
    ): JsonResponse {
        // 获取搜索关键词
        $keyword = trim($request->query->get('q', ''));
        
        // 如果关键词为空，返回空结果
        if (empty($keyword)) {
            return $this->json([
                'products' => [],
                'pagination' => [
                    'currentPage' => 1,
                    'totalPages' => 0,
                    'totalItems' => 0,
                    'itemsPerPage' => 20,
                ],
                'keyword' => '',
                'segments' => []
            ]);
        }
        
        // 获取分页参数
        $page = $request->query->getInt('page', 1);
        $limit = 20;
        
        // 使用阿里云NLP进行中文分词
        $segments = $nlpService->segmentForProductSearch($keyword);
        
        // 构建搜索条件
        $criteria = [
            'status' => 'approved',
            'searchKeyword' => $keyword,
            'searchSegments' => $segments,
            'sortField' => 'viewCount',
            'sortOrder' => 'DESC'
        ];
        
        // 使用仓库方法进行搜索
        $result = $productRepository->searchProducts($criteria, $page, $limit);
        
        // 初始化七牛云服务
        $qiniuService = new QiniuUploadService();
        
        // 格式化返回数据（使用 ProductDataFormatterService 支持多区域）
        $products = [];
        foreach ($result['data'] as $productData) {
            // 生成签名的缩略图URL
            $thumbnailImageUrl = null;
            if (!empty($productData['thumbnailImage'])) {
                $thumbnailImageUrl = $qiniuService->getPrivateUrl($productData['thumbnailImage']);
            }
            
            // 使用 ProductDataFormatterService 格式化商品数据（包括多区域配置）
            $products[] = $formatterService->formatProductForFrontend($productData, $thumbnailImageUrl);
        }
        
        return $this->json([
            'products' => $products,
            'pagination' => [
                'currentPage' => $result['page'],
                'totalPages' => $result['totalPages'],
                'totalItems' => $result['total'],
                'itemsPerPage' => $result['limit'],
            ],
            'keyword' => $keyword,
            'segments' => $segments // 返回分词结果，便于调试
        ]);
    }
}