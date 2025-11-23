<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ContactUsController extends AbstractController
{
    #[Route('/contact-us', name: 'shop_contact_us')]
    public function index(): Response
    {
        // 渲染联系我们页面的 Vue 组件
        return $this->render('shop/contact_us.html.twig');
    }
}