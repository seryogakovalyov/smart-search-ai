<?php
if (!defined('ABSPATH')) exit;

class Smart_Search_Activator
{
    public static function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // create words table
        $sql_words = "CREATE TABLE $table_words (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            word VARCHAR(100) NOT NULL,
            weight FLOAT DEFAULT 1,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY word (word),
            KEY last_used (last_used),
            KEY weight (weight)
        ) $charset_collate;";
        dbDelta($sql_words);

        // create search log table
        $sql_logs = "CREATE TABLE $table_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            query VARCHAR(255) NOT NULL,
            clicked_post_id BIGINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY query (query),
            KEY clicked_post_id (clicked_post_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_logs);

        if (!wp_next_scheduled('ssai_decay_word_weights')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'ssai_decay_word_weights');
        }

        if (!wp_next_scheduled('ssai_cleanup_logs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ssai_cleanup_logs');
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook('ssai_decay_word_weights');
        wp_clear_scheduled_hook('ssai_cleanup_logs');
    }

    public static function uninstall()
    {
        global $wpdb;

        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        $wpdb->query("DROP TABLE IF EXISTS $table_words");
        $wpdb->query("DROP TABLE IF EXISTS $table_logs");

        delete_option('ssai_post_type_weights');
        delete_option('ssai_decay_factor');
        delete_option('ssai_decay_threshold_days');
        delete_option('ssai_log_retention_days');
    }
}
