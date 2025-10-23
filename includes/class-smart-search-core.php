<?php
if (!defined('ABSPATH')) exit;

class Smart_Search_Core
{
    private const CLICK_NONCE_ACTION = 'ssai_click';

    /** @var string */
    private static $current_relevance_sql = '';

    /** @var string */
    private static $post_type_case_sql = '';

    /** @var bool */
    private static $clauses_filter_active = false;

    public static function init()
    {
        add_action('pre_get_posts', [__CLASS__, 'modify_search_query']);
        add_action('wp_ajax_ssai_register_click', [__CLASS__, 'register_click']);
        add_action('wp_ajax_nopriv_ssai_register_click', [__CLASS__, 'register_click']);
        add_action('wp_ajax_ssai_autocomplete', [__CLASS__, 'autocomplete']);
        add_action('wp_ajax_nopriv_ssai_autocomplete', [__CLASS__, 'autocomplete']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);

        add_action('ssai_decay_word_weights', [__CLASS__, 'decay_word_weights']);
        add_action('ssai_cleanup_logs', [__CLASS__, 'cleanup_logs']);

        self::maybe_schedule_events();
    }

    public static function enqueue_scripts()
    {
        if (is_admin()) {
            return;
        }

        $search_query = get_search_query(false);
        // if (!is_search() || empty($search_query)) {
        //     return;
        // }

        wp_register_style('smart-search-css', SSAI_URL . 'assets/css/smart-search.css', [], SSAI_VERSION);
        wp_enqueue_style('smart-search-css');

        wp_register_script('smart-search-js', SSAI_URL . 'assets/js/smart-search.js', ['jquery'], SSAI_VERSION, true);
        wp_localize_script('smart-search-js', 'ssai_ajax', [
            'url'        => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce(self::CLICK_NONCE_ACTION),
            'query'      => $search_query,
            'i18n'       => [
                'noSuggestions' => __('No hints', 'smart-search-ai'),
            ],
        ]);
        wp_enqueue_script('smart-search-js');
    }

