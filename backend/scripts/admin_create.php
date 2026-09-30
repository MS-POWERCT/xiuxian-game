#!/usr/bin/env php
<?php

chdir(dirname(__DIR__));
require_once __DIR__ . '/../vendor/autoload.php';

support\App::loadAllConfig(['route']);
date_default_timezone_set((string)config('app.default_timezone', 'Asia/Shanghai'));

$username = strtolower(trim((string)(getenv('ADMIN_USERNAME') ?: ($argv[1] ?? 'admin'))));
if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,31}$/', $username)) {
    fwrite(STDERR, "用户名必须为 3-32 位小写字母、数字、下划线、点或短横线。\n");
    exit(1);
}

$password = (string)(getenv('ADMIN_PASSWORD') ?: '');
if ($password === '') {
    $password = readHiddenPassword();
}
if (strlen($password) < 12 || strlen($password) > 200) {
    fwrite(STDERR, "管理员密码长度必须为 12-200 位。\n");
    exit(1);
}

try {
    $pdo = app\support\Db::pdo();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    $now = time();

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE admin_users
             SET password_hash = ?, is_enabled = 1, failed_login_count = 0, locked_until = NULL,
                 sensitive_fail_count = 0, sensitive_locked_until = NULL, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$hash, $now, $id]);
        fwrite(STDOUT, "已更新管理员：{$username} (id={$id})\n");
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, is_enabled, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?)'
        );
        $stmt->execute([$username, $hash, $now, $now]);
        $id = (int)$pdo->lastInsertId();
        fwrite(STDOUT, "已创建管理员：{$username} (id={$id})\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, '创建/更新管理员失败：' . $e->getMessage() . "\n");
    exit(1);
}

function readHiddenPassword(): string
{
    if (!function_exists('shell_exec') || !function_exists('posix_isatty') || !posix_isatty(STDIN)) {
        $value = trim((string)readline('管理员密码（输入会回显）：'));
        echo "\n";
        return $value;
    }

    $stty = trim((string)shell_exec('stty -g 2>/dev/null'));
    if ($stty === '') {
        $value = trim((string)readline('管理员密码（输入会回显）：'));
        echo "\n";
        return $value;
    }

    shell_exec('stty -echo');
    $value = trim((string)fgets(STDIN));
    shell_exec('stty ' . escapeshellarg($stty));
    echo "\n";
    return $value;
}
