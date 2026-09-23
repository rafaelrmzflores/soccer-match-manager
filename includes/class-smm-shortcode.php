<?php
class SMM_Shortcode {

    public function __construct() {
        add_shortcode('soccer_matches', array($this, 'display_matches'));
        add_shortcode('soccer_conflicts', array($this, 'display_conflicts'));
        add_shortcode('soccer_teams', array($this, 'display_teams'));
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
                <thead><tr>
                    <th>Date</th><th>Time</th><th>Home</th><th>Away</th>
                    <th>Location</th><th>Players</th>
                    <?php if ($atts['show_conflicts'] === 'yes'): ?><th>Status</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($matches as $m):
                    $conflicts = $checker->check_match_conflicts($m->id);
                    $attendance = SMM_Database::get_attendance($m->id);

                    $home_team = $m->home_team_id ? SMM_Teams::get($m->home_team_id) : null;
                    $away_team = $m->away_team_id ? SMM_Teams::get($m->away_team_id) : null;
                    $home_name = $home_team ? $home_team->team_name : $m->home_team;
                    $away_name = $away_team ? $away_team->team_name : $m->away_team;
                    $loc = $m->location_id ? SMM_Locations::get($m->location_id) : null;
                    ?>
                    <tr class="<?php echo !empty($conflicts) ? 'smm-conflict-row' : ''; ?>">
                        <td><?php echo date('M j, Y', strtotime($m->match_date)); ?></td>
                        <td>
                            <?php echo date('g:i A', strtotime($m->match_time)); ?>
                            <?php if ($m->match_duration): ?>
                                <small>(<?php echo intval($m->match_duration); ?>min)</small>
                            <?php endif; ?>
                        </td>
                        <td class="smm-team-cell">
                            <?php echo $home_team ? SMM_Teams::get_logo_html($home_team, 'thumbnail') : ''; ?>
                            <span><?php echo esc_html($home_name); ?></span>
                        </td>
                        <td class="smm-team-cell">
                            <?php echo $away_team ? SMM_Teams::get_logo_html($away_team, 'thumbnail') : ''; ?>
                            <span><?php echo esc_html($away_name); ?></span>
                        </td>
                        <td><?php echo esc_html($loc ? $loc->location_name : $m->location); ?></td>
                        <td class="smm-players">
                            <?php if (empty($attendance)): ?>—
                            <?php else: foreach ($attendance as $a):
                                $logo = '';
                                if (!empty($a->team_logo_id)) {
                                    $logo = wp_get_attachment_image($a->team_logo_id, array(20,20), false,
                                        array('class'=>'smm-player-mini-logo'));
                                }
                                ?>
                                <span class="smm-attendee"><?php echo $logo; ?><?php echo esc_html($a->player_name); ?></span>
                            <?php endforeach; endif; ?>
                        </td>
                        <?php if ($atts['show_conflicts'] === 'yes'): ?>
                            <td>
                                <?php if (!empty($conflicts)): ?>
                                    <span class="smm-conflict-badge"
                                          title="<?php echo esc_attr(implode("\n", $conflicts)); ?>">⚠️</span>
                                <?php else: ?>
                                    <span class="smm-ok-badge">✓</span>
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
            <?php foreach ($all as $data):
                $m = $data['match'];
                $home = $m->home_team_id ? SMM_Teams::get_name($m->home_team_id) : $m->home_team;
                $away = $m->away_team_id ? SMM_Teams::get_name($m->away_team_id) : $m->away_team;
                $loc = $m->location_id ? SMM_Locations::get_name($m->location_id) : $m->location;
                ?>
                <div class="smm-conflict-item">
                    <h4><?php echo esc_html($home . ' vs ' . $away); ?></h4>
                    <p><strong>Date:</strong> <?php echo date('M j, Y', strtotime($m->match_date)); ?></p>
                    <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($m->match_time)); ?>
                        <?php if ($m->match_duration) echo '(' . intval($m->match_duration) . ' min)'; ?></p>
                    <p><strong>Location:</strong> <?php echo esc_html($loc); ?></p>
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

    public function display_teams($atts) {
        $teams = SMM_Teams::get_all();
        if (empty($teams)) return '<p>No teams added yet.</p>';

        ob_start();
        ?>
        <div class="smm-teams-grid">
            <?php foreach ($teams as $t): ?>
                <div class="smm-team-card">
                    <?php echo SMM_Teams::get_logo_html($t, 'medium', 'smm-team-card-logo'); ?>
                    <h4><?php echo esc_html($t->team_name); ?></h4>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}