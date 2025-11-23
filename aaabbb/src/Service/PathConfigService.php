<?php

namespace App\Service;

class PathConfigService
{
    public static function getAdminLoginPath(): string
    {
        return $_ENV['ADMIN_LOGIN_PATH'];
    }

    public static function getSupplierLoginPath(): string
    {
        return $_ENV['SUPPLIER_LOGIN_PATH'];
    }
}