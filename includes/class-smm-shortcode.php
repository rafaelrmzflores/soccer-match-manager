<?php
class SMM_Shortcode {

    public function __construct() {
        add_shortcode('soccer_matches', array($this, 'display_matches'));
        add_shortcode('soccer_conflicts', array($this, 'display_conflicts'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
    }

    public function assets() {
        wp_enqueue_style('smm-frontend', SMM_PLUGIN_URL . 'assets/frontend.css', array(), SMM_VERSION);
    }

    public function display_matches($atts) {
        $atts = shortcode_atts(array(
            'limit' => -1,
            'show_conflicts' => 'yes',
            'show_past' => 'no'
        ), $atts);

        $where = $atts['show_past'] === 'no' ? 'match_date >= CURDATE()' : '';
        $matches = SMM_Database::get_matches(array('limit' => $atts['limit'], 'where' => $where));

        if (empty($matches)) return '<p>No upcoming matches scheduled.</p>';

        $checker = new SMM_Conflict_Checker();

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
                        <?php if ($atts['show_conflicts'] === 'yes'): ?><th>Status</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $m):
                        $conflicts = $checker->check_match_conflicts($m->id);
                        $attendance = SMM_Database::get_attendance($m->id);
                        $names = wp_list_pluck($attendance, 'player_name');
                        ?>
                        <tr class="<?php echo !empty($conflicts) ? 'smm-conflict-row' : ''; ?>">
                            <td><?php echo date('M j, Y', strtotime($m->match_date)); ?></td>
                            <td><?php echo date('g:i A', strtotime($m->match_time)); ?></td>
                            <td><?php echo esc_html($m->home_team . ' vs ' . $m->away_team); ?></td>
                            <td><?php echo esc_html($m->location); ?></td>
                            <td class="smm-players">
                                <?php echo $names ? esc_html(implode(', ', $names)) : '—'; ?>
                            </td>
                            <?php if ($atts['show_conflicts'] === 'yes'): ?>
                                <td>
                                    <?php if (!empty($conflicts)): ?>
                                        <span class="smm-conflict-badge"
                                              title="<?php echo esc_attr(implode("\n", $conflicts)); ?>">
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

    public function display_conflicts($atts) {
        $checker = new SMM_Conflict_Checker();
        $all = $checker->get_all_conflicts();

        if (empty($all)) {
            return '<p class="smm-no-conflicts">✓ No conflicts detected in the schedule.</p>';
        }

        ob_start();
        ?>
        <div class="smm-conflicts-container">
            <h3>⚠️ Schedule Conflicts Detected</h3>
            <?php foreach ($all as $data): ?>
                <div class="smm-conflict-item">
                    <h4><?php echo esc_html($data['match']->home_team . ' vs ' . $data['match']->away_team); ?></h4>
                    <p><strong>Date:</strong> <?php echo date('M j, Y', strtotime($data['match']->match_date)); ?></p>
                    <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($data['match']->match_time)); ?></p>
                    <p><strong>Location:</strong> <?php echo esc_html($data['match']->location); ?></p>
                    <ul>
                        <?php foreach ($data['conflicts'] as $c): ?>
                            <li><?php echo esc_html($c); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}