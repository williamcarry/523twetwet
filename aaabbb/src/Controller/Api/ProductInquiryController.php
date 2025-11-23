<?php

namespace App\Controller\Api;

use App\Entity\ProductInquiry;
use App\Repository\ProductInquiryRepository;
use App\Repository\ProductRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\QiniuUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 商品询价API控制器
 * 
 * 提供工厂直采询价相关的API接口
 */
#[Route('/shop/api/inquiry', name: 'api_inquiry_')]
class ProductInquiryController extends AbstractController
{
    /**
     * 多语言消息
     */
    private const MESSAGES = [
        'no_file' => [
            'zh' => '未上传文件',
            'en' => 'No file uploaded'
        ],
        'invalid_type' => [
            'zh' => '不支持的文件类型',
            'en' => 'Unsupported file type'
        ],
        'file_too_large' => [
            'zh' => '文件大小不能超过10MB',
            'en' => 'File size cannot exceed 10MB'
        ],
        'too_many_files' => [
            'zh' => '最多只能上传10个附件',
            'en' => 'Maximum 10 attachments allowed'
        ],
        'upload_success' => [
            'zh' => '文件上传成功',
            'en' => 'File uploaded successfully'
        ],
        'upload_failed' => [
            'zh' => '文件上传失败',
            'en' => 'File upload failed'
        ],
        'delete_success' => [
            'zh' => '文件删除成功',
            'en' => 'File deleted successfully'
        ],
        'delete_failed' => [
            'zh' => '文件删除失败',
            'en' => 'File deletion failed'
        ],
        'key_empty' => [
            'zh' => '文件key不能为空',
            'en' => 'File key cannot be empty'
        ],
        'submit_success' => [
            'zh' => '询价单提交成功，我们会尽快与您联系',
            'en' => 'Inquiry submitted successfully, we will contact you soon'
        ],
        'submit_failed' => [
            'zh' => '询价单提交失败',
            'en' => 'Inquiry submission failed'
        ],
        'product_not_found' => [
            'zh' => '商品不存在',
            'en' => 'Product not found'
        ],
        'inquiry_not_found' => [
            'zh' => '询价单不存在',
            'en' => 'Inquiry not found'
        ],
        'permission_denied' => [
            'zh' => '无权查看此询价单',
            'en' => 'Permission denied'
        ],
        'invalid_data' => [
            'zh' => '提交数据不完整或格式错误',
            'en' => 'Invalid or incomplete data'
        ],
        'invalid_phone' => [
            'zh' => '请输入正确的手机号',
            'en' => 'Please enter a valid phone number'
        ],
        'invalid_quantity' => [
            'zh' => '询价数量必须大于0',
            'en' => 'Inquiry quantity must be greater than 0'
        ],
        'requirement_required' => [
            'zh' => '请填写需求描述',
            'en' => 'Please fill in requirement description'
        ]
    ];

    /**
     * 获取多语言消息
     */
    private function getMessage(string $key, ?string $error = null): array
    {
        $messages = self::MESSAGES[$key] ?? [
            'zh' => $key,
            'en' => $key
        ];
        
        if ($error) {
            $messages['zh'] .= ': ' . $error;
            $messages['en'] .= ': ' . $error;
        }
        
        return [
            'message' => $messages['zh'],
            'messageEn' => $messages['en']
        ];
    }

