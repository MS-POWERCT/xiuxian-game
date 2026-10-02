<?php

namespace app\controller;

use app\services\TravelService;
use support\Request;

/**
 * 游历 / 事件控制器：游历状态、开始游历、处理/放弃事件（薄壳，业务逻辑在 app/services/TravelService.php）。
 */
class TravelController
{
    public function status(Request $request): \Webman\Http\Response
    {
        return (new TravelService())->status($request);
    }

    public function start(Request $request): \Webman\Http\Response
    {
        return (new TravelService())->start($request);
    }

    public function resolve(Request $request, int $id): \Webman\Http\Response
    {
        return (new TravelService())->resolve($request, $id);
    }

    public function abandon(Request $request, int $id): \Webman\Http\Response
    {
        return (new TravelService())->abandon($request, $id);
    }
}