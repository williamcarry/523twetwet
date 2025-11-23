<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'shop_login')]
    public function login(): Response
    {
        // 渲染登录页面的 Vue 组件
        return $this->render('shop/login.html.twig');
    }

    #[Route('/register', name: 'shop_register')]
    public function register(): Response
    {
        // 渲染注册页面的 Vue 组件
        return $this->render('shop/register.html.twig');
    }
}