<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * 快递查询服务
 * 使用阿里云市场快递查询API
 */
class ExpressTrackingService
{
    private string $host = 'https://kzexpress.market.alicloudapi.com';
    private string $path = '/api-mall/api/express/query';
    private ?string $appCode;
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger,
        string $expressAppCode = null
    ) {
        $this->logger = $logger;
        // 优先使用构造函数参数,其次从环境变量获取
        $this->appCode = $expressAppCode ?? $_ENV['EXPRESS_API_APPCODE'] ?? null;
    }

    /**
     * 检查服务是否可用
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return !empty($this->appCode);
    }

    /**
     * 查询快递物流信息
     *
     * @param string $expressNo 快递单号
     * @param string|null $mobile 手机号后四位(选填,部分快递公司需要)
     * @return array 返回格式化后的物流信息
     */
    public function track(string $expressNo, ?string $mobile = null): array
    {
        // 检查服务是否可用
        if (!$this->isAvailable()) {
            $this->logger->warning('快递查询服务不可用: AppCode未配置');
            return [
                'error' => true,
                'message' => '快递查询服务暂不可用',
                'expressNo' => $expressNo,
                'hasTracking' => false,
            ];
        }

        try {
            // 构建查询参数
            $params = ['expressNo' => $expressNo];
            if ($mobile) {
                $params['mobile'] = $mobile;
            }
            $queryString = http_build_query($params);

            // 构建完整URL
            $url = $this->host . $this->path . '?' . $queryString;

            // 设置请求头
            $headers = [
                'Authorization: APPCODE ' . $this->appCode
            ];

            // 执行CURL请求(带重试)
            $response = $this->executeCurlWithRetry($url, $headers);

            // 解析响应
            return $this->parseResponse($response);

        } catch (\Exception $e) {
            $this->logger->error('快递查询失败', [
                'expressNo' => $expressNo,
                'mobile' => $mobile,
                'error' => $e->getMessage()
            ]);
            
            // 返回错误信息而不是抛出异常
            return [
                'error' => true,
                'message' => $e->getMessage(),
                'expressNo' => $expressNo,
                'hasTracking' => false,
            ];
        }
    }

    /**
     * 执行CURL请求(带重试机制)
     *
     * @param string $url
     * @param array $headers
     * @param int $maxRetries 最大重试次数
     * @return string
     * @throws \Exception
     */
    private function executeCurlWithRetry(string $url, array $headers, int $maxRetries = 3): string
    {
        $lastError = null;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                return $this->executeCurl($url, $headers);
            } catch (\Exception $e) {
                $lastError = $e;
                $this->logger->warning('快递查询重试', [
                    'attempt' => $i + 1,
                    'maxRetries' => $maxRetries,
                    'error' => $e->getMessage()
                ]);
                
                // 如果不是最后一次重试,等待后再试
                if ($i < $maxRetries - 1) {
                    usleep(500000); // 等待0.5秒
                }
            }
        }
        
        throw $lastError;
    }

    /**
     * 执行CURL请求
     *
     * @param string $url
     * @param array $headers
     * @return string
     * @throws \Exception
     */
    private function executeCurl(string $url, array $headers): string
    {
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5); // 连接超时5秒

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        
        curl_close($curl);

        if ($error) {
            throw new \Exception('网络请求失败: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \Exception('API请求失败，HTTP状态码: ' . $httpCode);
        }

        return $response;
    }

    /**
     * 解析API响应
     *
     * @param string $response
     * @return array
     * @throws \Exception
     */
    private function parseResponse(string $response): array
    {
        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON解析失败: ' . json_last_error_msg());
        }

        if (!isset($result['success']) || !$result['success']) {
            throw new \Exception($result['msg'] ?? '快递查询失败');
        }

        if ($result['code'] !== 200) {
            throw new \Exception($result['msg'] ?? '快递查询失败');
        }

        return $this->formatTrackingData($result['data'] ?? []);
    }

    /**
     * 格式化物流数据
     *
     * @param array $data
     * @return array
     */
    private function formatTrackingData(array $data): array
    {
        return [
            // 基础信息
            'expressNo' => $data['mailNo'] ?? '',
            'companyCode' => $data['cpCode'] ?? '',
            'companyName' => $data['logisticsCompanyName'] ?? '',
            'companyPhone' => $data['cpMobile'] ?? '',
            'companyUrl' => $data['cpUrl'] ?? '',
            
            // 状态信息
            'status' => $data['logisticsStatus'] ?? 'UNKNOWN',
            'statusDesc' => $data['logisticsStatusDesc'] ?? '无物流信息',
            'statusText' => $this->getStatusText($data['logisticsStatus'] ?? ''),
            
            // 最新动态
            'latestTime' => $data['theLastTime'] ?? null,
            'latestMessage' => $data['theLastMessage'] ?? '',
            
            // 物流轨迹列表
            'traces' => $this->formatTraces($data['logisticsTraceDetailList'] ?? []),
            
            // 是否有物流信息
            'hasTracking' => !empty($data['logisticsTraceDetailList']),
        ];
    }

    /**
     * 格式化物流轨迹列表
     *
     * @param array $traces
     * @return array
     */
    private function formatTraces(array $traces): array
    {
        $formatted = array_map(function ($trace) {
            return [
                'time' => $trace['timeDesc'] ?? '',
                'timestamp' => $trace['time'] ?? 0,
                'status' => $trace['logisticsStatus'] ?? '',
                'subStatus' => $trace['subLogisticsStatus'] ?? '',
                'description' => $trace['desc'] ?? '',
                'areaCode' => $trace['areaCode'] ?? '',
                'areaName' => $trace['areaName'] ?? '',
            ];
        }, $traces);
        
        // 按时间戳降序排序（最新的在前面）
        usort($formatted, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });
        
        return $formatted;
    }

    /**
     * 获取状态中文描述
     *
     * @param string $status
     * @return string
     */
    private function getStatusText(string $status): string
    {
        $statusMap = [
            'WAIT_ACCEPT' => '待揽收',
            'ACCEPT' => '已揽收',
            'TRANSPORT' => '运输中',
            'DELIVERING' => '派件中',
            'AGENT_SIGN' => '已代签收',
            'SIGN' => '已签收',
            'FAILED' => '包裹异常',
            'UNKNOWN' => '无物流信息',
        ];

        return $statusMap[$status] ?? '未知状态';
    }

    /**
     * 批量查询快递信息
     *
     * @param array $expressNos 快递单号数组
     * @return array
     */
    public function batchTrack(array $expressNos): array
    {
        $results = [];
        
        foreach ($expressNos as $expressNo) {
            try {
                $results[$expressNo] = $this->track($expressNo);
            } catch (\Exception $e) {
                $results[$expressNo] = [
                    'error' => true,
                    'message' => $e->getMessage(),
                    'expressNo' => $expressNo,
                ];
            }
        }

        return $results;
    }

    /**
     * 检查物流是否已签收
     *
     * @param string $expressNo
     * @return bool
     */
    public function isSigned(string $expressNo): bool
    {
        try {
            $tracking = $this->track($expressNo);
            return in_array($tracking['status'], ['SIGN', 'AGENT_SIGN']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查物流是否异常
     *
     * @param string $expressNo
     * @return bool
     */
    public function isFailed(string $expressNo): bool
    {
        try {
            $tracking = $this->track($expressNo);
            return $tracking['status'] === 'FAILED';
        } catch (\Exception $e) {
            return false;
        }
    }
}
