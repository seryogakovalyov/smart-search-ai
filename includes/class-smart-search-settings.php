<?php
if (!defined('ABSPATH')) exit;

class Smart_Search_Settings
{
    private const OPTION_POST_TYPE_WEIGHTS = 'ssai_post_type_weights';
    private const OPTION_DECAY_FACTOR = 'ssai_decay_factor';
    private const OPTION_DECAY_THRESHOLD = 'ssai_decay_threshold_days';
    private const OPTION_LOG_RETENTION = 'ssai_log_retention_days';

    public static function get_post_type_weights()
    {
        $weights = get_option(self::OPTION_POST_TYPE_WEIGHTS, []);
        if (!is_array($weights)) {
            return [];
        }

        $sanitized = [];
        foreach ($weights as $post_type => $weight) {
            $post_type = sanitize_key($post_type);
            $weight = (float) $weight;
            if ($post_type && $weight > 0) {
                if (abs($weight - 1.0) < 0.01) {
                    continue;
                }
                $sanitized[$post_type] = $weight;
            }
        }

        return $sanitized;
    }

    public static function save_post_type_weights($weights)
    {
        if (!is_array($weights)) {
            $weights = [];
        }

        $prepared = [];
        foreach ($weights as $post_type => $weight) {
            $post_type = sanitize_key($post_type);
            $weight = (float) $weight;
            if ($post_type && $weight > 0) {
                if (abs($weight - 1.0) < 0.01) {
                    continue;
                }
                $prepared[$post_type] = $weight;
            }
        }

        update_option(self::OPTION_POST_TYPE_WEIGHTS, $prepared);
    }

    public static function get_decay_factor()
    {
        $value = (float) get_option(self::OPTION_DECAY_FACTOR, 0.95);
        if ($value <= 0 || $value >= 1) {
            $value = 0.95;
        }

        return $value;
    }

    public static function save_decay_factor($factor)
    {
        $factor = (float) $factor;
        if ($factor <= 0 || $factor >= 1) {
            $factor = 0.95;
        }

        update_option(self::OPTION_DECAY_FACTOR, $factor);
    }

    public static function get_decay_threshold_days()
    {
        $days = (int) get_option(self::OPTION_DECAY_THRESHOLD, 14);
        return max(1, $days);
    }

    public static function save_decay_threshold_days($days)
    {
        $days = max(1, (int) $days);
        update_option(self::OPTION_DECAY_THRESHOLD, $days);
    }

    public static function get_log_retention_days()
    {
        $days = (int) get_option(self::OPTION_LOG_RETENTION, 90);
        return max(1, $days);
    }

    public static function save_log_retention_days($days)
    {
        $days = max(1, (int) $days);
        update_option(self::OPTION_LOG_RETENTION, $days);
    }
}
