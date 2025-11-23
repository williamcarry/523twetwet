<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CartController extends AbstractController
{
    #[Route('/cart', name: 'shop_cart')]
    public function index(): Response
    {
        // 渲染购物车页面的 Vue 组件
        return $this->render('shop/cart.html.twig');
    }
}