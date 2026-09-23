<?php
class SMM_Players {

    public static function get_all($only_active = false) {
        global $wpdb;
        $sql = "SELECT p.*, t.team_name 
                FROM {$wpdb->prefix}soccer_players p
                LEFT JOIN {$wpdb->prefix}soccer_teams t ON t.id = p.team_id";
        if ($only_active) $sql .= " WHERE p.is_active = 1";
        $sql .= " ORDER BY p.player_name ASC";
        return $wpdb->get_results($sql);
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_players WHERE id = %d", $id
        ));
    }

    public static function get_by_team($team_id, $only_active = true) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_players WHERE team_id = %d", $team_id
        );
        if ($only_active) $sql .= " AND is_active = 1";
        $sql .= " ORDER BY player_name ASC";
        return $wpdb->get_results($sql);
    }

    public static function add($name, $email = '', $team_id = 0) {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;
        return $wpdb->insert($wpdb->prefix . 'soccer_players', array(
            'player_name'  => $name,
            'player_email' => sanitize_email($email),
            'team_id'      => intval($team_id),
            'is_active'    => 1
        ));
    }

    public static function update($id, $name, $email, $team_id, $is_active) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_players',
            array(
                'player_name'  => sanitize_text_field($name),
                'player_email' => sanitize_email($email),
                'team_id'      => intval($team_id),
                'is_active'    => $is_active ? 1 : 0
            ),
            array('id' => intval($id))
        );
    }

    public static function delete($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'soccer_attendance', array('player_id' => $id), array('%d'));
        return $wpdb->delete($wpdb->prefix . 'soccer_players', array('id' => $id), array('%d'));
    }
}