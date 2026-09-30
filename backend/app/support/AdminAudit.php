<?php

namespace app\support;

use support\Request;
use Throwable;

/**
 * 后台安全审计：登录事件和敏感操作写入独立日志表。
 */
class AdminAudit
{
    public static function login(?int $adminId, string $username, bool $success, Request $request): void
    {
        try {
            $stmt = Db::pdo()->prepare(
                'INSERT INTO admin_login_logs (admin_id, username, success, ip, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $adminId,
                self::limit($username, 32),
                $success ? 1 : 0,
                self::ip($request),
                self::userAgent($request),
                time(),
            ]);
        } catch (Throwable $e) {
            error_log('admin login audit failed: ' . $e->getMessage());
        }
    }

    public static function operation(
        int $adminId,
        string $action,
        string $targetType,
        string $targetId,
        string $reason,
        array $detail,
        Request $request
    ): void {
        try {
            $stmt = Db::pdo()->prepare(
                'INSERT INTO admin_operation_logs
                 (admin_id, action, target_type, target_id, reason, detail, ip, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $adminId,
                self::limit($action, 32),
                self::limit($targetType, 32),
                self::limit($targetId, 64),
                self::limit($reason, 255),
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::ip($request),
                self::userAgent($request),
                time(),
            ]);
        } catch (Throwable $e) {
            error_log('admin operation audit failed: ' . $e->getMessage());
        }
    }

    public static function ip(Request $request): string
    {
        $ip = $request->getRealIp();
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function userAgent(Request $request): string
    {
        return self::limit((string)$request->header('user-agent', ''), 255);
    }

    private static function limit(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }
        return strlen($value) > $length ? substr($value, 0, $length) : $value;
    }
}