    /**
     * 上传询价附件
     * 
     * 接收前端上传的附件文件，上传到七牛云存储，返回附件key和预览URL
     * 
     * 请求方式：POST
     * 请求参数：
     *   - file: 附件文件（multipart/form-data）
     *   - timestamp: 时间戳（签名参数）
     *   - nonce: 随机数（签名参数）
     *   - signature: 签名（签名参数）
     * 
     * 支持的文件类型：
     *   - 图片: JPG, PNG, GIF, WEBP
     *   - 文档: PDF, DOC, DOCX, XLS, XLSX
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - key: 附件在七牛云的存储key
     *   - name: 原始文件名
     *   - type: 文件MIME类型
     *   - size: 文件大小（字节）
     *   - previewUrl: 带签名的预览URL
     *   - message: 提示信息（中文）
     *   - messageEn: 提示信息（英文）
     */
    #[Route('/upload-attachment', name: 'upload_attachment', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function uploadAttachment(Request $request, QiniuUploadService $qiniuService): JsonResponse
    {
        try {
            // 获取上传的文件
            $uploadedFile = $request->files->get('file');
            
            if (!$uploadedFile) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('no_file')),
                    400
                );
            }
            
            // 验证文件类型（MIME类型和扩展名都检查）
            $allowedMimeTypes = [
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/gif',
                'image/webp',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/x-rar-compressed',
                'application/vnd.rar',
                'application/x-rar',
                'application/zip',
                'application/x-zip-compressed',
                'application/x-7z-compressed',
                'application/octet-stream'  // 通用二进制流，某些压缩文件可能使用这个
            ];
            
            $allowedExtensions = [
                'jpg', 'jpeg', 'png', 'gif', 'webp',
                'pdf',
                'doc', 'docx',
                'xls', 'xlsx',
                'rar', 'zip', '7z'
            ];
            
            // 获取文件扩展名
            $extension = strtolower($uploadedFile->getClientOriginalExtension());
            
            // 检查MIME类型或扩展名
            $hasValidMimeType = in_array($uploadedFile->getMimeType(), $allowedMimeTypes);
            $hasValidExtension = in_array($extension, $allowedExtensions);
            
            if (!$hasValidMimeType && !$hasValidExtension) {
                return new JsonResponse(
                    array_merge(
                        ['success' => false],
                        $this->getMessage('invalid_type')
                    ),
                    400
                );
            }
            
            // 验证文件大小（最大10MB）
            if ($uploadedFile->getSize() > 10 * 1024 * 1024) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('file_too_large')),
                    400
                );
            }
            
            // 生成文件名：inquiry_{timestamp}_{uniqid}.{ext}
            $extension = $uploadedFile->guessExtension() ?: $uploadedFile->getClientOriginalExtension();
            $fileName = 'inquiry_' . time() . '_' . uniqid() . '.' . $extension;
            
            // 上传到七牛云
            $uploadResult = $qiniuService->uploadFile($uploadedFile->getPathname(), $fileName);
            
            if ($uploadResult['success']) {
                return new JsonResponse(
                    array_merge(
                        [
                            'success' => true,
                            'key' => $uploadResult['key'],
                            'name' => $uploadedFile->getClientOriginalName(),
                            'type' => $uploadedFile->getMimeType(),
                            'size' => $uploadedFile->getSize(),
                            'previewUrl' => $uploadResult['url'],
                        ],
                        $this->getMessage('upload_success')
                    )
                );
            } else {
                return new JsonResponse(
                    array_merge(
                        ['success' => false],
                        $this->getMessage('upload_failed', $uploadResult['error'] ?? null)
                    ),
                    500
                );
            }
        } catch (\Exception $e) {
            return new JsonResponse(
                array_merge(
                    ['success' => false],
                    $this->getMessage('upload_failed', $e->getMessage())
                ),
                500
            );
        }
    }

    /**
     * 删除询价附件
     * 
     * 从七牛云删除指定的附件文件
     * 
     * 请求方式：POST
     * 请求参数（JSON）：
     *   - key: 七牛云文件key（必填）
     *   - timestamp: 时间戳（签名参数）
     *   - nonce: 随机数（签名参数）
     *   - signature: 签名（签名参数）
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - message: 提示信息（中文）
     *   - messageEn: 提示信息（英文）
     */
    #[Route('/delete-attachment', name: 'delete_attachment', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function deleteAttachment(Request $request, QiniuUploadService $qiniuService): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $key = $data['key'] ?? '';
            
            if (empty($key)) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('key_empty')),
                    400
                );
            }
            
            // 如果是完整URL，提取key
            if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
                $parts = parse_url($key);
                $key = ltrim($parts['path'] ?? '', '/');
                $key = preg_replace('/\?.*$/', '', $key);
            }
            
            // 删除文件
            $deleteResult = $qiniuService->deleteFile($key);
            
            if ($deleteResult) {
                return new JsonResponse(
                    array_merge(['success' => true], $this->getMessage('delete_success'))
                );
            } else {
                return new JsonResponse(
                    array_merge(
                        ['success' => false],
                        $this->getMessage('delete_failed')
                    ),
                    500
                );
            }
            
        } catch (\Exception $e) {
            return new JsonResponse(
                array_merge(
                    ['success' => false],
                    $this->getMessage('delete_failed', $e->getMessage())
                ),
                500
            );
        }
    }

    /**
     * 提交询价单
     * 
     * 接收前端提交的询价表单，创建询价记录
     * 
     * 请求方式：POST
     * 请求参数（JSON）：
     *   - productId: 商品ID（必填）
     *   - contactName: 联系人姓名（必填）
     *   - contactPhone: 联系电话（必填）
     *   - inquiryQuantity: 询价数量（必填）
     *   - requirementDescription: 需求描述（选填）
     *   - attachments: 附件数组（选填，最多10个）
     *       [{key: 'xxx', name: 'xxx', type: 'xxx'}]
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - inquiryNo: 询价单号
     *   - message: 提示信息（中文）
     *   - messageEn: 提示信息（英文）
     */
    #[Route('/submit', name: 'submit', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function submitInquiry(
        Request $request,
        EntityManagerInterface $em,
        ProductRepository $productRepo,
        ProductInquiryRepository $inquiryRepo,
        QiniuUploadService $qiniuService
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            
            // 验证必填字段
            if (
                !isset($data['productId']) ||
                !isset($data['contactName']) ||
                !isset($data['contactPhone']) ||
                !isset($data['inquiryQuantity']) ||
                !isset($data['requirementDescription']) ||
                empty(trim($data['requirementDescription']))
            ) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('invalid_data')),
                    400
                );
            }
            
            // 获取当前登录客户
            $customer = $this->getUser();
            
            // 验证商品是否存在
            $product = $productRepo->find($data['productId']);
            if (!$product) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('product_not_found')),
                    404
                );
            }
            
            // 验证手机号格式（简单验证）
            $phone = trim($data['contactPhone']);
            if (!preg_match('/^1[3-9]\d{9}$/', $phone) && !preg_match('/^\+?[\d\s-]{8,20}$/', $phone)) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('invalid_phone')),
                    400
                );
            }
            
            // 验证数量
            $quantity = (int)$data['inquiryQuantity'];
            if ($quantity <= 0) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('invalid_quantity')),
                    400
                );
            }
            
            // 验证附件数量
            $attachments = $data['attachments'] ?? [];
            if (count($attachments) > 10) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('too_many_files')),
                    400
                );
            }
            
            // 创建询价单
            $inquiry = new ProductInquiry();
            
            // 生成询价单号
            $inquiryNo = $inquiryRepo->generateInquiryNo();
            $inquiry->setInquiryNo($inquiryNo);
            
            // 设置关联关系
            $inquiry->setProduct($product);
            $inquiry->setCustomer($customer);
            
            // 设置商品快照
            $inquiry->setProductSku($product->getSku());
            $inquiry->setProductTitle($product->getTitle());
            $inquiry->setProductTitleEn($product->getTitleEn());
            $inquiry->setProductMainImage($product->getMainImage());
            
            // 设置表单数据
            $inquiry->setContactName(trim($data['contactName']));
            $inquiry->setContactPhone($phone);
            $inquiry->setInquiryQuantity($quantity);
            $inquiry->setRequirementDescription($data['requirementDescription'] ?? '');
            
            // 处理附件 - 生成带商品信息的下载文件名
            if (!empty($attachments)) {
                $attachmentUrls = [];
                $attachmentNames = [];  // 原始文件名
                $attachmentTypes = [];
                $attachmentDownloadNames = [];  // 下载时使用的文件名
                
                // 生成下载文件名前缀：商品ID_商品中文名
                $productId = $product->getId();
                $productTitle = $product->getTitle();
                // 清理文件名中的非法字符
                $safeProductTitle = preg_replace('#[\\\\/:*?"<>|]#', '_', $productTitle);
                $downloadPrefix = $productId . '_' . $safeProductTitle;
                
                foreach ($attachments as $attachment) {
                    $key = $attachment['key'] ?? '';
                    $originalName = $attachment['name'] ?? '';
                    $type = $attachment['type'] ?? '';
                    
                    $attachmentUrls[] = $key;
                    $attachmentNames[] = $originalName;
                    $attachmentTypes[] = $type;
                    
                    // 生成下载文件名：商品ID_商品中文名_原始文件名
                    $downloadName = $downloadPrefix . '_' . $originalName;
                    $attachmentDownloadNames[] = $downloadName;
                }
                
                $inquiry->setAttachmentUrl(json_encode($attachmentUrls, JSON_UNESCAPED_UNICODE));
                $inquiry->setAttachmentName(json_encode($attachmentDownloadNames, JSON_UNESCAPED_UNICODE));
                $inquiry->setAttachmentType(json_encode($attachmentTypes, JSON_UNESCAPED_UNICODE));
            }
            
            // 设置状态和操作记录
            $inquiry->setStatus('PENDING');
            $inquiry->setCreatedBy($customer->getId());
            
            // 保存到数据库
            $em->persist($inquiry);
            $em->flush();
            
            return new JsonResponse(
                array_merge(
                    [
                        'success' => true,
                        'inquiryNo' => $inquiryNo
                    ],
                    $this->getMessage('submit_success')
                )
            );
            
        } catch (\Exception $e) {
            return new JsonResponse(
                array_merge(
                    ['success' => false],
                    $this->getMessage('submit_failed', $e->getMessage())
                ),
                500
            );
        }
    }

    /**
     * 获取询价单列表
     * 
     * 获取当前客户的询价单列表（分页）
     * 
     * 请求方式：GET
     * 请求参数：
     *   - page: 页码（默认1）
     *   - pageSize: 每页数量（默认20）
     *   - status: 状态筛选（可选）
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - data: 询价单列表
     *   - total: 总数
     *   - page: 当前页码
     *   - pageSize: 每页数量
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function list(
        Request $request,
        ProductInquiryRepository $inquiryRepo,
        QiniuUploadService $qiniuService
    ): JsonResponse {
        try {
            // 获取当前登录客户
            $customer = $this->getUser();
            
            // 获取分页参数
            $page = max(1, (int)$request->query->get('page', 1));
            $pageSize = max(1, min(100, (int)$request->query->get('pageSize', 20)));
            
            // 查询询价单列表
            $result = $inquiryRepo->findByCustomerPaginated($customer, $page, $pageSize);
            
            // 格式化数据
            $data = [];
            foreach ($result['data'] as $inquiry) {
                $item = [
                    'inquiryNo' => $inquiry->getInquiryNo(),
                    'productId' => $inquiry->getProduct()->getId(),
                    'productSku' => $inquiry->getProductSku(),
                    'productTitle' => $inquiry->getProductTitle(),
                    'productTitleEn' => $inquiry->getProductTitleEn(),
                    'productMainImage' => $inquiry->getProductMainImage() ? 
                        $qiniuService->getPrivateUrl($inquiry->getProductMainImage()) : '',
                    'contactName' => $inquiry->getContactName(),
                    'contactPhone' => $inquiry->getContactPhone(),
                    'inquiryQuantity' => $inquiry->getInquiryQuantity(),
                    'requirementDescription' => $inquiry->getRequirementDescription(),
                    'status' => $inquiry->getStatus(),
                    'createdAt' => $inquiry->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
                
                // 处理附件
                $attachmentUrls = json_decode($inquiry->getAttachmentUrl() ?? '[]', true);
                $attachmentNames = json_decode($inquiry->getAttachmentName() ?? '[]', true);
                $attachmentTypes = json_decode($inquiry->getAttachmentType() ?? '[]', true);
                
                $attachments = [];
                for ($i = 0; $i < count($attachmentUrls); $i++) {
                    $attachments[] = [
                        'key' => $attachmentUrls[$i] ?? '',
                        'name' => $attachmentNames[$i] ?? '',
                        'type' => $attachmentTypes[$i] ?? '',
                        'url' => $attachmentUrls[$i] ? $qiniuService->getPrivateUrl($attachmentUrls[$i]) : ''
                    ];
                }
                $item['attachments'] = $attachments;
                
                // 供应商报价信息（只要有任何报价字段不为空，就返回）
                if ($inquiry->getQuotedPrice() || $inquiry->getQuotedTotal() || $inquiry->getQuotedRemark()) {
                    $item['quotedPrice'] = $inquiry->getQuotedPrice();
                    $item['quotedCurrency'] = $inquiry->getQuotedCurrency();
                    $item['quotedTotal'] = $inquiry->getQuotedTotal();
                    $item['quotedRemark'] = $inquiry->getQuotedRemark();
                    $item['quotedAt'] = $inquiry->getQuotedAt() ? 
                        $inquiry->getQuotedAt()->format('Y-m-d H:i:s') : null;
                }
                
                $data[] = $item;
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => $data,
                'total' => $result['total'],
                'page' => $result['page'],
                'pageSize' => $pageSize
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse(
                array_merge(
                    ['success' => false],
                    $this->getMessage('submit_failed', $e->getMessage())
                ),
                500
            );
        }
    }

    /**
     * 获取询价单详情
     * 
     * 获取指定询价单的详细信息
     * 
     * 请求方式：GET
     * 请求参数：
     *   - inquiryNo: 询价单号
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - data: 询价单详情
     *   - message: 提示信息（中文）
     *   - messageEn: 提示信息（英文）
     */
    #[Route('/detail/{inquiryNo}', name: 'detail', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function detail(
        string $inquiryNo,
        ProductInquiryRepository $inquiryRepo,
        QiniuUploadService $qiniuService
    ): JsonResponse {
        try {
            // 获取当前登录客户
            $customer = $this->getUser();
            
            // 查询询价单
            $inquiry = $inquiryRepo->findOneByInquiryNo($inquiryNo);
            
            if (!$inquiry) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('inquiry_not_found')),
                    404
                );
            }
            
            // 权限验证：只能查看自己的询价单
            if ($inquiry->getCustomer()->getId() !== $customer->getId()) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('permission_denied')),
                    403
                );
            }
            
            // 格式化数据
            $data = [
                'inquiryNo' => $inquiry->getInquiryNo(),
                'productId' => $inquiry->getProduct()->getId(),
                'productSku' => $inquiry->getProductSku(),
                'productTitle' => $inquiry->getProductTitle(),
                'productTitleEn' => $inquiry->getProductTitleEn(),
                'productMainImage' => $inquiry->getProductMainImage() ? 
                    $qiniuService->getPrivateUrl($inquiry->getProductMainImage()) : '',
                'contactName' => $inquiry->getContactName(),
                'contactPhone' => $inquiry->getContactPhone(),
                'inquiryQuantity' => $inquiry->getInquiryQuantity(),
                'requirementDescription' => $inquiry->getRequirementDescription(),
                'status' => $inquiry->getStatus(),
                'createdAt' => $inquiry->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
            
            // 处理附件
            $attachmentUrls = json_decode($inquiry->getAttachmentUrl() ?? '[]', true);
            $attachmentNames = json_decode($inquiry->getAttachmentName() ?? '[]', true);
            $attachmentTypes = json_decode($inquiry->getAttachmentType() ?? '[]', true);
            
            $attachments = [];
            for ($i = 0; $i < count($attachmentUrls); $i++) {
                $attachments[] = [
                    'key' => $attachmentUrls[$i] ?? '',
                    'name' => $attachmentNames[$i] ?? '',
                    'type' => $attachmentTypes[$i] ?? '',
                    'url' => $attachmentUrls[$i] ? $qiniuService->getPrivateUrl($attachmentUrls[$i]) : ''
                ];
            }
            $data['attachments'] = $attachments;
            
            // 供应商报价信息（只要有任何报价字段不为空，就返回）
            if ($inquiry->getQuotedPrice() || $inquiry->getQuotedTotal() || $inquiry->getQuotedRemark()) {
                $data['quotedPrice'] = $inquiry->getQuotedPrice();
                $data['quotedCurrency'] = $inquiry->getQuotedCurrency();
                $data['quotedTotal'] = $inquiry->getQuotedTotal();
                $data['quotedRemark'] = $inquiry->getQuotedRemark();
                $data['quotedAt'] = $inquiry->getQuotedAt() ? 
                    $inquiry->getQuotedAt()->format('Y-m-d H:i:s') : null;
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => $data
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse(
                array_merge(
                    ['success' => false],
                    $this->getMessage('submit_failed', $e->getMessage())
                ),
                500
            );
        }
    }
}
