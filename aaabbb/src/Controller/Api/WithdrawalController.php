<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\Withdrawal;
use App\Repository\WithdrawalRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\RsaCryptoService;
use App\Service\SiteConfigService;
use App\Service\FinancialCalculatorService;
use App\Service\BalanceHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 提现记录API控制器
 * 
 * 路由规范：/shop/api/withdrawal
 * 安全规范：所有接口需要用户认证 + API签名验证 + RSA加密传输
 */
#[Route('/shop/api/withdrawal', name: 'api_withdrawal_')]
class WithdrawalController extends AbstractController
{
    public function __construct(
        private WithdrawalRepository $withdrawalRepository,
        private RsaCryptoService $rsaCryptoService,
        private SiteConfigService $siteConfigService,
        private FinancialCalculatorService $financialCalculator,
        private BalanceHistoryService $balanceHistoryService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * 获取提现记录列表
     * 
     * 请求参数（加密后传输）：
     * - page: 页码（默认1）
     * - pageSize: 每页数量（默认20）
     * - status: 状态筛选（可选，pending/approved/rejected/completed）
     * 
     * 返回数据：提现记录列表
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
            ], 400);
        }

        // 分页参数
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($data['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        // 构建查询
        $qb = $this->withdrawalRepository->createQueryBuilder('w')
            ->where('w.userType = :userType')
            ->andWhere('w.userId = :userId')
            ->setParameter('userType', 'customer')
            ->setParameter('userId', $customer->getId());

        // 状态筛选
        if (!empty($data['status']) && $data['status'] !== 'all') {
            $qb->andWhere('w.status = :status')
                ->setParameter('status', $data['status']);
        }

        // 先获取总数
        $total = (int) $qb->select('COUNT(w.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // 再获取分页数据
        $qb->select('w')
            ->orderBy('w.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($pageSize);

        $records = $qb->getQuery()->getResult();

        // 格式化数据
        $recordList = [];
        foreach ($records as $record) {
            $recordList[] = [
                'id' => $record->getId(),
                'orderNo' => $record->getOrderNo(),
                'amount' => $record->getAmount(),
                'withdrawalMethod' => $record->getWithdrawalMethod(), // 返回原始代码，前端翻译
                'status' => $record->getStatus(),
                'statusText' => $record->getStatusText(),
                'statusTagType' => $record->getStatusTagType(),
                'paymentInfo' => $record->getPaymentInfo(),
                'remark' => $record->getRemark(),
                'reviewedBy' => $record->getReviewedBy(),
                'reviewedAt' => $record->getReviewedAt()?->format('Y-m-d H:i:s'),
                'paymentTransactionId' => $record->getPaymentTransactionId(),
                'createdAt' => $record->getCreatedAt()->format('Y-m-d H:i:s'),
                'updatedAt' => $record->getUpdatedAt()?->format('Y-m-d H:i:s'),
            ];
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
                ],
                'currencySymbol' => $this->siteConfigService->getCurrencySymbol()
            ]
        ]);
    }

    /**
     * 创建离线提现申请（手动打款）
     * 
     * 请求参数（加密后传输）：
     * - amount: 提现金额（必填）
     * - paymentInfo: 收款信息（必填，如银行账号、支付宝账号等）
     * 
     * 业务逻辑：
     * 1. 读取 withdrawRefundUseOnlinePay 配置，仅当配置为 'offline' 时才能使用此接口
     * 2. 验证余额是否充足（使用 FinancialCalculatorService）
     * 3. 冻结提现金额（从可用余额转到冻结余额）
     * 4. 创建提现记录（status=pending, withdrawalMethod=manual）
     * 5. 创建余额历史记录（type=withdraw_freeze）
     * 6. 等待管理员审核后手动打款
     * 
     * 返回数据：提现申请信息
     */
    #[Route('/create-offline', name: 'create_offline', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function createOfflineWithdrawal(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        $requestData = json_decode($request->getContent(), true);
        
        // 解密请求数据
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
            ], 400);
        }

        // 1. 检查配置，只有 offline 模式才能使用此接口
        if (!$this->siteConfigService->isWithdrawalUseOfflinePay()) {
            return $this->json([
                'success' => false,
                'message' => '当前不支持离线提现',
                'messageEn' => 'Offline withdrawal is not supported currently'
            ], 400);
        }

        // 2. 验证参数
        $amount = $data['amount'] ?? null;
        $paymentInfo = $data['paymentInfo'] ?? null;

        if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
            return $this->json([
                'success' => false,
                'message' => '提现金额无效',
                'messageEn' => 'Invalid withdrawal amount'
            ], 400);
        }

        if (empty($paymentInfo)) {
            return $this->json([
                'success' => false,
                'message' => '请填写收款信息',
                'messageEn' => 'Please provide payment information'
            ], 400);
        }

        // 格式化金额
        $amount = $this->financialCalculator->format((string)$amount);

        // 3. 检查余额是否充足
        $currentBalance = (string)$customer->getBalance();
        if (!$this->financialCalculator->isSufficient($currentBalance, $amount)) {
            return $this->json([
                'success' => false,
                'message' => '余额不足',
                'messageEn' => 'Insufficient balance',
                'data' => [
                    'currentBalance' => $currentBalance,
                    'requestAmount' => $amount
                ]
            ], 400);
        }

        try {
            $this->entityManager->beginTransaction();

            // 4. 冻结提现金额（从可用余额转到冻结余额）
            $balanceBefore = $currentBalance;
            $frozenBalanceBefore = (string)($customer->getFrozenBalance() ?? '0.00');

            // 可用余额减少
            $balanceAfter = $this->financialCalculator->subtract($balanceBefore, $amount);
            $customer->setBalance($balanceAfter);

            // 冻结余额增加
            $frozenBalanceAfter = $this->financialCalculator->add($frozenBalanceBefore, $amount);
            $customer->setFrozenBalance($frozenBalanceAfter);

            // 5. 生成提现订单号（格式：WIT+年月日+6位随机大写字母数字）
            $orderNo = 'WIT' . date('Ymd') . strtoupper(substr(uniqid(), -6));

            // 6. 创建提现记录
            $withdrawal = new Withdrawal();
            $withdrawal->setOrderNo($orderNo);
            $withdrawal->setUserType('customer');
            $withdrawal->setUserId($customer->getId());
            $withdrawal->setAmount($amount);
            $withdrawal->setPaymentInfo($paymentInfo);
            $withdrawal->setWithdrawalMethod('manual');
            $withdrawal->setStatus('pending');
            $withdrawal->setRemark('离线手动提现，等待管理员审核');

            $this->entityManager->persist($withdrawal);
            $this->entityManager->flush();

            // 7. 创建余额历史记录（type=withdraw_freeze）
            $balanceHistory = $this->balanceHistoryService->createBalanceHistory(
                userType: 'customer',
                userId: $customer->getId(),
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                amount: '-' . $amount,
                frozenBalanceBefore: $frozenBalanceBefore,
                frozenBalanceAfter: $frozenBalanceAfter,
                frozenAmount: $amount,
                type: 'withdraw_freeze',
                description: "离线提现冻结：订单号 {$orderNo}",
                referenceId: $orderNo
            );

            // 关联余额历史记录ID
            $withdrawal->setBalanceHistoryId($balanceHistory->getId());
            $this->entityManager->flush();

            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'message' => '提现申请已提交，等待管理员审核',
                'messageEn' => 'Withdrawal request submitted, pending admin review',
                'data' => [
                    'orderNo' => $orderNo,
                    'amount' => $amount,
                    'status' => 'pending',
                    'statusText' => '待审核',
                    'withdrawalMethod' => 'manual',
                    'currentBalance' => $balanceAfter,
                    'frozenBalance' => $frozenBalanceAfter,
                    'createdAt' => $withdrawal->getCreatedAt()->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            
            return $this->json([
                'success' => false,
                'message' => '提现申请失败：' . $e->getMessage(),
                'messageEn' => 'Withdrawal request failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取提现配置信息
     * 
     * 返回当前提现方式配置（online/offline）
     */
    #[Route('/config', name: 'config', methods: ['GET'])]
    #[RequireAuth]
    public function getWithdrawalConfig(): JsonResponse
    {
        $mode = $this->siteConfigService->getWithdrawalMode();
        
        return $this->json([
            'success' => true,
            'data' => [
                'withdrawalMode' => $mode,
                'isOnlinePayment' => $mode === 'online',
                'isOfflinePayment' => $mode === 'offline',
                'description' => $mode === 'online' 
                    ? '在线提现（Payoneer）' 
                    : '离线提现（管理员审核后手动打款）'
            ]
        ]);
    }
}
