<?php

namespace app\controller\Admin;

use app\services\Admin\AdminAuthService;
use support\Request;

/**
 * 后台认证入口。业务逻辑集中在 AdminAuthService。
 */
class AuthController
{
    public function csrf(Request $request): \Webman\Http\Response
    {
        return (new AdminAuthService())->csrf($request);
    }

    public function login(Request $request): \Webman\Http\Response
    {
        return (new AdminAuthService())->login($request);
    }

    public function me(Request $request): \Webman\Http\Response
    {
        return (new AdminAuthService())->me($request);
    }

    public function logout(Request $request): \Webman\Http\Response
    {
        return (new AdminAuthService())->logout($request);
    }
}
