<?php

namespace App\Controller\Api;

use App\Dto\HelpCategoryTreeDto;
use App\Dto\HelpSubCategoryTreeDto;
use App\Dto\HelpQuestionTreeDto;
use App\Repository\HelpCategoryRepository;
use App\Repository\HelpSubCategoryRepository;
use App\Repository\HelpQuestionRepository;
use App\Service\AliyunNlpService;
use App\Service\QiniuUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Redis;

/**
 * 商城帮助页面控制器
 * 
 * 提供商城帮助页面所需的数据接口，对接真实的帮助中心数据
 */
#[Route('/shop/api/help', name: 'api_shop_help_')]
class ShopHelpController extends AbstractController
{
    private HelpCategoryRepository $helpCategoryRepository;
    private HelpSubCategoryRepository $helpSubCategoryRepository;
    private HelpQuestionRepository $helpQuestionRepository;
    private AliyunNlpService $nlpService;

    public function __construct(
        HelpCategoryRepository $helpCategoryRepository,
        HelpSubCategoryRepository $helpSubCategoryRepository,
        HelpQuestionRepository $helpQuestionRepository,
        AliyunNlpService $nlpService
    ) {
        $this->helpCategoryRepository = $helpCategoryRepository;
        $this->helpSubCategoryRepository = $helpSubCategoryRepository;
        $this->helpQuestionRepository = $helpQuestionRepository;
        $this->nlpService = $nlpService;
    }

