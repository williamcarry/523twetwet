<?php

namespace App\Service;

use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use Qiniu\Storage\BucketManager;
use Qiniu\Config;

class QiniuUploadService
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $domain;  // 七牛云绑定的域名
    private Auth $auth;
    private UploadManager $uploadManager;
    private BucketManager $bucketManager;
    private $redis;

    public function __construct()
    {
        $this->accessKey = $_ENV['QINIU_AK'] ?? '';
        $this->secretKey = $_ENV['QINIU_SK'] ?? '';
        $this->bucket = $_ENV['QINIU_BUCKET'] ?? '';
        $this->domain = $_ENV['QINIU_DOMAIN'] ?? '';
        
        if (empty($this->accessKey) || empty($this->secretKey) || empty($this->bucket)) {
            throw new \Exception('七牛云配置不完整，请检查.env文件中的QINIU_AK、QINIU_SK和QINIU_BUCKET配置');
        }
        
        // 如果没有配置域名，使用默认域名
        if (empty($this->domain)) {
            $this->domain = "http://{$this->bucket}.qiniudn.com";
        }
        
        $this->auth = new Auth($this->accessKey, $this->secretKey);
        $this->uploadManager = new UploadManager();
        $this->bucketManager = new BucketManager($this->auth);
        
        // 初始化Redis连接
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
     * 从Redis缓存获取URL
     *
     * @param string $key 文件key
     * @return string|null 缓存的URL，如果不存在则返回null
     */
    private function getUrlFromCache(string $key): ?string
    {
        if ($this->redis === null) {
            return null;
        }
        
        try {
            $cachedUrl = $this->redis->get("qiniu_url_cache:{$key}");
            return $cachedUrl !== false ? $cachedUrl : null;
        } catch (\Exception $e) {
            error_log("Failed to get URL from Redis cache: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 将URL存储到Redis缓存
     *
     * @param string $key 文件key
     * @param string $url 文件URL
     * @param int $ttl 缓存有效期（秒），默认59分钟
     * @return bool 是否存储成功
     */
    private function storeUrlToCache(string $key, string $url, int $ttl = 3540): bool
    {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            return $this->redis->setex("qiniu_url_cache:{$key}", $ttl, $url);
        } catch (\Exception $e) {
            error_log("Failed to store URL to Redis cache: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 从Redis缓存中删除URL
     *
     * @param string $key 文件key
     * @return bool 是否删除成功
     */
    private function deleteUrlFromCache(string $key): bool
    {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            return $this->redis->del("qiniu_url_cache:{$key}") > 0;
        } catch (\Exception $e) {
            error_log("Failed to delete URL from Redis cache: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 上传文件到七牛云
     *
     * @param string $filePath 本地文件路径
     * @param string|null $key 文件在七牛云存储的key（文件名），为空则自动生成
     * @return array 上传结果 ['success' => bool, 'url' => string, 'key' => string, 'error' => string]
     */
    public function uploadFile(string $filePath, ?string $key = null): array
    {
        try {
            // 生成上传Token
            $token = $this->auth->uploadToken($this->bucket);
            
            // 上传文件
            list($ret, $err) = $this->uploadManager->putFile($token, $key, $filePath);
            
            if ($err !== null) {
                return [
                    'success' => false,
                    'url' => '',
                    'key' => '',
                    'error' => '七牛云上传失败: ' . $err->message()
                ];
            } else {
                $url = $this->getPrivateUrl($ret['key']);
                // 上传成功，把url压入redis 有效期59分钟
                $this->storeUrlToCache($ret['key'], $url);
                return [
                    'success' => true,
                    'url' => $url,
                    'key' => $ret['key'],
                    'error' => ''
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'url' => '',
                'key' => '',
                'error' => '上传过程中发生异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 上传文件内容到七牛云
     *
     * @param string $content 文件内容
     * @param string|null $key 文件在七牛云存储的key（文件名），为空则自动生成
     * @return array 上传结果 ['success' => bool, 'url' => string, 'key' => string, 'error' => string]
     */
    public function uploadContent(string $content, ?string $key = null): array
    {
        try {
            // 生成上传Token
            $token = $this->auth->uploadToken($this->bucket);
            
            // 上传文件内容
            list($ret, $err) = $this->uploadManager->put($token, $key, $content);
            
            if ($err !== null) {
                return [
                    'success' => false,
                    'url' => '',
                    'key' => '',
                    'error' => '七牛云上传失败: ' . $err->message()
                ];
            } else {
                $url = $this->getPrivateUrl($ret['key']);
                // 上传成功，把url压入redis 有效期59分钟
                $this->storeUrlToCache($ret['key'], $url);
                return [
                    'success' => true,
                    'url' => $url,
                    'key' => $ret['key'],
                    'error' => ''
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'url' => '',
                'key' => '',
                'error' => '上传过程中发生异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 删除七牛云上的文件
     *
     * @param string $key 文件key
     * @return bool 删除是否成功
     */
    public function deleteFile(string $key): bool
    {
        try {
            $err = $this->bucketManager->delete($this->bucket, $key);
            // 删除redis 对应的key
            $this->deleteUrlFromCache($key);
            
            // 如果没有错误，删除成功
            if ($err === null) {
                return true;
            }
            
            // 检查返回值的类型
            if (is_array($err)) {
                // 七牛云SDK的delete方法返回的是[$ret, $err]数组
                // 如果第二个元素为null，表示删除成功
                if (count($err) >= 2 && $err[1] === null) {
                    return true;
                }
                
                // 如果有错误信息，检查是否是文件不存在的错误
                if (count($err) >= 2 && $err[1] !== null) {
                    $errorMessage = '';
                    if (is_object($err[1])) {
                        if (method_exists($err[1], 'message')) {
                            $errorMessage = $err[1]->message();
                        } else {
                            $errorMessage = json_encode($err[1]);
                        }
                    } elseif (is_array($err[1])) {
                        $errorMessage = json_encode($err[1]);
                    } else {
                        $errorMessage = (string)$err[1];
                    }
                    
                    // 如果是文件不存在的错误，则认为删除成功
                    if (strpos($errorMessage, 'no such file or directory') !== false || 
                        strpos($errorMessage, 'file not exist') !== false ||
                        strpos($errorMessage, '文件不存在') !== false) {
                        return true;
                    }
                    
                    // 记录错误日志
                    error_log("七牛云文件删除失败，Key: {$key}，错误: {$errorMessage}");
                    return false;
                }
                
                // 其他情况认为删除成功
                return true;
            }
            
            // 如果有错误，检查错误类型
            $errorMessage = '';
            if (is_object($err)) {
                if (method_exists($err, 'message')) {
                    $errorMessage = $err->message();
                } else {
                    $errorMessage = json_encode($err);
                }
            } elseif (is_array($err)) {
                $errorMessage = json_encode($err);
            } else {
                $errorMessage = (string)$err;
            }
            
            // 如果是文件不存在的错误，则认为删除成功（因为我们的目标是确保文件不存在）
            if (strpos($errorMessage, 'no such file or directory') !== false || 
                strpos($errorMessage, 'file not exist') !== false ||
                strpos($errorMessage, '文件不存在') !== false) {
                return true;
            }
            
            // 记录错误日志
            error_log("七牛云文件删除失败，Key: {$key}，错误: {$errorMessage}");
            return false;
        } catch (\Exception $e) {
            // 文件不存在的异常也认为是删除成功
            $exceptionMessage = $e->getMessage();
            if (strpos($exceptionMessage, 'no such file or directory') !== false || 
                strpos($exceptionMessage, 'file not exist') !== false ||
                strpos($exceptionMessage, '文件不存在') !== false) {
                return true;
            }
            
            // 记录异常日志
            error_log("七牛云文件删除异常，Key: {$key}，异常: " . $exceptionMessage);
            return false;
        }
    }

    /**
     * 获取文件访问URL（私有链接，带签名，有效期1小时）
     *
     * @param string $key 文件key
     * @return string 文件访问URL
     */
    public function getPrivateUrl(string $key): string
    {
        // 先从redis获取，redis没有再签名
        $cachedUrl = $this->getUrlFromCache($key);
        if ($cachedUrl !== null) {
            return $cachedUrl;
        }
        
        // 生成带签名的私有URL，有效期为1小时（3600秒）
        $baseUrl = rtrim($this->domain, '/') . '/' . ltrim($key, '/');
        $signedUrl = $this->auth->privateDownloadUrl($baseUrl, 3600);
        // 上传成功，把url压入redis 有效期59分钟
        $this->storeUrlToCache($key, $signedUrl);
        return $signedUrl;
    }

    /**
     * 获取公共文件访问URL（不带签名）
     *
     * @param string $key 文件key
     * @return string 文件访问URL
     */
    public function getPublicUrl(string $key): string
    {
        $baseUrl = rtrim($this->domain, '/') . '/' . ltrim($key, '/');
        return $baseUrl;
    }

    /**
     * 检查七牛云配置是否有效
     *
     * @return bool 配置是否有效
     */
    public function isConfigValid(): bool
    {
        return !empty($this->accessKey) && !empty($this->secretKey) && !empty($this->bucket);
    }

    /**
     * 获取BucketManager实例
     *
     * @return BucketManager
     */
    public function getBucketManager(): BucketManager
    {
        return $this->bucketManager;
    }

    /**
     * 获取Bucket名称
     *
     * @return string
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * 验证图片是否为正方形
     *
     * @param string $filePath 本地文件路径
     * @return array ['isSquare' => bool, 'width' => int, 'height' => int, 'error' => string]
     */
    public function validateSquareImage(string $filePath): array
    {
        try {
            // 获取图片信息
            $imageInfo = getimagesize($filePath);
            
            if ($imageInfo === false) {
                return [
                    'isSquare' => false,
                    'width' => 0,
                    'height' => 0,
                    'error' => '无法读取图片信息'
                ];
            }
            
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            
            return [
                'isSquare' => $width === $height,
                'width' => $width,
                'height' => $height,
                'error' => ''
            ];
        } catch (\Exception $e) {
            return [
                'isSquare' => false,
                'width' => 0,
                'height' => 0,
                'error' => '验证图片失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 使用七牛云imageInfo接口验证图片是否为正方形（快速，无需下载图片）
     *
     * @param string $imageKey 图片key
     * @return array ['isSquare' => bool, 'width' => int, 'height' => int, 'error' => string]
     */
    public function validateSquareImageByKey(string $imageKey): array
    {
        try {
            // 构建七牛云imageInfo接口URL
            // 格式：http://domain/key?imageInfo
            $baseUrl = rtrim($this->domain, '/') . '/' . ltrim($imageKey, '/');
            $imageInfoUrl = $baseUrl . '?imageInfo';
            
            // 对于私有空间，需要给imageInfo URL添加签名
            $signedUrl = $this->auth->privateDownloadUrl($imageInfoUrl, 3600);
            
            // 调用imageInfo接口
            $response = @file_get_contents($signedUrl);
            
            if ($response === false) {
                return [
                    'isSquare' => false,
                    'width' => 0,
                    'height' => 0,
                    'error' => '无法获取图片信息'
                ];
            }
            
            // 解析JSON响应
            $imageInfo = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !isset($imageInfo['width']) || !isset($imageInfo['height'])) {
                return [
                    'isSquare' => false,
                    'width' => 0,
                    'height' => 0,
                    'error' => '解析图片信息失败'
                ];
            }
            
            $width = (int)$imageInfo['width'];
            $height = (int)$imageInfo['height'];
            
            return [
                'isSquare' => $width === $height,
                'width' => $width,
                'height' => $height,
                'error' => ''
            ];
        } catch (\Exception $e) {
            return [
                'isSquare' => false,
                'width' => 0,
                'height' => 0,
                'error' => '验证图片失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 压缩图片为400x400的缩略图
     *
     * @param string $sourceFilePath 源文件路径
     * @param string $outputFilePath 输出文件路径
     * @return array ['success' => bool, 'error' => string]
     */
    public function compressToThumbnail(string $sourceFilePath, string $outputFilePath): array
    {
        try {
            // 获取源图片信息
            $imageInfo = getimagesize($sourceFilePath);
            
            if ($imageInfo === false) {
                return [
                    'success' => false,
                    'error' => '无法读取图片信息'
                ];
            }
            
            $mimeType = $imageInfo['mime'];
            
            // 创建源图片资源
            $sourceImage = null;
            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $sourceImage = imagecreatefromjpeg($sourceFilePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($sourceFilePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($sourceFilePath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($sourceFilePath);
                    break;
                default:
                    return [
                        'success' => false,
                        'error' => '不支持的图片格式: ' . $mimeType
                    ];
            }
            
            if (!$sourceImage) {
                return [
                    'success' => false,
                    'error' => '创建图片资源失败'
                ];
            }
            
            // 创建400x400的缩略图资源
            $thumbnailImage = imagecreatetruecolor(400, 400);
            
            // 保持透明度（针对PNG和GIF）
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($thumbnailImage, false);
                imagesavealpha($thumbnailImage, true);
                $transparent = imagecolorallocatealpha($thumbnailImage, 255, 255, 255, 127);
                imagefilledrectangle($thumbnailImage, 0, 0, 400, 400, $transparent);
            }
            
            // 缩放图片到400x400
            imagecopyresampled(
                $thumbnailImage,
                $sourceImage,
                0, 0, 0, 0,
                400, 400,
                imagesx($sourceImage),
                imagesy($sourceImage)
            );
            
            // 保存缩略图（统一使用JPEG格式，质量85）
            $saveResult = imagejpeg($thumbnailImage, $outputFilePath, 85);
            
            // 释放资源
            imagedestroy($sourceImage);
            imagedestroy($thumbnailImage);
            
            if (!$saveResult) {
                return [
                    'success' => false,
                    'error' => '保存缩略图失败'
                ];
            }
            
            return [
                'success' => true,
                'error' => ''
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => '压缩图片失败: ' . $e->getMessage()
            ];
        }
    }
}