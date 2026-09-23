<?php
class SMM_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Soccer Matches',
            'Soccer Matches',
            'manage_options',
            'smm-matches',
            array($this, 'matches_page'),
            'dashicons-schedule',
            30
        );
        
        add_submenu_page(
            'smm-matches',
            'Add New Match',
            'Add New',
            'manage_options',
            'smm-add-match',
            array($this, 'add_match_page')
        );
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'smm-') === false) {
            return;
        }
        
        wp_enqueue_style('smm-admin', SMM_PLUGIN_URL . 'assets/admin.css', array(), SMM_VERSION);
        wp_enqueue_script('smm-admin', SMM_PLUGIN_URL . 'assets/admin.js', array('jquery'), SMM_VERSION, true);
    }
    
    public function handle_form_submissions() {
        // Handle add/edit match
        if (isset($_POST['smm_save_match']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_save_match')) {
            $data = array(
                'match_date' => sanitize_text_field($_POST['match_date']),
                'match_time' => sanitize_text_field($_POST['match_time']),
                'home_team' => sanitize_text_field($_POST['home_team']),
                'away_team' => sanitize_text_field($_POST['away_team']),
                'location' => sanitize_text_field($_POST['location']),
                'player1_attending' => isset($_POST['player1_attending']) ? 1 : 0,
                'player2_attending' => isset($_POST['player2_attending']) ? 1 : 0,
                'player3_attending' => isset($_POST['player3_attending']) ? 1 : 0
            );
            
            if (!empty($_POST['match_id'])) {
                SMM_Database::update_match(intval($_POST['match_id']), $data);
                wp_redirect(admin_url('admin.php?page=smm-matches&message=updated'));
            } else {
                SMM_Database::insert_match($data);
                wp_redirect(admin_url('admin.php?page=smm-matches&message=added'));
            }
            exit;
        }
        
        // Handle delete
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_delete_' . $_GET['id'])) {
                SMM_Database::delete_match(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-matches&message=deleted'));
                exit;
            }
        }
    }
    
    public function matches_page() {
        $matches = SMM_Database::get_matches();
        $conflict_checker = new SMM_Conflict_Checker();
        ?>
        <div class="wrap">
            <h1>Soccer Matches</h1>
            
            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php 
                        switch($_GET['message']) {
                            case 'added': echo 'Match added successfully.'; break;
                            case 'updated': echo 'Match updated successfully.'; break;
                            case 'deleted': echo 'Match deleted successfully.'; break;
                        }
                    ?></p>
                </div>
            <?php endif; ?>
            
            <a href="<?php echo admin_url('admin.php?page=smm-add-match'); ?>" class="button button-primary">Add New Match</a>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Home Team</th>
                        <th>Away Team</th>
                        <th>Location</th>
                        <th>Player 1</th>
                        <th>Player 2</th>
                        <th>Player 3</th>
                        <th>Conflicts</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matches)): ?>
                        <tr>
                            <td colspan="10">No matches found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($matches as $match): ?>
                            <?php $conflicts = $conflict_checker->check_match_conflicts($match->id); ?>
                            <tr class="<?php echo !empty($conflicts) ? 'smm-has-conflict' : ''; ?>">
                                <td><?php echo esc_html($match->match_date); ?></td>
                                <td><?php echo esc_html($match->match_time); ?></td>
                                <td><?php echo esc_html($match->home_team); ?></td>
                                <td><?php echo esc_html($match->away_team); ?></td>
                                <td><?php echo esc_html($match->location); ?></td>
                                <td><?php echo $match->player1_attending ? '✓' : '✗'; ?></td>
                                <td><?php echo $match->player2_attending ? '✓' : '✗'; ?></td>
                                <td><?php echo $match->player3_attending ? '✓' : '✗'; ?></td>
                                <td>
                                    <?php if (!empty($conflicts)): ?>
                                        <span class="smm-conflict-warning" title="<?php echo esc_attr(implode(', ', $conflicts)); ?>">
                                            ⚠️ <?php echo count($conflicts); ?> conflict(s)
                                        </span>
                                    <?php else: ?>
                                        <span class="smm-no-conflict">✓ None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=smm-add-match&id=' . $match->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-matches&action=delete&id=' . $match->id), 'smm_delete_' . $match->id); ?>" 
                                       onclick="return confirm('Are you sure?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function add_match_page() {
        $match = null;
        if (isset($_GET['id'])) {
            $match = SMM_Database::get_match(intval($_GET['id']));
        }
        ?>
        <div class="wrap">
            <h1><?php echo $match ? 'Edit Match' : 'Add New Match'; ?></h1>
            
            <form method="post" action="">
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
                        <td><input type="text" name="home_team" id="home_team" required 
                                   value="<?php echo $match ? esc_attr($match->home_team) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="away_team">Away Team</label></th>
                        <td><input type="text" name="away_team" id="away_team" required 
                                   value="<?php echo $match ? esc_attr($match->away_team) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="location">Location</label></th>
                        <td><input type="text" name="location" id="location" required 
                                   value="<?php echo $match ? esc_attr($match->location) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th>Player Attendance</th>
                        <td>
                            <label><input type="checkbox" name="player1_attending" value="1" 
                                          <?php echo ($match && $match->player1_attending) ? 'checked' : ''; ?>> Player 1</label><br>
                            <label><input type="checkbox" name="player2_attending" value="1" 
                                          <?php echo ($match && $match->player2_attending) ? 'checked' : ''; ?>> Player 2</label><br>
                            <label><input type="checkbox" name="player3_attending" value="1" 
                                          <?php echo ($match && $match->player3_attending) ? 'checked' : ''; ?>> Player 3</label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button($match ? 'Update Match' : 'Add Match', 'primary', 'smm_save_match'); ?>
            </form>
        </div>
        <?php
    }
}