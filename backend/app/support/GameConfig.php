<?php

namespace app\support;

/**
 * 游戏数值配置加载器
 *
 * 数值来源唯一：仓库根目录 config/*.json（前后端共用）。
 * 每次请求按文件 mtime 判断是否重载，改 JSON 后无需重启即生效。
 */
class GameConfig
{
    private static array $cache = [];
    private static array $mtime = [];

    private static function dir(): string
    {
        return dirname(base_path()) . '/config';
    }

    public static function get(string $name): array
    {
        $file = static::dir() . '/' . $name . '.json';
        $mtime = filemtime($file);
        if (!isset(static::$cache[$name]) || static::$mtime[$name] !== $mtime) {
            static::$cache[$name] = json_decode((string)file_get_contents($file), true);
            static::$mtime[$name] = $mtime;
        }
        return static::$cache[$name];
    }
}