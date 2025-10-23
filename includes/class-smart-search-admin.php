<?php
if (!defined('ABSPATH')) exit;

class Smart_Search_Admin {

    public static function menu() {
        add_menu_page(
            'Smart Search AI',
            'Smart Search AI',
            'manage_options',
            'smart-search-ai',
            [__CLASS__, 'page'],
            'dashicons-search',
            80
        );
    }

    public static function page() {
        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        $words = $wpdb->get_results("SELECT * FROM $table_words ORDER BY weight DESC LIMIT 50");
        $logs  = $wpdb->get_results("SELECT * FROM $table_logs ORDER BY created_at DESC LIMIT 20");
        ?>
        <div class="wrap">
            <h1>📈 Smart Search AI — Statistics</h1>

            <h2>🔤 Top Words</h2>
            <table class="widefat">
                <thead><tr><th>Word</th><th>Weight</th><th>Last Used</th></tr></thead>
                <tbody>
                <?php foreach ($words as $w): ?>
                    <tr>
                        <td><?= esc_html($w->word) ?></td>
                        <td><?= number_format($w->weight, 2) ?></td>
                        <td><?= esc_html($w->last_used) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:30px;">🧾 Recent Searches</h2>
            <table class="widefat">
                <thead><tr><th>Query</th><th>Clicked Post</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= esc_html($log->query) ?></td>
                        <td><?= $log->clicked_post_id ?: '—' ?></td>
                        <td><?= esc_html($log->created_at) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
