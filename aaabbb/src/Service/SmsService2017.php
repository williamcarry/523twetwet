<?php

namespace App\Service;

use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\Tea\Exception\TeaError;
use AlibabaCloud\Tea\Exception\TeaUnableRetryError;
use Darabonba\OpenApi\Models\Config;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;

/**
 * 阿里云短信服务类 (2017年版本SDK)
 * 
 * 用于发送验证码等短信通知
 */
class SmsService2017
{
    private string $accessKeyId;
    private string $accessKeySecret;
    private string $signName;
    private string $templateCode;
    private string $endpoint;
    
    public function __construct()
    {
        $this->accessKeyId = $_ENV['ALIYUN_SMS_ACCESS_KEY_ID'] ?? '';
        $this->accessKeySecret = $_ENV['ALIYUN_SMS_ACCESS_KEY_SECRET'] ?? '';
        $this->signName = $_ENV['ALIYUN_SMS_SIGN_NAME'] ?? '';
        $this->templateCode = $_ENV['ALIYUN_SMS_TEMPLATE_CODE'] ?? '';
        $this->endpoint = 'dysmsapi.aliyuncs.com';
    }
    
    /**
     * 发送验证码短信
     * 
     * @param string $phone 手机号码
     * @param string $code 验证码
     * @return array 发送结果
     */
    public function sendVerificationCode(string $phone, string $code): array
    {
        try {
            // 创建配置对象
            $config = new Config();
            $config->accessKeyId = $this->accessKeyId;
            $config->accessKeySecret = $this->accessKeySecret;
            $config->endpoint = $this->endpoint;
            
            // 创建客户端
            $client = new Dysmsapi($config);
            
            // 构造请求参数
            $sendSmsRequest = new SendSmsRequest([
                "phoneNumbers" => $phone,
                "signName" => $this->signName,
                "templateCode" => $this->templateCode,
                "templateParam" => json_encode([
                    "code" => $code
                ])
            ]);
            
            // 发送短信
            $response = $client->sendSms($sendSmsRequest);
            
            // 处理响应结果
            if ($response->body->code === 'OK') {
                return [
                    'success' => true,
                    'message' => '短信发送成功',
                    'data' => [
                        'bizId' => $response->body->bizId ?? null,
                        'code' => $response->body->code
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '短信发送失败: ' . $response->body->message,
                    'data' => [
                        'code' => $response->body->code,
                        'message' => $response->body->message
                    ]
                ];
            }
        } catch (TeaError $error) {
            return [
                'success' => false,
                'message' => '短信发送异常: ' . $error->getMessage(),
                'data' => [
                    'errorCode' => $error->getCode(),
                    'errorMessage' => $error->getMessage()
                ]
            ];
        } catch (TeaUnableRetryError $error) {
            return [
                'success' => false,
                'message' => '短信发送异常: ' . $error->getMessage(),
                'data' => [
                    'errorCode' => $error->getCode(),
                    'errorMessage' => $error->getMessage()
                ]
            ];
        } catch (\Exception $error) {
            return [
                'success' => false,
                'message' => '短信发送异常: ' . $error->getMessage(),
                'data' => [
                    'errorMessage' => $error->getMessage()
                ]
            ];
        }
    }
    
    /**
     * 生成随机验证码
     * 
     * @param int $length 验证码长度，默认6位
     * @return string 验证码
     */
    public function generateVerificationCode(int $length = 6): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= rand(0, 9);
        }
        return $code;
    }
}