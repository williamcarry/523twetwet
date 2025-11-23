<?php

namespace App\EventSubscriber;

use App\Security\Attribute\RequireSignature;
use App\Service\ApiSignatureService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API签名验证订阅器
 * 
 * 作用：自动拦截带 #[RequireSignature] 的接口，验证签名是否有效
 * 签名无效或过期直接返回401，不执行后续代码
 */
class ApiSignatureSubscriber implements EventSubscriberInterface
{
    private ApiSignatureService $signatureService;

    public function __construct(ApiSignatureService $signatureService)
    {
        $this->signatureService = $signatureService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 10],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (!is_array($controller)) {
            return;
        }

        $controllerObject = $controller[0];
        $method = $controller[1];

        try {
            $reflectionMethod = new \ReflectionMethod($controllerObject, $method);
        } catch (\ReflectionException $e) {
            return;
        }

        // 检查方法级别的签名注解
        $methodAttributes = $reflectionMethod->getAttributes(RequireSignature::class);
        
        // 检查类级别的签名注解
        $reflectionClass = new \ReflectionClass($controllerObject);
        $classAttributes = $reflectionClass->getAttributes(RequireSignature::class);

        if (empty($methodAttributes) && empty($classAttributes)) {
            return;
        }

        // 获取注解实例
        $requireSignatureAttr = null;
        if (!empty($methodAttributes)) {
            $requireSignatureAttr = $methodAttributes[0]->newInstance();
        } elseif (!empty($classAttributes)) {
            $requireSignatureAttr = $classAttributes[0]->newInstance();
        }

        $request = $event->getRequest();
        $params = $this->extractRequestParams($request);

        // 获取accessToken（用于获取签名密钥）
        $accessToken = $request->cookies->get('accessToken');

        // 检查签名参数
        if (!isset($params['signature'])) {
            $event->setController(function () use ($requireSignatureAttr) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $requireSignatureAttr?->message ?? '请求签名失效，请重新登录',
                    'messageEn' => $requireSignatureAttr?->messageEn ?? 'Request signature invalid, please login again',
                    'code' => 'SIGNATURE_REQUIRED',
                    'needRelogin' => true
                ], 401);
            });
            return;
        }

        // 验证签名（传入accessToken以获取用户的签名密钥）
        $errorMessage = '';
        try {
            $isValid = $this->signatureService->verifySignature($params, $errorMessage, $accessToken);
        } catch (\RuntimeException $e) {
            // 捕获签名验证过程中的异常（如：Access token is required）
            $event->setController(function () use ($requireSignatureAttr, $e) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $requireSignatureAttr?->message ?? '请求签名失效，请重新登录',
                    'messageEn' => $requireSignatureAttr?->messageEn ?? 'Request signature invalid, please login again',
                    'code' => 'SIGNATURE_REQUIRED',
                    'needRelogin' => true,
                    'error' => $e->getMessage() // 添加详细错误信息用于调试
                ], 401);
            });
            return;
        }
        
        if (!$isValid) {
            $event->setController(function () use ($requireSignatureAttr, $errorMessage) {
                // 根据错误信息判断是否需要重新登录
                $needRelogin = str_contains($errorMessage, '签名密钥') || 
                               str_contains($errorMessage, '签名验证失败') ||
                               str_contains($errorMessage, '时间戳');
                
                $message = $needRelogin 
                    ? '请求签名失效，请重新登录' 
                    : ($requireSignatureAttr?->message ?? "API签名验证失败：{$errorMessage}");
                    
                $messageEn = $needRelogin 
                    ? 'Request signature invalid, please login again' 
                    : ($requireSignatureAttr?->messageEn ?? "API signature verification failed: {$errorMessage}");
                
                return new JsonResponse([
                    'success' => false,
                    'message' => $message,
                    'messageEn' => $messageEn,
                    'code' => 'INVALID_SIGNATURE',
                    'needRelogin' => $needRelogin
                ], 401);
            });
            return;
        }

        // 检查nonce唯一性（防重放）
        if ($requireSignatureAttr?->checkNonce ?? true) {
            $nonce = $params['nonce'] ?? '';
            
            if (!$this->signatureService->checkNonceUnique($nonce, $requireSignatureAttr->validity ?? 300)) {
                $event->setController(function () use ($requireSignatureAttr) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $requireSignatureAttr?->message ?? '请求已过期，请重新登录',
                        'messageEn' => $requireSignatureAttr?->messageEn ?? 'Request expired, please login again',
                        'code' => 'DUPLICATE_REQUEST',
                        'needRelogin' => true
                    ], 401);
                });
                return;
            }
        }
    }

    /**
     * 提取请求参数
     */
    private function extractRequestParams($request): array
    {
        $params = [];

        // JSON body
        $content = $request->getContent();
        if ($content) {
            $jsonData = json_decode($content, true);
            if (is_array($jsonData)) {
                $params = array_merge($params, $jsonData);
            }
        }

        // POST表单
        $params = array_merge($params, $request->request->all());

        // GET参数
        $params = array_merge($params, $request->query->all());

        return $params;
    }
}
