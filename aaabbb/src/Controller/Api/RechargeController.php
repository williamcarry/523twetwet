<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Repository\RechargeRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\RsaCryptoService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 充值记录API控制器
 * 
 * 路由规范：/shop/api/recharge
 * 安全规范：所有接口需要用户认证 + API签名验证 + RSA加密传输
 */
#[Route('/shop/api/recharge', name: 'api_recharge_')]
class RechargeController extends AbstractController
{
    public function __construct(
        private RechargeRepository $rechargeRepository,
        private RsaCryptoService $rsaCryptoService,
        private SiteConfigService $siteConfigService
    ) {
    }

    /**
     * 获取充值记录列表
     * 
     * 请求参数（加密后传输）：
     * - page: 页码（默认1）
     * - pageSize: 每页数量（默认20）
     * - status: 状态筛选（可选，pending/processing/completed/failed）
     * 
     * 返回数据：充值记录列表
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
        $qb = $this->rechargeRepository->createQueryBuilder('r')
            ->where('r.userType = :userType')
            ->andWhere('r.userId = :userId')
            ->setParameter('userType', 'customer')
            ->setParameter('userId', $customer->getId());

        // 状态筛选
        if (!empty($data['status']) && $data['status'] !== 'all') {
            $qb->andWhere('r.status = :status')
                ->setParameter('status', $data['status']);
        }

        // 先获取总数
        $total = (int) $qb->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // 再获取分页数据
        $qb->select('r')
            ->orderBy('r.createdAt', 'DESC')
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
                'paymentMethod' => $record->getPaymentMethod(), // 返回原始代码，前端翻译
                'status' => $record->getStatus(),
                'statusText' => $record->getStatusText(),
                'statusTagType' => $record->getStatusTagType(),
                'paymentTransactionId' => $record->getPaymentTransactionId(),
                'createdAt' => $record->getCreatedAt()->format('Y-m-d H:i:s'),
                'updatedAt' => $record->getUpdatedAt()?->format('Y-m-d H:i:s'),
                'paidAt' => $record->getPaidAt()?->format('Y-m-d H:i:s'),
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
}
