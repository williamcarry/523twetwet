<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductPrice;
use App\Entity\ProductDiscountRule;
use App\Entity\ProductShipping;
use App\Entity\ShippingRegion;
use App\Repository\ProductPriceRepository;
use App\Repository\ProductDiscountRuleRepository;
use App\Repository\ProductShippingRepository;
use App\Repository\ShippingRegionRepository;

/**
 * 商品价格计算服务 (ProductPriceCalculator)
 * 
 * 核心功能：统一封装商品在不同区域的价格计算逻辑
 * 
 * =============================================
 * 设计理念
 * =============================================
 * 1. 单一职责：专注于价格计算，不涉及数据持久化
 * 2. 计算透明：返回详细的计算过程，便于调试和展示
 * 3. 区域隔离：每个区域的价格独立计算，互不影响
 * 4. 优先级明确：严格按照优先级顺序计算价格
 * 
 * =============================================
 * 价格计算优先级（核心公式）
 * =============================================
 * $finalPrice = $sellingPrice                      // 1. 基础售价
 *     * (1 - $discountRate)                        // 2. 应用商品折扣
 *     * (1 - $memberDiscount[$vipLevel])           // 3. 应用会员折扣
 *     - $discountAmount;                           // 4. 应用满减（如果满足条件）
 * 
 * =============================================
 * 使用场景
 * =============================================
 * 1. 商品详情页显示价格
 * 2. 购物车计算小计
 * 3. 订单确认页计算总价
 * 4. 促销活动页展示优惠后价格
 * 
 * =============================================
 * 使用示例
 * =============================================
 * ```php
 * // 注入服务
 * public function __construct(
 *     private ProductPriceCalculator $priceCalculator
 * ) {}
 * 
 * // 计算单个商品价格
 * $result = $this->priceCalculator->calculateProductPrice(
 *     product: $product,
 *     regionCode: 'CN',
 *     vipLevel: 3,
 *     quantity: 2
 * );
 * 
 * // 获取最终价格
 * echo $result['formatted']['total']; // "¥142.98"
 * 
 * // 查看计算明细
 * print_r($result['breakdown']);
 * ```
 */
class ProductPriceCalculator
{
    public function __construct(
        private ProductPriceRepository $productPriceRepository,
        private ProductDiscountRuleRepository $productDiscountRuleRepository,
        private ProductShippingRepository $productShippingRepository,
        private ShippingRegionRepository $shippingRegionRepository
    ) {}

    /**
     * 计算商品在指定区域、指定会员等级的最终价格
     * 
     * 这是核心方法，按照优先级顺序计算价格：
     * 1. 基础售价
     * 2. 商品折扣（ProductPrice.discount_rate）
     * 3. 会员折扣（ProductPrice.member_discount）
     * 4. 满减优惠（ProductDiscountRule）
     * 5. 运费（ProductShipping）
     * 
     * @param Product $product 商品实体
     * @param string $regionCode 区域代码（CN, US, UK, PR）
     * @param int $vipLevel 会员等级（0-5，0为普通用户）
     * @param float $quantity 购买数量
     * @param bool $includeShipping 是否包含运费
     * @return array 价格计算结果（包含明细和格式化字符串）
     * 
     * @throws \InvalidArgumentException 当区域无效或无价格配置时抛出
     */
    public function calculateProductPrice(
        Product $product,
        string $regionCode,
        int $vipLevel = 0,
        float $quantity = 1,
        bool $includeShipping = true
    ): array {
        // ====================================
        // 步骤1：验证参数和获取区域信息
        // ====================================
        $region = $this->shippingRegionRepository->findOneBy([
            'code' => $regionCode,
            'isActive' => true
        ]);
        
        if (!$region) {
            throw new \InvalidArgumentException("无效的区域代码: {$regionCode}");
        }

        $currency = $region->getCurrencyCode();
        $currencySymbol = $region->getCurrencySymbol();

        // ====================================
        // 步骤2：获取商品基础价格配置
        // ====================================
        $price = $this->productPriceRepository->findOneBy([
            'product' => $product,
            'region' => $regionCode,
            'isActive' => true
        ]);

        if (!$price) {
            throw new \InvalidArgumentException(
                "商品 [{$product->getSku()}] 在区域 [{$regionCode}] 无价格配置"
            );
        }

        // ====================================
        // 步骤3：计算单价（应用商品折扣和会员折扣）
        // ====================================
        $pricePerItem = $this->calculateUnitPrice($price, $vipLevel);

        // ====================================
        // 步骤4：计算小计（单价 × 数量）
        // ====================================
        $subtotal = $pricePerItem * $quantity;

        // ====================================
        // 步骤5：应用满减优惠
        // ====================================
        $discountInfo = $this->applyFullReduction(
            $product->getId(),
            $regionCode,
            $subtotal
        );

        $subtotalAfterDiscount = $subtotal - $discountInfo['amount'];

        // ====================================
        // 步骤6：计算运费
        // ====================================
        $shippingFee = 0.0;
        $shippingMethod = null;

        if ($includeShipping) {
            $shippingInfo = $this->calculateShipping(
                $product->getId(),
                $regionCode
            );
            $shippingFee = $shippingInfo['fee'];
            $shippingMethod = $shippingInfo['method'];
        }

        // ====================================
        // 步骤7：计算总价
        // ====================================
        $total = $subtotalAfterDiscount + $shippingFee;

        // ====================================
        // 步骤8：构建返回结果
        // ====================================
        return $this->buildPriceResult(
            region: $region,
            price: $price,
            vipLevel: $vipLevel,
            quantity: $quantity,
            pricePerItem: $pricePerItem,
            subtotal: $subtotal,
            discountInfo: $discountInfo,
            subtotalAfterDiscount: $subtotalAfterDiscount,
            shippingFee: $shippingFee,
            shippingMethod: $shippingMethod,
            total: $total,
            currency: $currency,
            currencySymbol: $currencySymbol
        );
    }

