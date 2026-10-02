<?php

namespace app\services;

use app\support\Auth;
use app\support\Db;
use app\support\GameConfig;
use support\Request;

/**
 * 庇护服务：凡人（纯数字，有上限）与宗门（名额随大境界提升）。
 * 上供按周期累积、受 max_accumulate 限制，玩家手动领取；解除宗门消耗断缘令。
 * 数值全部来自 config/patron.json。
 */
class PatronService
{
    private function ok(array $data = []): \Webman\Http\Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): \Webman\Http\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }

    // GET /api/patrons：庇护列表 + 各对象待领取预览 + 合计
    public function index(Request $request): \Webman\Http\Response
    {
        $playerId = $this->playerId($request);
        if ($playerId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $patrons = [];
        $total = $this->zeroStones();
        foreach ($this->rows($playerId) as $row) {
            $info = $this->supplyInfo($row);
            if ($info === null) {
                continue;
            }
            foreach ($info['pending'] as $level => $amount) {
                $total[$level] += $amount;
            }
            unset($info['advance_to'], $info['per_capita_low'], $info['supply']);
            $patrons[] = $info;
        }
        return $this->ok([
            'patrons' => $patrons,
            'total_pending' => $total,
            'stones' => (new SpiritStoneService())->get($playerId),
        ]);
    }

    // POST /api/patrons/claim：领取所有已累积上供（受 max_accumulate 与灵石上限限制）
    public function claim(Request $request): \Webman\Http\Response
    {
        $playerId = $this->playerId($request);
        if ($playerId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $stones = new SpiritStoneService();
        $total = $this->zeroStones();
        $claimed = 0;
        foreach ($this->rows($playerId) as $row) {
            $info = $this->supplyInfo($row);
            if ($info === null) {
                continue;
            }
            if ($info['cycles'] > 0) {
                $stmt = Db::pdo()->prepare('UPDATE player_patrons SET last_supply_at=?, updated_at=? WHERE id=?');
                $stmt->execute([$info['advance_to'], time(), (int)$row['id']]);
            }
            foreach ($info['pending'] as $level => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $total[$level] += $amount;
                $claimed++;
                $stones->add($playerId, $level, $amount);
            }
        }
        return $this->ok([
            'claimed' => $claimed,
            'total' => $total,
            'stones' => $stones->get($playerId),
        ]);
    }

    // POST /api/patrons/{id}/release：解除宗门庇护，消耗断缘令
    public function release(Request $request, int $id): \Webman\Http\Response
    {
        $playerId = $this->playerId($request);
        if ($playerId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM player_patrons WHERE id=? AND player_id=? LIMIT 1');
        $stmt->execute([$id, $playerId]);
        $row = $stmt->fetch();
        if (!$row || $row['kind'] !== 'sect') {
            return $this->fail(1000, '庇护对象不存在');
        }

        $release = GameConfig::get('patron')['release'];
        $level = (string)$row['sect_level'];
        $cost = (int)($release['compensation_by_level'][$level] ?? 0);
        $itemId = (string)$release['item_id'];
        $items = new ItemService();
        if (!$items->has($playerId, $itemId, $cost)) {
            return $this->fail(4010, '断缘令不足');
        }
        $items->remove($playerId, $itemId, $cost);
        $del = Db::pdo()->prepare('DELETE FROM player_patrons WHERE id=?');
        $del->execute([(int)$row['id']]);

        return $this->ok([
            'id' => (int)$row['id'],
            'sect_level' => $level,
            'item_id' => $itemId,
            'cost' => $cost,
        ]);
    }

    // ==================== 内部工具 ====================

    private function playerId(Request $request): ?int
    {
        $user = Auth::user($request);
        if ($user === null) {
            return null;
        }
        return (int)(new GameService())->playerRowForUser((int)$user['id'])['id'];
    }

    private function rows(int $playerId): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM player_patrons WHERE player_id=? ORDER BY kind ASC, id ASC');
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    private function zeroStones(): array
    {
        $out = [];
        foreach (SpiritStoneService::levels() as $level) {
            $out[$level] = 0;
        }
        return $out;
    }

    // 某个庇护对象的结算规格（凡人 / 宗门）
    private function supplySpec(array $row): ?array
    {
        $patron = GameConfig::get('patron');
        if ($row['kind'] === 'mortal') {
            $m = $patron['mortal'];
            return [
                'name' => '凡人',
                'interval_hours' => (int)$m['supply_interval_hours'],
                'max_accumulate' => (int)$m['max_accumulate'],
                'per_capita_low' => (float)$m['supply_per_capita_low'],
                'supply' => null,
            ];
        }
        $cfg = $patron['sect']['levels'][(string)$row['sect_level']] ?? null;
        if ($cfg === null) {
            return null;
        }
        return [
            'name' => (string)$cfg['name'],
            'interval_hours' => (int)$cfg['supply_interval_hours'],
            'max_accumulate' => (int)$cfg['max_accumulate'],
            'per_capita_low' => 0.0,
            'supply' => (array)$cfg['supply'],
        ];
    }

    // 计算待领取上供：已过整周期数受 max_accumulate 限制
    private function supplyInfo(array $row): ?array
    {
        $spec = $this->supplySpec($row);
        if ($spec === null) {
            return null;
        }
        $interval = max(1, $spec['interval_hours'] * 3600);
        $lastAt = (int)$row['last_supply_at'];
        $cycles = intdiv(max(0, time() - $lastAt), $interval);
        $applied = min($cycles, $spec['max_accumulate']);
        return [
            'id' => (int)$row['id'],
            'kind' => $row['kind'],
            'sect_level' => $row['sect_level'],
            'sect_name' => $spec['name'],
            'count' => (int)$row['count'],
            'interval_hours' => $spec['interval_hours'],
            'max_accumulate' => $spec['max_accumulate'],
            'cycles' => $cycles,
            'applied_cycles' => $applied,
            'pending' => $this->pendingStones($row, $spec, $applied),
            'last_supply_at' => $lastAt,
            'next_supply_at' => $lastAt + $interval,
            'advance_to' => $lastAt + $cycles * $interval,
            'supply' => $spec['supply'],
            'per_capita_low' => $spec['per_capita_low'],
        ];
    }

    // 按已结算周期数计算产出（凡人向下取整，不足 1 舍去）
    private function pendingStones(array $row, array $spec, int $applied): array
    {
        $out = $this->zeroStones();
        if ($applied <= 0) {
            return $out;
        }
        if ($row['kind'] === 'mortal') {
            $out['low'] = (int)floor((int)$row['count'] * $spec['per_capita_low'] * $applied);
            return $out;
        }
        foreach ($spec['supply'] as $level => $amount) {
            if (isset($out[$level])) {
                $out[$level] = (int)$amount * $applied;
            }
        }
        return $out;
    }
}