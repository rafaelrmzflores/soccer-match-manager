<?php
/**
 * Plugin Name: Soccer Match Manager
 * Description: Manage soccer matches, teams, players, and locations with conflict detection
 * Version: 4.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: soccer-match-manager
 */

if (!defined('ABSPATH')) exit;

define('SMM_VERSION', '4.0.0');
define('SMM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SMM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SMM_PLUGIN_DIR . 'includes/class-smm-database.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-teams.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-players.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-locations.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-admin.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-shortcode.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-conflict-checker.php';

register_activation_hook(__FILE__, array('SMM_Database', 'activate'));

add_action('plugins_loaded', 'smm_init_plugin');

function smm_init_plugin() {
    if (is_admin()) {
        new SMM_Admin();
    }
    new SMM_Shortcode();
}