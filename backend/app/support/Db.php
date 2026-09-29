<?php

namespace app\support;

use PDO;

/**
 * 原生 PDO 访问 MySQL（不引入 ORM）
 * 常驻内存下复用连接，首次连接时自动建库建表（对应 docs/database.md 三张表）。
 */
class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (static::$pdo !== null) {
            return static::$pdo;
        }
        $c = config('database');

        // 先连不带库名的连接，确保数据库存在
        $tmp = new PDO(
            "mysql:host={$c['host']};port={$c['port']};charset={$c['charset']}",
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$c['database']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $tmp = null;

        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset={$c['charset']}",
            $c['username'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        static::$pdo = $pdo;
        static::createTables($pdo);
        return static::$pdo;
    }

    private static function createTables(PDO $pdo): void
    {
        $ddl = [
            "CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                realname_age TINYINT NULL,
                auth_token VARCHAR(64) NULL,
                token_expires_at INT UNSIGNED NULL,
                realname_bound_at INT UNSIGNED NULL,
                created_at INT UNSIGNED NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                UNIQUE KEY uk_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS players (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                life_no INT UNSIGNED NOT NULL DEFAULT 1,
                name VARCHAR(32) NOT NULL,
                realm_id VARCHAR(16) NOT NULL,
                stage_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
                exp INT UNSIGNED NOT NULL DEFAULT 0,
                age INT UNSIGNED NOT NULL,
                lifespan_max INT UNSIGNED NOT NULL,
                hp TINYINT UNSIGNED NOT NULL DEFAULT 100,
                spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
                alive TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(16) NOT NULL DEFAULT 'idle',
                meditation_start_at INT UNSIGNED NULL,
                meditation_finish_at INT UNSIGNED NULL,
                meditation_duration INT UNSIGNED NULL,
                meditation_expected_exp INT UNSIGNED NULL,
                created_at INT UNSIGNED NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                UNIQUE KEY uk_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS reincarnation_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                life_no INT UNSIGNED NOT NULL,
                name VARCHAR(32) NOT NULL,
                realm_id VARCHAR(16) NOT NULL,
                stage_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
                exp INT UNSIGNED NOT NULL DEFAULT 0,
                age INT UNSIGNED NOT NULL,
                lifespan_max INT UNSIGNED NOT NULL,
                hp TINYINT UNSIGNED NOT NULL DEFAULT 100,
                spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
                cultivate_rate FLOAT NOT NULL DEFAULT 1.0,
                total_days INT UNSIGNED NOT NULL DEFAULT 0,
                death_reason VARCHAR(16) NOT NULL,
                death_at INT UNSIGNED NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                UNIQUE KEY uk_user_life (user_id, life_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS retreats (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                player_id BIGINT UNSIGNED NOT NULL,
                technique_id VARCHAR(32) NOT NULL,
                formation_id VARCHAR(32) NOT NULL DEFAULT '',
                pill_ids JSON NULL,
                expected_exp INT UNSIGNED NOT NULL,
                finish_at INT UNSIGNED NOT NULL,
                status TINYINT NOT NULL DEFAULT 0,
                created_at INT UNSIGNED NOT NULL,
                KEY idx_player_status (player_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }

        // 兼容旧表：已存在的 players 表缺少新增列时补建（CREATE TABLE IF NOT EXISTS 不会修改已有表）
        static::ensureColumn($pdo, 'players', 'status', "VARCHAR(16) NOT NULL DEFAULT 'idle'");
        static::ensureColumn($pdo, 'players', 'life_no', "INT UNSIGNED NOT NULL DEFAULT 1");
        static::ensureColumn($pdo, 'players', 'meditation_start_at', 'INT UNSIGNED NULL');
        static::ensureColumn($pdo, 'players', 'meditation_finish_at', 'INT UNSIGNED NULL');
        static::ensureColumn($pdo, 'players', 'meditation_duration', 'INT UNSIGNED NULL');
        static::ensureColumn($pdo, 'players', 'meditation_expected_exp', 'INT UNSIGNED NULL');

        // 兼容旧 users 表：账号模块新增列缺失时补建
        static::ensureColumn($pdo, 'users', 'email', "VARCHAR(255) NULL");
        static::ensureColumn($pdo, 'users', 'password_hash', "VARCHAR(255) NULL");
        static::ensureColumn($pdo, 'users', 'auth_token', "VARCHAR(64) NULL");
        static::ensureColumn($pdo, 'users', 'token_expires_at', "INT UNSIGNED NULL");
        static::ensureColumn($pdo, 'users', 'realname_bound_at', "INT UNSIGNED NULL");
        static::ensureUniqueIndex($pdo, 'users', 'uk_email', 'email');

        // 兼容旧 players 表：账号绑定列/索引缺失时补建
        static::ensureColumn($pdo, 'players', 'user_id', "BIGINT UNSIGNED NULL");
        static::ensureUniqueIndex($pdo, 'players', 'uk_user_id', 'user_id');
    }

    // 列不存在时 ALTER TABLE 补建，用于老库 schema 迁移
    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    // 唯一索引不存在时补建，用于老表补齐邮箱唯一约束
    private static function ensureUniqueIndex(PDO $pdo, string $table, string $index, string $column): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` (`{$column}`)");
        }
    }
}
