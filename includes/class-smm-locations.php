<?php
class SMM_Locations {

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}soccer_locations ORDER BY location_name ASC"
        );
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_locations WHERE id = %d", $id
        ));
    }

    public static function get_name($id) {
        $l = self::get($id);
        return $l ? $l->location_name : '';
    }

    public static function add($name, $address = '', $lat = null, $lng = null, $buffer = 30) {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;
        return $wpdb->insert($wpdb->prefix . 'soccer_locations', array(
            'location_name'          => $name,
            'location_address'       => sanitize_text_field($address),
            'latitude'               => ($lat !== null && $lat !== '') ? floatval($lat) : null,
            'longitude'              => ($lng !== null && $lng !== '') ? floatval($lng) : null,
            'travel_buffer_minutes'  => intval($buffer)
        ));
    }

    public static function update($id, $name, $address, $lat, $lng, $buffer) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_locations',
            array(
                'location_name'          => sanitize_text_field($name),
                'location_address'       => sanitize_text_field($address),
                'latitude'               => ($lat !== null && $lat !== '') ? floatval($lat) : null,
                'longitude'              => ($lng !== null && $lng !== '') ? floatval($lng) : null,
                'travel_buffer_minutes'  => intval($buffer)
            ),
            array('id' => intval($id))
        );
    }

    public static function delete($id) {
        global $wpdb;
        $id = intval($id);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}soccer_matches SET location_id = 0 WHERE location_id = %d", $id
        ));
        return $wpdb->delete($wpdb->prefix . 'soccer_locations', array('id' => $id), array('%d'));
    }

    public static function distance_km($lat1, $lng1, $lat2, $lng2) {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }
}