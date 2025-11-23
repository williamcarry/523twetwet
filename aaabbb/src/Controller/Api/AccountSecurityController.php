<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\EmailVerificationService;
use App\Service\SmsVerificationService;
use App\Service\RsaCryptoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 账号安全控制器
 * 处理用户的账号安全相关操作：更改手机号、邮箱、密码等
 */
#[Route('/shop/api/account-security', name: 'api_account_security_')]
class AccountSecurityController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private RsaCryptoService $rsaCryptoService;
    private CustomerRepository $customerRepository;
    private SmsVerificationService $smsService;
    private EmailVerificationService $emailService;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        RsaCryptoService $rsaCryptoService,
        CustomerRepository $customerRepository,
        SmsVerificationService $smsService,
        EmailVerificationService $emailService
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->rsaCryptoService = $rsaCryptoService;
        $this->customerRepository = $customerRepository;
        $this->smsService = $smsService;
        $this->emailService = $emailService;
    }

    /**
     * 发送手机验证码 - 用于更换手机号
     */
    #[Route('/send-mobile-code', name: 'send_mobile_code', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function sendMobileCode(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $mobile = $data['mobile'] ?? '';
            
            if (empty($mobile)) {
                return $this->json([
                    'success' => false,
                    'message' => '手机号不能为空',
                    'messageEn' => 'Mobile number cannot be empty'
                ], 400);
            }
            
            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                return $this->json([
                    'success' => false,
                    'message' => '手机号格式不正确',
                    'messageEn' => 'Invalid mobile number format'
                ], 400);
            }
            
            // 检查手机号是否已被其他用户使用
            /** @var Customer $currentUser */
            $currentUser = $this->getUser();
            $existingCustomer = $this->customerRepository->findOneBy(['mobile' => $mobile]);
            
            if ($existingCustomer && $existingCustomer->getId() !== $currentUser->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => '该手机号已被其他用户使用',
                    'messageEn' => 'This mobile number is already in use'
                ], 400);
            }
            
            // 获取客户端IP
            $clientIp = $request->getClientIp();
            
            // 发送验证码
            $result = $this->smsService->sendVerificationCode($mobile, $clientIp);
            
            return $this->json(
                array_diff_key($result, ['statusCode' => '', 'code' => '']),
                $result['statusCode'] ?? 200
            );
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '发送失败：' . $e->getMessage(),
                'messageEn' => 'Failed to send: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 发送邮箱验证码 - 用于更换邮箱
     */
    #[Route('/send-email-code', name: 'send_email_code', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function sendEmailCode(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $email = $data['email'] ?? '';
            $locale = $data['locale'] ?? 'zh_CN';
            
            if (empty($email)) {
                return $this->json([
                    'success' => false,
                    'message' => '邮箱不能为空',
                    'messageEn' => 'Email cannot be empty'
                ], 400);
            }
            
            // 验证邮箱格式
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json([
                    'success' => false,
                    'message' => '邮箱格式不正确',
                    'messageEn' => 'Invalid email format'
                ], 400);
            }
            
            // 检查邮箱是否已被其他用户使用
            /** @var Customer $currentUser */
            $currentUser = $this->getUser();
            $existingCustomer = $this->customerRepository->findOneBy(['email' => $email]);
            
            if ($existingCustomer && $existingCustomer->getId() !== $currentUser->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => '该邮箱已被其他用户使用',
                    'messageEn' => 'This email is already in use'
                ], 400);
            }
            
            // 生成并发送验证码
            $code = $this->emailService->generateCode();
            $success = $this->emailService->sendVerificationCode($email, $code, $locale);
            
            if ($success) {
                return $this->json([
                    'success' => true,
                    'message' => '验证码已发送',
                    'messageEn' => 'Verification code sent'
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'message' => '验证码发送失败',
                    'messageEn' => 'Failed to send verification code'
                ], 500);
            }
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '发送失败：' . $e->getMessage(),
                'messageEn' => 'Failed to send: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更改手机号
     */
    #[Route('/change-mobile', name: 'change_mobile', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function changeMobile(Request $request): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密数据
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    return $this->json([
                        'success' => false,
                        'message' => '请求数据格式错误',
                        'messageEn' => 'Invalid request data format'
                    ], 400);
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'message' => '请求失败，请重试',
                    'messageEn' => 'Request failed, please try again'
                ], 400);  // 解密失败是业务错误，返回 400
            }
            
            $newMobile = $data['newMobile'] ?? '';
            $code = $data['code'] ?? '';
            
            if (empty($newMobile) || empty($code)) {
                return $this->json([
                    'success' => false,
                    'message' => '新手机号和验证码不能为空',
                    'messageEn' => 'New mobile and code cannot be empty'
                ], 400);
            }
            
            // TODO: 待定 - 短信验证码验证（等待短信发送配置完成后启用）
            /*
            $verifyResult = $this->smsService->verifyCode($newMobile, $code);
            if (!$verifyResult['success']) {
                return $this->json([
                    'success' => false,
                    'message' => $verifyResult['message'],
                    'messageEn' => $verifyResult['messageEn']
                ], $verifyResult['statusCode'] ?? 400);
            }
            */
            
            /** @var Customer $customer */
            $customer = $this->getUser();
            
            // 检查新手机号是否已被使用
            $existingCustomer = $this->customerRepository->findOneBy(['mobile' => $newMobile]);
            if ($existingCustomer && $existingCustomer->getId() !== $customer->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => '该手机号已被其他用户使用',
                    'messageEn' => 'This mobile number is already in use'
                ], 400);
            }
            
            // 更新手机号
            $customer->setMobile($newMobile);
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '手机号修改成功',
                'messageEn' => 'Mobile number changed successfully',
                'data' => [
                    'mobile' => $customer->getMobile()
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '操作失败：' . $e->getMessage(),
                'messageEn' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更改邮箱
     */
    #[Route('/change-email', name: 'change_email', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function changeEmail(Request $request): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密数据
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    return $this->json([
                        'success' => false,
                        'message' => '请求数据格式错误',
                        'messageEn' => 'Invalid request data format'
                    ], 400);
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'message' => '请求失败，请重试',
                    'messageEn' => 'Request failed, please try again'
                ], 400);  // 解密失败是业务错误，返回 400
            }
            
            $newEmail = $data['newEmail'] ?? '';
            $code = $data['code'] ?? '';
            
            if (empty($newEmail) || empty($code)) {
                return $this->json([
                    'success' => false,
                    'message' => '新邮箱和验证码不能为空',
                    'messageEn' => 'New email and code cannot be empty'
                ], 400);
            }
            
            /** @var Customer $customer */
            $customer = $this->getUser();
            
            // 验证邮箱验证码
            if (!$this->emailService->verifyCode($newEmail, $code)) {
                return $this->json([
                    'success' => false,
                    'message' => '验证码错误或已过期',
                    'messageEn' => 'Invalid or expired verification code'
                ], 400);
            }
            
            // 检查新邮箱是否已被使用
            $existingCustomer = $this->customerRepository->findOneBy(['email' => $newEmail]);
            if ($existingCustomer && $existingCustomer->getId() !== $customer->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => '该邮箱已被其他用户使用',
                    'messageEn' => 'This email is already in use'
                ], 400);
            }
            
            // 更新邮箱
            $customer->setEmail($newEmail);
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '邮箱修改成功',
                'messageEn' => 'Email changed successfully',
                'data' => [
                    'email' => $customer->getEmail()
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '操作失败：' . $e->getMessage(),
                'messageEn' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更改密码
     */
    #[Route('/change-password', name: 'change_password', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密数据
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    return $this->json([
                        'success' => false,
                        'message' => '请求数据格式错误',
                        'messageEn' => 'Invalid request data format'
                    ], 400);
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'message' => '请求失败，请重试',
                    'messageEn' => 'Request failed, please try again'
                ], 400);  // 解密失败是业务错误，返回 400
            }
            
            $oldPassword = $data['oldPassword'] ?? '';
            $newPassword = $data['newPassword'] ?? '';
            $confirmPassword = $data['confirmPassword'] ?? '';
            // dd($data);
            if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
                return $this->json([
                    'success' => false,
                    'message' => '所有字段不能为空',
                    'messageEn' => 'All fields are required'
                ], 400);
            }
            
            // 验证新密码长度
            if (strlen($newPassword) < 8) {
                return $this->json([
                    'success' => false,
                    'message' => '密码至少8位',
                    'messageEn' => 'Password must be at least 8 characters'
                ], 400);
            }
            
            // 验证两次输入的新密码是否一致
            if ($newPassword !== $confirmPassword) {
                return $this->json([
                    'success' => false,
                    'message' => '两次输入的新密码不一致',
                    'messageEn' => 'New passwords do not match'
                ], 400);
            }
            
            /** @var Customer $customer */
            $customer = $this->getUser();
            
            // 验证旧密码
            if (!$this->passwordHasher->isPasswordValid($customer, $oldPassword)) {
                return $this->json([
                    'success' => false,
                    'message' => '原密码错误',
                    'messageEn' => 'Incorrect old password'
                ], 400);  // 业务错误，返回 400 而不是 401
            }
            
            // 检查新密码不能与旧密码相同
            if ($this->passwordHasher->isPasswordValid($customer, $newPassword)) {
                return $this->json([
                    'success' => false,
                    'message' => '新密码不能与原密码相同',
                    'messageEn' => 'New password cannot be the same as old password'
                ], 400);
            }
            
            // 更新密码
            $hashedPassword = $this->passwordHasher->hashPassword($customer, $newPassword);
            $customer->setPassword($hashedPassword);
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '密码修改成功',
                'messageEn' => 'Password changed successfully'
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '操作失败：' . $e->getMessage(),
                'messageEn' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }


}
