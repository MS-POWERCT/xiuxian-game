<?php

namespace app\services;

use app\support\Auth;
use app\support\Db;
use app\support\GameConfig;
use support\Request;

/**
 * 游历 / 事件服务：达到筑基开放，按模式等待产出事件，事件进入槽位供玩家处理。
 * 数值来自 config/travel.json（模式 / 槽位）与 config/travel_events.json（事件库），
 * 事件处理产出庇护对象、灵石或储物戒物品。
 */
class TravelService
{
    private function ok(array $data = []): \Webman\Http\Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): \Webman\Http\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }

    // ==================== 接口层 ====================

    // GET /api/travel：懒结算到期游历、清理过期事件，返回进行中游历 / 槽位 / 事件列表
    public function status(Request $request): \Webman\Http\Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $playerId = (int)(new GameService())->playerRowForUser((int)$user['id'])['id'];
        $this->settleFinishedTravels($playerId);
        $this->expireEvents($playerId);
        return $this->ok($this->stateDto($playerId));
    }

    // POST /api/travel/start：开始一次游历
    public function start(Request $request): \Webman\Http\Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $game = new GameService();
        $row = $game->playerRowForUser((int)$user['id']);
        if ($res = $game->guardUnlock($row, 'travel')) {
            return $res;
        }
        // 冥想中不能外出游历（闭关中允许）
        if ($res = $game->guard($row, 'travel')) {
            return $res;
        }
        $playerId = (int)$row['id'];

        $modeId = (string)$request->input('mode_id', '');
        $mode = $this->findMode($modeId);
        if ($mode === null) {
            return $this->fail(4012, '游历模式不存在');
        }

        $this->settleFinishedTravels($playerId);
        $this->expireEvents($playerId);
        if ($this->activeTravel($playerId) !== null) {
            return $this->fail(4009, '已有游历进行中');
        }
        if ($this->pendingEventCount($playerId) >= $this->maxSlots()) {
            return $this->fail(4008, '事件槽位已满');
        }

        $now = time();
        $finishAt = $now + (int)$mode['duration_minutes'] * 60;
        $stmt = Db::pdo()->prepare(
            'INSERT INTO travels (player_id, mode_id, finish_at, status, created_at) VALUES (?, ?, ?, 0, ?)'
        );
        $stmt->execute([$playerId, $modeId, $finishAt, $now]);

        return $this->ok([
            'travel_id' => (int)Db::pdo()->lastInsertId(),
            'mode_id' => $modeId,
            'mode_name' => $mode['name'],
            'start_at' => $now,
            'finish_at' => $finishAt,
        ]);
    }

    // POST /api/travel/events/{id}/resolve：处理事件，产出庇护对象或灵石
    public function resolve(Request $request, int $id): \Webman\Http\Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $row = (new GameService())->playerRowForUser((int)$user['id']);
        $playerId = (int)$row['id'];

        $event = $this->eventRow($playerId, $id);
        if ($event === null || (int)$event['status'] !== 0) {
            return $this->fail(4011, '事件不存在或已失效');
        }
        if ((int)$event['expire_at'] <= time()) {
            $this->markEvent($id, 3);
            return $this->fail(4011, '事件不存在或已失效');
        }
        $cfg = $this->findEvent((string)$event['event_id']);
        if ($cfg === null) {
            return $this->fail(4011, '事件不存在或已失效');
        }

        $type = (string)$cfg['type'];
        $reward = (array)($cfg['reward'] ?? []);
        $patron = null;
        $stones = null;
        $items = null;

        if ($type === 'patron_mortal') {
            $max = (int)GameConfig::get('patron')['mortal']['max_count'];
            if ($this->mortalCount($playerId) >= $max) {
                return $this->fail(4007, '凡人庇护已达上限');
            }
            $this->addMortal($playerId, (int)($reward['patron_mortal'] ?? 1));
            $patron = ['kind' => 'mortal', 'count' => $this->mortalCount($playerId)];
        } elseif ($type === 'patron_sect') {
            $level = (string)($reward['patron_sect'] ?? '');
            $levels = GameConfig::get('patron')['sect']['levels'];
            if (!isset($levels[$level])) {
                return $this->fail(1000, '事件奖励配置无效');
            }
            if ($this->sectCount($playerId) >= $this->sectLimit((string)$row['realm_id'])) {
                return $this->fail(4006, '宗门庇护名额已满');
            }
            $this->addSect($playerId, $level);
            $patron = ['kind' => 'sect', 'sect_level' => $level, 'sect_name' => $levels[$level]['name']];
        } elseif ($type === 'resource') {
            // 纯资源事件：产出储物戒物品或一次性灵石
            if (!empty($reward['item']) && is_array($reward['item'])) {
                $items = $this->grantItem($playerId, (array)$reward['item']);
            } else {
                $stones = $this->grantStones($playerId, (array)($reward['stones'] ?? []));
            }
        }

        $this->markEvent($id, 1);
        return $this->ok([
            'event_id' => $event['event_id'],
            'type' => $type,
            'patron' => $patron,
            'stones' => $stones,
            'items' => $items,
        ]);
    }

    // POST /api/travel/events/{id}/abandon：放弃事件，腾出槽位
    public function abandon(Request $request, int $id): \Webman\Http\Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $playerId = (int)(new GameService())->playerRowForUser((int)$user['id'])['id'];
        $event = $this->eventRow($playerId, $id);
        if ($event === null || (int)$event['status'] !== 0) {
            return $this->fail(4011, '事件不存在或已失效');
        }
        $this->markEvent($id, 2);
        return $this->ok(['event_id' => $event['event_id']]);
    }

    // ==================== 内部工具 ====================

    // 游历状态 DTO：进行中游历 + 事件槽列表
    private function stateDto(int $playerId): array
    {
        $events = [];
        foreach ($this->pendingEvents($playerId) as $e) {
            $cfg = $this->findEvent((string)$e['event_id']);
            $events[] = [
                'id' => (int)$e['id'],
                'event_id' => $e['event_id'],
                'name' => $cfg['name'] ?? $e['event_id'],
                'desc' => $cfg['desc'] ?? '',
                'quality' => $e['quality'],
                'type' => $cfg['type'] ?? '',
                'created_at' => (int)$e['created_at'],
                'expire_at' => (int)$e['expire_at'],
            ];
        }
        $result = [
            'max_slots' => $this->maxSlots(),
            'events' => $events,
            'active_travel' => null,
        ];
        $travel = $this->activeTravel($playerId);
        if ($travel !== null) {
            $mode = $this->findMode((string)$travel['mode_id']);
            $result['active_travel'] = [
                'travel_id' => (int)$travel['id'],
                'mode_id' => $travel['mode_id'],
                'mode_name' => $mode['name'] ?? $travel['mode_id'],
                'start_at' => (int)$travel['created_at'],
                'finish_at' => (int)$travel['finish_at'],
            ];
        }
        return $result;
    }

    // 懒结算所有到期游历：按模式抽取事件写入槽位（离线同样累积）
    private function settleFinishedTravels(int $playerId): void
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM travels WHERE player_id=? AND status=0 AND finish_at<=? ORDER BY id ASC');
        $stmt->execute([$playerId, time()]);
        foreach ($stmt->fetchAll() as $travel) {
            $this->produceEvent($playerId, (string)$travel['mode_id']);
            $upd = Db::pdo()->prepare('UPDATE travels SET status=1 WHERE id=? AND status=0');
            $upd->execute([(int)$travel['id']]);
        }
    }

    private function produceEvent(int $playerId, string $modeId): void
    {
        $mode = $this->findMode($modeId);
        if ($mode === null) {
            return;
        }
        // 开始游历时已校验有空槽，正常流程下不会槽满
        if ($this->pendingEventCount($playerId) >= $this->maxSlots()) {
            return;
        }
        $quality = $this->pickQuality((array)$mode['event_weights']);
        if ($quality === null) {
            return;
        }
        $cfg = $this->pickEvent($quality);
        if ($cfg === null || ($cfg['type'] ?? '') === 'nothing') {
            return; // 无事发生：不占槽位
        }
        $now = time();
        $expireAt = $now + (int)GameConfig::get('travel')['event_expire_days'] * 86400;
        $stmt = Db::pdo()->prepare(
            'INSERT INTO player_events (player_id, event_id, quality, status, created_at, expire_at) VALUES (?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([$playerId, $cfg['id'], $cfg['quality'], $now, $expireAt]);
    }

    // 按品质权重抽取本次事件品质
    private function pickQuality(array $weights): ?string
    {
        $total = 0;
        foreach ($weights as $w) {
            $total += (int)$w;
        }
        if ($total <= 0) {
            return null;
        }
        $roll = mt_rand(1, $total);
        foreach ($weights as $quality => $w) {
            $roll -= (int)$w;
            if ($roll <= 0) {
                return (string)$quality;
            }
        }
        return null;
    }

    // 在指定品质内按 weight 抽取事件（weight 缺省为 1，即等权）
    private function pickEvent(string $quality): ?array
    {
        $pool = array_values(array_filter(
            GameConfig::get('travel_events')['events'] ?? [],
            fn($e) => ($e['quality'] ?? '') === $quality
        ));
        $total = 0;
        foreach ($pool as $e) {
            $total += max(1, (int)($e['weight'] ?? 1));
        }
        if ($total <= 0) {
            return null;
        }
        $roll = mt_rand(1, $total);
        foreach ($pool as $e) {
            $roll -= max(1, (int)($e['weight'] ?? 1));
            if ($roll <= 0) {
                return $e;
            }
        }
        return null;
    }

    private function findMode(string $modeId): ?array
    {
        foreach (GameConfig::get('travel')['modes'] as $mode) {
            if ($mode['id'] === $modeId) {
                return $mode;
            }
        }
        return null;
    }

    private function findEvent(string $eventId): ?array
    {
        foreach (GameConfig::get('travel_events')['events'] ?? [] as $event) {
            if ($event['id'] === $eventId) {
                return $event;
            }
        }
        return null;
    }

    private function maxSlots(): int
    {
        return (int)GameConfig::get('travel')['max_event_slots'];
    }

    private function activeTravel(int $playerId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM travels WHERE player_id=? AND status=0 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$playerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function pendingEvents(int $playerId): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM player_events WHERE player_id=? AND status=0 ORDER BY id ASC');
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    private function pendingEventCount(int $playerId): int
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM player_events WHERE player_id=? AND status=0');
        $stmt->execute([$playerId]);
        return (int)$stmt->fetchColumn();
    }

    private function expireEvents(int $playerId): void
    {
        $now = time();
        $stmt = Db::pdo()->prepare(
            'UPDATE player_events SET status=3, resolved_at=? WHERE player_id=? AND status=0 AND expire_at<=?'
        );
        $stmt->execute([$now, $playerId, $now]);
    }

    private function eventRow(int $playerId, int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM player_events WHERE id=? AND player_id=? LIMIT 1');
        $stmt->execute([$id, $playerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function markEvent(int $id, int $status): void
    {
        $stmt = Db::pdo()->prepare('UPDATE player_events SET status=?, resolved_at=? WHERE id=?');
        $stmt->execute([$status, time(), $id]);
    }

    private function mortalCount(int $playerId): int
    {
        $stmt = Db::pdo()->prepare("SELECT COALESCE(SUM(`count`),0) FROM player_patrons WHERE player_id=? AND kind='mortal'");
        $stmt->execute([$playerId]);
        return (int)$stmt->fetchColumn();
    }

    private function addMortal(int $playerId, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $now = time();
        $stmt = Db::pdo()->prepare("SELECT id FROM player_patrons WHERE player_id=? AND kind='mortal' ORDER BY id ASC LIMIT 1");
        $stmt->execute([$playerId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $upd = Db::pdo()->prepare('UPDATE player_patrons SET `count`=`count`+?, updated_at=? WHERE id=?');
            $upd->execute([$count, $now, (int)$id]);
            return;
        }
        $ins = Db::pdo()->prepare(
            "INSERT INTO player_patrons (player_id, kind, sect_level, `count`, last_supply_at, created_at, updated_at) VALUES (?, 'mortal', '', ?, ?, ?, ?)"
        );
        $ins->execute([$playerId, $count, $now, $now, $now]);
    }

    private function sectCount(int $playerId): int
    {
        $stmt = Db::pdo()->prepare("SELECT COUNT(*) FROM player_patrons WHERE player_id=? AND kind='sect'");
        $stmt->execute([$playerId]);
        return (int)$stmt->fetchColumn();
    }

    private function addSect(int $playerId, string $level): void
    {
        $now = time();
        $stmt = Db::pdo()->prepare(
            "INSERT INTO player_patrons (player_id, kind, sect_level, `count`, last_supply_at, created_at, updated_at) VALUES (?, 'sect', ?, 1, ?, ?, ?)"
        );
        $stmt->execute([$playerId, $level, $now, $now, $now]);
    }

    private function sectLimit(string $realmId): int
    {
        $map = GameConfig::get('patron')['sect']['max_count_by_realm'];
        return (int)($map[$realmId] ?? 0);
    }

    private function grantStones(int $playerId, array $reward): array
    {
        $stones = new SpiritStoneService();
        foreach ($reward as $level => $amount) {
            $stones->add($playerId, (string)$level, (int)$amount);
        }
        return $stones->get($playerId);
    }

    // 发放储物戒物品：类别取自 config/materials.json，未登记时默认 material
    private function grantItem(int $playerId, array $reward): array
    {
        $itemId = (string)($reward['id'] ?? '');
        $quantity = (int)($reward['quantity'] ?? 0);
        if ($itemId === '' || $quantity <= 0) {
            return [];
        }
        $category = 'material';
        foreach (GameConfig::get('materials')['materials'] ?? [] as $material) {
            if (($material['id'] ?? '') === $itemId) {
                $category = (string)($material['category'] ?? 'material');
                break;
            }
        }
        $items = new ItemService();
        $items->add($playerId, $itemId, $category, $quantity);
        foreach ($items->get($playerId) as $item) {
            if ($item['item_id'] === $itemId) {
                return [$item];
            }
        }
        return [];
    }
}
