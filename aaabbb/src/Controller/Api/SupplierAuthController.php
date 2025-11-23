<?php

namespace App\Controller\Api;

use App\Entity\Supplier;
use App\Service\SmsVerificationService;
use App\Service\RsaCryptoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * 供应商认证控制器
 */
#[Route('/api/supplier/auth', name: 'api_supplier_auth_')]
class SupplierAuthController extends AbstractController
{
    /**
     * 发送手机验证码
     */
    #[Route('/send-sms-code', name: 'send_sms_code', methods: ['POST'])]
    public function sendSmsCode(Request $request, SmsVerificationService $smsVerification): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $phone = $data['phone'] ?? '';
            
            // 验证手机号不为空
            if (empty($phone)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '手机号不能为空',
                    'messageEn' => 'Phone number cannot be empty'
                ], 400);
            }
            
            // 获取客户端IP
            $clientIp = $request->getClientIp();
            
            // 调用短信验证码服务
            $result = $smsVerification->sendVerificationCode($phone, $clientIp);
            
            return new JsonResponse(
                array_diff_key($result, ['statusCode' => '', 'code' => '']),  // 移除 statusCode 和 code
                $result['statusCode'] ?? 200
            );
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '发送失败：' . $e->getMessage(),
                'messageEn' => 'Failed to send: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 验证手机验证码
     */
    #[Route('/verify-sms-code', name: 'verify_sms_code', methods: ['POST'])]
    public function verifySmsCode(Request $request, SmsVerificationService $smsVerification): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $phone = $data['phone'] ?? '';
            $code = $data['code'] ?? '';
            
            // 调用短信验证码服务
            $result = $smsVerification->verifyCode($phone, $code);
            
            return new JsonResponse(
                array_diff_key($result, ['statusCode' => '']),  // 移除 statusCode
                $result['statusCode'] ?? 200
            );
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '验证失败：' . $e->getMessage(),
                'messageEn' => 'Verification failed'
            ], 500);
        }
    }
    
    /**
     * 供应商入驻注册
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request, 
        SmsVerificationService $smsVerification,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        RsaCryptoService $rsaCryptoService
    ): JsonResponse {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象
            try {
                if (isset($requestData['encryptedPayload'])) {
                    // 解密整个对象
                    $data = $rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    return new JsonResponse([
                        'success' => false,
                        'message' => '请求数据格式错误',
                        'messageEn' => 'Invalid request data format'
                    ], 400);
                }
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '请求失败，请重试',
                    'messageEn' => 'Request failed, please try again'
                ], 400);  // 解密失败是业务错误，不是认证失败
            }
            
            // ==================== 1. 验证必填字段 ====================
            $requiredFields = ['username', 'password', 'email', 'contact_person', 'contact_phone', 'sms_code', 'supplier_type'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $field . ' 为必填项',
                        'messageEn' => $field . ' is required'
                    ], 400);
                }
            }
            
            // 自动将用户名转换为小写
            $data['username'] = strtolower($data['username']);
            
            // 验证用户名格式：必须以英文字母开头，只能包含英文字母、数字和下划线
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $data['username'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '用户名必须以英文字母开头，只能包含英文字母、数字和下划线',
                    'messageEn' => 'Username must start with a letter and can only contain letters, numbers and underscores'
                ], 400);
            }
            
            // 验证用户名长度（最长10个字符）
            if (mb_strlen($data['username']) > 10) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '用户名最长为10个字符',
                    'messageEn' => 'Username must be at most 10 characters'
                ], 400);
            }
            
            // 验证密码长度（至少8位）
            if (strlen($data['password']) < 8) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '密码必须至少8位',
                    'messageEn' => 'Password must be at least 8 characters'
                ], 400);
            }
            
            // ==================== 2. 验证短信验证码 ====================
            // TODO: 短信验证码功能暂时注释，等待阿里云短信服务配置完成后启用
            // 原因：需要配置阿里云短信签名的实名制报备
            /*
            $verifyResult = $smsVerification->verifyCode($data['contact_phone'], $data['sms_code']);
            if (!$verifyResult['success']) {
                return new JsonResponse($verifyResult, $verifyResult['statusCode'] ?? 400);
            }
            */
            
            // ==================== 3. 验证用户名和邮箱唯一性 ====================
            $existingUsername = $entityManager->getRepository(Supplier::class)
                ->findOneBy(['username' => $data['username']]);
            if ($existingUsername) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '用户名已存在',
                    'messageEn' => 'Username already exists'
                ], 400);
            }
            
            $existingEmail = $entityManager->getRepository(Supplier::class)
                ->findOneBy(['email' => $data['email']]);
            if ($existingEmail) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '邮箱已被注册',
                    'messageEn' => 'Email already registered'
                ], 400);
            }
            
            // ==================== 4. 验证供应商类型特定字段 ====================
            if ($data['supplier_type'] === 'company') {
                $companyRequiredFields = ['company_name', 'company_type', 'main_category', 'annual_sales_volume'];
                foreach ($companyRequiredFields as $field) {
                    if (empty($data[$field])) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => $field . ' 为公司供应商必填项',
                            'messageEn' => $field . ' is required for company supplier'
                        ], 400);
                    }
                }
            }
            
            // ==================== 5. 创建供应商实体 ====================
            $supplier = new Supplier();
            
            // 确保客户ID的唯一性
            $supplierRepository = $entityManager->getRepository(Supplier::class);
            $maxAttempts = 10;
            $attempts = 0;
            while ($attempts < $maxAttempts) {
                $existingCustomerId = $supplierRepository->findOneBy(['customerId' => $supplier->getCustomerId()]);
                if (!$existingCustomerId) {
                    break; // 找到唯一ID，退出循环
                }
                // ID冲突，重新生成
                $supplier->setCustomerId($this->generateUniqueCustomerId());
                $attempts++;
            }
            
            if ($attempts >= $maxAttempts) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '系统繁忙，请稍后重试',
                    'messageEn' => 'System busy, please try again later'
                ], 500);
            }
            
            // 基本信息
            $supplier->setUsername($data['username']);
            $supplier->setEmail($data['email']);
            $supplier->setContactPerson($data['contact_person']);
            $supplier->setContactPhone($data['contact_phone']);
            $supplier->setSupplierType($data['supplier_type']);
            
            // 加密密码
            $hashedPassword = $passwordHasher->hashPassword($supplier, $data['password']);
            $supplier->setPassword($hashedPassword);
            
            // 设置默认状态：未激活，待重新提交
            $supplier->setIsActive(false);  // 未激活
            $supplier->setAuditStatus('resubmit');  // 待重新提交
            $supplier->setIsVerified(false);  // 邮箱未验证
            
            // 设置角色权限：所有供应商入驻注册都拥有 ROLE_SUPPLIER_SUPER 权限
            $supplier->setRoles(['ROLE_SUPPLIER_SUPER']);
            
            // 公司信息
            if ($data['supplier_type'] === 'company') {
                $supplier->setCompanyName($data['company_name']);
                $supplier->setCompanyType($data['company_type']);
                $supplier->setMainCategory($data['main_category']);
                $supplier->setAnnualSalesVolume($data['annual_sales_volume']);
                $supplier->setHasCrossBorderExperience($data['has_cross_border_experience'] ?? false);
            }
            
            // 设置时间戳
            $supplier->setCreatedAt(new \DateTime());
            
            // ==================== 6. 数据验证 ====================
            $errors = $validator->validate($supplier);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return new JsonResponse([
                    'success' => false,
                    'message' => '数据验证失败: ' . implode(', ', $errorMessages),
                    'messageEn' => 'Validation failed',
                    'errors' => $errorMessages
                ], 400);
            }
            
            // ==================== 7. 保存到数据库 ====================
            $entityManager->persist($supplier);
            $entityManager->flush();
            
            return new JsonResponse([
                'success' => true,
                'message' => '注册成功，请等待管理员审核',
                'messageEn' => 'Registration successful, please wait for admin approval',
                'data' => [
                    'id' => $supplier->getId(),
                    'customerId' => $supplier->getCustomerId(),  // 返回客户ID
                    'username' => $supplier->getUsername(),
                    'audit_status' => $supplier->getAuditStatus()
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '注册失败：' . $e->getMessage(),
                'messageEn' => 'Registration failed'
            ], 500);
        }
    }

    /**
     * 生成唯一的客户ID
     * 
     * @return string
     */
    private function generateUniqueCustomerId(): string
    {
        $randomNumber = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        return 'CUST' . $randomNumber;
    }
}
