<?php
class SMM_Database {

    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $matches      = $wpdb->prefix . 'soccer_matches';
        $players      = $wpdb->prefix . 'soccer_players';
        $teams        = $wpdb->prefix . 'soccer_teams';
        $locations    = $wpdb->prefix . 'soccer_locations';
        $attendance   = $wpdb->prefix . 'soccer_attendance';
        $competitions = $wpdb->prefix . 'soccer_competitions';

        $sql_competitions = "CREATE TABLE $competitions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            competition_name varchar(150) NOT NULL,
            short_label varchar(50) DEFAULT '',
            season varchar(50) DEFAULT '',
            age_group varchar(50) DEFAULT '',
            color varchar(7) DEFAULT '#0d6efd',
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY competition_name (competition_name)
        ) $charset_collate;";

        $sql_teams = "CREATE TABLE $teams (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            team_name varchar(150) NOT NULL,
            team_logo_id bigint(20) DEFAULT 0,
            default_duration smallint DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY team_name (team_name)
        ) $charset_collate;";

        $sql_players = "CREATE TABLE $players (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            player_name varchar(100) NOT NULL,
            player_email varchar(150) DEFAULT '',
            team_id mediumint(9) DEFAULT 0,
            availability varchar(20) DEFAULT 'available',
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
            status varchar(20) DEFAULT 'scheduled',
            competition_id mediumint(9) DEFAULT 0,
            competition varchar(150) DEFAULT '',
            round varchar(50) DEFAULT '',
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY match_date (match_date),
            KEY location_id (location_id),
            KEY status (status),
            KEY competition_id (competition_id)
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
        dbDelta($sql_competitions);
        dbDelta($sql_teams);
        dbDelta($sql_players);
        dbDelta($sql_locations);
        dbDelta($sql_matches);
        dbDelta($sql_attendance);

        // Backfill defaults for older rows
        $wpdb->query("UPDATE $matches SET status = 'scheduled' WHERE status IS NULL OR status = ''");
        $wpdb->query("UPDATE $players SET availability = 'available' WHERE availability IS NULL OR availability = ''");

        // Migrate legacy free-text competition names into the competitions table
        self::migrate_legacy_competitions();
    }

    /**
     * One-time: for any existing match with a non-empty `competition` string
     * but no `competition_id`, create/find the competition and link it.
     */
    private static function migrate_legacy_competitions() {
        global $wpdb;
        $matches = $wpdb->prefix . 'soccer_matches';
        $comps   = $wpdb->prefix . 'soccer_competitions';

        $rows = $wpdb->get_results(
            "SELECT DISTINCT competition FROM $matches 
             WHERE competition IS NOT NULL 
               AND competition != '' 
               AND (competition_id IS NULL OR competition_id = 0)"
        );
        if (!$rows) return;

        foreach ($rows as $r) {
            $name = trim($r->competition);
            if (!$name) continue;

            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $comps WHERE competition_name = %s", $name
            ));
            if (!$id) {
                $wpdb->insert($comps, array(
                    'competition_name' => sanitize_text_field($name),
                ));
                $id = $wpdb->insert_id;
            }
            if ($id) {
                $wpdb->update(
                    $matches,
                    array('competition_id' => intval($id)),
                    array('competition' => $name),
                    array('%d'),
                    array('%s')
                );
            }
        }
    }

    /* ---------- Matches ---------- */

        public static function get_matches($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_matches';

        $defaults = array(
            'orderby' => 'match_date, match_time',
            'order'   => 'ASC',
            'limit' => -1,
            'where' => '',
            'search' => '',
            'date_from' => '',
            'date_to' => '',
            'team_id' => 0,
            'location_id' => 0,
            'status' => '',
            'competition_id' => 0,
        );
        $args = wp_parse_args($args, $defaults);

        $conds = array();
        $params = array();

        if (!empty($args['where'])) {
            $conds[] = '(' . $args['where'] . ')';
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $conds[] = '(home_team LIKE %s OR away_team LIKE %s OR competition LIKE %s OR location LIKE %s OR notes LIKE %s OR round LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if (!empty($args['date_from'])) {
            $conds[] = 'match_date >= %s';
            $params[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $conds[] = 'match_date <= %s';
            $params[] = $args['date_to'];
        }
        if (!empty($args['team_id'])) {
            $conds[] = '(home_team_id = %d OR away_team_id = %d)';
            $params[] = intval($args['team_id']); $params[] = intval($args['team_id']);
        }
        if (!empty($args['location_id'])) {
            $conds[] = 'location_id = %d';
            $params[] = intval($args['location_id']);
        }
        if (!empty($args['status'])) {
            $conds[] = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['competition_id'])) {
            $conds[] = 'competition_id = %d';
            $params[] = intval($args['competition_id']);
        }

                // --- Safe multi-column ORDER BY ---
        $allowed_cols = array(
            'id', 'match_date', 'match_time', 'match_duration',
            'home_team', 'away_team', 'location', 'status',
            'competition', 'round', 'created_at',
        );
        $order = (strtoupper($args['order']) === 'DESC') ? 'DESC' : 'ASC';

        $requested = array();
        foreach (array_map('trim', explode(',', $args['orderby'])) as $col) {
            if (in_array($col, $allowed_cols, true)) {
                $requested[] = $col;
            }
        }
        if (empty($requested)) {
            $requested = array('match_date', 'match_time');
        }

        // Always keep chronological as secondary sort (unless we're already
        // sorting by date/time explicitly)
        if (!in_array('match_date', $requested, true) && $requested !== array('id')) {
            $requested[] = 'match_date';
        }
        if (!in_array('match_time', $requested, true)
            && in_array('match_date', $requested, true)
            && !in_array('match_time', $requested, true)) {
            $requested[] = 'match_time';
        }

        // De-duplicate while preserving order
        $requested = array_values(array_unique($requested));

        $orderby_parts = array();
        foreach ($requested as $col) {
            $orderby_parts[] = $col . ' ' . $order;
        }

        $sql = "SELECT * FROM $table";
        if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);
        if ($params) $sql = $wpdb->prepare($sql, $params);
        $sql .= ' ORDER BY ' . implode(', ', $orderby_parts);
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
            "SELECT a.*, p.player_name, p.team_id, p.availability, t.team_name, t.team_logo_id
             FROM $att a
             JOIN $pl p ON p.id = a.player_id
             LEFT JOIN $tm t ON t.id = p.team_id
             WHERE a.match_id = %d", $match_id
        ));
    }

    public static function get_attending_player_ids($match_id) {
        global $wpdb;
        $att = $wpdb->prefix . 'soccer_attendance';
        $pl  = $wpdb->prefix . 'soccer_players';

        return $wpdb->get_col($wpdb->prepare(
            "SELECT a.player_id 
             FROM $att a
             JOIN $pl p ON p.id = a.player_id
             WHERE a.match_id = %d 
               AND a.attending = 1
               AND p.availability IN ('available','maybe')", $match_id
        ));
    }

    public static function set_attendance($match_id, $player_ids, $auto_player_ids = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_attendance';

        $wpdb->delete($table, array('match_id' => $match_id), array('%d'));

        $manual = array_map('intval', $player_ids);
        $auto   = array_map('intval', $auto_player_ids);

        foreach (array_unique($manual) as $pid) {
            $wpdb->insert($table, array(
                'match_id'   => intval($match_id),
                'player_id'  => $pid,
                'attending'  => 1,
                'auto_added' => 0,
            ));
        }
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