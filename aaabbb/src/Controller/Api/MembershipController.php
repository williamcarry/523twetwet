<?php

namespace App\Controller\Api;

use App\Entity\SiteConfig;
use App\Repository\SiteConfigRepository;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop/api/membership', name: 'api_shop_membership_')]
class MembershipController extends AbstractController
{
    /**
     * 获取会员权益信息
     * 
     * @param SiteConfigRepository $siteConfigRepository
     * @return JsonResponse
     */
    #[Route('/benefits', name: 'benefits', methods: ['GET'])]
    public function getMembershipBenefits(
        SiteConfigRepository $siteConfigRepository,
        SiteConfigService $siteConfigService
    ): JsonResponse {
        try {
            // 获取会员等级配置 (Vip1到Vip5)
            $membershipLevels = [];
            for ($i = 1; $i <= 5; $i++) {
                $configKey = 'vip' . $i;
                $config = $siteConfigRepository->findOneByKey($configKey);
                
                if ($config) {
                    $membershipLevels['vip' . $i] = [
                        'level' => 'VIP' . $i,
                        'sales' => $config->getConfigValue() ?? '',
                        'discount' => $config->getConfigValue2() ?? '',
                        'download_limit' => $config->getConfigValue3() ?? ''
                    ];
                } else {
                    $membershipLevels['vip' . $i] = [
                        'level' => 'VIP' . $i,
                        'sales' => '',
                        'discount' => ''
                    ];
                }
            }
            
            // 获取普通会员配置
            $normalConfig = $siteConfigRepository->findOneByKey('normal');
            $normalMember = [
                'level' => '普通会员',
                'sales' => $normalConfig ? ($normalConfig->getConfigValue() ?? '') : '',
                'discount' => $normalConfig ? ($normalConfig->getConfigValue2() ?? '') : '',
                'download_limit' => $normalConfig ? ($normalConfig->getConfigValue3() ?? '') : ''
            ];

            // 构建每月下载SKU数权益数据
            $downloadLimits = [
                'normal' => $normalConfig ? ($normalConfig->getConfigValue3() ?? '') : '',
                'v1' => isset($membershipLevels['vip1']) ? ($membershipLevels['vip1']['download_limit'] ?? '') : '',
                'v2' => isset($membershipLevels['vip2']) ? ($membershipLevels['vip2']['download_limit'] ?? '') : '',
                'v3' => isset($membershipLevels['vip3']) ? ($membershipLevels['vip3']['download_limit'] ?? '') : '',
                'v4' => isset($membershipLevels['vip4']) ? ($membershipLevels['vip4']['download_limit'] ?? '') : '',
                'v5' => isset($membershipLevels['vip5']) ? ($membershipLevels['vip5']['download_limit'] ?? '') : ''
            ];
            
            // 构建会员权益数据 - 只保留下载限制数据
            $membershipRights = [
                'downloadLimits' => $downloadLimits
            ];

            $response = [
                'success' => true,
                'data' => [
                    'normal_member' => $normalMember,
                    'membership_levels' => $membershipLevels,
                    'membership_rights' => $membershipRights,
                    'currencySymbol' => $siteConfigService->getCurrencySymbol()
                ]
            ];

            return new JsonResponse($response);
        } catch (\Exception $e) {
            $response = [
                'success' => false,
                'message' => '获取会员权益信息失败: ' . $e->getMessage()
            ];
            
            return new JsonResponse($response, 500);
        }
    }
    
}