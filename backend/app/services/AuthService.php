<?php

namespace app\services;

use app\support\Db;
use app\support\Auth;
use support\Request;

/**
 * 用户账号服务层：邮箱注册 / 邮箱登录 / 绑定身份证计算年龄（严格依据 docs/api.md）
 * 密码用 PHP 内置 password_hash（bcrypt）存储，绝不明文；身份证只存算出的年龄，不存原文。
 */
class AuthService
{
    private function ok(array $data = []): \Webman\Http\Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): \Webman\Http\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }

    // 邮箱注册
    public function register(Request $request): \Webman\Http\Response
    {
        $email = strtolower(trim((string)$request->input('email', '')));
        $password = (string)$request->input('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail(5001, '邮箱格式不正确');
        }
        if (strlen($password) < 8) {
            return $this->fail(5002, '密码长度至少 8 位');
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return $this->fail(5003, '邮箱已被注册');
        }

        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$email, password_hash($password, PASSWORD_BCRYPT), $now, $now]);

        return $this->ok(['id' => (int)$pdo->lastInsertId(), 'email' => $email]);
    }

    // 邮箱登录，返回 token 供后续接口使用
    public function login(Request $request): \Webman\Http\Response
    {
        $email = strtolower(trim((string)$request->input('email', '')));
        $password = (string)$request->input('password', '');

        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user === false || !password_verify($password, (string)$user['password_hash'])) {
            return $this->fail(5004, '邮箱或密码错误');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + Auth::TOKEN_TTL;
        $stmt = Db::pdo()->prepare('UPDATE users SET auth_token = ?, token_expires_at = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$token, $expiresAt, time(), (int)$user['id']]);

        return $this->ok([
            'token' => $token,
            'user' => $this->userDto($user),
        ]);
    }

    // 绑定身份证：校验合法性 → 计算周岁年龄 → 年龄 12-70 校验 → 入库（不存身份证号）
    public function bindIdcard(Request $request): \Webman\Http\Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }

        $idCard = strtoupper(trim((string)$request->input('id_card', '')));
        $age = $this->idCardAge($idCard);
        if ($age === null) {
            return $this->fail(5006, '身份证号不合法');
        }
        if ($age < 12 || $age > 70) {
            return $this->fail(5007, '年龄须在 12-70 岁之间');
        }

        $stmt = Db::pdo()->prepare(
            'UPDATE users SET realname_age = ?, realname_bound_at = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$age, time(), time(), (int)$user['id']]);

        return $this->ok(['age' => $age, 'realname_age' => $age]);
    }

    private function userDto(array $user): array
    {
        return [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'realname_age' => $user['realname_age'] !== null ? (int)$user['realname_age'] : null,
        ];
    }

    // 校验 18 位身份证（GB 11643-1999）并按生日计算周岁年龄；非法返回 null
    private function idCardAge(string $idCard): ?int
    {
        // 18 位，前 17 位数字，末位数字或 X
        if (!preg_match('/^\d{17}[0-9X]$/', $idCard)) {
            return null;
        }

        // 校验码
        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $codes = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int)$idCard[$i] * $weights[$i];
        }
        if ($codes[$sum % 11] !== $idCard[17]) {
            return null;
        }

        // 出生日期（YYYYMMDD）合法性
        $year = (int)substr($idCard, 6, 4);
        $month = (int)substr($idCard, 10, 2);
        $day = (int)substr($idCard, 12, 2);
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        // 周岁年龄：按今年生日是否已过判断
        $today = getdate();
        $age = $today['year'] - $year;
        if ($today['mon'] < $month || ($today['mon'] === $month && $today['mday'] < $day)) {
            $age--;
        }
        return $age;
    }
}
