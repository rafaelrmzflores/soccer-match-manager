<?php
class SMM_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_init', array($this, 'handle_forms'));
        add_action('wp_ajax_smm_get_team_players', array($this, 'ajax_get_team_players'));
    }

    public function menu() {
        add_menu_page('Soccer Matches', 'Soccer Matches', 'manage_options',
            'smm-matches', array($this, 'matches_page'), 'dashicons-schedule', 30);

        add_submenu_page('smm-matches', 'All Matches', 'All Matches',
            'manage_options', 'smm-matches', array($this, 'matches_page'));

        add_submenu_page('smm-matches', 'Add Match', 'Add Match',
            'manage_options', 'smm-add-match', array($this, 'add_match_page'));

        add_submenu_page('smm-matches', 'Teams', 'Teams',
            'manage_options', 'smm-teams', array($this, 'teams_page'));

        add_submenu_page('smm-matches', 'Players', 'Players',
            'manage_options', 'smm-players', array($this, 'players_page'));

        add_submenu_page('smm-matches', 'Locations', 'Locations',
            'manage_options', 'smm-locations', array($this, 'locations_page'));
    }

    public function assets($hook) {
        if (strpos($hook, 'smm-') === false) return;
        wp_enqueue_media();
        wp_enqueue_style('smm-admin', SMM_PLUGIN_URL . 'assets/admin.css', array(), SMM_VERSION);
        wp_enqueue_script('smm-admin', SMM_PLUGIN_URL . 'assets/admin.js',
            array('jquery'), SMM_VERSION, true);

        wp_localize_script('smm-admin', 'SMM', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('smm_ajax'),
        ));
    }

    public function ajax_get_team_players() {
        check_ajax_referer('smm_ajax', 'nonce');
        $team_id = intval($_POST['team_id'] ?? 0);
        if (!$team_id) wp_send_json_success(array('players' => array()));

        $players = SMM_Players::get_by_team($team_id, true);
        $out = array();
        foreach ($players as $p) {
            $out[] = array('id' => $p->id, 'name' => $p->player_name);
        }
        wp_send_json_success(array('players' => $out));
    }

    public function handle_forms() {
        /* ---- SAVE MATCH ---- */
        if (isset($_POST['smm_save_match']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_save_match')) {
            $home_id = intval($_POST['home_team_id'] ?? 0);
            $away_id = intval($_POST['away_team_id'] ?? 0);
            $loc_id  = intval($_POST['location_id'] ?? 0);

            $duration = !empty($_POST['match_duration']) ? intval($_POST['match_duration']) : null;
            if ($duration !== null && $duration <= 0) $duration = null;

            $data = array(
                'match_date'     => sanitize_text_field($_POST['match_date']),
                'match_time'     => sanitize_text_field($_POST['match_time']),
                'match_duration' => $duration,
                'home_team_id'   => $home_id,
                'away_team_id'   => $away_id,
                'home_team'      => SMM_Teams::get_name($home_id),
                'away_team'      => SMM_Teams::get_name($away_id),
                'location_id'    => $loc_id,
                'location'       => $loc_id ? SMM_Locations::get_name($loc_id)
                                            : sanitize_text_field($_POST['location'] ?? ''),
            );

            $match_id = !empty($_POST['match_id']) ? intval($_POST['match_id']) : 0;
            if ($match_id) {
                SMM_Database::update_match($match_id, $data);
            } else {
                $match_id = SMM_Database::insert_match($data);
            }

            // Manual + auto (from teams) attendance
            $manual = isset($_POST['attending_players'])
                ? array_map('intval', (array) $_POST['attending_players'])
                : array();

            $auto = $this->get_auto_attending_players($home_id, $away_id);

            SMM_Database::set_attendance($match_id, $manual, $auto);

            wp_redirect(admin_url('admin.php?page=smm-matches&message='
                . (!empty($_POST['match_id']) ? 'updated' : 'added')));
            exit;
        }

        /* ---- DELETE MATCH ---- */
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_match') {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_match_' . $_GET['id'])) {
                SMM_Database::delete_match(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-matches&message=deleted'));
                exit;
            }
        }

        /* ---- TEAMS ---- */
        if (isset($_POST['smm_add_team']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_team')) {
            SMM_Teams::add($_POST['team_name'] ?? '', intval($_POST['team_logo_id'] ?? 0));
            wp_redirect(admin_url('admin.php?page=smm-teams&message=team_added'));
            exit;
        }
        if (isset($_POST['smm_update_team']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_team')) {
            SMM_Teams::update(intval($_POST['team_id']), $_POST['team_name'] ?? '',
                intval($_POST['team_logo_id'] ?? 0));
            wp_redirect(admin_url('admin.php?page=smm-teams&message=team_updated'));
            exit;
        }
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_team') {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_team_' . $_GET['id'])) {
                SMM_Teams::delete(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-teams&message=team_deleted'));
                exit;
            }
        }

        /* ---- PLAYERS ---- */
        if (isset($_POST['smm_add_player']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_player')) {
            SMM_Players::add($_POST['player_name'] ?? '', $_POST['player_email'] ?? '',
                intval($_POST['player_team_id'] ?? 0));
            wp_redirect(admin_url('admin.php?page=smm-players&message=player_added'));
            exit;
        }
        if (isset($_POST['smm_update_player']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_player')) {
            SMM_Players::update(
                intval($_POST['player_id']),
                $_POST['player_name'] ?? '',
                $_POST['player_email'] ?? '',
                intval($_POST['player_team_id'] ?? 0),
                isset($_POST['is_active'])
            );
            wp_redirect(admin_url('admin.php?page=smm-players&message=player_updated'));
            exit;
        }
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_player') {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_player_' . $_GET['id'])) {
                SMM_Players::delete(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-players&message=player_deleted'));
                exit;
            }
        }

        /* ---- LOCATIONS ---- */
        if (isset($_POST['smm_add_location']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_location')) {
            SMM_Locations::add(
                $_POST['location_name'] ?? '',
                $_POST['location_address'] ?? '',
                $_POST['latitude'] ?? null,
                $_POST['longitude'] ?? null,
                intval($_POST['travel_buffer_minutes'] ?? 30)
            );
            wp_redirect(admin_url('admin.php?page=smm-locations&message=location_added'));
            exit;
        }
        if (isset($_POST['smm_update_location']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_location')) {
            SMM_Locations::update(
                intval($_POST['location_id']),
                $_POST['location_name'] ?? '',
                $_POST['location_address'] ?? '',
                $_POST['latitude'] ?? null,
                $_POST['longitude'] ?? null,
                intval($_POST['travel_buffer_minutes'] ?? 30)
            );
            wp_redirect(admin_url('admin.php?page=smm-locations&message=location_updated'));
            exit;
        }
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_location') {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_location_' . $_GET['id'])) {
                SMM_Locations::delete(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-locations&message=location_deleted'));
                exit;
            }
        }
    }

    /**
     * Returns player IDs to auto-attend a match, based on the two teams.
     */
    private function get_auto_attending_players($home_team_id, $away_team_id) {
        $ids = array();
        foreach (array($home_team_id, $away_team_id) as $tid) {
            if (!$tid) continue;
            foreach (SMM_Players::get_by_team($tid, true) as $p) {
                $ids[] = intval($p->id);
            }
        }
        return array_unique($ids);
    }

    /* =================== MATCHES LIST =================== */

    public function matches_page() {
        $matches = SMM_Database::get_matches();
        $checker = new SMM_Conflict_Checker();
        ?>
        <div class="wrap">
            <h1>Soccer Matches
                <a href="<?php echo admin_url('admin.php?page=smm-add-match'); ?>" class="page-title-action">Add New</a>
            </h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    $m = array('added'=>'Match added.','updated'=>'Match updated.','deleted'=>'Match deleted.');
                    echo esc_html($m[$_GET['message']] ?? 'Done.');
                ?></p></div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Date</th><th>Time</th><th>Duration</th><th>Home</th><th>Away</th>
                    <th>Location</th><th>Attending</th><th>Status</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php if (empty($matches)): ?>
                    <tr><td colspan="9">No matches yet.</td></tr>
                <?php else: foreach ($matches as $m):
                    $conflicts = $checker->check_match_conflicts($m->id);
                    $attendance = SMM_Database::get_attendance($m->id);

                    $home_team = $m->home_team_id ? SMM_Teams::get($m->home_team_id) : null;
                    $away_team = $m->away_team_id ? SMM_Teams::get($m->away_team_id) : null;
                    $home_name = $home_team ? $home_team->team_name : $m->home_team;
                    $away_name = $away_team ? $away_team->team_name : $m->away_team;
                    $location  = $m->location_id ? SMM_Locations::get($m->location_id) : null;
                    ?>
                    <tr class="<?php echo !empty($conflicts) ? 'smm-has-conflict' : ''; ?>">
                        <td><?php echo esc_html($m->match_date); ?></td>
                        <td><?php echo esc_html(date('g:i A', strtotime($m->match_time))); ?></td>
                        <td><?php echo $m->match_duration ? intval($m->match_duration) . ' min' : '—'; ?></td>
                        <td class="smm-team-cell">
                            <?php echo $home_team ? SMM_Teams::get_logo_html($home_team, array(24,24)) : ''; ?>
                            <?php echo esc_html($home_name); ?>
                        </td>
                        <td class="smm-team-cell">
                            <?php echo $away_team ? SMM_Teams::get_logo_html($away_team, array(24,24)) : ''; ?>
                            <?php echo esc_html($away_name); ?>
                        </td>
                        <td><?php echo esc_html($location ? $location->location_name : $m->location); ?></td>
                        <td>
                            <?php if (empty($attendance)): ?>—
                            <?php else: foreach ($attendance as $a):
                                $logo = '';
                                if (!empty($a->team_logo_id)) {
                                    $logo = wp_get_attachment_image($a->team_logo_id, array(18,18), false,
                                        array('class'=>'smm-player-mini-logo'));
                                }
                                $auto_class = $a->auto_added ? ' smm-attendee-auto' : '';
                                ?>
                                <span class="smm-attendee<?php echo $auto_class; ?>"
                                      title="<?php echo $a->auto_added ? 'Auto-added from team' : 'Manually added'; ?>">
                                    <?php echo $logo; ?><?php echo esc_html($a->player_name); ?>
                                </span>
                            <?php endforeach; endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($conflicts)): ?>
                                <span class="smm-conflict-warning"
                                      title="<?php echo esc_attr(implode("\n", $conflicts)); ?>">
                                    ⚠️ <?php echo count($conflicts); ?>
                                </span>
                            <?php else: ?>
                                <span class="smm-no-conflict">✓ OK</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=smm-add-match&id=' . $m->id); ?>">Edit</a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-matches&action=delete_match&id=' . $m->id), 'smm_del_match_' . $m->id); ?>"
                               onclick="return confirm('Delete this match?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* =================== ADD/EDIT MATCH =================== */

    public function add_match_page() {
        $match = null;
        $selected = array();
        if (isset($_GET['id'])) {
            $match = SMM_Database::get_match(intval($_GET['id']));
            if ($match) {
                $selected = array_map('intval', SMM_Database::get_attending_player_ids($match->id));
            }
        }

        $teams     = SMM_Teams::get_all();
        $players   = SMM_Players::get_all(true);
        $locations = SMM_Locations::get_all();
        ?>
        <div class="wrap">
            <h1><?php echo $match ? 'Edit Match' : 'Add New Match'; ?></h1>

            <?php if (empty($teams)): ?>
                <div class="notice notice-warning"><p>
                    Add teams first. <a href="<?php echo admin_url('admin.php?page=smm-teams'); ?>">Manage Teams →</a>
                </p></div>
            <?php endif; ?>

            <form method="post" id="smm-match-form">
                <?php wp_nonce_field('smm_save_match', 'smm_nonce'); ?>
                <?php if ($match): ?>
                    <input type="hidden" name="match_id" value="<?php echo $match->id; ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="match_date">Date</label></th>
                        <td><input type="date" name="match_date" id="match_date" required
                                   value="<?php echo $match ? esc_attr($match->match_date) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="match_time">Time</label></th>
                        <td><input type="time" name="match_time" id="match_time" required
                                   value="<?php echo $match ? esc_attr($match->match_time) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="match_duration">Duration (minutes)</label></th>
                        <td>
                            <input type="number" name="match_duration" id="match_duration"
                                   min="0" step="5" placeholder="e.g. 90"
                                   value="<?php echo ($match && $match->match_duration) ? esc_attr($match->match_duration) : ''; ?>">
                            <p class="description">
                                Optional. If set, used for exact time-overlap conflict detection.
                                If empty, conflicts use a 2-hour default slot.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="home_team_id">Home Team</label></th>
                        <td>
                            <select name="home_team_id" id="home_team_id" class="smm-team-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($teams as $t): ?>
                                    <option value="<?php echo $t->id; ?>"
                                        <?php selected($match ? $match->home_team_id : 0, $t->id); ?>>
                                        <?php echo esc_html($t->team_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="away_team_id">Away Team</label></th>
                        <td>
                            <select name="away_team_id" id="away_team_id" class="smm-team-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($teams as $t): ?>
                                    <option value="<?php echo $t->id; ?>"
                                        <?php selected($match ? $match->away_team_id : 0, $t->id); ?>>
                                        <?php echo esc_html($t->team_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="location_id">Location</label></th>
                        <td>
                            <select name="location_id" id="location_id">
                                <option value="">— Select —</option>
                                <?php foreach ($locations as $l): ?>
                                    <option value="<?php echo $l->id; ?>"
                                        <?php selected($match ? $match->location_id : 0, $l->id); ?>>
                                        <?php echo esc_html($l->location_name); ?>
                                        <?php if ($l->location_address) echo ' — ' . esc_html($l->location_address); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <a href="<?php echo admin_url('admin.php?page=smm-locations'); ?>">
                                    Manage locations →</a>
                                (add coordinates &amp; travel buffers to enable distance conflicts)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Attending Players</th>
                        <td>
                            <p class="description smm-auto-note">
                                ✓ Players from the selected teams are auto-selected.
                                Uncheck any who are not attending, or add others manually.
                            </p>
                            <fieldset class="smm-players-checkboxes">
                                <?php if (empty($players)): ?>
                                    <em>No active players yet.</em>
                                <?php else:
                                    $grouped = array();
                                    foreach ($players as $p) {
                                        $key = $p->team_name ?: '— No team —';
                                        $grouped[$key][] = $p;
                                    }
                                    foreach ($grouped as $team_name => $team_players): ?>
                                        <div class="smm-player-group">
                                            <strong><?php echo esc_html($team_name); ?></strong>
                                            <?php foreach ($team_players as $p): ?>
                                                <label class="smm-player-line"
                                                       data-team="<?php echo intval($p->team_id); ?>"
                                                       style="display:block;margin:4px 0 4px 12px;">
                                                    <input type="checkbox" name="attending_players[]"
                                                           value="<?php echo $p->id; ?>"
                                                           data-team="<?php echo intval($p->team_id); ?>"
                                                           <?php checked(in_array($p->id, $selected)); ?>>
                                                    <?php echo esc_html($p->player_name); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach;
                                endif; ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <?php submit_button($match ? 'Update Match' : 'Add Match', 'primary', 'smm_save_match'); ?>
            </form>
        </div>
        <?php
    }

    /* =================== TEAMS PAGE =================== */

    public function teams_page() {
        $teams = SMM_Teams::get_all();
        $edit_team = isset($_GET['edit']) ? SMM_Teams::get(intval($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1>Teams</h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    $m = array(
                        'team_added' => 'Team added.',
                        'team_updated' => 'Team updated.',
                        'team_deleted' => 'Team deleted.'
                    );
                    echo esc_html($m[$_GET['message']] ?? 'Done.');
                ?></p></div>
            <?php endif; ?>

            <div class="smm-players-layout">
                <div class="smm-player-form-wrap">
                    <h2><?php echo $edit_team ? 'Edit Team' : 'Add Team'; ?></h2>
                    <form method="post">
                        <?php wp_nonce_field($edit_team ? 'smm_update_team' : 'smm_add_team', 'smm_nonce'); ?>
                        <?php if ($edit_team): ?>
                            <input type="hidden" name="team_id" value="<?php echo $edit_team->id; ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="team_name">Team Name</label></th>
                                <td><input type="text" name="team_name" id="team_name" required
                                           value="<?php echo $edit_team ? esc_attr($edit_team->team_name) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th>Logo</th>
                                <td>
                                    <?php
                                    $logo_id = $edit_team ? $edit_team->team_logo_id : 0;
                                    $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : '';
                                    ?>
                                    <div class="smm-logo-picker">
                                        <div class="smm-logo-preview">
                                            <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" alt=""><?php endif; ?>
                                        </div>
                                        <input type="hidden" name="team_logo_id" id="team_logo_id"
                                               value="<?php echo esc_attr($logo_id); ?>">
                                        <button type="button" class="button smm-upload-logo">Select Logo</button>
                                        <button type="button" class="button smm-remove-logo"
                                                style="<?php echo $logo_id ? '' : 'display:none;'; ?>">Remove</button>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button($edit_team ? 'Update Team' : 'Add Team', 'primary',
                            $edit_team ? 'smm_update_team' : 'smm_add_team'); ?>
                        <?php if ($edit_team): ?>
                            <a href="<?php echo admin_url('admin.php?page=smm-teams'); ?>">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="smm-players-list-wrap">
                    <h2>All Teams</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr>
                            <th style="width:60px;">Logo</th><th>Team Name</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($teams)): ?>
                            <tr><td colspan="3">No teams yet.</td></tr>
                        <?php else: foreach ($teams as $t): ?>
                            <tr>
                                <td><?php echo SMM_Teams::get_logo_html($t, array(40,40)); ?></td>
                                <td><?php echo esc_html($t->team_name); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=smm-teams&edit=' . $t->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-teams&action=delete_team&id=' . $t->id), 'smm_del_team_' . $t->id); ?>"
                                       onclick="return confirm('Delete this team?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* =================== PLAYERS PAGE =================== */

    public function players_page() {
        $players = SMM_Players::get_all();
        $teams = SMM_Teams::get_all();
        $edit_player = isset($_GET['edit']) ? SMM_Players::get(intval($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1>Players</h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    $m = array(
                        'player_added' => 'Player added.',
                        'player_updated' => 'Player updated.',
                        'player_deleted' => 'Player deleted.'
                    );
                    echo esc_html($m[$_GET['message']] ?? 'Done.');
                ?></p></div>
            <?php endif; ?>

            <div class="smm-players-layout">
                <div class="smm-player-form-wrap">
                    <h2><?php echo $edit_player ? 'Edit Player' : 'Add Player'; ?></h2>
                    <form method="post">
                        <?php wp_nonce_field($edit_player ? 'smm_update_player' : 'smm_add_player', 'smm_nonce'); ?>
                        <?php if ($edit_player): ?>
                            <input type="hidden" name="player_id" value="<?php echo $edit_player->id; ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="player_name">Name</label></th>
                                <td><input type="text" name="player_name" id="player_name" required
                                           value="<?php echo $edit_player ? esc_attr($edit_player->player_name) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="player_email">Email (optional)</label></th>
                                <td><input type="email" name="player_email" id="player_email"
                                           value="<?php echo $edit_player ? esc_attr($edit_player->player_email) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="player_team_id">Team</label></th>
                                <td>
                                    <select name="player_team_id" id="player_team_id">
                                        <option value="0">— No team —</option>
                                        <?php foreach ($teams as $t): ?>
                                            <option value="<?php echo $t->id; ?>"
                                                <?php selected($edit_player ? $edit_player->team_id : 0, $t->id); ?>>
                                                <?php echo esc_html($t->team_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php if ($edit_player): ?>
                            <tr>
                                <th>Active</th>
                                <td><label>
                                    <input type="checkbox" name="is_active" value="1"
                                        <?php checked($edit_player->is_active, 1); ?>>
                                    Player is active
                                </label></td>
                            </tr>
                            <?php endif; ?>
                        </table>

                        <?php submit_button($edit_player ? 'Update Player' : 'Add Player', 'primary',
                            $edit_player ? 'smm_update_player' : 'smm_add_player'); ?>
                        <?php if ($edit_player): ?>
                            <a href="<?php echo admin_url('admin.php?page=smm-players'); ?>">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="smm-players-list-wrap">
                    <h2>All Players</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr>
                            <th>Name</th><th>Team</th><th>Email</th><th>Status</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($players)): ?>
                            <tr><td colspan="5">No players yet.</td></tr>
                        <?php else: foreach ($players as $p):
                            $team = $p->team_id ? SMM_Teams::get($p->team_id) : null;
                            ?>
                            <tr>
                                <td><?php echo esc_html($p->player_name); ?></td>
                                <td class="smm-team-cell">
                                    <?php if ($team): ?>
                                        <?php echo SMM_Teams::get_logo_html($team, array(24,24)); ?>
                                        <?php echo esc_html($team->team_name); ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?php echo esc_html($p->player_email); ?></td>
                                <td><?php echo $p->is_active ? '✓ Active' : '— Inactive'; ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=smm-players&edit=' . $p->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-players&action=delete_player&id=' . $p->id), 'smm_del_player_' . $p->id); ?>"
                                       onclick="return confirm('Delete this player?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* =================== LOCATIONS PAGE =================== */

    public function locations_page() {
        $locations = SMM_Locations::get_all();
        $edit_location = isset($_GET['edit']) ? SMM_Locations::get(intval($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1>Locations</h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    $m = array(
                        'location_added' => 'Location added.',
                        'location_updated' => 'Location updated.',
                        'location_deleted' => 'Location deleted.'
                    );
                    echo esc_html($m[$_GET['message']] ?? 'Done.');
                ?></p></div>
            <?php endif; ?>

            <div class="smm-players-layout">
                <div class="smm-player-form-wrap">
                    <h2><?php echo $edit_location ? 'Edit Location' : 'Add Location'; ?></h2>
                    <form method="post">
                        <?php wp_nonce_field($edit_location ? 'smm_update_location' : 'smm_add_location', 'smm_nonce'); ?>
                        <?php if ($edit_location): ?>
                            <input type="hidden" name="location_id" value="<?php echo $edit_location->id; ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="location_name">Name</label></th>
                                <td><input type="text" name="location_name" id="location_name" required
                                           value="<?php echo $edit_location ? esc_attr($edit_location->location_name) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="location_address">Address</label></th>
                                <td><input type="text" name="location_address" id="location_address" class="regular-text"
                                           value="<?php echo $edit_location ? esc_attr($edit_location->location_address) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="latitude">Latitude</label></th>
                                <td><input type="number" step="any" name="latitude" id="latitude"
                                           placeholder="e.g. 40.7128"
                                           value="<?php echo ($edit_location && $edit_location->latitude !== null) ? esc_attr($edit_location->latitude) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="longitude">Longitude</label></th>
                                <td><input type="number" step="any" name="longitude" id="longitude"
                                           placeholder="e.g. -74.0060"
                                           value="<?php echo ($edit_location && $edit_location->longitude !== null) ? esc_attr($edit_location->longitude) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="travel_buffer_minutes">Travel buffer (min)</label></th>
                                <td>
                                    <input type="number" name="travel_buffer_minutes" id="travel_buffer_minutes"
                                           min="0" step="5" value="<?php echo $edit_location ? intval($edit_location->travel_buffer_minutes) : 30; ?>">
                                    <p class="description">
                                        Extra minutes added per location when checking conflicts between
                                        two different locations (parking, warm-up, etc.).
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button($edit_location ? 'Update Location' : 'Add Location', 'primary',
                            $edit_location ? 'smm_update_location' : 'smm_add_location'); ?>
                        <?php if ($edit_location): ?>
                            <a href="<?php echo admin_url('admin.php?page=smm-locations'); ?>">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="smm-players-list-wrap">
                    <h2>All Locations</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr>
                            <th>Name</th><th>Address</th><th>Coordinates</th><th>Buffer</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($locations)): ?>
                            <tr><td colspan="5">No locations yet.</td></tr>
                        <?php else: foreach ($locations as $l): ?>
                            <tr>
                                <td><?php echo esc_html($l->location_name); ?></td>
                                <td><?php echo esc_html($l->location_address); ?></td>
                                <td>
                                    <?php if ($l->latitude !== null && $l->longitude !== null): ?>
                                        <?php echo esc_html($l->latitude . ', ' . $l->longitude); ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?php echo intval($l->travel_buffer_minutes); ?> min</td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=smm-locations&edit=' . $l->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-locations&action=delete_location&id=' . $l->id), 'smm_del_location_' . $l->id); ?>"
                                       onclick="return confirm('Delete this location?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}