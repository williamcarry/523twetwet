<?php

namespace App\EventSubscriber;

use App\Entity\Customer;
use App\Security\Attribute\RequireAuth;
use App\Service\CustomerTokenService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Customer认证事件订阅器
 * 拦截带有 #[RequireAuth] 注解的控制器方法，从 HttpOnly Cookie 验证 Token
 */
class CustomerAuthSubscriber implements EventSubscriberInterface
{
    private CustomerTokenService $tokenService;
    private TokenStorageInterface $tokenStorage;
    private AuthorizationCheckerInterface $authorizationChecker;

    public function __construct(
        CustomerTokenService $tokenService,
        TokenStorageInterface $tokenStorage,
        AuthorizationCheckerInterface $authorizationChecker
    ) {
        $this->tokenService = $tokenService;
        $this->tokenStorage = $tokenStorage;
        $this->authorizationChecker = $authorizationChecker;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        // 如果控制器是数组（控制器类和方法）
        if (!is_array($controller)) {
            return;
        }

        $controllerObject = $controller[0];
        $method = $controller[1];

        // 使用反射获取方法
        try {
            $reflectionMethod = new \ReflectionMethod($controllerObject, $method);
        } catch (\ReflectionException $e) {
            return;
        }

        // 检查方法级别的 RequireAuth 属性
        $methodAttributes = $reflectionMethod->getAttributes(RequireAuth::class);
        
        // 检查类级别的 RequireAuth 属性
        $reflectionClass = new \ReflectionClass($controllerObject);
        $classAttributes = $reflectionClass->getAttributes(RequireAuth::class);

        // 如果方法或类上没有 RequireAuth 属性，跳过
        if (empty($methodAttributes) && empty($classAttributes)) {
            return;
        }

        // 获取 RequireAuth 实例（方法级优先于类级）
        $requireAuthAttr = null;
        if (!empty($methodAttributes)) {
            $requireAuthAttr = $methodAttributes[0]->newInstance();
        } elseif (!empty($classAttributes)) {
            $requireAuthAttr = $classAttributes[0]->newInstance();
        }

        $request = $event->getRequest();

        // 1. 优先从 Cookie 获取 Token
        $token = $request->cookies->get('accessToken');
        
        // 2. 降级：从 Authorization Header 获取（兼容 API 调用）
        if (!$token) {
            $authHeader = $request->headers->get('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            }
        }
        
        if (!$token) {
            $event->setController(function () use ($requireAuthAttr) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $requireAuthAttr?->message ?? '请先登录',
                    'messageEn' => 'Please login first',
                    'errorCode' => 'UNAUTHORIZED'
                ], 401);
            });
            return;
        }

        // 3. 验证 Token（从 Redis）
        try {
            $customer = $this->tokenService->validateAccessToken($token);
            
            if (!$customer) {
                $event->setController(function () use ($requireAuthAttr) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $requireAuthAttr?->message ?? '登录已过期，请重新登录',
                        'messageEn' => 'Login expired, please login again',
                        'errorCode' => 'TOKEN_EXPIRED'
                    ], 401);
                });
                return;
            }

            // 4. 检查用户是否激活
            if ($requireAuthAttr?->checkActive && !$customer->isActive()) {
                $event->setController(function () {
                    return new JsonResponse([
                        'success' => false,
                        'message' => '账号已被禁用，请联系客服',
                        'messageEn' => 'Account has been disabled, please contact customer service',
                        'errorCode' => 'ACCOUNT_DISABLED'
                    ], 403);
                });
                return;
            }

            // 5. 将用户设置到安全上下文中
            $authToken = new UsernamePasswordToken(
                $customer,
                'customer_firewall',
                $customer->getRoles()
            );
            $this->tokenStorage->setToken($authToken);

        } catch (\Exception $e) {
            $event->setController(function () use ($requireAuthAttr) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $requireAuthAttr?->message ?? '认证失败',
                    'messageEn' => 'Authentication failed',
                    'errorCode' => 'AUTH_ERROR'
                ], 401);
            });
        }
    }
}
