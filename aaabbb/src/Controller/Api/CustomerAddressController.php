<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Repository\CustomerAddressRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop/api/customer/address', name: 'api_customer_address_')]
class CustomerAddressController extends AbstractController
{
    /**
     * 获取当前会员的所有地址
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function list(CustomerAddressRepository $addressRepository): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $this->getUser();
        // dd($customer);
        $addresses = $addressRepository->findBy(
            ['customer' => $customer],
            ['isDefault' => 'DESC', 'createdAt' => 'DESC']
        );
        
        return $this->json([
            'success' => true,
            'data' => array_map(fn($addr) => $this->formatAddress($addr), $addresses)
        ]);
    }
    
    /**
     * 检查当前会员是否有收货地址
     * 
     * 用于立即购买等操作前的地址验证
     * 返回格式：{ success: true, hasAddress: boolean, message: string, messageEn: string }
     */
    #[Route('/check', name: 'check', methods: ['GET'])]
    #[RequireAuth]
    #[RequireSignature]
    public function check(CustomerAddressRepository $addressRepository): JsonResponse
    {
        try {
            /** @var Customer $customer */
            $customer = $this->getUser();
            
            // 检查是否有地址
            $addressCount = $addressRepository->count(['customer' => $customer]);
            $hasAddress = $addressCount > 0;
            
            return $this->json([
                'success' => true,
                'hasAddress' => $hasAddress,
                'message' => $hasAddress ? '已有收货地址' : '请先添加收货地址',
                'messageEn' => $hasAddress ? 'Address found' : 'Please add a shipping address first'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'hasAddress' => false,
                'message' => '检查地址失败：' . $e->getMessage(),
                'messageEn' => 'Failed to check address: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 获取单个地址详情
     */
    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RequireAuth]
    #[RequireSignature]
    public function detail(
        int $id,
        CustomerAddressRepository $addressRepository
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        $address = $addressRepository->find($id);
        
        if (!$address) {
            return $this->json([
                'success' => false,
                'message' => '地址不存在',
                'messageEn' => 'Address not found'
            ], 404);
        }
        
        // 验证地址所有权
        if ($address->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '无权访问此地址',
                'messageEn' => 'Access denied'
            ], 403);
        }
        
        return $this->json([
            'success' => true,
            'data' => $this->formatAddress($address)
        ]);
    }
    
    /**
     * 创建新地址
     */
    #[Route('/create', name: 'create', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function create(
        Request $request,
        CustomerAddressRepository $addressRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        // 检查地址数量限制
        $addressCount = $addressRepository->count(['customer' => $customer]);
        if ($addressCount >= 10) {
            return $this->json([
                'success' => false,
                'message' => '地址数量已达上限（最多10个）',
                'messageEn' => 'Address limit reached (maximum 10)'
            ], 400);
        }
        
        $data = json_decode($request->getContent(), true);
        
        // 验证必填字段
        if (empty($data['receiverName'])) {
            return $this->json([
                'success' => false,
                'message' => '请输入收货人姓名',
                'messageEn' => 'Receiver name is required'
            ], 400);
        }
        
        if (empty($data['receiverPhone'])) {
            return $this->json([
                'success' => false,
                'message' => '请输入收货人电话',
                'messageEn' => 'Receiver phone is required'
            ], 400);
        }
        
        // 验证手机号格式
        if (!preg_match('/^1[3-9]\d{9}$/', $data['receiverPhone'])) {
            return $this->json([
                'success' => false,
                'message' => '手机号格式不正确',
                'messageEn' => 'Invalid phone number format'
            ], 400);
        }
        
        if (empty($data['receiverAddress'])) {
            return $this->json([
                'success' => false,
                'message' => '请输入详细地址',
                'messageEn' => 'Address is required'
            ], 400);
        }
        
        try {
            $address = new CustomerAddress();
            $address->setCustomer($customer);
            $address->setReceiverName($data['receiverName']);
            $address->setReceiverPhone($data['receiverPhone']);
            $address->setReceiverAddress($data['receiverAddress']);
            $address->setReceiverZipcode($data['receiverZipcode'] ?? null);
            $address->setAddressLabel($data['addressLabel'] ?? null);
            
            // 判断是否设为默认地址
            // 1. 如果这是第一个地址，自动设为默认
            // 2. 如果用户明确指定为默认，则设为默认并取消其他默认地址
            $isDefault = $data['isDefault'] ?? false;
            if ($addressCount === 0) {
                // 第一个地址自动设为默认
                $isDefault = true;
            } elseif ($isDefault) {
                // 用户指定为默认，取消其他默认地址
                $this->clearDefaultAddress($customer, $entityManager);
            }
            $address->setIsDefault($isDefault);
            
            $entityManager->persist($address);
            $entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '地址添加成功',
                'messageEn' => 'Address created successfully',
                'data' => $this->formatAddress($address)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '地址添加失败：' . $e->getMessage(),
                'messageEn' => 'Failed to create address: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 更新地址
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[RequireAuth]
    #[RequireSignature]
    public function update(
        int $id,
        Request $request,
        CustomerAddressRepository $addressRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        $address = $addressRepository->find($id);
        
        if (!$address) {
            return $this->json([
                'success' => false,
                'message' => '地址不存在',
                'messageEn' => 'Address not found'
            ], 404);
        }
        
        // 验证地址所有权
        if ($address->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '无权修改此地址',
                'messageEn' => 'Access denied'
            ], 403);
        }
        
        $data = json_decode($request->getContent(), true);
        
        // 验证必填字段
        if (isset($data['receiverName']) && empty($data['receiverName'])) {
            return $this->json([
                'success' => false,
                'message' => '收货人姓名不能为空',
                'messageEn' => 'Receiver name cannot be empty'
            ], 400);
        }
        
        if (isset($data['receiverPhone'])) {
            if (empty($data['receiverPhone'])) {
                return $this->json([
                    'success' => false,
                    'message' => '收货人电话不能为空',
                    'messageEn' => 'Receiver phone cannot be empty'
                ], 400);
            }
            if (!preg_match('/^1[3-9]\d{9}$/', $data['receiverPhone'])) {
                return $this->json([
                    'success' => false,
                    'message' => '手机号格式不正确',
                    'messageEn' => 'Invalid phone number format'
                ], 400);
            }
        }
        
        if (isset($data['receiverAddress']) && empty($data['receiverAddress'])) {
            return $this->json([
                'success' => false,
                'message' => '详细地址不能为空',
                'messageEn' => 'Address cannot be empty'
            ], 400);
        }
        
        try {
            if (isset($data['receiverName'])) {
                $address->setReceiverName($data['receiverName']);
            }
            if (isset($data['receiverPhone'])) {
                $address->setReceiverPhone($data['receiverPhone']);
            }
            if (isset($data['receiverAddress'])) {
                $address->setReceiverAddress($data['receiverAddress']);
            }
            if (isset($data['receiverZipcode'])) {
                $address->setReceiverZipcode($data['receiverZipcode']);
            }
            if (isset($data['addressLabel'])) {
                $address->setAddressLabel($data['addressLabel']);
            }
            
            // 处理默认地址状态
            if (isset($data['isDefault'])) {
                // 如果当前是默认地址，不允许取消默认状态（至少保留一个默认地址）
                if ($address->isDefault() && $data['isDefault'] === false) {
                    return $this->json([
                        'success' => false,
                        'message' => '至少需要保留一个默认地址',
                        'messageEn' => 'At least one default address is required'
                    ], 400);
                }
                
                // 如果设置为默认地址，取消其他默认地址
                if ($data['isDefault'] === true) {
                    $this->clearDefaultAddress($customer, $entityManager);
                    $address->setIsDefault(true);
                }
            }
            
            $entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '地址更新成功',
                'messageEn' => 'Address updated successfully',
                'data' => $this->formatAddress($address)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '地址更新失败：' . $e->getMessage(),
                'messageEn' => 'Failed to update address: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 删除地址
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[RequireAuth]
    #[RequireSignature]
    public function delete(
        int $id,
        CustomerAddressRepository $addressRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        $address = $addressRepository->find($id);
        
        if (!$address) {
            return $this->json([
                'success' => false,
                'message' => '地址不存在',
                'messageEn' => 'Address not found'
            ], 404);
        }
        
        // 验证地址所有权
        if ($address->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '无权删除此地址',
                'messageEn' => 'Access denied'
            ], 403);
        }
        
        // 如果是默认地址且地址数量大于1，先将另一个地址设为默认
        if ($address->isDefault()) {
            $otherAddresses = $addressRepository->findBy(
                ['customer' => $customer],
                ['createdAt' => 'ASC']
            );
            
            // 找到第一个不是当前地址的地址，设为默认
            foreach ($otherAddresses as $otherAddr) {
                if ($otherAddr->getId() !== $id) {
                    $otherAddr->setIsDefault(true);
                    break;
                }
            }
        }
        
        try {
            $entityManager->remove($address);
            $entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '地址删除成功',
                'messageEn' => 'Address deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '地址删除失败：' . $e->getMessage(),
                'messageEn' => 'Failed to delete address: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 设置默认地址
     */
    #[Route('/{id}/set-default', name: 'set_default', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[RequireAuth]
    #[RequireSignature]
    public function setDefault(
        int $id,
        CustomerAddressRepository $addressRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $this->getUser();
        
        $address = $addressRepository->find($id);
        
        if (!$address) {
            return $this->json([
                'success' => false,
                'message' => '地址不存在',
                'messageEn' => 'Address not found'
            ], 404);
        }
        
        // 验证地址所有权
        if ($address->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '无权操作此地址',
                'messageEn' => 'Access denied'
            ], 403);
        }
        
        try {
            // 取消其他默认地址
            $this->clearDefaultAddress($customer, $entityManager);
            
            // 设置当前地址为默认
            $address->setIsDefault(true);
            
            $entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => '默认地址设置成功',
                'messageEn' => 'Default address set successfully',
                'data' => $this->formatAddress($address)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '设置默认地址失败：' . $e->getMessage(),
                'messageEn' => 'Failed to set default address: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 清除该会员的所有默认地址标记
     */
    private function clearDefaultAddress(Customer $customer, EntityManagerInterface $entityManager): void
    {
        $defaultAddresses = $entityManager->getRepository(CustomerAddress::class)
            ->findBy(['customer' => $customer, 'isDefault' => true]);
        
        foreach ($defaultAddresses as $addr) {
            $addr->setIsDefault(false);
        }
    }
    
    /**
     * 格式化地址数据
     */
    private function formatAddress(CustomerAddress $address): array
    {
        return [
            'id' => $address->getId(),
            'receiverName' => $address->getReceiverName(),
            'receiverPhone' => $address->getReceiverPhone(),
            'receiverAddress' => $address->getReceiverAddress(),
            'receiverZipcode' => $address->getReceiverZipcode(),
            'addressLabel' => $address->getAddressLabel(),
            'isDefault' => $address->isDefault(),
            'createdAt' => $address->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updatedAt' => $address->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
