<?php
if (!defined('ABSPATH')) exit;

class Smart_Search_Admin
{
    public static function menu()
    {
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

    public static function page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        self::handle_post();

        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';
        $table_logs  = $wpdb->prefix . 'ai_search_log';

        $words = $wpdb->get_results("SELECT word, weight, last_used FROM {$table_words} ORDER BY weight DESC LIMIT 100");
        $logs  = $wpdb->get_results("SELECT query, clicked_post_id, created_at FROM {$table_logs} ORDER BY created_at DESC LIMIT 20");
        $zero_clicks = $wpdb->get_results("SELECT query, COUNT(*) AS total FROM {$table_logs} WHERE clicked_post_id IS NULL GROUP BY query ORDER BY total DESC LIMIT 20");
        $daily_stats = $wpdb->get_results(
            "SELECT DATE(created_at) AS day,
                    COUNT(*) AS searches,
                    SUM(CASE WHEN clicked_post_id IS NOT NULL THEN 1 ELSE 0 END) AS clicks
             FROM {$table_logs}
             GROUP BY DATE(created_at)
             ORDER BY day DESC
             LIMIT 14"
        );

        $post_types = get_post_types(['public' => true], 'objects');
        $post_type_weights = Smart_Search_Settings::get_post_type_weights();

        $decay_factor   = Smart_Search_Settings::get_decay_factor();
        $decay_days     = Smart_Search_Settings::get_decay_threshold_days();
        $retention_days = Smart_Search_Settings::get_log_retention_days();

        $nonce = wp_create_nonce('ssai_admin_action');

?>
        <div class="wrap smart-search-ai-admin">
            <h1>📈 Smart Search AI — <?php esc_html_e('Analytics & Training', 'smart-search-ai'); ?></h1>

            <?php settings_errors('ssai_admin'); ?>

            <h2>📊 <?php esc_html_e('Last 14 days performance', 'smart-search-ai'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'smart-search-ai'); ?></th>
                        <th><?php esc_html_e('Searches', 'smart-search-ai'); ?></th>
                        <th><?php esc_html_e('Clicks', 'smart-search-ai'); ?></th>
                        <th><?php esc_html_e('CTR', 'smart-search-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_stats as $stat):
                        $ctr = $stat->searches ? round(($stat->clicks / $stat->searches) * 100, 2) : 0; ?>
                        <tr>
                            <td><?php echo esc_html($stat->day); ?></td>
                            <td><?php echo esc_html((int) $stat->searches); ?></td>
                            <td><?php echo esc_html((int) $stat->clicks); ?></td>
                            <td><?php echo esc_html($ctr); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="ssai-columns">
                <div class="ssai-column">
                    <h2>🔤 <?php esc_html_e('Top words', 'smart-search-ai'); ?></h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Word', 'smart-search-ai'); ?></th>
                                <th><?php esc_html_e('Weight', 'smart-search-ai'); ?></th>
                                <th><?php esc_html_e('Last used', 'smart-search-ai'); ?></th>
                                <th><?php esc_html_e('Actions', 'smart-search-ai'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($words as $word): ?>
                                <tr>
                                    <td><?php echo esc_html($word->word); ?></td>
                                    <td>
                                        <form method="post" class="ssai-inline-form">
                                            <input type="hidden" name="ssai_nonce" value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="ssai_action" value="update_weight">
                                            <input type="hidden" name="word" value="<?php echo esc_attr($word->word); ?>">
                                            <input type="number" name="weight" step="0.01" min="0" class="small-text" value="<?php echo esc_attr(number_format((float) $word->weight, 2, '.', '')); ?>">
                                            <button type="submit" class="button button-small"><?php esc_html_e('Update', 'smart-search-ai'); ?></button>
                                        </form>
                                    </td>
                                    <td><?php echo esc_html($word->last_used); ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this word from the dictionary?', 'smart-search-ai')); ?>');">
                                            <input type="hidden" name="ssai_nonce" value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="ssai_action" value="delete_word">
                                            <input type="hidden" name="word" value="<?php echo esc_attr($word->word); ?>">
                                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete', 'smart-search-ai'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ssai-column">
                    <h2>🧾 <?php esc_html_e('Recent searches', 'smart-search-ai'); ?></h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Query', 'smart-search-ai'); ?></th>
                                <th><?php esc_html_e('Clicked post', 'smart-search-ai'); ?></th>
                                <th><?php esc_html_e('Date', 'smart-search-ai'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html($log->query); ?></td>
                                    <td><?php echo $log->clicked_post_id ? '<a href="' . get_the_permalink((int) $log->clicked_post_id) . '">' . get_the_title((int) $log->clicked_post_id) . '</a>' : '—'; ?></td>
                                    <td><?php echo esc_html($log->created_at); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h2 style="margin-top:30px;">⚠️ <?php esc_html_e('Zero-click queries', 'smart-search-ai'); ?></h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Query', 'smart-search-ai'); ?></th>
                                <th><?php esc_html_e('Attempts', 'smart-search-ai'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($zero_clicks as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item->query); ?></td>
                                    <td><?php echo esc_html((int) $item->total); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <h2>⚙️ <?php esc_html_e('Search tuning', 'smart-search-ai'); ?></h2>
            <form method="post" class="ssai-settings-form">
                <input type="hidden" name="ssai_nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="ssai_action" value="save_settings">

                <h3><?php esc_html_e('Post type weights', 'smart-search-ai'); ?></h3>
                <p class="description"><?php esc_html_e('Increase or decrease priority for particular post types.', 'smart-search-ai'); ?></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Post type', 'smart-search-ai'); ?></th>
                            <th><?php esc_html_e('Weight', 'smart-search-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($post_types as $type => $object):
                            $weight_value = $post_type_weights[$type] ?? 1; ?>
                            <tr>
                                <td><?php echo esc_html($object->labels->singular_name . " ({$type})"); ?></td>
                                <td>
                                    <input type="number" step="0.1" min="0" name="post_type_weights[<?php echo esc_attr($type); ?>]" value="<?php echo esc_attr($weight_value); ?>" class="small-text">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3><?php esc_html_e('Training behaviour', 'smart-search-ai'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Weight decay factor', 'smart-search-ai'); ?></th>
                        <td>
                            <input type="number" name="decay_factor" step="0.01" min="0.1" max="0.99" value="<?php echo esc_attr($decay_factor); ?>">
                            <p class="description"><?php esc_html_e('Multiplier applied to rarely used words (0.95 = decrease by 5%).', 'smart-search-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Decay threshold (days)', 'smart-search-ai'); ?></th>
                        <td>
                            <input type="number" name="decay_days" min="1" value="<?php echo esc_attr($decay_days); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Log retention (days)', 'smart-search-ai'); ?></th>
                        <td>
                            <input type="number" name="retention_days" min="7" value="<?php echo esc_attr($retention_days); ?>">
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save settings', 'smart-search-ai'); ?></button>
                </p>
            </form>
        </div>

        <style>
            .ssai-columns {
                display: flex;
                gap: 20px;
                margin-top: 30px;
            }

            .ssai-column {
                flex: 1;
                min-width: 0;
            }

            .ssai-inline-form {
                display: flex;
                gap: 6px;
                align-items: center;
            }

            .ssai-inline-form .small-text {
                width: 80px;
            }

            .ssai-settings-form .small-text {
                width: 120px;
            }

            @media (max-width: 960px) {
                .ssai-columns {
                    flex-direction: column;
                }
            }
        </style>
<?php
    }

    private static function handle_post()
    {
        if (empty($_POST['ssai_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('ssai_admin_action', 'ssai_nonce');

        global $wpdb;
        $table_words = $wpdb->prefix . 'ai_words';

        $action = sanitize_text_field(wp_unslash($_POST['ssai_action']));

        if ($action === 'update_weight') {
            $word = sanitize_text_field(wp_unslash($_POST['word'] ?? ''));
            $weight = isset($_POST['weight']) ? (float) wp_unslash($_POST['weight']) : 0;

            if ($word && $weight > 0) {
                $wpdb->update(
                    $table_words,
                    [
                        'weight'    => $weight,
                        'last_used' => current_time('mysql', true),
                    ],
                    ['word' => $word]
                );
                add_settings_error('ssai_admin', 'ssai_weight_updated', __('The weight of the word has been updated.', 'smart-search-ai'), 'updated');
            } else {
                add_settings_error('ssai_admin', 'ssai_weight_error', __('Failed to update word weight.', 'smart-search-ai'));
            }

            return;
        }

        if ($action === 'delete_word') {
            $word = sanitize_text_field(wp_unslash($_POST['word'] ?? ''));
            if ($word) {
                $wpdb->delete($table_words, ['word' => $word]);
                add_settings_error('ssai_admin', 'ssai_word_deleted', __('The word has been deleted from the dictionary.', 'smart-search-ai'), 'updated');
            }

            return;
        }

        if ($action === 'save_settings') {
            $weights = isset($_POST['post_type_weights']) && is_array($_POST['post_type_weights'])
                ? array_map('wp_unslash', $_POST['post_type_weights'])
                : [];
            Smart_Search_Settings::save_post_type_weights($weights);

            $decay_factor = isset($_POST['decay_factor']) ? (float) wp_unslash($_POST['decay_factor']) : Smart_Search_Settings::get_decay_factor();
            Smart_Search_Settings::save_decay_factor($decay_factor);

            $decay_days = isset($_POST['decay_days']) ? (int) wp_unslash($_POST['decay_days']) : Smart_Search_Settings::get_decay_threshold_days();
            Smart_Search_Settings::save_decay_threshold_days($decay_days);

            $retention_days = isset($_POST['retention_days']) ? (int) wp_unslash($_POST['retention_days']) : Smart_Search_Settings::get_log_retention_days();
            Smart_Search_Settings::save_log_retention_days($retention_days);

            add_settings_error('ssai_admin', 'ssai_settings_saved', __('Settings saved.', 'smart-search-ai'), 'updated');
        }
    }
}
