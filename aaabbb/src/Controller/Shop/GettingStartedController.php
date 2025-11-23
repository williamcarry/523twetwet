<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GettingStartedController extends AbstractController
{
    #[Route('/getting-started', name: 'shop_getting_started')]
    public function index(): Response
    {
        // 渲染 getting started 页面的 Vue 组件
        return $this->render('shop/getting_started.html.twig');
    }
}