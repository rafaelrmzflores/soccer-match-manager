<?php
class SMM_Players {

    public static function get_all($only_active = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_players';
        $sql = "SELECT * FROM $table";
        if ($only_active) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY player_name ASC";
        return $wpdb->get_results($sql);
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_players WHERE id = %d", $id
        ));
    }

    public static function add($name, $email = '') {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;

        return $wpdb->insert($wpdb->prefix . 'soccer_players', array(
            'player_name' => $name,
            'player_email' => sanitize_email($email),
            'is_active' => 1
        ));
    }

    public static function update($id, $name, $email, $is_active) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_players',
            array(
                'player_name' => sanitize_text_field($name),
                'player_email' => sanitize_email($email),
                'is_active' => $is_active ? 1 : 0
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