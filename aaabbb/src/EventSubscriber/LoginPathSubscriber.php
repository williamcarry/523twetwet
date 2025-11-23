<?php

namespace App\EventSubscriber;

use App\common\configs;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LoginPathSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $pathInfo = $request->getPathInfo();
        
        // 获取配置的路径
        $adminLoginPath = configs::$configs['admin_login_path'] ?? '';
        $supplierLoginPath = configs::$configs['supplier_login_path'] ?? '';
        
        // 检查是否是验证码路径，如果是则不做处理
        if ($pathInfo === '/captcha') {
            return;
        }
        
        // 检查是否是主页
        if ($pathInfo === '/') {
            // 让请求继续传递给控制器处理
            return;
        }
        
        // 检查是否访问管理员登录路径
        if ($pathInfo === '/' . $adminLoginPath . '/login') {
            // 访问了正确的管理员登录路径，不做处理
            return;
        }
        
        // 检查是否访问供应商登录路径
        if ($pathInfo === '/' . $supplierLoginPath . '/login') {
            // 访问了正确的供应商登录路径，不做处理
            return;
        }
        
        // 检查是否访问管理员根路径
        if ($pathInfo === '/' . $adminLoginPath) {
            // 访问了正确的管理员根路径，不做处理
            return;
        }
        
        // 检查是否访问供应商根路径
        if ($pathInfo === '/' . $supplierLoginPath) {
            // 访问了正确的供应商根路径，不做处理
            return;
        }
        
        // 检查是否访问管理员相关路径（但不是登录路径）
        // 使用更严格的检查，确保完全匹配路径前缀
        if ($pathInfo === '/' . $adminLoginPath || 
            (strpos($pathInfo, '/' . $adminLoginPath . '/') === 0 && strlen($pathInfo) > strlen('/' . $adminLoginPath . '/'))) {
            // 访问了正确的管理员路径前缀
            // 检查用户是否已登录且具有管理员角色
            // 这个检查将在控制器中进行，这里不做处理
            return;
        }
        
        // 检查是否访问供应商相关路径（但不是登录路径）
        // 使用更严格的检查，确保完全匹配路径前缀
        if ($pathInfo === '/' . $supplierLoginPath || 
            (strpos($pathInfo, '/' . $supplierLoginPath . '/') === 0 && strlen($pathInfo) > strlen('/' . $supplierLoginPath . '/'))) {
            // 访问了正确的供应商路径前缀
            // 检查用户是否已登录且具有供应商角色
            // 这个检查将在控制器中进行，这里不做处理
            return;
        }
        
        // 对于所有其他路径，不再显示404页面，让Symfony的默认处理机制来处理
        // 这将允许Symfony显示标准的404页面或根据配置进行其他处理
        return;
    }
}