<?php

/**
 * phpredis 扩展类型桩
 * 仅供 IDE 静态分析识别 \Redis 类型与方法，运行时由 C 扩展提供，此文件不会被自动加载。
 */

class Redis
{
    /** @return bool */
    public function connect(string $host, int $port = 6379, float $timeout = 0.0) {}

    /** @return bool */
    public function auth(string $password) {}

    /** @return bool */
    public function select(int $db) {}

    /** @return string|false */
    public function hGet(string $key, string $field) {}

    /** @return int|false */
    public function hIncrBy(string $key, string $field, int $value) {}

    /** @return bool */
    public function expire(string $key, int $seconds) {}
}
