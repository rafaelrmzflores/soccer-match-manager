<?php
class SMM_Teams {

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}soccer_teams ORDER BY team_name ASC"
        );
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_teams WHERE id = %d", $id
        ));
    }

    public static function get_name($id) {
        $t = self::get($id);
        return $t ? $t->team_name : '';
    }

    /**
     * Get teams that belong to a given league.
     */
    public static function get_by_league($league_id) {
        global $wpdb;
        $league_id = intval($league_id);
        if (!$league_id) return self::get_all();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_teams
             WHERE league_id = %d
             ORDER BY team_name ASC",
            $league_id
        ));
    }

    /**
     * Teams that have at least one active player ("my teams").
     * Returns an array keyed by team_id for quick lookups.
     */
    public static function get_teams_with_active_players() {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT DISTINCT p.team_id
             FROM {$wpdb->prefix}soccer_players p
             WHERE p.is_active = 1 AND p.team_id > 0"
        );
        $out = array();
        foreach ($rows as $tid) $out[intval($tid)] = true;
        return $out;
    }

    public static function add($name, $logo_id = 0, $default_duration = null, $league_id = 0) {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;
        return $wpdb->insert($wpdb->prefix . 'soccer_teams', array(
            'team_name'        => $name,
            'team_logo_id'     => intval($logo_id),
            'default_duration' => ($default_duration !== null && $default_duration !== '')
                                    ? intval($default_duration) : null,
            'league_id'        => intval($league_id),
        ));
    }

    public static function update($id, $name, $logo_id, $default_duration = null, $league_id = 0) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_teams',
            array(
                'team_name'        => sanitize_text_field($name),
                'team_logo_id'     => intval($logo_id),
                'default_duration' => ($default_duration !== null && $default_duration !== '')
                                        ? intval($default_duration) : null,
                'league_id'        => intval($league_id),
            ),
            array('id' => intval($id))
        );
    }

    public static function delete($id) {
        global $wpdb;
        $id = intval($id);
        $wpdb->delete($wpdb->prefix . 'soccer_team_locations', array('team_id' => $id), array('%d'));
        $wpdb->update($wpdb->prefix . 'soccer_players',
            array('team_id' => 0), array('team_id' => $id));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}soccer_matches SET home_team_id = 0 WHERE home_team_id = %d", $id));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}soccer_matches SET away_team_id = 0 WHERE away_team_id = %d", $id));
        return $wpdb->delete($wpdb->prefix . 'soccer_teams', array('id' => $id), array('%d'));
    }

    public static function get_logo_html($team, $size = 'thumbnail', $class = 'smm-team-logo') {
        if (!$team || empty($team->team_logo_id)) return '';
        $img = wp_get_attachment_image($team->team_logo_id, $size, false, array(
            'class' => $class, 'alt' => esc_attr($team->team_name)
        ));
        return $img ?: '';
    }

        /**
     * Returns all locations associated with a team, primary first.
     * Each row is joined with the location record for convenience.
     */
    public static function get_venues($team_id) {
        global $wpdb;
        $tl = $wpdb->prefix . 'soccer_team_locations';
        $loc = $wpdb->prefix . 'soccer_locations';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT tl.id AS link_id, tl.location_id, tl.is_primary,
                    l.location_name, l.location_address
             FROM $tl tl
             JOIN $loc l ON l.id = tl.location_id
             WHERE tl.team_id = %d
             ORDER BY tl.is_primary DESC, l.location_name ASC",
            intval($team_id)
        ));
    }

    /**
     * Returns the primary location_id for a team, or 0 if none.
     * Falls back to the first venue if none is flagged primary.
     */
    public static function get_primary_location_id($team_id) {
        global $wpdb;
        $tl = $wpdb->prefix . 'soccer_team_locations';
        $loc = $wpdb->prefix . 'soccer_locations';

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT tl.location_id 
             FROM $tl tl
             JOIN $loc l ON l.id = tl.location_id
             WHERE tl.team_id = %d 
             ORDER BY tl.is_primary DESC, l.location_name ASC
             LIMIT 1",
            intval($team_id)
        ));
        return $id ? intval($id) : 0;
    }

    /**
     * Replaces the venues for a team.
     * $venues is an array of ['location_id' => int, 'is_primary' => bool].
     */
    public static function set_venues($team_id, $venues) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_team_locations';
        $team_id = intval($team_id);

        $wpdb->delete($table, array('team_id' => $team_id), array('%d'));

        if (empty($venues)) return;

        // Ensure at most one is marked primary
        $has_primary = false;
        foreach ($venues as $v) {
            if (!empty($v['is_primary'])) { $has_primary = true; break; }
        }

        $first = true;
        foreach ($venues as $v) {
            $loc_id = intval($v['location_id']);
            if (!$loc_id) continue;

            $is_primary = 0;
            if (!empty($v['is_primary'])) {
                $is_primary = 1;
            } elseif (!$has_primary && $first) {
                // Fallback: first row becomes primary if none flagged
                $is_primary = 1;
            }
            $first = false;

            $wpdb->insert($table, array(
                'team_id'     => $team_id,
                'location_id' => $loc_id,
                'is_primary'  => $is_primary,
            ));
        }
    }

    /**
     * Map: team_id → primary location_id. Used by the match form JS.
     */
    public static function get_primary_location_map() {
        global $wpdb;
        $tl = $wpdb->prefix . 'soccer_team_locations';

        $rows = $wpdb->get_results(
            "SELECT team_id, location_id, is_primary 
             FROM $tl 
             ORDER BY is_primary DESC, location_id ASC"
        );
        $map = array();
        foreach ($rows as $r) {
            $tid = intval($r->team_id);
            // First row per team wins (primary sorts first)
            if (!isset($map[$tid])) {
                $map[$tid] = intval($r->location_id);
            }
        }
        return $map;
    }
}