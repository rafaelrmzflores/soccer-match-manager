<?php
/**
 * Plugin Name: Soccer Match Manager
 * Description: Manage soccer matches, teams, players, locations, leagues, and competitions with conflict detection
 * Version: 7.5.5
 * Author: Rafael Ramírez
 * License: GPL v2 or later
 * Text Domain: soccer-match-manager
 */

if (!defined('ABSPATH')) exit;

define('SMM_VERSION', '7.5.5');
define('SMM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SMM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SMM_DB_VERSION', '7.5.5');

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

// --- Migration ------------------------------------------------------------
add_action('plugins_loaded', 'smm_maybe_upgrade', 5);
function smm_maybe_upgrade() {
    $stored = get_option('smm_db_version');
    if ($stored !== SMM_DB_VERSION) {
        SMM_Database::activate();
        update_option('smm_db_version', SMM_DB_VERSION);
        error_log('[SMM] Migrated db version from ' . var_export($stored, true) . ' to ' . SMM_DB_VERSION);
    }
}

// --- Plugin bootstrap -----------------------------------------------------
add_action('plugins_loaded', 'smm_init_plugin', 10);
function smm_init_plugin() {
    if (is_admin()) {
        new SMM_Admin();
    }
    new SMM_Shortcode();
}

// --- TEMP DEBUG: show schema + version state in admin ---------------------
// REMOVE THIS ENTIRE BLOCK once the migration is confirmed working.
// add_action('admin_init', 'smm_debug_schema_init');
// function smm_debug_schema_init() {
//     add_action('admin_notices', 'smm_debug_schema_notice');
//     // Also dump to the PHP error log so you have a fallback
//     global $wpdb;
//     $table = $wpdb->prefix . 'soccer_matches';
//     $col = $wpdb->get_row($wpdb->prepare(
//         "SHOW COLUMNS FROM `$table` LIKE %s", 'match_time'
//     ));
//     error_log(sprintf(
//         '[SMM] match_time — Null: %s, Default: %s, option: %s, constant: %s',
//         $col ? $col->Null : '?',
//         $col ? var_export($col->Default, true) : '?',
//         get_option('smm_db_version', '(not set)'),
//         SMM_DB_VERSION
//     ));
// }

function smm_debug_schema_notice() {
    if (!current_user_can('manage_options')) return;
    if (!current_user_can('administrator')) return;

    global $wpdb;
    $table = $wpdb->prefix . 'soccer_matches';
    $col = $wpdb->get_row($wpdb->prepare(
        "SHOW COLUMNS FROM `$table` LIKE %s", 'match_time'
    ));

    $msg = 'SMM debug — match_time: ';
    if ($col) {
        $msg .= 'Type=' . $col->Type
              . ' Null=' . $col->Null
              . ' Default=' . var_export($col->Default, true);
    } else {
        $msg .= 'COLUMN NOT FOUND';
    }
    $msg .= ' | option=' . get_option('smm_db_version', '(not set)')
          . ' | constant=' . SMM_DB_VERSION;
    ?>
    <div class="notice notice-success" style="font-size:14px;">
        <p><strong><?php echo esc_html($msg); ?></strong></p>
    </div>
    <?php
}