<?php

namespace app\controller;

use app\services\AuthService;
use support\Request;

/**
 * 用户账号模块：邮箱注册 / 邮箱登录 / 绑定身份证计算年龄（薄壳，业务逻辑在 app/services/AuthService.php）
 * 严格依据 docs/api.md。
 */
class AuthController
{
    public function register(Request $request): \Webman\Http\Response
    {
        return (new AuthService())->register($request);
    }

    public function login(Request $request): \Webman\Http\Response
    {
        return (new AuthService())->login($request);
    }

    public function bindIdcard(Request $request): \Webman\Http\Response
    {
        return (new AuthService())->bindIdcard($request);
    }
}