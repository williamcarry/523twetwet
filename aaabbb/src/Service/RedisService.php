<?php

namespace App\Service;

/**
 * Redis 服务类
 * 提供 Redis 连接和数据操作的封装
 */
class RedisService
{
    private $redis;

    public function __construct()
    {
        $this->redis = null;
        try {
            $redisUrl = $_ENV['REDIS_KHUMFG'] ?? '';
            if (!empty($redisUrl) && extension_loaded('redis')) {
                $parsedUrl = parse_url($redisUrl);
                if ($parsedUrl) {
                    $this->redis = new \Redis();
                    $this->redis->connect($parsedUrl['host'], $parsedUrl['port'] ?? 6379);
                    if (isset($parsedUrl['pass']) && !empty($parsedUrl['pass'])) {
                        $this->redis->auth($parsedUrl['pass']);
                    }
                }
            }
        } catch (\Exception $e) {
            // Redis连接失败，记录日志但不中断服务
            error_log("Redis connection failed: " . $e->getMessage());
            $this->redis = null;
        }
    }

    /**
     * 从Redis获取数据
     *
     * @param string $key Redis键名
     * @return mixed 解析后的数据，如果获取失败或数据不存在则返回null
     */
    public function get(string $key)
    {
        if ($this->redis === null) {
            return null;
        }
        
        try {
            $data = $this->redis->get($key);
            if ($data !== false) {
                $decodedData = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decodedData;
                }
            }
            return null;
        } catch (\Exception $e) {
            error_log("Failed to get data from Redis: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 将数据存储到Redis
     *
     * @param string $key Redis键名
     * @param mixed $data 要存储的数据
     * @param int $ttl 过期时间（秒），默认3600秒（1小时）
     * @return bool 是否存储成功
     */
    public function set(string $key, $data, int $ttl = 3600): bool
    {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            $jsonData = json_encode($data);
            if ($jsonData === false) {
                return false;
            }
            return $this->redis->setex($key, $ttl, $jsonData);
        } catch (\Exception $e) {
            error_log("Failed to store data to Redis: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 从Redis删除数据
     *
     * @param string $key Redis键名
     * @return bool 是否删除成功
     */
    public function delete(string $key): bool
    {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            return $this->redis->del($key) > 0;
        } catch (\Exception $e) {
            error_log("Failed to delete data from Redis: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查Redis连接是否可用
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->redis !== null;
    }

    /**
     * 递增键的值
     *
     * @param string $key Redis键名
     * @return int|递增后的值，失败返回0
     */
    public function incr(string $key): int
    {
        if ($this->redis === null) {
            return 0;
        }
        
        try {
            return $this->redis->incr($key);
        } catch (\Exception $e) {
            error_log("Failed to increment key in Redis: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 设置键的过期时间
     *
     * @param string $key Redis键名
     * @param int $ttl 过期时间（秒）
     * @return bool 是否设置成功
     */
    public function expire(string $key, int $ttl): bool
    {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            return $this->redis->expire($key, $ttl);
        } catch (\Exception $e) {
            error_log("Failed to set expire time in Redis: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 存储原始字符串到Redis（不进行JSON编码）
     *
     * @param string $key Redis键名
     * @param string $value 要存储的字符串值
     * @param int $ttl 过期时间（秒）
     * @return bool 是否存储成功
     */
    public function setRaw(string $key, string $value, int $ttl): bool
    {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            return $this->redis->setex($key, $ttl, $value);
        } catch (\Exception $e) {
            error_log("Failed to store raw data to Redis: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取原始字符串（不进行JSON解码）
     *
     * @param string $key Redis键名
     * @return string|null 存储的原始字符串，失败或不存在返回null
     */
    public function getRaw(string $key): ?string
    {
        if ($this->redis === null) {
            return null;
        }
        
        try {
            $data = $this->redis->get($key);
            if ($data === false) {
                return null;
            }
            return $data;
        } catch (\Exception $e) {
            error_log("Failed to get raw data from Redis: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 按前缀批量删除Redis键
     *
     * @param string $prefix 键名前缀
     * @return int 删除的键数量
     */
    public function deleteByPrefix(string $prefix): int
    {
        if ($this->redis === null) {
            return 0;
        }
        
        try {
            // 使用SCAN命令扫描匹配的键，避免使用KEYS命令阻塞Redis
            $iterator = null;
            $deletedCount = 0;
            $pattern = $prefix . '*';
            
            do {
                // SCAN命令返回一个游标和匹配的键
                $keys = $this->redis->scan($iterator, $pattern, 100);
                
                if ($keys !== false && count($keys) > 0) {
                    // 批量删除键
                    $deleted = $this->redis->del($keys);
                    $deletedCount += $deleted;
                }
            } while ($iterator > 0);
            
            return $deletedCount;
        } catch (\Exception $e) {
            error_log("Failed to delete keys by prefix from Redis: " . $e->getMessage());
            return 0;
        }
    }
}