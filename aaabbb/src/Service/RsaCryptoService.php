<?php

namespace App\Service;

/**
 * RSA 加解密服务
 * 
 * 提供 RSA 非对称加解密功能
 */
class RsaCryptoService
{
    private string $privateKeyPath;

    public function __construct(string $projectDir)
    {
        $this->privateKeyPath = $projectDir . '/src/common/rsa_private_key.pem';
    }

    /**
     * 使用私钥解密数据
     * 
     * @param string $encryptedData Base64编码的加密数据
     * @return string 解密后的数据
     * @throws \RuntimeException
     */
    public function decrypt(string $encryptedData): string
    {
        if (!file_exists($this->privateKeyPath)) {
            throw new \RuntimeException('私钥文件不存在: ' . $this->privateKeyPath);
        }

        $privateKey = file_get_contents($this->privateKeyPath);
        if ($privateKey === false) {
            throw new \RuntimeException('无法读取私钥文件: ' . $this->privateKeyPath);
        }

        // Base64 解码
        $encrypted = base64_decode($encryptedData);
        if ($encrypted === false) {
            throw new \RuntimeException('无效的 Base64 数据');
        }

        // 解密
        $decrypted = '';
        if (!openssl_private_decrypt($encrypted, $decrypted, $privateKey)) {
            throw new \RuntimeException('解密失败: ' . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * 解密整个JSON对象（分段解密）
     * 
     * @param string $encryptedPayload 加密的负载数据（多个分段用|||分隔）
     * @return array 解密后的数据数组
     * @throws \RuntimeException
     */
    public function decryptObject(string $encryptedPayload): array
    {
        if (!file_exists($this->privateKeyPath)) {
            throw new \RuntimeException('私钥文件不存在: ' . $this->privateKeyPath);
        }

        $privateKey = file_get_contents($this->privateKeyPath);
        if ($privateKey === false) {
            throw new \RuntimeException('无法读取私钥文件: ' . $this->privateKeyPath);
        }

        // 分割加密分段
        $chunks = explode('|||', $encryptedPayload);
        $decryptedString = '';

        // 解密每个分段
        foreach ($chunks as $chunk) {
            $encrypted = base64_decode($chunk);
            if ($encrypted === false) {
                throw new \RuntimeException('无效的 Base64 数据');
            }

            $decrypted = '';
            if (!openssl_private_decrypt($encrypted, $decrypted, $privateKey)) {
                throw new \RuntimeException('分段解密失败: ' . openssl_error_string());
            }

            $decryptedString .= $decrypted;
        }

        // 将JSON字符串解析为数组
        $data = json_decode($decryptedString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON解析失败: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * 检查私钥文件是否存在
     * 
     * @return bool
     */
    public function isPrivateKeyAvailable(): bool
    {
        return file_exists($this->privateKeyPath);
    }
}