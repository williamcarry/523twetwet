<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DistributorsController extends AbstractController
{
    #[Route('/distributors', name: 'shop_distributors')]
    public function index(): Response
    {
        // 渲染跨境卖家入驻页面的 Vue 组件
        return $this->render('shop/distributors.html.twig');
    }
}