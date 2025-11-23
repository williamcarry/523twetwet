<?php

namespace App\MessageHandler;

use App\Entity\CustomerDownloadRecord;
use App\Message\DownloadProductJob;
use App\Repository\ProductRepository;
use App\Repository\ProductPriceRepository;
use App\Repository\ProductShippingRepository;
use App\Repository\ProductDiscountRuleRepository;
use App\Repository\CustomerDownloadRecordRepository;
use App\Service\QiniuUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * 商品下载任务处理器
 * 
 * 处理流程：
 * 1. 查询商品全量信息（Product + ProductPrice + ProductShipping + ProductDiscountRule）
 * 2. 生成Excel文件（多个工作表）
 * 3. 下载商品图片到本地目录
 * 4. 打包成ZIP文件
 * 5. 上传到七牛云
 * 6. 更新下载记录
 * 7. 清理临时文件
 */
#[AsMessageHandler]
class DownloadProductHandler
{
    public function __construct(
        private ProductRepository $productRepository,
        private ProductPriceRepository $priceRepository,
        private ProductShippingRepository $shippingRepository,
        private ProductDiscountRuleRepository $discountRuleRepository,
        private CustomerDownloadRecordRepository $downloadRecordRepository,
        private QiniuUploadService $qiniuService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DownloadProductJob $job): void
    {
        $customerId = $job->getCustomerId();
        $productId = $job->getProductId();
        $vipLevel = $job->getVipLevel();

        $this->logger->info("开始处理商品下载任务", [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'vip_level' => $vipLevel
        ]);

        $tempDir = null;
        $zipFile = null;

        try {
            // 0. 更新记录状态为“处理中”
            $this->updateDownloadStatus($customerId, $productId, 'processing');
            
            // 1. 查询商品全量信息
            $product = $this->productRepository->find($productId);
            if (!$product) {
                throw new \Exception("商品不存在: {$productId}");
            }

            // 2. 获取商品相关数据
            $prices = $this->priceRepository->findBy(['product' => $product]);
            $shippings = $this->shippingRepository->findBy(['product' => $product]);
            $discountRules = $this->discountRuleRepository->findBy(['product' => $product]);

            // 3. 创建临时目录（在项目public目录下）
            $publicDir = dirname(__DIR__, 2) . '/public';
            $tempDir = $publicDir . '/temp/product_download_' . uniqid();
            if (!mkdir($tempDir, 0777, true)) {
                throw new \Exception("无法创建临时目录: {$tempDir}");
            }

            // 4. 生成Excel文件
            $excelFile = $this->generateProductExcel($product, $prices, $shippings, $discountRules, $tempDir);

            // 5. 下载商品图片到目录
            $this->downloadProductImages($product, $tempDir);

            // 6. 打包目录为ZIP
            $zipFile = $this->createZipArchive($tempDir, $productId);

            // 7. 上传到七牛云
            $qiniuKey = "downloads/{$customerId}/{$productId}/" . time() . '.zip';
            $this->qiniuService->uploadFile($zipFile, $qiniuKey);

            // 8. 更新下载记录（设置为已完成）
            $this->updateDownloadRecord($customerId, $productId, $qiniuKey, 'completed');

            $this->logger->info("商品下载任务完成", [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'qiniu_key' => $qiniuKey
            ]);

        } catch (\Exception $e) {
            $this->logger->error("商品下载任务失败", [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // 更新记录状态为“失败”
            $this->updateDownloadStatus($customerId, $productId, 'failed');

            throw $e;
        } finally {
            // 9. 无论成功还是失败，都要清理临时文件
            $this->cleanupTempFiles($tempDir, $zipFile);
        }
    }

    /**
     * 生成商品信息Excel文件
     * 包含4个工作表：
     * 1. 商品基本信息
     * 2. 价格信息（按区域）
     * 3. 物流信息（按区域）
     * 4. 满减规则（按区域）
     */
    private function generateProductExcel($product, array $prices, array $shippings, array $discountRules, string $tempDir): string
    {
        $spreadsheet = new Spreadsheet();
        
        // 工作表1：商品基本信息
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('商品基本信息');
        $this->generateBasicInfoSheet($sheet1, $product);
        
        // 工作表2：价格信息
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('价格信息');
        $this->generatePriceSheet($sheet2, $prices);
        
        // 工作表3：物流信息
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('物流信息');
        $this->generateShippingSheet($sheet3, $shippings);
        
        // 工作表4：满减规则
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('满减规则');
        $this->generateDiscountSheet($sheet4, $discountRules);
        
        // 保存Excel文件
        $excelFile = $tempDir . '/product_info.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($excelFile);
        
        return $excelFile;
    }

    /**
     * 生成商品基本信息工作表
     */
    private function generateBasicInfoSheet($sheet, $product): void
    {
        // 设置标题行样式
        $sheet->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        
        // 表头
        $sheet->setCellValue('A1', '字段名称');
        $sheet->setCellValue('B1', '字段值');
        
        // 数据行
        $row = 2;
        
        // 获取供应商信息（避免Doctrine代理类问题）
        $supplier = $product->getSupplier();
        $supplierName = '';
        if ($supplier) {
            // 强制初始化代理对象
            $this->entityManager->refresh($supplier);
            $supplierName = method_exists($supplier, 'getCompanyName') ? $supplier->getCompanyName() : '';
        }
        
        $data = [
            ['SKU编码', $product->getSku()],
            ['SPU编码', $product->getSpu()],
            ['商品标题（中文）', $product->getTitle()],
            ['商品标题（英文）', $product->getTitleEn()],
            ['供应商', $supplierName],
            ['品牌', $product->getBrand()],
            ['商品状态', $product->getStatus()],
            ['一级分类', $product->getCategory()?->getTitle()],
            ['二级分类', $product->getSubcategory()?->getTitle()],
            ['三级分类', $product->getItem()?->getTitle()],
            ['长度（cm）', $product->getLength()],
            ['宽度（cm）', $product->getWidth()],
            ['高度（cm）', $product->getHeight()],
            ['重量（g）', $product->getWeight()],
            ['包装长度（cm）', $product->getPackageLength()],
            ['包装宽度（cm）', $product->getPackageWidth()],
            ['包装高度（cm）', $product->getPackageHeight()],
            ['包装重量（g）', $product->getPackageWeight()],
            ['支持一件代发', $product->isSupportDropship() ? '是' : '否'],
            ['支持批发', $product->isSupportWholesale() ? '是' : '否'],
            ['支持圈货', $product->isSupportCircleBuy() ? '是' : '否'],
            ['支持自提', $product->isSupportSelfPickup() ? '是' : '否'],
            ['支持借远地址', $product->isSupportBorrowingAddress() ? '是' : '否'],
            ['库存警戒线', $product->getAlertStockLine()],
            ['销售数量', $product->getSalesCount()],
            ['下载次数', $product->getDownloadCount()],
            ['浏览次数', $product->getViewCount()],
            ['收藏计数', $product->getFavoriteCount()],
            ['标签', implode(', ', $product->getTags() ?? [])],
            ['特别关注', $product->isFeatured() ? '是' : '否'],
            ['新品', $product->isNew() ? '是' : '否'],
            ['热卖', $product->isHot() ? '是' : '否'],
            ['促销', $product->isPromotion() ? '是' : '否'],
            ['限量', $product->isLimited() ? '是' : '否'],
            ['发货区域', implode(', ', $product->getShippingRegions() ?? [])],
            ['首次上架时间', $product->getPublishDate()?->format('Y-m-d H:i:s')],
            ['创建时间', $product->getCreatedAt()?->format('Y-m-d H:i:s')],
            ['更新时间', $product->getUpdatedAt()?->format('Y-m-d H:i:s')],
        ];
        
        foreach ($data as $rowData) {
            $sheet->setCellValue('A' . $row, $rowData[0]);
            $sheet->setCellValue('B' . $row, $rowData[1]);
            $row++;
        }
        
        // 自动调整列宽
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(50);
    }

    /**
     * 生成价格信息工作表
     */
    private function generatePriceSheet($sheet, array $prices): void
    {
        // 表头
        $headers = ['区域', '业务类型', '原价', '售价', '折扣率', '是否促销', 
                   '促销开始时间', '促销结束时间', '最小批发起订量', '货币单位'];
        
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            $col++;
        }
        
        // 数据行
        $row = 2;
        foreach ($prices as $price) {
            $sheet->setCellValue('A' . $row, $price->getRegion());
            $sheet->setCellValue('B' . $row, $price->getBusinessType());
            $sheet->setCellValue('C' . $row, $price->getOriginalPrice());
            $sheet->setCellValue('D' . $row, $price->getSellingPrice());
            $sheet->setCellValue('E' . $row, $price->getDiscountRate());
            $sheet->setCellValue('F' . $row, $price->isPromotion() ? '是' : '否');
            $sheet->setCellValue('G' . $row, $price->getPromotionStartAt()?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('H' . $row, $price->getPromotionEndAt()?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('I' . $row, $price->getMinWholesaleQuantity());
            $sheet->setCellValue('J' . $row, $price->getCurrency());
            $row++;
        }
        
        // 自动调整列宽
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * 生成物流信息工作表
     */
    private function generateShippingSheet($sheet, array $shippings): void
    {
        // 表头
        $headers = ['区域', '物流方式', '首件运费', '续件运费', '折后运费', 
                   '参考时效', '发货地址', '退货地址', '仓库代码', '仓库名称', '可售库存', '货币单位'];
        
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            $col++;
        }
        
        // 数据行
        $row = 2;
        foreach ($shippings as $shipping) {
            $sheet->setCellValue('A' . $row, $shipping->getRegion());
            $sheet->setCellValue('B' . $row, $shipping->getShippingMethod());
            $sheet->setCellValue('C' . $row, $shipping->getShippingPrice());
            $sheet->setCellValue('D' . $row, $shipping->getAdditionalPrice());
            $sheet->setCellValue('E' . $row, $shipping->getDiscountedPrice());
            $sheet->setCellValue('F' . $row, $shipping->getDeliveryTime());
            $sheet->setCellValue('G' . $row, $shipping->getShippingAddress());
            $sheet->setCellValue('H' . $row, $shipping->getReturnAddress());
            $sheet->setCellValue('I' . $row, $shipping->getWarehouseCode());
            $sheet->setCellValue('J' . $row, $shipping->getWarehouseName());
            $sheet->setCellValue('K' . $row, $shipping->getAvailableStock());
            $sheet->setCellValue('L' . $row, $shipping->getCurrency());
            $row++;
        }
        
        // 自动调整列宽
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * 生成满减规则工作表
     */
    private function generateDiscountSheet($sheet, array $discountRules): void
    {
        // 表头
        $headers = ['区域', '满减触发金额', '减免金额', '是否启用', '活动开始时间', '活动结束时间', '货币单位'];
        
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            $col++;
        }
        
        // 数据行
        $row = 2;
        foreach ($discountRules as $rule) {
            $sheet->setCellValue('A' . $row, $rule->getRegion());
            $sheet->setCellValue('B' . $row, $rule->getMinAmount());
            $sheet->setCellValue('C' . $row, $rule->getDiscountAmount());
            $sheet->setCellValue('D' . $row, $rule->isActive() ? '是' : '否');
            $sheet->setCellValue('E' . $row, $rule->getStartAt()?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('F' . $row, $rule->getEndAt()?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('G' . $row, $rule->getCurrency());
            $row++;
        }
        
        // 自动调整列宽
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * 下载商品图片到指定目录
     */
    private function downloadProductImages($product, string $tempDir): void
    {
        $imageDir = $tempDir . '/images';
        if (!mkdir($imageDir, 0777, true)) {
            throw new \Exception("无法创建图片目录: {$imageDir}");
        }

        // 下载缩略图
        if ($product->getThumbnailImage()) {
            $this->downloadImageFromQiniu($product->getThumbnailImage(), $imageDir . '/thumbnail.jpg');
        }

        // 下载主图
        if ($product->getMainImage()) {
            $this->downloadImageFromQiniu($product->getMainImage(), $imageDir . '/main.jpg');
        }

        // 下载详情图
        $detailImages = $product->getDetailImages();
        if (is_array($detailImages)) {
            foreach ($detailImages as $index => $imageInfo) {
                if (isset($imageInfo['key'])) {
                    $canBeMain = isset($imageInfo['canBeMain']) && $imageInfo['canBeMain'] ? '_main' : '';
                    $filename = "detail_{$index}{$canBeMain}.jpg";
                    $this->downloadImageFromQiniu($imageInfo['key'], $imageDir . '/' . $filename);
                }
            }
        }
    }

    /**
     * 从七牛云下载图片到本地
     */
    private function downloadImageFromQiniu(string $qiniuKey, string $localPath): void
    {
        $maxRetries = 3;
        $retryDelay = 1; // 秒
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // 生成临时访问URL（有效期1小时）
                $downloadUrl = $this->qiniuService->getPrivateUrl($qiniuKey, 3600);
                
                // 使用stream context设置超时
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 30, // 30秒超时
                        'ignore_errors' => false
                    ]
                ]);
                
                // 下载图片到本地
                $imageContent = @file_get_contents($downloadUrl, false, $context);
                if ($imageContent === false) {
                    throw new \Exception("无法下载图片: {$qiniuKey}");
                }
                
                if (file_put_contents($localPath, $imageContent) === false) {
                    throw new \Exception("无法保存图片到: {$localPath}");
                }
                
                // 下载成功，退出重试循环
                return;
                
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    // 最后一次重试仍然失败，记录警告但不中断流程
                    $this->logger->warning("图片下载失败（已重试{$maxRetries}次），已跳过", [
                        'qiniu_key' => $qiniuKey,
                        'error' => $e->getMessage()
                    ]);
                } else {
                    // 等待后重试
                    $this->logger->info("图片下载失败，{$retryDelay}秒后重试（{$attempt}/{$maxRetries}）", [
                        'qiniu_key' => $qiniuKey
                    ]);
                    sleep($retryDelay);
                }
            }
        }
    }

