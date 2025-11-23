<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * 邮件验证码服务
 * 从数据库读取SMTP配置，支持多语言发送验证码邮件
 */
class EmailVerificationService
{
    private SiteConfigService $configService;
    private LoggerInterface $logger;
    private RedisService $redis;
    
    // 验证码过期时间（秒）
    private const CODE_TTL = 900; // 15分钟

    public function __construct(
        SiteConfigService $configService,
        LoggerInterface $logger,
        RedisService $redis
    ) {
        $this->configService = $configService;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    /**
     * 生成6位数字验证码
     * 
     * @return string
     */
    public function generateCode(): string
    {
        return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 发送验证码邮件并存储到Redis
     * 
     * @param string $toEmail 收件人邮箱
     * @param string $code 验证码
     * @param string $locale 语言环境 (默认: 'zh_CN', 可选: 'en')
     * @return bool 发送是否成功
     */
    public function sendVerificationCode(string $toEmail, string $code, string $locale = 'zh_CN'): bool
    {
        try {
            // 将验证码存储到Redis，15分钟过期
            $redisKey = $this->getRedisKey($toEmail);
            $this->redis->setRaw($redisKey, $code, self::CODE_TTL);
            
            $this->logger->info('验证码已存储到Redis', [
                'email' => $toEmail,
                'key' => $redisKey,
                'ttl' => self::CODE_TTL
            ]);
            // 从数据库获取SMTP配置
            $smtpHost = $this->configService->getConfigValue('email_smtp_host');
            $smtpPort = $this->configService->getConfigValue('email_smtp_port');
            $smtpUsername = $this->configService->getConfigValue('email_smtp_username');
            $smtpPassword = $this->configService->getConfigValue('email_smtp_password');
            $fromAddress = $this->configService->getConfigValue('email_from_address');
            
            // 验证必要配置是否存在
            if (!$smtpHost || !$smtpPort || !$smtpUsername || !$smtpPassword || !$fromAddress) {
                $this->logger->error('邮件配置不完整', [
                    'missing_config' => [
                        'smtp_host' => !$smtpHost,
                        'smtp_port' => !$smtpPort,
                        'smtp_username' => !$smtpUsername,
                        'smtp_password' => !$smtpPassword,
                        'from_address' => !$fromAddress,
                    ]
                ]);
                return false;
            }

            // 根据语言环境获取发件人名称和邮件标题
            $fromName = $locale === 'en' 
                ? ($this->configService->getConfigValue('email_from_name_en') ?: 'Platform')
                : ($this->configService->getConfigValue('email_from_name') ?: '平台');
            
            $emailTitle = $locale === 'en'
                ? ($this->configService->getConfigValue('email_verification_title_en') ?: 'Email Verification Code')
                : ($this->configService->getConfigValue('email_verification_title') ?: '邮箱验证码');

            // 使用 Socket 发送邮件
            $result = $this->sendEmailViaSocket(
                $smtpHost,
                $smtpPort,
                $smtpUsername,
                $smtpPassword,
                $fromAddress,
                $fromName,
                $toEmail,
                $emailTitle,
                $code,
                $locale
            );
            
            if (!$result) {
                $this->logger->error('Socket 发送邮件失败');
                return false;
            }

            $this->logger->info('验证码邮件发送成功', [
                'to' => $toEmail,
                'locale' => $locale
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('验证码邮件发送失败', [
                'to' => $toEmail,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * 构建HTML格式的邮件内容
     * 
     * @param string $code 验证码
     * @param string $locale 语言环境
     * @return string
     */
    private function buildEmailContent(string $code, string $locale): string
    {
        if ($locale === 'en') {
            return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
        .code { font-size: 32px; font-weight: bold; color: #4CAF50; text-align: center; 
                letter-spacing: 5px; padding: 20px; background-color: #fff; 
                border: 2px dashed #4CAF50; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #999; }
        .warning { color: #ff6b6b; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Email Verification Code</h1>
        </div>
        <div class="content">
            <p>Dear User,</p>
            <p>Your email verification code is:</p>
            <div class="code">{$code}</div>
            <p>This code will expire in <span class="warning">15 minutes</span>.</p>
            <p>If you did not request this code, please ignore this email.</p>
        </div>
        <div class="footer">
            <p>This is an automated email, please do not reply.</p>
        </div>
    </div>
</body>
</html>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
        .code { font-size: 32px; font-weight: bold; color: #4CAF50; text-align: center; 
                letter-spacing: 5px; padding: 20px; background-color: #fff; 
                border: 2px dashed #4CAF50; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #999; }
        .warning { color: #ff6b6b; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>邮箱验证码</h1>
        </div>
        <div class="content">
            <p>尊敬的用户，</p>
            <p>您的邮箱验证码是：</p>
            <div class="code">{$code}</div>
            <p>此验证码将在 <span class="warning">15分钟</span> 后失效。</p>
            <p>如果这不是您的操作，请忽略此邮件。</p>
        </div>
        <div class="footer">
            <p>这是一封自动发送的邮件，请勿回复。</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * 使用 Socket 直接发送邮件
     * 
     * @param string $smtpHost SMTP服务器
     * @param string $smtpPort SMTP端口
     * @param string $smtpUsername SMTP用户名
     * @param string $smtpPassword SMTP密码
     * @param string $fromAddress 发件人邮箱
     * @param string $fromName 发件人名称
     * @param string $toEmail 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $code 验证码
     * @param string $locale 语言
     * @return bool
     */
    private function sendEmailViaSocket(
        string $smtpHost,
        string $smtpPort,
        string $smtpUsername,
        string $smtpPassword,
        string $fromAddress,
        string $fromName,
        string $toEmail,
        string $subject,
        string $code,
        string $locale
    ): bool {
        $socket = null;
        try {
            // 确定加密类型
            $useSSL = ($smtpPort == '465');
            $useTLS = ($smtpPort == '587');
            
            // 建立连接
            if ($useSSL) {
                $socket = @fsockopen('ssl://' . $smtpHost, $smtpPort, $errno, $errstr, 30);
            } else {
                $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 30);
            }
            
            if (!$socket) {
                $this->logger->error('Socket 连接失败', ['error' => $errstr, 'errno' => $errno]);
                return false;
            }
            
            // 读取欢迎消息
            $response = fgets($socket, 512);
            $this->logger->debug('SMTP Response', ['response' => $response]);
            
            // EHLO
            fputs($socket, "EHLO {$smtpHost}\r\n");
            // 读取多行响应
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (preg_match('/^\d{3} /', $line)) {
                    break;
                }
            }
            
            // 如果需要 STARTTLS
            if ($useTLS) {
                fputs($socket, "STARTTLS\r\n");
                $response = fgets($socket, 512);
                
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return false;
                }
                
                // STARTTLS 后重新 EHLO
                fputs($socket, "EHLO {$smtpHost}\r\n");
                // 读取多行响应
                $response = '';
                while ($line = fgets($socket, 512)) {
                    $response .= $line;
                    if (preg_match('/^\d{3} /', $line)) {
                        break;
                    }
                }
            }
            
            // AUTH LOGIN
            fputs($socket, "AUTH LOGIN\r\n");
            $response = fgets($socket, 512);
            
            // 发送用户名
            fputs($socket, base64_encode($smtpUsername) . "\r\n");
            $response = fgets($socket, 512);
            
            if (strpos($response, '334') === false) {
                return false;
            }
            
            // 发送密码
            fputs($socket, base64_encode($smtpPassword) . "\r\n");
            $response = fgets($socket, 512);
            
            if (strpos($response, '235') === false) {
                return false;
            }
            
            // MAIL FROM
            fputs($socket, "MAIL FROM: <{$fromAddress}>\r\n");
            $response = fgets($socket, 512);
            
            // RCPT TO
            fputs($socket, "RCPT TO: <{$toEmail}>\r\n");
            $response = fgets($socket, 512);
            
            // DATA
            fputs($socket, "DATA\r\n");
            $response = fgets($socket, 512);
            
            // 构建邮件内容
            $htmlContent = $this->buildEmailContent($code, $locale);
            
            // 获取发件人域名
            $domain = substr(strrchr($fromAddress, '@'), 1);
            
            // 邮件头
            $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>\r\n";
            $headers .= "To: <{$toEmail}>\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "Message-ID: <" . uniqid() . "@{$domain}>\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headers .= "X-Priority: 1\r\n"; // 高优先级
            $headers .= "\r\n";
            
            // 发送头部
            fputs($socket, $headers);
            
            // 发送内容（Base64编码）
            $encodedContent = chunk_split(base64_encode($htmlContent));
            fputs($socket, $encodedContent);
            
            // 结束 DATA
            fputs($socket, "\r\n.\r\n");
            $response = fgets($socket, 512);
            
            if (strpos($response, '250') === false) {
                return false;
            }
            
            // QUIT
            fputs($socket, "QUIT\r\n");
            $response = fgets($socket, 512);
            
            // 关闭连接
            fclose($socket);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Socket 发送异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            if ($socket) {
                @fclose($socket);
            }
            return false;
        }
    }

    /**
     * 发送验证码并返回验证码（用于测试或需要返回验证码的场景）
     * 
     * @param string $toEmail 收件人邮箱
     * @param string $locale 语言环境
     * @return array ['success' => bool, 'code' => string|null]
     */
    public function sendAndGetCode(string $toEmail, string $locale = 'zh_CN'): array
    {
        $code = $this->generateCode();
        $success = $this->sendVerificationCode($toEmail, $code, $locale);
        
        return [
            'success' => $success,
            'code' => $success ? $code : null
        ];
    }
    
    /**
     * 验证验证码
     * 
     * @param string $email 邮箱地址
     * @param string $code 用户输入的验证码
     * @return bool 验证是否通过
     */
    public function verifyCode(string $email, string $code): bool
    {
        try {
            $redisKey = $this->getRedisKey($email);
            $storedCode = $this->redis->getRaw($redisKey);
            
            if ($storedCode === null || $storedCode === false) {
                $this->logger->warning('验证码不存在或已过期', [
                    'email' => $email,
                    'key' => $redisKey
                ]);
                return false;
            }
            
            // 验证码匹配
            if ($storedCode === $code) {
                // 验证成功后删除验证码（一次性使用）
                $this->redis->delete($redisKey);
                
                $this->logger->info('验证码验证成功', [
                    'email' => $email
                ]);
                
                return true;
            }
            
            $this->logger->warning('验证码不匹配', [
                'email' => $email,
                'input_code' => $code
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('验证码验证异常', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 生成Redis键名
     * 
     * @param string $email 邮箱地址
     * @return string
     */
    private function getRedisKey(string $email): string
    {
        return 'email_verification:' . strtolower($email);
    }
}