    /**
     * 计算单价（应用商品折扣和会员折扣）
     * 
     * 计算公式：
     * $unitPrice = $sellingPrice * (1 - $discountRate) * (1 - $memberDiscountRate)
     * 
     * 注意：折扣是累乘关系，不是相加！
     * 例如：10%商品折扣 + 15%会员折扣 = 实际折扣23.5%（不是25%）
     * 
     * @param ProductPrice $price 价格实体
     * @param int $vipLevel 会员等级
     * @return float 计算后的单价
     */
    private function calculateUnitPrice(ProductPrice $price, int $vipLevel): float
    {
        // 获取基础售价
        $sellingPrice = (float) $price->getSellingPrice();

        // 应用商品折扣
        $discountRate = (float) ($price->getDiscountRate() ?? 0.0);
        $priceAfterDiscount = $sellingPrice * (1 - $discountRate);

        // 应用会员折扣
        $memberDiscounts = $price->getMemberDiscount() ?? [];
        $memberDiscountRate = (float) ($memberDiscounts[(string)$vipLevel] ?? 0.0);
        $finalPrice = $priceAfterDiscount * (1 - $memberDiscountRate);

        return $finalPrice;
    }

    /**
     * 应用满减优惠
     * 
     * 判断订单金额是否满足满减条件，如果满足则返回减免金额
     * 
     * @param int $productId 商品ID
     * @param string $regionCode 区域代码
     * @param float $amount 订单金额
     * @return array ['applied' => bool, 'amount' => float, 'rule' => ?ProductDiscountRule]
     */
    private function applyFullReduction(
        int $productId,
        string $regionCode,
        float $amount
    ): array {
        // 获取有效的满减规则
        $rule = $this->productDiscountRuleRepository->findActiveByProductAndRegion(
            $productId,
            $regionCode
        );

        if (!$rule) {
            return [
                'applied' => false,
                'amount' => 0.0,
                'rule' => null,
                'description' => null
            ];
        }

        $minAmount = (float) $rule->getMinAmount();
        $discountAmount = (float) $rule->getDiscountAmount();

        // 判断是否满足满减条件
        if ($amount >= $minAmount) {
            return [
                'applied' => true,
                'amount' => $discountAmount,
                'rule' => $rule,
                'description' => $rule->getDescription()
            ];
        }

        return [
            'applied' => false,
            'amount' => 0.0,
            'rule' => $rule,
            'description' => $rule->getDescription()
        ];
    }

    /**
     * 计算运费
     * 
     * @param int $productId 商品ID
     * @param string $regionCode 区域代码
     * @return array ['fee' => float, 'method' => ?string]
     */
    private function calculateShipping(int $productId, string $regionCode): array
    {
        $shipping = $this->productShippingRepository->createQueryBuilder('ps')
            ->join('ps.product', 'p')
            ->where('p.id = :productId')
            ->andWhere('ps.region = :region')
            ->andWhere('ps.isDefault = true')
            ->andWhere('ps.isActive = true')
            ->setParameter('productId', $productId)
            ->setParameter('region', $regionCode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$shipping) {
            return ['fee' => 0.0, 'method' => null];
        }

        // 优先使用折后运费
        $fee = $shipping->getDiscountedPrice() 
            ?? $shipping->getShippingPrice();

        return [
            'fee' => (float) $fee,
            'method' => $shipping->getShippingMethod()
        ];
    }

