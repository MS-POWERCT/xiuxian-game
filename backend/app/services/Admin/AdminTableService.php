<?php

namespace app\services\Admin;

use app\support\Db;
use app\support\GameConfig;
use support\Request;
use Webman\Http\Response;

/**
 * 数据表只读浏览：固定白名单、参数绑定、分页和敏感列脱敏。
 */
class AdminTableService
{
    private const TABLES = [
        'users' => ['label' => '用户账号', 'group' => 'game'],
        'players' => ['label' => '玩家角色', 'group' => 'game'],
        'reincarnation_records' => ['label' => '前世档案', 'group' => 'game'],
        'retreats' => ['label' => '闭关记录', 'group' => 'game'],
        'player_items' => ['label' => '储物戒', 'group' => 'game'],
        'travels' => ['label' => '游历记录', 'group' => 'game'],
        'player_events' => ['label' => '游历事件', 'group' => 'game'],
        'player_patrons' => ['label' => '庇护对象', 'group' => 'game'],
        'admin_users' => ['label' => '管理员账号', 'group' => 'admin'],
        'admin_login_logs' => ['label' => '管理员登录日志', 'group' => 'admin'],
        'admin_operation_logs' => ['label' => '管理员操作日志', 'group' => 'admin'],
    ];

    private const MASKED_COLUMNS = [
        'users' => ['password_hash', 'auth_token'],
        'admin_users' => ['password_hash', 'auth_token_hash', 'auth_token_expires_at'],
    ];

    private const COLUMN_LABELS = [
        'id' => 'ID',
        'email' => '邮箱',
        'password_hash' => '密码哈希',
        'realname_age' => '实名年龄',
        'auth_token' => '登录令牌',
        'token_expires_at' => '登录有效期',
        'realname_bound_at' => '实名绑定时间',
        'user_id' => '用户ID',
        'life_no' => '第几世',
        'name' => '道号',
        'realm_id' => '境界ID',
        'stage_index' => '阶段序号',
        'exp' => '修为',
        'age' => '年龄',
        'lifespan_max' => '寿命上限',
        'hp' => '气血',
        'spirit_stones' => '灵石',
        'alive' => '是否存活',
        'status' => '状态',
        'meditation_start_at' => '冥想开始时间',
        'meditation_finish_at' => '冥想结束时间',
        'meditation_duration' => '冥想时长',
        'meditation_expected_exp' => '冥想预计收益',
        'created_at' => '创建时间',
        'updated_at' => '更新时间',
        'cultivate_rate' => '修炼效率',
        'total_days' => '游玩天数',
        'death_reason' => '死亡原因',
        'death_at' => '死亡时间',
        'player_id' => '玩家ID',
        'item_id' => '物品ID',
        'category' => '分类',
        'quantity' => '数量',
        'technique_id' => '功法ID',
        'formation_id' => '法阵ID',
        'pill_ids' => '丹药ID列表',
        'expected_exp' => '预计收益',
        'finish_at' => '结束时间',
        'mode_id' => '游历模式',
        'event_id' => '事件ID',
        'quality' => '品质',
        'expire_at' => '过期时间',
        'resolved_at' => '处理时间',
        'kind' => '庇护类型',
        'sect_level' => '宗门等级',
        'count' => '数量',
        'last_supply_at' => '上次结算时间',
        'username' => '管理员账号',
        'is_enabled' => '是否启用',
        'auth_token_hash' => '登录令牌哈希',
        'auth_token_expires_at' => '令牌过期时间',
        'failed_login_count' => '登录失败次数',
        'locked_until' => '登录锁定截止',
        'sensitive_fail_count' => '敏感操作失败次数',
        'sensitive_locked_until' => '敏感操作锁定截止',
        'last_login_at' => '最后登录时间',
        'last_login_ip' => '最后登录IP',
        'admin_id' => '管理员ID',
        'success' => '是否成功',
        'ip' => 'IP地址',
        'user_agent' => '用户代理',
        'action' => '操作类型',
        'target_type' => '目标类型',
        'target_id' => '目标ID',
        'reason' => '操作原因',
        'detail' => '操作详情',
    ];

