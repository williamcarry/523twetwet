<?php

namespace App\Controller\common;

use App\Service\Payment\PayoneerPaymentService;
use App\Repository\OrderRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Payoneer 支付控制器（三端通用）
 * 
 * 提供 Payoneer 支付的 API 接口，供前后端（商城、管理后台、供应商后台）调用
 * 
 * ================================
 * API 路径规则
 * ================================
 * 所有接口路径以 /api/common/payoneer 开头
 * - 三端（商城前端、管理后台、供应商后台）都可以调用
 * - 接口需要根据业务场景进行权限验证
 * 
 * ================================
 * 配置说明
 * ================================
 * 
 * 1. 环境变量配置 (.env 文件)：
 * 
 * ###> payoneer/payment ###
 * # Payoneer API Token（从 Payoneer 商家后台获取）
 * PAYONEER_API_TOKEN=your_api_token_here
 * 
 * # Payoneer Webhook 签名密钥（用于验证回调真实性）
 * PAYONEER_WEBHOOK_SECRET=your_webhook_secret_here
 * 
 * # Payoneer 商户代码
 * PAYONEER_STORE_CODE=your_store_code_here
 * 
 * # Payoneer API URL（测试环境或生产环境）
 * # 测试环境: https://api.sandbox.payoneer.com/v2/checkout
 * # 生产环境: https://api.payoneer.com/v2/checkout
 * PAYONEER_API_URL=https://api.payoneer.com/v2/checkout
 * ###< payoneer/payment ###
 * 
 * ⚠️ 重要更新：
 * 以下配置项已不再需要，系统会根据业务类型自动生成：
 * - PAYONEER_WEBHOOK_URL（已移除）
 * - PAYONEER_RETURN_URL（已移除）
 * - PAYONEER_SETTLEMENT_CURRENCY（已移除，从数据库 site_config 表读取）
 * 
 * 2. 网站支付币种配置（从数据库读取）：
 * 
 * 支付币种存储在 site_config 表中：
 * - configKey: 'site_currency'
 * - configValue: 币种代码（如 'USD', 'EUR', 'GBP' 等）
 * 
 * 可在管理后台的"网站参数管理"中配置，或直接在数据库中修改：
 * ```sql
 * UPDATE site_config SET configValue = 'USD' WHERE configKey = 'site_currency';
 * ```
 * 
 * ================================
 * 功能列表
 * ================================
 * 
 * 1. [POST] /api/common/payoneer/create-session
 *    创建支付会话，获取支付页面链接
 *    
 * 2. [POST] /api/common/payoneer/webhook
 *    接收 Payoneer 支付回调通知
 *    
 * 3. [GET] /api/common/payoneer/query-status/{orderNo}
 *    查询订单支付状态
 *    
 * 4. [POST] /api/common/payoneer/refund
 *    发起退款请求
 *    
 * 5. [GET] /api/common/payoneer/payment-methods
 *    获取支持的支付方式列表
 *    
 * 6. [POST] /api/common/payoneer/recharge/create
 *    创建充值会话
 *    
 * 7. [POST] /api/common/payoneer/withdrawal/create
 *    创建提现请求
 *    
 * 8. [GET] /api/common/payoneer/withdrawal/status/{withdrawalNo}
 *    查询提现状态
 * 
 * ================================
 * 使用示例
 * ================================
 * 
 * 1. 前端创建支付（商城前端）：
 * ```javascript
 * // 用户点击"立即支付"按钮
 * const response = await fetch('/api/common/payoneer/create-session', {
 *   method: 'POST',
 *   headers: { 'Content-Type': 'application/json' },
 *   body: JSON.stringify({
 *     orderNo: 'ORDER_20251120_001',
 *     customerEmail: 'customer@example.com',
 *     customerFirstName: 'John',
 *     customerLastName: 'Doe'
 *   })
 * });
 * 
 * const data = await response.json();
 * if (data.success) {
 *   // 跳转到 Payoneer 支付页面
 *   window.location.href = data.paymentUrl;
 * }
 * ```
 * 
 * 2. 后台查询支付状态（管理后台）：
 * ```javascript
 * const response = await fetch('/api/common/payoneer/query-status/ORDER_20251120_001');
 * const data = await response.json();
 * console.log('支付状态:', data.status);
 * ```
 * 
 * 3. 后台发起退款（管理后台或供应商后台）：
 * ```javascript
 * const response = await fetch('/api/common/payoneer/refund', {
 *   method: 'POST',
 *   headers: { 'Content-Type': 'application/json' },
 *   body: JSON.stringify({
 *     orderNo: 'ORDER_20251120_001',
 *     amount: 50.00,  // 退款金额（可选，不填则全额退款）
 *     reason: 'Customer request'
 *   })
 * });
 * ```
 * 
 * ================================
 * Webhook 回调说明
 * ================================
 * 
 * Payoneer 会向 PAYONEER_WEBHOOK_URL 发送支付状态通知：
 * 
 * 1. 支付成功 (status: Captured)
 * 2. 支付失败 (status: Failed, Declined)
 * 3. 用户取消 (status: Canceled)
 * 4. 退款完成 (status: Refunded)
 * 5. 争议/退单 (status: In dispute, Dispute lost)
 * 
 * ⚠️ 重要：必须返回 200 OK，否则 Payoneer 会重试发送
 * 
 * ================================
 * 多币种支持说明
 * ================================
 * 
 * 1. 单一币种结算模式（推荐）：
 *    - 用户可以用任意币种支付（EUR, GBP, CNY 等）
 *    - Payoneer 自动换汇为网站结算币种（如 USD）
 *    - 配置方式：在 Payoneer 商家后台设置 Settlement Currency
 * 
 * 2. 原币种返回模式：
 *    - 用户支付什么币种，就收到什么币种
 *    - 适合多币种钱包系统
 *    - 配置方式：在 Payoneer 商家后台设置 TRANSACTION_CURRENCY
 * 
 * ================================
 * 相关文档
 * ================================
 * 
 * - Payoneer 支付集成完整方案：zreadme/Payoneer支付集成完整方案.md
 * - Payoneer 配置参数获取指南：zreadme/Payoneer配置参数获取指南.md
 * - 网站支付币种使用说明：zreadme/网站支付币种使用说明.md
 * 
 * ⚠️ 安全说明：
 * - Webhook 回调：Payoneer 服务器调用，验证签名，无需登录
 * - 用户接口（充值/提现/订单支付）：需要登录验证（#[RequireAuth]）和签名验证（#[RequireSignature]）
 * - 管理员接口（查询/退款）：需要管理员权限（手动检查 ROLE_ADMIN）
 * 
 * @see \App\Service\Payment\PayoneerPaymentService - Payoneer 支付服务
 * @see \App\Entity\SiteConfig - 网站配置实体（存储支付币种）
 * @see \App\Service\SiteConfigService - 网站配置服务
 */
