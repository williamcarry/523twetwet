<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\common\VipLevel;
use App\Repository\CustomerMonthlyStatsRepository;
use App\Repository\SiteConfigRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop/api/customer', name: 'api_customer_')]
class CustomerController extends AbstractController
{
    /**
     * 获取当前用户信息（包含当月下载统计和VIP下载额度）
     */
    #[Route('/profile', name: 'profile', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function profile(
        CustomerMonthlyStatsRepository $statsRepository,
        SiteConfigRepository $configRepository,
        SiteConfigService $siteConfigService,
        \App\Repository\CustomerAddressRepository $addressRepository
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        // 获取当前年月
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        
        // 查找或创建当月统计记录
        $monthlyStats = $statsRepository->findOrCreate(
            $customer->getId(),
            $currentYear,
            $currentMonth
        );
        
        // 获取VIP下载额度配置
        $vipDownloadLimits = $this->getVipDownloadLimits($configRepository);
        
        // 获取默认地址
        $defaultAddress = $addressRepository->findOneBy([
            'customer' => $customer,
            'isDefault' => true
        ]);
        
        $defaultAddressData = null;
        if ($defaultAddress) {
            $defaultAddressData = [
                'id' => $defaultAddress->getId(),
                'receiverName' => $defaultAddress->getReceiverName(),
                'receiverPhone' => $defaultAddress->getReceiverPhone(),
                'receiverAddress' => $defaultAddress->getReceiverAddress(),
                'receiverZipcode' => $defaultAddress->getReceiverZipcode(),
                'addressLabel' => $defaultAddress->getAddressLabel(),
            ];
        }
        
        // 解析图片URL
        $individualIdFrontUrl = $this->parseImageUrl($customer->getIndividualIdFront());
        $individualIdBackUrl = $this->parseImageUrl($customer->getIndividualIdBack());
        $businessLicenseImageUrl = $this->parseImageUrl($customer->getBusinessLicenseImage());
        $legalPersonIdFrontUrl = $this->parseImageUrl($customer->getLegalPersonIdFront());
        $legalPersonIdBackUrl = $this->parseImageUrl($customer->getLegalPersonIdBack());

