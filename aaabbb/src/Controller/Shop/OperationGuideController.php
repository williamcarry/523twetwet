<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class OperationGuideController extends AbstractController
{
    #[Route('/operation-guide', name: 'shop_operation_guide')]
    public function index(): Response
    {
        // 渲染操作引导页面的 Vue 组件
        return $this->render('shop/operation_guide.html.twig');
    }
}