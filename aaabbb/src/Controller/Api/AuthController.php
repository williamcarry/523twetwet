<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Service\RsaCryptoService;
use App\Service\CustomerTokenService;
use App\common\VipLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 认证控制器
 */
#[Route('/shop/api/auth', name: 'api_shop_auth_')]
class AuthController extends AbstractController
{
    private RsaCryptoService $rsaCryptoService;
    private UserPasswordHasherInterface $passwordHasher;
    private EntityManagerInterface $entityManager;
    private CustomerTokenService $tokenService;

    public function __construct(
        RsaCryptoService $rsaCryptoService,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        CustomerTokenService $tokenService
    ) {
        $this->rsaCryptoService = $rsaCryptoService;
        $this->passwordHasher = $passwordHasher;
        $this->entityManager = $entityManager;
        $this->tokenService = $tokenService;
    }

    /**
     * 生成验证码
     * 
     * @param Request $request
     * @return Response
     */
    #[Route('/captcha', name: 'captcha', methods: ['GET'])]
    public function captcha(Request $request): Response
    {
   
        // 生成随机验证码（5位）
        $captchaCode = $this->generateCaptchaCode(5);
        
        // 将验证码存储在session中
        $session = $request->getSession();
        $session->set('login_captcha_code', strtoupper($captchaCode));
        
        // 创建SVG验证码图片
        $svg = $this->createCaptchaSvg($captchaCode);
        
        $response = new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
        
        return $response;
    }

    /**
     * 验证验证码
     * 
     * @param Request $request
     * @return bool
     */
    private function verifyCaptcha(Request $request, string $userInput): bool
    {
        $session = $request->getSession();
        $storedCaptcha = $session->get('login_captcha_code');
        
        // 验证后清除验证码
        $session->remove('login_captcha_code');
        
        if (empty($storedCaptcha) || empty($userInput)) {
            return false;
        }
        
        return strtoupper(trim($userInput)) === strtoupper($storedCaptcha);
    }

    /**
     * 生成随机验证码
     */
    private function generateCaptchaCode(int $length = 5): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $captchaCode = '';
        for ($i = 0; $i < $length; $i++) {
            $captchaCode .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $captchaCode;
    }
    
    /**
     * 创建SVG验证码图片
     */
    private function createCaptchaSvg(string $text): string
    {
        $palette = ['#cb261c', '#ef6a02', '#2d6df6', '#11a683'];
        
        // 生成字母
        $letters = '';
        $chars = str_split($text);
        foreach ($chars as $idx => $char) {
            $x = 18 + $idx * 18;
            $y = 24 + ($idx % 2 === 0 ? -4 : 6);
            $angle = (rand(-140, 140) / 10); // -14 to 14 degrees
            $color = $palette[$idx % count($palette)];
            $letters .= sprintf(
                '<text x="%d" y="%d" fill="%s" font-size="22" font-family="Verdana" transform="rotate(%s %d %d)">%s</text>',
                $x, $y, $color, $angle, $x, $y - 16, $char
            );
        }
        
        // 生成干扰线
        $lines = '';
        for ($i = 0; $i < 3; $i++) {
            $x1 = rand(0, 120);
            $y1 = rand(0, 40);
            $x2 = rand(0, 120);
            $y2 = rand(0, 40);
            $color = $palette[array_rand($palette)];
            $lines .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1.2" opacity="0.55" />',
                $x1, $y1, $x2, $y2, $color
            );
        }
        
