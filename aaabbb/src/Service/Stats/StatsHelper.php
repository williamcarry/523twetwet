<?php

namespace App\Service\Stats;

/**
 * 统计工具类
 * 提供通用的统计辅助方法
 */
class StatsHelper
{
    /**
     * 计算环比增长率
     * 
     * @param float $current 本期数值
     * @param float $previous 上期数值
     * @return float 环比增长率（百分比）
     */
    public static function calculateGrowthRate(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        
        return round((($current - $previous) / $previous) * 100, 2);
    }
    
    /**
     * 格式化金额
     * 
     * @param float $amount 金额
     * @param int $decimals 小数位数
     * @return float
     */
    public static function formatAmount(float $amount, int $decimals = 2): float
    {
        return round($amount, $decimals);
    }
    
    /**
     * 计算百分比
     * 
     * @param float $part 部分值
     * @param float $total 总值
     * @return float 百分比
     */
    public static function calculatePercentage(float $part, float $total): float
    {
        if ($total == 0) {
            return 0.0;
        }
        
        return round(($part / $total) * 100, 2);
    }
    
    /**
     * 获取日期范围
     * 
     * @param string $period 时间周期 (today, yesterday, this_month, last_month, last_7_days, last_30_days, last_3_months)
     * @return array ['start' => \DateTime, 'end' => \DateTime]
     */
    public static function getDateRange(string $period): array
    {
        $now = new \DateTime();
        $start = clone $now;
        $end = clone $now;
        
        switch ($period) {
            case 'today':
                $start->setTime(0, 0, 0);
                $end->setTime(23, 59, 59);
                break;
                
            case 'yesterday':
                $start->modify('-1 day')->setTime(0, 0, 0);
                $end->modify('-1 day')->setTime(23, 59, 59);
                break;
                
            case 'this_month':
                $start->modify('first day of this month')->setTime(0, 0, 0);
                $end->setTime(23, 59, 59);
                break;
                
            case 'last_month':
                $start->modify('first day of last month')->setTime(0, 0, 0);
                $end->modify('last day of last month')->setTime(23, 59, 59);
                break;
                
            case 'last_7_days':
                $start->modify('-7 days')->setTime(0, 0, 0);
                $end->setTime(23, 59, 59);
                break;
                
            case 'last_30_days':
                $start->modify('-30 days')->setTime(0, 0, 0);
                $end->setTime(23, 59, 59);
                break;
                
            case 'last_3_months':
                $start->modify('-3 months')->setTime(0, 0, 0);
                $end->setTime(23, 59, 59);
                break;
                
            default:
                $start->setTime(0, 0, 0);
                $end->setTime(23, 59, 59);
        }
        
        return ['start' => $start, 'end' => $end];
    }
    
    /**
     * 获取近N个月的日期范围
     * 
     * @param int $months 月数
     * @return array ['start' => \DateTime, 'end' => \DateTime]
     */
    public static function getLastNMonthsRange(int $months): array
    {
        $end = new \DateTime();
        $end->setTime(23, 59, 59);
        
        $start = new \DateTime();
        $start->modify("-{$months} months")->modify('first day of this month')->setTime(0, 0, 0);
        
        return ['start' => $start, 'end' => $end];
    }
    
    /**
     * 生成缓存键
     * 
     * @param string $prefix 前缀
     * @param array $params 参数
     * @return string
     */
    public static function generateCacheKey(string $prefix, array $params = []): string
    {
        if (empty($params)) {
            return "stats:{$prefix}";
        }
        
        $paramStr = implode(':', array_map(function($key, $value) {
            return "{$key}_{$value}";
        }, array_keys($params), $params));
        
        return "stats:{$prefix}:{$paramStr}";
    }
}
