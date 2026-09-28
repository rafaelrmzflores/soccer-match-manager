<?php
class SMM_CSV {

    public static function import_matches($file_path) {
        $result = array('added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array());

        if (!file_exists($file_path)) {
            $result['errors'][] = 'File not found.';
            return $result;
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $result['errors'][] = 'Could not open file.';
            return $result;
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            $result['errors'][] = 'Empty CSV.';
            return $result;
        }

        $header = array_map(function($h) {
            return strtolower(trim(str_replace(' ', '_', $h)));
        }, $header);

        // time is now optional; date is required
        $required = array('date', 'home_team', 'away_team', 'location');
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                $result['errors'][] = "Missing required column: $col";
                fclose($handle);
                return $result;
            }
        }

        $row_num = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            }
            $data = array_combine(
                array_slice($header, 0, count($row)),
                array_slice($row, 0, count($header))
            );

            $date = self::normalize_date($data['date'] ?? '');
            $time = self::normalize_time($data['time'] ?? ''); // may be null
            $home = trim($data['home_team'] ?? '');
            $away = trim($data['away_team'] ?? '');
            $loc  = trim($data['location'] ?? '');

            if (!$date || !$home || !$away || !$loc) {
                $result['skipped']++;
                $result['errors'][] = "Row $row_num: missing required value(s).";
                continue;
            }

            $home_id = self::find_or_create_team($home);
            $away_id = self::find_or_create_team($away);
            $loc_id  = self::find_or_create_location($loc);

            $duration = null;
            if (isset($data['duration']) && $data['duration'] !== '') {
                $duration = intval($data['duration']);
                if ($duration <= 0) $duration = null;
            }

            $status = !empty($data['status']) ? sanitize_key($data['status']) : 'scheduled';
            if (!array_key_exists($status, SMM_Helpers::statuses())) {
                $status = 'scheduled';
            }

            $comp_name = trim($data['competition'] ?? '');
            $comp_id = 0;
            if ($comp_name) {
                $comp_id = self::find_or_create_competition($comp_name);
            }

            $ins = array(
                'match_date'     => $date,
                'match_time'     => $time,
                'match_duration' => $duration,
                'home_team_id'   => $home_id,
                'away_team_id'   => $away_id,
                'home_team'      => $home,
                'away_team'      => $away,
                'location_id'    => $loc_id,
                'location'       => $loc,
                'status'         => $status,
                'competition_id' => $comp_id,
                'competition'    => $comp_name,
                'round'          => sanitize_text_field($data['round'] ?? ''),
                'notes'          => sanitize_textarea_field($data['notes'] ?? ''),
            );

            SMM_Database::insert_match($ins);
            $result['added']++;
        }

        fclose($handle);
        return $result;
    }

    public static function export_matches() {
        $matches = SMM_Database::get_matches();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array(
            'date','time','duration','home_team','away_team',
            'location','status','competition','round','notes'
        ));

        foreach ($matches as $m) {
            $home = $m->home_team_id ? SMM_Teams::get_name($m->home_team_id) : $m->home_team;
            $away = $m->away_team_id ? SMM_Teams::get_name($m->away_team_id) : $m->away_team;
            $loc  = $m->location_id ? SMM_Locations::get_name($m->location_id) : $m->location;
            $comp = $m->competition_id ? SMM_Competitions::get_name($m->competition_id) : $m->competition;

            fputcsv($out, array(
                $m->match_date,
                empty($m->match_time) ? '' : substr($m->match_time, 0, 5),
                $m->match_duration,
                $home,
                $away,
                $loc,
                $m->status,
                $comp,
                $m->round,
                $m->notes,
            ));
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    private static function find_or_create_team($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_teams';
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE team_name = %s", $name
        ));
        if ($id) return intval($id);

        SMM_Teams::add($name, 0);
        return intval($wpdb->insert_id);
    }

    private static function find_or_create_location($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_locations';
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE location_name = %s", $name
        ));
        if ($id) return intval($id);

        SMM_Locations::add($name, '', null, null, 30);
        return intval($wpdb->insert_id);
    }

    private static function find_or_create_competition($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'soccer_competitions';
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE competition_name = %s", $name
        ));
        if ($id) return intval($id);

        SMM_Competitions::add($name);
        return intval($wpdb->insert_id);
    }

    private static function normalize_date($str) {
        $str = trim($str);
        if (!$str) return '';
        $ts = strtotime($str);
        if (!$ts) return '';
        return wp_date('Y-m-d', $ts, SMM_Helpers::tz());
    }

    /**
     * Returns null when the input is empty (i.e. time is TBD).
     */
    private static function normalize_time($str) {
        $str = trim($str);
        if (!$str || strtoupper($str) === 'TBD') return null;
        $ts = strtotime('2000-01-01 ' . $str);
        if (!$ts) return null;
        return wp_date('H:i:s', $ts, SMM_Helpers::tz());
    }
}