        return $this->json([
            'success' => true,
            'data' => [
                'id' => $customer->getId(),
                'customerId' => $customer->getCustomerId(),
                'username' => $customer->getUsername(),
                'email' => $customer->getEmail(),
                'nickname' => $customer->getNickname(),
                'avatar' => $customer->getAvatar(),
                'mobile' => $customer->getMobile(),
                'customerType' => $customer->getCustomerType(),
                'auditStatus' => $customer->getAuditStatus(),
                'vipLevel' => $customer->getVipLevel(),
                'vipLevelName' => $customer->getVipLevelName(),
                'vipLevelNameEn' => VipLevel::getLevelName($customer->getVipLevel(), 'en'),
                'balance' => $customer->getBalance(),
                'isVerified' => $customer->isVerified(),
                'gender' => $customer->getGender(),
                'birthday' => $customer->getBirthday()?->format('Y-m-d'),
                'realName' => $customer->getRealName(),
                'individualIdCard' => $customer->getIndividualIdCard(),
                'individualIdFront' => $individualIdFrontUrl,
                'individualIdBack' => $individualIdBackUrl,
                'address' => $customer->getAddress(),
                'companyName' => $customer->getCompanyName(),
                'companyType' => $customer->getCompanyType(),
                'businessLicenseNumber' => $customer->getBusinessLicenseNumber(),
                'businessLicenseImage' => $businessLicenseImageUrl,
                'legalPersonName' => $customer->getLegalPersonName(),
                'legalPersonIdCard' => $customer->getLegalPersonIdCard(),
                'legalPersonIdFront' => $legalPersonIdFrontUrl,
                'legalPersonIdBack' => $legalPersonIdBackUrl,
                'registeredCapital' => $customer->getRegisteredCapital(),
                'establishmentDate' => $customer->getEstablishmentDate()?->format('Y-m-d'),
                'businessScope' => $customer->getBusinessScope(),
                // 默认地址
                'defaultAddress' => $defaultAddressData,
                // 月度统计信息
                'monthlyStats' => [
                    'year' => $monthlyStats->getStatsYear(),
                    'month' => $monthlyStats->getStatsMonth(),
                    'downloadUsed' => $monthlyStats->getDownloadUsed(),
                    'totalOrderAmount' => $monthlyStats->getTotalOrderAmount(),
                    'totalOrders' => $monthlyStats->getTotalOrders(),
                ],
                // VIP下载额度配置
                'vipDownloadLimits' => $vipDownloadLimits,
                // 货币符号
                'currencySymbol' => $siteConfigService->getCurrencySymbol(),
            ]
        ]);
    }
    
    /**
     * 获取VIP各等级的月度下载额度
     * 
     * @param SiteConfigRepository $configRepository
     * @return array
     */
    private function getVipDownloadLimits(SiteConfigRepository $configRepository): array
    {
        $limits = [];
        
        // VIP等级配置键名映射
        $configKeys = [
            0 => 'NORMAL',
            1 => 'VIP1',
            2 => 'VIP2',
            3 => 'VIP3',
            4 => 'VIP4',
            5 => 'VIP5',
        ];
        
        foreach ($configKeys as $level => $configKey) {
            $config = $configRepository->findOneByKey($configKey);
            
            // 从configValue3字段获取下载次数，没有配置则为0
            $limits[$level] = ($config && $config->getConfigValue3()) 
                ? (int) $config->getConfigValue3() 
                : 0;
        }
        
        return $limits;
    }
    
    /**
     * 解析图片URL（将key转换为签名URL）
     * 
     * @param string|null $key 图片key
     * @return string|null 解析后的URL或null
     */
    private function parseImageUrl(?string $key): ?string
    {
        if (empty($key)) {
            return null;
        }
        
        // 如果已经是完整的URL，直接返回
        if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
            return $key;
        }
        
        // 否则调用七牛云生成签名URL
        try {
            $qiniuService = new \App\Service\QiniuUploadService();
            return $qiniuService->getPrivateUrl($key);
        } catch (\Exception $e) {
            // 如果解析失败，返回原始key
            return $key;
        }
    }
    
    /**
     * 更新会员资料并提交审核
     */
    #[Route('/profile/update', name: 'profile_update', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function updateProfile(
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        if (!$customer) {
            return $this->json([
                'success' => false,
                'message' => '未登录',
                'messageEn' => 'Not logged in'
            ], 401);  // ✅ 正确使用 401：这是认证失败场景（用户未登录）
        }
        
        // 检查是否可编辑：
        // 1. 未实名且审核状态为 resubmit 时可以编辑
        // 2. 已实名的个人用户可以升级为企业用户（提交企业信息）
        $data = json_decode($request->getContent(), true);
        $targetCustomerType = $data['customerType'] ?? $customer->getCustomerType();
        
        $canEdit = false;
        
        // 情兵1：未实名 + resubmit
        if (!$customer->isVerified() && $customer->getAuditStatus() === 'resubmit') {
            $canEdit = true;
        }
        
        // 情兵2：已实名的个人用户升级为企业
        if ($customer->isVerified() && 
            $customer->getCustomerType() === 'individual' && 
            $targetCustomerType === 'company') {
            $canEdit = true;
        }
        
        if (!$canEdit) {
            return $this->json([
                'success' => false,
                'message' => '当前状态不允许编辑',
                'messageEn' => 'Current status does not allow editing'
            ], 403);
        }
        
        try {
            $data = json_decode($request->getContent(), true);
            
            // 更新会员类型
            if (isset($data['customerType'])) {
                $customer->setCustomerType($data['customerType']);
            }
            
            // 更新个人信息
            if ($customer->getCustomerType() === 'individual') {
                if (isset($data['realName'])) {
                    $customer->setRealName($data['realName']);
                }
                if (isset($data['gender'])) {
                    $customer->setGender($data['gender']);
                }
                if (isset($data['birthday'])) {
                    $birthday = new \DateTime($data['birthday']);
                    $customer->setBirthday($birthday);
                }
                if (isset($data['individualIdCard'])) {
                    $customer->setIndividualIdCard($data['individualIdCard']);
                }
                if (isset($data['individualIdFront'])) {
                    $customer->setIndividualIdFront($data['individualIdFront']);
                }
                if (isset($data['individualIdBack'])) {
                    $customer->setIndividualIdBack($data['individualIdBack']);
                }
                if (isset($data['address'])) {
                    $customer->setAddress($data['address']);
                }
            }
            
            // 更新企业信息
            if ($customer->getCustomerType() === 'company') {
                if (isset($data['companyName'])) {
                    $customer->setCompanyName($data['companyName']);
                }
                if (isset($data['companyType'])) {
                    $customer->setCompanyType($data['companyType']);
                }
                if (isset($data['businessLicenseNumber'])) {
                    $customer->setBusinessLicenseNumber($data['businessLicenseNumber']);
                }
                if (isset($data['businessLicenseImage'])) {
                    $customer->setBusinessLicenseImage($data['businessLicenseImage']);
                }
                if (isset($data['legalPersonName'])) {
                    $customer->setLegalPersonName($data['legalPersonName']);
                }
                if (isset($data['legalPersonIdCard'])) {
                    $customer->setLegalPersonIdCard($data['legalPersonIdCard']);
                }
                if (isset($data['legalPersonIdFront'])) {
                    $customer->setLegalPersonIdFront($data['legalPersonIdFront']);
                }
                if (isset($data['legalPersonIdBack'])) {
                    $customer->setLegalPersonIdBack($data['legalPersonIdBack']);
                }
                if (isset($data['registeredCapital'])) {
                    $customer->setRegisteredCapital($data['registeredCapital']);
                }
                if (isset($data['establishmentDate'])) {
                    $establishmentDate = new \DateTime($data['establishmentDate']);
                    $customer->setEstablishmentDate($establishmentDate);
                }
                if (isset($data['businessScope'])) {
                    $customer->setBusinessScope($data['businessScope']);
                }
                if (isset($data['address'])) {
                    $customer->setAddress($data['address']);
                }
            }
            
            // 提交审核：将审核状态改为 pending
            $customer->setAuditStatus('pending');
            
            $entityManager->flush();
            
            // 返回更新后的用户信息
            return $this->json([
                'success' => true,
                'message' => '提交审核成功',
                'messageEn' => 'Submitted for review successfully',
                'data' => [
                    'id' => $customer->getId(),
                    'customerId' => $customer->getCustomerId(),
                    'username' => $customer->getUsername(),
                    'email' => $customer->getEmail(),
                    'nickname' => $customer->getNickname(),
                    'avatar' => $customer->getAvatar(),
                    'mobile' => $customer->getMobile(),
                    'customerType' => $customer->getCustomerType(),
                    'auditStatus' => $customer->getAuditStatus(),
                    'isVerified' => $customer->isVerified(),
                    'vipLevel' => $customer->getVipLevel(),
                    'vipLevelName' => $customer->getVipLevelName(),
                    'balance' => $customer->getBalance(),
                    'realName' => $customer->getRealName(),
                    'gender' => $customer->getGender(),
                    'birthday' => $customer->getBirthday()?->format('Y-m-d'),
                    'individualIdCard' => $customer->getIndividualIdCard(),
                    'individualIdFront' => $customer->getIndividualIdFront(),
                    'individualIdBack' => $customer->getIndividualIdBack(),
                    'companyName' => $customer->getCompanyName(),
                    'companyType' => $customer->getCompanyType(),
                    'businessLicenseNumber' => $customer->getBusinessLicenseNumber(),
                    'businessLicenseImage' => $customer->getBusinessLicenseImage(),
                    'legalPersonName' => $customer->getLegalPersonName(),
                    'legalPersonIdCard' => $customer->getLegalPersonIdCard(),
                    'legalPersonIdFront' => $customer->getLegalPersonIdFront(),
                    'legalPersonIdBack' => $customer->getLegalPersonIdBack(),
                    'registeredCapital' => $customer->getRegisteredCapital(),
                    'establishmentDate' => $customer->getEstablishmentDate()?->format('Y-m-d'),
                    'businessScope' => $customer->getBusinessScope(),
                    'address' => $customer->getAddress(),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '更新失败：' . $e->getMessage(),
                'messageEn' => 'Update failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
