<?php

namespace app\services;

use app\support\Auth;
use app\support\GameConfig;
use support\Request;

/**
 * 商店服务：以对应品级灵石购买物品，成交后写入储物戒。
 * 商品与价格来自 config/shop.json；限购与上架解锁本期未启用（配置位保留）。
 */
class ShopService
{
    private function ok(array $data = []): \Webman\Http\Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): \Webman\Http\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }

    public function index(Request $request): \Webman\Http\Response
    {
        if (Auth::user($request) === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $list = [];
        foreach (GameConfig::get('shop')['items'] as $item) {
            $list[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'category' => $item['category'],
                'ref_id' => $item['ref_id'],
                'price' => (int)$item['price'],
                'currency_level' => $item['currency_level'],
            ];
        }
        return $this->ok(['items' => $list]);
    }

    public function buy(Request $request): \Webman\Http\Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            return $this->fail(5005, '未登录或登录已失效');
        }
        $itemId = (string)$request->input('item_id', '');
        $quantity = (int)$request->input('quantity', 1);
        if ($quantity < 1) {
            $quantity = 1;
        }

        $item = null;
        foreach (GameConfig::get('shop')['items'] as $it) {
            if ($it['id'] === $itemId) {
                $item = $it;
                break;
            }
        }
        if ($item === null) {
            return $this->fail(1000, '商品不存在');
        }

        $playerId = (new GameService())->playerIdForUser((int)$user['id']);
        $level = (string)$item['currency_level'];
        $total = (int)$item['price'] * $quantity;

        $stones = new SpiritStoneService();
        if (!$stones->spend($playerId, $level, $total)) {
            return $this->fail(1001, '灵石不足');
        }
        (new ItemService())->add($playerId, (string)$item['ref_id'], (string)$item['category'], $quantity);

        return $this->ok([
            'item_id' => $item['ref_id'],
            'name' => $item['name'],
            'quantity' => $quantity,
            'cost' => $total,
            'currency_level' => $level,
            'stones' => $stones->get($playerId),
        ]);
    }
}