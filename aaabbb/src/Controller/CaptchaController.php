<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CaptchaController extends AbstractController
{
    #[Route('/captcha', name: 'app_captcha')]
    public function index(Request $request): Response
    {
        // 生成随机验证码
        $captchaCode = $this->generateCaptchaCode(4);
        
        // 将验证码存储在session中
        $session = $request->getSession();
        $session->set('captcha_code', $captchaCode);
        
        // 创建验证码图片
        $image = $this->createCaptchaImage($captchaCode);
        
        // 返回图片响应
        ob_start();
        imagepng($image);
        $imageData = ob_get_contents();
        ob_end_clean();
        
        imagedestroy($image);
        
        $response = new Response($imageData, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
        
        // 确保响应不被缓存
        $response->headers->addCacheControlDirective('no-cache', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->headers->addCacheControlDirective('proxy-revalidate', true);
        
        return $response;
    }
    
    /**
     * 生成随机验证码
     */
    private function generateCaptchaCode(int $length = 4): string
    {
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $captchaCode = '';
        for ($i = 0; $i < $length; $i++) {
            $captchaCode .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $captchaCode;
    }
    
    /**
     * 创建验证码图片
     */
    private function createCaptchaImage(string $code): \GdImage
    {
        $width = 60;
        $height = 40;
        
        // 创建图片
        $image = imagecreate($width, $height);
        
        // 设置颜色
        $backgroundColor = imagecolorallocate($image, 240, 240, 240);
        $textColor = imagecolorallocate($image, 50, 50, 50);
        $lineColor = imagecolorallocate($image, 200, 200, 200);
        
        // 填充背景
        imagefilledrectangle($image, 0, 0, $width, $height, $backgroundColor);
        
        // 添加干扰线
        for ($i = 0; $i < 5; $i++) {
            imageline(
                $image,
                rand(0, $width),
                rand(0, $height),
                rand(0, $width),
                rand(0, $height),
                $lineColor
            );
        }
        
        // 添加验证码文字
        $fontSize = 5;
        $x = ($width - strlen($code) * imagefontwidth($fontSize)) / 2;
        $y = ($height - imagefontheight($fontSize)) / 2;
        
        imagestring($image, $fontSize, $x, $y, $code, $textColor);
        
        // 添加干扰点
        for ($i = 0; $i < 50; $i++) {
            imagesetpixel(
                $image,
                rand(0, $width),
                rand(0, $height),
                imagecolorallocate($image, rand(100, 200), rand(100, 200), rand(100, 200))
            );
        }
        
        return $image;
    }
}