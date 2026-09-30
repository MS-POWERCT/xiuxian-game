<?php

namespace app\services\Admin;

use app\support\Db;
use support\Request;
use Webman\Http\Response;

/**
 * 后台概览统计。
 */
class AdminDashboardService
{
    public function show(Request $request): Response
    {
        $pdo = Db::pdo();
        $configDir = dirname(base_path()) . DIRECTORY_SEPARATOR . 'config';

        return $this->ok([
            'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'players' => (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn(),
            'alive_players' => (int)$pdo->query('SELECT COUNT(*) FROM players WHERE alive = 1')->fetchColumn(),
            'retreating_players' => (int)$pdo->query("SELECT COUNT(*) FROM players WHERE status = 'retreating'")->fetchColumn(),
            'admin_users' => (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn(),
            'configs' => count(glob($configDir . DIRECTORY_SEPARATOR . '*.json') ?: []),
            'server_time' => time(),
        ]);
    }

    private function ok(array $data = []): Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }
}
