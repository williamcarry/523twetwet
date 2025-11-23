<?php

namespace App\Controller;

use App\Service\PathConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\Request;

class SecurityController extends AbstractController
{
    #[Route('/admin{adminLoginPath}/login', name: 'app_login_admin', requirements: ['adminLoginPath' => '[a-zA-Z0-9]+'])]
    public function adminLogin(AuthenticationUtils $authenticationUtils, string $adminLoginPath = null): Response
    {
        // 添加调试信息
        error_log("ADMIN LOGIN FUNCTION CALLED with path: " . $adminLoginPath);
        
        // 检查用户是否已经登录
        if ($this->isGranted('ROLE_ADMIN')) {
            // 如果已经登录，重定向到管理员仪表板
            return $this->redirectToRoute('admin_dashboard', ['adminLoginPath' => PathConfigService::getAdminLoginPath()]);
        }
        
        // 验证路径是否正确
        if ($adminLoginPath !== PathConfigService::getAdminLoginPath()) {
            throw $this->createNotFoundException('Invalid admin login path');
        }
        
        // 获取登录错误信息（如果有的话）
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // 获取最后输入的用户名（如果有的话）
        $lastUsername = $authenticationUtils->getLastUsername();
        
        // 确定登录页面标题
        $loginTitle = '后台管理';

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'login_title' => $loginTitle,
        ]);
    }
    
    #[Route('/supplier{supplierLoginPath}/login', name: 'app_login_supplier', requirements: ['supplierLoginPath' => '[a-zA-Z0-9]+'])]
    public function supplierLogin(AuthenticationUtils $authenticationUtils, string $supplierLoginPath = null): Response
    {
        // 添加调试信息
        error_log("SUPPLIER LOGIN FUNCTION CALLED with path: " . $supplierLoginPath);
        
        // 检查用户是否已经登录
        if ($this->isGranted('ROLE_SUPPLIER')) {
            // 如果已经登录，重定向到供应商仪表板
            return $this->redirectToRoute('supplier_dashboard', ['supplierLoginPath' => PathConfigService::getSupplierLoginPath()]);
        }
        
        // 验证路径是否正确
        if ($supplierLoginPath !== PathConfigService::getSupplierLoginPath()) {
            throw $this->createNotFoundException('Invalid supplier login path');
        }
        
        // 获取登录错误信息（如果有的话）
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // 获取最后输入的用户名（如果有的话）
        $lastUsername = $authenticationUtils->getLastUsername();
        
        // 确定登录页面标题 - 硬编码为供应商后台
        $loginTitle = '供应商后台';

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'login_title' => $loginTitle,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(Request $request): Response
    {
        // 这个方法不会被直接调用，因为Symfony会拦截/logout请求
        // 但我们可以在这里处理一些自定义逻辑
        
        
        // 手动清除会话和认证信息
        $session = $request->getSession();
        if ($session) {
            // 清除所有与安全相关的会话数据
            $session->invalidate();
            $session->clear();
        }
        
        // 清除可能存在的安全令牌
        $token = $this->container->get('security.token_storage')->getToken();
        if ($token) {
            $this->container->get('security.token_storage')->setToken(null);
        }
        
        // 通过Referer头判断用户类型
        $referer = $request->headers->get('referer');
        
        if ($referer) {
            // 检查是否来自管理员页面 - 使用精确的路径匹配
            $adminPath = '/admin' . PathConfigService::getAdminLoginPath();
            if (strpos($referer, $adminPath) !== false) {
                error_log("Redirecting admin user to login page");
                return $this->redirectToRoute('app_login_admin', ['adminLoginPath' => PathConfigService::getAdminLoginPath()]);
            }
            
            // 检查是否来自供应商页面 - 使用精确的路径匹配
            $supplierPath = '/supplier' . PathConfigService::getSupplierLoginPath();
            if (strpos($referer, $supplierPath) !== false) {
                error_log("Redirecting supplier user to login page");
                return $this->redirectToRoute('app_login_supplier', ['supplierLoginPath' => PathConfigService::getSupplierLoginPath()]);
            }
        }
        
        // 默认重定向到主页
        error_log("Redirecting to homepage");
        return $this->redirectToRoute('app_homepage');
    }
    
    // 新增路由处理管理员特定路径前缀的访问
    #[Route('/admin{adminLoginPath}/', name: 'admin_path_redirect', requirements: ['adminLoginPath' => '[a-zA-Z0-9]+'])]
    #[Route('/admin{adminLoginPath}', name: 'admin_path_redirect_no_slash', requirements: ['adminLoginPath' => '[a-zA-Z0-9]+'])]
    public function adminPathRedirect(string $adminLoginPath): Response
    {
        // 添加调试信息
        error_log("ADMIN PATH REDIRECT CALLED with path: " . $adminLoginPath);
        
        // 验证路径是否正确
        if ($adminLoginPath !== PathConfigService::getAdminLoginPath()) {
            throw $this->createNotFoundException('Invalid admin path');
        }
        
        // 检查用户是否已登录且具有管理员角色
        if ($this->isGranted('ROLE_ADMIN')) {
            // 已登录，重定向到管理员仪表板
            return $this->redirectToRoute('admin_dashboard', ['adminLoginPath' => $adminLoginPath]);
        } else {
            // 未登录，重定向到管理员登录页面
            return $this->redirectToRoute('app_login_admin', ['adminLoginPath' => $adminLoginPath]);
        }
    }
    
    // 新增路由处理供应商特定路径前缀的访问
    #[Route('/supplier{supplierLoginPath}/', name: 'supplier_path_redirect', requirements: ['supplierLoginPath' => '[a-zA-Z0-9]+'])]
    #[Route('/supplier{supplierLoginPath}', name: 'supplier_path_redirect_no_slash', requirements: ['supplierLoginPath' => '[a-zA-Z0-9]+'])]
    public function supplierPathRedirect(string $supplierLoginPath): Response
    {
        // 添加调试信息
        error_log("SUPPLIER PATH REDIRECT CALLED with path: " . $supplierLoginPath);
        error_log("User granted ROLE_SUPPLIER: " . ($this->isGranted('ROLE_SUPPLIER') ? 'yes' : 'no'));
        
        // 验证路径是否正确
        if ($supplierLoginPath !== PathConfigService::getSupplierLoginPath()) {
            throw $this->createNotFoundException('Invalid supplier path');
        }
        
        // 检查用户是否已登录且具有供应商角色
        if ($this->isGranted('ROLE_SUPPLIER')) {
            // 已登录，重定向到供应商仪表板
            return $this->redirectToRoute('supplier_dashboard', ['supplierLoginPath' => $supplierLoginPath]);
        } else {
            // 未登录，重定向到供应商登录页面
            return $this->redirectToRoute('app_login_supplier', ['supplierLoginPath' => $supplierLoginPath]);
        }
    }
}