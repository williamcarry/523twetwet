<?php

namespace App\Service;

use App\Entity\LogisticsCompany;
use App\Repository\LogisticsCompanyRepository;
use Doctrine\ORM\EntityManagerInterface;

class LogisticsCompanyService
{
    private $entityManager;
    private $logisticsCompanyRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        LogisticsCompanyRepository $logisticsCompanyRepository
    ) {
        $this->entityManager = $entityManager;
        $this->logisticsCompanyRepository = $logisticsCompanyRepository;
    }

    /**
     * 获取所有启用的物流公司列表，按热度排序
     *
     * @return array
     */
    public function getActiveLogisticsCompanies(): array
    {
        $companies = $this->logisticsCompanyRepository->findAllActiveOrderBySort();
        
        $result = [];
        foreach ($companies as $company) {
            $result[] = [
                'id' => $company->getId(),
                'name' => $company->getName(),
                'nameEn' => $company->getNameEn(),
                'sortOrder' => $company->getSortOrder()
            ];
        }
        
        return $result;
    }

    /**
     * 根据ID获取物流公司信息
     *
     * @param int $id
     * @return array|null
     */
    public function getLogisticsCompanyById(int $id): ?array
    {
        $company = $this->logisticsCompanyRepository->find($id);
        
        if (!$company || !$company->getIsActive()) {
            return null;
        }
        
        return [
            'id' => $company->getId(),
            'name' => $company->getName(),
            'nameEn' => $company->getNameEn(),
            'sortOrder' => $company->getSortOrder()
        ];
    }

    /**
     * 根据名称获取物流公司信息
     *
     * @param string $name
     * @return array|null
     */
    public function getLogisticsCompanyByName(string $name): ?array
    {
        $company = $this->logisticsCompanyRepository->findOneActiveByName($name);
        
        if (!$company) {
            return null;
        }
        
        return [
            'id' => $company->getId(),
            'name' => $company->getName(),
            'nameEn' => $company->getNameEn(),
            'sortOrder' => $company->getSortOrder()
        ];
    }

    /**
     * 创建新的物流公司
     *
     * @param string $name
     * @param string $nameEn
     * @param int $sortOrder
     * @return LogisticsCompany
     */
    public function createLogisticsCompany(string $name, string $nameEn, int $sortOrder): LogisticsCompany
    {
        $company = new LogisticsCompany();
        $company->setName($name);
        $company->setNameEn($nameEn);
        $company->setSortOrder($sortOrder);
        $company->setIsActive(true);
        
        $this->entityManager->persist($company);
        $this->entityManager->flush();
        
        return $company;
    }

    /**
     * 更新物流公司信息
     *
     * @param int $id
     * @param array $data
     * @return LogisticsCompany|null
     */
    public function updateLogisticsCompany(int $id, array $data): ?LogisticsCompany
    {
        $company = $this->logisticsCompanyRepository->find($id);
        
        if (!$company) {
            return null;
        }
        
        if (isset($data['name'])) {
            $company->setName($data['name']);
        }
        
        if (isset($data['nameEn'])) {
            $company->setNameEn($data['nameEn']);
        }
        
        if (isset($data['sortOrder'])) {
            $company->setSortOrder($data['sortOrder']);
        }
        
        if (isset($data['isActive'])) {
            $company->setIsActive($data['isActive']);
        }
        
        $this->entityManager->flush();
        
        return $company;
    }

    /**
     * 删除物流公司
     *
     * @param int $id
     * @return bool
     */
    public function deleteLogisticsCompany(int $id): bool
    {
        $company = $this->logisticsCompanyRepository->find($id);
        
        if (!$company) {
            return false;
        }
        
        $this->entityManager->remove($company);
        $this->entityManager->flush();
        
        return true;
    }
}