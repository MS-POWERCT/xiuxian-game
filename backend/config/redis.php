<?php

// Redis 连接配置（Webman support\Redis + phpredis 扩展）——用于转世次数等需要过期时间的计数
// 可通过环境变量覆盖，未设置时使用下方默认值；部署时按需修改
return [
    'client' => 'phpredis',
    'default' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => (int)(getenv('REDIS_DATABASE') ?: 3),
        'prefix' => '',
    ],
];
