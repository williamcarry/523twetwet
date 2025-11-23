<?php

namespace App\MessageHandler;

use App\Message\OrderReadyMessage;
use App\Message\MultiProductOrderProcessingMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;

/**
 * 前端就绪事件处理器
 * 
 * 这个处理器在消息到达时立即触发（事件驱动）
 * 它的职责是触发订单处理的异步任务
 */
#[AsMessageHandler]
class OrderReadyMessageHandler
{
    private LoggerInterface $logger;
    private MessageBusInterface $bus;

    public function __construct(
        LoggerInterface $logger,
        MessageBusInterface $bus
    ) {
        $this->logger = $logger;
        $this->bus = $bus;
    }

    /**
     * 处理前端就绪事件
     * 
     * 这个方法在消息到达时立即执行
     * 完全异步，不阻塞任何东西
     */
    public function __invoke(OrderReadyMessage $message): void
    {
        $orderNo = $message->getOrderNo();

        $this->logger->info('[OrderReadyHandler] ✅ 收到前端就绪通知', [
            'orderNo' => $orderNo,
            'timestamp' => date('Y-m-d H:i:s.u'),
            'processId' => getmypid()
        ]);

        // 这里可以添加额外的逻辑
        // 例如：记录前端连接时间、验证订单状态等
        
        $this->logger->info('[OrderReadyHandler] 🚀 前端已就绪，订单处理器会自动处理', [
            'orderNo' => $orderNo
        ]);
        
        $this->logger->info('[OrderReadyHandler] 📋 注意：这只是通知，实际订单处理由 MultiProductOrderProcessingMessageHandler 负责');
    }
}