    /**
     * 构建价格计算结果
     * 
     * 返回格式化的价格信息，包括：
     * - region: 区域信息
     * - vipLevel: 会员等级信息
     * - breakdown: 价格明细（所有计算步骤）
     * - formatted: 格式化字符串（带币种符号）
     * 
     * @return array 完整的价格计算结果
     */
    private function buildPriceResult(
        ShippingRegion $region,
        ProductPrice $price,
        int $vipLevel,
        float $quantity,
        float $pricePerItem,
        float $subtotal,
        array $discountInfo,
        float $subtotalAfterDiscount,
        float $shippingFee,
        ?string $shippingMethod,
        float $total,
        string $currency,
        string $currencySymbol
    ): array {
        $sellingPrice = (float) $price->getSellingPrice();
        $discountRate = (float) ($price->getDiscountRate() ?? 0.0);
        
        $memberDiscounts = $price->getMemberDiscount() ?? [];
        $memberDiscountRate = (float) ($memberDiscounts[(string)$vipLevel] ?? 0.0);

        return [
            // 区域信息
            'region' => [
                'code' => $region->getCode(),
                'label' => $region->getLabel(),
                'labelEn' => $region->getLabelEn(),
            ],

            // 币种信息
            'currency' => [
                'code' => $currency,
                'symbol' => $currencySymbol,
            ],

            // 会员等级信息
            'vipLevel' => [
                'level' => $vipLevel,
                'name' => \App\common\VipLevel::getLevelName($vipLevel),
            ],

            // 价格计算明细
            'breakdown' => [
                // 基础售价
                'originalPrice' => round($sellingPrice, 2),
                
                // 商品折扣
                'productDiscountRate' => $discountRate,
                'productDiscountPercent' => round($discountRate * 100, 1) . '%',
                
                // 会员折扣
                'memberDiscountRate' => $memberDiscountRate,
                'memberDiscountPercent' => round($memberDiscountRate * 100, 1) . '%',
                
                // 单价（应用折扣后）
                'pricePerItem' => round($pricePerItem, 2),
                
                // 数量
                'quantity' => $quantity,
                
                // 小计（单价 × 数量）
                'subtotal' => round($subtotal, 2),
                
                // 满减信息
                'fullReduction' => [
                    'applied' => $discountInfo['applied'],
                    'amount' => round($discountInfo['amount'], 2),
                    'description' => $discountInfo['description'],
                ],
                
                // 满减后小计
                'subtotalAfterDiscount' => round($subtotalAfterDiscount, 2),
                
                // 运费
                'shipping' => [
                    'fee' => round($shippingFee, 2),
                    'method' => $shippingMethod,
                ],
                
                // 总价
                'total' => round($total, 2),
            ],

            // 格式化字符串（用于前端展示）
            'formatted' => [
                'originalPrice' => $currencySymbol . number_format($sellingPrice, 2),
                'pricePerItem' => $currencySymbol . number_format($pricePerItem, 2),
                'subtotal' => $currencySymbol . number_format($subtotal, 2),
                'discount' => $currencySymbol . number_format($discountInfo['amount'], 2),
                'subtotalAfterDiscount' => $currencySymbol . number_format($subtotalAfterDiscount, 2),
                'shipping' => $currencySymbol . number_format($shippingFee, 2),
                'total' => $currencySymbol . number_format($total, 2),
            ],
        ];
    }

    /**
     * 批量计算多个商品的价格（购物车场景）
     * 
     * @param array $items 商品列表 [['product' => Product, 'quantity' => float], ...]
     * @param string $regionCode 区域代码
     * @param int $vipLevel 会员等级
     * @return array 批量计算结果
     */
    public function calculateCartTotal(
        array $items,
        string $regionCode,
        int $vipLevel = 0
    ): array {
        $results = [];
        $cartSubtotal = 0.0;
        $totalDiscount = 0.0;
        $totalShipping = 0.0;

        foreach ($items as $item) {
            $result = $this->calculateProductPrice(
                product: $item['product'],
                regionCode: $regionCode,
                vipLevel: $vipLevel,
                quantity: $item['quantity'] ?? 1,
                includeShipping: true
            );

            $results[] = $result;
            $cartSubtotal += $result['breakdown']['subtotal'];
            $totalDiscount += $result['breakdown']['fullReduction']['amount'];
            $totalShipping += $result['breakdown']['shipping']['fee'];
        }

        $cartTotal = $cartSubtotal - $totalDiscount + $totalShipping;

        $region = $this->shippingRegionRepository->findOneBy([
            'code' => $regionCode,
            'isActive' => true
        ]);
        
        if (!$region) {
            throw new \InvalidArgumentException("无效的区域代码: {$regionCode}");
        }
        
        $currencySymbol = $region->getCurrencySymbol();

        return [
            'items' => $results,
            'summary' => [
                'subtotal' => round($cartSubtotal, 2),
                'totalDiscount' => round($totalDiscount, 2),
                'totalShipping' => round($totalShipping, 2),
                'total' => round($cartTotal, 2),
            ],
            'formatted' => [
                'subtotal' => $currencySymbol . number_format($cartSubtotal, 2),
                'totalDiscount' => $currencySymbol . number_format($totalDiscount, 2),
                'totalShipping' => $currencySymbol . number_format($totalShipping, 2),
                'total' => $currencySymbol . number_format($cartTotal, 2),
            ]
        ];
    }
}
