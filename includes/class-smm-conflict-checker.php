<?php
class SMM_Conflict_Checker {
    
    /**
     * Check for conflicts with a specific match
     */
    public function check_match_conflicts($match_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'soccer_matches';
        
        $match = SMM_Database::get_match($match_id);
        if (!$match) {
            return array();
        }
        
        $conflicts = array();
        
        // Check for time/location conflicts on the same date
        $same_day_matches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE match_date = %s 
             AND id != %d",
            $match->match_date,
            $match_id
        ));
        
        foreach ($same_day_matches as $other_match) {
            // Check if any players are attending both matches
            $shared_players = array();
            
            if ($match->player1_attending && $other_match->player1_attending) {
                $shared_players[] = 'Player 1';
            }
            if ($match->player2_attending && $other_match->player2_attending) {
                $shared_players[] = 'Player 2';
            }
            if ($match->player3_attending && $other_match->player3_attending) {
                $shared_players[] = 'Player 3';
            }
            
            if (!empty($shared_players)) {
                // Check time conflict (within 2 hours)
                $time1 = strtotime($match->match_time);
                $time2 = strtotime($other_match->match_time);
                $time_diff = abs($time1 - $time2) / 3600; // Difference in hours
                
                // Check location conflict
                $same_location = ($match->location === $other_match->location);
                
                if ($time_diff < 2) {
                    $conflicts[] = sprintf(
                        'Time conflict with %s vs %s at %s (Players: %s)',
                        $other_match->home_team,
                        $other_match->away_team,
                        $other_match->match_time,
                        implode(', ', $shared_players)
                    );
                } elseif ($same_location) {
                    $conflicts[] = sprintf(
                        'Same location conflict with %s vs %s at %s (Players: %s)',
                        $other_match->home_team,
                        $other_match->away_team,
                        $other_match->match_time,
                        implode(', ', $shared_players)
                    );
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Get all conflicts across all matches
     */
    public function get_all_conflicts() {
        $matches = SMM_Database::get_matches();
        $all_conflicts = array();
        
        foreach ($matches as $match) {
            $conflicts = $this->check_match_conflicts($match->id);
            if (!empty($conflicts)) {
                $all_conflicts[$match->id] = array(
                    'match' => $match,
                    'conflicts' => $conflicts
                );
            }
        }
        
        return $all_conflicts;
    }
}