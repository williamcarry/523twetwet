<?php

namespace App\Service\Payment;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Payoneer 支付服务
 * 
 * 提供 Payoneer Checkout API 的完整集成功能：
 * - 创建支付会话 (LIST API)
 * - 处理支付回调 (Webhook)
 * - 查询交易状态
 * - 退款处理
 * - 签名验证
 * - 多币种支持
 * 
 * @see zreadme/Payoneer支付集成完整方案.md - 完整文档
 * @see zreadme/Payoneer配置参数获取指南.md - 配置指南
 */
class PayoneerPaymentService
{
    // 支持的回调类型常量
    public const CALLBACK_TYPE_ORDER_PAYMENT = 'order_payment';
    public const CALLBACK_TYPE_CUSTOMER_RECHARGE = 'customer_recharge';
    public const CALLBACK_TYPE_SUPPLIER_RECHARGE = 'supplier_recharge';
    public const CALLBACK_TYPE_CUSTOMER_WITHDRAWAL = 'customer_withdrawal';
    public const CALLBACK_TYPE_SUPPLIER_WITHDRAWAL = 'supplier_withdrawal';
    
    // 所有支持的回调类型（内部使用）
    public const ALLOWED_CALLBACK_TYPES = [
        self::CALLBACK_TYPE_ORDER_PAYMENT,
        self::CALLBACK_TYPE_CUSTOMER_RECHARGE,
        self::CALLBACK_TYPE_SUPPLIER_RECHARGE,
        self::CALLBACK_TYPE_CUSTOMER_WITHDRAWAL,
        self::CALLBACK_TYPE_SUPPLIER_WITHDRAWAL,
    ];
    
