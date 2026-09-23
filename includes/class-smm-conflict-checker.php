<?php
class SMM_Conflict_Checker {

    /**
     * Conflict rules:
     *  1. Time overlap (uses match_duration or 2h default)
     *  2. Different locations only: gap < travel_time + buffer_a + buffer_b
     *     (same location → no buffer needed)
     *
     * Only matches with status 'scheduled' or 'confirmed' participate.
     * Only players whose availability is 'available' or 'maybe' count.
     */
    public function check_match_conflicts($match_id) {
        global $wpdb;
        $matches_table = $wpdb->prefix . 'soccer_matches';

        $match = SMM_Database::get_match($match_id);
        if (!$match) return array();

        // Canceled/postponed matches never report conflicts
        if (!SMM_Helpers::is_conflict_relevant($match->status)) return array();

        $my_players = array_map('intval', SMM_Database::get_attending_player_ids($match_id));
        if (empty($my_players)) return array();

        $others = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $matches_table 
             WHERE match_date = %s 
               AND id != %d
               AND status IN ('scheduled','confirmed')",
            $match->match_date, $match_id
        ));

        $conflicts = array();

        foreach ($others as $other) {
            $other_players = array_map('intval', SMM_Database::get_attending_player_ids($other->id));
            $shared = array_intersect($my_players, $other_players);
            if (empty($shared)) continue;

            $names = array();
            foreach ($shared as $pid) {
                $p = SMM_Players::get($pid);
                if ($p) $names[] = $p->player_name;
            }

            $home = $other->home_team_id ? SMM_Teams::get_name($other->home_team_id) : $other->home_team;
            $away = $other->away_team_id ? SMM_Teams::get_name($other->away_team_id) : $other->away_team;
            $label = sprintf('%s vs %s at %s', $home, $away,
                SMM_Helpers::fmt_time($other->match_time));
            $shared_txt = implode(', ', $names);

            // Time slots — use site tz via helpers
            $dur_a = !empty($match->match_duration) ? intval($match->match_duration) : 120;
            $dur_b = !empty($other->match_duration) ? intval($other->match_duration) : 120;

            $start_a = SMM_Helpers::to_ts($match->match_date, $match->match_time);
            $end_a   = $start_a + ($dur_a * 60);
            $start_b = SMM_Helpers::to_ts($other->match_date, $other->match_time);
            $end_b   = $start_b + ($dur_b * 60);

            // Rule 1: time overlap
            if (($start_a < $end_b) && ($start_b < $end_a)) {
                $conflicts[] = sprintf(
                    'Time overlap with %s — shared: %s',
                    $label, $shared_txt
                );
                continue;
            }

            // Determine if same location
            $la = $match->location_id ? SMM_Locations::get($match->location_id) : null;
            $lb = $other->location_id ? SMM_Locations::get($other->location_id) : null;

            $same_location = false;
            if ($la && $lb && $la->id === $lb->id) {
                $same_location = true;
            } elseif (!empty($match->location) && !empty($other->location)
                      && strcasecmp(trim($match->location), trim($other->location)) === 0) {
                $same_location = true;
            }

            // Same location → no buffer needed
            if ($same_location) continue;

            // Different locations with coordinates → travel check
            if ($la && $lb && $la->latitude !== null && $lb->latitude !== null) {
                $gap_min = ($start_a >= $end_b)
                    ? ($start_a - $end_b) / 60
                    : ($start_b - $end_a) / 60;

                $km = SMM_Locations::distance_km(
                    (float) $la->latitude, (float) $la->longitude,
                    (float) $lb->latitude, (float) $lb->longitude
                );

                $travel_min = ($km / 40) * 60 + 5;
                $buffer = intval($la->travel_buffer_minutes) + intval($lb->travel_buffer_minutes);
                $needed = $travel_min + $buffer;

                if ($gap_min < $needed) {
                    $conflicts[] = sprintf(
                        'Travel conflict with %s (%.1f km apart, ~%d min needed incl. buffers, only %d min gap) — shared: %s',
                        $label, $km, (int) ceil($needed), (int) floor($gap_min), $shared_txt
                    );
                }
            }
        }

        return $conflicts;
    }

    public function get_all_conflicts() {
        $matches = SMM_Database::get_matches();
        $all = array();
        foreach ($matches as $m) {
            if (!SMM_Helpers::is_conflict_relevant($m->status)) continue;
            $c = $this->check_match_conflicts($m->id);
            if (!empty($c)) {
                $all[$m->id] = array('match' => $m, 'conflicts' => $c);
            }
        }
        return $all;
    }

    public function count_conflicts() {
        return count($this->get_all_conflicts());
    }
}