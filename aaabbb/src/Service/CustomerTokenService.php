<?php

namespace App\Service;

use App\Entity\Customer;
use App\Repository\CustomerRepository;

/**
 * Customer Token 服务
 * 使用 Redis 管理 Token，支持双Token机制（访问令牌2小时，刷新令牌7天）
 */
class CustomerTokenService
{
    private \Redis $redis;
    private CustomerRepository $customerRepository;
    
    // Token 有效期常量
    private const ACCESS_TOKEN_TTL = 7200;      // 2小时
    private const REFRESH_TOKEN_TTL = 604800;   // 7天
    
    public function __construct(CustomerRepository $customerRepository)
    {
        $this->customerRepository = $customerRepository;
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
     * 生成双Token（访问令牌 + 刷新令牌）
     * 
     * @param Customer $customer 用户对象
     * @param array $deviceInfo 设备信息（userAgent, ip等）
     * @return array ['accessToken' => string, 'refreshToken' => string, 'signatureKey' => string, 'expiresIn' => int]
     */
    public function generateTokens(Customer $customer, array $deviceInfo = []): array
    {
        $userId = $customer->getId();
        $now = time();
        
        // 生成唯一的 Token ID
        $tokenId = uniqid('token_', true);
        
        // 生成临时签名密钥（与用户绑定）
        $signatureKey = bin2hex(random_bytes(32));  // 64位随机密钥
        
        // 生成访问令牌（简化版本，生产环境建议使用 JWT）
        $accessToken = $this->generateToken($userId, $tokenId, 'access', $now);
        
        // 生成刷新令牌
        $refreshToken = $this->generateToken($userId, $tokenId, 'refresh', $now);
        
        // 准备存储数据
        $tokenData = json_encode([
            'userId' => $userId,
            'username' => $customer->getUsername(),
            'deviceInfo' => $deviceInfo,
            'signatureKey' => $signatureKey,  // 保存签名密钥
            'createdAt' => $now,
            'lastUsedAt' => $now
        ]);
        
        // 存储访问令牌到 Redis（2小时TTL）
        $this->redis->setex(
            "customer:access_token:{$tokenId}",
            self::ACCESS_TOKEN_TTL,
            $tokenData
        );
        
        // 存储刷新令牌到 Redis（7天TTL）
        $this->redis->setex(
            "customer:refresh_token:{$tokenId}",
            self::REFRESH_TOKEN_TTL,
            $tokenData
        );
        
        // 记录用户的所有活跃Token（用于管理和撤销）
        $this->redis->sAdd("customer:user_tokens:{$userId}", $tokenId);
        $this->redis->expire("customer:user_tokens:{$userId}", self::REFRESH_TOKEN_TTL);
        
        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'signatureKey' => $signatureKey,  // 返回签名密钥给前端
            'expiresIn' => self::ACCESS_TOKEN_TTL,
            'tokenType' => 'Bearer'
        ];
    }
    
    /**
     * 生成 Token 字符串
     * 格式：base64(tokenId:userId:type:timestamp:hash)
     */
    private function generateToken(int $userId, string $tokenId, string $type, int $timestamp): string
    {
        $secret = $_ENV['APP_SECRET'] ?? throw new \RuntimeException('APP_SECRET not configured');
        $data = "{$tokenId}:{$userId}:{$type}:{$timestamp}";
        $hash = hash_hmac('sha256', $data, $secret);
        
        return base64_encode("{$tokenId}:{$userId}:{$type}:{$timestamp}:{$hash}");
    }
    
