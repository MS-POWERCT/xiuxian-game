<?php

namespace app\services\Admin;

/**
 * 配置写入前的结构校验：只校验本项目已经固定的关键约束，避免后台改出无法启动的配置。
 */
class AdminConfigValidator
{
    public static function validate(string $name, array $data): ?string
    {
        return match ($name) {
            'realms' => self::realms($data),
            'unlock' => self::unlock($data),
            'lifecycle' => self::lifecycle($data),
            'meditation' => self::meditation($data),
            'economy' => self::economy($data),
            'shop' => self::shop($data),
            'audio' => self::audio($data),
            default => null,
        };
    }

    private static function realms(array $data): ?string
    {
        if (
            !isset($data['time_ratio']['real_day_to_game_year'])
            || !is_numeric($data['time_ratio']['real_day_to_game_year'])
            || (float)$data['time_ratio']['real_day_to_game_year'] <= 0
        ) {
            return 'realms.time_ratio.real_day_to_game_year 必须大于 0';
        }
        if (!isset($data['start_age_years']) || !is_numeric($data['start_age_years']) || (int)$data['start_age_years'] <= 0) {
            return 'realms.start_age_years 必须为正整数';
        }
        if (empty($data['realms']) || !self::isList($data['realms'])) {
            return 'realms.realms 必须是非空数组';
        }

        foreach ($data['realms'] as $realm) {
            if (
                !is_array($realm)
                || empty($realm['id']) || !is_string($realm['id'])
                || empty($realm['name']) || !is_string($realm['name'])
                || !isset($realm['order']) || !is_numeric($realm['order'])
                || !isset($realm['lifespan_years']) || !is_numeric($realm['lifespan_years'])
                || !isset($realm['cultivate_rate']) || !is_numeric($realm['cultivate_rate'])
                || empty($realm['stages']) || !self::isList($realm['stages'])
                || empty($realm['exp_to_next']) || !self::isList($realm['exp_to_next'])
            ) {
                return 'realms.realms 存在字段缺失或类型错误';
            }
            if (count($realm['stages']) !== count($realm['exp_to_next'])) {
                return 'realms.realms 的 stages 与 exp_to_next 长度必须一致';
            }
            foreach ($realm['exp_to_next'] as $exp) {
                if (!is_numeric($exp) || (int)$exp < 0) {
                    return 'realms.realms.exp_to_next 必须是非负数字';
                }
            }
        }
        return null;
    }

    private static function unlock(array $data): ?string
    {
        foreach ($data as $feature => $item) {
            if (
                !is_string($feature) || !is_array($item)
                || empty($item['name']) || !is_string($item['name'])
                || empty($item['realm_id']) || !is_string($item['realm_id'])
                || !isset($item['stage']) || !is_numeric($item['stage']) || (int)$item['stage'] < 1
            ) {
                return 'unlock 配置项必须包含 name、realm_id、stage，且 stage 从 1 起';
            }
        }
        return null;
    }

    private static function lifecycle(array $data): ?string
    {
        if (
            !isset($data['hp']['max']) || !is_numeric($data['hp']['max'])
            || (int)$data['hp']['max'] <= 0 || (int)$data['hp']['max'] > 100
            || empty($data['hp']['injury_thresholds']) || !self::isList($data['hp']['injury_thresholds'])
        ) {
            return 'lifecycle.hp 配置不完整';
        }
        foreach ($data['hp']['injury_thresholds'] as $threshold) {
            if (
                !is_array($threshold)
                || !isset($threshold['below']) || !is_numeric($threshold['below'])
                || !isset($threshold['cultivate_rate_penalty']) || !is_numeric($threshold['cultivate_rate_penalty'])
            ) {
                return 'lifecycle.hp.injury_thresholds 字段错误';
            }
        }
        if (
            !isset($data['reincarnation']['max_reincarnations_per_day'])
            || !is_numeric($data['reincarnation']['max_reincarnations_per_day'])
            || (int)$data['reincarnation']['max_reincarnations_per_day'] < 1
        ) {
            return 'lifecycle.reincarnation.max_reincarnations_per_day 必须为正整数';
        }
        return null;
    }

