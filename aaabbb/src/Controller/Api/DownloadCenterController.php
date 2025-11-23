<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerDownloadRecord;
use App\Message\DownloadProductJob;
use App\Repository\CustomerDownloadRecordRepository;
use App\Repository\CustomerMonthlyStatsRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteConfigRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\RsaCryptoService;
use App\Service\QiniuUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 下载中心API控制器
 * 
 * 路由规范：/shop/api/download-center
 * 安全规范：所有接口需要用户认证 + API签名验证 + RSA加密传输
 * 
 * 功能说明：
 * - 获取用户下载记录列表（仅当月）
 * - 获取用户下载统计（已用/总额度）
 * - 生成下载链接（七牛云私有空间签名URL）
 */
#[Route('/shop/api/download-center', name: 'api_download_center_')]
class DownloadCenterController extends AbstractController
{
    public function __construct(
        private CustomerDownloadRecordRepository $downloadRecordRepository,
        private CustomerMonthlyStatsRepository $monthlyStatsRepository,
        private SiteConfigRepository $siteConfigRepository,
        private ProductRepository $productRepository,
        private RsaCryptoService $rsaCryptoService,
        private QiniuUploadService $qiniuUploadService,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * 获取下载记录列表
     * 
     * 请求参数（加密后传输）：
     * - page: 页码（默认1）
     * - pageSize: 每页数量（默认20）
     * 
     * 返回数据：
     * - list: 下载记录列表（仅当月）
     * - pagination: 分页信息
     * - downloadStats: 下载统计（已用/总额度）
     */
    #[Route('/list', name: 'list', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function list(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }

        // 分页参数
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($data['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        // 获取当前年月
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');

        // 查询当月下载记录总数
        $total = $this->downloadRecordRepository->countByCustomerAndMonth(
            $customer->getId(),
            $currentYear,
            $currentMonth
        );

        // 查询当月下载记录（分页）
        $records = $this->downloadRecordRepository->findByCustomerAndMonth(
            $customer->getId(),
            $currentYear,
            $currentMonth,
            $offset,
            $pageSize
        );

        // 格式化下载记录数据（添加序列号）
        $recordList = [];
        $serialNumber = $offset + 1; // 序列号从当前页的起始位置开始
        foreach ($records as $record) {
            $recordList[] = $this->formatDownloadRecord($record, $serialNumber++);
        }

        // 获取下载统计信息
        $downloadStats = $this->getDownloadStats($customer);

        return $this->json([
            'success' => true,
            'data' => [
                'list' => $recordList,
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => ceil($total / $pageSize)
                ],
                'downloadStats' => $downloadStats
            ]
        ]);
    }

    /**
     * 获取下载统计信息
     * 
     * 返回数据：
     * - vipLevel: 当前VIP等级
     * - vipLevelName: VIP等级名称
     * - downloadQuota: 月下载额度
     * - downloadUsed: 本月已使用次数
     * - downloadRemaining: 剩余次数
     * - year: 统计年份
     * - month: 统计月份
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function stats(): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $downloadStats = $this->getDownloadStats($customer);

        return $this->json([
            'success' => true,
            'data' => $downloadStats
        ]);
    }

    /**
     * 检查商品是否已下载过
     * 
     * 请求参数（加密后传输）：
     * - productIds: 商品ID数组
     * 
     * 返回数据：
     * - downloadedProductIds: 已下载过的商品ID数组
     */
    #[Route('/check-downloaded', name: 'check_downloaded', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function checkDownloaded(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                // 向下兼容：如果没有encryptedPayload，使用原始数据
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }

        $productIds = $data['productIds'] ?? [];
        
        if (empty($productIds) || !is_array($productIds)) {
            return $this->json([
                'success' => false,
                'message' => '商品ID不能为空',
                'messageEn' => 'Product IDs cannot be empty'
            ], 400);
        }

        // 获取当前年月
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');

        // 查询当月已下载过的商品ID（只查询已完成的记录）
        $downloadedProductIds = $this->downloadRecordRepository->createQueryBuilder('d')
            ->select('d.productId')
            ->where('d.customerId = :customerId')
            ->andWhere('d.downloadYear = :year')
            ->andWhere('d.downloadMonth = :month')
            ->andWhere('d.status = :status') // 只查询已完成的记录
            ->andWhere('d.productId IN (:productIds)')
            ->setParameter('customerId', $customer->getId())
            ->setParameter('year', $currentYear)
            ->setParameter('month', $currentMonth)
            ->setParameter('status', 'completed')
            ->setParameter('productIds', $productIds)
            ->getQuery()
            ->getResult();

