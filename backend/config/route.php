<?php

use Webman\Route;

// 最小闭环接口（依据 docs/api.md）
Route::get('/api/player', [app\controller\GameController::class, 'player']); // 获取玩家信息
Route::post('/api/meditate', [app\controller\GameController::class, 'meditate']); // 开始冥想
Route::post('/api/meditate/claim', [app\controller\GameController::class, 'meditateClaim']); // 结算冥想
Route::post('/api/dazuo', [app\controller\GameController::class, 'dazuo']); // 悟道批次结算
Route::post('/api/retreat/start', [app\controller\GameController::class, 'retreatStart']); // 闭关
Route::post('/api/retreat/claim', [app\controller\GameController::class, 'retreatClaim']); // 结算收益
Route::post('/api/breakthrough', [app\controller\GameController::class, 'breakthrough']); // 渡劫突破

// 死亡转世传承（依据 docs/api.md）
Route::post('/api/reincarnate', [app\controller\GameController::class, 'reincarnate']); // 转世重修/主动兵解
Route::get('/api/reincarnate/records', [app\controller\GameController::class, 'reincarnateRecords']); // 前世档案

// 用户账号模块（依据 docs/api.md）
Route::post('/api/auth/register', [app\controller\AuthController::class, 'register']); // 邮箱注册
Route::post('/api/auth/login', [app\controller\AuthController::class, 'login']); // 邮箱登录
Route::post('/api/auth/bind-idcard', [app\controller\AuthController::class, 'bindIdcard']); // 绑定身份证

// 后台管理接口（认证与会话独立于玩家接口，CSRF 由 AdminSecurity 统一校验）
Route::group('/api/admin', function () {
    Route::get('/auth/csrf', [app\controller\Admin\AuthController::class, 'csrf']); // 获取后台 CSRF Token
    Route::post('/auth/login', [app\controller\Admin\AuthController::class, 'login']); // 管理员登录

    Route::group('', function () {
        Route::get('/auth/me', [app\controller\Admin\AuthController::class, 'me']); // 当前管理员
        Route::post('/auth/logout', [app\controller\Admin\AuthController::class, 'logout']); // 退出登录
        Route::get('/dashboard', [app\controller\Admin\DashboardController::class, 'index']); // 后台概览

        Route::get('/configs', [app\controller\Admin\ConfigController::class, 'index']); // 配置列表
        Route::get('/configs/{name}', [app\controller\Admin\ConfigController::class, 'show']); // 配置详情
        Route::post('/configs/{name}', [app\controller\Admin\ConfigController::class, 'save']); // 保存配置
        Route::get('/configs/{name}/backups', [app\controller\Admin\ConfigController::class, 'backups']); // 配置备份
        Route::post('/configs/{name}/restore', [app\controller\Admin\ConfigController::class, 'restore']); // 恢复配置

        Route::get('/tables', [app\controller\Admin\TableController::class, 'index']); // 数据表列表
        Route::get('/tables/{table}', [app\controller\Admin\TableController::class, 'show']); // 数据表内容
        Route::post('/retreats/{id}/finish-at', [app\controller\Admin\RetreatController::class, 'updateFinishAt']); // 调整进行中闭关结束时间（测试工具）
    })->middleware(app\middleware\AdminAuth::class);
})->middleware(app\middleware\AdminSecurity::class);

// 关闭默认控制器路由，避免未定义路径被误解析
Route::disableDefaultRoute();
