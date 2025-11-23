<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MembershipController extends AbstractController
{
    #[Route('/membership', name: 'shop_membership')]
    public function index(): Response
    {
        // 渲染会员权益页面的 Vue 组件
        return $this->render('shop/membership.html.twig');
    }
}