        // 提取商品ID
        $downloadedIds = array_map(function($item) {
            return $item['productId'];
        }, $downloadedProductIds);

        return $this->json([
            'success' => true,
            'data' => [
                'downloadedProductIds' => $downloadedIds
            ]
        ]);
    }

    /**
     * 生成下载链接
     * 
     * 请求参数（加密后传输）：
     * - recordId: 下载记录ID
     * 
     * 返回数据：
     * - downloadUrl: 七牛云私有空间签名URL（有效期1小时）
     * - expiresIn: 链接有效期（秒）
     */
    #[Route('/generate-url', name: 'generate_url', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function generateDownloadUrl(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }

        // 验证必填字段
        if (empty($data['recordId'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少记录ID参数',
                'messageEn' => 'Missing record ID parameter'
            ], 400);
        }

        // 查询下载记录
        $record = $this->downloadRecordRepository->find($data['recordId']);
        
        if (!$record) {
            return $this->json([
                'success' => false,
                'message' => '下载记录不存在',
                'messageEn' => 'Download record not found'
            ], 404);
        }

        // 验证记录是否属于当前用户
        if ($record->getCustomerId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '无权访问此下载记录',
                'messageEn' => 'No permission to access this download record'
            ], 403);
        }

        // 检查记录是否有下载链接
        $downloadLink = $record->getDownloadLink();
        if (empty($downloadLink)) {
            return $this->json([
                'success' => false,
                'message' => '文件尚未生成，请稍后重试',
                'messageEn' => 'File is being generated, please try again later'
            ], 400);
        }

        // 查询商品信息以获取标题
        $product = $this->productRepository->find($record->getProductId());
        
        // 根据请求的语言选择商品标题
        $lang = $data['lang'] ?? 'zh-CN';
        $productTitle = $this->getProductTitle($product, $lang);
        
        // 生成合法的文件名
        $fileName = $this->sanitizeFileName($productTitle) . '.zip';

        // 生成七牛云私有空间签名URL（有效期1小时）
        try {
            $expiresIn = 3600; // 1小时
            $downloadUrl = $this->qiniuUploadService->getPrivateUrl($downloadLink, $expiresIn);
            
            // 添加文件名参数（使用 attname 参数指定下载文件名）
            $separator = strpos($downloadUrl, '?') !== false ? '&' : '?';
            $downloadUrl .= $separator . 'attname=' . urlencode($fileName);
            
            return $this->json([
                'success' => true,
                'data' => [
                    'downloadUrl' => $downloadUrl,
                    'expiresIn' => $expiresIn,
                    'fileName' => $fileName
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '生成下载链接失败：' . $e->getMessage(),
                'messageEn' => 'Failed to generate download URL: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取商品标题（根据语言）
     */
    private function getProductTitle($product, string $lang): string
    {
        if (!$product) {
            return 'product';
        }
        
        // 如果是英文环境，优先使用英文标题
        if ($lang === 'en' || $lang === 'en-US') {
            $titleEn = $product->getTitleEn();
            if (!empty($titleEn)) {
                return $titleEn;
            }
        }
        
        // 否则使用中文标题
        return $product->getTitle() ?: 'product';
    }

    /**
     * 清理文件名，移除非法字符
     */
    private function sanitizeFileName(string $fileName): string
    {
        // 移除文件系统不允许的字符
        $fileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $fileName);
        
        // 移除控制字符
        $fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName);
        
        // 限制文件名长度（保留200字符，为zip后缀留出空间）
        if (mb_strlen($fileName) > 200) {
            $fileName = mb_substr($fileName, 0, 200);
        }
        
        // 移除首尾空格和点
        $fileName = trim($fileName, " \t\n\r\0\x0B.");
        
        // 如果文件名为空，使用默认名称
        if (empty($fileName)) {
            $fileName = 'product_' . time();
        }
        
        return $fileName;
    }

    /**
     * 格式化下载记录数据
     * 
     * @param CustomerDownloadRecord $record 下载记录
     * @param int $serialNumber 序列号（用于前端显示）
     */
    private function formatDownloadRecord($record, int $serialNumber): array
    {
        return [
            'id' => $serialNumber, // 使用序列号代替数据库ID
            'recordId' => $record->getId(), // 保留数据库ID用于生成下载链接
            'productId' => $record->getProductId(),
            'productName' => $record->getProductName(),
            'downloadTime' => $record->getDownloadTime()->format('Y-m-d H:i:s'),
            'vipLevel' => $record->getVipLevel(),
            'hasFile' => !empty($record->getDownloadLink()),
            'downloadYear' => $record->getDownloadYear(),
            'downloadMonth' => $record->getDownloadMonth(),
            'statsMonthStr' => $record->getStatsMonthStr(),
        ];
    }

    /**
     * 获取下载统计信息
     */
    private function getDownloadStats(Customer $customer): array
    {
        $customerId = $customer->getId();
        $vipLevel = $customer->getVipLevel();
        
        // 获取当前年月
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        
        // 获取或创建当月统计记录
        $monthlyStats = $this->monthlyStatsRepository->findOrCreate(
            $customerId,
            $currentYear,
            $currentMonth
        );
        
        // 从SiteConfig读取该等级的下载额度
        $configKey = ($vipLevel === 0) ? 'NORMAL' : 'VIP' . $vipLevel;
        $config = $this->siteConfigRepository->findOneByKey($configKey);
        
        // 从configValue3字段获取月下载次数
        $downloadQuota = ($config && $config->getConfigValue3()) 
            ? (int)$config->getConfigValue3() 
            : 0;
        
        $downloadUsed = $monthlyStats->getDownloadUsed();
        $downloadRemaining = max(0, $downloadQuota - $downloadUsed);
        
        return [
            'vipLevel' => $vipLevel,
            'vipLevelName' => $customer->getVipLevelName(),
            'vipLevelNameEn' => $this->getVipLevelNameEn($vipLevel),
            'downloadQuota' => $downloadQuota,
            'downloadUsed' => $downloadUsed,
            'downloadRemaining' => $downloadRemaining,
            'year' => $currentYear,
            'month' => $currentMonth,
        ];
    }

    /**
     * 获取VIP等级英文名称
     */
    private function getVipLevelNameEn(int $vipLevel): string
    {
        $levelNames = [
            0 => 'Normal',
            1 => 'VIP1',
            2 => 'VIP2',
            3 => 'VIP3',
            4 => 'VIP4',
            5 => 'VIP5',
        ];
        
        return $levelNames[$vipLevel] ?? 'Normal';
    }

    /**
     * 下载商品（异步）
     * 
     * 请求参数（加密后传输）：
     * - productId: 商品ID
     * 
     * 返回数据：
     * - success: 是否成功
     * - message: 提示信息
     * 
     * 业务流程：
     * 1. 检查用户登录状态
     * 2. 验证商品是否存在
     * 3. 获取用户VIP等级和下载额度
     * 4. 检查本月下载次数是否超额
     * 5. 创建下载记录
     * 6. 更新月度统计（下载次数+1）
     * 7. 将下载任务加入消息队列（异步处理）
     * 8. 返回提示信息
     */
    #[Route('/download', name: 'download', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function downloadProduct(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }

        // 验证必填字段
        if (empty($data['productId'])) {
            return $this->json([
                'success' => false,
                'message' => '缺少商品ID参数',
                'messageEn' => 'Missing product ID parameter'
            ], 400);
        }

        $productId = (int)$data['productId'];
        $customerId = $customer->getId();
        $vipLevel = $customer->getVipLevel();

        // 查询商品信息
        $product = $this->productRepository->find($productId);
        if (!$product) {
            return $this->json([
                'success' => false,
                'message' => '商品不存在',
                'messageEn' => 'Product not found'
            ], 404);
        }

        // 从SiteConfig读取该等级的下载额度
        $configKey = ($vipLevel === 0) ? 'NORMAL' : 'VIP' . $vipLevel;
        $config = $this->siteConfigRepository->findOneByKey($configKey);
        
        if (!$config) {
            return $this->json([
                'success' => false,
                'message' => '系统配置错误',
                'messageEn' => 'System configuration error'
            ], 500);
        }
        
        $downloadQuota = (int)$config->getConfigValue3();

        // 获取本月已下载数量
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        
        // 检查是否已经下载过该商品（当月）
        // 注意：只检查已完成的记录
        $existingRecord = $this->downloadRecordRepository->createQueryBuilder('d')
            ->where('d.customerId = :customerId')
            ->andWhere('d.productId = :productId')
            ->andWhere('d.downloadYear = :year')
            ->andWhere('d.downloadMonth = :month')
            ->andWhere('d.status = :status')  // 只检查已完成的记录
            ->setParameter('customerId', $customerId)
            ->setParameter('productId', $productId)
            ->setParameter('year', $currentYear)
            ->setParameter('month', $currentMonth)
            ->setParameter('status', 'completed')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if ($existingRecord) {
            return $this->json([
                'success' => false,
                'message' => '您本月已下载过该商品，请到下载中心查看',
                'messageEn' => 'You have already downloaded this product this month, please check the download center'
            ], 400);
        }
        
        // 检查是否有正在处理中的记录
        $processingRecord = $this->downloadRecordRepository->createQueryBuilder('d')
            ->where('d.customerId = :customerId')
            ->andWhere('d.productId = :productId')
            ->andWhere('d.downloadYear = :year')
            ->andWhere('d.downloadMonth = :month')
            ->andWhere('d.status IN (:statuses)')  // pending 或 processing
            ->setParameter('customerId', $customerId)
            ->setParameter('productId', $productId)
            ->setParameter('year', $currentYear)
            ->setParameter('month', $currentMonth)
            ->setParameter('statuses', ['pending', 'processing'])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        // 如果有正在处理中的记录，提示用户稍后再试
        if ($processingRecord) {
            return $this->json([
                'success' => false,
                'message' => '该商品正在处理中，请稍后到下载中心查看',
                'messageEn' => 'This product is being processed, please check the download center later'
            ], 400);
        }
        
        // 检查是否有失败的记录，如果有则先删除（允许重试）
        // 包括：1. 状态为failed  2. 超过一天仍然是pending/processing状态
        $oneDayAgo = new \DateTime('-1 day');
        $failedRecords = $this->downloadRecordRepository->createQueryBuilder('d')
            ->where('d.customerId = :customerId')
            ->andWhere('d.productId = :productId')
            ->andWhere('d.downloadYear = :year')
            ->andWhere('d.downloadMonth = :month')
            ->andWhere(
                'd.status = :failedStatus OR (d.status IN (:processingStatuses) AND d.downloadTime < :oneDayAgo)'
            )
            ->setParameter('customerId', $customerId)
            ->setParameter('productId', $productId)
            ->setParameter('year', $currentYear)
            ->setParameter('month', $currentMonth)
            ->setParameter('failedStatus', 'failed')
            ->setParameter('processingStatuses', ['pending', 'processing'])
            ->setParameter('oneDayAgo', $oneDayAgo)
            ->getQuery()
            ->getResult();
        
        // 获取或创建当月统计记录（必须在删除失败记录之前获取）
        $monthlyStats = $this->monthlyStatsRepository->findOrCreate(
            $customerId,
            $currentYear,
            $currentMonth
        );
        
        // 删除失败的记录
        foreach ($failedRecords as $failedRecord) {
            $this->entityManager->remove($failedRecord);
        }
        
        // 如果有失败记录，需要减去之前的统计次数
        if (count($failedRecords) > 0) {
            $monthlyStats->setDownloadUsed(max(0, $monthlyStats->getDownloadUsed() - count($failedRecords)));
        }
        
        $downloadUsed = $monthlyStats->getDownloadUsed();

        // 判断是否超额
        if ($downloadUsed >= $downloadQuota) {
            return $this->json([
                'success' => false,
                'message' => "本月下载额度已用完（{$downloadUsed}/{$downloadQuota}）",
                'messageEn' => "Monthly download quota exceeded ({$downloadUsed}/{$downloadQuota})"
            ], 400);
        }

        try {
            // 创建下载记录（初始状态，文件尚未生成）
            $downloadRecord = new CustomerDownloadRecord();
            $downloadRecord->setCustomerId($customerId);
            $downloadRecord->setProductId($productId);
            $downloadRecord->setProductName($product->getTitle());
            $downloadRecord->setDownloadLink(null); // 文件尚未生成
            
            $now = new \DateTime();
            $downloadRecord->setDownloadTime($now);
            $downloadRecord->setDownloadYear((int)$now->format('Y'));
            $downloadRecord->setDownloadMonth((int)$now->format('n'));
            $downloadRecord->setStatsMonthStr($now->format('Y-m'));
            $downloadRecord->setVipLevel($vipLevel);
            $downloadRecord->setStatus('pending'); // 设置初始状态为等待处理
            
            $this->entityManager->persist($downloadRecord);

            // 更新月度统计（下载次数 +1）
            $monthlyStats->setDownloadUsed($downloadUsed + 1);
            
            // 保存所有更改
            $this->entityManager->flush();

            // 将下载任务加入消息队列（异步处理）
            $downloadJob = new DownloadProductJob($customerId, $productId, $vipLevel);
            $this->messageBus->dispatch($downloadJob);

            return $this->json([
                'success' => true,
                'message' => '下载准备中，请稍后到个人中心下载中心查看',
                'messageEn' => 'Download in progress, please check download center later',
                'data' => [
                    'downloadUsed' => $downloadUsed + 1,
                    'downloadQuota' => $downloadQuota,
                    'downloadRemaining' => max(0, $downloadQuota - $downloadUsed - 1)
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '下载任务创建失败：' . $e->getMessage(),
                'messageEn' => 'Failed to create download task: ' . $e->getMessage()
            ], 500);
        }
    }
}
