<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HelpCenterController extends AbstractController
{
    #[Route('/help-center', name: 'shop_help_center')]
    public function index(): Response
    {
        // 渲染帮助中心页面的 Vue 组件
        return $this->render('shop/help_center.html.twig');
    }
}