        // 生成干扰点
        $dots = '';
        for ($i = 0; $i < 16; $i++) {
            $cx = rand(0, 120);
            $cy = rand(0, 40);
            $color = $palette[array_rand($palette)];
            $dots .= sprintf(
                '<circle cx="%d" cy="%d" r="1.2" fill="%s" opacity="0.35" />',
                $cx, $cy, $color
            );
        }
        
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40" viewBox="0 0 120 40"><rect width="120" height="40" rx="6" ry="6" fill="#fdf8f6"/>%s%s%s</svg>',
            $lines, $dots, $letters
        );
        
        return $svg;
    }

    /**
     * 用户登录
     * 
     * @param Request $request
     * @param CustomerRepository $customerRepository
     * @return JsonResponse
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request, CustomerRepository $customerRepository): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象（与支付接口保持一致）
            try {
                if (isset($requestData['encryptedPayload'])) {
                    // 解密整个对象
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    // 向下兼容：字段加密方式
                    $data = $requestData;
                    if (isset($data['password'])) {
                        $data['password'] = $this->rsaCryptoService->decrypt($data['password']);
                    }
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'message' => '请求失败，请重试',
                    'messageEn' => 'Request failed, please try again'
                ], 400);  // 解密失败是业务错误，返回 400
            }
            
            $account = $data['account'] ?? '';
            $decryptedPassword = $data['password'] ?? '';
            $captcha = $data['captcha'] ?? '';
            
            if (empty($account) || empty($decryptedPassword)) {
                return $this->json([
                    'success' => false,
                    'message' => '账号和密码不能为空',
                    'messageEn' => 'Account and password cannot be empty'
                ], 400);
            }

            // 验证验证码
            if (!$this->verifyCaptcha($request, $captcha)) {
                return $this->json([
                    'success' => false,
                    'message' => '验证码错误或已过期',
                    'messageEn' => 'Verification code is incorrect or expired'
                ], 400);
            }
            
            // 查找用户（支持用户名、邮箱、手机号登录）
            $customer = $customerRepository->findOneBy(['username' => $account]);
            if (!$customer) {
                $customer = $customerRepository->findOneBy(['email' => $account]);
            }
            if (!$customer) {
                $customer = $customerRepository->findOneBy(['mobile' => $account]);
            }

            if (!$customer) {
                return $this->json([
                    'success' => false,
                    'message' => '账号或密码错误',
                    'messageEn' => 'Incorrect account or password'
                ], 400);  // 业务错误：账号不存在，返回 400
            }

            // 验证账号状态
            if (!$customer->isActive()) {
                return $this->json([
                    'success' => false,
                    'message' => '账号已被禁用，请联系客服',
                    'messageEn' => 'Account has been disabled, please contact customer service'
                ], 403);
            }

            // 验证密码
            if (!$this->passwordHasher->isPasswordValid($customer, $decryptedPassword)) {
                return $this->json([
                    'success' => false,
                    'message' => '账号或密码错误',
                    'messageEn' => 'Incorrect account or password'
                ], 400);  // 业务错误：密码错误，返回 400
            }

            // 更新登录信息
            $customer->updateLastLogin($request->getClientIp());
            $this->entityManager->flush();

            // 生成双Token（访问令2小时 + 刷新7天）
            $tokens = $this->tokenService->generateTokens($customer, [
                'userAgent' => $request->headers->get('User-Agent'),
                'ip' => $request->getClientIp()
            ]);

            // 创建响应
            $response = new JsonResponse([
                'success' => true,
                'message' => '登录成功',
                'messageEn' => 'Login successful',
                'apiSignKey' => $tokens['signatureKey'],  // 返回临时签名密钥
                'user' => [
                    'id' => $customer->getId(),
                    'customerId' => $customer->getCustomerId(),
                    'username' => $customer->getUsername(),
                    'email' => $customer->getEmail(),
                    'nickname' => $customer->getNickname(),
                    'avatar' => $customer->getAvatar(),
                    'mobile' => $customer->getMobile(),
                    'vipLevel' => $customer->getVipLevel(),
                    'vipLevelName' => $customer->getVipLevelName(),
                    'vipLevelNameEn' => VipLevel::getLevelName($customer->getVipLevel(), 'en'),
                    'balance' => $customer->getBalance(),
                    'isVerified' => $customer->isVerified()
                ]
            ]);

            // 设置 HttpOnly Cookie - Access Token（2小时）
            // 判断是否使用 HTTPS：只有生产环境且使用 HTTPS 时才启用 secure
            $isProduction = ($_ENV['APP_ENV'] ?? 'dev') === 'prod';
            $isHttps = $request->isSecure(); // 检测是否是 HTTPS 请求
            $shouldSecure = $isProduction && $isHttps; // 只有生产环境且使用HTTPS时才启用secure
            
            // 获取当前域名（支持IP和域名）
            $domain = $request->getHost();
            // 如果是IP地址，不设置domain（让浏览器自动处理）
            $cookieDomain = filter_var($domain, FILTER_VALIDATE_IP) ? null : $domain;
            
            $response->headers->setCookie(
                Cookie::create('accessToken')
                    ->withValue($tokens['accessToken'])
                    ->withExpires(time() + 7200)  // 2小时
                    ->withPath('/')
                    ->withDomain($cookieDomain)    // IP地址时不设置domain
                    ->withSecure($shouldSecure)    // HTTP环境下不启用secure
                    ->withHttpOnly(true)           // 防止JavaScript读取
                    ->withSameSite('lax')          // 防止CSRF，使用lax兼容跳转
            );

            // 设置 HttpOnly Cookie - Refresh Token（7天）
            $response->headers->setCookie(
                Cookie::create('refreshToken')
                    ->withValue($tokens['refreshToken'])
                    ->withExpires(time() + 604800)  // 7天
                    ->withPath('/')                  // ⚠️ 修改为根路径，确保所有接口都能访问
                    ->withDomain($cookieDomain)      // IP地址时不设置domain
                    ->withSecure($shouldSecure)      // HTTP环境下不启用secure
                    ->withHttpOnly(true)
                    ->withSameSite('lax')
            );

            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '登录失败，请稍后重试',
                'messageEn' => 'Login failed, please try again later'
            ], 500);
        }
    }

    /**
     * 发送短信验证码
     */
    #[Route('/send-sms-code', name: 'send_sms_code', methods: ['POST'])]
    public function sendSmsCode(Request $request, \App\Service\SmsVerificationService $smsVerification): JsonResponse
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
     * 用户注册示例 - 使用 RSA 解密密码
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request, 
        CustomerRepository $customerRepository,
        \App\Service\SmsVerificationService $smsVerification
    ): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象
            try {
                if (isset($requestData['encryptedPayload'])) {
                    // 解密整个对象
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
            
            // ==================== 1. 验证必填字段 ====================
            $username = $data['username'] ?? '';
            $decryptedPassword = $data['password'] ?? '';
            $confirmPassword = $data['confirm'] ?? '';
            $phone = $data['phone'] ?? '';
            $smsCode = $data['sms'] ?? '';
            
            if (empty($username) || empty($decryptedPassword) || empty($confirmPassword) || empty($phone) || empty($smsCode)) {
                return $this->json([
                    'success' => false,
                    'message' => '用户名、密码、确认密码、手机号和验证码不能为空',
                    'messageEn' => 'Username, password, confirm password, phone and SMS code cannot be empty'
                ], 400);
            }
            
            // 自动将用户名转换为小写
            $username = strtolower($username);
            
            // 验证用户名格式：必须以英文字母开头，只能包含英文字母、数字和下划线
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $username)) {
                return $this->json([
                    'success' => false,
                    'message' => '用户名必须以英文字母开头，只能包含英文字母、数字和下划线',
                    'messageEn' => 'Username must start with a letter and can only contain letters, numbers and underscores'
                ], 400);
            }
            
            // 验证用户名长度（最长10个字符）
            if (mb_strlen($username) > 10) {
                return $this->json([
                    'success' => false,
                    'message' => '用户名最长为10个字符',
                    'messageEn' => 'Username must be at most 10 characters'
                ], 400);
            }
            
            // TODO: 待定 - 短信验证码验证（等待后端验证码配置完成后启用）
            /*
            $verifyResult = $smsVerification->verifyCode($phone, $smsCode);
            if (!$verifyResult['success']) {
                return $this->json([
                    'success' => false,
                    'message' => $verifyResult['message'],
                    'messageEn' => $verifyResult['messageEn']
                ], $verifyResult['statusCode'] ?? 400);
            }
            */
            
            // 验证密码长度（至少8位）
            if (strlen($decryptedPassword) < 8) {
                return $this->json([
                    'success' => false,
                    'message' => '密码8位数以上',
                    'messageEn' => 'Password must be at least 8 characters'
                ], 400);
            }
            
            // 验证两次密码是否一致
            if ($decryptedPassword !== $confirmPassword) {
                return $this->json([
                    'success' => false,
                    'message' => '两次输入的密码不一致',
                    'messageEn' => 'The passwords entered twice do not match'
                ], 400);
            }
            
            // ==================== 2. 验证用户名、邮箱、手机号唯一性 ====================
            $existingUsername = $customerRepository->findOneBy(['username' => $username]);
            if ($existingUsername) {
                return $this->json([
                    'success' => false,
                    'message' => '用户名已存在',
                    'messageEn' => 'Username already exists'
                ], 400);
            }
            
            // 如果提供了邮箱，验证邮箱唯一性
            if (!empty($data['email'])) {
                $existingEmail = $customerRepository->findOneBy(['email' => $data['email']]);
                if ($existingEmail) {
                    return $this->json([
                        'success' => false,
                        'message' => '邮箱已被注册',
                        'messageEn' => 'Email already registered'
                    ], 400);
                }
            }
            
            $existingPhone = $customerRepository->findOneBy(['mobile' => $phone]);
            if ($existingPhone) {
                return $this->json([
                    'success' => false,
                    'message' => '手机号已被注册',
                    'messageEn' => 'Phone number already registered'
                ], 400);
            }
            
            // ==================== 3. 创建会员实体 ====================
            $customer = new Customer();
            
            // 确保客户ID的唯一性
            $maxAttempts = 10;
            $attempts = 0;
            while ($attempts < $maxAttempts) {
                $existingCustomerId = $customerRepository->findOneBy(['customerId' => $customer->getCustomerId()]);
                if (!$existingCustomerId) {
                    break; // 找到唯一ID，退出循环
                }
                // ID冲突，重新生成
                $customer->setCustomerId($this->generateUniqueCustomerId());
                $attempts++;
            }
            
            if ($attempts >= $maxAttempts) {
                return $this->json([
                    'success' => false,
                    'message' => '系统繁忙，请稍后重试',
                    'messageEn' => 'System busy, please try again later'
                ], 500);
            }
            
            // 基本信息
            $customer->setUsername($username);
            $customer->setMobile($phone);
            
            // 邮箱是可选的，如果没有提供，使用用户名@placeholder.com
            if (!empty($data['email'])) {
                $customer->setEmail($data['email']);
            } else {
                $customer->setEmail($username . '@placeholder.com');
            }
            
            // 加密密码
            $hashedPassword = $this->passwordHasher->hashPassword($customer, $decryptedPassword);
            $customer->setPassword($hashedPassword);
            
            // 设置默认状态：自动激活，待重新提交
            $customer->setIsActive(true);  // 自动激活
            $customer->setAuditStatus('resubmit');  // 待重新提交
            $customer->setIsVerified(false);  // 未实名
            
            // 设置注册IP
            $customer->setRegisterIp($request->getClientIp());
            
            // 设置时间戳
            $customer->setCreatedAt(new \DateTime());
            
            // ==================== 4. 保存到数据库 ====================
            $this->entityManager->persist($customer);
            $this->entityManager->flush();
            
            // ==================== 5. 注册成功后自动登录 ====================
            // 更新登录信息
            $customer->updateLastLogin($request->getClientIp());
            $this->entityManager->flush();
            
            // 生成双Token（访问令牌2小时 + 刷新令牌7天）
            $tokens = $this->tokenService->generateTokens($customer, [
                'userAgent' => $request->headers->get('User-Agent'),
                'ip' => $request->getClientIp()
            ]);
            
            // 创建响应
            $response = new JsonResponse([
                'success' => true,
                'message' => '注册成功',
                'messageEn' => 'Registration successful',
                'apiSignKey' => $tokens['signatureKey'],  // 返回临时签名密钥
                'user' => [
                    'id' => $customer->getId(),
                    'customerId' => $customer->getCustomerId(),  // 返回客户ID
                    'username' => $customer->getUsername(),
                    'email' => $customer->getEmail(),
                    'nickname' => $customer->getNickname(),
                    'avatar' => $customer->getAvatar(),
                    'mobile' => $customer->getMobile(),
                    'vipLevel' => $customer->getVipLevel(),
                    'vipLevelName' => $customer->getVipLevelName(),
                    'vipLevelNameEn' => VipLevel::getLevelName($customer->getVipLevel(), 'en'),
                    'balance' => $customer->getBalance(),
                    'isVerified' => $customer->isVerified()
                ]
            ]);
            
            // 设置 HttpOnly Cookie - Access Token（2小时）
            // 判断是否使用 HTTPS：只有生产环境且使用 HTTPS 时才启用 secure
            $isProduction = ($_ENV['APP_ENV'] ?? 'dev') === 'prod';
            $isHttps = $request->isSecure(); // 检测是否是 HTTPS 请求
            $shouldSecure = $isProduction && $isHttps; // 只有生产环境且使用HTTPS时才启用secure
            
            // 获取当前域名（支持IP和域名）
            $domain = $request->getHost();
            // 如果是IP地址，不设置domain（让浏览器自动处理）
            $cookieDomain = filter_var($domain, FILTER_VALIDATE_IP) ? null : $domain;
            
            $response->headers->setCookie(
                Cookie::create('accessToken')
                    ->withValue($tokens['accessToken'])
                    ->withExpires(time() + 7200)  // 2小时
                    ->withPath('/')
                    ->withDomain($cookieDomain)    // IP地址时不设置domain
                    ->withSecure($shouldSecure)    // HTTP环境下不启用secure
                    ->withHttpOnly(true)           // 防止JavaScript读取
                    ->withSameSite('lax')          // 防止CSRF，使用lax兼容跳转
            );
            
            // 设置 HttpOnly Cookie - Refresh Token（7天）
            $response->headers->setCookie(
                Cookie::create('refreshToken')
                    ->withValue($tokens['refreshToken'])
                    ->withExpires(time() + 604800)  // 7天
                    ->withPath('/')                  // ⚠️ 修改为根路径，确保所有接口都能访问
                    ->withDomain($cookieDomain)      // IP地址时不设置domain
                    ->withSecure($shouldSecure)      // HTTP环境下不启用secure
                    ->withHttpOnly(true)
                    ->withSameSite('lax')
            );
            
            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '注册失败，请稍后重试',
                'messageEn' => 'Registration failed, please try again later',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 用户登出
     * 撤销Token并清除Cookie
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        try {
            // 从 Cookie 获取 Access Token
            $accessToken = $request->cookies->get('accessToken');
            
            if ($accessToken) {
                // 从 Redis 撤销 Token
                $this->tokenService->revokeToken($accessToken);
            }
            
            // 创建响应
            $response = new JsonResponse([
                'success' => true,
                'message' => '登出成功'
            ]);
            
            // 清除 Cookie
            $response->headers->clearCookie('accessToken', '/');
            $response->headers->clearCookie('refreshToken', '/');  // ⚠️ 修改为根路径
            
            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '登出失败'
            ], 500);
        }
    }

    /**
     * 刷新Token
     * 使用Refresh Token获取新的Access Token
     */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        try {
            // 从 Cookie 获取 Refresh Token
            $refreshToken = $request->cookies->get('refreshToken');
            
            if (!$refreshToken) {
                return $this->json([
                    'success' => false,
                    'message' => 'Refresh token 不存在'
                ], 401);  // ✅ 正确使用 401：这是认证失败场景（Token不存在）
            }
            
            // 验证并生成新的 Access Token
            $newTokens = $this->tokenService->refreshToken($refreshToken);
            
            if (!$newTokens) {
                $response = new JsonResponse([
                    'success' => false,
                    'message' => 'Refresh token 无效或已过期'
                ], 401);  // ✅ 正确使用 401：这是认证失败场景（Token无效或过期）
                
                // 清除无效的 Cookie
                $response->headers->clearCookie('accessToken', '/');
                $response->headers->clearCookie('refreshToken', '/');  // ⚠️ 修改为根路径
                
                return $response;
            }
            
            // 创建响应（返回新的签名密钥）
            $response = new JsonResponse([
                'success' => true,
                'message' => 'Token 刷新成功',
                'apiSignKey' => $newTokens['signatureKey']  // 返回新的签名密钥
            ]);
            
            // 设置新的 Access Token Cookie
            // 判断是否使用 HTTPS：只有生产环境且使用 HTTPS 时才启用 secure
            $isProduction = ($_ENV['APP_ENV'] ?? 'dev') === 'prod';
            $isHttps = $request->isSecure(); // 检测是否是 HTTPS 请求
            $shouldSecure = $isProduction && $isHttps; // 只有生产环境且使用HTTPS时才启用secure
            
            // 获取当前域名（支持IP和域名）
            $domain = $request->getHost();
            // 如果是IP地址，不设置domain（让浏览器自动处理）
            $cookieDomain = filter_var($domain, FILTER_VALIDATE_IP) ? null : $domain;
            
            $response->headers->setCookie(
                Cookie::create('accessToken')
                    ->withValue($newTokens['accessToken'])
                    ->withExpires(time() + 7200)
                    ->withPath('/')
                    ->withDomain($cookieDomain)
                    ->withSecure($shouldSecure)    // HTTP环境下不启用secure
                    ->withHttpOnly(true)
                    ->withSameSite('lax')
            );
            
            // 设置新的 Refresh Token Cookie
            $response->headers->setCookie(
                Cookie::create('refreshToken')
                    ->withValue($newTokens['refreshToken'])
                    ->withExpires(time() + 604800)
                    ->withPath('/')  // ⚠️ 修改为根路径
                    ->withDomain($cookieDomain)
                    ->withSecure($shouldSecure)    // HTTP环境下不启用secure
                    ->withHttpOnly(true)
                    ->withSameSite('lax')
            );
            
            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Token 刷新失败'
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