<?php

namespace App\Service;

use App\Entity\Supplier;

class CommissionService
{
    private SiteConfigService $siteConfigService;
    private FinancialCalculatorService $financialCalculator;

    public function __construct(
        SiteConfigService $siteConfigService,
        FinancialCalculatorService $financialCalculator
    ) {
        $this->siteConfigService = $siteConfigService;
        $this->financialCalculator = $financialCalculator;
    }

    /**
     * 获取供应商的有效佣金比例
     * 
     * @param Supplier $supplier 供应商实体
     * @return string|null 佣金比例（小数形式，如'0.1000'表示10%）
     */
    public function getEffectiveCommissionRate(Supplier $supplier): ?string
    {
        // 使用闭包作为回调函数来获取网站配置
        $siteConfigCallback = function() {
            return $this->siteConfigService->getSiteCommissionRate();
        };
        
        return $supplier->getEffectiveCommissionRate($siteConfigCallback);
    }

    /**
     * 获取供应商的有效佣金比例（百分比形式）
     * 
     * @param Supplier $supplier 供应商实体
     * @return float|null 佣金比例（百分比形式，如10表示10%）
     */
    public function getEffectiveCommissionRatePercentage(Supplier $supplier): ?float
    {
        $rate = $this->getEffectiveCommissionRate($supplier);
        // 使用金融计算服务进行乘法运算，确保计算逻辑的一致性和可维护性
        return $rate !== null ? (float) $this->financialCalculator->multiply($rate, '100') : null;
    }
}