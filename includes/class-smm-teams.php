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
}