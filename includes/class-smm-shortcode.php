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

    /* ============================================================
       MATCHES
       ============================================================ */

    public function display_matches($atts) {
        $atts = shortcode_atts(array(
            'view'           => 'cards',        // cards | compact | list
            'limit'          => -1,
            'show_conflicts' => 'yes',
            'show_past'      => 'no',
            'status'         => '',
            'competition'    => '',
            'competition_id' => 0,
            'group_by_day'   => 'yes',
            'show_players'   => 'yes',
        ), $atts);

        $view = in_array($atts['view'], array('cards','compact','list'), true)
            ? $atts['view'] : 'cards';

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

        // Pre-compute per-match data once so all three renderers share it
        $rows = array();
        foreach ($matches as $m) {
            $rows[] = $this->prepare_match_row($m, $checker, $atts);
        }

        switch ($view) {
            case 'compact':
                return $this->render_matches_compact($rows, $atts);
            case 'list':
                return $this->render_matches_list($rows, $atts);
            case 'cards':
            default:
                return $this->render_matches_cards($rows, $atts);
        }
    }

    private function prepare_match_row($m, $checker, $atts) {
        $conflicts = $checker->check_match_conflicts($m->id);
        $show_players = ($atts['show_players'] === 'yes');

        $home_team = $m->home_team_id ? SMM_Teams::get($m->home_team_id) : null;
        $away_team = $m->away_team_id ? SMM_Teams::get($m->away_team_id) : null;
        $loc       = $m->location_id  ? SMM_Locations::get($m->location_id) : null;
        $comp      = $m->competition_id ? SMM_Competitions::get($m->competition_id) : null;

        return array(
            'match'       => $m,
            'conflicts'   => $conflicts,
            'has_conflict'=> !empty($conflicts),
            'home_team'   => $home_team,
            'away_team'   => $away_team,
            'loc'         => $loc,
            'comp'        => $comp,
            'home_name'   => $home_team ? $home_team->team_name : $m->home_team,
            'away_name'   => $away_team ? $away_team->team_name : $m->away_team,
            'loc_name'    => $loc ? $loc->location_name : $m->location,
            'attendance'  => $show_players ? SMM_Database::get_attendance($m->id) : array(),
            'is_canceled' => $m->status === 'canceled',
            'is_postponed'=> $m->status === 'postponed',
        );
    }

    /* ---------- VIEW: cards ---------- */

    private function render_matches_cards($rows, $atts) {
        $group_by_day = ($atts['group_by_day'] === 'yes');
        $show_conflicts = ($atts['show_conflicts'] === 'yes');
        $show_players = ($atts['show_players'] === 'yes');

        $grouped = array();
        if ($group_by_day) {
            foreach ($rows as $r) $grouped[$r['match']->match_date][] = $r;
        } else {
            $grouped = array('' => $rows);
        }

        ob_start();
        ?>
        <div class="smm-schedule smm-schedule--cards">
            <?php foreach ($grouped as $date => $day_rows): ?>
                <?php if ($group_by_day && $date): ?>
                    <div class="smm-day-heading">
                        <span class="smm-day-name"><?php echo esc_html(SMM_Helpers::fmt_date($date)); ?></span>
                        <span class="smm-day-count">
                            <?php echo count($day_rows); ?>
                            match<?php echo count($day_rows) === 1 ? '' : 'es'; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="smm-day-matches">
                <?php foreach ($day_rows as $r):
                    $m = $r['match'];
                    $classes = array('smm-match');
                    if ($r['has_conflict'] && $show_conflicts) $classes[] = 'smm-match--conflict';
                    if ($r['is_canceled'])  $classes[] = 'smm-match--canceled';
                    if ($r['is_postponed']) $classes[] = 'smm-match--postponed';
                    ?>
                    <article class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                        <div class="smm-match__time">
                            <span class="smm-match__hour"><?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?></span>
                            <?php if ($m->match_duration): ?>
                                <span class="smm-match__duration"><?php echo intval($m->match_duration); ?> min</span>
                            <?php endif; ?>
                        </div>

                        <div class="smm-match__body">
                            <div class="smm-match__teams">
                                <span class="smm-team">
                                    <?php if ($r['home_team']): ?>
                                        <?php echo SMM_Teams::get_logo_html($r['home_team'], array(32,32), 'smm-team__logo'); ?>
                                    <?php endif; ?>
                                    <span class="smm-team__name"><?php echo esc_html($r['home_name']); ?></span>
                                </span>
                                <span class="smm-vs">vs</span>
                                <span class="smm-team">
                                    <?php if ($r['away_team']): ?>
                                        <?php echo SMM_Teams::get_logo_html($r['away_team'], array(32,32), 'smm-team__logo'); ?>
                                    <?php endif; ?>
                                    <span class="smm-team__name"><?php echo esc_html($r['away_name']); ?></span>
                                </span>
                            </div>

                            <div class="smm-match__meta">
                                <?php if ($r['loc_name']): ?>
                                    <span class="smm-meta-item">
                                        <span class="smm-meta-icon">📍</span>
                                        <?php echo esc_html($r['loc_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($r['comp']): ?>
                                    <span class="smm-meta-item"><?php echo SMM_Competitions::badge_html($m->competition_id); ?></span>
                                <?php elseif ($m->competition): ?>
                                    <span class="smm-meta-item"><span class="smm-meta-icon">🏆</span> <?php echo esc_html($m->competition); ?></span>
                                <?php endif; ?>
                                <?php if ($m->round): ?>
                                    <span class="smm-meta-item smm-meta-item--muted">Round <?php echo esc_html($m->round); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($show_players && !empty($r['attendance'])): ?>
                                <div class="smm-match__players">
                                    <?php foreach ($r['attendance'] as $a):
                                        $avail = $a->availability ?? 'available'; ?>
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
                            <?php if ($r['has_conflict'] && $show_conflicts): ?>
                                <span class="smm-conflict-flag"
                                      title="<?php echo esc_attr(implode("\n", $r['conflicts'])); ?>">⚠️</span>
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

    /* ---------- VIEW: compact ---------- */

    private function render_matches_compact($rows, $atts) {
        $group_by_day = ($atts['group_by_day'] === 'yes');
        $show_conflicts = ($atts['show_conflicts'] === 'yes');

        $grouped = array();
        if ($group_by_day) {
            foreach ($rows as $r) $grouped[$r['match']->match_date][] = $r;
        } else {
            $grouped = array('' => $rows);
        }

        ob_start();
        ?>
        <div class="smm-schedule smm-schedule--compact">
            <?php foreach ($grouped as $date => $day_rows): ?>
                <?php if ($group_by_day && $date): ?>
                    <div class="smm-day-heading smm-day-heading--compact">
                        <span class="smm-day-name"><?php echo esc_html(SMM_Helpers::fmt_date($date)); ?></span>
                        <span class="smm-day-count">
                            <?php echo count($day_rows); ?> match<?php echo count($day_rows) === 1 ? '' : 'es'; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <ul class="smm-compact-list">
                <?php foreach ($day_rows as $r):
                    $m = $r['match'];
                    $classes = array('smm-compact-row');
                    if ($r['has_conflict'] && $show_conflicts) $classes[] = 'smm-compact-row--conflict';
                    if ($r['is_canceled'])  $classes[] = 'smm-compact-row--canceled';
                    if ($r['is_postponed']) $classes[] = 'smm-compact-row--postponed';
                    ?>
                    <li class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                        <?php if (!$group_by_day): ?>
                            <span class="smm-compact-row__date">
                                <?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?>
                            </span>
                        <?php endif; ?>

                        <span class="smm-compact-row__time">
                            <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
                        </span>

                        <span class="smm-compact-row__match">
                            <?php if ($r['home_team']): ?>
                                <?php echo SMM_Teams::get_logo_html($r['home_team'], array(18,18), 'smm-compact-logo'); ?>
                            <?php endif; ?>
                            <span class="smm-compact-row__team"><?php echo esc_html($r['home_name']); ?></span>
                            <span class="smm-compact-row__vs">vs</span>
                            <?php if ($r['away_team']): ?>
                                <?php echo SMM_Teams::get_logo_html($r['away_team'], array(18,18), 'smm-compact-logo'); ?>
                            <?php endif; ?>
                            <span class="smm-compact-row__team"><?php echo esc_html($r['away_name']); ?></span>
                        </span>

                        <span class="smm-compact-row__meta">
                            <?php if ($r['loc_name']): ?>
                                <span class="smm-compact-row__loc"><?php echo esc_html($r['loc_name']); ?></span>
                            <?php endif; ?>
                            <?php if ($r['comp']): ?>
                                <?php echo SMM_Competitions::badge_html($m->competition_id); ?>
                            <?php endif; ?>
                        </span>

                        <?php if ($r['has_conflict'] && $show_conflicts): ?>
                            <span class="smm-conflict-flag smm-conflict-flag--inline"
                                  title="<?php echo esc_attr(implode("\n", $r['conflicts'])); ?>">⚠️</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------- VIEW: list ---------- */

    private function render_matches_list($rows, $atts) {
        $show_conflicts = ($atts['show_conflicts'] === 'yes');
        $show_players   = ($atts['show_players'] === 'yes');

        ob_start();
        ?>
        <div class="smm-schedule smm-schedule--list">
            <?php foreach ($rows as $r):
                $m = $r['match'];
                $classes = array('smm-list-item');
                if ($r['has_conflict'] && $show_conflicts) $classes[] = 'smm-list-item--conflict';
                if ($r['is_canceled'])  $classes[] = 'smm-list-item--canceled';
                if ($r['is_postponed']) $classes[] = 'smm-list-item--postponed';
                ?>
                <article class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                    <div class="smm-list-item__teams">
                        <div class="smm-list-item__team smm-list-item__team--home">
                            <?php if ($r['home_team']): ?>
                                <?php echo SMM_Teams::get_logo_html($r['home_team'], array(48,48), 'smm-list-logo'); ?>
                            <?php endif; ?>
                            <span class="smm-list-item__name"><?php echo esc_html($r['home_name']); ?></span>
                        </div>
                        <div class="smm-list-item__vs">vs</div>
                        <div class="smm-list-item__team smm-list-item__team--away">
                            <?php if ($r['away_team']): ?>
                                <?php echo SMM_Teams::get_logo_html($r['away_team'], array(48,48), 'smm-list-logo'); ?>
                            <?php endif; ?>
                            <span class="smm-list-item__name"><?php echo esc_html($r['away_name']); ?></span>
                        </div>
                    </div>

                    <div class="smm-list-item__facts">
                        <span class="smm-list-item__fact">
                            <?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?>
                        </span>
                        <span class="smm-list-item__fact">
                            <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
                            <?php if ($m->match_duration): ?>
                                · <?php echo intval($m->match_duration); ?> min
                            <?php endif; ?>
                        </span>
                        <?php if ($r['loc_name']): ?>
                            <span class="smm-list-item__fact">
                                <span class="smm-meta-icon">📍</span>
                                <?php echo esc_html($r['loc_name']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($r['comp']): ?>
                            <span class="smm-list-item__fact">
                                <?php echo SMM_Competitions::badge_html($m->competition_id); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($m->round): ?>
                            <span class="smm-list-item__fact smm-meta-item--muted">
                                Round <?php echo esc_html($m->round); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($show_players && !empty($r['attendance'])): ?>
                        <div class="smm-list-item__players">
                            <?php foreach ($r['attendance'] as $a):
                                $avail = $a->availability ?? 'available'; ?>
                                <span class="smm-chip smm-chip--<?php echo esc_attr($avail); ?>">
                                    <?php echo esc_html($a->player_name); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="smm-list-item__footer">
                        <?php echo SMM_Helpers::status_badge($m->status); ?>
                        <?php if ($r['has_conflict'] && $show_conflicts): ?>
                            <span class="smm-conflict-flag"
                                  title="<?php echo esc_attr(implode("\n", $r['conflicts'])); ?>">⚠️</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       CONFLICTS
       ============================================================ */

    public function display_conflicts($atts) {
        $atts = shortcode_atts(array(
            'view'         => 'cards',   // cards | compact | minimal
            'show_players' => 'yes',
        ), $atts);

        $view = in_array($atts['view'], array('cards','compact','minimal'), true)
            ? $atts['view'] : 'cards';

        $checker = new SMM_Conflict_Checker();
        $all = $checker->get_all_conflicts();

        if (empty($all)) {
            return '<div class="smm-conflicts-empty">✓ No schedule conflicts detected.</div>';
        }

        switch ($view) {
            case 'compact': return $this->render_conflicts_compact($all, $atts);
            case 'minimal': return $this->render_conflicts_minimal($all, $atts);
            case 'cards':
            default:        return $this->render_conflicts_cards($all, $atts);
        }
    }

    private function render_conflicts_cards($all, $atts) {
        $show_players = ($atts['show_players'] === 'yes');
        ob_start();
        ?>
        <div class="smm-conflicts smm-conflicts--cards">
            <div class="smm-conflicts__header">
                <span class="smm-conflicts__icon">⚠️</span>
                <div>
                    <h3 class="smm-conflicts__title">Schedule Conflicts Detected</h3>
                    <p class="smm-conflicts__subtitle">
                        <?php echo count($all); ?> match<?php echo count($all) === 1 ? '' : 'es'; ?>
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
                                    <span class="smm-conflict-card__duration">(<?php echo intval($m->match_duration); ?> min)</span>
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
                                <div class="smm-conflict-item__msg"><?php echo esc_html($c); ?></div>
                                <?php if ($shared && $show_players): ?>
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

    private function render_conflicts_compact($all, $atts) {
        // Collect all individual conflicts into a flat list of rows
        $rows = array();
        foreach ($all as $data) {
            $m = $data['match'];
            foreach ($data['conflicts'] as $c) {
                $shared = '';
                if (preg_match('/—\s*shared:\s*(.+)$/u', $c, $mm)) {
                    $shared = $mm[1];
                    $c = preg_replace('/—\s*shared:\s*.+$/u', '', $c);
                }
                $rows[] = array(
                    'match'  => $m,
                    'reason' => trim($c, " \t\n\r\0\x0B—-"),
                    'shared' => $shared,
                );
            }
        }

        $home = function($m) {
            return $m->home_team_id ? SMM_Teams::get_name($m->home_team_id) : $m->home_team;
        };
        $away = function($m) {
            return $m->away_team_id ? SMM_Teams::get_name($m->away_team_id) : $m->away_team;
        };

        ob_start();
        ?>
        <div class="smm-conflicts smm-conflicts--compact">
            <div class="smm-conflicts__bar">
                <span class="smm-conflicts__bar-icon">⚠️</span>
                <span class="smm-conflicts__bar-title">
                    <?php echo count($rows); ?> conflict<?php echo count($rows) === 1 ? '' : 's'; ?>
                </span>
            </div>
            <ul class="smm-conflict-rows">
                <?php foreach ($rows as $r): $m = $r['match']; ?>
                    <li class="smm-conflict-row">
                        <div class="smm-conflict-row__when">
                            <span class="smm-conflict-row__date">
                                <?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?>
                            </span>
                            <span class="smm-conflict-row__time">
                                <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
                            </span>
                        </div>
                        <div class="smm-conflict-row__match">
                            <strong><?php echo esc_html($home($m)); ?></strong>
                            <span class="smm-conflict-row__vs">vs</span>
                            <strong><?php echo esc_html($away($m)); ?></strong>
                        </div>
                        <div class="smm-conflict-row__reason">
                            <span class="smm-conflict-row__icon">⛔</span>
                            <?php echo esc_html($r['reason']); ?>
                            <?php if ($r['shared'] && $atts['show_players'] === 'yes'): ?>
                                <span class="smm-conflict-row__shared">
                                    — <?php echo esc_html($r['shared']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_conflicts_minimal($all, $atts) {
        $home = function($m) {
            return $m->home_team_id ? SMM_Teams::get_name($m->home_team_id) : $m->home_team;
        };
        $away = function($m) {
            return $m->away_team_id ? SMM_Teams::get_name($m->away_team_id) : $m->away_team;
        };

        ob_start();
        ?>
        <ul class="smm-conflicts smm-conflicts--minimal">
            <?php foreach ($all as $data):
                $m = $data['match']; ?>
                <?php foreach ($data['conflicts'] as $c):
                    $shared = '';
                    if (preg_match('/—\s*shared:\s*(.+)$/u', $c, $mm)) {
                        $shared = $mm[1];
                        $c = preg_replace('/—\s*shared:\s*.+$/u', '', $c);
                    }
                    $c = trim($c, " \t\n\r\0\x0B—-");
                    ?>
                    <li class="smm-conflict-inline">
                        <span class="smm-conflict-inline__icon">⚠️</span>
                        <span class="smm-conflict-inline__match">
                            <strong><?php echo esc_html($home($m)); ?></strong>
                            vs
                            <strong><?php echo esc_html($away($m)); ?></strong>
                            <span class="smm-conflict-inline__when">
                                (<?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?>,
                                <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>)
                            </span>
                        </span>
                        <span class="smm-conflict-inline__reason">— <?php echo esc_html($c); ?></span>
                        <?php if ($shared && $atts['show_players'] === 'yes'): ?>
                            <span class="smm-conflict-inline__shared">[<?php echo esc_html($shared); ?>]</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       TEAMS (unchanged)
       ============================================================ */

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