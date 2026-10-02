<?php

namespace app\services;

use app\support\Auth;
use app\support\Db;
use app\support\GameConfig;
use support\Request;

/**
 * 储物戒服务：只存放物品（丹药/法阵/功法/符箓/材料），不放灵石。
 * 物品展示名从 config/shop.json、config/pills.json 等配置读取，不硬编码。
 */
class ItemService
{
    private function ok(array $data = []): \Webman\Http\Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): \Webman\Http\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }

    // ==================== 数据层 ====================

    // 入账：同一 item_id 叠加数量
    public function add(int $playerId, string $itemId, string $category, int $quantity): void
    {
        if ($quantity <= 0 || $itemId === '') {
            return;
        }
        $now = time();
        $stmt = Db::pdo()->prepare(
            'INSERT INTO player_items (player_id, item_id, category, quantity, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + ?, updated_at = ?'
        );
        $stmt->execute([$playerId, $itemId, $category, $quantity, $now, $now, $quantity, $now]);
    }

    // 扣除：数量不足返回 false 且不修改数据
    public function remove(int $playerId, string $itemId, int $quantity): bool
    {
        if ($quantity <= 0) {
            return false;
        }
        $stmt = Db::pdo()->prepare(
            'UPDATE player_items SET quantity = quantity - ?, updated_at = ?
             WHERE player_id = ? AND item_id = ? AND quantity >= ?'
        );
        $stmt->execute([$quantity, time(), $playerId, $itemId, $quantity]);
        return $stmt->rowCount() === 1;
    }

    public function get(int $playerId, ?string $category = null): array
    {
        $sql = 'SELECT item_id, category, quantity, created_at, updated_at FROM player_items
                WHERE player_id = ? AND quantity > 0';
        $params = [$playerId];
        if ($category !== null) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'item_id' => $r['item_id'],
                'name' => $this->displayName((string)$r['item_id']),
                'category' => $r['category'],
                'quantity' => (int)$r['quantity'],
                'created_at' => (int)$r['created_at'],
                'updated_at' => (int)$r['updated_at'],
            ];
        }
        return $items;
    }

    public function has(int $playerId, string $itemId, int $quantity): bool
    {
        if ($quantity <= 0) {
            return true;
        }
        $stmt = Db::pdo()->prepare('SELECT quantity FROM player_items WHERE player_id = ? AND item_id = ?');
        $stmt->execute([$playerId, $itemId]);
        $qty = $stmt->fetchColumn();
        return $qty !== false && (int)$qty >= $quantity;
    }

    // 物品展示名：优先取商店商品名，其次取丹药名，都找不到时回退为 id
    private function displayName(string $itemId): string
    {
        foreach (GameConfig::get('shop')['items'] as $item) {
            if (($item['ref_id'] ?? '') === $itemId || ($item['id'] ?? '') === $itemId) {
                return (string)$item['name'];
            }
        }
        foreach (GameConfig::get('pills')['pills'] as $pill) {
            if ($pill['id'] === $itemId) {
                return (string)$pill['name'];
            }
        }
        foreach (GameConfig::get('materials')['materials'] ?? [] as $material) {
            if (($material['id'] ?? '') === $itemId) {
                return (string)($material['name'] ?? $itemId);
            }
        }
        return $itemId;
    }

    // ==================== 接口层 ====================

    public function index(Request $request): \Webman\Http\Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $playerId = (new GameService())->playerIdForUser((int)$user['id']);
        $category = $request->input('category');
        return $this->ok(['items' => $this->get($playerId, $category === null ? null : (string)$category)]);
    }
}