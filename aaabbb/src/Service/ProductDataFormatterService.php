<?php

namespace App\Service;

use App\Entity\Product;

/**
 * 商品数据格式化服务
 * 
 * 负责将商品实体数据格式化为前端所需的数据结构
 * 特别是处理多区域配置数据的构建
 */
class ProductDataFormatterService
{
    /**
     * 构建商品的多区域配置数据
     * 
     * @param array $productData 从Repository获取的原始商品数据
     * @return array 格式化后的多区域配置
     * 
     * 返回格式:
     * [
     *   'CN' => [
     *     'price' => ['originalPrice' => ..., 'sellingPrice' => ..., 'discountRate' => ..., 'currency' => ...],
     *     'stock' => 100
     *   ],
     *   'US' => [...],
     *   ...
     * ]
     */
    public function buildRegionConfigs(array $productData): array
    {
        $shippingRegions = $productData['shippingRegions'] ?? [];
        $regionConfigs = [];
        
        // 遍历所有发货区域，构建每个区域的完整数据
        foreach ($shippingRegions as $region) {
            // 获取该区域的价格信息
            $regionPrice = $this->findRegionPrice($productData, $region);
            
            // 获取该区域的库存信息（从shippings表）
            $regionStock = $this->findRegionStock($productData, $region);
            
            // 构建该区域的配置
            $regionConfigs[$region] = [
                'price' => [
                    'originalPrice' => $regionPrice['originalPrice'] ?? null,
                    'sellingPrice' => $regionPrice['sellingPrice'] ?? null,
                    'discountRate' => $regionPrice['discountRate'] ?? null,
                    'currency' => $regionPrice['currency'] ?? 'USD',
                ],
                'stock' => $regionStock,
            ];
        }
        
        return $regionConfigs;
    }
    
    /**
     * 获取主区域（第一个发货区域）的数据
     * 
     * @param array $productData 商品数据
     * @param array $regionConfigs 区域配置数据
     * @return array 包含price和stock的数组
     */
    public function getPrimaryRegionData(array $productData, array $regionConfigs): array
    {
        $shippingRegions = $productData['shippingRegions'] ?? [];
        $primaryRegion = !empty($shippingRegions) ? $shippingRegions[0] : null;
        
        $defaultPrice = $primaryRegion && isset($regionConfigs[$primaryRegion]) 
            ? $regionConfigs[$primaryRegion]['price'] 
            : [
                'originalPrice' => null, 
                'sellingPrice' => null, 
                'discountRate' => null, 
                'currency' => 'USD'
            ];
        
        $defaultStock = $primaryRegion && isset($regionConfigs[$primaryRegion]) 
            ? $regionConfigs[$primaryRegion]['stock'] 
            : 0;
        
        return [
            'price' => $defaultPrice,
            'stock' => $defaultStock,
        ];
    }
    
    /**
     * 格式化单个商品数据为前端所需格式
     * 
     * @param array $productData 从Repository获取的原始商品数据
     * @param string|null $thumbnailImageUrl 缩略图URL（已处理签名）
     * @return array 格式化后的商品数据
     */
    public function formatProductForFrontend(array $productData, ?string $thumbnailImageUrl = null): array
    {
        // 构建多区域配置数据
        $regionConfigs = $this->buildRegionConfigs($productData);
        
        // 获取主区域的默认显示数据
        $primaryData = $this->getPrimaryRegionData($productData, $regionConfigs);
        
        return [
            'id' => $productData['id'] ?? null,
            'sku' => $productData['sku'] ?? null,
            'spu' => $productData['spu'] ?? null,
            'title' => $productData['title'] ?? '',
            'titleEn' => $productData['titleEn'] ?? '',
            'thumbnailImage' => $thumbnailImageUrl,
            'image' => $thumbnailImageUrl,  // 别名，方便前端使用
            // 默认显示数据（第一个区域）
            'stock' => $primaryData['stock'],
            'originalPrice' => $primaryData['price']['originalPrice'] ?? $productData['originalPrice'] ?? null,
            'sellingPrice' => $primaryData['price']['sellingPrice'] ?? $productData['sellingPrice'] ?? null,
            'discountRate' => $primaryData['price']['discountRate'] ?? null,
            'currency' => $primaryData['price']['currency'] ?? $productData['currency'] ?? 'USD',
            // 多区域配置数据
            'shippingRegions' => $productData['shippingRegions'] ?? [],
            'regionConfigs' => $regionConfigs,
        ];
    }
    
    /**
     * 从商品数据中查找指定区域的价格信息
     * 
     * @param array $productData 商品数据
     * @param string $region 区域代码
     * @return array|null 价格信息数组或null
     */
    private function findRegionPrice(array $productData, string $region): ?array
    {
        if (!isset($productData['prices'])) {
            return null;
        }
        
        foreach ($productData['prices'] as $price) {
            if ($price['region'] === $region) {
                return $price;
            }
        }
        
        return null;
    }
    
    /**
     * 从商品数据中查找指定区域的库存信息
     * 
     * @param array $productData 商品数据
     * @param string $region 区域代码
     * @return int 库存数量
     */
    private function findRegionStock(array $productData, string $region): int
    {
        if (!isset($productData['shippings'])) {
            return 0;
        }
        
        foreach ($productData['shippings'] as $shipping) {
            if ($shipping['region'] === $region) {
                return $shipping['availableStock'] ?? 0;
            }
        }
        
        return 0;
    }
}
