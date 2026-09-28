<?php
class SMM_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_init', array($this, 'handle_forms'));
        add_action('admin_notices', array($this, 'conflict_banner'));
        add_action('wp_ajax_smm_get_league_data', array($this, 'ajax_get_league_data'));
        add_action('wp_ajax_smm_get_team_players', array($this, 'ajax_get_team_players'));
    }

    public function menu() {
        add_menu_page('Soccer Matches', 'Soccer Matches', 'manage_options',
            'smm-matches', array($this, 'matches_page'), 'dashicons-schedule', 30);

        add_submenu_page('smm-matches', 'All Matches', 'All Matches',
            'manage_options', 'smm-matches', array($this, 'matches_page'));

        add_submenu_page('smm-matches', 'Add Match', 'Add Match',
            'manage_options', 'smm-add-match', array($this, 'add_match_page'));

        add_submenu_page('smm-matches', 'Leagues', 'Leagues',
            'manage_options', 'smm-leagues', array($this, 'leagues_page'));

        add_submenu_page('smm-matches', 'Competitions', 'Competitions',
            'manage_options', 'smm-competitions', array($this, 'competitions_page'));

        add_submenu_page('smm-matches', 'Teams', 'Teams',
            'manage_options', 'smm-teams', array($this, 'teams_page'));

        add_submenu_page('smm-matches', 'Players', 'Players',
            'manage_options', 'smm-players', array($this, 'players_page'));

        add_submenu_page('smm-matches', 'Locations', 'Locations',
            'manage_options', 'smm-locations', array($this, 'locations_page'));

        add_submenu_page('smm-matches', 'Import / Export', 'Import / Export',
            'manage_options', 'smm-import-export', array($this, 'import_export_page'));

        add_submenu_page('smm-matches', 'Settings', 'Settings',
            'manage_options', 'smm-settings', array($this, 'settings_page'));
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

    /* ============================================================
       AJAX
       ============================================================ */

    /**
     * Returns teams and competitions for a given league, plus the
     * set of "my team" IDs (teams with active players).
     */
    public function ajax_get_league_data() {
        check_ajax_referer('smm_ajax', 'nonce');
        $league_id = intval($_POST['league_id'] ?? 0);

        $teams = array();
        foreach (SMM_Teams::get_by_league($league_id) as $t) {
            $teams[] = array(
                'id'       => intval($t->id),
                'name'     => $t->team_name,
                'league_id'=> intval($t->league_id),
            );
        }

        $comps = array();
        foreach (SMM_Competitions::get_for_league($league_id) as $c) {
            $comps[] = array(
                'id'                => intval($c->id),
                'name'              => $c->competition_name,
                'short_label'       => $c->short_label,
                'season'            => $c->season,
                'league_id'         => intval($c->league_id),
                'computed_duration' => SMM_Competitions::compute_duration_from_row($c),
            );
        }

        $my_team_ids = array_keys(SMM_Teams::get_teams_with_active_players());

        wp_send_json_success(array(
            'teams'        => $teams,
            'competitions' => $comps,
            'my_team_ids'  => array_map('intval', $my_team_ids),
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

    /* ============================================================
       ADMIN NOTICES
       ============================================================ */

    public function conflict_banner() {
        if (!current_user_can('manage_options')) return;
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page_smm-matches' && isset($_GET['filter_conflicts'])) {
            return;
        }

        $checker = new SMM_Conflict_Checker();
        $count = $checker->count_conflicts();
        if (!$count) return;

        $url = admin_url('admin.php?page=smm-matches&filter_conflicts=1');
        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>Soccer Matches:</strong> %d upcoming match%s have schedule conflicts. <a href="%s">Review now →</a></p></div>',
            $count,
            $count === 1 ? '' : 'es',
            esc_url($url)
        );
    }

    /* ============================================================
       FORM HANDLING
       ============================================================ */

    public function handle_forms() {
        /* ---- SAVE MATCH ---- */
        if (isset($_POST['smm_save_match']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_save_match')) {
            $this->save_match_from_post();
        }

        /* ---- DELETE MATCH ---- */
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_match') {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_match_' . $_GET['id'])) {
                SMM_Database::delete_match(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-matches&message=deleted'));
                exit;
            }
        }

        /* ---- BULK STATUS CHANGE ---- */
        if (isset($_POST['smm_bulk_status'], $_POST['match_ids']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_bulk_status')) {
            $status = sanitize_key($_POST['smm_bulk_status']);
            if (array_key_exists($status, SMM_Helpers::statuses())) {
                foreach ((array) $_POST['match_ids'] as $id) {
                    SMM_Database::update_match(intval($id), array('status' => $status));
                }
            }
            wp_redirect(add_query_arg('message', 'bulk_updated', wp_get_referer()));
            exit;
        }

        /* ---- LEAGUES ---- */
        if (isset($_POST['smm_add_league']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_league')) {
            SMM_Leagues::add(
                $_POST['league_name'] ?? '',
                $_POST['season'] ?? '',
                $_POST['age_group'] ?? '',
                $_POST['color'] ?? '#0d6efd',
                $_POST['notes'] ?? '',
                intval($_POST['league_logo_id'] ?? 0)
            );
            wp_redirect(admin_url('admin.php?page=smm-leagues&message=league_added'));
            exit;
        }
        if (isset($_POST['smm_update_league']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_league')) {
            SMM_Leagues::update(
                intval($_POST['league_id']),
                $_POST['league_name'] ?? '',
                $_POST['season'] ?? '',
                $_POST['age_group'] ?? '',
                $_POST['color'] ?? '#0d6efd',
                $_POST['notes'] ?? '',
                intval($_POST['league_logo_id'] ?? 0)
            );
            wp_redirect(admin_url('admin.php?page=smm-leagues&message=league_updated'));
            exit;
        }
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_league') {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_league_' . $_GET['id'])) {
                SMM_Leagues::delete(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-leagues&message=league_deleted'));
                exit;
            }
        }

        /* ---- COMPETITIONS ---- */
        if (isset($_POST['smm_add_competition']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_competition')) {
            SMM_Competitions::add(
                $_POST['competition_name'] ?? '',
                $_POST['short_label'] ?? '',
                $_POST['season'] ?? '',
                $_POST['age_group'] ?? '',
                $_POST['color'] ?? '#0d6efd',
                $_POST['notes'] ?? '',
                intval($_POST['league_id'] ?? 0),
                intval($_POST['periods'] ?? 2),
                intval($_POST['period_minutes'] ?? 45),
                intval($_POST['break_minutes'] ?? 0),
                intval($_POST['halftime_minutes'] ?? 10),
                0
            );
            wp_redirect(admin_url('admin.php?page=smm-competitions&message=competition_added'));
            exit;
        }
        if (isset($_POST['smm_update_competition']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_competition')) {
            SMM_Competitions::update(
                intval($_POST['competition_id']),
                $_POST['competition_name'] ?? '',
                $_POST['short_label'] ?? '',
                $_POST['season'] ?? '',
                $_POST['age_group'] ?? '',
                $_POST['color'] ?? '#0d6efd',
                $_POST['notes'] ?? '',
                intval($_POST['league_id'] ?? 0),
                intval($_POST['periods'] ?? 2),
                intval($_POST['period_minutes'] ?? 45),
                intval($_POST['break_minutes'] ?? 0),
                intval($_POST['halftime_minutes'] ?? 10),
                0
            );
            wp_redirect(admin_url('admin.php?page=smm-competitions&message=competition_updated'));
            exit;
        }
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_competition') {
            if (wp_verify_nonce($_GET['_wpnonce'], 'smm_del_competition_' . $_GET['id'])) {
                SMM_Competitions::delete(intval($_GET['id']));
                wp_redirect(admin_url('admin.php?page=smm-competitions&message=competition_deleted'));
                exit;
            }
        }

        /* ---- TEAMS ---- */
        if (isset($_POST['smm_add_team']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_team')) {
            SMM_Teams::add(
                $_POST['team_name'] ?? '',
                intval($_POST['team_logo_id'] ?? 0),
                $_POST['default_duration'] ?? null,
                intval($_POST['team_league_id'] ?? 0)
            );
            wp_redirect(admin_url('admin.php?page=smm-teams&message=team_added'));
            exit;
        }
        if (isset($_POST['smm_update_team']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_team')) {
            SMM_Teams::update(
                intval($_POST['team_id']),
                $_POST['team_name'] ?? '',
                intval($_POST['team_logo_id'] ?? 0),
                $_POST['default_duration'] ?? null,
                intval($_POST['team_league_id'] ?? 0)
            );
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

        /* ---- PLAYERS (unchanged) ---- */
        if (isset($_POST['smm_add_player']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_player')) {
            SMM_Players::add($_POST['player_name'] ?? '', $_POST['player_email'] ?? '',
                intval($_POST['player_team_id'] ?? 0), $_POST['availability'] ?? 'available');
            wp_redirect(admin_url('admin.php?page=smm-players&message=player_added'));
            exit;
        }
        if (isset($_POST['smm_update_player']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_player')) {
            SMM_Players::update(
                intval($_POST['player_id']),
                $_POST['player_name'] ?? '',
                $_POST['player_email'] ?? '',
                intval($_POST['player_team_id'] ?? 0),
                $_POST['availability'] ?? 'available',
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

        /* ---- LOCATIONS (unchanged) ---- */
        if (isset($_POST['smm_add_location']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_add_location')) {
            SMM_Locations::add(
                $_POST['location_name'] ?? '', $_POST['location_address'] ?? '',
                $_POST['latitude'] ?? null, $_POST['longitude'] ?? null,
                intval($_POST['travel_buffer_minutes'] ?? 30));
            wp_redirect(admin_url('admin.php?page=smm-locations&message=location_added'));
            exit;
        }
        if (isset($_POST['smm_update_location']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_update_location')) {
            SMM_Locations::update(
                intval($_POST['location_id']),
                $_POST['location_name'] ?? '', $_POST['location_address'] ?? '',
                $_POST['latitude'] ?? null, $_POST['longitude'] ?? null,
                intval($_POST['travel_buffer_minutes'] ?? 30));
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

        /* ---- CSV IMPORT ---- */
        if (!empty($_POST['smm_import_entity']) && !empty($_FILES['csv_file']['tmp_name'])) {
            $entity = sanitize_key($_POST['smm_import_entity']);
            $allowed = array('leagues','locations','competitions','teams','players','matches');

            if (in_array($entity, $allowed, true)
                && wp_verify_nonce($_POST['smm_nonce'], 'smm_import_' . $entity)) {

                $update = !empty($_POST['update_existing']);
                $file = $_FILES['csv_file']['tmp_name'];

                switch ($entity) {
                    case 'leagues':      $res = SMM_CSV_Entities::import_leagues($file, $update); break;
                    case 'locations':    $res = SMM_CSV_Entities::import_locations($file, $update); break;
                    case 'competitions': $res = SMM_CSV_Entities::import_competitions($file, $update); break;
                    case 'teams':        $res = SMM_CSV_Entities::import_teams($file, $update); break;
                    case 'players':      $res = SMM_CSV_Entities::import_players($file, $update); break;
                    case 'matches':
                    default:             $res = SMM_CSV::import_matches($file); break;
                }
                set_transient('smm_import_result_' . get_current_user_id(), $res, 60);
            }
            wp_redirect(admin_url('admin.php?page=smm-import-export&message=imported'));
            exit;
        }

        /* ---- CSV EXPORT ---- */
        if (isset($_GET['smm_export'])) {
            $entity = sanitize_key($_GET['smm_export']);
            $allowed = array('leagues','locations','competitions','teams','players','matches');
            if (in_array($entity, $allowed, true)
                && wp_verify_nonce($_GET['_wpnonce'], 'smm_export_' . $entity)) {

                nocache_headers();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename='
                    . $entity . '-' . SMM_Helpers::today_ymd() . '.csv');

                switch ($entity) {
                    case 'leagues':      echo SMM_CSV_Entities::export_leagues(); break;
                    case 'locations':    echo SMM_CSV_Entities::export_locations(); break;
                    case 'competitions': echo SMM_CSV_Entities::export_competitions(); break;
                    case 'teams':        echo SMM_CSV_Entities::export_teams(); break;
                    case 'players':      echo SMM_CSV_Entities::export_players(); break;
                    case 'matches':
                    default:             echo SMM_CSV::export_matches(); break;
                }
                exit;
            }
        }

        /* ---- SETTINGS ---- */
        if (isset($_POST['smm_save_settings']) && wp_verify_nonce($_POST['smm_nonce'], 'smm_settings')) {
            update_option('smm_travel_speed_kmh', intval($_POST['smm_travel_speed_kmh'] ?? 50));
            wp_redirect(admin_url('admin.php?page=smm-settings&message=saved'));
            exit;
        }
    }

    private function save_match_from_post() {
        $home_id = intval($_POST['home_team_id'] ?? 0);
        $away_id = intval($_POST['away_team_id'] ?? 0);
        $loc_id  = intval($_POST['location_id'] ?? 0);
        $comp_id = intval($_POST['competition_id'] ?? 0);

        // Time: may be null if "TBD" was checked
        $time_tbd = !empty($_POST['time_tbd']);
        $match_time = null;
        if (!$time_tbd) {
            $raw_time = trim($_POST['match_time'] ?? '');
            if ($raw_time !== '') {
                $match_time = sanitize_text_field($raw_time);
            }
        }

        $duration = !empty($_POST['match_duration']) ? intval($_POST['match_duration']) : null;
        if ($duration !== null && $duration <= 0) $duration = null;

        $status = sanitize_key($_POST['status'] ?? 'scheduled');
        if (!array_key_exists($status, SMM_Helpers::statuses())) $status = 'scheduled';

        $data = array(
            'match_date'     => sanitize_text_field($_POST['match_date']),
            'match_time'     => $match_time,
            'match_duration' => $duration,
            'home_team_id'   => $home_id,
            'away_team_id'   => $away_id,
            'home_team'      => SMM_Teams::get_name($home_id),
            'away_team'      => SMM_Teams::get_name($away_id),
            'location_id'    => $loc_id,
            'location'       => $loc_id ? SMM_Locations::get_name($loc_id)
                                        : sanitize_text_field($_POST['location'] ?? ''),
            'status'         => $status,
            'competition_id' => $comp_id,
            'competition'    => $comp_id ? SMM_Competitions::get_name($comp_id) : '',
            'round'          => sanitize_text_field($_POST['round'] ?? ''),
            'notes'          => sanitize_textarea_field($_POST['notes'] ?? ''),
        );

        $repeat = !empty($_POST['repeat_weeks']) ? max(1, intval($_POST['repeat_weeks'])) : 1;
        $match_id = !empty($_POST['match_id']) ? intval($_POST['match_id']) : 0;

        if ($match_id) {
            SMM_Database::update_match($match_id, $data);
            $this->save_attendance_from_post($match_id, $home_id, $away_id);
            wp_redirect(admin_url('admin.php?page=smm-matches&message=updated'));
            exit;
        }

        $created = 0;
        for ($i = 0; $i < $repeat; $i++) {
            $row = $data;
            if ($i > 0) {
                $row['match_date'] = wp_date(
                    'Y-m-d',
                    strtotime($data['match_date'] . ' +' . ($i * 7) . ' days'),
                    SMM_Helpers::tz()
                );
            }
            $new_id = SMM_Database::insert_match($row);
            if ($new_id) {
                $this->save_attendance_from_post($new_id, $home_id, $away_id);
                $created++;
            }
        }

        wp_redirect(admin_url('admin.php?page=smm-matches&message=added&count=' . $created));
        exit;
    }

    private function save_attendance_from_post($match_id, $home_id, $away_id) {
        $manual = isset($_POST['attending_players'])
            ? array_map('intval', (array) $_POST['attending_players'])
            : array();
        $auto = $this->get_auto_attending_players($home_id, $away_id);
        SMM_Database::set_attendance($match_id, $manual, $auto);
    }

    private function get_auto_attending_players($home_team_id, $away_team_id) {
        $ids = array();
        foreach (array($home_team_id, $away_team_id) as $tid) {
            if (!$tid) continue;
            foreach (SMM_Players::get_by_team($tid, true) as $p) {
                if (SMM_Helpers::availability_counts_for_conflict($p->availability)) {
                    $ids[] = intval($p->id);
                }
            }
        }
        return array_unique($ids);
    }

    /* ============================================================
       SORT HELPERS
       ============================================================ */

    private function sortable_match_columns() {
        return array(
            'match_date'     => array('sql' => 'match_date',     'label' => 'Date'),
            'match_time'     => array('sql' => 'match_time',     'label' => 'Time'),
            'match_duration' => array('sql' => 'match_duration', 'label' => 'Duration'),
            'competition'    => array('sql' => 'competition',    'label' => 'Competition'),
            'home_team'      => array('sql' => 'home_team',      'label' => 'Home'),
            'away_team'      => array('sql' => 'away_team',      'label' => 'Away'),
            'round'          => array('sql' => 'round',          'label' => 'Round'),
            'location'       => array('sql' => 'location',       'label' => 'Location'),
            'status'         => array('sql' => 'status',         'label' => 'Status'),
        );
    }

    private function sort_link($key, $label, $current_by, $current_order) {
        $cols = $this->sortable_match_columns();
        if (!isset($cols[$key])) return esc_html($label);
        $sql_col = $cols[$key]['sql'];

        $is_current = ($current_by === $sql_col)
            || (strpos(',' . $current_by . ',', ',' . $sql_col . ',') !== false);

        $next_order = 'ASC';
        if ($is_current) {
            $next_order = (strtoupper($current_order) === 'ASC') ? 'DESC' : 'ASC';
        }

        $args = $_GET;
        $args['orderby'] = $sql_col;
        $args['order']   = $next_order;
        unset($args['message'], $args['count']);

        $url = add_query_arg($args, admin_url('admin.php'));

        $arrow = '';
        if ($is_current) {
            $arrow = (strtoupper($current_order) === 'ASC') ? ' ▲' : ' ▼';
        }

        return sprintf(
            '<a href="%s" class="smm-sort-link%s">%s%s</a>',
            esc_url($url),
            $is_current ? ' smm-sort-active' : '',
            esc_html($label),
            $arrow
        );
    }

    /* ============================================================
       PAGE: MATCHES LIST
       ============================================================ */

    public function matches_page() {
        $f_search      = sanitize_text_field($_GET['s'] ?? '');
        $f_date_from   = sanitize_text_field($_GET['date_from'] ?? '');
        $f_date_to     = sanitize_text_field($_GET['date_to'] ?? '');
        $f_team        = intval($_GET['f_team'] ?? 0);
        $f_location    = intval($_GET['f_location'] ?? 0);
        $f_status      = sanitize_key($_GET['f_status'] ?? '');
        $f_competition = intval($_GET['f_competition'] ?? 0);
        $f_conflicts   = !empty($_GET['filter_conflicts']);
        $show_past     = !empty($_GET['show_past']);

        $sortable = $this->sortable_match_columns();
        $allowed_sql_cols = array_map(function($c) { return $c['sql']; }, $sortable);

        $orderby = sanitize_key($_GET['orderby'] ?? 'match_date');
        if (!in_array($orderby, $allowed_sql_cols, true)) $orderby = 'match_date';
        $order = (strtoupper($_GET['order'] ?? '') === 'DESC') ? 'DESC' : 'ASC';
        if (!isset($_GET['order'])) $order = $show_past ? 'DESC' : 'ASC';

        $effective_orderby = $orderby;
        if ($orderby !== 'match_date' && $orderby !== 'match_time') {
            $effective_orderby = $orderby . ', match_date, match_time';
        }

        $where = '';
        if ($show_past) {
            // no date floor
        } elseif (!empty($f_date_from)) {
            $where = 'match_date >= "' . esc_sql($f_date_from) . '"';
        } else {
            $where = 'match_date >= "' . esc_sql(SMM_Helpers::today_ymd()) . '"';
        }

        $matches = SMM_Database::get_matches(array(
            'where'          => $where,
            'search'         => $f_search,
            'date_from'      => $f_date_from,
            'date_to'        => $f_date_to,
            'team_id'        => $f_team,
            'location_id'    => $f_location,
            'status'         => $f_status,
            'competition_id' => $f_competition,
            'orderby'        => $effective_orderby,
            'order'          => $order,
        ));

        $checker = new SMM_Conflict_Checker();
        if ($f_conflicts) {
            $matches = array_values(array_filter($matches, function($m) use ($checker) {
                return !empty($checker->check_match_conflicts($m->id));
            }));
        }

        $teams        = SMM_Teams::get_all();
        $locations    = SMM_Locations::get_all();
        $competitions = SMM_Competitions::get_all();
        $statuses     = SMM_Helpers::statuses();
        ?>
        <div class="wrap">
            <h1>Soccer Matches
                <a href="<?php echo admin_url('admin.php?page=smm-add-match'); ?>" class="page-title-action">Add New</a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-matches&smm_export=matches'), 'smm_export_matches'); ?>"
                   class="page-title-action">Export CSV</a>
            </h1>

            <?php
            $by_player = $checker->get_conflicts_by_player();
            if (!empty($by_player)):
                $total = 0;
                foreach ($by_player as $g) $total += $g['count'];
                ?>
                <details class="smm-conflict-panel">
                    <summary class="smm-conflict-panel__summary">
                        <span class="smm-conflict-panel__icon">⚠️</span>
                        <strong><?php echo intval($total); ?></strong>
                        conflict<?php echo $total === 1 ? '' : 's'; ?>
                        across
                        <strong><?php echo count($by_player); ?></strong>
                        player<?php echo count($by_player) === 1 ? '' : 's'; ?>
                        <span class="smm-conflict-panel__hint">Click to expand</span>
                    </summary>
                    <div class="smm-conflict-panel__body">
                        <?php foreach ($by_player as $name => $g): ?>
                            <div class="smm-conflict-panel__group">
                                <div class="smm-conflict-panel__name">
                                    <?php echo esc_html($name); ?>
                                    <span class="smm-conflict-panel__count"><?php echo intval($g['count']); ?></span>
                                </div>
                                <ul class="smm-conflict-panel__list">
                                    <?php foreach ($g['conflicts'] as $c):
                                        $cm = $c['match'];
                                        $chome = $cm->home_team_id ? SMM_Teams::get_name($cm->home_team_id) : $cm->home_team;
                                        $caway = $cm->away_team_id ? SMM_Teams::get_name($cm->away_team_id) : $cm->away_team;
                                        ?>
                                        <li>
                                            <a href="<?php echo admin_url('admin.php?page=smm-add-match&id=' . $cm->id); ?>">
                                                <?php echo esc_html(SMM_Helpers::fmt_date($cm->match_date)); ?>
                                                <?php echo esc_html(SMM_Helpers::fmt_time($cm->match_time)); ?>
                                                — <?php echo esc_html($chome); ?> vs <?php echo esc_html($caway); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    $m = array(
                        'added' => 'Match added' . (!empty($_GET['count']) ? ' (' . intval($_GET['count']) . ' created)' : '') . '.',
                        'updated' => 'Match updated.',
                        'deleted' => 'Match deleted.',
                        'bulk_updated' => 'Matches updated.',
                    );
                    echo esc_html($m[$_GET['message']] ?? 'Done.');
                ?></p></div>
            <?php endif; ?>

            <form method="get" class="smm-filters">
                <input type="hidden" name="page" value="smm-matches">
                <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
                <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
                <div class="smm-filters-row">
                    <input type="search" name="s" value="<?php echo esc_attr($f_search); ?>"
                           placeholder="Search team, competition, notes…">
                    <label>From <input type="date" name="date_from" value="<?php echo esc_attr($f_date_from); ?>"></label>
                    <label>To <input type="date" name="date_to" value="<?php echo esc_attr($f_date_to); ?>"></label>
                    <select name="f_competition">
                        <option value="0">All competitions</option>
                        <?php foreach ($competitions as $c): ?>
                            <option value="<?php echo $c->id; ?>" <?php selected($f_competition, $c->id); ?>>
                                <?php echo esc_html($c->competition_name . ($c->season ? ' (' . $c->season . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_team">
                        <option value="0">All teams</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?php echo $t->id; ?>" <?php selected($f_team, $t->id); ?>>
                                <?php echo esc_html($t->team_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_location">
                        <option value="0">All locations</option>
                        <?php foreach ($locations as $l): ?>
                            <option value="<?php echo $l->id; ?>" <?php selected($f_location, $l->id); ?>>
                                <?php echo esc_html($l->location_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_status">
                        <option value="">All statuses</option>
                        <?php foreach ($statuses as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($f_status, $k); ?>>
                                <?php echo esc_html($v); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>
                        <input type="checkbox" name="filter_conflicts" value="1" <?php checked($f_conflicts); ?>>
                        Only conflicts
                    </label>
                    <label>
                        <input type="checkbox" name="show_past" value="1" <?php checked($show_past); ?>>
                        Show past
                    </label>
                    <button class="button">Filter</button>
                    <a class="button" href="<?php echo admin_url('admin.php?page=smm-matches'); ?>">Reset</a>
                </div>
            </form>

            <form method="post">
                <?php wp_nonce_field('smm_bulk_status', 'smm_nonce'); ?>
                <div class="smm-bulk-actions">
                    <select name="smm_bulk_status">
                        <option value="">Bulk change status…</option>
                        <?php foreach ($statuses as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" onclick="return confirm('Apply status to selected matches?');">Apply</button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th style="width:28px;"><input type="checkbox" id="smm-check-all"></th>
                        <th><?php echo $this->sort_link('match_date', 'Date', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('match_time', 'Time', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('match_duration', 'Duration', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('competition', 'Competition', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('home_team', 'Home', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('away_team', 'Away', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('round', 'Round', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('location', 'Location', $orderby, $order); ?></th>
                        <th><?php echo $this->sort_link('status', 'Status', $orderby, $order); ?></th>
                        <th>Conflicts</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($matches)): ?>
                        <tr><td colspan="12">No matches match your filters.</td></tr>
                    <?php else: foreach ($matches as $m):
                        $conflicts = $checker->check_match_conflicts($m->id);
                        $home_team = $m->home_team_id ? SMM_Teams::get($m->home_team_id) : null;
                        $away_team = $m->away_team_id ? SMM_Teams::get($m->away_team_id) : null;
                        $home_name = $home_team ? $home_team->team_name : $m->home_team;
                        $away_name = $away_team ? $away_team->team_name : $m->away_team;
                        $loc = $m->location_id ? SMM_Locations::get($m->location_id) : null;
                        ?>
                        <tr class="<?php echo !empty($conflicts) ? 'smm-has-conflict' : ''; ?>">
                            <td><input type="checkbox" name="match_ids[]" value="<?php echo $m->id; ?>"></td>
                            <td><?php echo esc_html(SMM_Helpers::fmt_date($m->match_date)); ?></td>
                            <td>
                                <?php if (empty($m->match_time)): ?>
                                    <span class="smm-tbd">TBD</span>
                                <?php else: ?>
                                    <?php echo esc_html(SMM_Helpers::fmt_time($m->match_time)); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $m->match_duration ? intval($m->match_duration) . ' min' : '—'; ?></td>
                            <td>
                                <?php if ($m->competition_id): ?>
                                    <?php echo SMM_Competitions::badge_html($m->competition_id); ?>
                                <?php else: ?>
                                    <?php echo esc_html($m->competition); ?>
                                <?php endif; ?>
                            </td>
                            <td class="smm-team-cell">
                                <?php echo $home_team ? SMM_Teams::get_logo_html($home_team, array(24,24)) : ''; ?>
                                <?php echo esc_html($home_name); ?>
                            </td>
                            <td class="smm-team-cell">
                                <?php echo $away_team ? SMM_Teams::get_logo_html($away_team, array(24,24)) : ''; ?>
                                <?php echo esc_html($away_name); ?>
                            </td>
                            <td><?php echo esc_html($m->round); ?></td>
                            <td><?php echo esc_html($loc ? $loc->location_name : $m->location); ?></td>
                            <td><?php echo SMM_Helpers::status_badge($m->status); ?></td>
                            <td>
                                <?php if (!empty($conflicts)):
                                    $msgs = array_map(function($c) {
                                        return is_array($c) ? ($c['message'] ?? '') : (string) $c;
                                    }, $conflicts);
                                    ?>
                                    <span class="smm-conflict-warning"
                                          title="<?php echo esc_attr(implode("\n", $msgs)); ?>">
                                        ⚠️ <?php echo count($conflicts); ?>
                                    </span>
                                <?php elseif (empty($m->match_time)): ?>
                                    <span class="smm-no-conflict smm-no-conflict--muted"
                                          title="No time set — not checked for conflicts">—</span>
                                <?php else: ?>
                                    <span class="smm-no-conflict">✓</span>
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
            </form>
        </div>
        <?php
    }

    /* ============================================================
       PAGE: ADD / EDIT MATCH
       ============================================================ */

    public function add_match_page() {
        $match = null;
        $selected = array();
        if (isset($_GET['id'])) {
            $match = SMM_Database::get_match(intval($_GET['id']));
            if ($match) {
                $selected = array_map('intval', SMM_Database::get_attending_player_ids($match->id));
            }
        }

        // Determine initial league based on the match's competition
        // $initial_league_id = 0; // delete unused variable
        // if ($match && $match->competition_id) {
        //     $match_comp = SMM_Competitions::get($match->competition_id);
        //     if ($match_comp) $initial_league_id = intval($match_comp->league_id);
        // }

        // $leagues   = SMM_Leagues::get_all(); // delete unused variable
        $locations = SMM_Locations::get_all();
        $statuses  = SMM_Helpers::statuses();

        // Initial dropdown data (server-rendered, then JS filters on change)
        // $teams_for_league = $initial_league_id
        //     ? SMM_Teams::get_by_league($initial_league_id)
        //     : SMM_Teams::get_all();
        $teams_for_league = SMM_Teams::get_all();
        // $comps_for_league = SMM_Competitions::get_for_league($initial_league_id); // delete unused variable

        $my_team_ids = array_keys(SMM_Teams::get_teams_with_active_players());

        // Sort: my teams first, then alphabetical
        usort($teams_for_league, function($a, $b) use ($my_team_ids) {
            $a_mine = in_array(intval($a->id), $my_team_ids, true);
            $b_mine = in_array(intval($b->id), $my_team_ids, true);
            if ($a_mine !== $b_mine) return $a_mine ? -1 : 1;
            return strcasecmp($a->team_name, $b->team_name);
        });

        $players = SMM_Players::get_all(true);
        ?>
        <div class="wrap">
            <h1><?php echo $match ? 'Edit Match' : 'Add New Match'; ?></h1>

            <?php if (empty($leagues)): ?>
                <div class="notice notice-info"><p>
                    Tip: create a <a href="<?php echo admin_url('admin.php?page=smm-leagues'); ?>">League</a>
                    to organize your teams, then assign teams to it.
                </p></div>
            <?php endif; ?>

            <form method="post" id="smm-match-form"
                  data-my-team-ids="<?php echo esc_attr(implode(',', $my_team_ids)); ?>">
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
                        <td>
                            <input type="time" name="match_time" id="match_time"
                                   value="<?php echo ($match && $match->match_time) ? esc_attr(substr($match->match_time, 0, 5)) : ''; ?>">
                            <label style="margin-left:12px;">
                                <input type="checkbox" name="time_tbd" id="time_tbd" value="1"
                                    <?php checked($match && empty($match->match_time)); ?>>
                                Time TBD
                            </label>
                            <p class="description">
                                Times use your site's timezone (<?php echo esc_html(wp_timezone_string()); ?>).
                                Matches without a time are not checked for conflicts.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="competition_id">Competition</label></th>
                        <td>
                            <select name="competition_id" id="competition_id">
                                <option value="0" data-duration="" data-league="0">— None —</option>
                                <?php
                                $grouped_comps = SMM_Competitions::get_grouped_by_league();
                                $current_comp = $match ? intval($match->competition_id) : 0;
                                foreach ($grouped_comps as $group_label => $items): ?>
                                    <optgroup label="<?php echo esc_attr($group_label); ?>">
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo intval($item['id']); ?>"
                                                    data-duration="<?php echo intval($item['computed_duration']); ?>"
                                                    data-league="<?php echo intval($item['league_id']); ?>"
                                                <?php selected($current_comp, $item['id']); ?>>
                                                <?php echo esc_html($item['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Picking a competition filters the team dropdowns to its league
                                and auto-fills the duration below. Leave blank for a friendly —
                                all active teams appear.
                                <a href="<?php echo admin_url('admin.php?page=smm-competitions'); ?>">Manage competitions →</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="round">Round / Matchday</label></th>
                        <td><input type="text" name="round" id="round" class="small-text"
                                   placeholder="e.g. MD3"
                                   value="<?php echo $match ? esc_attr($match->round) : ''; ?>"></td>
                    </tr>

                    <tr>
                        <th><label for="match_duration">Duration (minutes)</label></th>
                        <td>
                            <input type="number" name="match_duration" id="match_duration"
                                   min="0" step="5" placeholder="e.g. 90"
                                   value="<?php echo ($match && $match->match_duration) ? esc_attr($match->match_duration) : ''; ?>">
                            <p class="description">
                                Auto-filled from the selected competition. Override if needed.
                                Used for time-overlap conflict detection.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <?php foreach ($statuses as $k => $v): ?>
                                    <option value="<?php echo esc_attr($k); ?>"
                                        <?php selected($match ? $match->status : 'scheduled', $k); ?>>
                                        <?php echo esc_html($v); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="home_team_id">Home Team</label></th>
                        <td>
                            <select name="home_team_id" id="home_team_id" class="smm-team-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($teams_for_league as $t):
                                    $is_mine = in_array(intval($t->id), $my_team_ids, true);
                                    ?>
                                    <option value="<?php echo $t->id; ?>"
                                            data-league="<?php echo intval($t->league_id); ?>"
                                            data-my-team="<?php echo $is_mine ? '1' : '0'; ?>"
                                        <?php selected($match ? $match->home_team_id : 0, $t->id); ?>>
                                        <?php if ($is_mine) echo '★ '; ?><?php echo esc_html($t->team_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">★ = a team you have active players on.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="away_team_id">Away Team</label></th>
                        <td>
                            <select name="away_team_id" id="away_team_id" class="smm-team-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($teams_for_league as $t):
                                    $is_mine = in_array(intval($t->id), $my_team_ids, true);
                                    ?>
                                    <option value="<?php echo $t->id; ?>"
                                            data-league="<?php echo intval($t->league_id); ?>"
                                            data-my-team="<?php echo $is_mine ? '1' : '0'; ?>"
                                        <?php selected($match ? $match->away_team_id : 0, $t->id); ?>>
                                        <?php if ($is_mine) echo '★ '; ?><?php echo esc_html($t->team_name); ?>
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
                        </td>
                    </tr>

                    <tr>
                        <th><label for="notes">Notes</label></th>
                        <td><textarea name="notes" id="notes" rows="3" class="large-text"
                                      placeholder="Referee, kit color, parking info…"><?php
                            echo $match ? esc_textarea($match->notes) : '';
                        ?></textarea></td>
                    </tr>

                    <tr>
                        <th>Attending Players</th>
                        <td>
                            <p class="description smm-auto-note">
                                ✓ Players from the selected teams are auto-selected.
                                Uncheck to exclude, or add others manually.
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
                                            <?php foreach ($team_players as $p):
                                                $avail_label = SMM_Helpers::availabilities()[$p->availability] ?? $p->availability;
                                                ?>
                                                <label class="smm-player-line"
                                                       data-team="<?php echo intval($p->team_id); ?>"
                                                       style="display:block;margin:4px 0 4px 12px;">
                                                    <input type="checkbox" name="attending_players[]"
                                                           value="<?php echo $p->id; ?>"
                                                           data-team="<?php echo intval($p->team_id); ?>"
                                                           <?php checked(in_array($p->id, $selected)); ?>>
                                                    <?php echo esc_html($p->player_name); ?>
                                                    <small class="smm-avail smm-avail-<?php echo esc_attr($p->availability); ?>">
                                                        (<?php echo esc_html($avail_label); ?>)
                                                    </small>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach;
                                endif; ?>
                            </fieldset>
                        </td>
                    </tr>

                    <?php if (!$match): ?>
                    <tr>
                        <th><label for="repeat_weeks">Repeat weekly</label></th>
                        <td>
                            <input type="number" name="repeat_weeks" id="repeat_weeks"
                                   min="1" max="52" value="1">
                            <p class="description">
                                Enter 1 for a single match, or N to create N weekly copies.
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button($match ? 'Update Match' : 'Add Match', 'primary', 'smm_save_match'); ?>
            </form>
        </div>
        <?php
    }

    /* ============================================================
       PAGE: LEAGUES
       ============================================================ */

    public function leagues_page() {
        $leagues = SMM_Leagues::get_all();
        $edit = isset($_GET['edit']) ? SMM_Leagues::get(intval($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1>Leagues</h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    $m = array(
                        'league_added' => 'League added.',
                        'league_updated' => 'League updated.',
                        'league_deleted' => 'League deleted.',
                    );
                    echo esc_html($m[$_GET['message']] ?? 'Done.');
                ?></p></div>
            <?php endif; ?>

            <p class="description" style="max-width:700px;">
                A <strong>League</strong> is a season-long registration container — e.g. "Fall 2025 U12 South".
                Teams are assigned to a league, and competitions can optionally belong to a league.
                Cross-league competitions (tournaments with teams from many leagues) leave the league blank.
            </p>

            <div class="smm-players-layout">
                <div class="smm-player-form-wrap">
                    <h2><?php echo $edit ? 'Edit League' : 'Add League'; ?></h2>
                    <form method="post">
                        <?php wp_nonce_field($edit ? 'smm_update_league' : 'smm_add_league', 'smm_nonce'); ?>
                        <?php if ($edit): ?>
                            <input type="hidden" name="league_id" value="<?php echo $edit->id; ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="league_name">Name</label></th>
                                <td><input type="text" name="league_name" id="league_name" required
                                           placeholder="e.g. Fall 2025 U12 South"
                                           value="<?php echo $edit ? esc_attr($edit->league_name) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="season">Season</label></th>
                                <td><input type="text" name="season" id="season"
                                           placeholder="e.g. 2025 or Fall 2025"
                                           value="<?php echo $edit ? esc_attr($edit->season) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="age_group">Age group</label></th>
                                <td><input type="text" name="age_group" id="age_group"
                                           placeholder="e.g. U12"
                                           value="<?php echo $edit ? esc_attr($edit->age_group) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="color">Color</label></th>
                                <td>
                                    <input type="color" name="color" id="color"
                                           value="<?php echo $edit ? esc_attr($edit->color) : '#0d6efd'; ?>">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="league_logo_id">Logo</label></th>
                                <td>
                                    <?php
                                    $logo_id = $edit ? intval($edit->logo_id) : 0;
                                    $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : '';
                                    ?>
                                    <div class="smm-logo-picker">
                                        <div class="smm-logo-preview">
                                            <?php if ($logo_url): ?>
                                                <img src="<?php echo esc_url($logo_url); ?>" alt="">
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" name="league_logo_id" id="league_logo_id"
                                               value="<?php echo esc_attr($logo_id); ?>">
                                        <button type="button" class="button smm-upload-logo">Select Logo</button>
                                        <button type="button" class="button smm-remove-logo"
                                                style="<?php echo $logo_id ? '' : 'display:none;'; ?>">Remove</button>
                                        <p class="description" style="margin-top:8px;">
                                            Optional. If no logo is uploaded, a colored badge with the league name is shown instead.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="notes">Notes</label></th>
                                <td><textarea name="notes" id="notes" rows="3" class="large-text"><?php
                                    echo $edit ? esc_textarea($edit->notes) : '';
                                ?></textarea></td>
                            </tr>
                        </table>

                        <?php submit_button($edit ? 'Update League' : 'Add League', 'primary',
                            $edit ? 'smm_update_league' : 'smm_add_league'); ?>
                        <?php if ($edit): ?>
                            <a href="<?php echo admin_url('admin.php?page=smm-leagues'); ?>">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="smm-players-list-wrap">
                    <h2>All Leagues</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr>
                            <th>Badge</th><th>Name</th><th>Season</th><th>Age</th>
                            <th>Teams</th><th>Comps</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($leagues)): ?>
                            <tr><td colspan="7">No leagues yet.</td></tr>
                        <?php else: foreach ($leagues as $l): ?>
                            <tr>
                                <td><?php echo SMM_Leagues::badge_html($l->id); ?></td>
                                <td><?php echo esc_html($l->league_name); ?></td>
                                <td><?php echo esc_html($l->season); ?></td>
                                <td><?php echo esc_html($l->age_group); ?></td>
                                <td><?php echo SMM_Leagues::count_teams($l->id); ?></td>
                                <td><?php echo SMM_Leagues::count_competitions($l->id); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=smm-leagues&edit=' . $l->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-leagues&action=delete_league&id=' . $l->id), 'smm_del_league_' . $l->id); ?>"
                                       onclick="return confirm('Delete this league? Teams and competitions will be detached.');">Delete</a>
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

    /* ============================================================
       PAGE: COMPETITIONS
       ============================================================ */

    public function competitions_page() {
        $competitions = SMM_Competitions::get_all();
        $leagues = SMM_Leagues::get_all();
        $edit = isset($_GET['edit']) ? SMM_Competitions::get(intval($_GET['edit'])) : null;
        ?>
        <div class="wrap">
            <h1>Competitions</h1>

            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    $m = array(
                        'competition_added' => 'Competition added.',
                        'competition_updated' => 'Competition updated.',
                        'competition_deleted' => 'Competition deleted.',
                    );
                    echo esc_html($m[$_GET['message']] ?? 'Done.');
                ?></p></div>
            <?php endif; ?>

            <div class="smm-players-layout">
                <div class="smm-player-form-wrap">
                    <h2><?php echo $edit ? 'Edit Competition' : 'Add Competition'; ?></h2>
                    <form method="post" id="smm-competition-form">
                        <?php wp_nonce_field($edit ? 'smm_update_competition' : 'smm_add_competition', 'smm_nonce'); ?>
                        <?php if ($edit): ?>
                            <input type="hidden" name="competition_id" value="<?php echo $edit->id; ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="competition_name">Name</label></th>
                                <td><input type="text" name="competition_name" id="competition_name" required
                                           placeholder="e.g. Fall League Regular Season"
                                           value="<?php echo $edit ? esc_attr($edit->competition_name) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="short_label">Short label</label></th>
                                <td><input type="text" name="short_label" id="short_label"
                                           placeholder="e.g. FL"
                                           value="<?php echo $edit ? esc_attr($edit->short_label) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="league_id">League</label></th>
                                <td>
                                    <select name="league_id" id="league_id">
                                        <option value="0">— Cross-league (any team) —</option>
                                        <?php foreach ($leagues as $l): ?>
                                            <option value="<?php echo $l->id; ?>"
                                                <?php selected($edit ? $edit->league_id : 0, $l->id); ?>>
                                                <?php echo esc_html($l->league_name); ?>
                                                <?php if ($l->season) echo ' (' . esc_html($l->season) . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Leave empty for tournaments and cups with teams from multiple leagues.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="season">Season</label></th>
                                <td><input type="text" name="season" id="season"
                                           value="<?php echo $edit ? esc_attr($edit->season) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="age_group">Age group</label></th>
                                <td><input type="text" name="age_group" id="age_group"
                                           value="<?php echo $edit ? esc_attr($edit->age_group) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="color">Color</label></th>
                                <td><input type="color" name="color" id="color"
                                           value="<?php echo $edit ? esc_attr($edit->color) : '#0d6efd'; ?>"></td>
                            </tr>

                                                        <!-- Period configuration -->
                            <tr>
                                <th colspan="2" style="padding-bottom:0;">
                                    <hr>
                                    <h3 style="margin:0;">Match Format</h3>
                                </th>
                            </tr>
                            <tr>
                                <th><label for="periods">Periods</label></th>
                                <td>
                                    <select name="periods" id="periods">
                                        <?php
                                        $current_periods = $edit ? intval($edit->periods) : 2;
                                        foreach (array(2 => 'Halves (2)', 4 => 'Quarters (4)') as $k => $v):
                                            ?>
                                            <option value="<?php echo $k; ?>"
                                                <?php selected($current_periods, $k); ?>>
                                                <?php echo esc_html($v); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="period_minutes">Length of period (min)</label></th>
                                <td>
                                    <input type="number" name="period_minutes" id="period_minutes"
                                           min="1" step="1" class="small-text"
                                           value="<?php echo $edit ? intval($edit->period_minutes) : 45; ?>">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="halftime_minutes">Halftime (min)</label></th>
                                <td>
                                    <input type="number" name="halftime_minutes" id="halftime_minutes"
                                           min="0" step="1" class="small-text"
                                           value="<?php echo $edit ? intval($edit->halftime_minutes) : 10; ?>">
                                    <p class="description">The long break at the midpoint.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="break_minutes">Extra break per period (min)</label></th>
                                <td>
                                    <input type="number" name="break_minutes" id="break_minutes"
                                           min="0" step="1" class="small-text"
                                           value="<?php echo $edit ? intval($edit->break_minutes) : 0; ?>">
                                    <p class="description">
                                        Optional. Water breaks in halves, or short breaks
                                        between quarters. Set to 0 if not used.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th>Computed total</th>
                                <td>
                                    <div id="smm-computed-duration" class="smm-computed-duration">
                                        <?php
                                        if ($edit) {
                                            $computed = SMM_Competitions::compute_duration_from_row($edit);
                                            echo esc_html($computed) . ' minutes';
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </div>
                                    <p class="description">
                                        Auto-applied to matches when this competition is selected.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="notes">Notes</label></th>
                                <td><textarea name="notes" id="notes" rows="2" class="large-text"><?php
                                    echo $edit ? esc_textarea($edit->notes) : '';
                                ?></textarea></td>
                            </tr>
                        </table>

                        <?php submit_button($edit ? 'Update Competition' : 'Add Competition', 'primary',
                            $edit ? 'smm_update_competition' : 'smm_add_competition'); ?>
                        <?php if ($edit): ?>
                            <a href="<?php echo admin_url('admin.php?page=smm-competitions'); ?>">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="smm-players-list-wrap">
                    <h2>All Competitions</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr>
                            <th>Badge</th><th>Name</th><th>League</th><th>Format</th>
                            <th>Duration</th><th>Matches</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($competitions)): ?>
                            <tr><td colspan="7">No competitions yet.</td></tr>
                        <?php else: foreach ($competitions as $c):
                            $computed = SMM_Competitions::compute_duration_from_row($c);
                            $format = intval($c->periods) === 2
                                ? '2 × ' . intval($c->period_minutes)
                                : intval($c->periods) . ' × ' . intval($c->period_minutes);
                            ?>
                            <tr>
                                <td><?php echo SMM_Competitions::badge_html($c->id); ?></td>
                                <td><?php echo esc_html($c->competition_name); ?></td>
                                <td>
                                    <?php if ($c->league_id): ?>
                                        <?php echo SMM_Leagues::badge_html($c->league_id); ?>
                                    <?php else: ?>
                                        <em>cross-league</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($format); ?></td>
                                <td><?php echo intval($computed); ?> min</td>
                                <td><?php echo SMM_Competitions::count_matches($c->id); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=smm-competitions&edit=' . $c->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=smm-competitions&action=delete_competition&id=' . $c->id), 'smm_del_competition_' . $c->id); ?>"
                                       onclick="return confirm('Delete this competition?');">Delete</a>
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

    /* ============================================================
       PAGE: TEAMS
       ============================================================ */

    public function teams_page() {
        $teams = SMM_Teams::get_all();
        $leagues = SMM_Leagues::get_all();
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
                                <th><label for="team_league_id">League</label></th>
                                <td>
                                    <select name="team_league_id" id="team_league_id">
                                        <option value="0">— No league —</option>
                                        <?php foreach ($leagues as $l): ?>
                                            <option value="<?php echo $l->id; ?>"
                                                <?php selected($edit_team ? $edit_team->league_id : 0, $l->id); ?>>
                                                <?php echo esc_html($l->league_name); ?>
                                                <?php if ($l->season) echo ' (' . esc_html($l->season) . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        The league this team is registered in for the current season.
                                        <a href="<?php echo admin_url('admin.php?page=smm-leagues'); ?>">Manage leagues →</a>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="default_duration">Default duration (min)</label></th>
                                <td><input type="number" name="default_duration" id="default_duration"
                                           min="0" step="5"
                                           value="<?php echo ($edit_team && $edit_team->default_duration) ? esc_attr($edit_team->default_duration) : ''; ?>">
                                    <p class="description">
                                        Fallback when a match has no competition. Competition format wins if set.
                                    </p>
                                </td>
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
                            <th style="width:60px;">Logo</th><th>Team Name</th><th>League</th>
                            <th>Default Duration</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($teams)): ?>
                            <tr><td colspan="5">No teams yet.</td></tr>
                        <?php else: foreach ($teams as $t): ?>
                            <tr>
                                <td><?php echo SMM_Teams::get_logo_html($t, array(40,40)); ?></td>
                                <td><?php echo esc_html($t->team_name); ?></td>
                                <td>
                                    <?php if ($t->league_id): ?>
                                        <?php echo SMM_Leagues::badge_html($t->league_id); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $t->default_duration ? intval($t->default_duration) . ' min' : '—'; ?></td>
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

    /* ============================================================
       PAGE: PLAYERS (unchanged from v6.3)
       ============================================================ */

    public function players_page() {
        $players = SMM_Players::get_all();
        $teams = SMM_Teams::get_all();
        $edit_player = isset($_GET['edit']) ? SMM_Players::get(intval($_GET['edit'])) : null;
        $availabilities = SMM_Helpers::availabilities();
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
                            <tr>
                                <th><label for="availability">Availability</label></th>
                                <td>
                                    <select name="availability" id="availability">
                                        <?php foreach ($availabilities as $k => $v): ?>
                                            <option value="<?php echo esc_attr($k); ?>"
                                                <?php selected($edit_player ? $edit_player->availability : 'available', $k); ?>>
                                                <?php echo esc_html($v); ?>
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
                            <th>Name</th><th>Team</th><th>Availability</th><th>Email</th>
                            <th>Status</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($players)): ?>
                            <tr><td colspan="6">No players yet.</td></tr>
                        <?php else: foreach ($players as $p):
                            $team = $p->team_id ? SMM_Teams::get($p->team_id) : null;
                            $avail_label = $availabilities[$p->availability] ?? $p->availability;
                            ?>
                            <tr>
                                <td><?php echo esc_html($p->player_name); ?></td>
                                <td class="smm-team-cell">
                                    <?php if ($team): ?>
                                        <?php echo SMM_Teams::get_logo_html($team, array(24,24)); ?>
                                        <?php echo esc_html($team->team_name); ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <span class="smm-avail-badge smm-avail-<?php echo esc_attr($p->availability); ?>">
                                        <?php echo esc_html($avail_label); ?>
                                    </span>
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

    /* ============================================================
       PAGE: LOCATIONS (unchanged from v6.3)
       ============================================================ */

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
                                           value="<?php echo ($edit_location && $edit_location->latitude !== null) ? esc_attr($edit_location->latitude) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="longitude">Longitude</label></th>
                                <td><input type="number" step="any" name="longitude" id="longitude"
                                           value="<?php echo ($edit_location && $edit_location->longitude !== null) ? esc_attr($edit_location->longitude) : ''; ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="travel_buffer_minutes">Travel buffer (min)</label></th>
                                <td>
                                    <input type="number" name="travel_buffer_minutes" id="travel_buffer_minutes"
                                           min="0" step="5" value="<?php echo $edit_location ? intval($edit_location->travel_buffer_minutes) : 30; ?>">
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

    /* ============================================================
       PAGE: IMPORT / EXPORT
       ============================================================ */

    public function import_export_page() {
        $result = get_transient('smm_import_result_' . get_current_user_id());
        if ($result) delete_transient('smm_import_result_' . get_current_user_id());
        ?>
        <div class="wrap">
            <h1>Import / Export</h1>

            <?php if ($result): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <strong>Import complete.</strong>
                        Added: <?php echo intval($result['added']); ?>,
                        Updated: <?php echo intval($result['updated'] ?? 0); ?>,
                        Skipped: <?php echo intval($result['skipped']); ?>.
                    </p>
                    <?php if (!empty($result['errors'])): ?>
                        <details>
                            <summary><?php echo count($result['errors']); ?> issue(s)</summary>
                            <ul>
                                <?php foreach ($result['errors'] as $e): ?>
                                    <li><?php echo esc_html($e); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2>Recommended Import Order</h2>
            <p>
                Import these first so matches can link to them:
                <strong>Leagues → Locations → Competitions → Teams → Players → Matches</strong>.
                Each file matches by name, so running an import twice is safe.
            </p>

            <h2>Export</h2>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Entity</th><th>What's included</th><th>Download</th></tr></thead>
                <tbody>
                <?php
                $exports = array(
                    'leagues'      => array('Leagues', 'name, season, age_group, color, notes'),
                    'locations'    => array('Locations', 'name, address, latitude, longitude, travel_buffer_minutes'),
                    'competitions' => array('Competitions', 'name, league, short_label, season, age_group, color, periods, period_minutes, break_minutes, halftime_minutes, water_break_minutes, notes'),
                    'teams'        => array('Teams', 'name, league, default_duration'),
                    'players'      => array('Players', 'name, email, team, availability, is_active'),
                    'matches'      => array('Matches', 'date, time, duration, home_team, away_team, location, status, competition, round, notes'),
                );
                foreach ($exports as $key => $info):
                    $url = wp_nonce_url(
                        admin_url('admin.php?page=smm-import-export&smm_export=' . $key),
                        'smm_export_' . $key
                    );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($info[0]); ?></strong></td>
                        <td><code><?php echo esc_html($info[1]); ?></code></td>
                        <td><a class="button" href="<?php echo esc_url($url); ?>">Download CSV</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Import</h2>
            <p>CSV files must have a header row. Names are matched case-sensitively.</p>

            <table class="widefat striped" style="max-width:1000px;">
                <thead><tr><th>Entity</th><th>Required columns</th><th>Upload</th></tr></thead>
                <tbody>
                <?php
                $imports = array(
                    'leagues'      => array('Leagues', 'name'),
                    'locations'    => array('Locations', 'name'),
                    'competitions' => array('Competitions', 'name'),
                    'teams'        => array('Teams', 'name'),
                    'players'      => array('Players', 'name'),
                    'matches'      => array('Matches', 'date, home_team, away_team, location'),
                );
                foreach ($imports as $key => $info): ?>
                    <tr>
                        <td><strong><?php echo esc_html($info[0]); ?></strong></td>
                        <td><code><?php echo esc_html($info[1]); ?></code></td>
                        <td>
                            <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;">
                                <?php wp_nonce_field('smm_import_' . $key, 'smm_nonce'); ?>
                                <input type="hidden" name="smm_import_entity" value="<?php echo esc_attr($key); ?>">
                                <input type="file" name="csv_file" accept=".csv,text/csv" required>
                                <label style="white-space:nowrap;">
                                    <input type="checkbox" name="update_existing" value="1" checked>
                                    Update existing
                                </label>
                                <?php submit_button('Import', 'secondary', 'smm_import_' . $key, false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ============================================================
       PAGE: SETTINGS
       ============================================================ */

    public function settings_page() {
        if (isset($_GET['message']) && $_GET['message'] === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        $speed = SMM_Helpers::travel_speed_kmh();
        ?>
        <div class="wrap">
            <h1>Settings</h1>
            <form method="post">
                <?php wp_nonce_field('smm_settings', 'smm_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="smm_travel_speed_kmh">Avg travel speed (km/h)</label></th>
                        <td>
                            <input type="number" name="smm_travel_speed_kmh" id="smm_travel_speed_kmh"
                                   min="5" max="120" value="<?php echo intval($speed); ?>">
                            <p class="description">
                                Used to estimate driving time between locations for conflict detection.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save', 'primary', 'smm_save_settings'); ?>
            </form>
        </div>
        <?php
    }
}