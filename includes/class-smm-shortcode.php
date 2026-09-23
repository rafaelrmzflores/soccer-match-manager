<?php
class SMM_Shortcode {
    
    public function __construct() {
        add_shortcode('soccer_matches', array($this, 'display_matches'));
        add_shortcode('soccer_conflicts', array($this, 'display_conflicts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }
    
    public function enqueue_frontend_assets() {
        wp_enqueue_style('smm-frontend', SMM_PLUGIN_URL . 'assets/frontend.css', array(), SMM_VERSION);
    }
    
    /**
     * Display matches shortcode
     * Usage: [soccer_matches limit="10" show_conflicts="yes"]
     */
    public function display_matches($atts) {
        $atts = shortcode_atts(array(
            'limit' => -1,
            'show_conflicts' => 'yes',
            'show_past' => 'no'
        ), $atts);
        
        $where = '';
        if ($atts['show_past'] === 'no') {
            $where = "match_date >= CURDATE()";
        }
        
        $matches = SMM_Database::get_matches(array(
            'limit' => $atts['limit'],
            'where' => $where
        ));
        
        if (empty($matches)) {
            return '<p>No upcoming matches scheduled.</p>';
        }
        
        $conflict_checker = new SMM_Conflict_Checker();
        
        ob_start();
        ?>
        <div class="smm-matches-container">
            <table class="smm-matches-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Match</th>
                        <th>Location</th>
                        <th>Players</th>
                        <?php if ($atts['show_conflicts'] === 'yes'): ?>
                            <th>Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $match): ?>
                        <?php 
                        $conflicts = $conflict_checker->check_match_conflicts($match->id);
                        $has_conflict = !empty($conflicts);
                        ?>
                        <tr class="<?php echo $has_conflict ? 'smm-conflict-row' : ''; ?>">
                            <td><?php echo date('M j, Y', strtotime($match->match_date)); ?></td>
                            <td><?php echo date('g:i A', strtotime($match->match_time)); ?></td>
                            <td><?php echo esc_html($match->home_team . ' vs ' . $match->away_team); ?></td>
                            <td><?php echo esc_html($match->location); ?></td>
                            <td class="smm-players">
                                <?php 
                                $attending = array();
                                if ($match->player1_attending) $attending[] = 'Player 1';
                                if ($match->player2_attending) $attending[] = 'Player 2';
                                if ($match->player3_attending) $attending[] = 'Player 3';
                                echo !empty($attending) ? implode(', ', $attending) : 'None';
                                ?>
                            </td>
                            <?php if ($atts['show_conflicts'] === 'yes'): ?>
                                <td class="smm-status">
                                    <?php if ($has_conflict): ?>
                                        <span class="smm-conflict-badge" title="<?php echo esc_attr(implode('; ', $conflicts)); ?>">
                                            ⚠️ Conflict
                                        </span>
                                    <?php else: ?>
                                        <span class="smm-ok-badge">✓ OK</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Display only conflicts shortcode
     * Usage: [soccer_conflicts]
     */
    public function display_conflicts($atts) {
        $conflict_checker = new SMM_Conflict_Checker();
        $all_conflicts = $conflict_checker->get_all_conflicts();
        
        if (empty($all_conflicts)) {
            return '<p class="smm-no-conflicts">✓ No conflicts detected in the schedule.</p>';
        }
        
        ob_start();
        ?>
        <div class="smm-conflicts-container">
            <h3>⚠️ Schedule Conflicts Detected</h3>
            <?php foreach ($all_conflicts as $match_id => $data): ?>
                <div class="smm-conflict-item">
                    <h4><?php echo esc_html($data['match']->home_team . ' vs ' . $data['match']->away_team); ?></h4>
                    <p><strong>Date:</strong> <?php echo date('M j, Y', strtotime($data['match']->match_date)); ?></p>
                    <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($data['match']->match_time)); ?></p>
                    <p><strong>Location:</strong> <?php echo esc_html($data['match']->location); ?></p>
                    <ul>
                        <?php foreach ($data['conflicts'] as $conflict): ?>
                            <li><?php echo esc_html($conflict); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}