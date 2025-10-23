<?php
if (!defined('ABSPATH')) exit;

class Smart_Search_Core
{

    public static function init()
    {
        add_action('pre_get_posts', [__CLASS__, 'modify_search_query']);
        add_action('wp_ajax_ssai_register_click', [__CLASS__, 'register_click']);
        add_action('wp_ajax_nopriv_ssai_register_click', [__CLASS__, 'register_click']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    public static function enqueue_scripts()
    {
        wp_register_script('smart-search-js', SSAI_URL . 'assets/js/smart-search.js', ['jquery'], '1.0', true);
        wp_localize_script('smart-search-js', 'ssai_ajax', ['url' => admin_url('admin-ajax.php')]);
        wp_enqueue_script('smart-search-js');
    }

    public static function modify_search_query($query)
    {
        if (!is_admin() && $query->is_main_query() && $query->is_search()) {
            global $wpdb;

            $s = trim($query->get('s'));
            if (empty($s)) return;

            self::process_search($s);

            //  Split search string into words
            $words = preg_split('/\s+/', mb_strtolower($s));
            $words = array_filter($words, fn($w) => mb_strlen($w) > 2);
            if (empty($words)) return;

            $table_words = $wpdb->prefix . 'ai_words';
            $table_posts = $wpdb->prefix . 'posts';
            $table_log = $wpdb->prefix . 'ai_search_log';

            // Get weights for the words
            $placeholders = implode(',', array_fill(0, count($words), '%s'));
            $weights = $wpdb->get_results(
                $wpdb->prepare("SELECT word, weight FROM $table_words WHERE word IN ($placeholders)", ...$words),
                OBJECT_K
            );

            // Form relevance SQL
            $relevance_parts = [];
            foreach ($words as $word) {
                $w = isset($weights[$word]) ? $weights[$word]->weight : 1;
                $relevance_parts[] = "($w * ({$table_posts}.post_title LIKE '%$word%' OR {$table_posts}.post_content LIKE '%$word%'))";
            }
            $relevance_sql = implode(' + ', $relevance_parts);

            // Inject custom clauses
            add_filter('posts_clauses', function ($clauses) use ($wpdb, $table_posts, $table_log, $relevance_sql) {

                $clauses['join'] .= " LEFT JOIN $table_log sl ON sl.clicked_post_id = {$table_posts}.ID ";

                // Counts clicks and calculates relevance
                $clauses['fields'] .= ", COALESCE(COUNT(sl.clicked_post_id),0) AS clicks, ($relevance_sql) AS relevance";

                // Group by post ID
                $clauses['groupby'] = "{$table_posts}.ID";

                // Sort by clicks and relevance
                $clauses['orderby'] = "clicks DESC, relevance DESC";

                return $clauses;
            });
        }
    }


    public static function process_search($query)
    {
        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        $words = preg_split('/\s+/', mb_strtolower($query));
        $words = array_filter($words, fn($w) => mb_strlen($w) > 2);

        foreach ($words as $word) {
            $wpdb->query($wpdb->prepare("
                INSERT INTO $table_words (word, weight)
                VALUES (%s, 1)
                ON DUPLICATE KEY UPDATE
                    last_used = NOW(),
                    weight = weight + 0.05
            ", $word));
        }

        $wpdb->insert($table_logs, ['query' => $query]);
    }

    public static function register_click()
    {
        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';
        $table_log   = $wpdb->prefix . 'ai_search_log';

        $query   = sanitize_text_field($_POST['query'] ?? '');
        $post_id = intval($_POST['post_id'] ?? 0);

        if (!$query || !$post_id) {
            wp_send_json_error(['message' => 'Invalid data']);
        }

        // Strengthen weights of words in the query
        $words = preg_split('/\s+/', mb_strtolower($query));
        foreach ($words as $word) {
            $wpdb->query(
                $wpdb->prepare("UPDATE $table_words SET weight = weight + 0.5 WHERE word = %s", $word)
            );
        }

        // Update the log entry to record the clicked post
        $wpdb->query(
            $wpdb->prepare("
            UPDATE $table_log 
            SET clicked_post_id = %d 
            WHERE id = (
                SELECT id FROM (
                    SELECT id 
                    FROM $table_log 
                    WHERE query = %s 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) AS sub
            )
        ", $post_id, $query)
        );

        wp_send_json_success();
    }
}
