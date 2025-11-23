<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 商城页面控制器
 * 
 * 负责处理商城前端的页面路由，渲染对应的 Twig 模板
 * 每个方法对应一个页面路由，返回渲染后的 HTML 响应
 * 
 * 注意：
 * - Twig 模板位于: templates/shop/
 * - Vue 组件位于: assets/vue/controllers/shop/pages/
 * - 数据接口位于: src/Controller/Api/HomeController.php
 */
class ShopController extends AbstractController
{
    /**
     * 商城首页
     * 
     * 路由: /
     * 模板: templates/shop/home.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/HomePage.vue
     * 
     * 功能：
     * - 展示首页轮播图（数据来源：/shop/api/home/categories）
     * - 展示三级分类菜单侧边栏
     * - 展示平台爆款商品
     * - 展示楼层商品
     * 
     * @return Response
     */
    #[Route('/', name: 'shop_home')]
    public function home(): Response
    {
        return $this->render('shop/home.html.twig');
    }
    /**
     * 所有商品页面
     * 
     * 路由: /all-products
     * 模板: templates/shop/all_products.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/AllProductsPage.vue
     * 
     * 功能：
     * - 展示所有商品列表
     * - 支持筛选、排序、分页
     * - 支持搜索功能
     * 
     * @return Response
     */
    #[Route('/all-products', name: 'shop_all_products')]
    public function allProducts(): Response
    {
        return $this->render('shop/all_products.html.twig');
    }
    /**
     * 点击首页分类会跳转的这个路由。
     * 
     * 路由: /all-categories-products
     * 模板: templates/shop/all_categories_products.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/AllCategoriesProductsPage.vue
     * 
     * 功能：
     * - 根据分类筛选商品。
     * - 支持筛选、排序、分页
     * - 支持搜索功能
     * 
     * @return Response
     */
    #[Route('/all-categories-products', name: 'shop_all_categories_products')]
    public function allCategoriesProducts(): Response
    {
        return $this->render('shop/all_categories_products.html.twig');
    }
    /**
     * 热销商品页面
     * 
     * 路由: /hot-sales
     * 模板: templates/shop/hot_sales.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/HotSalesPage.vue
     * 
     * 功能：展示热销商品列表
     * 
     * @return Response
     */
    #[Route('/hot-sales', name: 'shop_hot_sales')]
    public function hotSales(): Response
    {
        return $this->render('shop/hot_sales.html.twig');
    }

    /**
     * 折扣商品页面
     * 
     * 路由: /discount
     * 模板: templates/shop/discount.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/DiscountPage.vue
     * 
     * 功能：展示正在打折的商品
     * 
     * @return Response
     */
    #[Route('/discount', name: 'shop_discount')]
    public function discount(): Response
    {
        return $this->render('shop/discount.html.twig');
    }

    /**
     * 折扣促销页面（别名路由）
     * 
     * 路由: /discount-sale
     * 模板: templates/shop/discount.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/DiscountPage.vue
     * 
     * 注意：这是 /discount 的别名路由，指向同一个页面
     * 
     * @return Response
     */
    #[Route('/discount-sale', name: 'shop_discount_sale')]
    public function discountSale(): Response
    {
        // 别名路由，指向同一个页面
        return $this->render('shop/discount.html.twig');
    }

    /**
     * 新品页面
     * 
     * 路由: /new
     * 模板: templates/shop/new.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/NewPage.vue
     * 
     * 功能：展示最新上架的商品
     * 
     * @return Response
     */
    #[Route('/new', name: 'shop_new')]
    public function newProducts(): Response
    {
        return $this->render('shop/new.html.twig');
    }

    /**
     * 直发商品页面
     * 
     * 路由: /direct-delivery
     * 模板: templates/shop/direct_delivery.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/DirectDeliveryPage.vue
     * 
     * 功能：展示支持直发的商品列表
     * 
     * @return Response
     */
    #[Route('/direct-delivery', name: 'shop_direct_delivery')]
    public function directDelivery(): Response
    {
        return $this->render('shop/direct_delivery.html.twig');
    }

    /**
     * 所有分类页面
     * 
     * 路由: /all-categories
     * 模板: templates/shop/all_categories.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/AllCategoriesPage.vue
     * 
     * 功能：展示所有商品分类
     * 
     * @return Response
     */
    #[Route('/all-categories', name: 'shop_all_categories')]
    public function allCategories(): Response
    {
        return $this->render('shop/all_categories.html.twig');
    }
    /**
     * 'amazon', 'walmart', 'ebay', 'temu', 'shein', 'tiktok'
     * 
     * 路由: /cross-bordere-commerce
     * 模板: templates/shop/cross_bordere_commerce.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/crossBordereCommercePage.vue
     * 
     * 功能：展示所有商品分类
     * 
     * @return Response
     */
    #[Route('/cross-bordere-commerce', name: 'shop_cross_bordere_commerce')]
    public function crossBordereCommerce(): Response
    {
        return $this->render('shop/shop_cross_bordere_commerce.html.twig');
    }

    /**
     * 公共消息中心页面
     * 
     * 路由: /public-messages
     * 模板: templates/shop/public_messages.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/PublicMessagePage.vue
     * 
     * 功能：
     * - 展示所有公共消息（商城公告 + 平台消息）
     * - 不需要登录验证
     * - 不需要签名验证
     * - 支持分页（20条/页）
     * 
     * @return Response
     */
    #[Route('/public-messages', name: 'shop_public_messages')]
    public function publicMessages(): Response
    {
        return $this->render('shop/public_messages.html.twig');
    }

    /**
     * 订单支付成功页面
     * 
     * 路由: /payment-success
     * 模板: templates/shop/payment_success.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/PaymentSuccessPage.vue
     * 
     * 功能：
     * - 显示订单支付成功信息
     * - 提供返回个人中心按钮
     * - 使用网站公用 SiteHeader 和 SiteFooter
     * - 支持多语言
     * 
     * @return Response
     */
    #[Route('/payment-success', name: 'shop_payment_success')]
    public function paymentSuccess(): Response
    {
        return $this->render('shop/payment_success.html.twig');
    }

    /**
     * 余额操作成功页面（充值/提现通用）
     * 
     * 路由: /balance-success
     * 模板: templates/shop/balance_success.html.twig
     * Vue组件: assets/vue/controllers/shop/pages/WithdrawalSuccessPage.vue
     * 
     * 功能：
     * - 通过 type 参数区分充值成功或提现成功
     * - 显示充值/提现成功信息
     * - 提供返回个人中心按钮
     * - 使用网站公用 SiteHeader 和 SiteFooter
     * - 支持多语言
     * 
     * URL 参数：
     * - type: 'recharge'（充值成功）或 'withdrawal'（提现成功）
     * - orderNo: 订单号/提现单号（可选）
     * 
     * 使用示例：
     * - 充值成功：/balance-success?type=recharge&orderNo=RECHARGE_20250120_123
     * - 提现成功：/balance-success?type=withdrawal&orderNo=WIT20250120AB12CD
     * 
     * @return Response
     */
    #[Route('/balance-success', name: 'shop_balance_success')]
    public function balanceSuccess(): Response
    {
        return $this->render('shop/balance_success.html.twig');
    }
}