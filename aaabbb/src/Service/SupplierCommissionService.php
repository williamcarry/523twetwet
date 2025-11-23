<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Supplier;

/**
 * 供应商佣金计算服务
 * 
 * 佣金计算规则说明：
 * ==================
 * 
 * 1. 佣金基数（Commission Base）
 *    - 佣金基数 = 商品支付价格 - 运费
 *    - 商品支付价格：用户实际支付的金额（包含运费）
 *    - 运费：从商品支付价格中扣除，不作为佣金计算基数
 *    - 说明：只对纯商品金额计算佣金，运费不参与佣金计算
 * 
 * 2. 佣金率优先级（Commission Rate Priority）
 *    优先级从高到低：
 *    
 *    (1) 会员免佣金（最高优先级）
 *        - 如果供应商是活跃会员（会员有效期内），免收佣金（佣金率=0）
 *        - 会员类型：月会员(monthly)、季度会员(quarterly)、半年会员(half_yearly)、年会员(yearly)
 *        - 判断条件：membershipType != 'none' && membershipExpiredAt > 当前时间
 *        - 返回佣金率：0.0
 *    
 *    (2) 自定义佣金比例
 *        - 如果供应商不是会员，且设置了自定义佣金比例 > 0，使用自定义比例
 *        - 存储字段：supplier.commission_rate（小数形式，如 0.1000 表示 10%）
 *        - 精确度：4位小数
 *        - 取值范围：0 < rate <= 1
 *    
 *    (3) 网站通用佣金比例
 *        - 如果自定义佣金比例为 0 或未设置（null），使用网站统一佣金比例
 *        - 从网站配置获取：SiteConfigService->getSiteCommissionRate()
 *        - 存储字段：site_config.commission_rate
 *        - 精确度：4位小数
 *    
 *    (4) 无佣金
 *        - 如果以上都没有设置，返回 null（不收佣金）
 * 
 * 3. 佣金计算公式
 *    - 纯商品金额 = 商品支付价格 - 运费
 *    - 应付佣金 = 纯商品金额 × 佣金率
 *    - 供应商实际收入 = 纯商品金额 - 应付佣金
 *    - 精度：保留2位小数，使用bcmath确保精确计算
 * 
 * 4. 佣金记录说明
 *    - 佣金记录保存在BalanceHistory表
 *    - 注意：这些记录仅用于对账查看，不影响实际业务逻辑
 *    - 业务类型：supplier_commission（供应商佣金）
 *    - 记录字段：供应商ID、订单号、商品金额、佣金率、佣金金额
 * 
 * 使用示例：
 * =========
 * 
 * // 计算单个供应商的佣金
 * $result = $commissionService->calculateSupplierCommission(
 *     supplierId: 1,
 *     paidAmount: '120.00',    // 商品支付价格（含运费）
 *     shippingFee: '20.00',    // 运费
 *     supplier: $supplier      // 供应商实体（可选，不传则从数据库查询）
 * );
 * 
 * // 获取供应商佣金率
 * $rate = $commissionService->getSupplierCommissionRate($supplier);
 * 
 * // 判断供应商是否为活跃会员
 * $isActive = $commissionService->isActiveMember($supplier);
 */
class SupplierCommissionService
{
    private FinancialCalculatorService $calculator;
    private SiteConfigService $siteConfigService;

    public function __construct(
        FinancialCalculatorService $calculator,
        SiteConfigService $siteConfigService
    ) {
        $this->calculator = $calculator;
        $this->siteConfigService = $siteConfigService;
    }

