<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FeedbackController extends AbstractController
{
    #[Route('/feedback', name: 'shop_feedback')]
    public function index(): Response
    {
        // 渲染体验反馈页面的 Vue 组件
        return $this->render('shop/feedback.html.twig');
    }
}