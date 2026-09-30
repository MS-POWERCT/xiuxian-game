<?php

namespace app\middleware;

use app\support\AdminAuth;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 后台接口安全保护：CSRF 校验、禁止缓存、限制嵌入。
 */
class AdminSecurity implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $cookie = (string)$request->cookie(AdminAuth::CSRF_COOKIE, '');
            $header = (string)$request->header('X-CSRF-Token', '');
            if ($cookie === '' || $header === '' || !hash_equals($cookie, $header)) {
                return $this->secure(json(['code' => 6004, 'msg' => 'CSRF 校验失败', 'data' => (object)[]]));
            }
        }

        return $this->secure($next($request));
    }

    private function secure(Response $response): Response
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
