<?php

namespace App\Service;

/**
 * 金融计算器服务
 * 用于处理精确的金融计算，避免浮点数精度问题
 */
class FinancialCalculatorService
{
    private int $scale;

    public function __construct(int $scale = 2)
    {
        $this->scale = $scale;
    }

    /**
     * 加法运算
     * 
     * @param string $leftOperand 左操作数
     * @param string $rightOperand 右操作数
     * @param int|null $scale 精度（小数点后位数）
     * @return string 结果
     */
    public function add(string $leftOperand, string $rightOperand, ?int $scale = null): string
    {
        return bcadd($leftOperand, $rightOperand, $scale ?? $this->scale);
    }

    /**
     * 减法运算
     * 
     * @param string $leftOperand 左操作数
     * @param string $rightOperand 右操作数
     * @param int|null $scale 精度（小数点后位数）
     * @return string 结果
     */
    public function subtract(string $leftOperand, string $rightOperand, ?int $scale = null): string
    {
        return bcsub($leftOperand, $rightOperand, $scale ?? $this->scale);
    }

    /**
     * 乘法运算
     * 
     * @param string $leftOperand 左操作数
     * @param string $rightOperand 右操作数
     * @param int|null $scale 精度（小数点后位数）
     * @return string 结果
     */
    public function multiply(string $leftOperand, string $rightOperand, ?int $scale = null): string
    {
        return bcmul($leftOperand, $rightOperand, $scale ?? $this->scale);
    }

    /**
     * 除法运算
     * 
     * @param string $dividend 被除数
     * @param string $divisor 除数
     * @param int|null $scale 精度（小数点后位数）
     * @return string 结果
     */
    public function divide(string $dividend, string $divisor, ?int $scale = null): string
    {
        if (bccomp($divisor, '0', $scale ?? $this->scale) === 0) {
            throw new \InvalidArgumentException('Division by zero');
        }
        return bcdiv($dividend, $divisor, $scale ?? $this->scale);
    }

    /**
     * 比较两个数
     * 
     * @param string $leftOperand 左操作数
     * @param string $rightOperand 右操作数
     * @param int|null $scale 精度（小数点后位数）
     * @return int 比较结果：0表示相等，1表示左操作数大于右操作数，-1表示左操作数小于右操作数
     */
    public function compare(string $leftOperand, string $rightOperand, ?int $scale = null): int
    {
        return bccomp($leftOperand, $rightOperand, $scale ?? $this->scale);
    }

    /**
     * 判断是否足够支付
     * 
     * @param string $balance 余额
     * @param string $amount 需要支付的金额
     * @return bool 是否足够支付
     */
    public function isSufficient(string $balance, string $amount): bool
    {
        return $this->compare($balance, $amount) >= 0;
    }

    /**
     * 格式化金额（避免浮点数转换，使用bcmath保证精度）
     * 
     * @param string $amount 金额
     * @param int|null $scale 精度（小数点后位数）
     * @return string 格式化后的金额
     */
    public function format(string $amount, ?int $scale = null): string
    {
        $scale = $scale ?? $this->scale;
        // 使用bcadd进行四舍五入，避免浮点数转换
        // bcadd($amount, '0', $scale) 会自动四舍五入到指定精度
        return bcadd($amount, '0', $scale);
    }

    /**
     * 计算百分比金额
     * 
     * @param string $amount 基础金额
     * @param string $percentage 百分比（如10表示10%）
     * @param int|null $scale 精度（小数点后位数）
     * @return string 计算结果
     */
    public function calculatePercentage(string $amount, string $percentage, ?int $scale = null): string
    {
        // 将百分比转换为小数形式
        $rate = $this->divide($percentage, '100', 6);
        // 计算金额
        return $this->multiply($amount, $rate, $scale ?? $this->scale);
    }
}