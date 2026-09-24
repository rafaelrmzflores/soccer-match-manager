<?php
class SMM_Competitions {

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}soccer_competitions 
             ORDER BY season DESC, competition_name ASC"
        );
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_competitions WHERE id = %d", $id
        ));
    }

    public static function get_name($id) {
        $c = self::get($id);
        return $c ? $c->competition_name : '';
    }

    public static function get_by_name($name) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_competitions WHERE competition_name = %s",
            $name
        ));
    }

    /**
     * Return a display label including season if present.
     * e.g. "Fall League (2025)"
     */
    public static function get_label($id) {
        $c = self::get($id);
        if (!$c) return '';
        return $c->season
            ? sprintf('%s (%s)', $c->competition_name, $c->season)
            : $c->competition_name;
    }

    public static function add($name, $short_label = '', $season = '', $age_group = '', $color = '#0d6efd', $notes = '') {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;

        return $wpdb->insert($wpdb->prefix . 'soccer_competitions', array(
            'competition_name' => $name,
            'short_label'      => sanitize_text_field($short_label),
            'season'           => sanitize_text_field($season),
            'age_group'        => sanitize_text_field($age_group),
            'color'            => self::sanitize_color($color),
            'notes'            => sanitize_textarea_field($notes),
        ));
    }

    public static function update($id, $name, $short_label, $season, $age_group, $color, $notes) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_competitions',
            array(
                'competition_name' => sanitize_text_field($name),
                'short_label'      => sanitize_text_field($short_label),
                'season'           => sanitize_text_field($season),
                'age_group'        => sanitize_text_field($age_group),
                'color'            => self::sanitize_color($color),
                'notes'            => sanitize_textarea_field($notes),
            ),
            array('id' => intval($id))
        );
    }

    public static function delete($id) {
        global $wpdb;
        $id = intval($id);

        // Detach matches — keep the free-text name so nothing breaks
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}soccer_matches SET competition_id = 0 WHERE competition_id = %d",
            $id
        ));

        return $wpdb->delete(
            $wpdb->prefix . 'soccer_competitions',
            array('id' => $id),
            array('%d')
        );
    }

    /** Number of matches currently assigned to a competition. */
    public static function count_matches($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}soccer_matches WHERE competition_id = %d",
            intval($id)
        ));
    }

    public static function sanitize_color($color) {
        $color = trim((string) $color);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) return strtolower($color);
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $color))  return strtolower($color);
        return '#0d6efd';
    }

    /** Small colored badge for a competition (used in lists). */
    public static function badge_html($id) {
        $c = self::get($id);
        if (!$c) return '';
        $label = $c->short_label ?: $c->competition_name;
        return sprintf(
            '<span class="smm-comp-badge" style="background:%s" title="%s">%s</span>',
            esc_attr($c->color),
            esc_attr($c->competition_name . ($c->season ? ' (' . $c->season . ')' : '')),
            esc_html($label)
        );
    }
}