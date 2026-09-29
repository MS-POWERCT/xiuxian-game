<?php

namespace app\support;

use support\Request;

/**
 * 登录态解析：从 Authorization: Bearer <token> 取当前用户，含 token 过期校验。
 */
class Auth
{
    // 登录令牌有效期（秒）
    public const TOKEN_TTL = 30 * 24 * 3600;

    // 未登录或 token 已过期返回 null
    public static function user(Request $request): ?array
    {
        $header = (string)$request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE auth_token = ?');
        $stmt->execute([$m[1]]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if ((int)$row['token_expires_at'] <= time()) {
            return null;
        }
        return $row;
    }
}