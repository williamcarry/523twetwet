<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AboutMeController extends AbstractController
{
    #[Route('/about-me', name: 'shop_about_me')]
    public function index(): Response
    {
        // 渲染关于赛盈页面的 Vue 组件
        return $this->render('shop/about_me.html.twig');
    }
}