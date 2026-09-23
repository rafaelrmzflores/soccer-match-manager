<?php
class SMM_Database {

    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $matches_table = $wpdb->prefix . 'soccer_matches';
        $players_table = $wpdb->prefix . 'soccer_players';
        $attendance_table = $wpdb->prefix . 'soccer_attendance';

        $sql1 = "CREATE TABLE $matches_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            match_date date NOT NULL,
            match_time time NOT NULL,
            home_team varchar(100) NOT NULL,
            away_team varchar(100) NOT NULL,
            location varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY match_date (match_date),
            KEY location (location)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $players_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            player_name varchar(100) NOT NULL,
            player_email varchar(150) DEFAULT '',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY player_name (player_name)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE $attendance_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            match_id mediumint(9) NOT NULL,
            player_id mediumint(9) NOT NULL,
            attending tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY match_player (match_id, player_id),
            KEY match_id (match_id),
            KEY player_id (player_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        add_option('smm_db_version', SMM_VERSION);
    }

    /* ---------- Match helpers ---------- */

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

    /* ---------- Attendance helpers ---------- */

    public static function get_attendance($match_id) {
        global $wpdb;
        $att_table = $wpdb->prefix . 'soccer_attendance';
        $players_table = $wpdb->prefix . 'soccer_players';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.player_name 
             FROM $att_table a
             JOIN $players_table p ON p.id = a.player_id
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

    public static function set_attendance($match_id, $player_ids) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_attendance';

        // Clear existing
        $wpdb->delete($table, array('match_id' => $match_id), array('%d'));

        if (empty($player_ids)) return;

        foreach ($player_ids as $pid) {
            $wpdb->insert($table, array(
                'match_id' => intval($match_id),
                'player_id' => intval($pid),
                'attending' => 1
            ));
        }
    }
}