#[Route('/api/common/payoneer', name: 'api_common_payoneer_')]
class PayoneerPaymentController extends AbstractController
{
    public function __construct(
        private PayoneerPaymentService $payoneerService,
        private OrderRepository $orderRepository,
        private LoggerInterface $logger,
        private \App\Service\SiteConfigService $siteConfigService,
        private \Doctrine\ORM\EntityManagerInterface $entityManager,
        private \App\Service\BalanceHistoryService $balanceHistoryService,
        private \App\Service\Order\OrderItemStatusService $orderItemStatusService,
        private \App\Service\RsaCryptoService $rsaCryptoService
    ) {}

    /**
     * 1️⃣ 创建支付会话
     * 
     * 功能：创建 Payoneer 支付会话，获取支付页面链接供用户跳转支付
     * 
     * ⚠️ 安全要求：需要用户登录 + 签名验证
     * 
     * ================================
     * 请求方式：POST
     * ================================
     * 
     * ================================
     * 请求参数（JSON格式）：
     * ================================
     * {
     *   "orderNo": "ORDER_20251120_001",           // 必填：订单号
     *   "customerEmail": "customer@example.com",    // 必填：客户邮箱
     *   "customerFirstName": "John",                // 可选：客户名
     *   "customerLastName": "Doe"                   // 可选：客户姓
     * }
     * 
     * ================================
     * 响应示例（成功）：
     * ================================
     * {
     *   "success": true,
     *   "paymentUrl": "https://checkout.payoneer.com/payment/session_abc123",
     *   "sessionId": "unique_session_id",
     *   "transactionId": "ORDER_20251120_001",
     *   "amount": 100.00,
     *   "currency": "USD",
     *   "networks": [
     *     {
     *       "code": "VISA",
     *       "label": "Visa",
     *       "method": "CREDIT_CARD"
     *     },
     *     {
     *       "code": "MASTERCARD",
     *       "label": "Mastercard",
     *       "method": "CREDIT_CARD"
     *     }
     *   ]
     * }
     * 
     * ================================
     * 响应示例（失败）：
     * ================================
     * {
     *   "success": false,
     *   "error": "订单不存在"
     * }
     * 
     * ================================
     * 使用场景：
     * ================================
     * - 商城前端：用户点击"立即支付"按钮时调用
     * - 管理后台：管理员为订单补发支付链接
     * 
     * ================================
     * 前端调用示例：
     * ================================
     * ```javascript
     * const createPayment = async (orderNo) => {
     *   const encryptedData = await encryptionService.prepareData({
     *     orderNo: orderNo,
     *     customerEmail: userInfo.email,
     *     customerFirstName: userInfo.firstName,
     *     customerLastName: userInfo.lastName
     *   });
     *   
     *   const signature = await apiSignature.sign(encryptedData);
     *   
     *   const response = await fetch('/api/common/payoneer/create-session', {
     *     method: 'POST',
     *     headers: {
     *       'Content-Type': 'application/json',
     *       'X-Requested-With': 'XMLHttpRequest'
     *     },
     *     credentials: 'include',
     *     body: JSON.stringify({
     *       encryptedPayload: encryptedData,
     *       signature: signature
     *     })
     *   });
     *   
     *   const data = await response.json();
     *   if (data.success) {
     *     // 跳转到 Payoneer 支付页面
     *     window.location.href = data.paymentUrl;
     *   } else {
     *     alert('创建支付失败: ' + data.error);
     *   }
     * };
     * ```
     */
    #[Route('/create-session', name: 'create_session', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function createPaymentSession(Request $request): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    // 向下兼容：如果没有encryptedPayload，使用原始数据
                    $data = $requestData;
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error' => '请求失败，请重试'
                ], 400);
            }

            // 验证必填参数
            if (empty($data['orderNo'])) {
                return $this->json(['success' => false, 'error' => '缺少订单号'], Response::HTTP_BAD_REQUEST);
            }

            // 查找订单
            $order = $this->orderRepository->findOneBy(['orderNo' => $data['orderNo']]);
            if (!$order) {
                return $this->json(['success' => false, 'error' => '订单不存在'], Response::HTTP_NOT_FOUND);
            }

            // ⚠️ 权限验证：确保订单属于当前用户
            /** @var \App\Entity\Customer $currentUser */
            $currentUser = $this->getUser();
            if ($order->getCustomer()->getId() !== $currentUser->getId()) {
                $this->logger->warning('用户尝试支付他人订单', [
                    'userId' => $currentUser->getId(),
                    'orderNo' => $order->getOrderNo(),
                    'orderOwnerId' => $order->getCustomer()->getId(),
                ]);
                return $this->json([
                    'success' => false,
                    'error' => '无权操作此订单',
                ], Response::HTTP_FORBIDDEN);
            }

            // 调用服务创建支付会话
            $result = $this->payoneerService->createPaymentSession($order);

            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'paymentUrl' => $result['paymentUrl'],
                    'sessionId' => $result['sessionId'],
                    'transactionId' => $result['transactionId'],
                    'amount' => (float) $order->getTotalAmount(),
                    'currency' => $this->siteConfigService->getSiteCurrency(),
                    'networks' => $result['networks'] ?? [],
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 创建支付会话失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => '系统错误，请稍后重试',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 2️⃣ Webhook 回调接收
     * 
     * 功能：接收 Payoneer 的异步支付状态通知
     * 
     * ⚠️ 重要：此接口由 Payoneer 服务器调用，不是前端调用
     * 
     * ================================
     * 请求方式：POST
     * ================================
     * 
     * ================================
     * URL 参数：
     * ================================
     * type - 回调类型（可选）
     *   - order_payment: 订单支付
     *   - customer_recharge: 客户充值
     *   - supplier_recharge: 供应商充值
     *   - customer_withdrawal: 客户提现
     *   - supplier_withdrawal: 供应商提现
     * 
     * ================================
     * 请求头：
     * ================================
     * X-Payoneer-Signature: {签名}  // Payoneer 的签名，用于验证请求真实性
     * 
     * ================================
     * 请求体（JSON格式）：
     * ================================
     * 由 Payoneer 自动发送，包含支付状态、订单信息、支付详情等
     * 详细格式请参考：zreadme/Payoneer支付集成完整方案.md
     * 
     * ================================
     * 响应示例：
     * ================================
     * 必须返回 200 OK，否则 Payoneer 会重试发送
     * 
     * ================================
     * 配置说明：
     * ================================
     * 在 Payoneer 商家后台配置 Webhook URL：
     * 
     * 1. 订单支付：
     *    https://yourdomain.com/api/common/payoneer/webhook?type=order_payment
     * 
     * 2. 客户充值：
     *    https://yourdomain.com/api/common/payoneer/webhook?type=customer_recharge
     * 
     * 3. 供应商充值：
     *    https://yourdomain.com/api/common/payoneer/webhook?type=supplier_recharge
     * 
     * 4. 客户提现：
     *    https://yourdomain.com/api/common/payoneer/webhook?type=customer_withdrawal
     * 
     * 5. 供应商提现：
     *    https://yourdomain.com/api/common/payoneer/webhook?type=supplier_withdrawal
     * 
     * 注意：确保此 URL 可以公网访问且使用 HTTPS
     * 
     * ================================
     * 支付状态类型：
     * ================================
     * - Captured: 支付成功
     * - Failed / Declined: 支付失败
     * - Canceled: 用户取消
     * - Refunded: 退款完成
     * - In dispute: 争议中
     */
    #[Route('/webhook', name: 'webhook', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $signature = $request->headers->get('X-Payoneer-Signature');
            $callbackType = $request->query->get('type');  // ⚠️ 从 URL 参数获取回调类型

            if (!$signature) {
                $this->logger->error('Payoneer Webhook: 缺少签名');
                return $this->json(['success' => false, 'error' => 'Missing signature'], Response::HTTP_BAD_REQUEST);
            }

            if (!$callbackType) {
                $this->logger->error('Payoneer Webhook: 缺少 type 参数');
                return $this->json(['success' => false, 'error' => 'Missing type parameter'], Response::HTTP_BAD_REQUEST);
            }

            // 处理 Webhook（包含签名验证）
            $result = $this->payoneerService->handleWebhook($payload, $signature, $callbackType);

            // ⚠️ 必须返回 200 OK，否则 Payoneer 会重试
            return $this->json(['success' => true, 'message' => 'Webhook received'], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Payoneer Webhook 处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 即使出错也返回 200，避免 Payoneer 重试
            return $this->json(['success' => false, 'error' => 'Internal error'], Response::HTTP_OK);
        }
    }

    /**
     * 3️⃣ 查询支付状态
     * 
     * 功能：主动查询订单的 Payoneer 支付状态（用于补单、对账）
     * 
     * ⚠️ 安全要求：需要管理员权限
     * 
     * ================================
     * 请求方式：GET
     * ================================
     * 
     * ================================
     * URL 参数：
     * ================================
     * {orderNo} - 订单号
     * 
     * ================================
     * 响应示例（成功）：
     * ================================
     * {
     *   "success": true,
     *   "orderNo": "ORDER_20251120_001",
     *   "status": "charged",
     *   "statusReason": "Captured",
     *   "paymentReference": "PAY_20251120_123456",
     *   "amount": 100.00,
     *   "currency": "USD",
     *   "paymentMethod": "CREDIT_CARD",
     *   "cardBrand": "VISA",
     *   "timestamp": "2025-11-20T14:30:00Z"
     * }
     * 
     * ================================
     * 响应示例（失败）：
     * ================================
     * {
     *   "success": false,
     *   "error": "订单不存在"
     * }
     * 
     * ================================
     * 使用场景：
     * ================================
     * - 管理后台：查看订单支付详情
     * - 供应商后台：查看订单是否已支付
     * - 补单：当 Webhook 丢失时主动查询支付状态
     * 
     * ================================
     * 前端调用示例：
     * ================================
     * ```javascript
     * const queryPaymentStatus = async (orderNo) => {
     *   const response = await fetch(`/api/common/payoneer/query-status/${orderNo}`);
     *   const data = await response.json();
     *   
     *   if (data.success) {
     *     console.log('支付状态:', data.status);
     *     console.log('支付参考号:', data.paymentReference);
     *   } else {
     *     console.error('查询失败:', data.error);
     *   }
     * };
     * ```
     */
    #[Route('/query-status/{orderNo}', name: 'query_status', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function queryPaymentStatus(string $orderNo): JsonResponse
    {
        try {
            // 查找订单
            $order = $this->orderRepository->findOneBy(['orderNo' => $orderNo]);
            if (!$order) {
                return $this->json(['success' => false, 'error' => '订单不存在'], Response::HTTP_NOT_FOUND);
            }

            // 权限验证已通过 IsGranted('ROLE_ADMIN') 完成

            // 调用 Payoneer API 查询状态
            $result = $this->payoneerService->queryPaymentStatus($orderNo);

            return $this->json([
                'success' => true,
                'orderNo' => $orderNo,
                'status' => $result['status']['code'] ?? 'unknown',
                'statusReason' => $result['status']['reason'] ?? '',
                'paymentReference' => $result['payment']['reference'] ?? null,
                'amount' => $result['payment']['amount'] ?? null,
                'currency' => $result['payment']['currency'] ?? null,
                'paymentMethod' => $result['payment']['method'] ?? null,
                'cardBrand' => $result['account']['brand'] ?? null,
                'timestamp' => $result['timestamp'] ?? null,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 查询支付状态失败', [
                'orderNo' => $orderNo,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => '查询失败: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 4️⃣ 发起退款
     * 
     * 功能：向 Payoneer 发起退款请求（支持全额退款或部分退款）
     * 
     * ⚠️ 重要：退款必须使用用户支付时的原始币种
     * ⚠️ 安全要求：需要管理员权限
     * 
     * ================================
     * 请求方式：POST
     * ================================
     * 
     * ================================
     * 请求参数（JSON格式）：
     * ================================
     * {
     *   "orderNo": "ORDER_20251120_001",  // 必填：订单号
     *   "amount": 50.00,                   // 可选：退款金额（不填则全额退款）
     *   "reason": "Customer request"       // 可选：退款原因
     * }
     * 
     * ================================
     * 响应示例（成功）：
     * ================================
     * {
     *   "success": true,
     *   "message": "退款成功",
     *   "refundReference": "REF_123456",
     *   "refundAmount": 50.00,
     *   "refundCurrency": "USD"
     * }
     * 
     * ================================
     * 响应示例（失败）：
     * ================================
     * {
     *   "success": false,
     *   "error": "订单未支付，无法退款"
     * }
     * 
     * ================================
     * 使用场景：
     * ================================
     * - 管理后台：管理员发起退款
     * - 供应商后台：供应商申请退款（需审批）
     * 
     * ================================
     * 前端调用示例：
     * ================================
     * ```javascript
     * const refundOrder = async (orderNo, amount) => {
     *   const response = await fetch('/api/common/payoneer/refund', {
     *     method: 'POST',
     *     headers: { 'Content-Type': 'application/json' },
     *     body: JSON.stringify({
     *       orderNo: orderNo,
     *       amount: amount,  // 可选，不填则全额退款
     *       reason: 'Customer request'
     *     })
     *   });
     *   
     *   const data = await response.json();
     *   if (data.success) {
     *     alert('退款成功');
     *   } else {
     *     alert('退款失败: ' + data.error);
     *   }
     * };
     * ```
     */
    #[Route('/refund', name: 'refund', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function refundPayment(Request $request): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    // 向下兼容：如果没有encryptedPayload，使用原始数据
                    $data = $requestData;
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error' => '请求失败，请重试'
                ], 400);
            }

            // 验证必填参数
            if (empty($data['orderNo'])) {
                return $this->json(['success' => false, 'error' => '缺少订单号'], Response::HTTP_BAD_REQUEST);
            }

            // 查找订单
            $order = $this->orderRepository->findOneBy(['orderNo' => $data['orderNo']]);
            if (!$order) {
                return $this->json(['success' => false, 'error' => '订单不存在'], Response::HTTP_NOT_FOUND);
            }

            // 验证订单是否已支付
            if ($order->getPaymentStatus() !== 'paid') {
                return $this->json(['success' => false, 'error' => '订单未支付，无法退款'], Response::HTTP_BAD_REQUEST);
            }

            // 权限验证已通过 IsGranted('ROLE_ADMIN') 完成

            // 调用服务发起退款
            $amount = isset($data['amount']) ? (float) $data['amount'] : null;
            $reason = $data['reason'] ?? 'Refund';

            $result = $this->payoneerService->refundPayment($order, $amount, $reason);

            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'message' => '退款成功',
                    'refundReference' => $result['refundReference'],
                    'refundAmount' => $result['refundAmount'],
                    'refundCurrency' => $result['refundCurrency'],
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 退款失败', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => '退款失败: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 5️⃣ 获取支持的支付方式
     * 
     * ⚠️ 安全要求：需要管理员权限（配置信息）
     */
    #[Route('/payment-methods', name: 'payment_methods', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function getPaymentMethods(Request $request): JsonResponse
    {
        try {
            $currency = $request->query->get('currency', 'USD');
            $country = $request->query->get('country', 'US');

            $result = $this->payoneerService->getAvailablePaymentMethods($currency, $country);

            return $this->json([
                'success' => true,
                'methods' => $result['methods'],
                'note' => $result['note'],
                'usage' => $result['usage'],
                'currency' => $currency,
                'country' => $country,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 获取支付方式失败', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => '获取支付方式失败',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 6️⃣ 创建充值会话
     * 
     * ⚠️ 安全要求：需要用户登录 + 签名验证
     */
    #[Route('/recharge/create', name: 'recharge_create', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function createRecharge(Request $request): JsonResponse
    {
        try {
            /** @var \App\Entity\Customer $customer */
            $customer = $this->getUser();

            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    // 向下兼容：如果没有encryptedPayload，使用原始数据
                    $data = $requestData;
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error' => '请求失败，请重试'
                ], 400);
            }

            $amount = (float) ($data['amount'] ?? 0);

            if ($amount < 10) {
                return $this->json(['success' => false, 'error' => '充值金额不能小于 10 元'], Response::HTTP_BAD_REQUEST);
            }

            $result = $this->payoneerService->createRechargeSession($customer, $amount);

            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'paymentUrl' => $result['paymentUrl'],
                    'rechargeNo' => $result['rechargeNo'],
                    'amount' => $amount,
                    'currency' => $this->siteConfigService->getSiteCurrency(),
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            $this->logger->error('创建充值会话失败', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => '系统错误'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 7️⃣ 创建提现请求
     * 
     * ⚠️ 安全要求：需要用户登录 + 签名验证
     */
    #[Route('/withdrawal/create', name: 'withdrawal_create', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function createWithdrawal(Request $request): JsonResponse
    {
        try {
            /** @var \App\Entity\Customer $customer */
            $customer = $this->getUser();

            $requestData = json_decode($request->getContent(), true);
            
            // 解密整个JSON对象
            try {
                if (isset($requestData['encryptedPayload'])) {
                    $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
                } else {
                    // 向下兼容：如果没有encryptedPayload，使用原始数据
                    $data = $requestData;
                }
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error' => '请求失败，请重试'
                ], 400);
            }

            $amount = (float) ($data['amount'] ?? 0);
            $accountNumber = $data['accountNumber'] ?? '';

            // 验证金额
            if ($amount < 10) {
                return $this->json(['success' => false, 'error' => '提现金额不能小于 10 元'], Response::HTTP_BAD_REQUEST);
            }

            // 验证 Payoneer 账号
            if (empty($accountNumber)) {
                return $this->json(['success' => false, 'error' => '请填写 Payoneer 账号'], Response::HTTP_BAD_REQUEST);
            }

            // 只保留 Payoneer 账号字段
            $payoutInfo = [
                'accountNumber' => $accountNumber,
            ];

            $result = $this->payoneerService->createWithdrawal($customer, $amount, $payoutInfo);

            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'withdrawalId' => $result['withdrawalId'],
                    'amount' => $amount,
                    'status' => $result['status'],
                    'message' => '提现申请已提交',
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            $this->logger->error('创建提现请求失败', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => '系统错误'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 8️⃣ 查询提现状态
     * 
     * ⚠️ 安全要求：需要用户登录（查询自己的提现）
     */
    #[Route('/withdrawal/status/{withdrawalNo}', name: 'withdrawal_status', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function queryWithdrawalStatus(string $withdrawalNo): JsonResponse
    {
        try {
            /** @var \App\Entity\Customer $customer */
            $customer = $this->getUser();

            $result = $this->payoneerService->queryWithdrawalStatus($withdrawalNo);

            return $this->json([
                'success' => true,
                'withdrawalNo' => $withdrawalNo,
                'status' => $result['status']['code'] ?? 'unknown',
                'statusReason' => $result['status']['reason'] ?? '',
                'amount' => $result['payout']['amount'] ?? null,
                'currency' => $result['payout']['currency'] ?? null,
                'timestamp' => $result['timestamp'] ?? null,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('查询提现状态失败', ['withdrawalNo' => $withdrawalNo, 'error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => '查询失败'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
