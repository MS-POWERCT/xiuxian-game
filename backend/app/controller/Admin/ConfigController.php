<?php

namespace app\controller\Admin;

use app\services\Admin\AdminConfigService;
use support\Request;

/**
 * 后台 JSON 配置管理。
 */
class ConfigController
{
    public function index(Request $request): \Webman\Http\Response
    {
        return (new AdminConfigService())->list($request);
    }

    public function show(Request $request, string $name): \Webman\Http\Response
    {
        return (new AdminConfigService())->show($request, $name);
    }

    public function save(Request $request, string $name): \Webman\Http\Response
    {
        return (new AdminConfigService())->save($request, $name);
    }

    public function backups(Request $request, string $name): \Webman\Http\Response
    {
        return (new AdminConfigService())->backups($request, $name);
    }

    public function restore(Request $request, string $name): \Webman\Http\Response
    {
        return (new AdminConfigService())->restore($request, $name);
    }
}