    private static function meditation(array $data): ?string
    {
        if (
            !isset($data['dazuo_base_exp']) || !is_numeric($data['dazuo_base_exp'])
            || !isset($data['dazuo_batch_size']) || !is_numeric($data['dazuo_batch_size']) || (int)$data['dazuo_batch_size'] < 1
            || !isset($data['dazuo_daily_limit_base']) || !is_numeric($data['dazuo_daily_limit_base']) || (int)$data['dazuo_daily_limit_base'] < 0
            || !isset($data['dazuo_daily_limit_per_realm']) || !is_numeric($data['dazuo_daily_limit_per_realm']) || (int)$data['dazuo_daily_limit_per_realm'] < 0
            || !isset($data['dazuo_click_interval_ms']) || !is_numeric($data['dazuo_click_interval_ms']) || (int)$data['dazuo_click_interval_ms'] < 0
            || !isset($data['dazuo_cooldown_ms']) || !is_numeric($data['dazuo_cooldown_ms']) || (int)$data['dazuo_cooldown_ms'] < 0
        ) {
            return 'meditation 的感悟配置错误';
        }
        if (
            empty($data['meditation_durations']) || !self::isList($data['meditation_durations'])
            || !isset($data['meditation_exp_ratio']) || !is_numeric($data['meditation_exp_ratio'])
        ) {
            return 'meditation 的冥想时长或收益系数配置错误';
        }
        foreach ($data['meditation_durations'] as $duration) {
            if (!is_numeric($duration) || (int)$duration < 1) {
                return 'meditation.meditation_durations 必须为正整数数组';
            }
        }
        if (
            empty($data['meditations']) || !self::isList($data['meditations'])
            || empty($data['retreats']) || !self::isList($data['retreats'])
        ) {
            return 'meditation.meditations 与 meditation.retreats 必须是非空数组';
        }
        foreach ($data['meditations'] as $meditation) {
            if (
                !is_array($meditation)
                || empty($meditation['duration_seconds']) || !is_numeric($meditation['duration_seconds'])
                || !isset($meditation['base_exp']) || !is_numeric($meditation['base_exp'])
            ) {
                return 'meditation.meditations 字段错误';
            }
        }
        if (
            !isset($data['retreat_early_exit_ratio']) || !is_numeric($data['retreat_early_exit_ratio'])
            || (float)$data['retreat_early_exit_ratio'] < 0 || (float)$data['retreat_early_exit_ratio'] > 1
            || !isset($data['retreat_buff_cap']) || !is_numeric($data['retreat_buff_cap'])
        ) {
            return 'meditation 的闭关比例或 buff 上限配置错误';
        }
        return null;
    }

    private static function economy(array $data): ?string
    {
        if (empty($data['spirit_stone_levels']) || !self::isList($data['spirit_stone_levels'])) {
            return 'economy.spirit_stone_levels 必须是非空数组';
        }
        foreach ($data['spirit_stone_levels'] as $level) {
            if (!is_string($level) || $level === '') {
                return 'economy.spirit_stone_levels 必须为字符串数组';
            }
        }
        if (!isset($data['spirit_stone_caps']) || !is_array($data['spirit_stone_caps'])) {
            return 'economy.spirit_stone_caps 必须是对象';
        }
        foreach ($data['spirit_stone_levels'] as $level) {
            if (
                !isset($data['spirit_stone_caps'][$level])
                || !is_numeric($data['spirit_stone_caps'][$level])
                || (int)$data['spirit_stone_caps'][$level] < 0
            ) {
                return 'economy.spirit_stone_caps 每个品级都必须配置非负上限';
            }
        }
        if (!isset($data['exchange_ratio']) || !is_numeric($data['exchange_ratio']) || (int)$data['exchange_ratio'] < 1) {
            return 'economy.exchange_ratio 必须为正整数';
        }
        if (!isset($data['exchange_fee_ratio']) || !is_array($data['exchange_fee_ratio'])) {
            return 'economy.exchange_fee_ratio 必须是对象';
        }
        foreach ($data['exchange_fee_ratio'] as $key => $ratio) {
            if (!is_string($key) || !is_numeric($ratio) || (float)$ratio < 0 || (float)$ratio > 1) {
                return 'economy.exchange_fee_ratio 的比例必须在 0 到 1 之间';
            }
        }
        return null;
    }

    private static function shop(array $data): ?string
    {
        if (empty($data['items']) || !self::isList($data['items'])) {
            return 'shop.items 必须是非空数组';
        }
        foreach ($data['items'] as $item) {
            if (
                !is_array($item)
                || empty($item['id']) || !is_string($item['id'])
                || empty($item['name']) || !is_string($item['name'])
                || empty($item['category']) || !is_string($item['category'])
                || !isset($item['ref_id']) || !is_string($item['ref_id'])
                || !isset($item['price']) || !is_numeric($item['price']) || (int)$item['price'] < 0
                || empty($item['currency_level']) || !is_string($item['currency_level'])
            ) {
                return 'shop.items 存在字段缺失或类型错误';
            }
        }
        return null;
    }

    private static function audio(array $data): ?string
    {
        foreach (['bgm', 'sfx'] as $group) {
            if (!isset($data[$group]) || !is_array($data[$group])) {
                return "audio.{$group} 必须是对象";
            }
            foreach ($data[$group] as $key => $item) {
                if (!is_string($key) || !is_array($item) || empty($item['src']) || !is_string($item['src'])) {
                    return "audio.{$group} 存在非法配置项";
                }
                if (strpos($item['src'], '/audio/') !== 0 || strpos($item['src'], '..') !== false) {
                    return "audio.{$group}.{$key}.src 必须位于 /audio/ 目录下";
                }
            }
        }
        return null;
    }

    private static function isList(array $value): bool
    {
        $i = 0;
        foreach ($value as $key => $_) {
            if ($key !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}