    /**
     * 计算单个供应商的佣金
     * 
     * 返回格式：
     * [
     *   'supplier_id' => 1,
     *   'paid_amount' => '120.00',              // 商品支付价格（含运费）
     *   'shipping_fee' => '20.00',              // 运费
     *   'product_amount' => '100.00',           // 纯商品金额（佣金基数）
     *   'commission_rate' => 0.15,              // 佣金率（0-1之间，null表示无佣金）
     *   'commission_rate_source' => 'member',   // 佣金率来源：member/custom/site/none
     *   'commission_amount' => '15.00',         // 应付佣金
     *   'supplier_income' => '85.00',           // 供应商实际收入
     *   'is_member' => true                     // 是否为活跃会员
     * ]
     * 
     * @param int $supplierId 供应商ID
     * @param string $paidAmount 商品支付价格（含运费）
     * @param string $shippingFee 运费
     * @param Supplier|null $supplier 供应商实体（可选，不传则从数据库查询）
     * @return array 佣金计算结果
     */
    public function calculateSupplierCommission(
        int $supplierId,
        string $paidAmount,
        string $shippingFee,
        ?Supplier $supplier = null
    ): array {
        // 1. 计算纯商品金额（佣金基数）= 商品支付价格 - 运费
        $productAmount = $this->calculator->subtract($paidAmount, $shippingFee);

        // 2. 获取供应商佣金率及来源
        $rateInfo = $this->getSupplierCommissionRateWithSource($supplier);
        $commissionRate = $rateInfo['rate'];
        $rateSource = $rateInfo['source'];
        $isMember = $rateInfo['is_member'];

        // 3. 计算佣金金额
        if ($commissionRate === null) {
            // 无佣金
            $commissionAmount = '0.00';
            $supplierIncome = $productAmount;
        } else {
            // 应付佣金 = 纯商品金额 × 佣金率
            $commissionAmount = $this->calculator->multiply($productAmount, (string)$commissionRate);
            // 供应商实际收入 = 纯商品金额 - 应付佣金
            $supplierIncome = $this->calculator->subtract($productAmount, $commissionAmount);
        }

        return [
            'supplier_id' => $supplierId,                              // 供应商ID
            'paid_amount' => $this->calculator->format($paidAmount),    // 商品支付价格（含运费）
            'shipping_fee' => $this->calculator->format($shippingFee),  // 运费
            'product_amount' => $this->calculator->format($productAmount), // 纯商品金额（佣金基数）
            'commission_rate' => $commissionRate,                       // 佣金比例（0-1之间，如 0.15 表示 15%，null表示无佣金）
            'commission_rate_source' => $rateSource,                    // 佣金率来源：member(会员免佣金)/custom(自定义)/site(网站通用)/none(无佣金)
            'commission_amount' => $this->calculator->format($commissionAmount), // 佣金金额（元）= 纯商品金额 × 佣金比例
            'supplier_income' => $this->calculator->format($supplierIncome),     // 供应商实际收入（元）= 纯商品金额 - 佣金金额
            'is_member' => $isMember                                    // 是否为活跃会员
        ];
    }

    /**
     * 获取供应商的佣金率（带来源信息）
     * 
     * 优先级：会员免佣金 > 自定义佣金 > 网站通用佣金 > 无佣金
     * 
     * 返回格式：
     * [
     *   'rate' => 0.15,              // 佣金率（null表示无佣金）
     *   'source' => 'custom',        // 来源：member/custom/site/none
     *   'is_member' => false         // 是否为活跃会员
     * ]
     * 
     * @param Supplier|null $supplier 供应商实体
     * @return array 佣金率信息
     */
    public function getSupplierCommissionRateWithSource(?Supplier $supplier): array
    {
        if (!$supplier) {
            return [
                'rate' => null,
                'source' => 'none',
                'is_member' => false
            ];
        }

        // 1. 会员免佣金（最高优先级）
        if ($this->isActiveMember($supplier)) {
            return [
                'rate' => 0.0,
                'source' => 'member',
                'is_member' => true
            ];
        }

        // 2. 自定义佣金比例
        $customRate = $supplier->getCommissionRate();
        if ($customRate !== null && $customRate > 0) {
            return [
                'rate' => (float)$customRate,
                'source' => 'custom',
                'is_member' => false
            ];
        }

        // 3. 网站通用佣金比例
        $siteRate = $this->siteConfigService->getSiteCommissionRate();
        if ($siteRate !== null && (float)$siteRate > 0) {
            return [
                'rate' => (float)$siteRate,
                'source' => 'site',
                'is_member' => false
            ];
        }

        // 4. 无佣金
        return [
            'rate' => null,
            'source' => 'none',
            'is_member' => false
        ];
    }

    /**
     * 获取供应商的佣金率
     * 
     * 优先级：会员免佣金 > 自定义佣金 > 网站通用佣金 > 无佣金
     * 
     * @param Supplier|null $supplier 供应商实体
     * @return float|null 佣金率（0-1之间的小数，null表示无佣金）
     */
    public function getSupplierCommissionRate(?Supplier $supplier): ?float
    {
        $rateInfo = $this->getSupplierCommissionRateWithSource($supplier);
        return $rateInfo['rate'];
    }

