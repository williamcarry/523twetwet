<?php

namespace App\Service;

use App\Entity\BalanceHistory;
use Doctrine\ORM\EntityManagerInterface;

class BalanceHistoryService
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * 创建余额变化记录
     *
     * @param string $userType 用户类型：supplier 或 customer
     * @param int $userId 用户ID
     * @param string $balanceBefore 变化前余额
     * @param string $balanceAfter 变化后余额
     * @param string $amount 变化金额
     * @param string $frozenBalanceBefore 变化前冻结余额
     * @param string $frozenBalanceAfter 变化后冻结余额
     * @param string $frozenAmount 冻结余额变化金额
     * @param string $type 业务类型
     * @param string|null $description 变化描述
     * @param string|null $referenceId 关联业务ID
     * @param int|null $orderItemId 订单项ID
     * @param array|null $typeDescriptions 类型说明
     * @param string|null $pendingSettlementBefore 变化前待结算金额
     * @param string|null $pendingSettlementAfter 变化后待结算金额
     * @param string|null $pendingSettlementAmount 待结算金额变化金额
     * @return BalanceHistory
     */
    public function createBalanceHistory(
        string $userType,
        int $userId,
        string $balanceBefore,
        string $balanceAfter,
        string $amount,
        string $frozenBalanceBefore,
        string $frozenBalanceAfter,
        string $frozenAmount,
        string $type,
        ?string $description = null,
        ?string $referenceId = null,
        ?int $orderItemId = null,
        ?array $typeDescriptions = null,
        ?string $pendingSettlementBefore = null,
        ?string $pendingSettlementAfter = null,
        ?string $pendingSettlementAmount = null
    ): BalanceHistory {
        $balanceHistory = new BalanceHistory();
        $balanceHistory->setUserType($userType);
        $balanceHistory->setUserId($userId);
        $balanceHistory->setBalanceBefore($balanceBefore);
        $balanceHistory->setBalanceAfter($balanceAfter);
        $balanceHistory->setAmount($amount);
        $balanceHistory->setFrozenBalanceBefore($frozenBalanceBefore);
        $balanceHistory->setFrozenBalanceAfter($frozenBalanceAfter);
        $balanceHistory->setFrozenAmount($frozenAmount);
        $balanceHistory->setType($type);
        $balanceHistory->setDescription($description);
        $balanceHistory->setReferenceId($referenceId);
        $balanceHistory->setOrderItemId($orderItemId);
        
        // 设置待结算金额相关字段
        if ($pendingSettlementBefore !== null) {
            $balanceHistory->setPendingSettlementBefore($pendingSettlementBefore);
        }
        if ($pendingSettlementAfter !== null) {
            $balanceHistory->setPendingSettlementAfter($pendingSettlementAfter);
        }
        if ($pendingSettlementAmount !== null) {
            $balanceHistory->setPendingSettlementAmount($pendingSettlementAmount);
        }
        
        // 如果提供了类型说明，则更新它们
        if ($typeDescriptions !== null) {
            $balanceHistory->setTypeDescriptions($typeDescriptions);
        }

        $this->entityManager->persist($balanceHistory);
        $this->entityManager->flush();

        return $balanceHistory;
    }

    /**
     * 根据用户类型和ID获取余额变化记录
     *
     * @param string $userType 用户类型：supplier 或 customer
     * @param int $userId 用户ID
     * @param int $limit 限制返回记录数
     * @return BalanceHistory[]
     */
    public function getBalanceHistoryByUser(string $userType, int $userId, int $limit = 50): array
    {
        return $this->entityManager->getRepository(BalanceHistory::class)
            ->findBy(
                ['userType' => $userType, 'userId' => $userId],
                ['createdAt' => 'DESC'],
                $limit
            );
    }

    /**
     * 根据业务类型获取余额变化记录
     *
     * @param string $type 业务类型
     * @param int $limit 限制返回记录数
     * @return BalanceHistory[]
     */
    public function getBalanceHistoryByType(string $type, int $limit = 50): array
    {
        return $this->entityManager->getRepository(BalanceHistory::class)
            ->findBy(
                ['type' => $type],
                ['createdAt' => 'DESC'],
                $limit
            );
    }

    /**
     * 根据业务类型和用户获取余额变化记录
     *
     * @param string $type 业务类型
     * @param string $userType 用户类型：supplier 或 customer
     * @param int $userId 用户ID
     * @param int $limit 限制返回记录数
     * @return BalanceHistory[]
     */
    public function getBalanceHistoryByTypeAndUser(string $type, string $userType, int $userId, int $limit = 50): array
    {
        return $this->entityManager->getRepository(BalanceHistory::class)
            ->findBy(
                ['type' => $type, 'userType' => $userType, 'userId' => $userId],
                ['createdAt' => 'DESC'],
                $limit
            );
    }
}