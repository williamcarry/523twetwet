<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Repository\BalanceHistoryRepository;
use App\Repository\WithdrawalRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\RsaCryptoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 余额记录API控制器
 * 
 * 路由规范：/shop/api/balance-history
 * 安全规范：所有接口需要用户认证 + API签名验证 + RSA加密传输
 */
#[Route('/shop/api/balance-history', name: 'api_balance_history_')]
class BalanceHistoryController extends AbstractController
{
    public function __construct(
        private BalanceHistoryRepository $balanceHistoryRepository,
        private WithdrawalRepository $withdrawalRepository,
        private RsaCryptoService $rsaCryptoService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * 获取余额记录列表
     * 
     * 请求参数（加密后传输）：
     * - page: 页码（默认1）
     * - pageSize: 每页数量（默认20）
     * - type: 类型筛选（可选，recharge/withdraw/order_payment等）
     * 
     * 返回数据：余额记录列表
     */
    #[Route('/list', name: 'list', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function list(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);  // 解密失败是业务错误，返回 400
        }

        // 分页参数
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($data['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        // 构建查询
        $qb = $this->balanceHistoryRepository->createQueryBuilder('bh')
            ->where('bh.userType = :userType')
            ->andWhere('bh.userId = :userId')
            ->setParameter('userType', 'customer')
            ->setParameter('userId', $customer->getId());

        // 类型筛选
        if (!empty($data['type']) && $data['type'] !== 'all') {
            $qb->andWhere('bh.type = :type')
                ->setParameter('type', $data['type']);
        }

        // 先获取总数
        $total = (int) $qb->select('COUNT(bh.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // 再获取分页数据
        $qb->select('bh')
            ->orderBy('bh.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($pageSize);

        $records = $qb->getQuery()->getResult();

        // 格式化数据
        $recordList = [];
        foreach ($records as $record) {
            $data = [
                'id' => $record->getId(),
                'amount' => $record->getAmount(),
                'balanceBefore' => $record->getBalanceBefore(),
                'balanceAfter' => $record->getBalanceAfter(),
                'frozenAmount' => $record->getFrozenAmount(),
                'frozenBalanceBefore' => $record->getFrozenBalanceBefore(),
                'frozenBalanceAfter' => $record->getFrozenBalanceAfter(),
                'type' => $record->getType(),
                'typeLabel' => $record->getTypeDescription($record->getType()) ?? $record->getType(),
                'description' => $record->getDescription(),
                'referenceId' => $record->getReferenceId(),
                'createdAt' => $record->getCreatedAt()->format('Y-m-d H:i:s'),
                'reason' => null, // 默认为空
            ];
            
            // 如果是提现相关记录，查询提现表的备注（审核意见）
            if (in_array($record->getType(), ['withdraw_freeze', 'withdraw_success', 'withdraw_refund']) && $record->getReferenceId()) {
                $withdrawal = $this->withdrawalRepository->findOneBy([
                    'id' => $record->getReferenceId(),
                    'userType' => 'customer',
                    'userId' => $customer->getId()
                ]);
                
                if ($withdrawal && $withdrawal->getRemark()) {
                    $data['reason'] = $withdrawal->getRemark();
                }
            }
            
            $recordList[] = $data;
        }

        return $this->json([
            'success' => true,
            'data' => [
                'list' => $recordList,
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => ceil($total / $pageSize)
                ]
            ]
        ]);
    }

    /**
     * 获取用户当前余额信息
     */
    #[Route('/balance', name: 'balance', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function balance(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        // 获取货币符号（从 site_config 表读取 configKey='site_currency' 的 configValue3）
        $currencySymbol = '$'; // 默认值
        $config = $this->entityManager->getRepository(\App\Entity\SiteConfig::class)->findOneBy(['configKey' => 'site_currency']);
        if ($config && $config->getConfigValue3()) {
            $currencySymbol = $config->getConfigValue3();
        }

        return $this->json([
            'success' => true,
            'data' => [
                'balance' => $customer->getBalance(),
                'frozenBalance' => $customer->getFrozenBalance(),
                'currencySymbol' => $config && $config->getConfigValue3() ? $config->getConfigValue3() : '$',
                'currencyCode' => $config && $config->getConfigValue() ? $config->getConfigValue() : 'USD'
            ]
        ]);
    }
}