    /**
     * 表.列 → 单元格值翻译来源
     * entity=按 id 查 config 中的名称；entity_multi=JSON 数组逐项翻译；
     * 其余为 labels.json enums 的分组名。
     */
    private const VALUE_LABEL_SOURCES = [
        'players' => ['realm_id' => 'entity', 'status' => 'player_status', 'alive' => 'bool'],
        'reincarnation_records' => ['realm_id' => 'entity', 'death_reason' => 'death_reason'],
        'retreats' => [
            'status' => 'record_status',
            'technique_id' => 'entity',
            'formation_id' => 'entity',
            'pill_ids' => 'entity_multi',
        ],
        'player_items' => ['item_id' => 'entity', 'category' => 'category'],
        'travels' => ['mode_id' => 'entity', 'status' => 'record_status'],
        'player_events' => ['event_id' => 'entity', 'quality' => 'quality', 'status' => 'event_status'],
        'player_patrons' => ['kind' => 'patron_kind', 'sect_level' => 'sect_level'],
        'admin_users' => ['is_enabled' => 'bool'],
        'admin_login_logs' => ['success' => 'bool'],
        'admin_operation_logs' => ['action' => 'admin_action', 'target_type' => 'target_type'],
    ];

    // 数字状态映射（不放 labels.json，避免 PHP 把数字键解码成列表）
    private const NUMERIC_LABELS = [
        'record_status' => [0 => '进行中', 1 => '已结算'],
        'event_status' => [0 => '待处理', 1 => '已完成', 2 => '已放弃', 3 => '已过期'],
        'bool' => [0 => '否', 1 => '是'],
    ];

    private static ?array $entityLabels = null;
    private static ?array $enumLabels = null;

    public function index(Request $request): Response
    {
        $tables = [];
        foreach (self::TABLES as $name => $meta) {
            $tables[] = ['name' => $name, 'label' => $meta['label'], 'group' => $meta['group']];
        }
        return $this->ok(['tables' => $tables]);
    }

    public function show(Request $request, string $table): Response
    {
        if (!array_key_exists($table, self::TABLES)) {
            return $this->fail(6013, '数据表不允许访问或不存在');
        }
        $columnMeta = $this->columnMeta($table);
        $columns = array_column($columnMeta, 'name');
        if ($columns === []) {
            return $this->fail(6013, '数据表不允许访问或不存在');
        }

        $page = max(1, min(1000000, (int)$request->input('page', 1)));
        $pageSize = max(10, min(50, (int)$request->input('page_size', 20)));
        $sort = (string)$request->input('sort', in_array('id', $columns, true) ? 'id' : $columns[0]);
        if (!in_array($sort, $columns, true)) {
            $sort = in_array('id', $columns, true) ? 'id' : $columns[0];
        }
        $order = strtolower((string)$request->input('order', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $keyword = trim((string)$request->input('keyword', ''));
        $keyword = function_exists('mb_substr') ? mb_substr($keyword, 0, 64) : substr($keyword, 0, 64);

        $where = '';
        $params = [];
        if ($keyword !== '') {
            $searchColumns = array_slice($this->searchableColumns($table), 0, 8);
            if ($searchColumns !== []) {
                $parts = [];
                foreach ($searchColumns as $column) {
                    $parts[] = 'CAST(`' . $column . '` AS CHAR) LIKE ?';
                    $params[] = '%' . $keyword . '%';
                }
                $where = ' WHERE (' . implode(' OR ', $parts) . ')';
            }
        }

        $pdo = Db::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '`' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $pageSize;

        $sql = 'SELECT * FROM `' . $table . '`' . $where
            . ' ORDER BY `' . $sort . '` ' . $order
            . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        $masked = self::MASKED_COLUMNS[$table] ?? [];
        foreach ($stmt->fetchAll() as $row) {
            foreach ($masked as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = '***';
                }
            }
            foreach ($row as $key => $value) {
                if (is_string($value) && !(function_exists('mb_check_encoding') ? mb_check_encoding($value, 'UTF-8') : preg_match('//u', $value))) {
                    $row[$key] = '[binary]';
                }
            }
            $rows[] = $row;
        }

        $labels = self::COLUMN_LABELS;
        return $this->ok([
            'table' => $table,
            'columns' => array_map(
                function (array $column) use ($masked, $labels, $table): array {
                    return [
                        'name' => $column['name'],
                        'label' => $labels[$column['name']] ?? $column['name'],
                        'type' => $column['type'],
                        'is_time' => self::isTimeColumn($column['name']),
                        'masked' => in_array($column['name'], $masked, true),
                        'multi' => (self::VALUE_LABEL_SOURCES[$table][$column['name']] ?? '') === 'entity_multi',
                        'value_labels' => $this->valueLabels($table, $column['name']),
                    ];
                },
                $columnMeta
            ),
            'rows' => $rows,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ]);
    }

    /**
     * @return array<int, array{name: string, type: string}>
     */
    private function columnMeta(string $table): array
    {
        $stmt = Db::pdo()->query('SHOW COLUMNS FROM `' . $table . '`');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = (string)$row['Field'];
            if (preg_match('/^[A-Za-z0-9_]+$/', $field)) {
                $columns[] = [
                    'name' => $field,
                    'type' => (string)$row['Type'],
                ];
            }
        }
        return $columns;
    }

