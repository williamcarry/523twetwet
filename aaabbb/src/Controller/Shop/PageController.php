<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/shop', name: 'shop_')]
class PageController extends AbstractController
{
    #[Route('/partners', name: 'shop_partners')]
    public function partners(): Response
    {
        return $this->render('shop/partners.html.twig');
    }

    #[Route('/vat-policy', name: 'shop_vat_policy')]
    public function vatPolicy(): Response
    {
        return $this->render('shop/vat_policy.html.twig');
    }

    #[Route('/faq/{code}', name: 'shop_faq_detail')]
    public function faqDetail(string $code): Response
    {
        // 可以在这里根据code从数据库查询FAQ详情
        return $this->render('shop/faq_detail.html.twig', [
            'code' => $code,
        ]);
    }

    #[Route('/item/{id}', name: 'shop_item_detail', requirements: ['id' => '\d+'])]
    public function itemDetail(int $id): Response
    {
        // 可以在这里根据id从数据库查询商品详情
        return $this->render('shop/item_detail.html.twig', [
            'id' => $id,
        ]);
    }
}
