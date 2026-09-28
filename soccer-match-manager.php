<?php
/**
 * Plugin Name: Soccer Match Manager
 * Description: Manage soccer matches, teams, players, locations, leagues, and competitions with conflict detection
 * Version: 7.0.0
 * Author: Rafael Ramírez
 * License: GPL v2 or later
 * Text Domain: soccer-match-manager
 */

if (!defined('ABSPATH')) exit;

define('SMM_VERSION', '7.1.0');
define('SMM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SMM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SMM_DB_VERSION', '7.1.0');

require_once SMM_PLUGIN_DIR . 'includes/class-smm-database.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-helpers.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-leagues.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-teams.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-players.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-locations.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-competitions.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-admin.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-csv.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-csv-entities.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-shortcode.php';
require_once SMM_PLUGIN_DIR . 'includes/class-smm-conflict-checker.php';

register_activation_hook(__FILE__, array('SMM_Database', 'activate'));

add_action('plugins_loaded', 'smm_maybe_upgrade');
function smm_maybe_upgrade() {
    if (get_option('smm_db_version') !== SMM_DB_VERSION) {
        SMM_Database::activate();
        update_option('smm_db_version', SMM_DB_VERSION);
    }
}

add_action('plugins_loaded', 'smm_init_plugin');
function smm_init_plugin() {
    if (is_admin()) {
        new SMM_Admin();
    }
    new SMM_Shortcode();
}