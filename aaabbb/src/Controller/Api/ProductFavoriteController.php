<?php

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\ProductFavorite;
use App\Entity\ProductFavoriteGroup;
use App\Repository\ProductFavoriteRepository;
use App\Repository\ProductFavoriteGroupRepository;
use App\Repository\ProductRepository;
use App\Security\Attribute\RequireAuth;
use App\Security\Attribute\RequireSignature;
use App\Service\QiniuUploadService;
use App\Service\RsaCryptoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop/api/favorite', name: 'api_shop_favorite_')]
class ProductFavoriteController extends AbstractController
{
    private RsaCryptoService $rsaCryptoService;

    public function __construct(
        RsaCryptoService $rsaCryptoService,
        private \App\Service\SiteConfigService $siteConfigService
    )
    {
        $this->rsaCryptoService = $rsaCryptoService;
    }
    /**
     * 获取用户的所有收藏夹分组列表
     */
    #[Route('/groups', name: 'groups', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function getGroups(
        ProductFavoriteGroupRepository $groupRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $groups = $groupRepository->findByCustomer($customer->getId());

        $result = [];
        foreach ($groups as $group) {
            $result[] = [
                'id' => $group->getId(),
                'groupName' => $group->getGroupName(),
                'groupDescription' => $group->getGroupDescription(),
                'groupIcon' => $group->getGroupIcon(),
                'groupColor' => $group->getGroupColor(),
                'sortOrder' => $group->getSortOrder(),
                'productCount' => $group->getProductCount(),
                'createdAt' => $group->getCreatedAt()->format('Y-m-d H:i:s'),
                'updatedAt' => $group->getUpdatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'success' => true,
            'groups' => $result,
        ]);
    }

    /**
     * 获取指定分组的收藏商品（分页）
     */
    #[Route('/group/products', name: 'group_products', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function getGroupProducts(
        Request $request,
        ProductFavoriteRepository $favoriteRepository,
        ProductFavoriteGroupRepository $groupRepository,
        QiniuUploadService $qiniuService
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $groupId = $data['groupId'] ?? null;
        $page = $data['page'] ?? 1;

        if (!$groupId) {
            return $this->json([
                'success' => false,
                'message' => '缺少分组ID',
                'messageEn' => 'Missing groupId'
            ], 400);
        }

        // 验证分组是否属于当前用户
        $group = $groupRepository->find($groupId);
        if (!$group || $group->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '分组不存在',
                'messageEn' => 'Group not found'
            ], 404);
        }

        // 获取分页数据
        $limit = 20;
        $result = $favoriteRepository->findPaginatedByGroup($customer->getId(), $groupId, $page, $limit);

        // 格式化返回数据
        $products = [];
        foreach ($result['data'] as $favorite) {
            /** @var ProductFavorite $favorite */
            $thumbnailUrl = null;
            if ($favorite->getProductThumbnail()) {
                $thumbnailUrl = $qiniuService->getPrivateUrl($favorite->getProductThumbnail());
            }

            $products[] = [
                'id' => $favorite->getId(),
                'productId' => $favorite->getProduct()->getId(),
                'productSku' => $favorite->getProductSku(),
                'productTitle' => $favorite->getProductTitle(),
                'productThumbnail' => $thumbnailUrl,
                'originalPrice' => $favorite->getOriginalPrice(),
                'sellingPrice' => $favorite->getSellingPrice(),
                'sortOrder' => $favorite->getSortOrder(),
                'createdAt' => $favorite->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'success' => true,
            'group' => [
                'id' => $group->getId(),
                'groupName' => $group->getGroupName(),
                'groupDescription' => $group->getGroupDescription(),
                'productCount' => $group->getProductCount(),
            ],
            'products' => $products,
            'pagination' => [
                'currentPage' => $result['page'],
                'totalPages' => $result['totalPages'],
                'totalItems' => $result['total'],
                'itemsPerPage' => $result['limit'],
            ],
            'siteCurrency' => $this->siteConfigService->getConfigValue('site_currency') ?? 'USD'  // 网站货币符号
        ]);
    }

    /**
     * 添加商品到收藏夹
     */
    #[Route('/add', name: 'add', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function addFavorite(
        Request $request,
        EntityManagerInterface $em,
        ProductRepository $productRepository,
        ProductFavoriteGroupRepository $groupRepository,
        ProductFavoriteRepository $favoriteRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $productId = $data['productId'] ?? null;
        $groupId = $data['groupId'] ?? null;

        if (!$productId || !$groupId) {
            return $this->json([
                'success' => false,
                'message' => '缺少参数',
                'messageEn' => 'Missing parameters'
            ], 400);
        }

        // 验证商品是否存在
        $product = $productRepository->find($productId);
        if (!$product) {
            return $this->json([
                'success' => false,
                'message' => '商品不存在',
                'messageEn' => 'Product not found'
            ], 404);
        }

        // 验证分组是否存在且属于当前用户
        $group = $groupRepository->find($groupId);
        if (!$group || $group->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '分组不存在',
                'messageEn' => 'Group not found'
            ], 404);
        }

        // 检查是否已收藏（在任何分组中）
        $existingFavorite = $favoriteRepository->findOneBy([
            'customer' => $customer,
            'product' => $productId
        ]);
        
        if ($existingFavorite) {
            // 如果已在同一个分组，提示已收藏
            if ($existingFavorite->getFavoriteGroup()->getId() === $groupId) {
                return $this->json([
                    'success' => false,
                    'message' => '该商品已在此分组中',
                    'messageEn' => 'Product already in this group'
                ], 400);
            }
            
            // 如果在不同分组，移动到新分组
            $oldGroupName = $existingFavorite->getFavoriteGroup()->getGroupName();
            $oldGroupId = $existingFavorite->getFavoriteGroup()->getId();
            
            // 更新分组
            $existingFavorite->setFavoriteGroup($group);
            
            // 更新旧分组商品数量（减1）
            $groupRepository->decrementProductCount($oldGroupId, 1);
            
            // 更新新分组商品数量（加1）
            $groupRepository->incrementProductCount($groupId, 1);
            
            $em->flush();
            
            return $this->json([
                'success' => true,
                'message' => '商品已从「' . $oldGroupName . '」移至「' . $group->getGroupName() . '」',
                'messageEn' => 'Product moved from "' . $oldGroupName . '" to "' . $group->getGroupName() . '"',
                'favoriteId' => $existingFavorite->getId(),
                'moved' => true,
                'oldGroupName' => $oldGroupName
            ]);
        }

        // 创建收藏记录
        $favorite = new ProductFavorite();
        $favorite->setCustomer($customer);
        $favorite->setProduct($product);
        $favorite->setFavoriteGroup($group);
        $favorite->setProductSku($product->getSku());
        $favorite->setProductTitle([
            'zh' => $product->getTitle(),
            'en' => $product->getTitleEn() ?? $product->getTitle(),
        ]);
        $favorite->setProductThumbnail($product->getThumbnailImage());
        
        // 获取商品价格
        $productPrices = $product->getPrices();
        if (!$productPrices->isEmpty()) {
            $firstPrice = $productPrices->first();
            $favorite->setOriginalPrice($firstPrice->getOriginalPrice());
            $favorite->setSellingPrice($firstPrice->getSellingPrice());
        }

        $em->persist($favorite);
        
        // 更新分组商品数量
        $groupRepository->incrementProductCount($groupId, 1);
        
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => '添加收藏成功',
            'messageEn' => 'Added to favorites',
            'favoriteId' => $favorite->getId(),
        ]);
    }

    /**
     * 从收藏夹移除商品
     */
    #[Route('/remove', name: 'remove', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function removeFavorite(
        Request $request,
        EntityManagerInterface $em,
        ProductFavoriteRepository $favoriteRepository,
        ProductFavoriteGroupRepository $groupRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $favoriteId = $data['favoriteId'] ?? null;

        if (!$favoriteId) {
            return $this->json([
                'success' => false,
                'message' => '缺少收藏ID',
                'messageEn' => 'Missing favoriteId'
            ], 400);
        }

        $favorite = $favoriteRepository->find($favoriteId);
        if (!$favorite || $favorite->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '收藏不存在',
                'messageEn' => 'Favorite not found'
            ], 404);
        }

        $groupId = $favorite->getFavoriteGroup()->getId();
        
        $em->remove($favorite);
        
        // 更新分组商品数量
        $groupRepository->decrementProductCount($groupId, 1);
        
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => '移除收藏成功',
            'messageEn' => 'Removed from favorites',
        ]);
    }

    /**
     * 创建新的收藏夹分组
     */
    #[Route('/group/create', name: 'group_create', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function createGroup(
        Request $request,
        EntityManagerInterface $em,
        ProductFavoriteGroupRepository $groupRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $groupName = $data['groupName'] ?? null;
        $groupDescription = $data['groupDescription'] ?? null;
        $groupIcon = $data['groupIcon'] ?? 'heart';
        $groupColor = $data['groupColor'] ?? 'gray';

        if (!$groupName) {
            return $this->json([
                'success' => false,
                'message' => '请输入分组名称',
                'messageEn' => 'Group name is required'
            ], 400);
        }

        // 获取当前用户的分组数量
        $existingGroups = $groupRepository->findByCustomer($customer->getId());
        
        // 检查分组数量限制（最多20个）
        if (count($existingGroups) >= 20) {
            return $this->json([
                'success' => false,
                'message' => '最多只能创建20个分组',
                'messageEn' => 'Maximum 20 groups allowed'
            ], 400);
        }

        // 检查分组名称是否已存在
        if ($groupRepository->isGroupNameExists($customer->getId(), $groupName)) {
            return $this->json([
                'success' => false,
                'message' => '分组名称已存在',
                'messageEn' => 'Group name already exists'
            ], 400);
        }

        // 创建分组
        $group = new ProductFavoriteGroup();
        $group->setCustomer($customer);
        $group->setGroupName($groupName);
        $group->setGroupDescription($groupDescription);
        $group->setGroupIcon($groupIcon);
        $group->setGroupColor($groupColor);
        
        // 获取当前最大排序号
        $existingGroups = $groupRepository->findByCustomer($customer->getId());
        $maxSortOrder = 0;
        foreach ($existingGroups as $existingGroup) {
            if ($existingGroup->getSortOrder() > $maxSortOrder) {
                $maxSortOrder = $existingGroup->getSortOrder();
            }
        }
        $group->setSortOrder($maxSortOrder + 1);

        $em->persist($group);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => '创建分组成功',
            'messageEn' => 'Group created successfully',
            'group' => [
                'id' => $group->getId(),
                'groupName' => $group->getGroupName(),
                'groupDescription' => $group->getGroupDescription(),
                'groupIcon' => $group->getGroupIcon(),
                'groupColor' => $group->getGroupColor(),
                'sortOrder' => $group->getSortOrder(),
                'productCount' => $group->getProductCount(),
            ],
        ]);
    }

    /**
     * 删除收藏夹分组
     */
    #[Route('/group/delete', name: 'group_delete', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function deleteGroup(
        Request $request,
        EntityManagerInterface $em,
        ProductFavoriteGroupRepository $groupRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $groupId = $data['groupId'] ?? null;

        if (!$groupId) {
            return $this->json([
                'success' => false,
                'message' => '缺少分组ID',
                'messageEn' => 'Missing groupId'
            ], 400);
        }

        $group = $groupRepository->find($groupId);
        if (!$group || $group->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '分组不存在',
                'messageEn' => 'Group not found'
            ], 404);
        }

        // 删除分组（级联删除所有收藏商品）
        $em->remove($group);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => '删除分组成功',
            'messageEn' => 'Group deleted successfully',
        ]);
    }

    /**
     * 批量检查商品收藏状态
     */
    #[Route('/check-status', name: 'check_status', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function checkFavoriteStatus(
        Request $request,
        ProductFavoriteRepository $favoriteRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $productIds = $data['productIds'] ?? [];

        if (empty($productIds) || !is_array($productIds)) {
            return $this->json([
                'success' => false,
                'message' => '缺少商品ID列表',
                'messageEn' => 'Missing productIds'
            ], 400);
        }

        // 批量查询收藏状态
        $favoritedProducts = [];
        foreach ($productIds as $productId) {
            $favorite = $favoriteRepository->findOneBy([
                'customer' => $customer,
                'product' => $productId
            ]);

            if ($favorite) {
                $favoritedProducts[(string)$productId] = [
                    'isFavorited' => true,
                    'favoriteId' => $favorite->getId(),
                    'groupId' => $favorite->getFavoriteGroup()->getId(),
                    'groupName' => $favorite->getFavoriteGroup()->getGroupName()
                ];
            } else {
                $favoritedProducts[(string)$productId] = [
                    'isFavorited' => false
                ];
            }
        }

        return $this->json([
            'success' => true,
            'favoritedProducts' => $favoritedProducts,
        ]);
    }

    /**
     * 批量添加商品到收藏夹
     */
    #[Route('/batch-add', name: 'batch_add', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function batchAddFavorite(
        Request $request,
        EntityManagerInterface $em,
        ProductRepository $productRepository,
        ProductFavoriteGroupRepository $groupRepository,
        ProductFavoriteRepository $favoriteRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $productIds = $data['productIds'] ?? [];
        $groupId = $data['groupId'] ?? null;

        if (empty($productIds) || !is_array($productIds)) {
            return $this->json([
                'success' => false,
                'message' => '缺少商品ID列表',
                'messageEn' => 'Missing productIds'
            ], 400);
        }

        if (!$groupId) {
            return $this->json([
                'success' => false,
                'message' => '缺少分组ID',
                'messageEn' => 'Missing groupId'
            ], 400);
        }

        // 验证分组是否存在且属于当前用户
        $group = $groupRepository->find($groupId);
        if (!$group || $group->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '分组不存在',
                'messageEn' => 'Group not found'
            ], 404);
        }

        $successCount = 0;
        $skippedCount = 0;
        $movedCount = 0;
        $skippedProducts = [];
        $movedProducts = [];

        foreach ($productIds as $productId) {
            // 检查商品是否存在
            $product = $productRepository->find($productId);
            if (!$product) {
                $skippedCount++;
                $skippedProducts[] = $productId;
                continue;
            }

            // 检查是否已收藏（在任何分组中）
            $existingFavorite = $favoriteRepository->findOneBy([
                'customer' => $customer,
                'product' => $productId
            ]);
            
            if ($existingFavorite) {
                // 如果已在同一个分组，跳过
                if ($existingFavorite->getFavoriteGroup()->getId() === $groupId) {
                    $skippedCount++;
                    $skippedProducts[] = $productId;
                    continue;
                }
                
                // 如果在不同分组，移动到新分组
                $oldGroupId = $existingFavorite->getFavoriteGroup()->getId();
                $existingFavorite->setFavoriteGroup($group);
                
                // 更新旧分组商品数量（减1）
                $groupRepository->decrementProductCount($oldGroupId, 1);
                
                // 更新新分组商品数量（加1）
                $groupRepository->incrementProductCount($groupId, 1);
                
                $movedCount++;
                $movedProducts[] = $productId;
                continue;
            }

            // 创建收藏记录
            $favorite = new ProductFavorite();
            $favorite->setCustomer($customer);
            $favorite->setProduct($product);
            $favorite->setFavoriteGroup($group);
            $favorite->setProductSku($product->getSku());
            $favorite->setProductTitle([
                'zh' => $product->getTitle(),
                'en' => $product->getTitleEn() ?? $product->getTitle(),
            ]);
            $favorite->setProductThumbnail($product->getThumbnailImage());
            
            // 获取商品价格
            $productPrices = $product->getPrices();
            if (!$productPrices->isEmpty()) {
                $firstPrice = $productPrices->first();
                $favorite->setOriginalPrice($firstPrice->getOriginalPrice());
                $favorite->setSellingPrice($firstPrice->getSellingPrice());
            }

            $em->persist($favorite);
            $successCount++;
        }

        // 更新分组商品数量（只增加新收藏的）
        if ($successCount > 0) {
            $groupRepository->incrementProductCount($groupId, $successCount);
        }
        
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => '批量收藏成功',
            'messageEn' => 'Batch favorite successful',
            'successCount' => $successCount,
            'skippedCount' => $skippedCount,
            'movedCount' => $movedCount,
            'skippedProducts' => $skippedProducts,
            'movedProducts' => $movedProducts,
        ]);
    }

    /**
     * 重命名收藏夹分组
     */
    #[Route('/group/rename', name: 'group_rename', methods: ['POST'])]
    #[RequireAuth]
    #[RequireSignature]
    public function renameGroup(
        Request $request,
        EntityManagerInterface $em,
        ProductFavoriteGroupRepository $groupRepository
    ): JsonResponse {
        /** @var Customer|null $customer */
        $customer = $this->getUser();

        $requestData = json_decode($request->getContent(), true);
        
        // 解密整个JSON对象
        try {
            if (isset($requestData['encryptedPayload'])) {
                $data = $this->rsaCryptoService->decryptObject($requestData['encryptedPayload']);
            } else {
                $data = $requestData;
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '请求失败，请重试',
                'messageEn' => 'Request failed, please try again'
            ], 400);
        }
        
        $groupId = $data['groupId'] ?? null;
        $newGroupName = $data['groupName'] ?? null;
        $newGroupDescription = $data['groupDescription'] ?? null;

        if (!$groupId) {
            return $this->json([
                'success' => false,
                'message' => '缺少分组ID',
                'messageEn' => 'Missing groupId'
            ], 400);
        }

        $group = $groupRepository->find($groupId);
        if (!$group || $group->getCustomer()->getId() !== $customer->getId()) {
            return $this->json([
                'success' => false,
                'message' => '分组不存在',
                'messageEn' => 'Group not found'
            ], 404);
        }

        if (!$newGroupName) {
            return $this->json([
                'success' => false,
                'message' => '请输入分组名称',
                'messageEn' => 'Group name is required'
            ], 400);
        }

        // 检查新名称是否已存在（排除当前分组）
        if ($groupRepository->isGroupNameExists($customer->getId(), $newGroupName, $groupId)) {
            return $this->json([
                'success' => false,
                'message' => '分组名称已存在',
                'messageEn' => 'Group name already exists'
            ], 400);
        }

        $group->setGroupName($newGroupName);
        if ($newGroupDescription !== null) {
            $group->setGroupDescription($newGroupDescription);
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => '重命名成功',
            'messageEn' => 'Group renamed successfully',
            'group' => [
                'id' => $group->getId(),
                'groupName' => $group->getGroupName(),
                'groupDescription' => $group->getGroupDescription(),
            ],
        ]);
    }
}
