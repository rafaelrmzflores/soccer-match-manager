<?php
class SMM_Database {
    
    public static function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'soccer_matches';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            match_date date NOT NULL,
            match_time time NOT NULL,
            home_team varchar(100) NOT NULL,
            away_team varchar(100) NOT NULL,
            location varchar(255) NOT NULL,
            player1_attending tinyint(1) DEFAULT 0,
            player2_attending tinyint(1) DEFAULT 0,
            player3_attending tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY match_date (match_date),
            KEY location (location)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add version option
        add_option('smm_db_version', SMM_VERSION);
    }
    
    public static function get_matches($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'soccer_matches';
        
        $defaults = array(
            'orderby' => 'match_date',
            'order' => 'ASC',
            'limit' => -1,
            'where' => ''
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $sql = "SELECT * FROM $table_name";
        
        if (!empty($args['where'])) {
            $sql .= " WHERE " . $args['where'];
        }
        
        $sql .= " ORDER BY {$args['orderby']} {$args['order']}";
        
        if ($args['limit'] > 0) {
            $sql .= " LIMIT {$args['limit']}";
        }
        
        return $wpdb->get_results($sql);
    }
    
    public static function get_match($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'soccer_matches';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ));
    }
    
    public static function insert_match($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'soccer_matches';
        
        $defaults = array(
            'match_date' => '',
            'match_time' => '',
            'home_team' => '',
            'away_team' => '',
            'location' => '',
            'player1_attending' => 0,
            'player2_attending' => 0,
            'player3_attending' => 0
        );
        
        $data = wp_parse_args($data, $defaults);
        
        return $wpdb->insert($table_name, $data);
    }
    
    public static function update_match($id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'soccer_matches';
        
        return $wpdb->update($table_name, $data, array('id' => $id));
    }
    
    public static function delete_match($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'soccer_matches';
        
        return $wpdb->delete($table_name, array('id' => $id), array('%d'));
    }
}