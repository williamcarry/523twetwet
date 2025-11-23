<?php

namespace App\Service;

/**
 * 短信验证码服务类
 * 
 * 统一管理短信验证码的发送、验证和防刷策略
 * 
 * Redis Key 说明：
 * - sms_code:{phone}          验证码内容，TTL=600秒（10分钟）
 * - sms_limit:{phone}         60秒间隔限制，TTL=60秒
 * - sms_daily:{phone}:{date}  每天20次限制，TTL=86400秒（24小时）
 * - sms_ip:{ip}               每小时10次限制，TTL=3600秒（1小时）
 * 
 * 防刷策略：
 * 1. 同一手机号60秒内只能发送一次
 * 2. 同一手机号每天最多发送20次
 * 3. 同一IP每小时最多发送10次
 */
class SmsVerificationService
{
    private SmsService2017 $smsService;
    private RedisService $redis;

    public function __construct(SmsService2017 $smsService, RedisService $redis)
    {
        $this->smsService = $smsService;
        $this->redis = $redis;
    }

    /**
     * 发送短信验证码
     * 
     * @param string $phone 手机号码
     * @param string $clientIp 客户端IP地址
     * @return array 包含 success, message, messageEn 等字段
     */
    public function sendVerificationCode(string $phone, string $clientIp): array
    {
        // 验证手机号格式（中国大陆）
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return [
                'success' => false,
                'message' => '手机号格式不正确',
                'messageEn' => 'Invalid phone number format',
                'statusCode' => 400
            ];
        }

        $today = date('Y-m-d');

        // Redis防刷检查
        if ($this->redis->isConnected()) {
            // 1. 检查60秒间隔限制
            $limitKey = "sms_limit:{$phone}";
            if ($this->redis->get($limitKey)) {
                return [
                    'success' => false,
                    'message' => '验证码发送过于频繁，请60秒后再试',
                    'messageEn' => 'Too frequent, please try again after 60 seconds',
                    'statusCode' => 429
                ];
            }

            // 2. 检查每天次数限制（20次）
            $dailyKey = "sms_daily:{$phone}:{$today}";
            $dailyCount = (int)$this->redis->get($dailyKey);
            if ($dailyCount >= 20) {
                return [
                    'success' => false,
                    'message' => '今日发送次数已达上限（20次）',
                    'messageEn' => 'Daily limit reached (20 times)',
                    'statusCode' => 429
                ];
            }

            // 3. 检查IP每小时限制（10次）
            $ipKey = "sms_ip:{$clientIp}";
            $ipCount = (int)$this->redis->get($ipKey);
            if ($ipCount >= 10) {
                return [
                    'success' => false,
                    'message' => '同一IP每小时最多发送10次验证码',
                    'messageEn' => 'IP hourly limit reached (10 times)',
                    'statusCode' => 429
                ];
            }
        }

        // 生成6位验证码
        $code = $this->smsService->generateVerificationCode(6);

        // 发送短信
        $result = $this->smsService->sendVerificationCode($phone, $code);

