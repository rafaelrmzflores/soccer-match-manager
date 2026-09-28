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
     * Competitions that can be selected when a specific league is chosen.
     * Includes: competitions belonging to that league PLUS cross-league
     * competitions (league_id = 0).
     */
    public static function get_for_league($league_id) {
        global $wpdb;
        $league_id = intval($league_id);
        if (!$league_id) {
            return self::get_all();
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_competitions
             WHERE league_id = %d OR league_id = 0
             ORDER BY (league_id = 0) ASC, competition_name ASC",
            $league_id
        ));
    }

    public static function add($name, $short_label = '', $season = '', $age_group = '',
                               $color = '#0d6efd', $notes = '', $league_id = 0,
                               $periods = 2, $period_minutes = 45, $break_minutes = 5,
                               $halftime_minutes = 15, $water_break_minutes = 0) {
        global $wpdb;
        $name = sanitize_text_field($name);
        if (empty($name)) return false;

        return $wpdb->insert($wpdb->prefix . 'soccer_competitions', array(
            'competition_name'     => $name,
            'short_label'          => sanitize_text_field($short_label),
            'season'               => sanitize_text_field($season),
            'age_group'            => sanitize_text_field($age_group),
            'color'                => self::sanitize_color($color),
            'notes'                => sanitize_textarea_field($notes),
            'league_id'            => intval($league_id),
            'periods'              => max(1, intval($periods)),
            'period_minutes'       => max(1, intval($period_minutes)),
            'break_minutes'        => max(0, intval($break_minutes)),
            'halftime_minutes'     => max(0, intval($halftime_minutes)),
            'water_break_minutes'  => max(0, intval($water_break_minutes)),
        ));
    }

    public static function update($id, $name, $short_label, $season, $age_group, $color, $notes,
                                  $league_id = 0, $periods = 2, $period_minutes = 45,
                                  $break_minutes = 5, $halftime_minutes = 15,
                                  $water_break_minutes = 0) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'soccer_competitions',
            array(
                'competition_name'     => sanitize_text_field($name),
                'short_label'          => sanitize_text_field($short_label),
                'season'               => sanitize_text_field($season),
                'age_group'            => sanitize_text_field($age_group),
                'color'                => self::sanitize_color($color),
                'notes'                => sanitize_textarea_field($notes),
                'league_id'            => intval($league_id),
                'periods'              => max(1, intval($periods)),
                'period_minutes'       => max(1, intval($period_minutes)),
                'break_minutes'        => max(0, intval($break_minutes)),
                'halftime_minutes'     => max(0, intval($halftime_minutes)),
                'water_break_minutes'  => max(0, intval($water_break_minutes)),
            ),
            array('id' => intval($id))
        );
    }

    public static function delete($id) {
        global $wpdb;
        $id = intval($id);
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

    public static function count_matches($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}soccer_matches WHERE competition_id = %d",
            intval($id)
        ));
    }

    /**
     * Compute the total duration in minutes from a competition's period config.
     *
     * Halves (periods = 2):
     *   total = 2 × period_minutes + halftime_minutes + (2 × water_break_minutes)
     *
     * Quarters (periods = 4):
     *   total = 4 × period_minutes + (2 × break_minutes) + halftime_minutes
     *
     * Other period counts: generalized
     *   total = N × period_minutes + (N - 2) × break_minutes + halftime_minutes
     *
     * Returns null if the competition has no period config (shouldn't happen,
     * but defensive for legacy data before migration ran).
     */
    public static function compute_duration($id) {
        $c = self::get($id);
        if (!$c) return null;
        return self::compute_duration_from_row($c);
    }

    public static function compute_duration_from_row($c) {
        if (!$c) return null;
        $periods    = max(1, intval($c->periods ?? 2));
        $period_min = max(1, intval($c->period_minutes ?? 45));
        $break_min  = max(0, intval($c->break_minutes ?? 0));
        $half_min   = max(0, intval($c->halftime_minutes ?? 0));
        $water_min  = max(0, intval($c->water_break_minutes ?? 0));

        if ($periods === 2) {
            // Halves: 1 halftime + 2 possible water breaks
            return ($periods * $period_min) + $half_min + ($periods * $water_min);
        }

        // Quarters or general: (N - 2) short breaks + 1 halftime
        $short_breaks = max(0, $periods - 2);
        return ($periods * $period_min) + ($short_breaks * $break_min) + $half_min;
    }

    public static function sanitize_color($color) {
        $color = trim((string) $color);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) return strtolower($color);
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $color))  return strtolower($color);
        return '#0d6efd';
    }

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