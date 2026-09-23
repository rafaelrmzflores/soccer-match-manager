<?php
class SMM_Conflict_Checker {

    /**
     * Detect conflicts for a match. Two matches conflict if:
     * - Same date, and their times are within 2 hours, and they share at least one attending player
     * - Same date, same location, and they share at least one attending player
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

            // Get player names for the shared ids
            $names = array();
            foreach ($shared as $pid) {
                $p = SMM_Players::get($pid);
                if ($p) $names[] = $p->player_name;
            }

            $time1 = strtotime($match->match_time);
            $time2 = strtotime($other->match_time);
            $hours_apart = abs($time1 - $time2) / 3600;

            $same_location = (strcasecmp(trim($match->location), trim($other->location)) === 0);

            if ($hours_apart < 2) {
                $conflicts[] = sprintf(
                    'Time clash (%s vs %s at %s) — shared: %s',
                    $other->home_team, $other->away_team,
                    date('g:i A', strtotime($other->match_time)),
                    implode(', ', $names)
                );
            } elseif ($same_location) {
                $conflicts[] = sprintf(
                    'Same location (%s) as %s vs %s at %s — shared: %s',
                    $other->location,
                    $other->home_team, $other->away_team,
                    date('g:i A', strtotime($other->match_time)),
                    implode(', ', $names)
                );
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