<?php

namespace App\Controller\Api;

use App\Entity\PublicResource;
use App\Repository\PublicResourceRepository;
use App\Service\QiniuUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop/api/public-resource', name: 'api_shop_public_resource_')]
class PublicResourceController extends AbstractController
{
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(
        PublicResourceRepository $publicResourceRepository,
        Request $request
    ): JsonResponse {
        try {
            // 获取所有公共资源数据
            $resources = $publicResourceRepository->findAll();
            
            // 创建七牛云服务实例
            $qiniuService = new QiniuUploadService();
            
            // 按位置分组资源
            $groupedResources = [
                'header' => [],
                'footer' => [],
                'supplierIntro' => [],
                'copyright' => [],
                'webicp' => [],
                'privacyPolicy' => [],
                'termsOfService' => [],
            ];
            
            // 格式化数据
            foreach ($resources as $resource) {
                // 处理图片URL
                $imageUrl = $resource->getImage();
                if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                    // 如果是图片key，生成完整URL
                    $imageUrl = $qiniuService->getPrivateUrl($imageUrl);
                }
                
                $resourceData = [
                    'id' => $resource->getId(),
                    'title' => $resource->getTitle(),
                    'titleEn' => $resource->getTitleEn(),
                    'type' => $resource->getType(),
                    'position' => $resource->getPosition(),
                    'positiontype' => $resource->getPositiontype(),
                    'image' => $imageUrl, // 返回完整图片URL
                    'description' => $resource->getDescription(),
                    'helpFaqId' => $resource->getHelpFaqId(),
                    'createdAt' => $resource->getCreatedAt()->format('Y-m-d H:i:s'),
                    'updatedAt' => $resource->getUpdatedAt()->format('Y-m-d H:i:s'),
                ];
                
                // 按位置分组
                $position = $resource->getPosition();
                if (isset($groupedResources[$position])) {
                    $groupedResources[$position][] = $resourceData;
                }
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => $groupedResources
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取公共资源数据失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    // 已废弃：按位置加载资源的API，现统一使用list接口一次性加载所有资源
    /*
    #[Route('/by-position/{position}', name: 'by_position', methods: ['GET'])]
    public function getByPosition(
        string $position,
        PublicResourceRepository $publicResourceRepository,
        Request $request
    ): JsonResponse {
        try {
            // 验证位置参数
            if (!in_array($position, ['header', 'footer', 'supplierIntro', 'copyright', 'webicp'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '无效的位置参数'
                ], 400);
            }
            
            // 获取指定位置的资源
            $resources = $publicResourceRepository->findBy(['position' => $position]);
            
            // 创建七牛云服务实例
            $qiniuService = new QiniuUploadService();
            
            // 格式化数据
            $resourcesData = [];
            foreach ($resources as $resource) {
                // 处理图片URL
                $imageUrl = $resource->getImage();
                if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                    // 如果是图片key，生成完整URL
                    $imageUrl = $qiniuService->getPrivateUrl($imageUrl);
                }
                
                $resourcesData[] = [
                    'id' => $resource->getId(),
                    'title' => $resource->getTitle(),
                    'titleEn' => $resource->getTitleEn(),
                    'type' => $resource->getType(),
                    'position' => $resource->getPosition(),
                    'positiontype' => $resource->getPositiontype(),
                    'image' => $imageUrl, // 返回完整图片URL
                    'description' => $resource->getDescription(),
                    'helpFaqId' => $resource->getHelpFaqId(),
                    'createdAt' => $resource->getCreatedAt()->format('Y-m-d H:i:s'),
                    'updatedAt' => $resource->getUpdatedAt()->format('Y-m-d H:i:s'),
                ];
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => $resourcesData
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取公共资源数据失败: ' . $e->getMessage()
            ], 500);
        }
    }
    */
    
    #[Route('/image/signed-url', name: 'image_signed_url', methods: ['POST'])]
    public function getImageSignedUrl(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $key = $data['key'] ?? '';
            
            if (empty($key)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => '图片key不能为空'
                ], 400);
            }
            
            // 如果已经是完整URL，直接返回
            if (str_starts_with($key, 'http')) {
                return new JsonResponse([
                    'success' => true,
                    'url' => $key
                ]);
            }
            
            // 生成七牛云签名URL
            $qiniuService = new QiniuUploadService();
            $signedUrl = $qiniuService->getPrivateUrl($key);
            
            return new JsonResponse([
                'success' => true,
                'url' => $signedUrl
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => '获取图片签名URL失败: ' . $e->getMessage()
            ], 500);
        }
    }
}