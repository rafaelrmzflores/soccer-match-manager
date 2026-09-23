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

    public static function add($name, $logo_id = 0) {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;
        return $wpdb->insert($wpdb->prefix . 'soccer_teams', array(
            'team_name'    => $name,
            'team_logo_id' => intval($logo_id)
        ));
    }

    public static function update($id, $name, $logo_id) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_teams',
            array(
                'team_name'    => sanitize_text_field($name),
                'team_logo_id' => intval($logo_id)
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
            'class' => $class,
            'alt'   => esc_attr($team->team_name)
        ));
        return $img ?: '';
    }
}