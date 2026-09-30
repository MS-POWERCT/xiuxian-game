<?php

namespace app\controller;

use app\services\GameService;
use support\Request;

/**
 * 核心玩法控制器：悟道 / 冥想 / 闭关 / 渡劫突破（薄壳，业务逻辑在 app/services/GameService.php）
 * 严格依据 docs/api.md。
 */
class GameController
{
    public function player(Request $request): \Webman\Http\Response
    {
        return (new GameService())->player($request);
    }

    public function meditate(Request $request): \Webman\Http\Response
    {
        return (new GameService())->meditate($request);
    }

    public function meditateClaim(Request $request): \Webman\Http\Response
    {
        return (new GameService())->meditateClaim($request);
    }

    public function dazuo(Request $request): \Webman\Http\Response
    {
        return (new GameService())->dazuo($request);
    }

    public function retreatStart(Request $request): \Webman\Http\Response
    {
        return (new GameService())->retreatStart($request);
    }

    public function retreatClaim(Request $request): \Webman\Http\Response
    {
        return (new GameService())->retreatClaim($request);
    }

    public function breakthrough(Request $request): \Webman\Http\Response
    {
        return (new GameService())->breakthrough($request);
    }

    public function reincarnate(Request $request): \Webman\Http\Response
    {
        return (new GameService())->reincarnate($request);
    }

    public function reincarnateRecords(Request $request): \Webman\Http\Response
    {
        return (new GameService())->reincarnateRecords($request);
    }
}