    private string $apiToken;
    private string $webhookSecret;
    private string $storeCode;
    private string $apiUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private SiteConfigService $siteConfigService,
        private LoggerInterface $logger,
        private \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private \App\Service\Order\OrderItemStatusService $orderItemStatusService,
        private \App\Service\BalanceHistoryService $balanceHistoryService,
        private \App\Service\FinancialCalculatorService $financialCalculator,
        string $payoneerApiToken,
        string $payoneerWebhookSecret,
        string $payoneerStoreCode,
        string $payoneerApiUrl
    ) {
        $this->apiToken = $payoneerApiToken;
        $this->webhookSecret = $payoneerWebhookSecret;
        $this->storeCode = $payoneerStoreCode;
        $this->apiUrl = $payoneerApiUrl;
    }

    /**
     * 获取网站基础URL（支持HTTPS和HTTP）
     * 
     * @return string 网站基础URL，例如：https://example.com
     */
    private function getBaseUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            // 如果没有当前请求（如命令行环境），返回空字符串，稍后会处理
            // 或者从环境变量中读取
            return $_ENV['SITE_URL'] ?? '';
        }
        
        $scheme = $request->getScheme(); // http 或 https
        $host = $request->getHost(); // example.com
        $port = $request->getPort();
        
        // 如果是标准端口（80或443），不需要添加端口号
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $scheme . '://' . $host;
        }
        
        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * 生成 Webhook 通知地址
     * 
     * 根据不同的业务类型生成对应的通知地址
     * 
     * @param string $callbackType 回调类型（order_payment, customer_recharge, supplier_recharge, customer_withdrawal, supplier_withdrawal）
     * @return string Webhook通知地址
     */
    private function generateWebhookUrl(string $callbackType): string
    {
        $baseUrl = $this->getBaseUrl();
        return $baseUrl . '/api/common/payoneer/webhook?type=' . urlencode($callbackType);
    }

    /**
     * 生成支付完成跳转地址
     * 
     * 根据不同的业务类型生成对应的跳转地址
     * 
     * @param string $callbackType 回调类型
     * @param string $referenceNo 订单号/充值单号/提现单号
     * @return string 跳转地址
     */
    private function generateReturnUrl(string $callbackType, string $referenceNo): string
    {
        $baseUrl = $this->getBaseUrl();
        
        switch ($callbackType) {
            case self::CALLBACK_TYPE_ORDER_PAYMENT:
                // 订单支付成功页面
                return $baseUrl . '/payment-success?orderNo=' . urlencode($referenceNo);
                
            case self::CALLBACK_TYPE_CUSTOMER_RECHARGE:
            case self::CALLBACK_TYPE_SUPPLIER_RECHARGE:
                // 充值成功页面
                return $baseUrl . '/balance-success?type=recharge&orderNo=' . urlencode($referenceNo);
                
            case self::CALLBACK_TYPE_CUSTOMER_WITHDRAWAL:
            case self::CALLBACK_TYPE_SUPPLIER_WITHDRAWAL:
                // 提现成功页面
                return $baseUrl . '/balance-success?type=withdrawal&orderNo=' . urlencode($referenceNo);
                
            default:
                // 默认跳转到首页
                return $baseUrl . '/';
        }
    }

    /**
     * 创建支付会话 (LIST API)
     * 
     * 创建 Payoneer 支付会话，获取支付页面链接供用户跳转支付
     * 
     * @param Order $order 订单对象
     * @return array 返回 ['success' => bool, 'paymentUrl' => string, 'sessionId' => string, 'error' => string]
     * 
     * @throws \Exception 当API调用失败时
     * 
     * @example
     * $result = $payoneerService->createPaymentSession($order);
     * 
     * if ($result['success']) {
     *     // 跳转到支付页面: $result['paymentUrl']
     * }
     */
    public function createPaymentSession(Order $order): array
    {
        try {
            // 从数据库读取网站支付币种
            $currency = $this->siteConfigService->getSiteCurrency();

            // 构建 Payoneer LIST API 请求参数
            $requestData = [
                'transactionId' => $order->getOrderNo(),  // 使用订单号作为交易ID
                'amount' => (float) $order->getTotalAmount(),
                'currency' => $currency,  // 从数据库读取的币种
                'country' => $this->getCustomerCountry($order),
                'operationType' => 'CHARGE',  // 收款操作
                'customer' => [
                    'customerNumber' => $order->getCustomer()->getCustomerId() ?? (string) $order->getCustomer()->getId(),
                    'email' => $order->getCustomer()->getEmail(),
                    'firstName' => $order->getCustomer()->getUsername(),
                    'lastName' => '',
                ],
                'notificationURL' => $this->generateWebhookUrl(self::CALLBACK_TYPE_ORDER_PAYMENT),  // Webhook 回调地址
                'returnURL' => $this->generateReturnUrl(self::CALLBACK_TYPE_ORDER_PAYMENT, $order->getOrderNo()),  // 支付完成跳转
            ];

            // 调用 Payoneer API
            $response = $this->httpClient->request('POST', $this->apiUrl . '/lists', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode !== 200) {
                throw new \Exception('Payoneer API 调用失败: ' . ($data['resultInfo'] ?? 'Unknown error'));
            }

            // 验证响应状态
            if ($data['status']['code'] !== 'listed') {
                throw new \Exception('创建支付会话失败: ' . ($data['status']['reason'] ?? 'Unknown reason'));
            }

            // 保存会话信息到订单的支付回调日志中
            $callbackLog = json_decode($order->getPaymentCallbackLog() ?? '{}', true);
            $callbackLog['payoneer_session'] = [
                'sessionId' => $data['identification']['longId'],
                'shortId' => $data['identification']['shortId'] ?? null,
                'createdAt' => date('Y-m-d H:i:s'),
            ];
            $order->setPaymentCallbackLog(json_encode($callbackLog, JSON_UNESCAPED_UNICODE));
            $this->entityManager->flush();

            // 记录日志
            $this->logger->info('Payoneer 支付会话创建成功', [
                'orderNo' => $order->getOrderNo(),
                'sessionId' => $data['identification']['longId'],
                'amount' => $requestData['amount'],
                'currency' => $currency,
            ]);

            return [
                'success' => true,
                'paymentUrl' => $data['links']['redirect'],  // 支付页面URL
                'sessionId' => $data['identification']['longId'],
                'transactionId' => $data['identification']['transactionId'],
                'networks' => $data['networks']['applicable'] ?? [],  // 可用支付方式
            ];

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 支付会话创建失败', [
                'orderNo' => $order->getOrderNo(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 处理 Webhook 回调通知
     * 
     * 接收并处理 Payoneer 的异步支付状态通知
     * 
     * ⚠️ 重要：必须验证签名，防止伪造回调攻击
     * ⚠️ 必须传递 callbackType 参数，明确指定订单类型
     * 
     * @param string $payload 原始请求体 (JSON字符串)
     * @param string $signature HTTP 头部的签名 (X-Payoneer-Signature)
     * @param string $callbackType 回调类型 (必填: order_payment, customer_recharge, supplier_recharge, customer_withdrawal, supplier_withdrawal)
     * @return array 返回 ['success' => bool, 'message' => string]
     * 
     * @throws BadRequestException 当签名验证失败或缺少 callbackType 时
     * 
     * @example
     * // 在 Controller 中调用：
     * $payload = $request->getContent();
     * $signature = $request->headers->get('X-Payoneer-Signature');
     * $callbackType = $request->query->get('type'); // 从 URL 参数获取
     * $result = $payoneerService->handleWebhook($payload, $signature, $callbackType);
     */
    public function handleWebhook(string $payload, string $signature, string $callbackType): array
    {
        // 1. 验证签名（防止伪造回调）
        if (!$this->verifyWebhookSignature($payload, $signature)) {
            $this->logger->error('Payoneer Webhook 签名验证失败');
            throw new BadRequestException('Invalid signature');
        }

        $data = json_decode($payload, true);
        if (!$data) {
            throw new BadRequestException('Invalid JSON payload');
        }

        $transactionId = $data['identification']['transactionId'] ?? null;
        if (!$transactionId) {
            throw new BadRequestException('Missing transactionId');
        }

        // ⚠️ 必须传递 callbackType 参数，明确指定订单类型
        if (!$callbackType) {
            $this->logger->error('Payoneer Webhook: 缺少 callbackType 参数', [
                'transactionId' => $transactionId
            ]);
            throw new BadRequestException('Missing callbackType parameter');
        }

        // 根据 callbackType 参数判断订单类型
        switch ($callbackType) {
            case self::CALLBACK_TYPE_ORDER_PAYMENT:
                // 订单支付回调
                return $this->handleOrderPaymentWebhook($transactionId, $data);
                
            case self::CALLBACK_TYPE_CUSTOMER_RECHARGE:
            case self::CALLBACK_TYPE_SUPPLIER_RECHARGE:
                // 充值回调
                return $this->handleRechargeWebhook($transactionId, $data);
                
            case self::CALLBACK_TYPE_CUSTOMER_WITHDRAWAL:
            case self::CALLBACK_TYPE_SUPPLIER_WITHDRAWAL:
                // 提现回调
                return $this->handleWithdrawalWebhook($transactionId, $data);
                
            default:
                $this->logger->warning('Payoneer Webhook: 未知的回调类型', ['callbackType' => $callbackType]);
                throw new BadRequestException('Unknown callback type: ' . $callbackType);
        }
    }

    /**
     * 处理订单支付 Webhook 回调
     */
    private function handleOrderPaymentWebhook(string $transactionId, array $data): array
    {
        // 2. 查找订单
        $order = $this->orderRepository->findOneBy(['orderNo' => $transactionId]);
        if (!$order) {
            $this->logger->error('Payoneer Webhook: 订单不存在', ['orderNo' => $transactionId]);
            return ['success' => false, 'message' => 'Order not found'];
        }

        // 3. 根据状态类型处理
        $status = $data['status'] ?? '';
        $code = $data['code'] ?? '';
        $entity = $data['entity'] ?? 'payment';

        $this->logger->info('Payoneer Webhook 接收', [
            'orderNo' => $transactionId,
            'status' => $status,
            'code' => $code,
            'entity' => $entity,
        ]);

        try {
            switch ($status) {
                case 'Captured':
                    // 支付成功
                    return $this->handlePaymentCaptured($order, $data);

                case 'Failed':
                case 'Declined':
                case 'Rejected':
                    // 支付失败
                    return $this->handlePaymentFailed($order, $data);

                case 'Canceled':
                    // 用户取消支付
                    return $this->handlePaymentCanceled($order, $data);

                case 'Refunded':
                case 'Partially Refunded':
                    // 退款成功
                    return $this->handlePaymentRefunded($order, $data);

                case 'In dispute':
                case 'Dispute lost':
                    // 争议/退单
                    return $this->handlePaymentDispute($order, $data);

                case 'Registered':
                    // 用户注册支付方式
                    return $this->handleCustomerRegistered($order, $data);

                default:
                    $this->logger->warning('Payoneer Webhook: 未处理的状态', [
                        'orderNo' => $transactionId,
                        'status' => $status,
                    ]);
                    return ['success' => true, 'message' => 'Status not handled'];
            }
        } catch (\Exception $e) {
            $this->logger->error('Payoneer Webhook 处理失败', [
                'orderNo' => $transactionId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '系统错误',
                'messageEn' => 'System error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 处理充值 Webhook 回调
     */
    private function handleRechargeWebhook(string $rechargeNo, array $data): array
    {
        $status = $data['status'] ?? '';

        $this->logger->info('Payoneer 充值 Webhook 接收', [
            'rechargeNo' => $rechargeNo,
            'status' => $status,
        ]);

        switch ($status) {
            case 'Captured':
                // 充值成功
                return $this->handleRechargeSuccess($rechargeNo, $data);

            case 'Failed':
            case 'Declined':
            case 'Rejected':
                // 充值失败
                $this->logger->warning('Payoneer 充值失败', [
                    'rechargeNo' => $rechargeNo,
                    'reason' => $data['interaction']['reason'] ?? 'Unknown',
                ]);
                return [
                    'success' => true,
                    'message' => '充值失败已记录',
                    'messageEn' => 'Recharge failed logged',
                ];

            case 'Canceled':
                // 用户取消充值
                $this->logger->info('Payoneer 充值已取消', ['rechargeNo' => $rechargeNo]);
                return [
                    'success' => true,
                    'message' => '充值已取消',
                    'messageEn' => 'Recharge canceled',
                ];

            default:
                $this->logger->warning('Payoneer 充值 Webhook: 未处理的状态', [
                    'rechargeNo' => $rechargeNo,
                    'status' => $status,
                ]);
                return [
                    'success' => true,
                    'message' => '状态未处理',
                    'messageEn' => 'Status not handled',
                ];
        }
    }

    /**
     * 处理提现 Webhook 回调
     */
    private function handleWithdrawalWebhook(string $withdrawalNo, array $data): array
    {
        $status = $data['status'] ?? '';

        $this->logger->info('Payoneer 提现 Webhook 接收', [
            'withdrawalNo' => $withdrawalNo,
            'status' => $status,
        ]);

        switch ($status) {
            case 'Completed':
            case 'Paid':
                // 提现成功
                return $this->handleWithdrawalSuccess($withdrawalNo, $data);

            case 'Failed':
            case 'Rejected':
                // 提现失败，解冻余额
                return $this->handleWithdrawalFailed($withdrawalNo, $data);

            case 'Canceled':
                // 提现取消，解冻余额
                return $this->handleWithdrawalCanceled($withdrawalNo, $data);

            default:
                $this->logger->warning('Payoneer 提现 Webhook: 未处理的状态', [
                    'withdrawalNo' => $withdrawalNo,
                    'status' => $status,
                ]);
                return [
                    'success' => true,
                    'message' => '状态未处理',
                    'messageEn' => 'Status not handled',
                ];
        }
    }

    /**
     * 处理提现失败回调
     */
    private function handleWithdrawalFailed(string $withdrawalNo, array $data): array
    {
        try {
            // 查找提现记录
            $balanceHistoryRepo = $this->entityManager->getRepository(\App\Entity\BalanceHistory::class);
            $withdrawalRecord = $balanceHistoryRepo->findOneBy([
                'referenceId' => $withdrawalNo,
                'type' => 'withdraw',
            ]);

            if (!$withdrawalRecord) {
                $this->logger->error('提现记录不存在', ['withdrawalNo' => $withdrawalNo]);
                return [
                    'success' => false,
                    'message' => '提现记录不存在',
                    'messageEn' => 'Withdrawal record not found',
                ];
            }

            // 获取客户
            $customerRepo = $this->entityManager->getRepository(\App\Entity\Customer::class);
            $customer = $customerRepo->find($withdrawalRecord->getUserId());

            if (!$customer) {
                $this->logger->error('客户不存在', ['userId' => $withdrawalRecord->getUserId()]);
                return [
                    'success' => false,
                    'message' => '客户不存在',
                    'messageEn' => 'Customer not found',
                ];
            }

            // 解冻余额
            $withdrawalAmount = abs((float) $withdrawalRecord->getAmount());
            $frozenBalance = (float) ($customer->getFrozenBalance() ?? 0);
            $customer->setFrozenBalance((string) ($frozenBalance - $withdrawalAmount));

            // 更新提现记录
            $withdrawalRecord->setFrozenBalanceAfter((string) ($frozenBalance - $withdrawalAmount));
            $withdrawalRecord->setDescription('Payoneer 提现失败 - ' . ($data['interaction']['reason'] ?? 'Unknown'));

            $this->entityManager->flush();

            $this->logger->warning('Payoneer 提现失败，已解冻余额', [
                'withdrawalNo' => $withdrawalNo,
                'customerId' => $customer->getId(),
                'amount' => $withdrawalAmount,
                'reason' => $data['interaction']['reason'] ?? 'Unknown',
            ]);

            return [
                'success' => true,
                'message' => '提现失败，余额已解冻',
                'messageEn' => 'Withdrawal failed, balance unfrozen',
            ];

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 提现失败处理错误', [
                'withdrawalNo' => $withdrawalNo,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '系统错误',
                'messageEn' => 'System error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 处理提现取消回调
     */
    private function handleWithdrawalCanceled(string $withdrawalNo, array $data): array
    {
        // 提现取消的处理逻辑与失败相同：解冻余额
        return $this->handleWithdrawalFailed($withdrawalNo, $data);
    }

    /**
     * 查询交易状态
     * 
     * 主动查询 Payoneer 订单的支付状态（用于补单、对账）
     * 
     * @param string $transactionId 订单号（你的系统订单号）
     * @return array 返回支付状态详情
     * 
     * @throws \Exception 当API调用失败时
     * 
     * @example
     * $status = $payoneerService->queryPaymentStatus('ORDER_20251120_001');
     * if ($status['status']['code'] === 'charged') {
     *     // 支付成功
     * }
     */
    public function queryPaymentStatus(string $transactionId): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . '/payments/' . $transactionId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ],
            ]);

            $data = $response->toArray();

            $this->logger->info('Payoneer 交易状态查询', [
                'transactionId' => $transactionId,
                'status' => $data['status']['code'] ?? 'unknown',
            ]);

            return $data;

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 交易状态查询失败', [
                'transactionId' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 退款处理
     * 
     * 向 Payoneer 发起退款请求（支持全额退款或部分退款）
     * 
     * ⚠️ 重要：退款必须使用用户支付时的原始币种
     * 
     * @param Order $order 订单对象
     * @param float|null $amount 退款金额（null表示全额退款）
     * @param string $reason 退款原因
     * @return array 返回 ['success' => bool, 'refundReference' => string, 'error' => string]
     * 
     * @throws \Exception 当API调用失败时
     * 
     * @example
     * // 全额退款
     * $result = $payoneerService->refundPayment($order, null, 'Customer request');
     * 
     * // 部分退款
     * $result = $payoneerService->refundPayment($order, 50.00, 'Partial refund');
     */
    public function refundPayment(Order $order, ?float $amount = null, string $reason = 'Refund'): array
    {
        try {
            // 获取订单的支付币种（从支付回调日志中读取，如果没有则使用网站默认币种）
            $callbackLog = json_decode($order->getPaymentCallbackLog() ?? '{}', true);
            $currency = $callbackLog['payment_currency'] ?? $this->siteConfigService->getSiteCurrency();

            // 如果未指定金额，则全额退款
            if ($amount === null) {
                $amount = (float) $order->getTotalAmount();
            }

            $requestData = [
                'amount' => $amount,
                'currency' => $currency,  // ⚠️ 必须使用原始支付币种
                'reason' => $reason,
                'reference' => 'REFUND_' . $order->getOrderNo() . '_' . time(),
            ];

            $response = $this->httpClient->request(
                'POST',
                $this->apiUrl . '/payments/' . $order->getOrderNo() . '/refund',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $requestData,
                ]
            );

            $data = $response->toArray();

            if ($data['status'] === 'Refunded' || $data['status'] === 'Partially Refunded') {
                $this->logger->info('Payoneer 退款成功', [
                    'orderNo' => $order->getOrderNo(),
                    'amount' => $amount,
                    'currency' => $currency,
                    'refundReference' => $data['payment']['refund']['reference'] ?? null,
                ]);

                return [
                    'success' => true,
                    'refundReference' => $data['payment']['refund']['reference'] ?? null,
                    'refundAmount' => $data['payment']['refund']['amount'] ?? $amount,
                    'refundCurrency' => $data['payment']['refund']['currency'] ?? $currency,
                ];
            } else {
                throw new \Exception('退款失败: ' . ($data['resultInfo'] ?? 'Unknown error'));
            }

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 退款失败', [
                'orderNo' => $order->getOrderNo(),
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 验证 Webhook 签名
     * 
     * 使用 HMAC-SHA256 验证 Payoneer Webhook 请求的真实性
     * 
     * @param string $payload 原始请求体
     * @param string $signature HTTP 头部的签名
     * @return bool 验证通过返回 true，否则返回 false
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $calculatedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($calculatedSignature, $signature);
    }

    /**
     * 创建充值会话
     * 
     * 用户向平台账户余额充值，使用 Payoneer 支付
     * 
     * @param mixed $customer 客户对象（Customer 或 Supplier）
     * @param float $amount 充值金额
     * @return array 返回 ['success' => bool, 'paymentUrl' => string, 'sessionId' => string, 'error' => string]
     * 
     * @throws \Exception 当API调用失败时
     * 
     * @example
     * // 客户充值
     * $result = $payoneerService->createRechargeSession($customer, 100.00);
     * 
     * // 供应商充值
     * $result = $payoneerService->createRechargeSession($supplier, 500.00);
     * 
     * if ($result['success']) {
     *     // 跳转到支付页面: $result['paymentUrl']
     * }
     */
    public function createRechargeSession($customer, float $amount): array
    {
        try {
            // 从数据库读取网站支付币种
            $currency = $this->siteConfigService->getSiteCurrency();
            $amountStr = (string) $amount;

            // 生成充值订单号（格式：RCH+年月日+uniqid最后6位大写，如：RCH20251120AB12CD）
            $rechargeNo = 'RCH' . date('Ymd') . strtoupper(substr(uniqid(), -6));
            
            // ⭐ 自动判断回调类型：根据客户类型自动设置
            $callbackType = ($customer instanceof \App\Entity\Customer) 
                ? self::CALLBACK_TYPE_CUSTOMER_RECHARGE 
                : self::CALLBACK_TYPE_SUPPLIER_RECHARGE;

            // 1️⃣ 先创建充值订单（使用 Recharge 实体）
            $recharge = new \App\Entity\Recharge();
            $recharge->setOrderNo($rechargeNo);
            $recharge->setUserType($customer instanceof \App\Entity\Customer ? 'customer' : 'supplier');
            $recharge->setUserId($customer->getId());
            $recharge->setAmount($amountStr);
            $recharge->setPaymentMethod('payoneer');
            $recharge->setStatus('pending');  // 待支付

            $this->entityManager->persist($recharge);
            $this->entityManager->flush();

            // 2️⃣ 构建 Payoneer LIST API 请求参数
            $requestData = [
                'transactionId' => $rechargeNo,  // 使用充值订单号作为交易ID
                'amount' => (float) $amount,
                'currency' => $currency,  // 从数据库读取的币种
                'country' => $this->getCustomerCountryFromCustomer($customer),
                'operationType' => 'CHARGE',  // 收款操作
                'customer' => [
                    'customerNumber' => $customer->getCustomerId() ?? (string) $customer->getId(),
                    'email' => $customer->getEmail(),
                    'firstName' => $customer->getUsername(),
                    'lastName' => '',
                ],
                'notificationURL' => $this->generateWebhookUrl($callbackType),  // ⭐ Webhook 回调地址
                'returnURL' => $this->generateReturnUrl($callbackType, $rechargeNo),  // ⭐ 支付完成跳转
            ];

            // 3️⃣ 调用 Payoneer API
            $response = $this->httpClient->request('POST', $this->apiUrl . '/lists', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode !== 200) {
                // API调用失败，更新订单状态为failed
                $recharge->markAsFailed();
                $recharge->setPaymentCallbackLog(json_encode([
                    'error' => $data['resultInfo'] ?? 'Unknown error',
                    'statusCode' => $statusCode,
                    'timestamp' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE));
                $this->entityManager->flush();

                return [
                    'success' => false,
                    'message' => 'Payoneer API 调用失败',
                    'messageEn' => 'Payoneer API call failed',
                    'error' => $data['resultInfo'] ?? 'Unknown error',
                    'rechargeNo' => $rechargeNo,  // 返回订单号
                ];
            }

            // 验证响应状态
            if ($data['status']['code'] !== 'listed') {
                // API响应失败，更新订单状态为failed
                $recharge->markAsFailed();
                $recharge->setPaymentCallbackLog(json_encode([
                    'error' => $data['status']['reason'] ?? 'Unknown reason',
                    'statusCode' => $data['status']['code'],
                    'timestamp' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE));
                $this->entityManager->flush();

                return [
                    'success' => false,
                    'message' => '创建充值会话失败',
                    'messageEn' => 'Failed to create recharge session',
                    'error' => $data['status']['reason'] ?? 'Unknown reason',
                    'rechargeNo' => $rechargeNo,  // 返回订单号
                ];
            }

            // 4️⃣ API调用成功，更新订单状态为processing
            $recharge->markAsProcessing();
            $recharge->setPaymentCallbackLog(json_encode([
                'sessionId' => $data['identification']['longId'],
                'shortId' => $data['identification']['shortId'] ?? null,
                'createdAt' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE));
            $this->entityManager->flush();

            // 记录日志
            $this->logger->info('Payoneer 充值会话创建成功', [
                'rechargeNo' => $rechargeNo,
                'customerId' => $customer->getId(),
                'sessionId' => $data['identification']['longId'],
                'amount' => $amount,
                'currency' => $currency,
            ]);

            return [
                'success' => true,
                'paymentUrl' => $data['links']['redirect'],  // 支付页面URL
                'sessionId' => $data['identification']['longId'],
                'transactionId' => $data['identification']['transactionId'],
                'rechargeNo' => $rechargeNo,
                'networks' => $data['networks']['applicable'] ?? [],  // 可用支付方式
            ];

        } catch (\Exception $e) {
            // 如果已经创建了订单，更新为failed状态
            if (isset($recharge) && $recharge->getId()) {
                $recharge->markAsFailed();
                $recharge->setPaymentCallbackLog(json_encode([
                    'error' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE));
                $this->entityManager->flush();
            }

            $this->logger->error('Payoneer 充值会话创建失败', [
                'customerId' => $customer->getId(),
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'System error / 系统错误: ' . $e->getMessage(),
                'rechargeNo' => $rechargeNo ?? null,  // 尽量返回订单号
            ];
        }
    }

    /**
     * 处理充值成功回调
     * 
     * 当 Payoneer 充值支付成功后，更新用户余额
     * 
     * @param string $rechargeNo 充值订单号
     * @param array $data Webhook 回调数据
     * @return array 返回 ['success' => bool, 'message' => string]
     */
    public function handleRechargeSuccess(string $rechargeNo, array $data): array
    {
        try {
            // 1️⃣ 查找充值订单（Recharge 表）
            $rechargeRepo = $this->entityManager->getRepository(\App\Entity\Recharge::class);
            $recharge = $rechargeRepo->findOneBy(['orderNo' => $rechargeNo]);

            if (!$recharge) {
                $this->logger->error('充值订单不存在', ['rechargeNo' => $rechargeNo]);
                return [
                    'success' => false,
                    'message' => '充值订单不存在',
                    'messageEn' => 'Recharge order not found',
                ];
            }

            // 2️⃣ 检查是否已经处理过
            if ($recharge->isCompleted()) {
                $this->logger->warning('充值已处理，避免重复', ['rechargeNo' => $rechargeNo]);
                return [
                    'success' => true,
                    'message' => '充值已处理',
                    'messageEn' => 'Recharge already processed',
                ];
            }

            // 3️⃣ 获取客户
            $customerRepo = $this->entityManager->getRepository(\App\Entity\Customer::class);
            $customer = $customerRepo->find($recharge->getUserId());

            if (!$customer) {
                $this->logger->error('客户不存在', ['userId' => $recharge->getUserId()]);
                return [
                    'success' => false,
                    'message' => '客户不存在',
                    'messageEn' => 'Customer not found',
                ];
            }

            // 4️⃣ 获取实际到账金额
            $settledAmount = (float) ($data['payment']['clearing']['amount'] ?? $data['payment']['amount']);
            $settledCurrency = $data['payment']['clearing']['currency'] ?? $data['payment']['currency'];
            $paymentReference = $data['payment']['reference'] ?? null;
            $settledAmountStr = (string) $settledAmount;

            // 5️⃣ 使用金融服务更新客户余额
            $balanceBefore = $customer->getBalance();
            $frozenBalanceBefore = $customer->getFrozenBalance() ?? '0.00';
            
            // 使用金融计算器进行精确计算
            $balanceAfter = $this->financialCalculator->add($balanceBefore, $settledAmountStr);  // 余额增加
            
            $customer->setBalance($balanceAfter);

            // 6️⃣ 使用 BalanceHistoryService 创建余额历史记录（充值成功后才创建）
            $balanceHistory = $this->balanceHistoryService->createBalanceHistory(
                userType: $recharge->getUserType(),
                userId: $recharge->getUserId(),
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                amount: $settledAmountStr,  // 正数表示增加
                frozenBalanceBefore: $frozenBalanceBefore,
                frozenBalanceAfter: $frozenBalanceBefore,  // 冻结余额不变
                frozenAmount: '0.00',  // 冻结金额不变
                type: 'recharge',
                description: 'Payoneer 充值成功 - ' . $paymentReference,
                referenceId: $rechargeNo
            );

            // 7️⃣ 更新充值订单状态
            $recharge->markAsCompleted();
            $recharge->setPaymentTransactionId($paymentReference);
            $recharge->setBalanceHistoryId($balanceHistory->getId());  // 关联余额历史记录ID
            
            // 记录支付回调日志
            $callbackLog = [
                'paymentReference' => $paymentReference,
                'settledAmount' => $settledAmount,
                'settledCurrency' => $settledCurrency,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            $recharge->setPaymentCallbackLog(json_encode($callbackLog, JSON_UNESCAPED_UNICODE));

            $this->entityManager->flush();

            $this->logger->info('Payoneer 充值成功处理完成', [
                'rechargeNo' => $rechargeNo,
                'customerId' => $customer->getId(),
                'amount' => $settledAmount,
                'currency' => $settledCurrency,
                'balanceBefore' => $balanceBefore,
                'balanceAfter' => $balanceAfter,
                'paymentReference' => $paymentReference,
                'balanceHistoryId' => $balanceHistory->getId(),
            ]);

            return ['success' => true, 'message' => '充值处理成功', 'messageEn' => 'Recharge processed successfully'];

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 充值处理失败', [
                'rechargeNo' => $rechargeNo,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'System error / 系统错误: ' . $e->getMessage()];
        }
    }

    /**
     * 创建提现请求（使用 Payoneer Payout API）
     * 
     * 用户从平台账户余额提现到银行卡或支付账户
     * 
     * ⚠️ 重要：提现需要使用 Payoneer Payout API，而非 Checkout API
     * ⚠️ 流程：先生成提现记录 (Withdrawal) → 冻结余额 → 调用 Payoneer API → 等待回调
     * 
     * @param mixed $customer 客户对象（Customer 或 Supplier）
     * @param float $amount 提现金额
     * @param array $payoutInfo 提现信息 ['accountType' => 'bank', 'accountNumber' => '', 'accountName' => '', 'bankName' => '']
     * @return array 返回 ['success' => bool, 'withdrawalNo' => string, 'withdrawalId' => int, 'error' => string]
     * 
     * @throws \Exception 当API调用失败或余额不足时
     * 
     * @example
     * $result = $payoneerService->createWithdrawal($customer, 100.00, [
     *     'accountType' => 'bank',  // bank-银行账户, alipay-支付宝, wechat-微信
     *     'accountNumber' => '6222021234567890',
     *     'accountName' => 'John Doe',
     *     'bankName' => 'Bank of America'
     * ]);
     */
    public function createWithdrawal($customer, float $amount, array $payoutInfo): array
    {
        try {
            // 1️⃣ 使用金融服务验证余额是否足够
            $balance = $customer->getBalance();
            $amountStr = (string) $amount;
            
            if (!$this->financialCalculator->isSufficient($balance, $amountStr)) {
                return [
                    'success' => false,
                    'message' => '余额不足',
                    'messageEn' => 'Insufficient balance',
                    'currentBalance' => $balance,
                ];
            }

            // 2️⃣ 从数据库读取网站币种
            $currency = $this->siteConfigService->getSiteCurrency();

            // 3️⃣ 生成提现订单号（格式：WIT+年月日+uniqid最后6位大写，如：WIT20251121AB12CD）
            $withdrawalNo = 'WIT' . date('Ymd') . strtoupper(substr(uniqid(), -6));
            
            // ⭐ 自动判断回调类型：根据客户类型自动设置
            $callbackType = ($customer instanceof \App\Entity\Customer) 
                ? self::CALLBACK_TYPE_CUSTOMER_WITHDRAWAL 
                : self::CALLBACK_TYPE_SUPPLIER_WITHDRAWAL;

            // 4️⃣ 先创建提现记录（使用 Withdrawal 实体）
            $withdrawal = new \App\Entity\Withdrawal();
            $withdrawal->setOrderNo($withdrawalNo);
            $withdrawal->setUserType($customer instanceof \App\Entity\Customer ? 'customer' : 'supplier');
            $withdrawal->setUserId($customer->getId());
            $withdrawal->setAmount($amountStr);
            $withdrawal->setWithdrawalMethod('payoneer');
            $withdrawal->setStatus('pending');  // 待处理
            $withdrawal->setPaymentInfo(json_encode($payoutInfo, JSON_UNESCAPED_UNICODE));

            $this->entityManager->persist($withdrawal);
            $this->entityManager->flush();

            // 5️⃣ 使用金融服务提前扣减可用余额，转入冻结余额（重要：先扣减再提现）
            $balanceBefore = $customer->getBalance();
            
            // ⭐ 根据实体类型获取冻结余额（Supplier 和 Customer 字段名不同）
            if ($customer instanceof \App\Entity\Supplier) {
                $frozenBalanceBefore = $customer->getBalanceFrozen() ?? '0.00';
            } else {
                $frozenBalanceBefore = $customer->getFrozenBalance() ?? '0.00';
            }
            
            // 使用金融计算器进行精确计算
            $balanceAfter = $this->financialCalculator->subtract($balanceBefore, $amountStr);  // 余额减少
            $frozenBalanceAfter = $this->financialCalculator->add($frozenBalanceBefore, $amountStr);  // 冻结余额增加
            
            $customer->setBalance($balanceAfter);
            
            // ⭐ 根据实体类型设置冻结余额
            if ($customer instanceof \App\Entity\Supplier) {
                $customer->setBalanceFrozen($frozenBalanceAfter);
            } else {
                $customer->setFrozenBalance($frozenBalanceAfter);
            }

            // 6️⃣ 使用 BalanceHistoryService 生成余额记录（可用余额转冻结余额）
            $balanceHistory = $this->balanceHistoryService->createBalanceHistory(
                userType: $withdrawal->getUserType(),
                userId: $customer->getId(),
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                amount: '-' . $amountStr,  // 负数表示减少
                frozenBalanceBefore: $frozenBalanceBefore,
                frozenBalanceAfter: $frozenBalanceAfter,
                frozenAmount: $amountStr,  // 冻结金额增加
                type: 'withdraw_freeze',  // 提现冻结
                description: 'Payoneer 提现 - 余额冻绗',
                referenceId: $withdrawalNo
            );

            // 关联余额历史记录
            $withdrawal->setBalanceHistoryId($balanceHistory->getId());
            $this->entityManager->flush();

            // 7️⃣ 构建 Payoneer Payout API 请求参数
            $requestData = [
                'transactionId' => $withdrawalNo,
                'amount' => (float) $amount,
                'currency' => $currency,
                'operationType' => 'PAYOUT',  // ⭐ 提现操作
                'customer' => [
                    'customerNumber' => $customer->getCustomerId() ?? (string) $customer->getId(),
                    'email' => $customer->getEmail(),
                    'firstName' => $customer->getUsername(),
                    'lastName' => '',
                ],
                'account' => [
                    'number' => $payoutInfo['accountNumber'],  // Payoneer 账号
                ],
                'notificationURL' => $this->generateWebhookUrl($callbackType),  // ⭐ Webhook 回调地址
            ];

            // 8️⃣ 调用 Payoneer Payout API
            $response = $this->httpClient->request('POST', $this->apiUrl . '/payouts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode !== 200) {
                // ⚠️ API调用失败，需要回滚余额（从冻结余额返回可用余额）
                $this->handleWithdrawalFailure($withdrawal, $customer, $amountStr, 'Payoneer API 调用失败: ' . ($data['resultInfo'] ?? 'Unknown error'));
                
                return [
                    'success' => false,
                    'message' => 'Payoneer Payout API 调用失败',
                    'messageEn' => 'Payoneer Payout API call failed',
                    'error' => $data['resultInfo'] ?? 'Unknown error',
                    'withdrawalNo' => $withdrawalNo,
                ];
            }

            // 9️⃣ 更新提现记录状态为 approved（已通过，等待 Payoneer 回调）
            $withdrawal->setStatus('approved');
            $withdrawal->setPaymentTransactionId($data['identification']['longId'] ?? null);
            $withdrawal->setPaymentCallbackLog(json_encode([
                'payoutId' => $data['identification']['longId'] ?? null,
                'status' => $data['status']['code'] ?? 'pending',
                'createdAt' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE));
            $this->entityManager->flush();

            // 🔟 记录日志
            $this->logger->info('Payoneer 提现请求创建成功', [
                'withdrawalNo' => $withdrawalNo,
                'customerId' => $customer->getId(),
                'amount' => $amount,
                'currency' => $currency,
                'payoutId' => $data['identification']['longId'] ?? null,
                'balanceBefore' => $balanceBefore,
                'balanceAfter' => $balanceAfter,
                'frozenBalanceAfter' => $frozenBalanceAfter,
            ]);

            return [
                'success' => true,
                'withdrawalNo' => $withdrawalNo,
                'withdrawalId' => $withdrawal->getId(),
                'payoutId' => $data['identification']['longId'] ?? null,
                'status' => $data['status']['code'] ?? 'pending',
            ];

        } catch (\Exception $e) {
            // ⚠️ 系统异常，回滚余额
            if (isset($withdrawal) && $withdrawal->getId() && isset($amountStr)) {
                $this->handleWithdrawalFailure($withdrawal, $customer, $amountStr, '系统错误: ' . $e->getMessage());
            }

            $this->logger->error('Payoneer 提现请求创建失败', [
                'customerId' => $customer->getId(),
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'System error / 系统错误: ' . $e->getMessage(),
                'withdrawalNo' => $withdrawalNo ?? null,
            ];
        }
    }

    /**
     * 查询提现状态
     * 
     * 主动查询 Payoneer 提现订单的状态（用于补单、对账）
     * 
     * @param string $withdrawalNo 提现订单号
     * @return array 返回提现状态详情
     * 
     * @throws \Exception 当API调用失败时
     * 
     * @example
     * $status = $payoneerService->queryWithdrawalStatus('WITHDRAW_20251120_001');
     * if ($status['status']['code'] === 'completed') {
     *     // 提现成功
     * }
     */
    public function queryWithdrawalStatus(string $withdrawalNo): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . '/payouts/' . $withdrawalNo, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ],
            ]);

            $data = $response->toArray();

            $this->logger->info('Payoneer 提现状态查询', [
                'withdrawalNo' => $withdrawalNo,
                'status' => $data['status']['code'] ?? 'unknown',
            ]);

            return $data;

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 提现状态查询失败', [
                'withdrawalNo' => $withdrawalNo,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 处理提现成功回调
     * 
     * 当 Payoneer 提现成功后，扣除冻结余额
     * 
     * @param string $withdrawalNo 提现订单号
     * @param array $data Webhook 回调数据
     * @return array 返回 ['success' => bool, 'message' => string]
     */
    public function handleWithdrawalSuccess(string $withdrawalNo, array $data): array
    {
        try {
            // 1️⃣ 查找提现记录（使用 Withdrawal 实体）
            $withdrawalRepo = $this->entityManager->getRepository(\App\Entity\Withdrawal::class);
            $withdrawal = $withdrawalRepo->findOneBy([
                'orderNo' => $withdrawalNo,
            ]);

            if (!$withdrawal) {
                $this->logger->error('提现记录不存在', ['withdrawalNo' => $withdrawalNo]);
                return ['success' => false, 'message' => 'Withdrawal record not found / 提现记录不存在: ' . $withdrawalNo];
            }

            // 2️⃣ 检查是否已经处理过（幂等性检查）
            // ⭐ 即使提现已处理，也必须返回成功，否则 Payoneer 会认为通知失败而不断重试
            if ($withdrawal->getStatus() === 'completed') {
                $this->logger->warning('提现已处理，避免重复', ['withdrawalNo' => $withdrawalNo]);
                return [
                    'success' => true,
                    'message' => '提现已处理',
                    'messageEn' => 'Withdrawal already processed',
                ];
            }

            // 3️⃣ 获取用户（支持 Customer 和 Supplier）
            $userType = $withdrawal->getUserType();
            $userId = $withdrawal->getUserId();
            
            if ($userType === 'customer') {
                $userRepo = $this->entityManager->getRepository(\App\Entity\Customer::class);
            } else {
                $userRepo = $this->entityManager->getRepository(\App\Entity\Supplier::class);
            }
            
            $user = $userRepo->find($userId);

            if (!$user) {
                $this->logger->error('用户不存在', ['userType' => $userType, 'userId' => $userId]);
                return ['success' => false, 'message' => 'User not found / 用户不存在: ' . $userId];
            }

            // 4️⃣ 获取提现金额和支付信息
            $withdrawalAmount = $withdrawal->getAmount();  // 已经是字符串格式
            $payoutReference = $data['payout']['reference'] ?? null;

            // 5️⃣ 使用金融服务更新用户余额和冻结余额（从冻结余额中扣除）
            $balanceBefore = $user->getBalance();
            
            // ⭐ 根据实体类型获取冻结余额
            if ($user instanceof \App\Entity\Supplier) {
                $frozenBalanceBefore = $user->getBalanceFrozen() ?? '0.00';
            } else {
                $frozenBalanceBefore = $user->getFrozenBalance() ?? '0.00';
            }
            
            // 使用金融计算器从冻结余额中扣除提现金额
            $frozenBalanceAfter = $this->financialCalculator->subtract($frozenBalanceBefore, $withdrawalAmount);
            
            // ⭐ 根据实体类型设置冻结余额
            if ($user instanceof \App\Entity\Supplier) {
                $user->setBalanceFrozen($frozenBalanceAfter);
            } else {
                $user->setFrozenBalance($frozenBalanceAfter);
            }

            // 6️⃣ 使用 BalanceHistoryService 生成余额记录（冻绗余额划转）
            $this->balanceHistoryService->createBalanceHistory(
                userType: $withdrawal->getUserType(),
                userId: $user->getId(),
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceBefore,  // 余额不变
                amount: '0.00',  // 可用余额不变
                frozenBalanceBefore: $frozenBalanceBefore,
                frozenBalanceAfter: $frozenBalanceAfter,
                frozenAmount: '-' . $withdrawalAmount,  // 冻绗余额减少
                type: 'withdraw_success',  // 提现成功
                description: 'Payoneer 提现成功 - ' . $payoutReference,
                referenceId: $withdrawalNo
            );

            // 7️⃣ 更新 Withdrawal 记录状态
            $withdrawal->setStatus('completed');
            $withdrawal->setPaymentTransactionId($payoutReference);
            $withdrawal->setReviewedAt(new \DateTime());

            $this->entityManager->flush();

            $this->logger->info('Payoneer 提现成功处理完成', [
                'withdrawalNo' => $withdrawalNo,
                'userType' => $userType,
                'userId' => $userId,
                'amount' => $withdrawalAmount,
                'balanceBefore' => $balanceBefore,
                'frozenBalanceBefore' => $frozenBalanceBefore,
                'frozenBalanceAfter' => $frozenBalanceAfter,
                'payoutReference' => $payoutReference,
            ]);

            return ['success' => true, 'message' => '提现处理成功', 'messageEn' => 'Withdrawal processed successfully'];

        } catch (\Exception $e) {
            $this->logger->error('Payoneer 提现处理失败', [
                'withdrawalNo' => $withdrawalNo,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'System error / 系统错误: ' . $e->getMessage()];
        }
    }

    /**
     * 获取支持的支付方式列表
     * 
     * ⚠️ 注意：此方法返回的是预定义的支付方式列表，仅用于前端展示图标
     * 实际可用的支付方式由 Payoneer 在创建支付会话时动态返回（networks.applicable 字段）
     * 
     * 真实的支付方式获取流程：
     * 1. 调用 createPaymentSession() 创建支付会话
     * 2. 从返回结果的 networks.applicable 字段获取实际可用的支付方式
     * 3. 根据用户的币种、国家、订单金额等因素，Payoneer 会返回不同的支付方式
     * 
     * @param string $currency 币种代码（如 USD, EUR）
     * @param string $country 国家代码（如 US, GB）
     * @return array 预定义的支付方式列表（仅用于前端展示参考）
     */
    public function getAvailablePaymentMethods(string $currency, string $country): array
    {
        // ⚠️ 此列表仅用于前端展示图标，不代表用户实际可用的支付方式
        // 实际可用的支付方式需要从 createPaymentSession() 的返回结果中获取
        
        // 基础支付方式（全球通用）
        $methods = [
            [
                'code' => 'CREDIT_CARD',
                'name' => '信用卡/借记卡',
                'description' => 'Visa, Mastercard, American Express, Discover',
                'brands' => ['VISA', 'MASTERCARD', 'AMEX', 'DISCOVER'],
                'icon' => '/images/payment/credit-card.png',
                'note' => '实际支持的卡组织由 Payoneer 根据订单信息动态确定',
            ],
            [
                'code' => 'PAYPAL',
                'name' => 'PayPal',
                'description' => 'PayPal 账户支付',
                'icon' => '/images/payment/paypal.png',
                'note' => '需要 PayPal 账户',
            ],
            [
                'code' => 'APPLE_PAY',
                'name' => 'Apple Pay',
                'description' => '苹果支付（需要 Safari 浏览器或 iOS 设备）',
                'icon' => '/images/payment/apple-pay.png',
                'note' => '仅在支持的设备和浏览器上可用',
            ],
            [
                'code' => 'GOOGLE_PAY',
                'name' => 'Google Pay',
                'description' => '谷歌支付（需要 Chrome 浏览器或 Android 设备）',
                'icon' => '/images/payment/google-pay.png',
                'note' => '仅在支持的设备和浏览器上可用',
            ],
        ];

        // 根据国家添加本地支付方式
        $localMethods = $this->getLocalPaymentMethods($country);
        $methods = array_merge($methods, $localMethods);

        return [
            'methods' => $methods,
            'note' => '⚠️ 此列表为预定义的支付方式参考，实际可用的支付方式需要在创建支付会话后从 Payoneer 返回的 networks.applicable 字段中获取',
            'usage' => '调用 createPaymentSession() 后，从返回结果的 networks 字段获取实际可用的支付方式',
        ];
    }

    // ==================== 私有方法 ====================

    /**
     * 处理支付成功回调
     */
    private function handlePaymentCaptured(Order $order, array $data): array
    {
        $paymentData = $data['payment'] ?? [];
        $clearingData = $paymentData['clearing'] ?? [];

        // 获取支付信息
        $paymentReference = $paymentData['reference'] ?? null;
        $paymentAmount = $paymentData['amount'] ?? 0;
        $paymentCurrency = $paymentData['currency'] ?? '';
        
        // 获取结算信息
        $settledAmount = $clearingData['amount'] ?? $paymentAmount;
        $settledCurrency = $clearingData['currency'] ?? $paymentCurrency;
        $exchangeRate = $clearingData['fxRate'] ?? 1.0;

        // 获取支付方式和卡信息
        $paymentMethod = $paymentData['method'] ?? '';
        $account = $data['account'] ?? [];
        $cardBrand = $account['brand'] ?? null;
        $cardNumber = $account['number'] ?? null;

        // 4. 检查订单是否已经支付（幂等性检查）
        // ⭐ 即使订单已处理，也必须返回成功，否则 Payoneer 会认为通知失败而不断重试
        if ($order->getPaymentStatus() === 'paid') {
            $this->logger->warning('Payoneer 订单已支付，忽略重复回调', [
                'orderNo' => $order->getOrderNo(),
            ]);
            return [
                'success' => true,
                'message' => '订单已处理',
                'messageEn' => 'Order already processed'
            ];
        }

        // 5. 验证支付金额
        $orderAmount = (float)$order->getTotalAmount();
        $callbackAmount = (float)$settledAmount; // 使用结算金额
        
        // 允许小数点误差（0.01以内）
        if (abs($orderAmount - $callbackAmount) > 0.01) {
            $this->logger->error('Payoneer 支付金额不匹配', [
                'orderNo' => $order->getOrderNo(),
                'order_amount' => $orderAmount,
                'payment_amount' => $callbackAmount,
            ]);
            return [
                'success' => false,
                'message' => '支付金额不匹配',
                'messageEn' => 'Payment amount mismatch',
                'data' => [
                    'order_amount' => $orderAmount,
                    'payment_amount' => $callbackAmount
                ]
            ];
        }

        // 6. 开启数据库事务
        $this->entityManager->getConnection()->beginTransaction();
        
        try {
            $customer = $order->getCustomer();
            
            // 7. 记录用户在线支付的余额历史（余额实际不变，但记录支付金额用于统计）
            $currentBalance = $customer->getBalance();
            $totalAmount = $order->getTotalAmount();
            
            // 获取 BalanceHistoryService
            $balanceHistoryService = $this->entityManager->getRepository(\App\Entity\BalanceHistory::class);
            
            // 创建余额历史记录
            $balanceHistory = new \App\Entity\BalanceHistory();
            $balanceHistory->setUserType('customer');
            $balanceHistory->setUserId($customer->getId());
            $balanceHistory->setBalanceBefore($currentBalance);
            $balanceHistory->setBalanceAfter($currentBalance); // 余额不变
            $balanceHistory->setAmount((string)(-$totalAmount)); // 金额变化为负的订单总额
            $balanceHistory->setFrozenBalanceBefore($customer->getFrozenBalance());
            $balanceHistory->setFrozenBalanceAfter($customer->getFrozenBalance());
            $balanceHistory->setFrozenAmount('0.00');
            $balanceHistory->setType('online_payment'); // 使用在线支付类型
            $balanceHistory->setDescription(
                "在线支付：{$order->getOrderNo()} | 支付方式：payoneer | 支付金额：{$settledAmount} | 交易号：{$paymentReference}"
            );
            $balanceHistory->setReferenceId($order->getOrderNo());
            
            $this->entityManager->persist($balanceHistory);
            
            // 8. 处理支付和冻结供应商余额
            foreach ($order->getItems() as $orderItem) {
                $this->orderItemStatusService->confirmPayment($orderItem);
            }
            
            // 9. 更新订单支付信息
            $order->setPaymentMethod('payoneer');
            $order->setPaymentStatus('paid');
            $order->setPaymentTime(new \DateTime());
            $order->setPaymentTransactionId($paymentReference);
            
            // 记录支付回调日志
            $callbackLogEntry = sprintf(
                "[%s] 支付回调成功 | 支付方式: payoneer | 交易号: %s | 金额: %s %s",
                date('Y-m-d H:i:s'),
                $paymentReference,
                $settledAmount,
                $settledCurrency
            );
            
            $currentLog = $order->getPaymentCallbackLog();
            $newLog = $currentLog ? $currentLog . "\n" . $callbackLogEntry : $callbackLogEntry;
            $order->setPaymentCallbackLog($newLog);
            
            $this->entityManager->flush();
            $this->entityManager->getConnection()->commit();

            $this->logger->info('Payoneer 支付成功处理完成', [
                'orderNo' => $order->getOrderNo(),
                'paymentReference' => $paymentReference,
                'paymentAmount' => $paymentAmount,
                'paymentCurrency' => $paymentCurrency,
                'settledAmount' => $settledAmount,
                'settledCurrency' => $settledCurrency,
            ]);

            return [
                'success' => true,
                'message' => '支付回调处理成功',
                'messageEn' => 'Payment callback processed successfully'
            ];
            
        } catch (\Exception $e) {
            $this->entityManager->getConnection()->rollBack();
            $this->logger->error('Payoneer 支付处理事务失败', [
                'orderNo' => $order->getOrderNo(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 处理支付失败回调
     */
    private function handlePaymentFailed(Order $order, array $data): array
    {
        $reason = $data['interaction']['reason'] ?? 'Unknown error';

        // 记录失败日志到支付回调日志字段
        $callbackLog = json_decode($order->getPaymentCallbackLog() ?? '{}', true);
        if (!isset($callbackLog['failures'])) {
            $callbackLog['failures'] = [];
        }
        $callbackLog['failures'][] = [
            'status' => 'failed',
            'reason' => $reason,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $order->setPaymentCallbackLog(json_encode($callbackLog, JSON_UNESCAPED_UNICODE));

        $this->entityManager->flush();

        $this->logger->warning('Payoneer 支付失败', [
            'orderNo' => $order->getOrderNo(),
            'reason' => $reason,
        ]);

        return ['success' => true, 'message' => 'Payment failed logged'];
    }

    /**
     * 处理用户取消支付回调
     */
    private function handlePaymentCanceled(Order $order, array $data): array
    {
        // 记录取消日志到支付回调日志字段
        $callbackLog = json_decode($order->getPaymentCallbackLog() ?? '{}', true);
        $callbackLog['canceled'] = [
            'status' => 'canceled',
            'reason' => 'User canceled payment',
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $order->setPaymentCallbackLog(json_encode($callbackLog, JSON_UNESCAPED_UNICODE));

        $this->entityManager->flush();

        $this->logger->info('Payoneer 支付已取消', [
            'orderNo' => $order->getOrderNo(),
        ]);

        return ['success' => true, 'message' => 'Payment canceled logged'];
    }

    /**
     * 处理退款回调
     */
    private function handlePaymentRefunded(Order $order, array $data): array
    {
        $refundData = $data['payment']['refund'] ?? [];
        $refundAmount = $refundData['amount'] ?? 0;
        $refundCurrency = $refundData['currency'] ?? '';
        $refundReference = $refundData['reference'] ?? null;

        // ⭐ 更新订单退款状态（调用现有的退款服务）
        // 例如：$this->refundService->handleRefund($order, $refundAmount, $refundReference);

        $this->logger->info('Payoneer 退款处理完成', [
            'orderNo' => $order->getOrderNo(),
            'refundAmount' => $refundAmount,
            'refundCurrency' => $refundCurrency,
            'refundReference' => $refundReference,
        ]);

        return ['success' => true, 'message' => 'Refund processed'];
    }

    /**
     * 处理争议/退单回调
     */
    private function handlePaymentDispute(Order $order, array $data): array
    {
        $status = $data['status'] ?? '';
        $disputeData = $data['payment']['dispute'] ?? [];

        // 记录争议信息到支付回调日志字段
        $callbackLog = json_decode($order->getPaymentCallbackLog() ?? '{}', true);
        $callbackLog['dispute'] = [
            'status' => $status === 'In dispute' ? 'pending' : 'lost',
            'disputeId' => $disputeData['id'] ?? null,
            'reason' => $disputeData['reason'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $order->setPaymentCallbackLog(json_encode($callbackLog, JSON_UNESCAPED_UNICODE));

        $this->entityManager->flush();

        $this->logger->warning('Payoneer 支付争议', [
            'orderNo' => $order->getOrderNo(),
            'status' => $status,
            'disputeId' => $disputeData['id'] ?? null,
        ]);

        return ['success' => true, 'message' => 'Dispute logged'];
    }

    /**
     * 处理用户注册支付方式回调
     */
    private function handleCustomerRegistered(Order $order, array $data): array
    {
        $customerData = $data['customer'] ?? [];
        $registrationId = $customerData['registration']['id'] ?? null;

        if ($registrationId) {
            // 保存用户的 Payoneer 注册ID（用于快速支付）
            $customer = $order->getCustomer();
            // $customer->setPayoneerRegistrationId($registrationId);
            // $this->entityManager->flush();

            $this->logger->info('Payoneer 用户注册支付方式', [
                'customerId' => $customer->getId(),
                'registrationId' => $registrationId,
            ]);
        }

        return ['success' => true, 'message' => 'Customer registered'];
    }

    /**
     * 获取客户国家代码（从订单）
     */
    private function getCustomerCountry(Order $order): string
    {
        // 从网站配置中读取国家代码
        // configKey='site_currency' 的 configValue2 字段存储国家代码（符合 ISO 3166-1 标准）
        return $this->siteConfigService->getSiteCountry();
    }

    /**
     * 获取客户国家代码（从客户信息）
     */
    private function getCustomerCountryFromCustomer($customer): string
    {
        // 从网站配置中读取国家代码
        // configKey='site_currency' 的 configValue2 字段存储国家代码（符合 ISO 3166-1 标准）
        return $this->siteConfigService->getSiteCountry();
    }

    /**
     * 获取本地支付方式（预定义列表，仅供参考）
     * 
     * ⚠️ 注意：这是预定义的本地支付方式参考列表
     * 实际可用的本地支付方式由 Payoneer 根据用户的国家、币种、订单金额等动态确定
     * 
     * @param string $country 国家代码
     * @return array 预定义的本地支付方式列表
     */
    private function getLocalPaymentMethods(string $country): array
    {
        // ⚠️ 预定义的本地支付方式参考列表
        // 实际可用的支付方式需要从 Payoneer API 的 networks.applicable 字段中获取
        $localMethods = [
            'DE' => [  // 德国
                [
                    'code' => 'SOFORT',
                    'name' => 'Sofort',
                    'description' => '德国在线银行转账',
                    'icon' => '/images/payment/sofort.png',
                    'note' => '需要德国银行账户',
                ],
                [
                    'code' => 'GIROPAY',
                    'name' => 'Giropay',
                    'description' => '德国在线支付',
                    'icon' => '/images/payment/giropay.png',
                    'note' => '需要德国银行账户',
                ],
            ],
            'NL' => [  // 荷兰
                [
                    'code' => 'IDEAL',
                    'name' => 'iDEAL',
                    'description' => '荷兰在线银行支付',
                    'icon' => '/images/payment/ideal.png',
                    'note' => '需要荷兰银行账户',
                ],
            ],
            'BE' => [  // 比利时
                [
                    'code' => 'BANCONTACT',
                    'name' => 'Bancontact',
                    'description' => '比利时在线支付',
                    'icon' => '/images/payment/bancontact.png',
                    'note' => '需要比利时银行账户',
                ],
            ],
            'PL' => [  // 波兰
                [
                    'code' => 'PRZELEWY24',
                    'name' => 'Przelewy24',
                    'description' => '波兰在线支付',
                    'icon' => '/images/payment/przelewy24.png',
                    'note' => '需要波兰银行账户',
                ],
            ],
            'CN' => [  // 中国
                [
                    'code' => 'ALIPAY',
                    'name' => '支付宝',
                    'description' => '支付宝账户支付',
                    'icon' => '/images/payment/alipay.png',
                    'note' => '需要支付宝账户',
                ],
                [
                    'code' => 'WECHAT_PAY',
                    'name' => '微信支付',
                    'description' => '微信账户支付',
                    'icon' => '/images/payment/wechat.png',
                    'note' => '需要微信账户',
                ],
            ],
        ];

        return $localMethods[$country] ?? [];
    }

    /**
     * 处理提现失败（回滚余额）
     * 
     * 当提现API调用失败或系统异常时，需要将冻结余额返回可用余额
     * 
     * @param \App\Entity\Withdrawal $withdrawal 提现记录
     * @param mixed $customer 用户实体（Customer 或 Supplier）
     * @param string $amount 提现金额（字符串格式）
     * @param string $errorMessage 错误信息
     * @return void
     */
    private function handleWithdrawalFailure(\App\Entity\Withdrawal $withdrawal, $customer, string $amount, string $errorMessage): void
    {
        try {
            // 1️⃣ 更新提现记录状态为 rejected
            $withdrawal->setStatus('rejected');
            $withdrawal->setRemark($errorMessage);
            $withdrawal->setPaymentCallbackLog(json_encode([
                'error' => $errorMessage,
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE));

            // 2️⃣ 使用金融服务回滚余额：从冻结余额返回可用余额
            $balanceBefore = $customer->getBalance();
            
            // ⭐ 根据实体类型获取冻结余额
            if ($customer instanceof \App\Entity\Supplier) {
                $frozenBalanceBefore = $customer->getBalanceFrozen() ?? '0.00';
            } else {
                $frozenBalanceBefore = $customer->getFrozenBalance() ?? '0.00';
            }
            
            // 使用金融计算器进行精确计算
            $balanceAfter = $this->financialCalculator->add($balanceBefore, $amount);  // 余额增加
            $frozenBalanceAfter = $this->financialCalculator->subtract($frozenBalanceBefore, $amount);  // 冻结余额减少
            
            $customer->setBalance($balanceAfter);
            
            // ⭐ 根据实体类型设置冻结余额
            if ($customer instanceof \App\Entity\Supplier) {
                $customer->setBalanceFrozen($frozenBalanceAfter);
            } else {
                $customer->setFrozenBalance($frozenBalanceAfter);
            }

            // 3️⃣ 使用 BalanceHistoryService 生成余额记录（冻结余额返回可用余额）
            $this->balanceHistoryService->createBalanceHistory(
                userType: $withdrawal->getUserType(),
                userId: $customer->getId(),
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                amount: $amount,  // 正数表示增加
                frozenBalanceBefore: $frozenBalanceBefore,
                frozenBalanceAfter: $frozenBalanceAfter,
                frozenAmount: '-' . $amount,  // 冻结金额减少
                type: 'withdraw_refund',  // 提现退款
                description: 'Payoneer 提现失败 - 余额退回',
                referenceId: $withdrawal->getOrderNo()
            );

            $this->entityManager->flush();

            $this->logger->warning('Payoneer 提现失败，余额已退回', [
                'withdrawalNo' => $withdrawal->getOrderNo(),
                'customerId' => $customer->getId(),
                'amount' => $amount,
                'error' => $errorMessage,
                'balanceAfter' => $balanceAfter,
                'frozenBalanceAfter' => $frozenBalanceAfter,
            ]);

        } catch (\Exception $e) {
            // 如果回滚失败，记录严重错误
            $this->logger->error('提现失败回滚余额异常', [
                'withdrawalNo' => $withdrawal->getOrderNo(),
                'customerId' => $customer->getId(),
                'amount' => $amount,
                'originalError' => $errorMessage,
                'rollbackError' => $e->getMessage(),
            ]);
        }
    }
}
