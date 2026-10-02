<?php

use Webman\Route;

// 最小闭环接口（依据 docs/api.md）
Route::get('/api/player', [app\controller\GameController::class, 'player']); // 获取玩家信息
Route::post('/api/meditate', [app\controller\GameController::class, 'meditate']); // 开始冥想
Route::post('/api/meditate/claim', [app\controller\GameController::class, 'meditateClaim']); // 结算冥想
Route::post('/api/dazuo', [app\controller\GameController::class, 'dazuo']); // 感悟批次结算
Route::post('/api/retreat/start', [app\controller\GameController::class, 'retreatStart']); // 闭关
Route::post('/api/retreat/claim', [app\controller\GameController::class, 'retreatClaim']); // 结算收益
Route::post('/api/breakthrough', [app\controller\GameController::class, 'breakthrough']); // 渡劫突破

// 死亡转世传承（依据 docs/api.md）
Route::post('/api/reincarnate', [app\controller\GameController::class, 'reincarnate']); // 转世重修/主动兵解
Route::get('/api/reincarnate/records', [app\controller\GameController::class, 'reincarnateRecords']); // 前世档案

// 灵石 / 商店 / 储物戒（依据 docs/灵石商店储物戒设计.md）
Route::get('/api/spirit-stones', [app\controller\SpiritStoneController::class, 'index']); // 灵石余额
Route::post('/api/spirit-stones/exchange', [app\controller\SpiritStoneController::class, 'exchange']); // 灵石品级兑换
Route::get('/api/shop', [app\controller\ShopController::class, 'index']); // 商店商品列表
Route::post('/api/shop/buy', [app\controller\ShopController::class, 'buy']); // 购买商品
Route::get('/api/items', [app\controller\ItemController::class, 'index']); // 储物戒物品列表

// 游历 / 事件 / 庇护（依据 docs/庇护游历事件系统设计.md）
Route::get('/api/travel', [app\controller\TravelController::class, 'status']); // 游历状态（含事件槽）
Route::post('/api/travel/start', [app\controller\TravelController::class, 'start']); // 开始游历
Route::post('/api/travel/events/{id}/resolve', [app\controller\TravelController::class, 'resolve']); // 处理事件
Route::post('/api/travel/events/{id}/abandon', [app\controller\TravelController::class, 'abandon']); // 放弃事件
Route::get('/api/patrons', [app\controller\PatronController::class, 'index']); // 庇护列表
Route::post('/api/patrons/claim', [app\controller\PatronController::class, 'claim']); // 领取上供
Route::post('/api/patrons/{id}/release', [app\controller\PatronController::class, 'release']); // 解除宗门庇护

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
        Route::post('/travels/{id}/finish-at', [app\controller\Admin\TravelController::class, 'updateFinishAt']); // 调整进行中游历归来时间（测试工具）
        Route::post('/players/{id}/spirit-stones', [app\controller\Admin\PlayerController::class, 'grantSpiritStones']); // 给玩家增加灵石
    })->middleware(app\middleware\AdminAuth::class);
})->middleware(app\middleware\AdminSecurity::class);

// 关闭默认控制器路由，避免未定义路径被误解析
Route::disableDefaultRoute();
