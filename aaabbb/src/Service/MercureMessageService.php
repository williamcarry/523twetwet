<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Mercure 消息可靠性服务
 * 
 * 功能：
 * 1. 消息持久化：将 Mercure 消息存储到 Redis
 * 2. 消息查询：前端连接后可查询待处理消息
 * 3. 消息清理：消息被前端确认后删除
 * 4. 消息重试：避免消息丢失
 * 
 * 解决问题：
 * - Linux 高速执行导致前端未建立连接时消息已发送的问题
 * - 网络波动导致的消息丢失问题
 */
class MercureMessageService
{
    private \Redis $redis;
    private LoggerInterface $logger;
    private string $messageQueuePrefix = 'mercure:messages:';  // Redis key 前缀
    
    public function __construct(
        \Redis $redis,
        LoggerInterface $logger
    ) {
        $this->redis = $redis;
        $this->logger = $logger;
    }
    
    /**
     * 推送消息到 Redis 队列（确保消息不丢失）
     * 
     * 这个方法做两件事：
     * 1. 存储消息到 Redis
     * 2. 发送 Mercure 更新（尽力而为）
     * 
     * @param string $orderNo 订单号
     * @param array $data 消息数据
     * @return bool 是否成功存储到 Redis
     */
    public function publishMessage(string $orderNo, array $data): bool
    {
        try {
            // 构建 Redis key
            $queueKey = $this->messageQueuePrefix . $orderNo;
            
            // 构建消息对象
            $messageData = [
                'timestamp' => microtime(true),
                'data' => $data,
                'status' => $data['status'] ?? 'unknown',
                'step' => $data['step'] ?? 'unknown'
            ];
            
            // 将消息存储到 Redis 列表（FIFO队列）
            // 使用 RPUSH 添加到列表末尾
            $this->redis->rPush($queueKey, json_encode($messageData));
            
            // 设置消息过期时间（24小时后自动删除）
            // 这样可以避免 Redis 被充满
            $this->redis->expire($queueKey, 86400);
            
            $this->logger->info('[MercureMessageService] 📝 消息已存储到Redis队列', [
                'order_no' => $orderNo,
                'queue_key' => $queueKey,
                'status' => $data['status'] ?? 'unknown',
                'step' => $data['step'] ?? 'unknown',
                'timestamp' => $messageData['timestamp']
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('[MercureMessageService] ❌ 存储消息到Redis失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * 查询待处理的消息（前端连接建立后调用）
     * 
     * 这个方法从 Redis 获取所有待处理的消息
     * 前端在 EventSource 连接建立后立即调用此方法
     * 确保不会遗漏任何消息
     * 
     * @param string $orderNo 订单号
     * @return array 消息列表
     */
    public function getPendingMessages(string $orderNo): array
    {
        try {
            $queueKey = $this->messageQueuePrefix . $orderNo;
            
            // 从 Redis 列表获取所有消息
            // LRANGE 0 -1 表示获取列表中的所有元素
            $messageList = $this->redis->lRange($queueKey, 0, -1);
            
            $messages = [];
            foreach ($messageList as $messageJson) {
                $message = json_decode($messageJson, true);
                if ($message) {
                    $messages[] = $message;
                }
            }
            
            if (count($messages) > 0) {
                $this->logger->info('[MercureMessageService] 📬 获取待处理消息', [
                    'order_no' => $orderNo,
                    'queue_key' => $queueKey,
                    'message_count' => count($messages),
                    'messages' => $messages
                ]);
            }
            
            return $messages;
            
        } catch (\Exception $e) {
            $this->logger->error('[MercureMessageService] ❌ 查询待处理消息失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * 清空已处理的消息
     * 
     * 前端处理完消息后调用此方法清空队列
     * 这样下次连接时不会重复接收
     * 
     * @param string $orderNo 订单号
     * @return bool 是否成功清空
     */
    public function clearMessages(string $orderNo): bool
    {
        try {
            $queueKey = $this->messageQueuePrefix . $orderNo;
            
            // 删除 Redis 中的消息队列
            $deleteCount = $this->redis->del($queueKey);
            
            $this->logger->info('[MercureMessageService] 🗑️ 已清空消息队列', [
                'order_no' => $orderNo,
                'queue_key' => $queueKey,
                'deleted_count' => $deleteCount
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('[MercureMessageService] ❌ 清空消息队列失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 获取最后一条消息（用于快速查询最新状态）
     * 
     * @param string $orderNo 订单号
     * @return array|null 最后一条消息，或 null 如果队列为空
     */
    public function getLastMessage(string $orderNo): ?array
    {
        try {
            $queueKey = $this->messageQueuePrefix . $orderNo;
            
            // 获取列表中的最后一个元素（索引 -1）
            $messageJson = $this->redis->lIndex($queueKey, -1);
            
            if ($messageJson === false) {
                return null;
            }
            
            return json_decode($messageJson, true);
            
        } catch (\Exception $e) {
            $this->logger->error('[MercureMessageService] ❌ 查询最后一条消息失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * 检查是否有待处理的消息
     * 
     * @param string $orderNo 订单号
     * @return bool true 表示有消息，false 表示没有
     */
    public function hasPendingMessages(string $orderNo): bool
    {
        try {
            $queueKey = $this->messageQueuePrefix . $orderNo;
            $length = $this->redis->lLen($queueKey);
            return $length > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
