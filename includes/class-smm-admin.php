<?php
class SMM_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_init', array($this, 'handle_forms'));
    }

    public function menu() {
        add_menu_page('Soccer Matches', 'Soccer Matches', 'manage_options',
            'smm-matches', array($this, 'matches_page'), 'dashicons-schedule', 30);

        add_submenu_page('smm-matches', 'All Matches', 'All Matches',
            'manage_options', 'smm-matches', array($this, 'matches_page'));

        add_submenu_page('smm-matches', 'Add Match', 'Add Match',
            'manage_options', 'smm-add-match', array($this, 'add_match_page'));

        add_submenu_page('smm-matches', 'Players', 'Players',
            'manage_options', 'smm-players', array($this, 'players_page'));
    }

    public function assets($hook) {
        if (strpos($hook, 'smm-') === false) return;
        wp_enqueue_style('smm-admin', SMM_PLUGIN_URL . 'assets/admin.css', array(), SMM_VERSION);
        wp_enqueue_script('smm-admin', SMM_PLUGIN_URL . 'assets/admin.js', array('jquery'), SMM_VERSION, true);
    }

    public function handle_forms() {
        // Save match
        if (isset($_POST['smm_save_match']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_save_match')) {
            $data = array(
                'match_date' => sanitize_text_field($_POST['match_date']),
                'match_time' => sanitize_text_field($_POST['match_time']),
                'home_team' => sanitize_text_field($_POST['home_team']),
                'away_team' => sanitize_text_field($_POST['away_team']),
                'location' => sanitize_text_field($_POST['location']),
            );

            $match_id = !empty($_POST['match_id']) ? intval($_POST['match_id']) : 0;

            if ($match_id) {
                SMM_Database::update_match($match_id, $data);
            } else {
                $match_id = SMM_Database::insert_match($data);
            }

            $attending = isset($_POST['attending_players']) ? array_map('intval', (array) $_POST['attending_players']) : array();
            SMM_Database::set_attendance($match_id, $attending);

            wp_redirect(admin_url('admin.php?page=smm-matches&message='
                . ($match_id && !empty($_POST['match_id']) ? 'updated' : 'added')));
            exit;
        }

        // Delete match
        if (isset($_GET['action']) && $_GET['action'] === 'delete_match' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_match_' . $_GET['id'])) {
                SMM_Database::delete_match(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-matches&message=deleted'));
                exit;
            }
        }

        // Add player
        if (isset($_POST['smm_add_player']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_player')) {
            SMM_Players::add($_POST['player_name'], $_POST['player_email'] ?? '');
            wp_redirect(admin_url('admin.php?page=smm-players&message=player_added'));
            exit;
        }

        // Update player
        if (isset($_POST['smm_update_player']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_player')) {
            SMM_Players::update(
                intval($_POST['player_id']),
                $_POST['player_name'],
                $_POST['player_email'] ?? '',
                isset($_POST['is_active'])
            );
            wp_redirect(admin_url('admin.php?page=smm-players&message=player_updated'));
            exit;
        }

        // Delete player
        if (isset($_GET['action']) && $_GET['action'] === 'delete_player' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_player_' . $_GET['id'])) {
                SMM_Players::delete(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-players&message=player_deleted'));
                exit;
            }
        }
    }

    /* ---------- MATCHES LIST ---------- */

    public function matches_page() {
        $matches = SMM_Database::get_matches();
        $checker = new SMM_Conflict_Checker();
        ?>
        <div class="wrap">
            <h1>Soccer Matches
                <a href="<?php echo admin_url('admin.php?page=smm-add-match'); ?>" class="page-title-action">Add New</a>
            </h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                        $msgs = array(
                            'added' => 'Match added.',
                            'updated' => 'Match updated.',
                            'deleted' => 'Match deleted.'
                        );
                        echo esc_html($msgs[$_GET['message']] ?? 'Done.');
                    ?></p>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Home</th>
                        <th>Away</th>
                        <th>Location</th>
                        <th>Attending Players</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($matches)): ?>
                    <tr><td colspan="8">No matches yet.</td></tr>
                <?php else: foreach ($matches as $m):
                    $conflicts = $checker->check_match_conflicts($m->id);
                    $attendance = SMM_Database::get_attendance($m->id);
                    $names = wp_list_pluck($attendance, 'player_name');
                    ?>
                    <tr class="<?php echo !empty($conflicts) ? 'smm-has-conflict' : ''; ?>">
                        <td><?php echo esc_html($m->match_date); ?></td>
                        <td><?php echo esc_html(date('g:i A', strtotime($m->match_time))); ?></td>
                        <td><?php echo esc_html($m->home_team); ?></td>
                        <td><?php echo esc_html($m->away_team); ?></td>
                        <td><?php echo esc_html($m->location); ?></td>
                        <td><?php echo $names ? esc_html(implode(', ', $names)) : '—'; ?></td>
                        <td>
                            <?php if (!empty($conflicts)): ?>
                                <span class="smm-conflict-warning"
                                      title="<?php echo esc_attr(implode("\n", $conflicts)); ?>">
                                    ⚠️ <?php echo count($conflicts); ?> conflict(s)
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

    /* ---------- ADD/EDIT MATCH ---------- */

    public function add_match_page() {
        $match = null;
        $selected = array();

        if (isset($_GET['id'])) {
            $match = SMM_Database::get_match(intval($_GET['id']));
            if ($match) {
                $selected = array_map('intval', SMM_Database::get_attending_player_ids($match->id));
            }
        }

        $players = SMM_Players::get_all(true);
        ?>
        <div class="wrap">
            <h1><?php echo $match ? 'Edit Match' : 'Add New Match'; ?></h1>

            <?php if (empty($players)): ?>
                <div class="notice notice-warning">
                    <p>You haven't added any players yet.
                        <a href="<?php echo admin_url('admin.php?page=smm-players'); ?>">Add players here</a> first.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post">
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
                        <th><label for="home_team">Home Team</label></th>
                        <td><input type="text" name="home_team" id="home_team" class="regular-text" required
                                   value="<?php echo $match ? esc_attr($match->home_team) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="away_team">Away Team</label></th>
                        <td><input type="text" name="away_team" id="away_team" class="regular-text" required
                                   value="<?php echo $match ? esc_attr($match->away_team) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="location">Location</label></th>
                        <td><input type="text" name="location" id="location" class="regular-text" required
                                   value="<?php echo $match ? esc_attr($match->location) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th>Attending Players</th>
                        <td>
                            <fieldset class="smm-players-checkboxes">
                                <legend class="screen-reader-text">Select attending players</legend>
                                <?php if (empty($players)): ?>
                                    <em>No active players yet.</em>
                                <?php else: foreach ($players as $p): ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox" name="attending_players[]"
                                               value="<?php echo $p->id; ?>"
                                               <?php checked(in_array($p->id, $selected)); ?>>
                                        <?php echo esc_html($p->player_name); ?>
                                    </label>
                                <?php endforeach; endif; ?>
                                <p class="description">
                                    Select all players attending this match.
                                    <a href="<?php echo admin_url('admin.php?page=smm-players'); ?>">Manage players</a>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <?php submit_button($match ? 'Update Match' : 'Add Match', 'primary', 'smm_save_match'); ?>
            </form>
        </div>
        <?php
    }

    /* ---------- PLAYERS PAGE ---------- */

    public function players_page() {
        $players = SMM_Players::get_all();
        $edit_player = null;
        if (isset($_GET['edit'])) {
            $edit_player = SMM_Players::get(intval($_GET['edit']));
        }
        ?>
        <div class="wrap">
            <h1>Players</h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                        $msgs = array(
                            'player_added' => 'Player added.',
                            'player_updated' => 'Player updated.',
                            'player_deleted' => 'Player deleted.'
                        );
                        echo esc_html($msgs[$_GET['message']] ?? 'Done.');
                    ?></p>
                </div>
            <?php endif; ?>

            <div class="smm-players-layout">

                <!-- Add / Edit form -->
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
                            <?php if ($edit_player): ?>
                            <tr>
                                <th>Active</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="is_active" value="1"
                                            <?php checked($edit_player->is_active, 1); ?>>
                                        Player is active
                                    </label>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                        <?php submit_button(
                            $edit_player ? 'Update Player' : 'Add Player',
                            'primary',
                            $edit_player ? 'smm_update_player' : 'smm_add_player'
                        ); ?>
                        <?php if ($edit_player): ?>
                            <a href="<?php echo admin_url('admin.php?page=smm-players'); ?>">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Players list -->
                <div class="smm-players-list-wrap">
                    <h2>All Players</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($players)): ?>
                            <tr><td colspan="4">No players yet. Add your first one!</td></tr>
                        <?php else: foreach ($players as $p): ?>
                            <tr>
                                <td><?php echo esc_html($p->player_name); ?></td>
                                <td><?php echo esc_html($p->player_email); ?></td>
                                <td><?php echo $p->is_active ? '✓ Active' : '— Inactive'; ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=smm-players&edit=' . $p->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-players&action=delete_player&id=' . $p->id), 'smm_del_player_' . $p->id); ?>"
                                       onclick="return confirm('Delete this player? Their attendance records will also be removed.');">Delete</a>
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