    /**
     * 创建ZIP压缩包
     */
    private function createZipArchive(string $sourceDir, int $productId): string
    {
        $publicDir = dirname(__DIR__, 2) . '/public';
        $zipFile = $publicDir . '/temp/product_' . $productId . '_' . time() . '.zip';
        
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("无法创建ZIP文件: {$zipFile}");
        }
        
        // 递归添加目录中的所有文件
        $this->addDirectoryToZip($zip, $sourceDir, '');
        
        $zip->close();
        
        return $zipFile;
    }

    /**
     * 递归添加目录到ZIP
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $sourceDir, string $zipPath): void
    {
        $files = scandir($sourceDir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $filePath = $sourceDir . '/' . $file;
            $zipFilePath = empty($zipPath) ? $file : $zipPath . '/' . $file;
            
            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipFilePath);
                $this->addDirectoryToZip($zip, $filePath, $zipFilePath);
            } else {
                $zip->addFile($filePath, $zipFilePath);
            }
        }
    }

    /**
     * 更新下载记录状态
     */
    private function updateDownloadStatus(int $customerId, int $productId, string $status): void
    {
        // 获取当前年月
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        
        // 查找当月最近的下载记录（按时间倒序）
        $downloadRecord = $this->downloadRecordRepository->createQueryBuilder('d')
            ->where('d.customerId = :customerId')
            ->andWhere('d.productId = :productId')
            ->andWhere('d.downloadYear = :year')
            ->andWhere('d.downloadMonth = :month')
            ->setParameter('customerId', $customerId)
            ->setParameter('productId', $productId)
            ->setParameter('year', $currentYear)
            ->setParameter('month', $currentMonth)
            ->orderBy('d.downloadTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($downloadRecord) {
            $downloadRecord->setStatus($status);
            $this->entityManager->flush();
        }
    }

    /**
     * 更新下载记录（设置下载链接和状态）
     */
    private function updateDownloadRecord(int $customerId, int $productId, string $qiniuKey, string $status): void
    {
        // 获取当前年月
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        
        // 查找当月最近的下载记录（按时间倒序）
        $downloadRecord = $this->downloadRecordRepository->createQueryBuilder('d')
            ->where('d.customerId = :customerId')
            ->andWhere('d.productId = :productId')
            ->andWhere('d.downloadYear = :year')
            ->andWhere('d.downloadMonth = :month')
            ->setParameter('customerId', $customerId)
            ->setParameter('productId', $productId)
            ->setParameter('year', $currentYear)
            ->setParameter('month', $currentMonth)
            ->orderBy('d.downloadTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($downloadRecord) {
            $downloadRecord->setDownloadLink($qiniuKey);
            $downloadRecord->setStatus($status);
            $this->entityManager->flush();
        }
    }

    /**
     * 清理临时文件
     */
    private function cleanupTempFiles(?string $tempDir, ?string $zipFile): void
    {
        // 删除临时目录
        if ($tempDir && is_dir($tempDir)) {
            try {
                $this->deleteDirectory($tempDir);
            } catch (\Exception $e) {
                // 记录警告但不中断执行
                $this->logger->warning("删除临时目录失败", [
                    'temp_dir' => $tempDir,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 删除ZIP文件
        if ($zipFile && file_exists($zipFile)) {
            try {
                unlink($zipFile);
            } catch (\Exception $e) {
                // 记录警告但不中断执行
                $this->logger->warning("删除ZIP文件失败", [
                    'zip_file' => $zipFile,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 删除目录及其内容
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
