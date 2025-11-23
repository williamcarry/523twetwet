<?php

namespace App\Service;

/**
 * API签名服务
 * 
 * 作用：防止支付等关键接口的参数被篡改（如修改金额、商品ID等）
 * 
 * 工作原理：
 * 1. 前端：把所有参数按字母排序，拼接后用密钥生成签名，一起发给后端
 * 2. 后端：收到后用同样方法重新算一遍签名，对比是否一致
 * 3. 如果参数被改过，签名就对不上，后端拒绝请求
 * 
 * 防护：
 * - 防篡改：参数改了签名就错
 * - 防重放：timestamp（5分钟过期）+ nonce（不能重复使用）
 * 
 * 新增：使用用户临时签名密钥（登录时生成）
 */
class ApiSignatureService
{
    private \Redis $redis;
    
    /**
     * 签名有效期（秒）
     * 防止重放攻击
     */
    private const SIGNATURE_VALIDITY = 300; // 5分钟
    
    public function __construct()
    {
        $this->initRedis();
    }
    
    /**
     * 初始化 Redis 连接
     */
    private function initRedis(): void
    {
        try {
            if (!extension_loaded('redis') || !class_exists('\Redis')) {
                throw new \RuntimeException('Redis extension not available');
            }
            
            $this->redis = new \Redis();
            $redisUrl = $_ENV['REDIS_KHUMFG'] ?? 'redis://localhost:6379';
            $parsedUrl = parse_url($redisUrl);
            
            $host = $parsedUrl['host'] ?? 'localhost';
            $port = $parsedUrl['port'] ?? 6379;
            $password = isset($parsedUrl['pass']) ? urldecode($parsedUrl['pass']) : null;
            
            $this->redis->connect($host, $port);
            
            if ($password) {
                $this->redis->auth($password);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to connect to Redis: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取签名密钥（从用户Token中获取）
     * @param string $accessToken 用户的accessToken
     * @return string 签名密钥
     * @throws \RuntimeException 如果无法获取签名密钥
     */
    private function getSecretKeyFromToken(string $accessToken): string
    {
        // 解析Token获取tokenId
        $decoded = base64_decode($accessToken);
        if (!$decoded) {
            throw new \RuntimeException('Invalid access token format');
        }
        
        $parts = explode(':', $decoded);
        if (count($parts) !== 5) {
            throw new \RuntimeException('Invalid access token structure');
        }
        
        $tokenId = $parts[0];
        
        // 从Redis获取Token数据
        $tokenDataJson = $this->redis->get("customer:access_token:{$tokenId}");
        if (!$tokenDataJson) {
            throw new \RuntimeException('Token not found or expired');
        }
        
        $tokenData = json_decode($tokenDataJson, true);
        if (!isset($tokenData['signatureKey'])) {
            throw new \RuntimeException('Signature key not found in token');
        }
        
        return $tokenData['signatureKey'];
    }
    
    /**
     * 获取签名密钥（必须从用户Token中获取，不提供降级方案）
     * @param string|null $accessToken 用户的accessToken
     * @return string 签名密钥
     * @throws \RuntimeException 如果无法获取签名密钥（安全要求：拒绝降级策略）
     */
    private function getSecretKey(?string $accessToken = null): string
    {
        if (!$accessToken) {
            throw new \RuntimeException('Access token is required for API signature verification');
        }
        
        // 必须从用户Token中获取临时签名密钥，禁止降级使用全局密钥
        // 防止攻击者通过触发降级逻辑使用可能被逆向的全局密钥伪造签名
        return $this->getSecretKeyFromToken($accessToken);
    }
    
    /**
     * 生成API签名
     * 
     * @param array $params 请求参数（不包含signature字段）
     * @param int|null $timestamp 时间戳（可选，不传则使用当前时间）
     * @param string|null $nonce 随机字符串（可选，不传则自动生成）
     * @return array 包含signature、timestamp、nonce的数组
     */
    public function generateSignature(array $params, ?int $timestamp = null, ?string $nonce = null): array
    {
        // 生成timestamp和nonce
        $timestamp = $timestamp ?? time();
        $nonce = $nonce ?? $this->generateNonce();
        
        // 添加timestamp和nonce到参数中
        $params['timestamp'] = $timestamp;
        $params['nonce'] = $nonce;
        
        // 生成签名字符串
        $signString = $this->buildSignString($params);
        
        // 使用HMAC-SHA256生成签名
        $signature = hash_hmac('sha256', $signString, $this->getSecretKey());
        
        return [
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce
        ];
    }
    
    /**
     * 验证API签名
     * 
     * @param array $params 包含signature、timestamp、nonce的请求参数
     * @param string &$errorMessage 错误信息（引用传递）
     * @param string|null $accessToken 用户accessToken（用于获取签名密钥）
     * @return bool 签名是否有效
     */
    public function verifySignature(array $params, string &$errorMessage = '', ?string $accessToken = null): bool
    {
        // 1. 检查必要参数
        if (!isset($params['signature'])) {
            $errorMessage = '缺少签名参数';
            return false;
        }
        
        if (!isset($params['timestamp'])) {
            $errorMessage = '缺少时间戳参数';
            return false;
        }
        
        if (!isset($params['nonce'])) {
            $errorMessage = '缺少随机数参数';
            return false;
        }
        
        $receivedSignature = $params['signature'];
        $timestamp = (int)$params['timestamp'];
        $nonce = $params['nonce'];
        
        // 2. 验证时间戳（防止重放攻击）
        $currentTime = time();
        $timeDiff = abs($currentTime - $timestamp);
        
        if ($timeDiff > self::SIGNATURE_VALIDITY) {
            $errorMessage = sprintf(
                '请求已过期（时间差：%d秒，允许：%d秒）',
                $timeDiff,
                self::SIGNATURE_VALIDITY
            );
            return false;
        }
        
        // 3. 验证nonce格式（至少16位）
        if (strlen($nonce) < 16) {
            $errorMessage = '随机数格式无效';
            return false;
        }
        
        // 4. 移除signature参数，准备重新计算签名
        $paramsForSign = $params;
        unset($paramsForSign['signature']);
        
        // 5. 重新生成签名字符串
        $signString = $this->buildSignString($paramsForSign);
        
        // 6. 计算期望的签名（使用用户的签名密钥）
        $expectedSignature = hash_hmac('sha256', $signString, $this->getSecretKey($accessToken));
        
        // 7. 使用时间安全的比较函数
        if (!hash_equals($expectedSignature, $receivedSignature)) {
            $errorMessage = '签名验证失败';
            return false;
        }
        
        return true;
    }
    
    /**
     * 构建待签名字符串
     * 
     * 规则：
     * 1. 移除signature字段
     * 2. 按key升序排序
     * 3. 拼接成 key1=value1&key2=value2 格式
     * 4. 对数组和对象进行JSON编码
     * 
     * @param array $params 参数数组
     * @return string 待签名字符串
     */
    private function buildSignString(array $params): string
    {
        // 移除signature字段（如果存在）
        unset($params['signature']);
        
        // 按key升序排序
        ksort($params);
        
        // 构建签名字符串
        $parts = [];
        foreach ($params as $key => $value) {
            // 处理不同类型的值
            if (is_array($value) || is_object($value)) {
                // 数组和对象转JSON
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                // 布尔值转字符串
                $value = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                // null转空字符串
                $value = '';
            } else {
                // 其他类型转字符串
                $value = (string)$value;
            }
            
            $parts[] = $key . '=' . $value;
        }
        
        return implode('&', $parts);
    }
    
    /**
     * 生成随机nonce
     * 
     * @param int $length nonce长度（默认32位）
     * @return string 随机字符串
     */
    private function generateNonce(int $length = 32): string
    {
        try {
            // 使用加密安全的随机数生成器
            return bin2hex(random_bytes($length / 2));
        } catch (\Exception $e) {
            // 降级方案：使用uniqid
            return md5(uniqid((string)mt_rand(0, 999999), true));
        }
    }
    
    /**
     * 验证nonce是否已使用（防止重放攻击）
     * 
     * 需要配合Redis使用，存储已使用的nonce
     * 
     * @param string $nonce 随机数
     * @param int $ttl 有效期（秒）
     * @return bool true=未使用，false=已使用
     */
    public function checkNonceUnique(string $nonce, int $ttl = self::SIGNATURE_VALIDITY): bool
    {
        try {
            // 检查Redis扩展
            if (!extension_loaded('redis') || !class_exists('\Redis')) {
                // Redis不可用，跳过nonce检查
                return true;
            }
            
            $redis = new \Redis();
            $redisUrl = $_ENV['REDIS_KHUMFG'] ?? 'redis://localhost:6379';
            $parsedUrl = parse_url($redisUrl);
            
            $host = $parsedUrl['host'] ?? 'localhost';
            $port = $parsedUrl['port'] ?? 6379;
            $password = isset($parsedUrl['pass']) ? urldecode($parsedUrl['pass']) : null;
            
            $redis->connect($host, $port);
            if ($password) {
                $redis->auth($password);
            }
            
            $key = "api:nonce:{$nonce}";
            
            // 检查nonce是否存在
            if ($redis->exists($key)) {
                // nonce已使用
                return false;
            }
            
            // 标记nonce为已使用
            $redis->setex($key, $ttl, '1');
            
            return true;
        } catch (\Exception $e) {
            // Redis异常，记录日志但不阻止请求
            error_log('Nonce check failed: ' . $e->getMessage());
            return true;
        }
    }
    
    /**
     * 生成客户端签名示例代码（用于文档）
     * 
     * @return string JavaScript代码示例
     */
    public function getClientSignatureExample(): string
    {
        return <<<'JS'
// JavaScript 客户端签名生成示例
// 使用登录时获取的临时密钥
import apiSignature from './apiSignature'  // 导入签名工具

// 登录成功后保存密钥
if (loginResponse.apiSignKey) {
    apiSignature.setKey(loginResponse.apiSignKey)
}

// 需要签名的请求
const requestData = {
    productId: 123,
    quantity: 2,
    region: 'US'
}

// 生成签名（自动使用保存的临时密钥）
const signedData = apiSignature.sign(requestData)
// signedData 包含: productId, quantity, region, timestamp, nonce, signature

// 发送请求
fetch('/shop/api/item-detail/confirm-payment', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify(signedData)
})
JS;
    }
}
