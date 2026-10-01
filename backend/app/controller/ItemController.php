<?php

namespace app\controller;

use app\services\ItemService;
use support\Request;

/**
 * 储物戒控制器：物品列表（薄壳，业务逻辑在 app/services/ItemService.php）。
 */
class ItemController
{
    public function index(Request $request): \Webman\Http\Response
    {
        return (new ItemService())->index($request);
    }
}