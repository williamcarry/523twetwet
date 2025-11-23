<?php

namespace App\Service\Stats;

use App\Service\RedisService;

/**
 * 缓存统计服务
 * 提供统计数据的缓存功能
 * 
 * 缓存策略：
 * - 热数据（实时数据）：5分钟
 * - 温数据（今日/本周数据）：1小时
 * - 冷数据（历史数据）：24小时
 */
class CacheStatsService
{
    private RedisService $redis;
    
    // 缓存时间常量（秒）
    const TTL_HOT = 300;        // 5分钟
    const TTL_WARM = 3600;      // 1小时
    const TTL_COLD = 86400;     // 24小时
    
    public function __construct(RedisService $redis)
    {
        $this->redis = $redis;
    }
    
    /**
     * 获取缓存数据
     * 
     * @param string $key 缓存键
     * @return mixed|null
     */
    public function get(string $key)
    {
        if (!$this->redis->isConnected()) {
            return null;
        }
        
        return $this->redis->get($key);
    }
    
    /**
     * 设置缓存数据
     * 
     * @param string $key 缓存键
     * @param mixed $data 数据
     * @param int $ttl 过期时间（秒）
     * @return bool
     */
    public function set(string $key, $data, int $ttl = self::TTL_WARM): bool
    {
        if (!$this->redis->isConnected()) {
            return false;
        }
        
        return $this->redis->set($key, $data, $ttl);
    }
    
    /**
     * 设置热数据（5分钟缓存）
     * 
     * @param string $key 缓存键
     * @param mixed $data 数据
     * @return bool
     */
    public function setHot(string $key, $data): bool
    {
        return $this->set($key, $data, self::TTL_HOT);
    }
    
    /**
     * 设置温数据（1小时缓存）
     * 
     * @param string $key 缓存键
     * @param mixed $data 数据
     * @return bool
     */
    public function setWarm(string $key, $data): bool
    {
        return $this->set($key, $data, self::TTL_WARM);
    }
    
    /**
     * 设置冷数据（24小时缓存）
     * 
     * @param string $key 缓存键
     * @param mixed $data 数据
     * @return bool
     */
    public function setCold(string $key, $data): bool
    {
        return $this->set($key, $data, self::TTL_COLD);
    }
    
    /**
     * 删除缓存
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public function delete(string $key): bool
    {
        if (!$this->redis->isConnected()) {
            return false;
        }
        
        return $this->redis->delete($key);
    }
    
    /**
     * 批量删除缓存（按前缀）
     * 
     * @param string $prefix 缓存键前缀
     * @return int 删除的键数量
     */
    public function deleteByPrefix(string $prefix): int
    {
        if (!$this->redis->isConnected()) {
            return 0;
        }
        
        return $this->redis->deleteByPrefix($prefix);
    }
    
    /**
     * 检查Redis连接状态
     * 
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->redis->isConnected();
    }
}
