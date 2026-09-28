<?php
class SMM_Conflict_Checker {

    /**
     * Detect conflicts for a match. Returns an array of structured
     * conflict records. Matches without a time set are skipped entirely
     * (they're treated as "TBD" and cannot be evaluated for overlap or travel).
     */
    public function check_match_conflicts($match_id) {
        global $wpdb;
        $matches_table = $wpdb->prefix . 'soccer_matches';

        $match = SMM_Database::get_match($match_id);
        if (!$match) return array();

        // A match with no time can't participate in conflict detection.
        if (empty($match->match_time)) return array();

        if (!SMM_Helpers::is_conflict_relevant($match->status)) return array();

        $my_players = array_map('intval', SMM_Database::get_attending_player_ids($match_id));
        if (empty($my_players)) return array();

        $others = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $matches_table 
             WHERE match_date = %s 
               AND id != %d
               AND status IN ('scheduled','confirmed')
               AND match_time IS NOT NULL",
            $match->match_date, $match_id
        ));

        $conflicts = array();

        foreach ($others as $other) {
            // Defensive — the SQL above excludes nulls, but double-check.
            if (empty($other->match_time)) continue;

            $other_players = array_map('intval', SMM_Database::get_attending_player_ids($other->id));
            $shared = array_intersect($my_players, $other_players);
            if (empty($shared)) continue;

            $names = array();
            foreach ($shared as $pid) {
                $p = SMM_Players::get($pid);
                if ($p) $names[] = $p->player_name;
            }
            $shared_txt = implode(', ', $names);

            $home = $other->home_team_id ? SMM_Teams::get_name($other->home_team_id) : $other->home_team;
            $away = $other->away_team_id ? SMM_Teams::get_name($other->away_team_id) : $other->away_team;
            $label = sprintf('%s vs %s at %s', $home, $away,
                SMM_Helpers::fmt_time($other->match_time));

            $dur_a = !empty($match->match_duration) ? intval($match->match_duration) : 120;
            $dur_b = !empty($other->match_duration) ? intval($other->match_duration) : 120;

            $start_a = SMM_Helpers::to_ts($match->match_date, $match->match_time);
            $end_a   = $start_a + ($dur_a * 60);
            $start_b = SMM_Helpers::to_ts($other->match_date, $other->match_time);
            $end_b   = $start_b + ($dur_b * 60);

            if ($start_a === null || $start_b === null) continue;

            // Rule 1: time overlap
            if (($start_a < $end_b) && ($start_b < $end_a)) {
                $conflicts[] = array(
                    'other_id' => intval($other->id),
                    'type'     => 'time_overlap',
                    'message'  => sprintf('Time overlap with %s — shared: %s', $label, $shared_txt),
                    'shared'   => $shared_txt,
                );
                continue;
            }

            $la = $match->location_id ? SMM_Locations::get($match->location_id) : null;
            $lb = $other->location_id ? SMM_Locations::get($other->location_id) : null;

            $same_location = false;
            if ($la && $lb && $la->id === $lb->id) {
                $same_location = true;
            } elseif (!empty($match->location) && !empty($other->location)
                      && strcasecmp(trim($match->location), trim($other->location)) === 0) {
                $same_location = true;
            }

            if ($same_location) continue;

            if ($la && $lb && $la->latitude !== null && $lb->latitude !== null) {
                $gap_min = ($start_a >= $end_b)
                    ? ($start_a - $end_b) / 60
                    : ($start_b - $end_a) / 60;

                $km = SMM_Locations::distance_km(
                    (float) $la->latitude, (float) $la->longitude,
                    (float) $lb->latitude, (float) $lb->longitude
                );

                $speed = SMM_Helpers::travel_speed_kmh();
                $travel_min = ($km / max(5, $speed)) * 60 + 5;

                $destination = ($start_a >= $end_b) ? $lb : $la;
                $dest_buffer = $destination ? intval($destination->travel_buffer_minutes) : 0;
                $needed = $travel_min + $dest_buffer;

                if ($gap_min < $needed) {
                    $earlier = ($start_a < $start_b) ? $la : $lb;
                    $later   = ($start_a < $start_b) ? $lb : $la;

                    $conflicts[] = array(
                        'other_id'    => intval($other->id),
                        'type'        => 'travel',
                        'message'     => sprintf(
                            'Travel conflict with %s (%.1f km apart, ~%d min needed incl. buffer, only %d min gap) — shared: %s',
                            $label, $km, (int) ceil($needed), (int) floor($gap_min), $shared_txt
                        ),
                        'shared'      => $shared_txt,
                        'maps_url'    => self::build_maps_url($earlier, $later),
                        'distance_km' => round($km, 1),
                        'gap_min'     => (int) floor($gap_min),
                        'needed_min'  => (int) ceil($needed),
                    );
                }
            }
        }

        return $conflicts;
    }

    public static function build_maps_url($loc_a, $loc_b) {
        if (!$loc_a || !$loc_b) return '';
        $origin      = self::location_query($loc_a);
        $destination = self::location_query($loc_b);
        if (!$origin || !$destination) return '';

        return add_query_arg(
            array(
                'api'         => '1',
                'origin'      => $origin,
                'destination' => $destination,
                'travelmode'  => 'driving',
            ),
            'https://www.google.com/maps/dir/'
        );
    }

    private static function location_query($loc) {
        if ($loc->latitude !== null && $loc->longitude !== null) {
            return $loc->latitude . ',' . $loc->longitude;
        }
        if (!empty($loc->location_address)) {
            return $loc->location_address;
        }
        return '';
    }

    public function get_all_conflicts() {
        $matches = SMM_Database::get_matches(array(
            'orderby' => 'match_date, match_time',
            'order'   => 'ASC',
        ));

        $seen_pairs = array();
        $all = array();

        foreach ($matches as $m) {
            if (!SMM_Helpers::is_conflict_relevant($m->status)) continue;
            if (empty($m->match_time)) continue;

            $conflicts = $this->check_match_conflicts($m->id);
            if (empty($conflicts)) continue;

            $filtered = array();

            foreach ($conflicts as $c) {
                $other = $this->find_conflicting_match($m, $c['message']);
                if (!$other) {
                    $filtered[] = $c;
                    continue;
                }

                $pair_key = ($m->id < $other->id)
                    ? $m->id . '-' . $other->id
                    : $other->id . '-' . $m->id;

                if (isset($seen_pairs[$pair_key])) continue;
                $seen_pairs[$pair_key] = true;
                $filtered[] = $c;
            }

            if (!empty($filtered)) {
                $all[$m->id] = array(
                    'match'     => $m,
                    'conflicts' => $filtered,
                );
            }
        }

        return $all;
    }

    private function find_conflicting_match($match, $conflict_string) {
        global $wpdb;
        $matches_table = $wpdb->prefix . 'soccer_matches';

        $others = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $matches_table 
             WHERE match_date = %s 
               AND id != %d
               AND status IN ('scheduled','confirmed')
               AND match_time IS NOT NULL",
            $match->match_date, $match->id
        ));

        foreach ($others as $other) {
            $home = $other->home_team_id ? SMM_Teams::get_name($other->home_team_id) : $other->home_team;
            $away = $other->away_team_id ? SMM_Teams::get_name($other->away_team_id) : $other->away_team;
            $label_home = $home . ' vs ' . $away;

            if (strpos($conflict_string, $label_home) !== false) {
                return $other;
            }
        }
        return null;
    }

    public function get_conflicts_by_player() {
        $all = $this->get_all_conflicts();
        $by_player = array();

        foreach ($all as $data) {
            $match = $data['match'];
            foreach ($data['conflicts'] as $c) {
                if (is_string($c)) {
                    $c = array('message' => $c, 'shared' => '', 'maps_url' => '', 'type' => '');
                }
                $shared = trim($c['shared'] ?? '');
                if (!$shared) continue;

                $names = array_map('trim', explode(',', $shared));
                foreach ($names as $name) {
                    if (!$name) continue;
                    if (!isset($by_player[$name])) {
                        $by_player[$name] = array(
                            'player_name' => $name,
                            'count'       => 0,
                            'conflicts'   => array(),
                        );
                    }
                    $by_player[$name]['conflicts'][] = array(
                        'match'    => $match,
                        'message'  => $c['message'] ?? '',
                        'maps_url' => $c['maps_url'] ?? '',
                        'type'     => $c['type'] ?? '',
                    );
                    $by_player[$name]['count']++;
                }
            }
        }

        uasort($by_player, function($a, $b) {
            if ($a['count'] === $b['count']) {
                return strcasecmp($a['player_name'], $b['player_name']);
            }
            return $b['count'] - $a['count'];
        });

        return $by_player;
    }

    public function count_conflicts() {
        return count($this->get_all_conflicts());
    }
}