<?php

namespace App\Controller\Api;

use App\Service\RsaCryptoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * RSA 解密控制器
 * 
 * 提供 RSA 解密服务，供前端加密数据解密使用
 */
#[Route('/shop/api/rsa', name: 'api_shop_rsa_')]
class RsaDecryptController extends AbstractController
{
    private RsaCryptoService $rsaCryptoService;

    public function __construct(RsaCryptoService $rsaCryptoService)
    {
        $this->rsaCryptoService = $rsaCryptoService;
    }

    /**
     * 解密单个数据
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/decrypt', name: 'decrypt', methods: ['POST'])]
    public function decryptData(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['encryptedData'])) {
                return $this->json([
                    'success' => false,
                    'message' => '缺少 encryptedData 参数'
                ], 400);
            }

            $encryptedData = $data['encryptedData'];
            
            // 解密数据
            $decryptedData = $this->rsaCryptoService->decrypt($encryptedData);
            
            return $this->json([
                'success' => true,
                'data' => $decryptedData
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '解密失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 解密多个字段
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/decrypt-fields', name: 'decrypt_fields', methods: ['POST'])]
    public function decryptFields(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['encryptedFields']) || !is_array($data['encryptedFields'])) {
                return $this->json([
                    'success' => false,
                    'message' => '缺少 encryptedFields 参数或参数格式不正确'
                ], 400);
            }

            $encryptedFields = $data['encryptedFields'];
            $decryptedFields = [];
            
            // 解密每个字段
            foreach ($encryptedFields as $key => $encryptedValue) {
                if (!empty($encryptedValue)) {
                    $decryptedFields[$key] = $this->rsaCryptoService->decrypt($encryptedValue);
                } else {
                    $decryptedFields[$key] = $encryptedValue;
                }
            }
            
            return $this->json([
                'success' => true,
                'data' => $decryptedFields
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '解密失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 健康检查接口
     * 
     * @return JsonResponse
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function healthCheck(): JsonResponse
    {
        try {
            // 检查 RSA 服务是否正常
            $isAvailable = $this->rsaCryptoService->isPrivateKeyAvailable();
            
            if (!$isAvailable) {
                return $this->json([
                    'success' => false,
                    'message' => 'RSA 私钥文件不可用'
                ], 500);
            }
            
            return $this->json([
                'success' => true,
                'message' => 'RSA 解密服务正常',
                'timestamp' => time()
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'RSA 解密服务异常: ' . $e->getMessage()
            ], 500);
        }
    }
}