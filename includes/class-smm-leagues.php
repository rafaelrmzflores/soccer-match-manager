<?php
class SMM_Leagues {

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}soccer_leagues 
             ORDER BY season DESC, league_name ASC"
        );
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_leagues WHERE id = %d", $id
        ));
    }

    public static function get_name($id) {
        $l = self::get($id);
        return $l ? $l->league_name : '';
    }

    public static function get_by_name($name) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_leagues WHERE league_name = %s",
            $name
        ));
    }

    public static function get_label($id) {
        $l = self::get($id);
        if (!$l) return '';
        return $l->season
            ? sprintf('%s (%s)', $l->league_name, $l->season)
            : $l->league_name;
    }

    public static function add($name, $season = '', $age_group = '', $color = '#0d6efd', $notes = '') {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;

        return $wpdb->insert($wpdb->prefix . 'soccer_leagues', array(
            'league_name' => $name,
            'season'      => sanitize_text_field($season),
            'age_group'   => sanitize_text_field($age_group),
            'color'       => self::sanitize_color($color),
            'notes'       => sanitize_textarea_field($notes),
        ));
    }

    public static function update($id, $name, $season, $age_group, $color, $notes) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_leagues',
            array(
                'league_name' => sanitize_text_field($name),
                'season'      => sanitize_text_field($season),
                'age_group'   => sanitize_text_field($age_group),
                'color'       => self::sanitize_color($color),
                'notes'       => sanitize_textarea_field($notes),
            ),
            array('id' => intval($id))
        );
    }

    public static function delete($id) {
        global $wpdb;
        $id = intval($id);

        // Detach teams and competitions
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}soccer_teams SET league_id = 0 WHERE league_id = %d", $id
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}soccer_competitions SET league_id = 0 WHERE league_id = %d", $id
        ));

        return $wpdb->delete(
            $wpdb->prefix . 'soccer_leagues',
            array('id' => $id),
            array('%d')
        );
    }

    public static function count_teams($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}soccer_teams WHERE league_id = %d",
            intval($id)
        ));
    }

    public static function count_competitions($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}soccer_competitions WHERE league_id = %d",
            intval($id)
        ));
    }

    public static function sanitize_color($color) {
        $color = trim((string) $color);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) return strtolower($color);
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $color))  return strtolower($color);
        return '#0d6efd';
    }

    public static function badge_html($id) {
        $l = self::get($id);
        if (!$l) return '';
        return sprintf(
            '<span class="smm-league-badge" style="background:%s" title="%s">%s</span>',
            esc_attr($l->color),
            esc_attr($l->league_name . ($l->season ? ' (' . $l->season . ')' : '')),
            esc_html($l->league_name)
        );
    }
}