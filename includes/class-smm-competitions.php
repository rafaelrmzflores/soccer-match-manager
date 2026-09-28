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

    /**
     * Returns competitions grouped by league, ordered for display:
     *   1. Leagues sorted alphabetically
     *   2. Competitions under each league, sorted alphabetically
     *   3. A trailing "Cross-league" group for competitions with no league
     *
     * Each entry: ['id' => int, 'label' => string, 'league_id' => int, 'computed_duration' => int]
     * Structure: [
     *   'Fall 2025 U12 South' => [
     *      ['id' => 1, 'label' => 'Regular Season', 'league_id' => 3, 'computed_duration' => 75],
     *      ...
     *   ],
     *   'Cross-league' => [ ... ],
     * ]
     */
    public static function get_grouped_by_league() {
        $comps = self::get_all();
        $grouped = array();

        foreach ($comps as $c) {
            $key = $c->league_id ? SMM_Leagues::get_label($c->league_id) : 'Cross-league';
            if (!$key) $key = 'Cross-league';

            $label = $c->competition_name;
            if ($c->season && !$c->league_id) {
                // For cross-league comps, include season for disambiguation
                $label .= ' (' . $c->season . ')';
            }

            $grouped[$key][] = array(
                'id'                => intval($c->id),
                'label'             => $label,
                'league_id'         => intval($c->league_id),
                'computed_duration' => self::compute_duration_from_row($c),
            );
        }

        // Sort groups: real leagues alphabetically, cross-league last
        uksort($grouped, function($a, $b) {
            if ($a === 'Cross-league') return 1;
            if ($b === 'Cross-league') return -1;
            return strcasecmp($a, $b);
        });

        // Sort inside each group alphabetically by label
        foreach ($grouped as &$items) {
            usort($items, function($a, $b) {
                return strcasecmp($a['label'], $b['label']);
            });
        }
        unset($items);

        return $grouped;
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

    /**
     * Compute total match duration from a competition's period config.
     *
     * Formula:
     *   total = (periods × period_minutes) + halftime + (periods × break_minutes)
     *
     * Where:
     *   - periods × period_minutes = actual playing time
     *   - halftime = the long break at the midpoint
     *   - periods × break_minutes = short breaks added within each period
     *     (water breaks in halves; between-quarter breaks in quarters)
     *
     * Returns null for missing competition; 0 for competitions with no
     * config set (shouldn't happen after migration).
     */
    public static function compute_duration_from_row($c) {
        if (!$c) return null;

        $periods    = max(1, intval($c->periods ?? 2));
        $period_min = max(1, intval($c->period_minutes ?? 45));
        $break_min  = max(0, intval($c->break_minutes ?? 0));
        $half_min   = max(0, intval($c->halftime_minutes ?? 0));

        return ($periods * $period_min) + $half_min + ($periods * $break_min);
    }

    public static function sanitize_color($color) {
        $color = trim((string) $color);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) return strtolower($color);
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $color))  return strtolower($color);
        return '#0d6efd';
    }

    public static function badge_html($id, $size = array(40, 40)) {
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