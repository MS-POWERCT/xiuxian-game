<?php

namespace app\services;

use app\support\Db;
use app\support\Auth;
use app\support\GameConfig;
use support\Redis;
use support\Request;

/**
 * 核心玩法服务层：感悟 / 冥想 / 闭关 / 渡劫突破（严格依据 docs/api.md）
 * 所有数值来自 config/*.json，逻辑层不硬编码任何数值。
 */
class GameService
{
    private function ok(array $data = []): \Webman\Http\Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): \Webman\Http\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }

    // 动作 → 允许的玩家状态（集中定义，避免各接口各写一套互斥判断）
    private const ACTION_STATUS = [
        'dazuo' => ['idle', 'meditating', 'retreating', 'exploring'],
        'meditate' => ['idle'],
        'meditate_claim' => ['meditating'],
        'retreat' => ['idle'],
        'breakthrough' => ['idle'],
        'explore' => ['idle'],
        'reincarnate' => ['idle', 'dead'],
        'retreat_claim' => ['retreating'],
    ];

    // 状态互斥校验：状态允许则返回 null，否则返回错误响应
    private function guard(array $row, string $action): ?\Webman\Http\Response
    {
        $status = $row['status'] ?? 'idle';
        if (!in_array($status, self::ACTION_STATUS[$action], true)) {
            return $this->fail(1003, '当前状态不允许该操作');
        }
        return null;
    }

    // 功能解锁条件（config/unlock.json），未登记的功能返回 null（默认解锁）
    private function unlockCondition(string $featureId): ?array
    {
        $unlocks = GameConfig::get('unlock');
        return $unlocks[$featureId] ?? null;
    }

    // 判断玩家是否满足解锁条件（境界/层级），未登记视为已解锁
    private function isUnlocked(array $row, ?array $cond): bool
    {
        if ($cond === null) {
            return true;
        }
        $playerOrder = $this->unlockOrder((string)$row['realm_id']);
        $condOrder = $this->unlockOrder((string)$cond['realm_id']);
        if ($playerOrder > $condOrder) {
            return true;
        }
        if ($playerOrder < $condOrder) {
            return false;
        }
        // 同境界：unlock.json 的 stage 从 1 起（一层=1），玩家 stage_index 从 0 起
        return (int)$row['stage_index'] >= (int)$cond['stage'] - 1;
    }

    // 统一功能解锁校验：未解锁返回错误响应，否则返回 null
    private function guardUnlock(array $row, string $featureId): ?\Webman\Http\Response
    {
        $cond = $this->unlockCondition($featureId);
        if ($this->isUnlocked($row, $cond)) {
            return null;
        }
        $realm = $this->realmConfig((string)$cond['realm_id']);
        $stageName = $realm['stages'][(int)$cond['stage'] - 1] ?? (string)$cond['stage'];
        $msg = ($cond['name'] ?? $featureId) . '需' . $realm['name'] . $stageName . '解锁';
        return $this->fail(1004, $msg);
    }

    // ==================== 玩家 ====================

    public function player(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $row = $this->playerRow($userId);
        $retreat = $row['status'] === 'retreating' ? $this->activeRetreat((int)$row['id']) : null;
        $meditation = $this->activeMeditation($row);
        return $this->ok([
            'player' => $this->playerDto($row),
            'retreat' => $retreat ? $this->retreatDto($retreat) : null,
            'meditation' => $meditation ? $this->meditationDto($row) : null,
            'dazuo' => $this->dazuoState($row),
        ]);
    }

    public function meditate(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $meditationConfig = GameConfig::get('meditation');
        $defaultDuration = (int)($meditationConfig['meditation_durations'][0] ?? 180);
        $duration = (int)$request->input('duration', $defaultDuration);
        $med = $this->findMeditation($duration);
        if ($med === null) {
            return $this->fail(1000, '无效的冥想时长');
        }
        $row = $this->playerRow($userId);
        if ($res = $this->guard($row, 'meditate')) {
            return $res;
        }
        if ($res = $this->guardUnlock($row, (string)$med['id'])) {
            return $res;
        }
        $realm = $this->realmConfig($row['realm_id']);
        $ageRate = $this->cultivateRate((int)$row['age']);

        // 修为 = base_exp × 境界效率 × 年龄补偿 × 转世速度加成（时长差异已含在 base_exp 中）
        $meditationRatio = (float)($meditationConfig['meditation_exp_ratio'] ?? 1.0);
        $gained = (int)round(
            (float)$med['base_exp']
                * $meditationRatio
                * (float)$realm['cultivate_rate']
                * $ageRate
                * (1 + $this->speedBonus($row))
        );
        $now = time();
        $finishAt = $now + $duration;
        $stmt = Db::pdo()->prepare(
            'UPDATE players
             SET status=?, meditation_start_at=?, meditation_finish_at=?, meditation_duration=?, meditation_expected_exp=?, updated_at=?
             WHERE id=? AND status=?'
        );
        $stmt->execute([
            'meditating',
            $now,
            $finishAt,
            $duration,
            $gained,
            $now,
            (int)$row['id'],
            'idle',
        ]);
        if ($stmt->rowCount() !== 1) {
            return $this->fail(1003, '当前状态不允许该操作');
        }

        $row['status'] = 'meditating';
        $row['meditation_start_at'] = $now;
        $row['meditation_finish_at'] = $finishAt;
        $row['meditation_duration'] = $duration;
        $row['meditation_expected_exp'] = $gained;
        return $this->ok([
            'expected_exp' => $gained,
            'player' => $this->playerDto($row),
            'meditation' => $this->meditationDto($row),
        ]);
    }

    public function meditateClaim(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $row = $this->playerRow($userId);
        if ($res = $this->guard($row, 'meditate_claim')) {
            return $res;
        }
        if ((int)($row['meditation_finish_at'] ?? 0) > time()) {
            return $this->fail(3002, '冥想尚未结束');
        }

        $gained = (int)($row['meditation_expected_exp'] ?? 0);
        $this->addExp($row, $this->realmConfig($row['realm_id']), $gained);
        $stmt = Db::pdo()->prepare(
            'UPDATE players
             SET stage_index=?, exp=?, status=?, meditation_start_at=NULL, meditation_finish_at=NULL, meditation_duration=NULL, meditation_expected_exp=NULL, updated_at=?
             WHERE id=? AND status=?'
        );
        $stmt->execute([
            (int)$row['stage_index'],
            (int)$row['exp'],
            'idle',
            time(),
            (int)$row['id'],
            'meditating',
        ]);
        if ($stmt->rowCount() !== 1) {
            return $this->fail(1003, '当前状态不允许该操作');
        }

        $row['status'] = 'idle';
        $row['meditation_start_at'] = null;
        $row['meditation_finish_at'] = null;
        $row['meditation_duration'] = null;
        $row['meditation_expected_exp'] = null;
        return $this->ok(['gained_exp' => $gained, 'player' => $this->playerDto($row)]);
    }

    public function dazuo(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }

        $config = GameConfig::get('meditation');
        $batchSize = (int)$config['dazuo_batch_size'];
        $count = (int)$request->input('count', 0);
        if ($count !== $batchSize) {
            return $this->fail(1000, '感悟次数不足');
        }

        $row = $this->playerRow($userId);
        if ($res = $this->guard($row, 'dazuo')) {
            return $res;
        }

        $dailyLimit = $this->dazuoDailyLimit($row);
        $dailyKey = $this->dazuoDailyKey($row);
        $dailyUsed = $this->dazuoDailyUsed($row);
        if ($dailyLimit > 0 && $dailyUsed + $count > $dailyLimit) {
            return $this->fail(4003, '今日感悟次数已达上限');
        }

        $cooldownMs = (int)$config['dazuo_cooldown_ms'];
        $cooldownKey = 'xiuxian:dazuo:cooldown:' . (int)$row['id'] . ':' . (int)($row['life_no'] ?? 1);
        if ($cooldownMs > 0) {
            $acquired = Redis::connection()->client()->set($cooldownKey, '1', ['NX', 'PX' => $cooldownMs]);
            if (!$acquired) {
                return $this->fail(4004, '感悟尚未冷却');
            }
        }

        $dailyUsed = (int)Redis::incrBy($dailyKey, $count);
        Redis::expire($dailyKey, 2 * 86400);
        if ($dailyLimit > 0 && $dailyUsed > $dailyLimit) {
            Redis::decrBy($dailyKey, $count);
            if ($cooldownMs > 0) {
                Redis::del($cooldownKey);
            }
            return $this->fail(4003, '今日感悟次数已达上限');
        }

        $realm = $this->realmConfig($row['realm_id']);
        $unitExp = (float)$config['dazuo_base_exp']
            * (float)$realm['cultivate_rate']
            * $this->cultivateRate((int)$row['age'])
            * (1 + $this->speedBonus($row));
        $gained = (int)round($unitExp * $count);
        $this->addExp($row, $realm, $gained);
        $this->savePlayerRow($row);

        return $this->ok([
            'gained_exp' => $gained,
            'batch_size' => $batchSize,
            'daily_used' => $dailyUsed,
            'daily_limit' => $dailyLimit,
            'player' => $this->playerDto($row),
        ]);
    }

    public function retreatStart(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $row = $this->playerRow($userId);
        if ($res = $this->guard($row, 'retreat')) {
            return $res;
        }
        if ($res = $this->guardUnlock($row, 'retreat')) {
            return $res;
        }

        $techniqueId = (string)$request->input('technique_id', 'tuna');
        $formationId = (string)$request->input('formation_id', '');
        $pillIds = array_map('strval', (array)$request->input('pill_ids', []));
        $durationHours = (int)$request->input('duration_hours', 24);

        $technique = $this->byId(GameConfig::get('techniques')['techniques'], $techniqueId);
        if ($technique === null) {
            return $this->fail(1000, '功法不存在');
        }
        $formation = null;
        if ($formationId !== '') {
            $formation = $this->byId(GameConfig::get('formations')['formations'], $formationId);
            if ($formation === null) {
                return $this->fail(1000, '法阵不存在');
            }
        }
        $pillObjs = [];
        foreach ($pillIds as $pid) {
            $p = $this->byId(GameConfig::get('pills')['pills'], $pid);
            if ($p === null) {
                return $this->fail(1000, '丹药不存在：' . $pid);
            }
            $pillObjs[] = $p;
        }
        if (!$this->checkUnlock($row['realm_id'], $technique, $formation, $pillObjs)) {
            return $this->fail(1000, '境界不足，功法/法阵/丹药未解锁');
        }
        if (!$this->checkPillLimit($pillIds)) {
            return $this->fail(1000, '同种丹药数量超出单次闭关上限');
        }

        $retreatCfg = $this->findRetreat($durationHours);
        if ($retreatCfg === null) {
            return $this->fail(1000, '闭关时长无效');
        }

        // 成本 = 法阵 cost_per_use + 丹药 price 之和
        $cost = $formation ? (int)$formation['cost_per_use'] : 0;
        foreach ($pillObjs as $p) {
            $cost += (int)$p['price'];
        }
        if ((int)$row['spirit_stones'] < $cost) {
            return $this->fail(1001, '灵石不足');
        }

        // 关闭 buff：法阵加成 + 丹药加成，上限定为 retreat_buff_cap
        $buff = 0.0;
        if ($formation && $formation['effect_type'] === 'closing_buff') {
            $buff += (float)$formation['effect_value'];
        }
        foreach ($pillObjs as $p) {
            if ($p['effect_type'] === 'closing_buff') {
                $buff += (float)$p['effect_value'];
            }
        }
        $buff = min($buff, (float)GameConfig::get('meditation')['retreat_buff_cap']);

        $realm = $this->realmConfig($row['realm_id']);
        $expected = (int)round(
            (float)$retreatCfg['base_exp']
                * (float)$realm['cultivate_rate']
                * (float)$technique['closing_efficiency']
                * (1 + $buff)
                * (1 + $this->speedBonus($row))
        );

        $row['spirit_stones'] -= $cost;
        $row['status'] = 'retreating';
        $this->savePlayerRow($row);

        $finishAt = time() + $durationHours * 3600;
        $retreatId = $this->insertRetreat((int)$row['id'], $techniqueId, $formationId, $pillIds, $expected, $finishAt);

        return $this->ok([
            'retreat_id' => $retreatId,
            'finish_at' => $finishAt,
            'expected_exp' => $expected,
        ]);
    }

    public function retreatClaim(Request $request): \Webman\Http\Response
    {
        $retreatId = (int)$request->input('retreat_id', 0);
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $row = $this->playerRow($userId);
        if ($res = $this->guard($row, 'retreat_claim')) {
            return $res;
        }
        $retreat = $this->getRetreat($retreatId);
        if ($retreat === null || (int)$retreat['player_id'] !== (int)$row['id'] || (int)$retreat['status'] !== 0) {
            return $this->fail(1000, '闭关记录不存在');
        }
        $realm = $this->realmConfig($row['realm_id']);
        $gained = $this->retreatGainedExp($retreat);
        $this->addExp($row, $realm, $gained);
        $row['status'] = 'idle';
        $this->savePlayerRow($row);
        $this->markRetreatDone($retreatId);
        return $this->ok(['gained_exp' => $gained, 'player' => $this->playerDto($row)]);
    }

    public function breakthrough(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $row = $this->playerRow($userId);
        if ($res = $this->guard($row, 'breakthrough')) {
            return $res;
        }
        $realms = GameConfig::get('realms')['realms'];
        $idx = $this->realmIndex($row['realm_id']);
        $realm = $realms[$idx];
        $expToNext = $realm['exp_to_next'];
        $last = count($expToNext) - 1;

        // 渡劫前提：处于当前大境界最后一小阶段，且修为达到最后一项阈值
        if ((int)$row['stage_index'] !== $last || (int)$row['exp'] < $expToNext[$last]) {
            return $this->fail(3001, '修为不足，无法突破');
        }
        if (!isset($realms[$idx + 1])) {
            return $this->fail(1000, '已至当前大境界上限');
        }
        $toRealmId = $realms[$idx + 1]['id'];

        $bt = null;
        foreach (GameConfig::get('breakthrough')['stages'] as $s) {
            if ($s['to_realm'] === $toRealmId) {
                $bt = $s;
                break;
            }
        }
        if ($bt === null) {
            return $this->fail(1000, '无对应渡劫配置');
        }

        $roll = mt_rand() / mt_getrandmax();
        if ($roll < (float)$bt['fail_rate']) {
            $rollback = (int)round((int)$row['exp'] * (float)$bt['exp_rollback_ratio']);
            $hpLoss = (int)($bt['hp_loss'] ?? 0);
            $row['exp'] -= $rollback;
            $row['hp'] = max(0, (int)$row['hp'] - $hpLoss);
            $this->savePlayerRow($row);
            return $this->ok([
                'success' => false,
                'exp_rollback' => $rollback,
                'hp_loss' => $hpLoss,
                'player' => $this->playerDto($row),
            ]);
        }

        $toRealm = $realms[$idx + 1];
        $reward = (int)(GameConfig::get('economy')['breakthrough_reward'][$toRealmId] ?? 0);
        $row['realm_id'] = $toRealmId;
        $row['stage_index'] = 0;
        $row['exp'] = 0;
        $row['lifespan_max'] = $toRealm['lifespan_years'];
        $row['spirit_stones'] += $reward;
        $this->savePlayerRow($row);
        return $this->ok([
            'success' => true,
            'to_realm' => $toRealmId,
            'player' => $this->playerDto($row),
        ]);
    }

    // 今日转世次数校验：每个用户一个 hash，日期作为 field 计数，hash 整体 15 天后自动过期
    private function guardReincarnationLimit(int $userId): ?\Webman\Http\Response
    {
        $re = GameConfig::get('lifecycle')['reincarnation'];
        $limit = (int)($re['max_reincarnations_per_day'] ?? 0);
        if ($limit <= 0) {
            return null; // 0 或未配置 = 不限制
        }
        $field = date('Y-m-d');
        $key = 'xiuxian:reincarnate:' . $field;
        $count = (int)Redis::hGet($key, (string)$userId);
        if ($count >= $limit) {
            return $this->fail(4002, '今日转世次数已达上限');
        }
        Redis::hIncrBy($key, (string)$userId, 1);
        Redis::expire($key, 15 * 24 * 3600);
        return null;
    }

    // 转世重修 / 主动兵解：快照当前世 → 按配置继承传承 → 生成新角色
    public function reincarnate(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $row = $this->playerRow($userId);
        if ($res = $this->guard($row, 'reincarnate')) {
            return $res;
        }
        if ($res = $this->guardReincarnationLimit($userId)) {
            return $res;
        }

        $lifecycle = GameConfig::get('lifecycle');
        $re = $lifecycle['reincarnation'];

        // 死亡原因：活着调用 = 主动兵解（self）；死亡调用 = 寿元耗尽（lifespan，当前唯一死亡机制）
        $isDead = (int)$row['alive'] !== 1 || $row['status'] === 'dead';
        $deathReason = $isDead ? 'lifespan' : 'self';

        // 1) 快照当前世最终状态
        $this->snapshotLife($row, $deathReason);

        // 2) 传承：灵石按比例继承；速度加成由 life_no 递增后实时计算，不落地存储
        $inheritStones = (int)floor((int)$row['spirit_stones'] * (float)$re['inherit_spirit_stone_ratio']);

        // 3) 新角色：境界回练气初期、年龄回 restart_age、气血回满、继承灵石、life_no + 1
        $first = GameConfig::get('realms')['realms'][0];
        $newLifeNo = (int)$row['life_no'] + 1;
        $now = time();
        $stmt = Db::pdo()->prepare(
            'UPDATE players
             SET life_no=?, realm_id=?, stage_index=0, exp=0, age=?, lifespan_max=?, hp=?, spirit_stones=?, alive=1, status=?, meditation_start_at=NULL, meditation_finish_at=NULL, meditation_duration=NULL, meditation_expected_exp=NULL, created_at=?, updated_at=?
             WHERE id=?'
        );
        $stmt->execute([
            $newLifeNo,
            $first['id'],
            (int)$re['restart_age'],
            (int)$first['lifespan_years'],
            (int)$lifecycle['hp']['max'],
            $inheritStones,
            'idle',
            $now,
            $now,
            (int)$row['id'],
        ]);

        $row = $this->playerRow($userId);
        return $this->ok(['player' => $this->playerDto($row)]);
    }

    // 前世档案列表：按 life_no 倒序返回该用户所有前世
    public function reincarnateRecords(Request $request): \Webman\Http\Response
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM reincarnation_records WHERE user_id=? ORDER BY life_no DESC');
        $stmt->execute([$userId]);
        $records = [];
        foreach ($stmt->fetchAll() as $r) {
            $realm = $this->realmConfig((string)$r['realm_id']);
            $records[] = [
                'life_no' => (int)$r['life_no'],
                'name' => $r['name'],
                'realm_id' => $r['realm_id'],
                'realm_name' => $realm['name'],
                'stage_index' => (int)$r['stage_index'],
                'stage_name' => $realm['stages'][(int)$r['stage_index']] ?? '',
                'exp' => (int)$r['exp'],
                'age' => (int)$r['age'],
                'lifespan_max' => (int)$r['lifespan_max'],
                'hp' => (int)$r['hp'],
                'spirit_stones' => (int)$r['spirit_stones'],
                'cultivate_rate' => (float)$r['cultivate_rate'],
                'total_days' => (int)$r['total_days'],
                'death_reason' => $r['death_reason'],
                'death_at' => (int)$r['death_at'],
            ];
        }
        return $this->ok(['records' => $records]);
    }

    // ==================== 内部工具 ====================

    private function addExp(array &$row, array $realm, int $gained): void
    {
        $row['exp'] = (int)$row['exp'] + $gained;
        $expToNext = $realm['exp_to_next'];
        $last = count($expToNext) - 1;
        // 自动晋升小阶段，直到渡劫临界（最后一层不自动突破）
        while ((int)$row['stage_index'] < $last && (int)$row['exp'] >= $expToNext[(int)$row['stage_index']]) {
            $row['exp'] -= $expToNext[(int)$row['stage_index']];
            $row['stage_index'] = (int)$row['stage_index'] + 1;
        }
    }

    private function byId(array $list, string $id): ?array
    {
        foreach ($list as $it) {
            if ($it['id'] === $id) {
                return $it;
            }
        }
        return null;
    }

    private function findMeditation(int $duration): ?array
    {
        foreach (GameConfig::get('meditation')['meditations'] as $m) {
            if ((int)$m['duration_seconds'] === $duration) {
                return $m;
            }
        }
        return null;
    }

    private function findRetreat(int $durationHours): ?array
    {
        foreach (GameConfig::get('meditation')['retreats'] as $r) {
            if ((int)$r['duration_hours'] === $durationHours) {
                return $r;
            }
        }
        return null;
    }

    // 结算收益：到期取全额；提前出关按已过时间比例 × retreat_early_exit_ratio 折算
    private function retreatGainedExp(array $retreat): int
    {
        $expected = (int)$retreat['expected_exp'];
        $finishAt = (int)$retreat['finish_at'];
        if (time() >= $finishAt) {
            return $expected;
        }
        $startAt = (int)$retreat['created_at'];
        $total = $finishAt - $startAt;
        if ($total <= 0) {
            return 0;
        }
        $elapsed = max(0, time() - $startAt);
        $ratio = min(1.0, $elapsed / $total);
        $earlyRatio = (float)(GameConfig::get('meditation')['retreat_early_exit_ratio'] ?? 0.5);
        return (int)round($expected * $ratio * $earlyRatio);
    }

    private function realmIndex(string $realmId): int
    {
        foreach (GameConfig::get('realms')['realms'] as $i => $r) {
            if ($r['id'] === $realmId) {
                return $i;
            }
        }
        return 0;
    }

    private function realmConfig(string $realmId): array
    {
        return GameConfig::get('realms')['realms'][$this->realmIndex($realmId)];
    }

    private function cultivateRate(int $age): float
    {
        $preset = $this->agePreset(GameConfig::get('lifecycle'), $age);
        return (float)($preset['cultivate_rate'] ?? 1.0);
    }

    // 转世修炼速度加成：上一世突破的大境界次数 × cultivate_speed_bonus_per_realm，不超过 cap
    private function speedBonus(array $row): float
    {
        $re = GameConfig::get('lifecycle')['reincarnation'];
        $breakthroughs = $this->priorBreakthroughs((int)$row['user_id'], (int)$row['life_no']);
        return min($breakthroughs * (float)$re['cultivate_speed_bonus_per_realm'], (float)$re['cultivate_speed_bonus_cap']);
    }

    // 上一世（最近一条转世记录）突破的大境界次数：最终境界下标即该世突破次数，练气（0）不计
    private function priorBreakthroughs(int $userId, int $lifeNo): int
    {
        $stmt = Db::pdo()->prepare('SELECT realm_id FROM reincarnation_records WHERE user_id = ? AND life_no < ? ORDER BY life_no DESC LIMIT 1');
        $stmt->execute([$userId, $lifeNo]);
        $row = $stmt->fetch();
        return $row ? $this->realmIndex((string)$row['realm_id']) : 0;
    }

    private function agePreset(array $lifecycle, int $age): array
    {
        $preset = [];
        foreach ($lifecycle['age_preset'] as $p) {
            if ($age >= (int)$p['age']) {
                $preset = $p;
                continue;
            }
            break;
        }
        return $preset ?: ($lifecycle['age_preset'][0] ?? []);
    }

    private function unlockOrder(string $realmId): int
    {
        foreach (GameConfig::get('realms')['realms'] as $r) {
            if ($r['id'] === $realmId) {
                return (int)$r['order'];
            }
        }
        return 0;
    }

    // 校验功法/法阵/丹药的解锁境界（unlock_realm 为 null 表示不限）
    private function checkUnlock(string $realmId, ?array $technique, ?array $formation, array $pillObjs): bool
    {
        $order = $this->unlockOrder($realmId);
        $items = array_values(array_filter([$technique, $formation], fn($it) => $it !== null));
        foreach (array_merge($items, $pillObjs) as $it) {
            if (!empty($it['unlock_realm']) && $this->unlockOrder($it['unlock_realm']) > $order) {
                return false;
            }
        }
        return true;
    }

    // 校验同种丹药单次闭关数量上限（max_per_retreat，null 表示不限）
    private function checkPillLimit(array $pillIds): bool
    {
        $pills = GameConfig::get('pills')['pills'];
        $count = array_count_values($pillIds);
        foreach ($count as $pid => $num) {
            $p = $this->byId($pills, $pid);
            if ($p && $p['max_per_retreat'] !== null && $num > (int)$p['max_per_retreat']) {
                return false;
            }
        }
        return true;
    }

    // ==================== 数据读写 ====================

    // 登录态校验：未登录返回 null，否则返回当前用户 id
    private function authUserId(Request $request): ?int
    {
        $user = Auth::user($request);
        return $user === null ? null : (int)$user['id'];
    }

    // 取当前用户绑定的玩家，不存在则创建（一个账号一个角色）
    private function playerRow(int $userId): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM players WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return $this->createPlayer($userId);
        }
        return $this->settle($row);
    }

    private function createPlayer(int $userId): array
    {
        $realms = GameConfig::get('realms');
        $lifecycle = GameConfig::get('lifecycle');
        $realm = $realms['realms'][0];
        $stmt = Db::pdo()->prepare('SELECT realname_age FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $realnameAge = $stmt->fetchColumn();
        $age = $realnameAge !== false && $realnameAge !== null
            ? (int)$realnameAge
            : (int)($realms['start_age_years'] ?? 16);
        $preset = $this->agePreset($lifecycle, $age);
        $now = time();

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO players (user_id, life_no, name, realm_id, stage_index, exp, age, lifespan_max, hp, spirit_stones, alive, created_at, updated_at)
             VALUES (?, 1, ?, ?, 0, 0, ?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            $userId,
            '无名散修',
            $realm['id'],
            $age,
            (int)$realm['lifespan_years'],
            (int)$lifecycle['hp']['max'],
            (int)($preset['initial_spirit_stones'] ?? 0),
            $now,
            $now,
        ]);
        return $this->playerRow($userId);
    }

    private function savePlayerRow(array $row): void
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE players SET name=?, realm_id=?, stage_index=?, exp=?, age=?, lifespan_max=?, hp=?, spirit_stones=?, alive=?, status=?, updated_at=? WHERE id=?'
        );
        $stmt->execute([
            $row['name'],
            $row['realm_id'],
            (int)$row['stage_index'],
            (int)$row['exp'],
            (int)$row['age'],
            (int)$row['lifespan_max'],
            (int)$row['hp'],
            (int)$row['spirit_stones'],
            (int)$row['alive'],
            $row['status'],
            time(),
            (int)$row['id'],
        ]);
    }

    // 自然时间结算：按 real_day_to_game_year 把流逝真实时间换算成游戏年，累加年龄并在寿元耗尽时标记死亡
    private function settle(array $row): array
    {
        if ((int)$row['alive'] !== 1) {
            return $row;
        }
        $now = time();
        $elapsed = $now - (int)$row['updated_at'];
        if ($elapsed <= 0) {
            return $row;
        }
        $ratio = (float)GameConfig::get('realms')['time_ratio']['real_day_to_game_year'];
        $gameYears = $elapsed / 86400.0 * $ratio;
        if ($gameYears < 1.0) {
            return $row;
        }
        $whole = (int)floor($gameYears);
        $age = (int)$row['age'] + $whole;
        // 已结算的整年折算成真实秒数，从 updated_at 扣除，余数留给下次结算
        $updatedAt = (int)$row['updated_at'] + (int)floor($whole * 86400.0 / $ratio);
        $alive = (int)$row['alive'];
        $status = $row['status'];
        if ($age >= (int)$row['lifespan_max']) {
            $alive = 0;
            $status = 'dead';
        }
        $stmt = Db::pdo()->prepare('UPDATE players SET age=?, updated_at=?, alive=?, status=? WHERE id=?');
        $stmt->execute([$age, $updatedAt, $alive, $status, (int)$row['id']]);
        if ($status === 'dead') {
            $this->closeActiveActivities((int)$row['id']);
        }
        $row['age'] = $age;
        $row['updated_at'] = $updatedAt;
        $row['alive'] = $alive;
        $row['status'] = $status;
        return $row;
    }

    // 离线结算中死亡时，关闭未结束的玩法，避免转世后残留进行中状态
    private function closeActiveActivities(int $playerId): void
    {
        $stmt = Db::pdo()->prepare('UPDATE retreats SET status = 1 WHERE player_id = ? AND status = 0');
        $stmt->execute([$playerId]);
        $stmt = Db::pdo()->prepare(
            'UPDATE players
             SET meditation_start_at = NULL, meditation_finish_at = NULL, meditation_duration = NULL, meditation_expected_exp = NULL
             WHERE id = ?'
        );
        $stmt->execute([$playerId]);
    }

    // 把这一世最终状态快照写入 reincarnation_records
    private function snapshotLife(array $row, string $deathReason): void
    {
        $now = time();
        $createdAt = (int)($row['created_at'] ?? $now);
        $totalDays = ($createdAt > 0 && $now > $createdAt) ? (int)floor(($now - $createdAt) / 86400) : 0;
        $stmt = Db::pdo()->prepare(
            'INSERT INTO reincarnation_records
                (user_id, life_no, name, realm_id, stage_index, exp, age, lifespan_max, hp, spirit_stones, cultivate_rate, total_days, death_reason, death_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$row['user_id'],
            (int)$row['life_no'],
            $row['name'],
            $row['realm_id'],
            (int)$row['stage_index'],
            (int)$row['exp'],
            (int)$row['age'],
            (int)$row['lifespan_max'],
            (int)$row['hp'],
            (int)$row['spirit_stones'],
            $this->cultivateRate((int)$row['age']),
            $totalDays,
            $deathReason,
            $now,
            $createdAt,
        ]);
    }

    private function playerDto(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'life_no' => (int)($row['life_no'] ?? 1),
            'name' => $row['name'],
            'realm_id' => $row['realm_id'],
            'stage_index' => (int)$row['stage_index'],
            'exp' => (int)$row['exp'],
            'age' => (int)$row['age'],
            'lifespan_max' => (int)$row['lifespan_max'],
            'hp' => (int)$row['hp'],
            'spirit_stones' => (int)$row['spirit_stones'],
            'cultivate_rate' => $this->cultivateRate((int)$row['age']),
            'speed_bonus' => $this->speedBonus($row),
            'status' => $row['status'],
        ];
    }

    private function insertRetreat(int $playerId, string $techniqueId, string $formationId, array $pillIds, int $expected, int $finishAt): int
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO retreats (player_id, technique_id, formation_id, pill_ids, expected_exp, finish_at, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([
            $playerId,
            $techniqueId,
            $formationId,
            json_encode($pillIds, JSON_UNESCAPED_UNICODE),
            $expected,
            $finishAt,
            time(),
        ]);
        return (int)Db::pdo()->lastInsertId();
    }

    private function activeRetreat(int $playerId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM retreats WHERE player_id=? AND status=0 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$playerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function activeMeditation(array $row): bool
    {
        return ($row['status'] ?? '') === 'meditating' && !empty($row['meditation_finish_at']);
    }

    private function dazuoDailyKey(array $row): string
    {
        return 'xiuxian:dazuo:' . date('Y-m-d') . ':' . (int)$row['id'] . ':' . (int)($row['life_no'] ?? 1);
    }

    private function dazuoDailyUsed(array $row): int
    {
        return (int)Redis::get($this->dazuoDailyKey($row));
    }

    // 今日感悟次数上限：dazuo_daily_limit_base + (境界 order - 1) × dazuo_daily_limit_per_realm
    private function dazuoDailyLimit(array $row): int
    {
        $config = GameConfig::get('meditation');
        $base = (int)($config['dazuo_daily_limit_base'] ?? 0);
        $perRealm = (int)($config['dazuo_daily_limit_per_realm'] ?? 0);
        $order = $this->unlockOrder((string)$row['realm_id']);
        return $base + max(0, $order - 1) * $perRealm;
    }

    private function dazuoState(array $row): array
    {
        $config = GameConfig::get('meditation');
        return [
            'daily_used' => $this->dazuoDailyUsed($row),
            'daily_limit' => $this->dazuoDailyLimit($row),
            'batch_size' => (int)$config['dazuo_batch_size'],
        ];
    }

    private function getRetreat(int $retreatId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM retreats WHERE id=?');
        $stmt->execute([$retreatId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function markRetreatDone(int $retreatId): void
    {
        $stmt = Db::pdo()->prepare('UPDATE retreats SET status=1 WHERE id=?');
        $stmt->execute([$retreatId]);
    }

    private function retreatDto(array $retreat): array
    {
        return [
            'retreat_id' => (int)$retreat['id'],
            'start_at' => (int)$retreat['created_at'],
            'finish_at' => (int)$retreat['finish_at'],
            'expected_exp' => (int)$retreat['expected_exp'],
            'status' => (int)$retreat['status'],
        ];
    }

    private function meditationDto(array $row): array
    {
        return [
            'start_at' => (int)$row['meditation_start_at'],
            'finish_at' => (int)$row['meditation_finish_at'],
            'duration' => (int)$row['meditation_duration'],
            'expected_exp' => (int)$row['meditation_expected_exp'],
        ];
    }
}
