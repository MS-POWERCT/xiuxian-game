<?php

namespace app\services;

use app\support\Auth;
use app\support\Db;
use app\support\GameConfig;
use support\Request;

/**
 * 灵石服务：四级灵石（下/中/上/极）的读写、上限丢弃与兑换。
 * 数值全部来自 config/economy.json，所有灵石变动必须走本服务，禁止业务代码直接改字段。
 */
class SpiritStoneService
{
    private function ok(array $data = []): \Webman\Http\Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): \Webman\Http\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }

    // 品级顺序（低 → 高）
    public static function levels(): array
    {
        return GameConfig::get('economy')['spirit_stone_levels'];
    }

    // 把数据库 JSON 字段解析为四级对象（缺失品级补 0）
    public static function parse($raw): array
    {
        $stones = [];
        foreach (self::levels() as $level) {
            $stones[$level] = 0;
        }
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (is_array($data)) {
            foreach ($stones as $level => $_) {
                $stones[$level] = max(0, (int)($data[$level] ?? 0));
            }
        }
        return $stones;
    }

    // 序列化为数据库 JSON 字段
    public static function encode(array $stones): string
    {
        $out = [];
        foreach (self::levels() as $level) {
            $out[$level] = max(0, (int)($stones[$level] ?? 0));
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    // ==================== 数据层 ====================

    public function get(int $playerId): array
    {
        $stmt = Db::pdo()->prepare('SELECT spirit_stones FROM players WHERE id = ?');
        $stmt->execute([$playerId]);
        $raw = $stmt->fetchColumn();
        return self::parse($raw === false ? null : $raw);
    }

    // 覆盖式写入（转世继承等重置场景）
    public function set(int $playerId, array $stones): array
    {
        $normalized = self::parse($stones);
        $stmt = Db::pdo()->prepare('UPDATE players SET spirit_stones = ? WHERE id = ?');
        $stmt->execute([self::encode($normalized), $playerId]);
        return $normalized;
    }

    // 增加灵石：超过该品级上限的部分直接丢弃，不入账
    public function add(int $playerId, string $level, int $amount): array
    {
        if ($amount <= 0 || !in_array($level, self::levels(), true)) {
            return $this->get($playerId);
        }
        $stones = $this->get($playerId);
        $stones[$level] = min($this->capOf($level), $stones[$level] + $amount);
        return $this->set($playerId, $stones);
    }

    // 消耗灵石：不足返回 false 且不修改数据
    public function spend(int $playerId, string $level, int $amount): bool
    {
        if (!in_array($level, self::levels(), true) || $amount < 0) {
            return false;
        }
        $stones = $this->get($playerId);
        if ($stones[$level] < $amount) {
            return false;
        }
        $stones[$level] -= $amount;
        $this->set($playerId, $stones);
        return true;
    }

    private function capOf(string $level): int
    {
        $caps = GameConfig::get('economy')['spirit_stone_caps'];
        return (int)($caps[$level] ?? PHP_INT_MAX);
    }

    // ==================== 接口层 ====================

    public function index(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $playerId = (new GameService())->playerIdForUser($userId);
        return $this->ok(['stones' => $this->get($playerId)]);
    }

    // 兑换：amount 恒为「高品级一侧」的数量；升级收手续费，降级免手续费
    public function exchange(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $levels = self::levels();
        $from = (string)$request->input('from_level', '');
        $to = (string)$request->input('to_level', '');
        $amount = (int)$request->input('amount', 0);
        $fromIdx = array_search($from, $levels, true);
        $toIdx = array_search($to, $levels, true);
        if ($fromIdx === false || $toIdx === false || $amount <= 0) {
            return $this->fail(1000, '兑换参数无效');
        }
        if (abs($fromIdx - $toIdx) !== 1) {
            return $this->fail(1000, '仅支持相邻品级兑换');
        }

        $playerId = (new GameService())->playerIdForUser($userId);
        $economy = GameConfig::get('economy');
        $ratio = (int)$economy['exchange_ratio'];
        $stones = $this->get($playerId);

        if ($fromIdx < $toIdx) {
            // 升级：本金与手续费均以源品级扣除，手续费额外消耗
            $feeRatio = (float)($economy['exchange_fee_ratio'][$levels[$fromIdx] . '_to_' . $levels[$toIdx]] ?? 0);
            $principal = $amount * $ratio;
            $fee = (int)ceil($principal * $feeRatio);
            if ($stones[$from] < $principal + $fee) {
                return $this->fail(1001, '灵石不足');
            }
            if ($stones[$to] + $amount > $this->capOf($to)) {
                return $this->fail(4005, '目标品级灵石已达上限');
            }
            $stones[$from] -= $principal + $fee;
            $stones[$to] += $amount;
            $this->set($playerId, $stones);
            return $this->ok(['stones' => $stones, 'cost' => $principal, 'fee' => $fee]);
        }

        // 降级：amount 为源品级数量，免手续费
        $gain = $amount * $ratio;
        if ($stones[$from] < $amount) {
            return $this->fail(1001, '灵石不足');
        }
        if ($stones[$to] + $gain > $this->capOf($to)) {
            return $this->fail(4005, '目标品级灵石已达上限');
        }
        $stones[$from] -= $amount;
        $stones[$to] += $gain;
        $this->set($playerId, $stones);
        return $this->ok(['stones' => $stones, 'cost' => 0, 'fee' => 0]);
    }

    private function authUserId(Request $request): ?int
    {
        $user = Auth::user($request);
        return $user === null ? null : (int)$user['id'];
    }
}
