<?php

namespace App\Service;

use App\Entity\SiteConfig;
use App\Repository\SiteConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class SiteConfigService
{
    private $siteConfigRepository;
    private $entityManager;

    public function __construct(
        SiteConfigRepository $siteConfigRepository,
        EntityManagerInterface $entityManager
    ) {
        $this->siteConfigRepository = $siteConfigRepository;
        $this->entityManager = $entityManager;
    }

    /**
     * 根据键名获取配置值
     *
     * @param string $key 配置键名
     * @return string|null
     */
    public function getConfigValue(string $key): ?string
    {
        $config = $this->siteConfigRepository->findOneByKey($key);
        return $config ? $config->getConfigValue() : null;
    }

    /**
     * 设置配置值
     *
     * @param string $key 配置键名
     * @param string|null $value 配置值
     * @param string|null $description 配置描述
     * @return SiteConfig
     */
    public function setConfigValue(string $key, ?string $value, ?string $description = null): SiteConfig
    {
        return $this->siteConfigRepository->updateOrCreateConfig($key, $value, $description);
    }

    /**
     * 获取所有配置项
     *
     * @return SiteConfig[]
     */
    public function getAllConfig(): array
    {
        return $this->siteConfigRepository->findAll();
    }

    /**
     * 删除配置项
     *
     * @param string $key 配置键名
     * @return bool
     */
    public function deleteConfig(string $key): bool
    {
        $config = $this->siteConfigRepository->findOneByKey($key);
        if ($config) {
            $this->entityManager->remove($config);
            $this->entityManager->flush();
            return true;
        }
        return false;
    }

    /**
     * 获取网站通用佣金比例
     * 
     * @return string|null 佣金比例（小数形式，如'0.1000'表示10%）
     */
    public function getSiteCommissionRate(): ?string
    {
        $configValue = $this->getConfigValue('commission_rate');
        if ($configValue !== null && is_numeric($configValue)) {
            // 确保精度为4位小数
            return number_format((float) $configValue, 4, '.', '');
        }
        return null;
    }

    /**
     * 获取会员价格配置
     * 
     * @param string $membershipType 会员类型
     * @return string|null 会员价格
     */
    public function getMembershipPrice(string $membershipType): ?string
    {
        $configKey = 'mvip_price_' . $membershipType;
        return $this->getConfigValue($configKey);
    }

    /**
     * 获取所有会员价格配置
     * 
     * @return array 会员价格配置数组
     */
    public function getAllMembershipPrices(): array
    {
        $membershipTypes = ['monthly', 'quarterly', 'half_yearly', 'yearly'];
        $prices = [];
        
        foreach ($membershipTypes as $type) {
            $price = $this->getMembershipPrice($type);
            if ($price !== null) {
                $prices[$type] = $price;
            }
        }
        
        return $prices;
    }

    /**
     * 获取网站支付币种
     * 
     * 从 site_config 表中读取 configKey 为 'site_currency' 的配置值
     * 返回符合 ISO 4217 标准的大写币种代码（如 USD, EUR, GBP 等）
     * 
     * @return string 币种代码（大写，如 'USD'），默认为 'USD'
     */
    public function getSiteCurrency(): string
    {
        $currency = $this->getConfigValue('site_currency');
        
        // 如果未配置或为空，默认返回 USD
        if (empty($currency)) {
            return 'USD';
        }
        
        // 确保返回大写格式（符合 ISO 4217 标准）
        return strtoupper(trim($currency));
    }

    /**
     * 设置网站支付币种
     * 
     * 注意：根据项目规范，site_currency 配置项只允许修改描述字段
     * 但为了初始化方便，提供此方法用于设置币种
     * 
     * @param string $currency 币种代码（如 USD, EUR, GBP）
     * @param string|null $description 配置描述
     * @return SiteConfig
     */
    public function setSiteCurrency(string $currency, ?string $description = null): SiteConfig
    {
        // 确保币种代码为大写（符合 ISO 4217 标准）
        $currency = strtoupper(trim($currency));
        
        if (empty($description)) {
            $description = "网站支付币种：{$currency}";
        }
        
        return $this->setConfigValue('site_currency', $currency, $description);
    }

    /**
     * 获取网站国家代码
     * 
     * 从 site_config 表中读取 configKey 为 'site_currency' 的 configValue2 字段
     * 返回符合 ISO 3166-1 标准的大写国家代码（如 US, CN, DE 等）
     * 
     * @return string 国家代码（大写，如 'US'），默认为 'US'
     */
    public function getSiteCountry(): string
    {
        $config = $this->siteConfigRepository->findOneByKey('site_currency');
        
        // 如果未配置或 configValue2 为空，默认返回 US
        if (!$config || empty($config->getConfigValue2())) {
            return 'US';
        }
        
        // 确保返回大写格式（符合 ISO 3166-1 标准）
        return strtoupper(trim($config->getConfigValue2()));
    }

    /**
     * 获取提现方式配置
     * 
     * 读取 withdrawRefundUseOnlinePay 配置项的 config_value 值
     * - 'online': 使用在线支付（Payoneer）
     * - 'offline': 使用离线手动打款（管理员审核）
     * 
     * @return string 'online' 或 'offline'，默认为 'offline'
     */
    public function getWithdrawalMode(): string
    {
        $value = $this->getConfigValue('withdrawRefundUseOnlinePay');
        
        // 默认使用离线手动打款模式
        if (empty($value)) {
            return 'offline';
        }
        
        return strtolower(trim($value));
    }

    /**
     * 检查提现是否使用在线支付
     * 
     * @return bool true-使用在线支付(Payoneer), false-使用离线手动打款
     */
    public function isWithdrawalUseOnlinePay(): bool
    {
        return $this->getWithdrawalMode() === 'online';
    }

    /**
     * 检查提现是否使用离线手动打款
     * 
     * @return bool true-使用离线手动打款, false-使用在线支付
     */
    public function isWithdrawalUseOfflinePay(): bool
    {
        return $this->getWithdrawalMode() === 'offline';
    }

    /**
     * 获取网站货币符号
     * 
     * 从 site_config 表中读取 configKey 为 'site_currency' 的 configValue3 字段
     * 这个字段存储货币符号（如 $, €, ¥ 等）
     * 
     * @return string 货币符号，默认为 '$'
     */
    public function getCurrencySymbol(): string
    {
        $config = $this->siteConfigRepository->findOneByKey('site_currency');
        
        // 如果未配置或 configValue3 为空，默认返回 $
        if (!$config || empty($config->getConfigValue3())) {
            return '$';
        }
        
        return $config->getConfigValue3();
    }
}