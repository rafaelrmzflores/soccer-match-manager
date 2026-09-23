<?php
class SMM_Database {

    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $matches    = $wpdb->prefix . 'soccer_matches';
        $players    = $wpdb->prefix . 'soccer_players';
        $teams      = $wpdb->prefix . 'soccer_teams';
        $locations  = $wpdb->prefix . 'soccer_locations';
        $attendance = $wpdb->prefix . 'soccer_attendance';

        $sql_teams = "CREATE TABLE $teams (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            team_name varchar(150) NOT NULL,
            team_logo_id bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY team_name (team_name)
        ) $charset_collate;";

        $sql_players = "CREATE TABLE $players (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            player_name varchar(100) NOT NULL,
            player_email varchar(150) DEFAULT '',
            team_id mediumint(9) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY player_name (player_name),
            KEY team_id (team_id)
        ) $charset_collate;";

        $sql_locations = "CREATE TABLE $locations (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            location_name varchar(200) NOT NULL,
            location_address varchar(255) DEFAULT '',
            latitude decimal(10,7) DEFAULT NULL,
            longitude decimal(10,7) DEFAULT NULL,
            travel_buffer_minutes smallint DEFAULT 30,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY location_name (location_name)
        ) $charset_collate;";

        $sql_matches = "CREATE TABLE $matches (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            match_date date NOT NULL,
            match_time time NOT NULL,
            match_duration smallint DEFAULT NULL,
            home_team_id mediumint(9) DEFAULT 0,
            away_team_id mediumint(9) DEFAULT 0,
            home_team varchar(150) DEFAULT '',
            away_team varchar(150) DEFAULT '',
            location_id mediumint(9) DEFAULT 0,
            location varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY match_date (match_date),
            KEY location_id (location_id)
        ) $charset_collate;";

        $sql_attendance = "CREATE TABLE $attendance (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            match_id mediumint(9) NOT NULL,
            player_id mediumint(9) NOT NULL,
            attending tinyint(1) DEFAULT 1,
            auto_added tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY match_player (match_id, player_id),
            KEY match_id (match_id),
            KEY player_id (player_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_teams);
        dbDelta($sql_players);
        dbDelta($sql_locations);
        dbDelta($sql_matches);
        dbDelta($sql_attendance);

        update_option('smm_db_version', SMM_VERSION);
    }

    /* ---------- Matches ---------- */

    public static function get_matches($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_matches';

        $defaults = array('orderby' => 'match_date', 'order' => 'ASC', 'limit' => -1, 'where' => '');
        $args = wp_parse_args($args, $defaults);

        $sql = "SELECT * FROM $table";
        if (!empty($args['where'])) $sql .= " WHERE " . $args['where'];
        $sql .= " ORDER BY {$args['orderby']} {$args['order']}";
        if ($args['limit'] > 0) $sql .= " LIMIT " . intval($args['limit']);

        return $wpdb->get_results($sql);
    }

    public static function get_match($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_matches WHERE id = %d", $id
        ));
    }

    public static function insert_match($data) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'soccer_matches', $data);
        return $wpdb->insert_id;
    }

    public static function update_match($id, $data) {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'soccer_matches', $data, array('id' => $id));
    }

    public static function delete_match($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'soccer_attendance', array('match_id' => $id), array('%d'));
        return $wpdb->delete($wpdb->prefix . 'soccer_matches', array('id' => $id), array('%d'));
    }

    /* ---------- Attendance ---------- */

    public static function get_attendance($match_id) {
        global $wpdb;
        $att = $wpdb->prefix . 'soccer_attendance';
        $pl  = $wpdb->prefix . 'soccer_players';
        $tm  = $wpdb->prefix . 'soccer_teams';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.player_name, p.team_id, t.team_name, t.team_logo_id
             FROM $att a
             JOIN $pl p ON p.id = a.player_id
             LEFT JOIN $tm t ON t.id = p.team_id
             WHERE a.match_id = %d", $match_id
        ));
    }

    public static function get_attending_player_ids($match_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT player_id FROM {$wpdb->prefix}soccer_attendance
             WHERE match_id = %d AND attending = 1", $match_id
        ));
    }

    /**
     * Save attendance. $player_ids is a flat array of manually-selected player IDs.
     * Auto-added players (based on teams) are stored too, flagged auto_added=1.
     */
    public static function set_attendance($match_id, $player_ids, $auto_player_ids = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_attendance';

        $wpdb->delete($table, array('match_id' => $match_id), array('%d'));

        $manual = array_map('intval', $player_ids);
        $auto   = array_map('intval', $auto_player_ids);

        // Manual entries first (auto_added = 0)
        foreach (array_unique($manual) as $pid) {
            $wpdb->insert($table, array(
                'match_id'   => intval($match_id),
                'player_id'  => $pid,
                'attending'  => 1,
                'auto_added' => 0,
            ));
        }

        // Auto entries not already added manually
        foreach (array_unique($auto) as $pid) {
            if (in_array($pid, $manual, true)) continue;
            $wpdb->insert($table, array(
                'match_id'   => intval($match_id),
                'player_id'  => $pid,
                'attending'  => 1,
                'auto_added' => 1,
            ));
        }
    }
}