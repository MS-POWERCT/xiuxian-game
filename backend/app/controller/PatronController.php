<?php

namespace app\controller;

use app\services\PatronService;
use support\Request;

/**
 * 庇护控制器：庇护列表、领取上供、解除宗门庇护（薄壳，业务逻辑在 app/services/PatronService.php）。
 */
class PatronController
{
    public function index(Request $request): \Webman\Http\Response
    {
        return (new PatronService())->index($request);
    }

    public function claim(Request $request): \Webman\Http\Response
    {
        return (new PatronService())->claim($request);
    }

    public function release(Request $request, int $id): \Webman\Http\Response
    {
        return (new PatronService())->release($request, $id);
    }
}