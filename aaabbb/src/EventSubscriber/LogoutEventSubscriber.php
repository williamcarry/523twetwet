<?php

namespace App\EventSubscriber;

use App\Service\PathConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class LogoutEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $token = $event->getToken();
        
        // 清除会话数据
        $this->clearSessionData($request);
        
        if (!$token) {
            // 如果没有token，重定向到主页
            $targetUrl = $this->urlGenerator->generate('app_homepage');
            $event->setResponse(new RedirectResponse($targetUrl));
            return;
        }
        
        $user = $token->getUser();
        if (!$user) {
            // 如果没有用户，重定向到主页
            $targetUrl = $this->urlGenerator->generate('app_homepage');
            $event->setResponse(new RedirectResponse($targetUrl));
            return;
        }
        
        // 检查用户角色并重定向到相应的登录页面
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            $targetUrl = $this->urlGenerator->generate('app_login_admin', [
                'adminLoginPath' => PathConfigService::getAdminLoginPath()
            ]);
            $event->setResponse(new RedirectResponse($targetUrl));
            return;
        }
        
        if (in_array('ROLE_SUPPLIER', $user->getRoles())) {
            $targetUrl = $this->urlGenerator->generate('app_login_supplier', [
                'supplierLoginPath' => PathConfigService::getSupplierLoginPath()
            ]);
            $event->setResponse(new RedirectResponse($targetUrl));
            return;
        }
        
        // 默认重定向到主页
        $targetUrl = $this->urlGenerator->generate('app_homepage');
        $event->setResponse(new RedirectResponse($targetUrl));
    }
    
    private function clearSessionData(Request $request): void
    {
        // 清除会话中的用户数据
        $session = $request->getSession();
        if ($session) {
            // 清除所有与认证相关的会话数据
            $session->remove('_security_admin_firewall');
            $session->remove('_security_supplier_firewall');
            $session->remove('_security.last_username');
            $session->remove('captcha_code');
            
            // 清除所有安全相关的会话键
            $sessionKeys = $session->all();
            foreach ($sessionKeys as $key => $value) {
                if (strpos($key, '_security') === 0) {
                    $session->remove($key);
                }
            }
            
            // 使会话完全无效
            $session->invalidate();
            $session->clear();
        }
    }
}