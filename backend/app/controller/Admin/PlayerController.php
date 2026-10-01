<?php

namespace app\controller\Admin;

use app\services\Admin\AdminSpiritStoneService;
use support\Request;

/**
 * 后台玩家操作。
 */
class PlayerController
{
    public function grantSpiritStones(Request $request, int $id): \Webman\Http\Response
    {
        return (new AdminSpiritStoneService())->grant($request, $id);
    }
}