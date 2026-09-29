<?php

use Webman\Route;

// 最小闭环接口（依据 docs/api.md）
Route::get('/api/player', [app\controller\GameController::class, 'player']); // 获取玩家信息
Route::post('/api/meditate', [app\controller\GameController::class, 'meditate']); // 开始冥想
Route::post('/api/meditate/claim', [app\controller\GameController::class, 'meditateClaim']); // 结算冥想
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

// 关闭默认控制器路由，避免未定义路径被误解析
Route::disableDefaultRoute();
