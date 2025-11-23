<?php

namespace App\Controller\Shop;

use App\Service\PathConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SupplierController extends AbstractController
{
    #[Route('/supplier', name: 'shop_supplier')]
    public function index(): Response
    {
        // 获取供应商登录路径
        $supplierLoginPath = PathConfigService::getSupplierLoginPath();
        
        // 渲染供应商入驻页面的 Vue 组件，并传递登录路径
        return $this->render('shop/supplier.html.twig', [
            'supplierLoginPath' => $supplierLoginPath,
        ]);
    }
}