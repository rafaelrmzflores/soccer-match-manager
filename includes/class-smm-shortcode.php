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
            'show_past' => 'no',
            'status' => '', // comma-separated list to include, e.g. "scheduled,confirmed"
            'competition' => '',   // slug or name (matches competition_name), comma-separated
            'competition_id' => 0, // explicit ID, takes precedence
        ), $atts);

        $where_parts = array();
        if ($atts['show_past'] === 'no') {
            $where_parts[] = "match_date >= '" . esc_sql(SMM_Helpers::today_ymd()) . "'";
        }
        if (!empty($atts['status'])) {
            $statuses = array_map('sanitize_key', explode(',', $atts['status']));
            $statuses = array_intersect($statuses, array_keys(SMM_Helpers::statuses()));
            if ($statuses) {
                $where_parts[] = "status IN ('" . implode("','", array_map('esc_sql', $statuses)) . "')";
            }
        }

        $competition_id = 0;
        if (!empty($atts['competition_id'])) {
            $competition_id = intval($atts['competition_id']);
        } elseif (!empty($atts['competition'])) {
            global $wpdb;
            $names = array_map('trim', explode(',', $atts['competition']));
            $placeholders = implode(',', array_fill(0, count($names), '%s'));
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}soccer_competitions
                 WHERE competition_name IN ($placeholders)", $names
            ));
            if ($ids) {
                $where_parts[] = "competition_id IN (" . implode(',', array_map('intval', $ids)) . ")";
            }
        }
        if ($competition_id) {
            $where_parts[] = "competition_id = " . intval($competition_id);
        }

        $matches = SMM_Database::get_matches(array(
            'limit' => $atts['limit'],
            'where' => implode(' AND ', $where_parts),
        ));

        if (empty($matches)) return '<p>No upcoming matches scheduled.</p>';

        $checker = new SMM_Conflict_Checker();

        ob_start();
        ?>
        <div class="smm-matches-container">
            <table class="smm-matches-table">
                <thead><tr>
                    <th>Date</th><th>Time</th><th>Home</th><th>Away</th>
                    <th>Competition</th>
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
                    $is_canceled = in_array($m->status, array('canceled'), true);
                    ?>
                    <tr class="<?php echo $is_canceled ? 'smm-canceled-row' : (!empty($conflicts) ? 'smm-conflict-row' : ''); ?>">
                        <td><?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?></td>
                        <td>
                            <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
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

                        <!-- <td>
                            <?php echo esc_html($m->competition); ?>
                            
                            <?php if ($m->round): ?>
                                <small><?php echo esc_html($m->round); ?></small>
                            <?php endif; ?>
                        </td> -->

                        <td>
                            <?php if ($m->competition_id): ?>
                                <?php echo SMM_Competitions::badge_html($m->competition_id); ?>
                            <?php else: ?>
                                <?php echo esc_html($m->competition); ?>
                            <?php endif; ?>
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
                                $avail = $a->availability ?? 'available';
                                ?>
                                <span class="smm-attendee smm-avail-<?php echo esc_attr($avail); ?>"
                                      title="<?php echo esc_attr(SMM_Helpers::availabilities()[$avail] ?? $avail); ?>">
                                    <?php echo $logo; ?><?php echo esc_html($a->player_name); ?>
                                </span>
                            <?php endforeach; endif; ?>
                        </td>
                        <?php if ($atts['show_conflicts'] === 'yes'): ?>
                            <td>
                                <?php echo SMM_Helpers::status_badge($m->status); ?>
                                <?php if (!empty($conflicts)): ?>
                                    <span class="smm-conflict-badge"
                                          title="<?php echo esc_attr(implode("\n", $conflicts)); ?>">⚠️</span>
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
                    <p><strong>Date:</strong> <?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?></p>
                    <p><strong>Time:</strong> <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
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