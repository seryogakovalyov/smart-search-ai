<?php

/**
 * Plugin Name: Smart Search AI
 * Description: Enhances WordPress search functionality using AI-driven relevance and click tracking.
 * Version: 1.1.0
 * Author: Sergey
 */

if (!defined('ABSPATH')) exit;

define('SSAI_PATH', plugin_dir_path(__FILE__));
define('SSAI_URL', plugin_dir_url(__FILE__));
define('SSAI_VERSION', '1.1.0');

require_once SSAI_PATH . 'includes/class-smart-search-settings.php';
require_once SSAI_PATH . 'includes/class-smart-search-activator.php';
require_once SSAI_PATH . 'includes/class-smart-search-core.php';
require_once SSAI_PATH . 'includes/class-smart-search-admin.php';

register_activation_hook(__FILE__, ['Smart_Search_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Smart_Search_Activator', 'deactivate']);
register_uninstall_hook(__FILE__, ['Smart_Search_Activator', 'uninstall']);

// Initialize the core functionality
add_action('init', ['Smart_Search_Core', 'init']);
add_action('admin_menu', ['Smart_Search_Admin', 'menu']);
