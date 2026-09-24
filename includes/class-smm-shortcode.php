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
            'status' => '',
            'competition' => '',
            'competition_id' => 0,
            'group_by_day' => 'yes',
            'show_players' => 'yes',
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

        if (!empty($atts['competition_id'])) {
            $where_parts[] = "competition_id = " . intval($atts['competition_id']);
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

        $matches = SMM_Database::get_matches(array(
            'limit'   => $atts['limit'],
            'where'   => implode(' AND ', $where_parts),
            'orderby' => 'match_date, match_time',
            'order'   => 'ASC',
        ));

        if (empty($matches)) {
            return '<div class="smm-empty">No upcoming matches scheduled.</div>';
        }

        $checker = new SMM_Conflict_Checker();
        $group_by_day = ($atts['group_by_day'] === 'yes');
        $show_players = ($atts['show_players'] === 'yes');
        $show_conflicts = ($atts['show_conflicts'] === 'yes');

        $grouped = array();
        if ($group_by_day) {
            foreach ($matches as $m) {
                $grouped[$m->match_date][] = $m;
            }
        } else {
            $grouped = array('' => $matches);
        }

        ob_start();
        ?>
        <div class="smm-schedule">
            <?php foreach ($grouped as $date => $day_matches): ?>
                <?php if ($group_by_day && $date): ?>
                    <div class="smm-day-heading">
                        <span class="smm-day-name"><?php echo esc_html(SMM_Helpers::fmt_date($date)); ?></span>
                        <span class="smm-day-count">
                            <?php echo count($day_matches); ?>
                            match<?php echo count($day_matches) === 1 ? '' : 'es'; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="smm-day-matches">
                <?php foreach ($day_matches as $m):
                    $conflicts = $checker->check_match_conflicts($m->id);
                    $has_conflict = !empty($conflicts);
                    $attendance = $show_players ? SMM_Database::get_attendance($m->id) : array();

                    $home_team = $m->home_team_id ? SMM_Teams::get($m->home_team_id) : null;
                    $away_team = $m->away_team_id ? SMM_Teams::get($m->away_team_id) : null;
                    $home_name = $home_team ? $home_team->team_name : $m->home_team;
                    $away_name = $away_team ? $away_team->team_name : $m->away_team;
                    $loc = $m->location_id ? SMM_Locations::get($m->location_id) : null;
                    $loc_name = $loc ? $loc->location_name : $m->location;

                    $is_canceled  = $m->status === 'canceled';
                    $is_postponed = $m->status === 'postponed';

                    $classes = array('smm-match');
                    if ($has_conflict && $show_conflicts)  $classes[] = 'smm-match--conflict';
                    if ($is_canceled)    $classes[] = 'smm-match--canceled';
                    if ($is_postponed)   $classes[] = 'smm-match--postponed';
                    ?>
                    <article class="<?php echo esc_attr(implode(' ', $classes)); ?>">

                        <div class="smm-match__time">
                            <span class="smm-match__hour">
                                <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
                            </span>
                            <?php if ($m->match_duration): ?>
                                <span class="smm-match__duration">
                                    <?php echo intval($m->match_duration); ?> min
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="smm-match__body">
                            <div class="smm-match__teams">
                                <span class="smm-team">
                                    <?php if ($home_team): ?>
                                        <?php echo SMM_Teams::get_logo_html($home_team, array(32,32), 'smm-team__logo'); ?>
                                    <?php endif; ?>
                                    <span class="smm-team__name"><?php echo esc_html($home_name); ?></span>
                                </span>
                                <span class="smm-vs">vs</span>
                                <span class="smm-team">
                                    <?php if ($away_team): ?>
                                        <?php echo SMM_Teams::get_logo_html($away_team, array(32,32), 'smm-team__logo'); ?>
                                    <?php endif; ?>
                                    <span class="smm-team__name"><?php echo esc_html($away_name); ?></span>
                                </span>
                            </div>

                            <div class="smm-match__meta">
                                <?php if ($loc_name): ?>
                                    <span class="smm-meta-item">
                                        <span class="smm-meta-icon">📍</span>
                                        <?php echo esc_html($loc_name); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($m->competition_id): ?>
                                    <span class="smm-meta-item">
                                        <?php echo SMM_Competitions::badge_html($m->competition_id); ?>
                                    </span>
                                <?php elseif ($m->competition): ?>
                                    <span class="smm-meta-item">
                                        <span class="smm-meta-icon">🏆</span>
                                        <?php echo esc_html($m->competition); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($m->round): ?>
                                    <span class="smm-meta-item smm-meta-item--muted">
                                        Round <?php echo esc_html($m->round); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($attendance) && $show_players): ?>
                                <div class="smm-match__players">
                                    <?php foreach ($attendance as $a):
                                        $avail = $a->availability ?? 'available';
                                        ?>
                                        <span class="smm-chip smm-chip--<?php echo esc_attr($avail); ?>"
                                              title="<?php echo esc_attr(SMM_Helpers::availabilities()[$avail] ?? $avail); ?>">
                                            <?php echo esc_html($a->player_name); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="smm-match__status">
                            <?php echo SMM_Helpers::status_badge($m->status); ?>
                            <?php if ($has_conflict && $show_conflicts): ?>
                                <span class="smm-conflict-flag"
                                      title="<?php echo esc_attr(implode("\n", $conflicts)); ?>">
                                    ⚠️
                                </span>
                            <?php endif; ?>
                        </div>

                    </article>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function display_conflicts($atts) {
        $atts = shortcode_atts(array(
            'show_players' => 'yes',
        ), $atts);

        $checker = new SMM_Conflict_Checker();
        $all = $checker->get_all_conflicts();

        if (empty($all)) {
            return '<div class="smm-conflicts-empty">✓ No schedule conflicts detected.</div>';
        }

        ob_start();
        ?>
        <div class="smm-conflicts">
            <div class="smm-conflicts__header">
                <span class="smm-conflicts__icon">⚠️</span>
                <div>
                    <h3 class="smm-conflicts__title">Schedule Conflicts Detected</h3>
                    <p class="smm-conflicts__subtitle">
                        <?php echo count($all); ?>
                        match<?php echo count($all) === 1 ? '' : 'es'; ?>
                        with overlapping commitments.
                    </p>
                </div>
            </div>

            <?php foreach ($all as $data):
                $m = $data['match'];
                $home = $m->home_team_id ? SMM_Teams::get_name($m->home_team_id) : $m->home_team;
                $away = $m->away_team_id ? SMM_Teams::get_name($m->away_team_id) : $m->away_team;
                $loc  = $m->location_id ? SMM_Locations::get($m->location_id) : null;
                $loc_name = $loc ? $loc->location_name : $m->location;
                ?>
                <article class="smm-conflict-card">
                    <header class="smm-conflict-card__head">
                        <div class="smm-conflict-card__match">
                            <span class="smm-conflict-card__teams">
                                <?php echo esc_html($home); ?>
                                <span class="smm-conflict-card__vs">vs</span>
                                <?php echo esc_html($away); ?>
                            </span>
                        </div>
                        <div class="smm-conflict-card__when">
                            <span class="smm-conflict-card__date">
                                <?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?>
                            </span>
                            <span class="smm-conflict-card__time">
                                <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
                                <?php if ($m->match_duration): ?>
                                    <span class="smm-conflict-card__duration">
                                        (<?php echo intval($m->match_duration); ?> min)
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($loc_name): ?>
                            <div class="smm-conflict-card__loc">
                                <span class="smm-conflict-card__icon">📍</span>
                                <?php echo esc_html($loc_name); ?>
                            </div>
                        <?php endif; ?>
                    </header>

                    <ul class="smm-conflict-list">
                        <?php foreach ($data['conflicts'] as $c):
                            $shared = '';
                            if (preg_match('/—\s*shared:\s*(.+)$/u', $c, $mm)) {
                                $shared = $mm[1];
                                $c = preg_replace('/—\s*shared:\s*.+$/u', '', $c);
                            }
                            $c = trim($c, " \t\n\r\0\x0B—-");
                            ?>
                            <li class="smm-conflict-item">
                                <div class="smm-conflict-item__msg">
                                    <?php echo esc_html($c); ?>
                                </div>
                                <?php if ($shared && $atts['show_players'] === 'yes'): ?>
                                    <div class="smm-conflict-item__shared">
                                        <span class="smm-conflict-item__label">Shared:</span>
                                        <?php echo esc_html($shared); ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
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