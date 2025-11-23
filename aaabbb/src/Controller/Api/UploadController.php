<?php

namespace App\Controller\Api;

use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\QiniuUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 文件上传和图片处理API控制器
 * 
 * 提供统一的文件上传和图片URL解析接口
 */
#[Route('/shop/api/upload', name: 'api_upload_')]
class UploadController extends AbstractController
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
            'zh' => '只支持上传图片文件（JPG/PNG/GIF/WEBP）',
            'en' => 'Only image files are supported (JPG/PNG/GIF/WEBP)'
        ],
        'file_too_large' => [
            'zh' => '图片大小不能超过10MB',
            'en' => 'Image size cannot exceed 10MB'
        ],
        'upload_success' => [
            'zh' => '图片上传成功',
            'en' => 'Image uploaded successfully'
        ],
        'upload_failed' => [
            'zh' => '图片上传失败',
            'en' => 'Image upload failed'
        ],
        'upload_error' => [
            'zh' => '上传过程中发生错误',
            'en' => 'Error occurred during upload'
        ],
        'key_empty' => [
            'zh' => '图片key不能为空',
            'en' => 'Image key cannot be empty'
        ],
        'parse_failed' => [
            'zh' => '解析图片URL失败',
            'en' => 'Failed to parse image URL'
        ],
        'provide_key' => [
            'zh' => '请提供key或keys参数',
            'en' => 'Please provide key or keys parameter'
        ],
        'delete_success' => [
            'zh' => '图片删除成功',
            'en' => 'Image deleted successfully'
        ],
        'delete_failed' => [
            'zh' => '图片删除失败',
            'en' => 'Failed to delete image'
        ],
        'delete_error' => [
            'zh' => '删除图片失败',
            'en' => 'Failed to delete image'
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
     * 上传图片到七牛云
     * 
     * 接收前端上传的图片文件，上传到七牛云存储，返回图片key和预览URL
     * 
     * 请求方式：POST
     * 请求参数：
     *   - file: 图片文件（multipart/form-data）
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - key: 图片在七牛云的存储key（用于保存到数据库）
     *   - previewUrl: 带签名的预览URL（用于立即显示）
     *   - url: 同key，兼容旧代码
     *   - message: 提示信息（中文）
     *   - messageEn: 提示信息（英文）
     * 
     * @param Request $request
     * @param QiniuUploadService $qiniuService
     * @return JsonResponse
     */
    #[Route('/image', name: 'image', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function uploadImage(Request $request, QiniuUploadService $qiniuService): JsonResponse
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
            
            // 验证文件类型（只允许图片）
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($uploadedFile->getMimeType(), $allowedTypes)) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('invalid_type')),
                    400
                );
            }
            
            // 验证文件大小（最夜10MB）
            if ($uploadedFile->getSize() > 10 * 1024 * 1024) {
                return new JsonResponse(
                    array_merge(['success' => false], $this->getMessage('file_too_large')),
                    400
                );
            }
            
            // 生成文件名
            $extension = $uploadedFile->guessExtension() ?: $uploadedFile->getClientOriginalExtension();
            $fileName = 'customer_' . time() . '_' . uniqid() . '.' . $extension;
            
            // 上传到七牛云
            $uploadResult = $qiniuService->uploadFile($uploadedFile->getPathname(), $fileName);
            
            if ($uploadResult['success']) {
                return new JsonResponse(
                    array_merge(
                        [
                            'success' => true,
                            'key' => $uploadResult['key'],           // 存储到数据库的key
                            'url' => $uploadResult['key'],           // 兼容旧代码
                            'previewUrl' => $uploadResult['url'],    // 带签名的预览URL（有效期1小时）
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
                    $this->getMessage('upload_error', $e->getMessage())
                ),
                500
            );
        }
    }
    
    /**
     * 解析图片URL
     * 
     * 根据图片key生成带签名的访问URL（用于私有空间）
     * 如果传入的已经是完整URL，则直接返回
     * 
     * 请求方式：POST
     * 请求参数：
     *   - key: 图片key或完整URL
     *   - keys: 批量解析（数组）
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - url: 带签名的URL（单个key时）
     *   - urls: URL映射对象（批量时，key => url）
     *   - message: 提示信息（中文）
     *   - messageEn: 提示信息（英文）
     * 
     * @param Request $request
     * @param QiniuUploadService $qiniuService
     * @return JsonResponse
     */
    #[Route('/parse-image', name: 'parse_image', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function parseImage(Request $request, QiniuUploadService $qiniuService): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            // 支持单个key解析
            if (isset($data['key'])) {
                $key = $data['key'];
                
                if (empty($key)) {
                    return new JsonResponse(
                        array_merge(['success' => false], $this->getMessage('key_empty')),
                        400
                    );
                }
                
                // 如果已经是完整URL，直接返回
                if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
                    return new JsonResponse([
                        'success' => true,
                        'url' => $key
                    ]);
                }
                
                // 生成带签名的URL
                $signedUrl = $qiniuService->getPrivateUrl($key);
                
                return new JsonResponse([
                    'success' => true,
                    'url' => $signedUrl
                ]);
            }
            
            // 支持批量解析
            if (isset($data['keys']) && is_array($data['keys'])) {
                $keys = $data['keys'];
                $urls = [];
                
                foreach ($keys as $key) {
                    if (empty($key)) {
                        $urls[$key] = '';
                        continue;
                    }
                    
                    // 如果已经是完整URL，直接使用
                    if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
                        $urls[$key] = $key;
                    } else {
                        // 生成带签名的URL
                        $urls[$key] = $qiniuService->getPrivateUrl($key);
                    }
                }
                
                return new JsonResponse([
                    'success' => true,
                    'urls' => $urls
                ]);
            }
            
            return new JsonResponse(
                array_merge(['success' => false], $this->getMessage('provide_key')),
                400
            );
            
        } catch (\Exception $e) {
            return new JsonResponse(
                array_merge(
                    ['success' => false],
                    $this->getMessage('parse_failed', $e->getMessage())
                ),
                500
            );
        }
    }
    
    /**
     * 删除图片
     * 
     * 从七牛云删除指定的图片
     * 
     * 请求方式：POST
     * 请求参数：
     *   - key: 要删除的图片key
     * 
     * 返回数据：
     *   - success: 是否成功
     *   - message: 提示信息（中文）
     *   - messageEn: 提示信息（英文）
     * 
     * @param Request $request
     * @param QiniuUploadService $qiniuService
     * @return JsonResponse
     */
    #[Route('/delete-image', name: 'delete_image', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function deleteImage(Request $request, QiniuUploadService $qiniuService): JsonResponse
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
            
            if ($deleteResult['success']) {
                return new JsonResponse(
                    array_merge(['success' => true], $this->getMessage('delete_success'))
                );
            } else {
                return new JsonResponse(
                    array_merge(
                        ['success' => false],
                        $this->getMessage('delete_failed', $deleteResult['error'] ?? null)
                    ),
                    500
                );
            }
            
        } catch (\Exception $e) {
            return new JsonResponse(
                array_merge(
                    ['success' => false],
                    $this->getMessage('delete_error', $e->getMessage())
                ),
                500
            );
        }
    }
}
