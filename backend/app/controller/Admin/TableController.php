<?php

namespace app\controller\Admin;

use app\services\Admin\AdminTableService;
use support\Request;

/**
 * 后台数据表只读浏览。
 */
class TableController
{
    public function index(Request $request): \Webman\Http\Response
    {
        return (new AdminTableService())->index($request);
    }

    public function show(Request $request, string $table): \Webman\Http\Response
    {
        return (new AdminTableService())->show($request, $table);
    }
}