        if ($result['success']) {
            // 使用Redis存储验证码和限制信息
            if ($this->redis->isConnected()) {
                // 存储验证码，10分钟有效期
                $codeKey = "sms_code:{$phone}";
                $this->redis->set($codeKey, $code, 600);

                // 设置60秒间隔限制
                $limitKey = "sms_limit:{$phone}";
                $this->redis->set($limitKey, 1, 60);

                // 增加每日计数
                $dailyKey = "sms_daily:{$phone}:{$today}";
                $count = $this->redis->incr($dailyKey);
                if ($count === 1) {
                    // 第一次计数，设置24小时过期
                    $this->redis->expire($dailyKey, 86400);
                }

                // 增加IP计数
                $ipKey = "sms_ip:{$clientIp}";
                $ipCount = $this->redis->incr($ipKey);
                if ($ipCount === 1) {
                    // 第一次计数，设置1小时过期
                    $this->redis->expire($ipKey, 3600);
                }
            }

            return [
                'success' => true,
                'message' => '验证码已发送',
                'messageEn' => 'Verification code sent',
                'statusCode' => 200,
                'code' => $code  // 仅用于测试，生产环境应删除
            ];
        } else {
            // 返回详细的错误信息，包括阿里云的错误代码
            $errorMessage = $result['message'];
            $errorDetails = '';

            // 如果有详细的错误信息，添加到消息中
            if (isset($result['data']['code'])) {
                $errorDetails = ' (错误代码: ' . $result['data']['code'] . ')';
            }
            if (isset($result['data']['message'])) {
                $errorDetails .= ' - ' . $result['data']['message'];
            }

            return [
                'success' => false,
                'message' => $errorMessage . $errorDetails,
                'messageEn' => 'Failed to send SMS',
                'errorCode' => $result['data']['code'] ?? null,
                'errorDetails' => $result['data'] ?? null,
                'statusCode' => 500
            ];
        }
    }

    /**
     * 验证短信验证码
     * 
     * @param string $phone 手机号码
     * @param string $code 验证码
     * @return array 包含 success, message, messageEn 等字段
     */
    public function verifyCode(string $phone, string $code): array
    {
        if (empty($phone) || empty($code)) {
            return [
                'success' => false,
                'message' => '手机号和验证码不能为空',
                'messageEn' => 'Phone and code cannot be empty',
                'statusCode' => 400
            ];
        }

        $storedCode = null;

        // 从 Redis 获取
        if ($this->redis->isConnected()) {
            $codeKey = "sms_code:{$phone}";
            $storedCode = $this->redis->get($codeKey);
        }

        // 检查验证码是否存在
        if (!$storedCode) {
            return [
                'success' => false,
                'message' => '验证码已过期或不存在',
                'messageEn' => 'Code expired or not found',
                'statusCode' => 400
            ];
        }

        // 验证验证码
        if ($code !== $storedCode) {
            return [
                'success' => false,
                'message' => '验证码错误',
                'messageEn' => 'Invalid code',
                'statusCode' => 400
            ];
        }

        // 验证成功，清除验证码
        if ($this->redis->isConnected()) {
            $codeKey = "sms_code:{$phone}";
            $this->redis->delete($codeKey);
        }

        return [
            'success' => true,
            'message' => '验证成功',
            'messageEn' => 'Verification successful',
            'statusCode' => 200
        ];
    }

    /**
     * 获取手机号今日剩余发送次数
     * 
     * @param string $phone 手机号码
     * @return int 剩余次数
     */
    public function getRemainingDailyCount(string $phone): int
    {
        if (!$this->redis->isConnected()) {
            return 20;  // Redis不可用时返回最大值
        }

        $today = date('Y-m-d');
        $dailyKey = "sms_daily:{$phone}:{$today}";
        $dailyCount = (int)$this->redis->get($dailyKey);

        return max(0, 20 - $dailyCount);
    }

    /**
     * 检查手机号是否可以发送验证码
     * 
     * @param string $phone 手机号码
     * @param string $clientIp 客户端IP
     * @return array 包含 canSend, reason 等字段
     */
    public function canSendCode(string $phone, string $clientIp): array
    {
        if (!$this->redis->isConnected()) {
            return ['canSend' => true];
        }

        // 检查60秒间隔
        $limitKey = "sms_limit:{$phone}";
        if ($this->redis->get($limitKey)) {
            return [
                'canSend' => false,
                'reason' => '60秒内只能发送一次'
            ];
        }

        // 检查每日限制
        $today = date('Y-m-d');
        $dailyKey = "sms_daily:{$phone}:{$today}";
        $dailyCount = (int)$this->redis->get($dailyKey);
        if ($dailyCount >= 20) {
            return [
                'canSend' => false,
                'reason' => '今日发送次数已达上限'
            ];
        }

        // 检查IP限制
        $ipKey = "sms_ip:{$clientIp}";
        $ipCount = (int)$this->redis->get($ipKey);
        if ($ipCount >= 10) {
            return [
                'canSend' => false,
                'reason' => 'IP每小时发送次数已达上限'
            ];
        }

        return ['canSend' => true];
    }
}
