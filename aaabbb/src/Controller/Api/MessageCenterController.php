<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\MallCustomerMessages;
use App\Repository\MallMessagesRepository;
use App\Repository\MallCustomerMessagesRepository;
use App\Security\Attribute\RequireAuth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 用户中心消息中心API控制器
 */
#[Route('/shop/api/message-center', name: 'api_message_center_')]
class MessageCenterController extends AbstractController
{
    /**
     * 获取商城公告列表
     * 
     * 条件：message_type = 'mall_announcement' AND recipient_type != 'supplier'
     * 分页：20条/页
     * 
     * @param Request $request
     * @param MallMessagesRepository $messagesRepository
     * @param MallCustomerMessagesRepository $customerMessagesRepository
     * @return JsonResponse
     */
    #[Route('/mall-announcements', name: 'mall_announcements', methods: ['GET'])]
    #[RequireAuth]
    public function getMallAnnouncements(
        Request $request,
        MallMessagesRepository $messagesRepository,
        MallCustomerMessagesRepository $customerMessagesRepository
    ): JsonResponse {
        try {
            // TODO: 获取当前登录用户ID，这里使用模拟ID
            $currentUserId = $this->getCurrentUserId();
            
            $page = $request->query->getInt('page', 1);
            $limit = 20; // 固定20条/页

            // 构建查询条件
            $qb = $messagesRepository->createQueryBuilder('m')
                ->where('m.messageType = :messageType')
                ->andWhere('m.recipientType != :recipientType OR m.recipientType IS NULL')
                ->andWhere('m.isDraft = :isDraft')
                ->setParameter('messageType', 'mall_announcement')
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

            // 格式化数据，包含已读状态
            $formattedMessages = array_map(function ($message) use ($customerMessagesRepository, $currentUserId) {
                // 检查该用户是否已读
                $isRead = false;
                if ($currentUserId) {
                    $customerMessage = $customerMessagesRepository->findOneBy([
                        'messageId' => $message->getId(),
                        'recipientId' => $currentUserId
                    ]);
                    $isRead = $customerMessage ? $customerMessage->getIsRead() : false;
                }
                
                return [
                    'id' => $message->getId(),
                    'title' => $message->getTitle(),
                    'titleEn' => $message->getTitleEn(),
                    'content' => strip_tags($message->getContent()), // 移除HTML标签用于列表预览
                    'messageType' => $message->getMessageType(),
                    'priority' => $message->getPriority(),
                    'sendTime' => $message->getSendTime() ? $message->getSendTime()->format('Y-m-d H:i:s') : null,
                    'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
                    'isRead' => $isRead, // 已读状态
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
                'error' => '获取商城公告失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取平台消息列表
     * 
     * 条件：message_type = 'platform_message' AND recipient_type != 'supplier'
     * 分页：20条/页
     * 
     * @param Request $request
     * @param MallMessagesRepository $messagesRepository
     * @param MallCustomerMessagesRepository $customerMessagesRepository
     * @return JsonResponse
     */
    #[Route('/platform-messages', name: 'platform_messages', methods: ['GET'])]
    #[RequireAuth]
    public function getPlatformMessages(
        Request $request,
        MallMessagesRepository $messagesRepository,
        MallCustomerMessagesRepository $customerMessagesRepository
    ): JsonResponse {
        try {
            // TODO: 获取当前登录用户ID
            $currentUserId = $this->getCurrentUserId();
            
            $page = $request->query->getInt('page', 1);
            $limit = 20; // 固定20条/页

            // 构建查询条件
            $qb = $messagesRepository->createQueryBuilder('m')
                ->where('m.messageType = :messageType')
                ->andWhere('m.recipientType != :recipientType OR m.recipientType IS NULL')
                ->andWhere('m.isDraft = :isDraft')
                ->setParameter('messageType', 'platform_message')
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

            // 格式化数据，包含已读状态
            $formattedMessages = array_map(function ($message) use ($customerMessagesRepository, $currentUserId) {
                // 检查该用户是否已读
                $isRead = false;
                if ($currentUserId) {
                    $customerMessage = $customerMessagesRepository->findOneBy([
                        'messageId' => $message->getId(),
                        'recipientId' => $currentUserId
                    ]);
                    $isRead = $customerMessage ? $customerMessage->getIsRead() : false;
                }
                
                return [
                    'id' => $message->getId(),
                    'title' => $message->getTitle(),
                    'titleEn' => $message->getTitleEn(),
                    'content' => strip_tags($message->getContent()), // 移除HTML标签用于列表预览
                    'messageType' => $message->getMessageType(),
                    'priority' => $message->getPriority(),
                    'sendTime' => $message->getSendTime() ? $message->getSendTime()->format('Y-m-d H:i:s') : null,
                    'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
                    'isRead' => $isRead, // 已读状态
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
                'error' => '获取平台消息失败: ' . $e->getMessage()
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
    #[RequireAuth]
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

            // 验证消息类型（只允许普通用户查看非供应商消息）
            if ($message->getRecipientType() === 'supplier') {
                return new JsonResponse([
                    'success' => false,
                    'error' => '无权查看此消息'
                ], 403);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'id' => $message->getId(),
                    'title' => $message->getTitle(),
                    'titleEn' => $message->getTitleEn(),
                    'content' => $message->getContent(), // 完整的HTML内容
                    'messageType' => $message->getMessageType(),
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
     * 标记消息为已读
     * 
     * @param int $id
     * @param MallMessagesRepository $messagesRepository
     * @param MallCustomerMessagesRepository $customerMessagesRepository
     * @param EntityManagerInterface $entityManager
     * @return JsonResponse
     */
    #[Route('/mark-read/{id}', name: 'mark_read', methods: ['POST'])]
    #[RequireAuth]
    public function markAsRead(
        int $id,
        MallMessagesRepository $messagesRepository,
        MallCustomerMessagesRepository $customerMessagesRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        try {
            $message = $messagesRepository->find($id);

            if (!$message) {
                return new JsonResponse([
                    'success' => false,
                    'error' => '消息不存在'
                ], 404);
            }

            // TODO: 获取当前登录用户ID
            $currentUserId = $this->getCurrentUserId();
            
            if (!$currentUserId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => '用户未登录'
                ], 401);  // ✅ 正确使用 401：这是认证失败场景（用户未登录）
            }

            // 查找或创建用户消息记录
            $customerMessage = $customerMessagesRepository->findOneBy([
                'messageId' => $id,
                'recipientId' => $currentUserId
            ]);

            if (!$customerMessage) {
                // 创建新记录
                $customerMessage = new MallCustomerMessages();
                $customerMessage->setMessageId($id);
                $customerMessage->setRecipientId($currentUserId);
                $customerMessage->setMessage($message);
            }

            // 标记为已读
            if (!$customerMessage->getIsRead()) {
                $customerMessage->setIsRead(true);
                $customerMessage->setReadTime(new \DateTime());
                
                $entityManager->persist($customerMessage);
                $entityManager->flush();
            }

            return new JsonResponse([
                'success' => true,
                'message' => '标记成功'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => '标记失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取当前登录用户ID
     * 
     * @return int|null
     */
    private function getCurrentUserId(): ?int
    {
        /** @var Customer $customer */
        $customer = $this->getUser();
        return $customer ? $customer->getId() : null;
    }
}
