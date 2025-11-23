<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\OrderReadyMessage;
use App\Service\MercureMessageService;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

/**
 * Mercure JWT Token 生成控制器
 */
#[Route('/api/mercure', name: 'api_mercure_')]
class MercureController extends AbstractController
{
    private MessageBusInterface $bus;
    private MercureMessageService $mercureMessageService;

    public function __construct(
        MessageBusInterface $bus,
        MercureMessageService $mercureMessageService
    ) {
        $this->bus = $bus;
        $this->mercureMessageService = $mercureMessageService;
    }

    /**
     * 获取 Redis 连接
     */
    private function getRedis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        $redisPassword = $_ENV['REDIS_KHUMFG'] ?? '';
        if ($redisPassword) {
            // 从 redis://密码@host:port 格式中提取密码
            if (preg_match('/redis:\/\/:(.+)@/', $redisPassword, $matches)) {
                $redis->auth($matches[1]);
            }
        }
        return $redis;
    }
    /**
     * 生成 Mercure 订阅 JWT Token
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/token', name: 'token', methods: ['GET'])]
    public function getToken(Request $request): JsonResponse
    {
        try {
            // 获取订单号参数
            $orderNo = $request->query->get('orderNo');
            
            if (!$orderNo) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '缺少订单号参数'
                ], 400);
            }
            
            // 构建订阅的 topic
            $topic = "https://example.com/orders/{$orderNo}";
            
            error_log("[MercureToken] 为订单生成 Token: {$orderNo}, Topic: {$topic}");
            
            // 从环境变量获取 Mercure JWT 密钥
            $jwtSecret = $_ENV['MERCURE_JWT_SECRET'] ?? '';
            
            if (empty($jwtSecret)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Mercure JWT 密钥未配置'
                ], 500);
            }
            
            // 配置 JWT
            $config = Configuration::forSymmetricSigner(
                new Sha256(),
                InMemory::plainText($jwtSecret)
            );
            
            // 构建订阅的 topic
            $topic = "https://example.com/orders/{$orderNo}";
            
            // 生成 JWT Token
            $now = new \DateTimeImmutable();
            $token = $config->builder()
                ->issuedAt($now)
                ->expiresAt($now->modify('+1 hour'))
                ->withClaim('mercure', [
                    'subscribe' => [$topic]
                ])
                ->getToken($config->signer(), $config->signingKey());
            
            $tokenString = $token->toString();
            
            // 将 Token 设置为 Cookie（Mercure 会从 Cookie 中读取）
            $response = new JsonResponse([
                'success' => true,
                'token' => $tokenString,
                'topic' => $topic
            ]);
            
            // 设置 mercureAuthorization Cookie
            // 注意：Mercure 默认从名为 'mercureAuthorization' 的 Cookie 中读取 JWT
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie(
                    'mercureAuthorization',
                    $tokenString,
                    time() + 3600, // 1小时后过期
                    '/', // 路径
                    null, // domain
                    false, // secure (HTTPS)
                    false, // httpOnly - 必须设为 false，因为 Mercure 需要读取
                    false, // raw
                    'lax' // sameSite
                )
            );
            
            return $response;
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '生成 Token 失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 前端通知已就绪（事件驱动方案）
     *
     * 这个端点的职责很简单：
     * 1. 接收通知
     * 2. 发布一个消息事件
     * 3. 立即返回（不等待任何事）
     *
     * 总耗时 < 10ms
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/ready', name: 'ready', methods: ['POST'])]
    public function notifyReady(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $orderNo = $data['orderNo'] ?? null;

            if (!$orderNo) {
                error_log("[OrderReady] ❌ 缺少 orderNo");
                return new JsonResponse(['error' => '缺少 orderNo'], 400);
            }

            error_log("[OrderReady] ✅ 收到前端就绪通知: {$orderNo}, 时间: " . date('Y-m-d H:i:s.u'));
            error_log("[OrderReady] 📋 请求数据: " . json_encode($data));

            // 【修复】标记订单状态：前端已连接
            // 这样后端处理器可以在消息准备好时立即推送
            $readyKey = "order:ready:{$orderNo}";
            $redis = $this->getRedis();
            $redis->set($readyKey, '1', 3600); // 1小时后过期
            error_log("[OrderReady] ✅ 已标记订单为就绪状态: {$readyKey}");

            // 唯一的动作：发布一个消息事件
            // 这会被异步处理，不会阻塞响应
            $message = new OrderReadyMessage($orderNo);
            error_log("[OrderReady] 📤 发布 OrderReadyMessage");
            $this->bus->dispatch($message);
            error_log("[OrderReady] ✅ 消息已发布到队列");

            // 立即返回
            $response = [
                'ok' => true,
                'message' => '通知已接收',
                'timestamp' => microtime(true)
            ];
            error_log("[OrderReady] 📤 返回响应: " . json_encode($response));
            return new JsonResponse($response);

        } catch (\Exception $e) {
            error_log("[OrderReady] ❌ 异常: " . $e->getMessage());
            error_log("[OrderReady] ❌ 堆栈: " . $e->getTraceAsString());
            return new JsonResponse([
                'ok' => false,
                'message' => '通知接收失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 获取待处理的消息（前端连接建立后调用）
     *
     * 功能：查询 Redis 中存储的所有待处理消息
     * 场景：
     * 1. 前端 EventSource 连接建立后，立即调用此接口
     * 2. 获取在连接建立前发送的消息（解决Linux高速执行问题）
     * 3. 处理完消息后调用 clearMessages 清空队列
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/pending-messages', name: 'pending_messages', methods: ['GET'])]
    public function getPendingMessages(Request $request): JsonResponse
    {
        try {
            $orderNo = $request->query->get('orderNo');

            if (!$orderNo) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '缺少订单号参数'
                ], 400);
            }

            // 从 Redis 获取所有待处理消息
            $messages = $this->mercureMessageService->getPendingMessages($orderNo);

            return new JsonResponse([
                'success' => true,
                'orderNo' => $orderNo,
                'messages' => $messages,
                'count' => count($messages),
                'timestamp' => microtime(true)
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '查询失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 清空已处理的消息
     *
     * 功能：删除 Redis 中存储的消息队列
     * 场景：前端处理完所有消息后调用，清空队列以避免重复
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/clear-messages', name: 'clear_messages', methods: ['POST'])]
    public function clearMessages(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $orderNo = $data['orderNo'] ?? null;

            if (!$orderNo) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '缺少订单号参数'
                ], 400);
            }

            // 清空 Redis 中的消息队列
            $success = $this->mercureMessageService->clearMessages($orderNo);

            return new JsonResponse([
                'success' => $success,
                'orderNo' => $orderNo,
                'message' => $success ? '消息已清空' : '清空失败',
                'timestamp' => microtime(true)
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '清空失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取订单状态（降级方案使用）
     *
     * 直接查询，无任何延迟
     *
     * @param string $orderNo
     * @return JsonResponse
     */
    #[Route('/order/{orderNo}/status', name: 'order_status', methods: ['GET'])]
    public function getOrderStatus(string $orderNo): JsonResponse
    {
        try {
            if (!$orderNo) {
                return new JsonResponse(['error' => '缺少 orderNo'], 400);
            }

            // 从 Redis 或数据库查询��单状态
            // 这里简化处理，实际应该查询数据库
            $key = "order:status:{$orderNo}";
            $redis = $this->getRedis();
            $status = $redis->get($key);

            if ($status) {
                return new JsonResponse([
                    'orderNo' => $orderNo,
                    'status' => $status,
                    'timestamp' => microtime(true)
                ]);
            }

            return new JsonResponse([
                'orderNo' => $orderNo,
                'status' => 'processing',
                'message' => '订单处理中',
                'timestamp' => microtime(true)
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => '查询失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 【已废弃】前端确认已订阅 Mercure（旧的轮询方案）
     * 
     * 保留此端点是为了兼容性，但不再使用
     * 新方案使用 /ready 端点
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/subscription-ready', name: 'subscription_ready', methods: ['POST'])]
    public function subscriptionReady(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $orderNo = $data['orderNo'] ?? '';
            
            if (!$orderNo) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '缺少订单号参数'
                ], 400);
            }
            
            error_log("[Mercure] ⚠️ 收到旧版订阅确认请求（已废弃）: {$orderNo}");
            
            // 为了兼容性，仍然返回成功
            // 但实际上不再使用这个逻辑
            return new JsonResponse([
                'success' => true,
                'message' => '此端点已废弃，请使用 /ready',
                'confirmed' => true
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '请求失败: ' . $e->getMessage()
            ], 500);
        }
    }
}
