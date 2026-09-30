<?php

namespace app\controller\Admin;

use app\services\Admin\AdminDashboardService;
use support\Request;

/**
 * 后台概览接口。
 */
class DashboardController
{
    public function index(Request $request): \Webman\Http\Response
    {
        return (new AdminDashboardService())->show($request);
    }
}