    /**
     * 判断供应商是否为活跃会员
     * 
     * 判断条件：
     * 1. membershipType != 'none'
     * 2. membershipExpiredAt > 当前时间
     * 
     * @param Supplier $supplier 供应商实体
     * @return bool 是否为活跃会员
     */
    public function isActiveMember(Supplier $supplier): bool
    {
        // 检查会员类型
        $membershipType = $supplier->getMembershipType();
        if ($membershipType === null || $membershipType === 'none') {
            return false;
        }

        // 检查会员有效期
        $expiredAt = $supplier->getMembershipExpiredAt();
        if ($expiredAt === null) {
            return false;
        }

        // 判断是否在有效期内
        $now = new \DateTime();
        return $expiredAt > $now;
    }

    /**
     * 格式化佣金率为百分比显示
     * 
     * @param float|null $rate 佣金率（0-1之间，null表示无佣金）
     * @return string 格式化后的百分比（如"15.0%"或"无佣金"）
     */
    public function formatCommissionRate(?float $rate): string
    {
        if ($rate === null) {
            return '无佣金';
        }
        
        if ($rate === 0.0) {
            return '0.0% (会员免佣金)';
        }
        
        return number_format($rate * 100, 1) . '%';
    }

    /**
     * 计算退款佣金冲回金额
     * 
     * 当订单发生退款时，需要按比例冲回已计算的佣金
     * 
     * 重要说明：
     * 1. 退款佣金冲回后，需要增加供应商余额（Supplier.balance += 冲回佣金）
     * 2. 需要在 BalanceHistory 表中记录冲回记录：
     *    - userType: 'supplier'
     *    - userId: 供应商ID
     *    - amount: 冲回佣金金额（正数，表示增加余额）
     *    - type: 'commission_refund' (退款佣金冲回)
     *    - description: '订单退款佣金冲回：订单号XXX'
     *    - referenceId: 订单号
     * 
     * 公式：冲回佣金 = 原始佣金 × (退款金额 / 原始商品金额)
     * 
     * @param string $originalCommission 原始佣金金额
     * @param string $refundAmount 退款金额
     * @param string $originalAmount 原始商品金额（支付总额减去运费后的纯商品金额，即 OrderItem.subtotal_amount - OrderItem.shipping_fee）
     * @return string 需要冲回的佣金金额
     */
    public function calculateRefundCommission(
        string $originalCommission,
        string $refundAmount,
        string $originalAmount
    ): string {
        // 冲回佣金 = 原始佣金 × (退款金额 / 原始金额)
        $refundRatio = $this->calculator->divide($refundAmount, $originalAmount);
        return $this->calculator->multiply($originalCommission, $refundRatio);
    }

    /**
     * 生成佣金计算报告
     * 
     * 用于展示或记录佣金计算的详细信息
     * 
     * @param int $supplierId 供应商ID
     * @param string $paidAmount 商品支付价格（含运费）
     * @param string $shippingFee 运费
     * @param Supplier|null $supplier 供应商实体
     * @return array 报告数据
     */
    public function generateCommissionReport(
        int $supplierId,
        string $paidAmount,
        string $shippingFee,
        ?Supplier $supplier = null
    ): array {
        $commission = $this->calculateSupplierCommission(
            $supplierId,
            $paidAmount,
            $shippingFee,
            $supplier
        );

        return [
            'supplier_id' => $commission['supplier_id'],
            'supplier_name' => $supplier ? $supplier->getDisplayName() : '未知供应商',
            'paid_amount' => $commission['paid_amount'],
            'shipping_fee' => $commission['shipping_fee'],
            'product_amount' => $commission['product_amount'],
            'commission_rate' => $this->formatCommissionRate($commission['commission_rate']),
            'commission_rate_source' => $this->getCommissionRateSourceLabel($commission['commission_rate_source']),
            'commission_amount' => $commission['commission_amount'],
            'supplier_income' => $commission['supplier_income'],
            'is_member' => $commission['is_member'],
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s')
        ];
    }

    /**
     * 获取佣金率来源的标签
     * 
     * @param string $source 来源代码
     * @return string 来源标签
     */
    private function getCommissionRateSourceLabel(string $source): string
    {
        return match($source) {
            'member' => '会员免佣金',
            'custom' => '供应商自定义佣金',
            'site' => '网站通用佣金',
            'none' => '无佣金',
            default => '未知'
        };
    }
}
