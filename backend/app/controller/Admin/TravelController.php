<?php

namespace app\controller\Admin;

use app\services\Admin\AdminTravelService;
use support\Request;

/**
 * 后台游历测试操作。
 */
class TravelController
{
    public function updateFinishAt(Request $request, int $id): \Webman\Http\Response
    {
        return (new AdminTravelService())->updateFinishAt($request, $id);
    }
}