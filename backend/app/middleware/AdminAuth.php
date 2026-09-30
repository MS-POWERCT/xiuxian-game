<?php

namespace app\middleware;

use app\support\AdminAuth as AdminAuthSupport;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 后台登录态校验。仅校验认证，不负责 CSRF；CSRF 由 AdminSecurity 统一处理。
 */
class AdminAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if (AdminAuthSupport::user($request) === null) {
            return json(['code' => 6001, 'msg' => '未登录或登录已失效', 'data' => (object)[]]);
        }
        return $next($request);
    }
}
