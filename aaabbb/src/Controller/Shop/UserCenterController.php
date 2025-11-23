<?php

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UserCenterController extends AbstractController
{
    #[Route('/user-center', name: 'shop_user_center')]
    public function index(): Response
    {
        return $this->render('shop/user_center.html.twig');
    }

    #[Route('/product-management', name: 'shop_product_management')]
    #[IsGranted('ROLE_USER')]
    public function productManagement(): Response
    {
        return $this->render('shop/product_management.html.twig');
    }

    #[Route('/listing-push', name: 'shop_listing_push')]
    #[IsGranted('ROLE_USER')]
    public function listingPush(): Response
    {
        return $this->render('shop/listing_push.html.twig');
    }

    #[Route('/listing-products', name: 'shop_listing_products')]
    #[IsGranted('ROLE_USER')]
    public function listingProducts(): Response
    {
        return $this->render('shop/listing_products.html.twig');
    }

    #[Route('/brand-auth', name: 'shop_brand_auth')]
    #[IsGranted('ROLE_USER')]
    public function brandAuth(): Response
    {
        return $this->render('shop/brand_auth.html.twig');
    }

    #[Route('/product-notification', name: 'shop_product_notification')]
    #[IsGranted('ROLE_USER')]
    public function productNotification(): Response
    {
        return $this->render('shop/product_notification.html.twig');
    }
}