    /**
     * 获取FAQ分类数据（包含子分类和问题）
     * 优先从Redis缓存读取，如果没有则从数据库读取
     * 
     * @return JsonResponse
     */
    #[Route('/faq-categories', name: 'faq_categories', methods: ['GET'])]
    public function getFaqCategories(): JsonResponse
    {
        try {
            // 尝试从Redis获取数据
            $redisData = $this->getFromRedis('helpfaq');
            if ($redisData !== null) {
                // 返回完整的分类树形结构数据，不分页
                // 前端会自动处理分页：当用户选择特定二级分类时，
                // 前端会调用 /shop/api/help/faq-questions/{subCategoryId} 接口获取分页的问题列表
                return $this->json([
                    'success' => true,
                    'data' => $redisData
                ]);
            }
            
            // 如果Redis中没有数据，则从数据库获取
            // 使用优化的查询方法一次性获取完整的分类树结构，按sortOrder升序排列
            $categories = $this->helpCategoryRepository->findCategoryTreeWithSubCategoriesAndQuestions('faq');

            $categoryData = [];
            foreach ($categories as $category) {
                $categoryDto = new HelpCategoryTreeDto($category->getId(), $category->getName(), $category->getNameEn());
                
                // 获取该分类下的所有子分类，按sortOrder升序排列
                $subCategories = $category->getSubCategories()->toArray();
                usort($subCategories, function($a, $b) {
                    return $a->getSortOrder() <=> $b->getSortOrder();
                });
                
                foreach ($subCategories as $subCategory) {
                    $subCategoryDto = new HelpSubCategoryTreeDto($subCategory->getId(), $subCategory->getName(), $subCategory->getNameEn());
                    
                    // 获取该子分类下的所有问题，按sortOrder升序排列
                    $questions = $subCategory->getQuestions()->toArray();
                    usort($questions, function($a, $b) {
                        return $a->getSortOrder() <=> $b->getSortOrder();
                    });
                    
                    foreach ($questions as $question) {
                        $questionDto = new HelpQuestionTreeDto($question->getId(), $question->getQuestion(), $question->getQuestionEn());
                        $subCategoryDto->addQuestion($questionDto);
                    }
                    
                    $categoryDto->addChild($subCategoryDto);
                }
                
                $categoryData[] = $categoryDto;
            }

            return $this->json([
                'success' => true,
                'data' => $categoryData
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '获取FAQ分类数据失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取操作指引分类数据（包含子分类和问题）
     * 优先从Redis缓存读取，如果没有则从数据库读取
     * 
     * @return JsonResponse
     */
    #[Route('/guide-categories', name: 'guide_categories', methods: ['GET'])]
    public function getGuideCategories(): JsonResponse
    {
        try {
            // 尝试从Redis获取数据
            $redisData = $this->getFromRedis('helpguide');
            if ($redisData !== null) {
                // 返回完整的分类树形结构数据，不分页
                // 前端会自动处理分页：当用户选择特定二级分类时，
                // 前端会调用 /shop/api/help/guide-questions/{subCategoryId} 接口获取分页的问题列表
                return $this->json([
                    'success' => true,
                    'data' => $redisData
                ]);
            }

            // 如果Redis中没有数据，则从数据库获取
            // 使用优化的查询方法一次性获取完整的分类树结构，按sortOrder升序排列
            $categories = $this->helpCategoryRepository->findCategoryTreeWithSubCategoriesAndQuestions('guide');

            $categoryData = [];
            foreach ($categories as $category) {
                $categoryDto = new HelpCategoryTreeDto($category->getId(), $category->getName(), $category->getNameEn());
                
                // 获取该分类下的所有子分类，按sortOrder升序排列
                $subCategories = $category->getSubCategories()->toArray();
                usort($subCategories, function($a, $b) {
                    return $a->getSortOrder() <=> $b->getSortOrder();
                });
                
                foreach ($subCategories as $subCategory) {
                    $subCategoryDto = new HelpSubCategoryTreeDto($subCategory->getId(), $subCategory->getName(), $subCategory->getNameEn());
                    
                    // 获取该子分类下的所有问题，按sortOrder升序排列
                    $questions = $subCategory->getQuestions()->toArray();
                    usort($questions, function($a, $b) {
                        return $a->getSortOrder() <=> $b->getSortOrder();
                    });
                    
                    foreach ($questions as $question) {
                        $questionDto = new HelpQuestionTreeDto($question->getId(), $question->getQuestion(), $question->getQuestionEn());
                        $subCategoryDto->addQuestion($questionDto);
                    }
                    
                    $categoryDto->addChild($subCategoryDto);
                }
                
                $categoryData[] = $categoryDto;
            }

            return $this->json([
                'success' => true,
                'data' => $categoryData
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '获取操作指引分类数据失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 从Redis获取数据
     * 
     * @param string $key Redis键名
     * @return array|null 解析后的数据数组，如果获取失败或数据不存在则返回null
     */
    private function getFromRedis(string $key): ?array
    {
        try {
            // 创建Redis连接
            $redis = new Redis();
            
            // 从环境变量读取Redis配置
            $redisUrl = $_ENV['REDIS_KHUMFG'] ?? 'redis://localhost:6379';
            $parsedUrl = parse_url($redisUrl);
            
            $host = $parsedUrl['host'] ?? 'localhost';
            $port = $parsedUrl['port'] ?? 6379;
            $password = isset($parsedUrl['pass']) ? urldecode($parsedUrl['pass']) : null;
            
            // 连接到Redis服务器
            $redis->connect($host, $port);
            // 如果有密码，需要认证
            if ($password) {
                $redis->auth($password);
            }
            
            // 获取数据
            $data = $redis->get($key);
            
            // 如果数据存在，解析JSON
            if ($data !== false) {
                $decodedData = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decodedData;
                }
            }
            
            return null;
        } catch (\Exception $e) {
            // Redis连接或操作失败，返回null
            return null;
        }
    }
    
    /**
     * 获取FAQ子分类下的问题列表（支持分页）
     * 
     * @param Request $request
     * @param int $subCategoryId 子分类ID
     * @return JsonResponse
     */
    #[Route('/faq-questions/{subCategoryId}', name: 'faq_questions', methods: ['GET'])]
    public function getFaqQuestions(Request $request, int $subCategoryId): JsonResponse
    {
        try {
            $page = $request->query->getInt('page', 1);
            $limit = $request->query->getInt('limit', 10);
            
            // 获取该子分类下的问题总数
            $total = $this->helpQuestionRepository->countBySubCategoryId($subCategoryId);
            
            // 获取分页数据，按sortOrder升序排列
            $questions = $this->helpQuestionRepository->findQuestionsBySubCategoryIdWithPagination($subCategoryId, $page, $limit);
            
            // 确保问题按sortOrder排序
            usort($questions, function($a, $b) {
                return $a->getSortOrder() <=> $b->getSortOrder();
            });
            
            // 转换为DTO
            $questionData = [];
            foreach ($questions as $question) {
                $questionData[] = new HelpQuestionTreeDto($question->getId(), $question->getQuestion(), $question->getQuestionEn());
            }
            
            return $this->json([
                'success' => true,
                'data' => [
                    'questions' => $questionData,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '获取FAQ问题列表失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取操作指引子分类下的问题列表（支持分页）
     * 
     * @param Request $request
     * @param int $subCategoryId 子分类ID
     * @return JsonResponse
     */
    #[Route('/guide-questions/{subCategoryId}', name: 'guide_questions', methods: ['GET'])]
    public function getGuideQuestions(Request $request, int $subCategoryId): JsonResponse
    {
        try {
            $page = $request->query->getInt('page', 1);
            $limit = $request->query->getInt('limit', 10);
            
            // 获取该子分类下的问题总数
            $total = $this->helpQuestionRepository->countBySubCategoryId($subCategoryId);
            
            // 获取分页数据，按sortOrder升序排列
            $questions = $this->helpQuestionRepository->findQuestionsBySubCategoryIdWithPagination($subCategoryId, $page, $limit);
            
            // 确保问题按sortOrder排序
            usort($questions, function($a, $b) {
                return $a->getSortOrder() <=> $b->getSortOrder();
            });
            
            // 转换为DTO
            $questionData = [];
            foreach ($questions as $question) {
                $questionData[] = new HelpQuestionTreeDto($question->getId(), $question->getQuestion(), $question->getQuestionEn());
            }
            
            return $this->json([
                'success' => true,
                'data' => [
                    'questions' => $questionData,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '获取操作指引问题列表失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取单个问题详情
     * 
     * @param string $id 问题ID
     * @return JsonResponse
     */
    #[Route('/question/{id}', name: 'question_detail', methods: ['GET'])]
    public function getQuestionDetail(string $id): JsonResponse
    {
        try {
            // 查找问题并增加浏览次数
            $question = $this->helpQuestionRepository->findWithIncrementViewCount($id);
            
            if (!$question) {
                return $this->json([
                    'success' => false,
                    'message' => '问题不存在'
                ], 404);
            }

            // 获取分类信息
            $categoryName = $question->getCategory() ? $question->getCategory()->getName() : '';
            $subCategoryName = $question->getSubCategory() ? $question->getSubCategory()->getName() : '';

            // 处理图片签名
            $images = $question->getImages();
            $signedImages = [];
            if (!empty($images)) {
                $qiniuService = new QiniuUploadService();
                foreach ($images as $imageKey) {
                    // 如果已经是完整URL，则直接使用
                    if (filter_var($imageKey, FILTER_VALIDATE_URL)) {
                        $signedImages[] = $imageKey;
                    } else {
                        // 否则生成带签名的URL
                        try {
                            $signedImages[] = $qiniuService->getPrivateUrl($imageKey);
                        } catch (\Exception $e) {
                            // 如果签名失败，使用原始key
                            $signedImages[] = $imageKey;
                        }
                    }
                }
            }

            return $this->json([
                'success' => true,
                'data' => [
                    'id' => $question->getId(),
                    'question' => $question->getQuestion(),
                    'questionEn' => $question->getQuestionEn(),
                    'content' => $question->getContent(),
                    'images' => $signedImages,
                    'viewCount' => $question->getViewCount(),
                    'solvedCount' => $question->getSolvedCount(),
                    'unsolvedCount' => $question->getUnsolvedCount(),
                    'categoryId' => $question->getCategory() ? $question->getCategory()->getId() : null,
                    'categoryName' => $categoryName,
                    'subCategoryId' => $question->getSubCategory() ? $question->getSubCategory()->getId() : null,
                    'subCategoryName' => $subCategoryName,
                    'createdAt' => $question->getCreatedAt()->format('Y-m-d H:i:s'),
                    'updatedAt' => $question->getUpdatedAt()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '获取问题详情失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 增加问题的已解决计数
     * 
     * @param string $id 问题ID
     * @return JsonResponse
     */
    #[Route('/question/{id}/solved', name: 'question_solved', methods: ['POST'])]
    public function incrementSolvedCount(string $id): JsonResponse
    {
        try {
            $this->helpQuestionRepository->incrementSolvedCount($id);
            
            return $this->json([
                'success' => true,
                'message' => '计数更新成功'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '更新计数失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 增加问题的未解决计数
     * 
     * @param string $id 问题ID
     * @return JsonResponse
     */
    #[Route('/question/{id}/unsolved', name: 'question_unsolved', methods: ['POST'])]
    public function incrementUnsolvedCount(string $id): JsonResponse
    {
        try {
            $this->helpQuestionRepository->incrementUnsolvedCount($id);
            
            return $this->json([
                'success' => true,
                'message' => '计数更新成功'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '更新计数失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 搜索问题（支持分页和中文分词）
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        try {
            $keyword = $request->query->get('keyword', '');
            $page = $request->query->getInt('page', 1);
            $limit = $request->query->getInt('limit', 10);
            
            if (empty($keyword)) {
                return $this->json([
                    'success' => false,
                    'message' => '搜索关键词不能为空'
                ], 400);
            }
            
            // 使用阿里云NLP服务优化搜索关键词
            $optimizedKeyword = $this->nlpService->optimizeSearchKeyword($keyword);
            
            // 获取搜索结果总数
            $total = count($this->helpQuestionRepository->searchByKeyword($optimizedKeyword));
            
            // 计算偏移量
            $offset = ($page - 1) * $limit;
            
            // 构建查询，支持多个关键词搜索
            $queryBuilder = $this->helpQuestionRepository->createQueryBuilder('hq');
            
            // 如果优化后的关键词包含空格（多个词），则分别处理每个词
            if (strpos($optimizedKeyword, ' ') !== false) {
                $keywords = explode(' ', $optimizedKeyword);
                $orConditions = [];
                $parameters = [];
                
                foreach ($keywords as $index => $kw) {
                    if (!empty($kw)) {
                        $orConditions[] = 'hq.question LIKE :keyword' . $index . ' OR hq.questionEn LIKE :keyword' . $index;
                        $parameters['keyword' . $index] = '%' . $kw . '%';
                    }
                }
                
                if (!empty($orConditions)) {
                    $queryBuilder->andWhere('(' . implode(' OR ', $orConditions) . ')');
                    foreach ($parameters as $key => $value) {
                        $queryBuilder->setParameter($key, $value);
                    }
                }
            } else {
                // 单个关键词的情况
                $queryBuilder->andWhere('hq.question LIKE :keyword OR hq.questionEn LIKE :keyword')
                    ->setParameter('keyword', '%' . $optimizedKeyword . '%');
            }
            
            // 获取分页搜索结果，按sortOrder升序排列
            $questions = $queryBuilder
                ->orderBy('hq.sortOrder', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
            
            // 转换为DTO
            $questionData = [];
            foreach ($questions as $question) {
                $questionData[] = new HelpQuestionTreeDto($question->getId(), $question->getQuestion(), $question->getQuestionEn());
            }
            
            return $this->json([
                'success' => true,
                'data' => [
                    'results' => $questionData,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '搜索失败: ' . $e->getMessage()
            ], 500);
        }
    }
}