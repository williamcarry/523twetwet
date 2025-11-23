<?php

namespace App\Service;

use App\Entity\ProductShipping;

/**
 * 商品价格计算服务
 * 统一处理商品价格计算逻辑，使用金融计算器确保精度
 */
class ProductPriceCalculatorService
{
    private FinancialCalculatorService $calculator;

    public function __construct(FinancialCalculatorService $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * 计算会员价格
     * 
     * @param string $sellingPrice 售价
     * @param float $memberDiscountRate 会员折扣率（0-1之间，如0.1表示10%）
     * @return string 会员价格
     */
    public function calculateMemberPrice(string $sellingPrice, float $memberDiscountRate): string
    {
        // 会员价 = 售价 - (售价 × 会员折扣率)
        $discountAmount = $this->calculator->multiply($sellingPrice, (string)$memberDiscountRate);
        return $this->calculator->subtract($sellingPrice, $discountAmount);
    }

    /**
     * 计算小计（单价 × 数量）
     * 
     * @param string $unitPrice 单价
     * @param int $quantity 数量
     * @return string 小计
     */
    public function calculateSubtotal(string $unitPrice, int $quantity): string
    {
        return $this->calculator->multiply($unitPrice, (string)$quantity);
    }

    /**
     * 应用满减优惠
     * 
     * @param string $subtotal 小计金额
     * @param string $minAmount 满减门槛
     * @param string $discountAmount 减免金额
     * @return string 应用满减后的金额
     */
    public function applyFullReduction(string $subtotal, string $minAmount, string $discountAmount): string
    {
        // 检查是否达到满减门槛
        if ($this->calculator->compare($subtotal, $minAmount) >= 0) {
            return $this->calculator->subtract($subtotal, $discountAmount);
        }
        return $subtotal;
    }

    /**
     * 添加运费
     * 
     * @param string $amount 当前金额
     * @param string $shippingFee 运费
     * @return string 加上运费后的金额
     */
    public function addShippingFee(string $amount, string $shippingFee): string
    {
        return $this->calculator->add($amount, $shippingFee);
    }

    /**
     * 计算商品最终总价
     * 完整的价格计算流程：售价 → 会员折扣 → 小计 → 满减 → 运费
     * 
     * @param array $params 计算参数
     *   - sellingPrice: 售价
     *   - memberDiscountRate: 会员折扣率（可选，0-1之间）
     *   - quantity: 数量
     *   - minAmount: 满减门槛（可选）
     *   - discountAmount: 满减金额（可选）
     *   - shippingFee: 运费（可选）
     *   - shippingMethod: 物流方式（可选，默认 ProductShipping::STANDARD_SHIPPING）
     * @return array 计算结果
     *   - displayPrice: 显示单价（应用会员折扣后）
     *   - subtotal: 小计
     *   - totalPrice: 最终总价
     *   - breakdown: 价格明细数组
     */
    public function calculateTotalPrice(array $params): array
    {
        $sellingPrice = $params['sellingPrice'];
        $memberDiscountRate = $params['memberDiscountRate'] ?? 0;
        $quantity = $params['quantity'];
        $minAmount = $params['minAmount'] ?? null;
        $discountAmount = $params['discountAmount'] ?? null;
        $shippingFee = $params['shippingFee'] ?? '0';
        $shippingMethod = $params['shippingMethod'] ?? ProductShipping::STANDARD_SHIPPING;
        $currency = $params['currency'] ?? 'CNY';

        $breakdown = [];

        // 1. 计算会员价格
        $displayPrice = $sellingPrice;
        if ($memberDiscountRate > 0) {
            $displayPrice = $this->calculateMemberPrice($sellingPrice, $memberDiscountRate);
            
            // 添加到价格明细
            $memberDiscountAmount = $this->calculator->subtract($sellingPrice, $displayPrice);
            $breakdown[] = [
                'label' => '会员折扣',
                'rate' => $memberDiscountRate * 100,
                'amount' => '-' . $this->calculator->format($memberDiscountAmount),
                'currency' => $currency
            ];
        }

        // 2. 计算小计
        $subtotal = $this->calculateSubtotal($displayPrice, $quantity);
        $finalTotal = $subtotal;

        // 3. 应用满减
        if ($minAmount !== null && $discountAmount !== null) {
            $beforeReduction = $finalTotal;
            $finalTotal = $this->applyFullReduction($finalTotal, $minAmount, $discountAmount);
            
            // 如果实际应用了满减，添加到价格明细
            if ($this->calculator->compare($beforeReduction, $finalTotal) > 0) {
                $breakdown[] = [
                    'label' => '满减',
                    'amount' => '-' . $this->calculator->format($discountAmount),
                    'currency' => $currency
                ];
            }
        }

        // 4. 添加运费
        if ($shippingMethod === ProductShipping::STANDARD_SHIPPING && $this->calculator->compare($shippingFee, '0') > 0) {
            $finalTotal = $this->addShippingFee($finalTotal, $shippingFee);
            
            $breakdown[] = [
                'label' => '运费',
                'amount' => '+' . $this->calculator->format($shippingFee),
                'currency' => $currency
            ];
        }

        return [
            'displayPrice' => $this->calculator->format($displayPrice),
            'subtotal' => $this->calculator->format($subtotal),
            'totalPrice' => $this->calculator->format($finalTotal),
            'breakdown' => $breakdown
        ];
    }

    /**
     * 验证两个价格是否相等（精确到分）
     * 使用容差机制解决浮点数计算精度问题
     * 
     * @param string $price1 价格1
     * @param string $price2 价格2
     * @param string $tolerance 容差值（默认0.01，即相差不超过1分视为相等）
     * @return bool 是否相等
     */
    public function pricesEqual(string $price1, string $price2, string $tolerance = '0.01'): bool
    {
        // 先格式化为2位小数
        $formatted1 = $this->calculator->format($price1);
        $formatted2 = $this->calculator->format($price2);
        
        // 计算差值的绝对值
        $diff = $this->calculator->subtract($formatted1, $formatted2);
        $absDiff = ltrim($diff, '-'); // 去掉负号得到绝对值
        
        // 如果差值小于或等于容差，则认为相等
        return $this->calculator->compare($absDiff, $tolerance) <= 0;
    }

    /**
     * 格式化价格
     * 
     * @param string $price 价格
     * @return string 格式化后的价格（保留2位小数）
     */
    public function formatPrice(string $price): string
    {
        return $this->calculator->format($price);
    }
}
