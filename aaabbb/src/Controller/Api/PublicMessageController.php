<?php

namespace App\Controller\Api;

use App\Repository\MallMessagesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 公共消息API控制器
 * 不需要登录验证，不需要签名
 */
#[Route('/shop/api/public/messages', name: 'api_public_messages_')]
class PublicMessageController extends AbstractController
{
    /**
     * 获取所有公共消息列表
     * 
     * 条件：recipient_type != 'supplier' AND isDraft = false
     * 分页：20条/页
     * 
     * @param Request $request
     * @param MallMessagesRepository $messagesRepository
     * @return JsonResponse
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function getMessages(
        Request $request,
        MallMessagesRepository $messagesRepository
    ): JsonResponse {
        try {
            $page = $request->query->getInt('page', 1);
            $limit = 20; // 固定20条/页

            // 构建查询条件：排除供应商消息，排除草稿
            $qb = $messagesRepository->createQueryBuilder('m')
                ->where('m.recipientType != :recipientType OR m.recipientType IS NULL')
                ->andWhere('m.isDraft = :isDraft')
                ->setParameter('recipientType', 'supplier')
                ->setParameter('isDraft', false)
                ->orderBy('m.sendTime', 'DESC')
                ->addOrderBy('m.createdAt', 'DESC');

            // 获取总数
            $totalQb = clone $qb;
            $total = count($totalQb->getQuery()->getResult());

            // 分页
            $offset = ($page - 1) * $limit;
            $qb->setFirstResult($offset)
                ->setMaxResults($limit);

            $messages = $qb->getQuery()->getResult();

            // 格式化数据
            $formattedMessages = array_map(function ($message) {
                return [
                    'id' => $message->getId(),
                    'title' => $message->getTitle(),
                    'titleEn' => $message->getTitleEn(),
                    'content' => strip_tags($message->getContent()), // 移除HTML标签用于列表预览
                    'messageType' => $message->getMessageType(),
                    'messageTypeText' => $this->getMessageTypeText($message->getMessageType()),
                    'priority' => $message->getPriority(),
                    'sendTime' => $message->getSendTime() ? $message->getSendTime()->format('Y-m-d H:i:s') : null,
                    'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }, $messages);

            return new JsonResponse([
                'success' => true,
                'data' => $formattedMessages,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => ceil($total / $limit),
                    'totalItems' => $total,
                    'itemsPerPage' => $limit,
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => '获取消息列表失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取消息详情
     * 
     * @param int $id
     * @param MallMessagesRepository $messagesRepository
     * @return JsonResponse
     */
    #[Route('/detail/{id}', name: 'detail', methods: ['GET'])]
    public function getMessageDetail(
        int $id,
        MallMessagesRepository $messagesRepository
    ): JsonResponse {
        try {
            $message = $messagesRepository->find($id);

            if (!$message) {
                return new JsonResponse([
                    'success' => false,
                    'error' => '消息不存在'
                ], 404);
            }

            // 验证消息类型（只允许查看非供应商消息）
            if ($message->getRecipientType() === 'supplier') {
                return new JsonResponse([
                    'success' => false,
                    'error' => '无权查看此消息'
                ], 403);
            }

            // 验证不是草稿
            if ($message->getIsDraft()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => '消息不存在'
                ], 404);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'id' => $message->getId(),
                    'title' => $message->getTitle(),
                    'titleEn' => $message->getTitleEn(),
                    'content' => $message->getContent(), // 完整的HTML内容
                    'messageType' => $message->getMessageType(),
                    'messageTypeText' => $this->getMessageTypeText($message->getMessageType()),
                    'priority' => $message->getPriority(),
                    'sendTime' => $message->getSendTime() ? $message->getSendTime()->format('Y-m-d H:i:s') : null,
                    'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
                    'updatedAt' => $message->getUpdatedAt()->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => '获取消息详情失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取消息类型文本
     * 
     * @param string $messageType
     * @return string
     */
    private function getMessageTypeText(string $messageType): string
    {
        $types = [
            'mall_announcement' => '商城公告',
            'platform_message' => '平台消息',
        ];

        return $types[$messageType] ?? '未知类型';
    }
}
