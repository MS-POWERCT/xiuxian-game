<?php

namespace app\controller\Admin;

use app\services\Admin\AdminRetreatService;
use support\Request;

/**
 * 后台闭关测试操作。
 */
class RetreatController
{
    public function updateFinishAt(Request $request, int $id): \Webman\Http\Response
    {
        return (new AdminRetreatService())->updateFinishAt($request, $id);
    }
}
