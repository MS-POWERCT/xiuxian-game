<?php

namespace app\controller;

use app\services\SpiritStoneService;
use support\Request;

/**
 * 灵石控制器：查询与品级兑换（薄壳，业务逻辑在 app/services/SpiritStoneService.php）。
 */
class SpiritStoneController
{
    public function index(Request $request): \Webman\Http\Response
    {
        return (new SpiritStoneService())->index($request);
    }

    public function exchange(Request $request): \Webman\Http\Response
    {
        return (new SpiritStoneService())->exchange($request);
    }
}