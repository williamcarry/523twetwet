<?php

namespace App\Service;

use AlibabaCloud\SDK\Alinlp\V20200629\Alinlp;
use AlibabaCloud\SDK\Alinlp\V20200629\Models\GetWsChGeneralRequest;
use Darabonba\OpenApi\Models\Config;

/**
 * 阿里云NLP自然语言处理服务
 * 用于中文分词等功能
 */
class AliyunNlpService
{
    private Alinlp $client;

    public function __construct()
    {
        $config = new Config();
        // 使用更可靠的方式获取环境变量
        $config->accessKeyId = $_ENV['ALIYUN_NLP_ACCESS_KEY_ID'] ?? getenv('ALIYUN_NLP_ACCESS_KEY_ID') ?? '';
        $config->accessKeySecret = $_ENV['ALIYUN_NLP_ACCESS_KEY_SECRET'] ?? getenv('ALIYUN_NLP_ACCESS_KEY_SECRET') ?? '';
        $config->regionId = "cn-hangzhou";
        $config->endpoint = $_ENV['ALIYUN_NLP_ENDPOINT'] ?? getenv('ALIYUN_NLP_ENDPOINT') ?? 'alinlp.cn-shanghai.aliyuncs.com';

        $this->client = new Alinlp($config);
    }

    /**
     * 中文分词
     *
     * @param string $text 待分词的文本
     * @return array 分词结果
     */
    public function wordSegmentation(string $text): array
    {
        try {
            $request = new GetWsChGeneralRequest();
            $request->serviceCode = 'alinlp';
            $request->text = $text;
            $request->tokenizerId = 'GENERAL_CHN';

            $response = $this->client->getWsChGeneral($request);
           
             if ($response->body && isset($response->body->data)) {
                // 解析返回的JSON数据
                $data = json_decode($response->body->data, true);
                // 根据实际返回格式解析数据
                if (isset($data['result'])) {
                    return $data['result'];
                }
            }

            return [];
        } catch (\Exception $e) {
            // 记录错误日志
            error_log('Aliyun NLP Word Segmentation Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 使用中文分词优化商品搜索关键词
     * 返回分词后的关键词数组，用于构建更精确的搜索条件
     *
     * @param string $keyword 原始搜索关键词
     * @return array 分词后的关键词数组
     */
    public function segmentForProductSearch(string $keyword): array
    {
        // 如果关键词为空，返回空数组
        if (empty($keyword)) {
            return [];
        }

        // 如果关键词较短，直接返回原关键词
        if (mb_strlen($keyword) <= 2) {
            return [$keyword];
        }

        // 进行中文分词
        $segments = $this->wordSegmentation($keyword);

        // 如果分词成功，返回分词结果数组
        if (!empty($segments)) {
            $words = [];
            foreach ($segments as $segment) {
                if (isset($segment['word']) && !empty($segment['word'])) {
                    $words[] = $segment['word'];
                }
            }

            if (!empty($words)) {
                return $words;
            }
        }

        // 如果分词失败，返回原始关键词
        return [$keyword];
    }

    /**
     * 使用中文分词优化搜索关键词
     *
     * @param string $keyword 原始搜索关键词
     * @return string 优化后的搜索关键词
     */
    public function optimizeSearchKeyword(string $keyword): string
    {
        // 如果关键词较短，直接返回
        if (mb_strlen($keyword) <= 2) {
            return $keyword;
        }

        // 进行中文分词
        $segments = $this->wordSegmentation($keyword);

        // 如果分词成功，将分词结果用空格连接
        if (!empty($segments)) {
            $words = [];
            foreach ($segments as $segment) {
                if (isset($segment['word'])) {
                    $words[] = $segment['word'];
                }
            }

            if (!empty($words)) {
                return implode(' ', $words);
            }
        }

        // 如果分词失败，返回原始关键词
        return $keyword;
    }
}