    /**
     * @return string[]
     */
    private function columns(string $table): array
    {
        return array_column($this->columnMeta($table), 'name');
    }

    private static function isTimeColumn(string $name): bool
    {
        return (bool)preg_match('/_at$/', $name) || $name === 'server_time';
    }

    /**
     * @return string[]
     */
    private function searchableColumns(string $table): array
    {
        $stmt = Db::pdo()->query('SHOW COLUMNS FROM `' . $table . '`');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = (string)$row['Field'];
            $type = strtolower((string)$row['Type']);
            if (
                preg_match('/^[A-Za-z0-9_]+$/', $field)
                && preg_match('/char|text|enum|date|time/', $type)
            ) {
                $columns[] = $field;
            }
        }
        return $columns;
    }

    /**
     * 单元格值中文映射：返回「原值 → 中文」对象，无映射时返回 null。
     * 输出统一用 (object) 保证是 JSON 对象（数字键也不会退化成数组）。
     */
    private function valueLabels(string $table, string $column): ?object
    {
        $source = self::VALUE_LABEL_SOURCES[$table][$column] ?? '';
        if ($source === 'entity' || $source === 'entity_multi') {
            $map = $this->entityLabels();
        } elseif (isset(self::NUMERIC_LABELS[$source])) {
            $map = self::NUMERIC_LABELS[$source];
        } else {
            $map = $this->enumLabels()[$source] ?? [];
        }
        return $map === [] ? null : (object)$map;
    }

    // config 内所有「id → 名称」：境界、丹药、法阵、功法、商店、材料、游历模式与事件
    private function entityLabels(): array
    {
        if (self::$entityLabels !== null) {
            return self::$entityLabels;
        }
        $map = [];
        foreach (['realms' => 'realms', 'pills' => 'pills', 'formations' => 'formations', 'techniques' => 'techniques'] as $config => $key) {
            foreach (GameConfig::get($config)[$key] ?? [] as $row) {
                if (!empty($row['id'])) {
                    $map[(string)$row['id']] = (string)($row['name'] ?? $row['id']);
                }
            }
        }
        foreach (GameConfig::get('shop')['items'] ?? [] as $row) {
            if (empty($row['id'])) {
                continue;
            }
            $map[(string)$row['id']] = (string)($row['name'] ?? $row['id']);
            if (!empty($row['ref_id'])) {
                $map[(string)$row['ref_id']] = (string)($row['name'] ?? $row['ref_id']);
            }
        }
        foreach (GameConfig::get('travel')['modes'] ?? [] as $row) {
            if (!empty($row['id'])) {
                $map[(string)$row['id']] = (string)($row['name'] ?? $row['id']);
            }
        }
        foreach (GameConfig::get('travel_events')['events'] ?? [] as $row) {
            if (!empty($row['id'])) {
                $map[(string)$row['id']] = (string)($row['name'] ?? $row['id']);
            }
        }
        foreach (GameConfig::get('materials')['materials'] ?? [] as $row) {
            if (!empty($row['id'])) {
                $map[(string)$row['id']] = (string)($row['name'] ?? $row['id']);
            }
        }
        self::$entityLabels = $map;
        return $map;
    }

    // labels.json 的 enums 分组（字符串枚举）
    private function enumLabels(): array
    {
        if (self::$enumLabels !== null) {
            return self::$enumLabels;
        }
        $enums = GameConfig::get('labels')['enums'] ?? [];
        self::$enumLabels = is_array($enums) ? $enums : [];
        return self::$enumLabels;
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
