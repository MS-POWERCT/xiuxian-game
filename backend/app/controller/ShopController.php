<?php

namespace app\controller;

use app\services\ShopService;
use support\Request;

/**
 * 商店控制器：商品列表与购买（薄壳，业务逻辑在 app/services/ShopService.php）。
 */
class ShopController
{
    public function index(Request $request): \Webman\Http\Response
    {
        return (new ShopService())->index($request);
    }

    public function buy(Request $request): \Webman\Http\Response
    {
        return (new ShopService())->buy($request);
    }
}