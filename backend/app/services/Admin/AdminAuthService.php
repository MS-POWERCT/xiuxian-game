<?php

namespace app\services\Admin;

use app\support\AdminAudit;
use app\support\AdminAuth;
use app\support\Db;
use support\Request;
use Webman\Http\Response;

/**
 * 后台管理员认证：登录、会话、退出和敏感操作二次确认。
 */
class AdminAuthService
{
    private const MAX_LOGIN_FAILURES = 5;
    private const LOGIN_LOCK_SECONDS = 15 * 60;
    private const MAX_SENSITIVE_FAILURES = 5;
    private const SENSITIVE_LOCK_SECONDS = 15 * 60;
    private const PASSWORD_MIN_LENGTH = 12;
    private const PASSWORD_MAX_LENGTH = 200;

    public function csrf(Request $request): Response
    {
        $token = AdminAuth::csrfToken($request);
        if ($token === '') {
            $token = AdminAuth::newCsrfToken();
        }
        $response = $this->ok(['csrf_token' => $token]);
        return AdminAuth::setCsrfCookie($request, $response, $token);
    }

    public function login(Request $request): Response
    {
        $username = strtolower(trim((string)$request->input('username', '')));
        $password = (string)$request->input('password', '');

        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,31}$/', $username)
            || strlen($password) < self::PASSWORD_MIN_LENGTH
            || strlen($password) > self::PASSWORD_MAX_LENGTH
        ) {
            AdminAudit::login(null, $username, false, $request);
            return $this->fail(6002, '账号或密码错误');
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin === false) {
            password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            AdminAudit::login(null, $username, false, $request);
            return $this->fail(6002, '账号或密码错误');
        }

        $adminId = (int)$admin['id'];
        $now = time();
        $lockedUntil = (int)($admin['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            AdminAudit::login($adminId, $username, false, $request);
            return $this->fail(6003, '登录失败次数过多，请稍后重试');
        }

        if ((int)$admin['is_enabled'] !== 1 || !password_verify($password, (string)$admin['password_hash'])) {
            $failures = (int)$admin['failed_login_count'] + 1;
            $lockUntil = $failures >= self::MAX_LOGIN_FAILURES ? $now + self::LOGIN_LOCK_SECONDS : null;
            $stmt = $pdo->prepare(
                'UPDATE admin_users SET failed_login_count = ?, locked_until = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute([$failures, $lockUntil, $now, $adminId]);
            AdminAudit::login($adminId, $username, false, $request);
            return $this->fail(6002, '账号或密码错误');
        }

        $token = AdminAuth::issueToken($adminId);
        $csrf = AdminAuth::newCsrfToken();
        $stmt = $pdo->prepare(
            'UPDATE admin_users
             SET failed_login_count = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$now, AdminAudit::ip($request), $now, $adminId]);
        AdminAudit::login($adminId, $username, true, $request);

        $response = $this->ok([
            'admin' => ['id' => $adminId, 'username' => (string)$admin['username']],
            'csrf_token' => $csrf,
        ]);
        return AdminAuth::setSessionCookies($request, $response, $token, $csrf);
    }

    public function me(Request $request): Response
    {
        $admin = AdminAuth::user($request);
        if ($admin === null) {
            return $this->fail(6001, '未登录或登录已失效');
        }

        $csrf = AdminAuth::csrfToken($request);
        if ($csrf === '') {
            $csrf = AdminAuth::newCsrfToken();
        }
        $response = $this->ok([
            'admin' => ['id' => (int)$admin['id'], 'username' => (string)$admin['username']],
            'csrf_token' => $csrf,
        ]);
        return AdminAuth::setCsrfCookie($request, $response, $csrf);
    }

    public function logout(Request $request): Response
    {
        $admin = AdminAuth::user($request);
        if ($admin === null) {
            return $this->fail(6001, '未登录或登录已失效');
        }
        AdminAuth::revokeToken((int)$admin['id']);
        AdminAudit::operation(
            (int)$admin['id'],
            'logout',
            'admin',
            (string)$admin['id'],
            '退出登录',
            [],
            $request
        );
        return AdminAuth::clearSessionCookies($request, $this->ok());
    }

    /**
     * 校验敏感操作密码。成功返回 null，失败返回统一错误响应。
     */
    public function confirmSensitivePassword(Request $request, array $admin, string $password): ?Response
    {
        $now = time();
        $lockedUntil = (int)($admin['sensitive_locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return $this->fail(6007, '敏感操作已锁定，请稍后重试');
        }

        if (!password_verify($password, (string)$admin['password_hash'])) {
            $failures = (int)$admin['sensitive_fail_count'] + 1;
            $lockUntil = $failures >= self::MAX_SENSITIVE_FAILURES
                ? $now + self::SENSITIVE_LOCK_SECONDS
                : null;
            $stmt = Db::pdo()->prepare(
                'UPDATE admin_users SET sensitive_fail_count = ?, sensitive_locked_until = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute([$failures, $lockUntil, $now, (int)$admin['id']]);
            return $this->fail(6006, '管理员密码错误');
        }

        $stmt = Db::pdo()->prepare(
            'UPDATE admin_users SET sensitive_fail_count = 0, sensitive_locked_until = NULL, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, (int)$admin['id']]);
        return null;
    }

    private function ok(array $data = []): Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }
}
