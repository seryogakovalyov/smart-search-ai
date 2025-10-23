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
            id INT NOT NULL AUTO_INCREMENT,
            word VARCHAR(100) NOT NULL UNIQUE,
            weight FLOAT DEFAULT 1,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) $charset_collate;";
        dbDelta($sql_words);

        // create search log table
        $sql_logs = "CREATE TABLE $table_logs (
            id INT NOT NULL AUTO_INCREMENT,
            query VARCHAR(255) NOT NULL,
            clicked_post_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) $charset_collate;";
        dbDelta($sql_logs);
    }

    public static function deactivate()
    {
        // Do nothing for now
    }

    public static function uninstall()
    {
        global $wpdb;

        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        $wpdb->query("DROP TABLE IF EXISTS $table_words");
        $wpdb->query("DROP TABLE IF EXISTS $table_logs");
    }
}
