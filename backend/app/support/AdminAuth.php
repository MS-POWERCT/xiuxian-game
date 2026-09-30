<?php

namespace app\support;

use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 后台管理员会话：Cookie 只保存随机令牌，数据库只保存令牌哈希。
 */
class AdminAuth
{
    public const TOKEN_COOKIE = 'admin_token';
    public const CSRF_COOKIE = 'admin_csrf';
    public const TOKEN_TTL = 8 * 3600;

    // 解析当前管理员；令牌无效、过期或账号停用时返回 null
    public static function user(Request $request): ?array
    {
        $token = (string)$request->cookie(self::TOKEN_COOKIE, '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = Db::pdo()->prepare(
            'SELECT * FROM admin_users
             WHERE auth_token_hash = ? AND is_enabled = 1 AND auth_token_expires_at > ?
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), time()]);
        $admin = $stmt->fetch();
        return $admin ?: null;
    }

    public static function issueToken(int $adminId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = Db::pdo()->prepare(
            'UPDATE admin_users
             SET auth_token_hash = ?, auth_token_expires_at = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([hash('sha256', $token), time() + self::TOKEN_TTL, time(), $adminId]);
        return $token;
    }

    public static function revokeToken(int $adminId): void
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE admin_users
             SET auth_token_hash = NULL, auth_token_expires_at = NULL, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([time(), $adminId]);
    }

    public static function csrfToken(Request $request): string
    {
        $token = (string)$request->cookie(self::CSRF_COOKIE, '');
        return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
    }

    public static function newCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function setSessionCookies(Request $request, Response $response, string $token, string $csrf): Response
    {
        $secure = self::isSecure($request);
        $response->cookie(self::TOKEN_COOKIE, $token, self::TOKEN_TTL, '/', '', $secure, true, 'Strict');
        $response->cookie(self::CSRF_COOKIE, $csrf, self::TOKEN_TTL, '/', '', $secure, true, 'Strict');
        return $response;
    }

    public static function setCsrfCookie(Request $request, Response $response, string $csrf): Response
    {
        $response->cookie(
            self::CSRF_COOKIE,
            $csrf,
            self::TOKEN_TTL,
            '/',
            '',
            self::isSecure($request),
            true,
            'Strict'
        );
        return $response;
    }

    public static function clearSessionCookies(Request $request, Response $response): Response
    {
        $secure = self::isSecure($request);
        $response->cookie(self::TOKEN_COOKIE, '', 0, '/', '', $secure, true, 'Strict');
        $response->cookie(self::CSRF_COOKIE, '', 0, '/', '', $secure, true, 'Strict');
        return $response;
    }

    private static function isSecure(Request $request): bool
    {
        $proto = strtolower((string)$request->header('x-forwarded-proto', ''));
        if ($proto !== '' && strpos($proto, 'https') === 0) {
            return true;
        }
        return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    }
}
