<?php

namespace App\Controller\Api;

use App\Service\SmsService2017;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 短信服务控制器
 * 
 * 提供短信发送相关接口
 */
#[Route('/shop/api/sms', name: 'api_shop_sms_')]
class SmsController extends AbstractController
{
    private SmsService2017 $smsService;
    
    public function __construct(SmsService2017 $smsService)
    {
        $this->smsService = $smsService;
    }
    
    /**
     * 发送验证码短信
     * 
     * @param Request $request HTTP请求对象
     * @return JsonResponse JSON响应
     */
    #[Route('/send-verification-code', name: 'send_verification_code', methods: ['POST'])]
    public function sendVerificationCode(Request $request): JsonResponse
    {
        try {
            // 获取请求参数
            $phoneNumber = $request->request->get('phone');
            $code = $request->request->get('code');
            
            // 参数验证
            if (empty($phoneNumber)) {
                return $this->json([
                    'success' => false,
                    'message' => '手机号码不能为空'
                ], 400);
            }
            
            // 如果没有提供验证码，则生成一个6位随机验证码
            if (empty($code)) {
                $code = $this->smsService->generateVerificationCode(6);
            }
            
            // 发送短信
            $result = $this->smsService->sendVerificationCode($phoneNumber, $code);
            
            // 返回结果
            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'message' => '验证码发送成功',
                    'data' => [
                        'code' => $code, // 仅用于测试，实际应用中不应返回验证码
                        'bizId' => $result['data']['bizId'] ?? null
                    ]
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'message' => $result['message']
                ], 500);
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '发送验证码失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 生成验证码（不发送短信，仅用于测试）
     * 
     * @param Request $request HTTP请求对象
     * @return JsonResponse JSON响应
     */
    #[Route('/generate-code', name: 'generate_code', methods: ['POST'])]
    public function generateCode(Request $request): JsonResponse
    {
        try {
            $length = $request->request->getInt('length', 6);
            
            $code = $this->smsService->generateVerificationCode($length);
            
            return $this->json([
                'success' => true,
                'message' => '验证码生成成功',
                'data' => [
                    'code' => $code
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '生成验证码失败: ' . $e->getMessage()
            ], 500);
        }
    }
}