    /**
     * 验证访问令牌并返回 Customer 对象
     * 
     * @param string $token 访问令牌
     * @return Customer|null 返回用户对象，失败返回null
     */
    public function validateAccessToken(string $token): ?Customer
    {
        try {
            // 解析 Token
            $decoded = base64_decode($token);
            if (!$decoded) {
                return null;
            }
            
            $parts = explode(':', $decoded);
            if (count($parts) !== 5) {
                return null;
            }
            
            [$tokenId, $userId, $type, $timestamp, $hash] = $parts;
            
            // 验证类型
            if ($type !== 'access') {
                return null;
            }
            
            // 验证签名
            $secret = $_ENV['APP_SECRET'] ?? throw new \RuntimeException('APP_SECRET not configured');
            $expectedHash = hash_hmac('sha256', "{$tokenId}:{$userId}:{$type}:{$timestamp}", $secret);
            if (!hash_equals($expectedHash, $hash)) {
                return null;
            }
            
            // 从 Redis 检查 Token 是否存在且未过期
            $redisKey = "customer:access_token:{$tokenId}";
            $exists = $this->redis->exists($redisKey);
            
            if (!$exists) {
                return null; // Token 已过期或被撤销
            }
            
            // 更新最后使用时间
            $tokenData = json_decode($this->redis->get($redisKey), true);
            if ($tokenData) {
                $tokenData['lastUsedAt'] = time();
                $ttl = $this->redis->ttl($redisKey);
                if ($ttl > 0) {
                    $this->redis->setex($redisKey, $ttl, json_encode($tokenData));
                }
            }
            
            // 加载用户
            return $this->customerRepository->find((int)$userId);
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 使用刷新令牌获取新的访问令牌
     * 
     * @param string $refreshToken 刷新令牌
     * @return array|null 返回新的Token对，失败返回null
     */
    public function refreshToken(string $refreshToken): ?array
    {
        try {
            // 解析 Refresh Token
            $decoded = base64_decode($refreshToken);
            if (!$decoded) {
                return null;
            }
            
            $parts = explode(':', $decoded);
            if (count($parts) !== 5) {
                return null;
            }
            
            [$tokenId, $userId, $type, $timestamp, $hash] = $parts;
            
            // 验证类型
            if ($type !== 'refresh') {
                return null;
            }
            
            // 验证签名
            $secret = $_ENV['APP_SECRET'] ?? throw new \RuntimeException('APP_SECRET not configured');
            $expectedHash = hash_hmac('sha256', "{$tokenId}:{$userId}:{$type}:{$timestamp}", $secret);
            if (!hash_equals($expectedHash, $hash)) {
                return null;
            }
            
            // 检查 Refresh Token 是否存在
            $refreshKey = "customer:refresh_token:{$tokenId}";
            $exists = $this->redis->exists($refreshKey);
            
            if (!$exists) {
                return null; // Refresh Token 已过期或被撤销
            }
            
            // 删除旧的 Access Token
            $this->redis->del("customer:access_token:{$tokenId}");
            
            // 获取原始 Token 数据
            $tokenData = json_decode($this->redis->get($refreshKey), true);
            
            // 加载用户
            $customer = $this->customerRepository->find((int)$userId);
            if (!$customer) {
                return null;
            }
            
            // 生成新的 Token 对
            return $this->generateTokens($customer, $tokenData['deviceInfo'] ?? []);
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 撤销 Token（登出）
     * 
     * @param string $token 访问令牌或刷新令牌
     * @return bool 是否成功撤销
     */
    public function revokeToken(string $token): bool
    {
        try {
            $decoded = base64_decode($token);
            if (!$decoded) {
                return false;
            }
            
            $parts = explode(':', $decoded);
            if (count($parts) !== 5) {
                return false;
            }
            
            [$tokenId, $userId] = $parts;
            
            // 删除 Access Token 和 Refresh Token
            $this->redis->del("customer:access_token:{$tokenId}");
            $this->redis->del("customer:refresh_token:{$tokenId}");
            
            // 从用户 Token 列表中移除
            $this->redis->sRem("customer:user_tokens:{$userId}", $tokenId);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 撤销用户的所有 Token（强制全部设备下线）
     * 
     * @param int $userId 用户ID
     * @return int 撤销的Token数量
     */
    public function revokeAllUserTokens(int $userId): int
    {
        try {
            // 获取用户所有 Token
            $tokenIds = $this->redis->sMembers("customer:user_tokens:{$userId}");
            
            if (empty($tokenIds)) {
                return 0;
            }
            
            $count = 0;
            foreach ($tokenIds as $tokenId) {
                $this->redis->del("customer:access_token:{$tokenId}");
                $this->redis->del("customer:refresh_token:{$tokenId}");
                $count++;
            }
            
            // 清空用户 Token 列表
            $this->redis->del("customer:user_tokens:{$userId}");
            
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * 获取用户的活跃 Token 数量
     * 
     * @param int $userId 用户ID
     * @return int Token数量
     */
    public function getUserActiveTokenCount(int $userId): int
    {
        try {
            return (int)$this->redis->sCard("customer:user_tokens:{$userId}");
        } catch (\Exception $e) {
            return 0;
        }
    }
}
