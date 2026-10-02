<?php

namespace app\services\Admin;

use app\services\SpiritStoneService;
use app\support\AdminAudit;
use app\support\AdminAuth;
use app\support\Db;
use support\Request;
use Webman\Http\Response;

/**
 * 后台灵石发放：按品级给指定玩家增加灵石（受 economy.json 上限约束）。
 */
class AdminSpiritStoneService
{
    // 单个品级一次发放的上限，防止误填超大值
    private const MAX_GRANT_PER_LEVEL = 100000;

    public function grant(Request $request, int $playerId): Response
    {
        $admin = AdminAuth::user($request);
        if ($admin === null) {
            return $this->fail(6001, '未登录或登录已失效');
        }

        $password = (string)$request->input('password', '');
        $reason = trim((string)$request->input('reason', ''));
        if ($this->textLength($reason) < 3 || $this->textLength($reason) > 200) {
            return $this->fail(6014, '操作原因需为 3-200 字');
        }
        if ($error = (new AdminAuthService())->confirmSensitivePassword($request, $admin, $password)) {
            return $error;
        }

        $amounts = $request->input('amounts', []);
        $amounts = is_array($amounts) ? $amounts : [];
        $levels = SpiritStoneService::levels();
        $grant = [];
        $total = 0;
        foreach ($levels as $level) {
            $value = (int)($amounts[$level] ?? 0);
            if ($value < 0 || $value > self::MAX_GRANT_PER_LEVEL) {
                return $this->fail(6005, '灵石数量不正确');
            }
            $grant[$level] = $value;
            $total += $value;
        }
        if ($total <= 0) {
            return $this->fail(6005, '请至少填写一个品级的灵石数量');
        }

        $stmt = Db::pdo()->prepare('SELECT id, name FROM players WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();
        if ($player === false) {
            return $this->fail(6015, '玩家不存在');
        }

        $service = new SpiritStoneService();
        $before = $service->get($playerId);
        foreach ($levels as $level) {
            if ($grant[$level] > 0) {
                // 复用服务层的上限丢弃规则，保证与游戏内一致
                $service->add($playerId, $level, $grant[$level]);
            }
        }
        $after = $service->get($playerId);

        AdminAudit::operation(
            (int)$admin['id'],
            'player_spirit_stones',
            'player',
            (string)$playerId,
            $reason,
            [
                'player_name' => (string)$player['name'],
                'requested' => $grant,
                'before' => $before,
                'after' => $after,
            ],
            $request
        );

        return $this->ok([
            'player_id' => $playerId,
            'player_name' => (string)$player['name'],
            'requested' => $grant,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
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
