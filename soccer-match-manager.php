<?php
/**
 * Plugin Name: Soccer Match Manager
 * Plugin URI: https://yourwebsite.com
 * Description: Manage soccer matches with player attendance and conflict detection
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: soccer-match-manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SMM_VERSION', '1.0.0');
define('SMM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SMM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once SMM_PLUGIN_DIR . 'includes/class-smm-database.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-admin.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-shortcode.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-conflict-checker.php';

// Activation hook
register_activation_hook(__FILE__, array('SMM_Database', 'activate'));

// Initialize plugin
add_action('plugins_loaded', 'smm_init_plugin');

function smm_init_plugin() {
    // Initialize admin
    if (is_admin()) {
        new SMM_Admin();
    }
    
    // Initialize shortcode
    new SMM_Shortcode();
}