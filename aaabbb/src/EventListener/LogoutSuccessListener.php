<?php

namespace App\EventListener;

use App\Service\PathConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Psr\Log\LoggerInterface;

class LogoutSuccessListener
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger
    ) {
    }

    public function onLogoutSuccess(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        
        // 记录日志
        $this->logger->info('Logout event triggered');
        
        // 获取用户的角色
        $token = $event->getToken();
        if ($token) {
            $user = $token->getUser();
            $this->logger->info('User found: ' . ($user ? get_class($user) : 'no'));
            
            if ($user) {
                // 获取用户的角色
                if (method_exists($user, 'getRoles')) {
                    $roles = $user->getRoles();
                } else {
                    // 如果用户对象没有getRoles方法，尝试从token获取
                    $roles = $token->getRoleNames();
                }
                
                $this->logger->info('User roles: ' . json_encode($roles));
                
                // 根据用户角色重定向到相应的登录页面
                if (in_array('ROLE_ADMIN', $roles)) {
                    $this->logger->info('Redirecting to admin login');
                    $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login_admin', [
                        'adminLoginPath' => PathConfigService::getAdminLoginPath()
                    ])));
                    return;
                }
                
                if (in_array('ROLE_SUPPLIER', $roles)) {
                    $this->logger->info('Redirecting to supplier login');
                    $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login_supplier', [
                        'supplierLoginPath' => PathConfigService::getSupplierLoginPath()
                    ])));
                    return;
                }
            }
        }

        // 默认重定向到主页
        $this->logger->info('Redirecting to homepage');
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_homepage')));
    }
}