<?php
class SMM_Conflict_Checker {

    /**
     * Detect conflicts for a match.
     *
     * A conflict exists between two matches on the same date that share at
     * least one attending player if EITHER:
     *
     *   1. Their time slots overlap (start .. start + duration).
     *   2. They are at DIFFERENT locations, and the gap between one ending and
     *      the other starting is less than estimated travel time + the two
     *      locations' travel buffers.
     *
     * Same-location matches only conflict on time overlap — no buffer
     * required between back-to-back games at the same venue.
     *
     * If duration is not set, a 2-hour default slot is assumed.
     */
    public function check_match_conflicts($match_id) {
        global $wpdb;
        $matches_table = $wpdb->prefix . 'soccer_matches';

        $match = SMM_Database::get_match($match_id);
        if (!$match) return array();

        $my_players = array_map('intval', SMM_Database::get_attending_player_ids($match_id));
        if (empty($my_players)) return array();

        $others = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $matches_table WHERE match_date = %s AND id != %d",
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
                date('g:i A', strtotime($other->match_time)));
            $shared_txt = implode(', ', $names);

            // --- Time slots ---
            $dur_a = !empty($match->match_duration) ? intval($match->match_duration) : 120;
            $dur_b = !empty($other->match_duration) ? intval($other->match_duration) : 120;

            $start_a = strtotime($match->match_date . ' ' . $match->match_time);
            $end_a   = $start_a + ($dur_a * 60);

            $start_b = strtotime($other->match_date . ' ' . $other->match_time);
            $end_b   = $start_b + ($dur_b * 60);

            // --- Rule 1: time overlap ---
            if (($start_a < $end_b) && ($start_b < $end_a)) {
                $conflicts[] = sprintf(
                    'Time overlap with %s — shared: %s',
                    $label, $shared_txt
                );
                continue;
            }

            // --- Rule 2: travel-gap check (different locations only) ---
            $la = $match->location_id ? SMM_Locations::get($match->location_id) : null;
            $lb = $other->location_id ? SMM_Locations::get($other->location_id) : null;

            // Determine if same location
            $same_location = false;
            if ($la && $lb && $la->id === $lb->id) {
                $same_location = true;
            } elseif (!empty($match->location) && !empty($other->location)
                      && strcasecmp(trim($match->location), trim($other->location)) === 0) {
                $same_location = true;
            }

            // Same location → no buffer needed. Player can go straight from one to the next.
            if ($same_location) {
                continue;
            }

            // Different locations with coordinates → travel check
            if ($la && $lb && $la->latitude !== null && $lb->latitude !== null) {
                $gap_min = ($start_a >= $end_b)
                    ? ($start_a - $end_b) / 60
                    : ($start_b - $end_a) / 60;

                $km = SMM_Locations::distance_km(
                    (float) $la->latitude, (float) $la->longitude,
                    (float) $lb->latitude, (float) $lb->longitude
                );

                $travel_min = ($km / 40) * 60 + 5; // 40 km/h avg + 5 min overhead
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
            $c = $this->check_match_conflicts($m->id);
            if (!empty($c)) {
                $all[$m->id] = array('match' => $m, 'conflicts' => $c);
            }
        }
        return $all;
    }
}