    public static function modify_search_query($query)
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return;
        }

        global $wpdb;

        $search_string = trim($query->get('s'));
        if ($search_string === '') {
            return;
        }

        self::process_search($search_string);

        $words = self::tokenize_search($search_string);
        if (empty($words)) {
            return;
        }

        $table_words = $wpdb->prefix . 'ai_words';
        $table_posts = $wpdb->posts;
        $table_log   = $wpdb->prefix . 'ai_search_log';

        $placeholders = implode(',', array_fill(0, count($words), '%s'));
        $weights = $wpdb->get_results(
            $wpdb->prepare("SELECT word, weight FROM {$table_words} WHERE word IN ($placeholders)", ...$words),
            OBJECT_K
        );

        $relevance_parts = [];
        foreach ($words as $word) {
            $weight = isset($weights[$word]) ? (float) $weights[$word]->weight : 1.0;
            $like = '%' . $wpdb->esc_like($word) . '%';
            $relevance_parts[] = $wpdb->prepare(
                "(%f * (({$table_posts}.post_title LIKE %s) + ({$table_posts}.post_content LIKE %s)))",
                $weight,
                $like,
                $like
            );
        }

        if (empty($relevance_parts)) {
            return;
        }

        $post_type_weights = self::get_post_type_weight_cases();
        self::$post_type_case_sql = $post_type_weights;

        self::$current_relevance_sql = implode(' + ', $relevance_parts);

        if (!self::$clauses_filter_active) {
            add_filter('posts_clauses', [__CLASS__, 'filter_posts_clauses']);
            self::$clauses_filter_active = true;
        }
    }

    public static function filter_posts_clauses($clauses)
    {
        if (empty(self::$current_relevance_sql)) {
            remove_filter('posts_clauses', [__CLASS__, 'filter_posts_clauses']);
            self::$clauses_filter_active = false;
            return $clauses;
        }

        global $wpdb;
        $table_posts = $wpdb->posts;
        $table_log   = $wpdb->prefix . 'ai_search_log';

        $clauses['join'] .= " LEFT JOIN {$table_log} ssai_log ON ssai_log.clicked_post_id = {$table_posts}.ID ";

        $fields = ", COALESCE(COUNT(DISTINCT ssai_log.id), 0) AS ssai_clicks, (" . self::$current_relevance_sql . ") AS ssai_relevance";
        if (!empty(self::$post_type_case_sql)) {
            $fields .= ', ' . self::$post_type_case_sql . ' AS ssai_post_type_weight';
        }
        $clauses['fields'] .= $fields;

        if (!empty($clauses['groupby'])) {
            $clauses['groupby'] .= ", {$table_posts}.ID";
        } else {
            $clauses['groupby'] = "{$table_posts}.ID";
        }

        $orderby_parts = ['ssai_clicks DESC', 'ssai_relevance DESC'];
        if (!empty(self::$post_type_case_sql)) {
            $orderby_parts[] = 'ssai_post_type_weight DESC';
        }
        if (!empty($clauses['orderby'])) {
            $orderby_parts[] = $clauses['orderby'];
        }
        $clauses['orderby'] = implode(', ', $orderby_parts);

        remove_filter('posts_clauses', [__CLASS__, 'filter_posts_clauses']);
        self::$clauses_filter_active = false;
        self::$current_relevance_sql = '';
        self::$post_type_case_sql = '';

        return $clauses;
    }

    public static function process_search($query)
    {
        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        $words = self::tokenize_search($query);

        foreach ($words as $word) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table_words} (word, weight, last_used) VALUES (%s, 1, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE last_used = UTC_TIMESTAMP(), weight = weight + 0.05",
                $word
            ));
        }

        $wpdb->insert($table_logs, [
            'query'      => $query,
            'created_at' => current_time('mysql', true),
        ]);
    }

    public static function register_click()
    {
        check_ajax_referer(self::CLICK_NONCE_ACTION, 'nonce');

        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';
        $table_log   = $wpdb->prefix . 'ai_search_log';

        $query   = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        $post_id = intval($_POST['post_id'] ?? 0);

        if (!$query || !$post_id || !get_post_status($post_id)) {
            wp_send_json_error(['message' => __('Incorrect data', 'smart-search-ai')]);
        }

        $words = self::tokenize_search($query);
        foreach ($words as $word) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_words} SET weight = LEAST(weight + 0.5, 1000), last_used = UTC_TIMESTAMP() WHERE word = %s",
                    $word
                )
            );
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_log}
                 SET clicked_post_id = %d
                 WHERE id = (
                    SELECT id FROM (
                        SELECT id FROM {$table_log}
                        WHERE query = %s AND clicked_post_id IS NULL
                        ORDER BY created_at DESC
                        LIMIT 1
                    ) AS ssai_latest
                 )",
                $post_id,
                $query
            )
        );

        wp_send_json_success();
    }

    public static function autocomplete()
    {
        check_ajax_referer(self::CLICK_NONCE_ACTION, 'nonce');

        $term = sanitize_text_field(wp_unslash($_REQUEST['term'] ?? ''));
        if (self::word_length($term) < 2) {
            wp_send_json_success(['suggestions' => [], 'queries' => []]);
        }

        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        $like = '%' . $wpdb->esc_like(self::normalize_word($term)) . '%';

        $word_results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT word, weight FROM {$table_words} WHERE word LIKE %s ORDER BY weight DESC LIMIT 10",
                $like
            )
        );

        $post_args = [
            's'                   => $term,
            'posts_per_page'      => 5,
            'post_status'         => 'publish',
            'post_type'           => 'any',
            'orderby'             => 'relevance',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];
        $post_args = apply_filters('ssai_autocomplete_query_args', $post_args, $term);

        $post_query       = new WP_Query($post_args);
        $post_suggestions = [];

        if (!empty($post_query->posts)) {
            foreach ($post_query->posts as $post_item) {
                $title = trim(wp_strip_all_tags(get_the_title($post_item)));
                if ($title === '') {
                    continue;
                }
                $title = wp_specialchars_decode($title, ENT_QUOTES);

                $post_type_object = get_post_type_object($post_item->post_type);
                $post_type_label = '';
                if ($post_type_object && isset($post_type_object->labels->singular_name)) {
                    $post_type_label = trim(wp_strip_all_tags($post_type_object->labels->singular_name));
                }

                $post_suggestions[] = [
                    'id'               => $post_item->ID,
                    'title'            => $title,
                    'permalink'        => esc_url_raw(get_permalink($post_item)),
                    'post_type'        => sanitize_key($post_item->post_type),
                    'post_type_label'  => $post_type_label,
                ];
            }
        }
        $post_suggestions = apply_filters('ssai_autocomplete_post_results', $post_suggestions, $term, $post_query);

        $query_like = '%' . $wpdb->esc_like($term) . '%';
        $recent_queries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT query, COUNT(*) as total
             FROM {$table_logs}
             WHERE query LIKE %s
             GROUP BY query
             ORDER BY total DESC, MAX(created_at) DESC
             LIMIT 5",
                $query_like
            )
        );

        wp_send_json_success([
            'suggestions' => array_map(fn($row) => ['word' => $row->word, 'weight' => (float) $row->weight], $word_results),
            'posts'       => array_map(
                fn($item) => [
                    'id'              => (int) $item['id'],
                    'title'           => $item['title'],
                    'permalink'       => $item['permalink'],
                    'post_type'       => $item['post_type'],
                    'post_type_label' => $item['post_type_label'],
                ],
                $post_suggestions
            ),
            'queries'     => array_map(fn($row) => ['query' => $row->query, 'count' => (int) $row->total], $recent_queries),
        ]);
    }

    public static function decay_word_weights()
    {
        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';

        $factor = Smart_Search_Settings::get_decay_factor();
        $factor = (float) apply_filters('ssai_decay_factor', $factor);
        $factor = ($factor <= 0 || $factor >= 1) ? Smart_Search_Settings::get_decay_factor() : min($factor, 0.999);

        $days = Smart_Search_Settings::get_decay_threshold_days();
        $days = (int) apply_filters('ssai_decay_threshold_days', $days);
        $days = max(1, $days);

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_words}
                 SET weight = GREATEST(1, weight * %f)
                 WHERE last_used < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$days} DAY)",
                $factor
            )
        );
    }

    public static function cleanup_logs()
    {
        global $wpdb;
        $table_log = $wpdb->prefix . 'ai_search_log';

        $days = Smart_Search_Settings::get_log_retention_days();
        $days = (int) apply_filters('ssai_log_retention_days', $days);
        $days = max(1, $days);

        $wpdb->query("DELETE FROM {$table_log} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$days} DAY)");
    }

    private static function maybe_schedule_events()
    {
        if (!wp_next_scheduled('ssai_decay_word_weights')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'ssai_decay_word_weights');
        }

        if (!wp_next_scheduled('ssai_cleanup_logs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ssai_cleanup_logs');
        }
    }

    private static function tokenize_search($query)
    {
        $query = wp_strip_all_tags((string) $query);
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return [];
        }

        $words = array_map([__CLASS__, 'normalize_word'], $words);
        $words = array_filter($words, fn($word) => self::word_length($word) > 2);

        return array_values(array_unique($words));
    }

    private static function normalize_word($word)
    {
        $word = remove_accents($word);
        $word = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
        $word = preg_replace('/[^\p{L}\p{N}]+/u', '', $word);

        if (function_exists('wp_stem') && $word !== '') {
            $stem = wp_stem($word);
            if (!empty($stem)) {
                $word = $stem;
            }
        }

        return apply_filters('ssai_normalize_word', $word);
    }

    private static function get_post_type_weight_cases()
    {
        $weights = Smart_Search_Settings::get_post_type_weights();
        if (empty($weights)) {
            return '';
        }

        global $wpdb;
        $cases = [];
        foreach ($weights as $post_type => $weight) {
            $weight = (float) $weight;
            if ($weight <= 0 || abs($weight - 1.0) < 0.01) {
                continue;
            }
            $cases[] = $wpdb->prepare("WHEN %s THEN %f", $post_type, $weight);
        }

        if (empty($cases)) {
            return '';
        }

        return 'CASE ' . $wpdb->posts . '.post_type ' . implode(' ', $cases) . ' ELSE 1 END';
    }

    private static function word_length($word)
    {
        return function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
    }
}
