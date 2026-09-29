<?php
class SMM_CSV_Entities {

    /* ============================================================
       EXPORT
       ============================================================ */

    public static function export_leagues() {
        $rows = SMM_Leagues::get_all();
        return self::to_csv(
            array('name','season','age_group','color','notes'),
            array_map(function($l) {
                return array(
                    $l->league_name,
                    $l->season,
                    $l->age_group,
                    $l->color,
                    $l->notes,
                );
            }, $rows)
        );
    }

    public static function export_locations() {
        $rows = SMM_Locations::get_all();
        return self::to_csv(
            array('name','address','latitude','longitude','travel_buffer_minutes'),
            array_map(function($l) {
                return array(
                    $l->location_name,
                    $l->location_address,
                    $l->latitude,
                    $l->longitude,
                    $l->travel_buffer_minutes,
                );
            }, $rows)
        );
    }

    public static function export_competitions() {
        $rows = SMM_Competitions::get_all();
        return self::to_csv(
            array('name','league','short_label','season','age_group','color',
                  'periods','period_minutes','break_minutes','halftime_minutes','water_break_minutes','notes'),
            array_map(function($c) {
                $league_name = $c->league_id ? SMM_Leagues::get_name($c->league_id) : '';
                return array(
                    $c->competition_name,
                    $league_name,
                    $c->short_label,
                    $c->season,
                    $c->age_group,
                    $c->color,
                    intval($c->periods),
                    intval($c->period_minutes),
                    intval($c->break_minutes),
                    intval($c->halftime_minutes),
                    intval($c->water_break_minutes),
                    $c->notes,
                );
            }, $rows)
        );
    }

    public static function export_teams() {
        $rows = SMM_Teams::get_all();
        return self::to_csv(
            array('name','league','default_duration','venues'),
            array_map(function($t) {
                $league_name = $t->league_id ? SMM_Leagues::get_name($t->league_id) : '';

                // Serialize venues as Name|Name|Name — primary marked with *
                $venues = SMM_Teams::get_venues($t->id);
                $parts = array();
                foreach ($venues as $v) {
                    $parts[] = ($v->is_primary ? '*' : '') . $v->location_name;
                }
                $venues_str = implode('|', $parts);

                return array(
                    $t->team_name,
                    $league_name,
                    $t->default_duration,
                    $venues_str,
                );
            }, $rows)
        );
    }

    public static function export_players() {
        $rows = SMM_Players::get_all();
        return self::to_csv(
            array('name','email','team','availability','is_active'),
            array_map(function($p) {
                $team_name = $p->team_id ? SMM_Teams::get_name($p->team_id) : '';
                return array(
                    $p->player_name,
                    $p->player_email,
                    $team_name,
                    $p->availability,
                    $p->is_active ? 1 : 0,
                );
            }, $rows)
        );
    }

    private static function to_csv($headers, $rows) {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);
        foreach ($rows as $r) fputcsv($out, $r);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /* ============================================================
       IMPORT
       ============================================================ */

    public static function import_leagues($file_path, $update_existing = true) {
        return self::import_generic($file_path, array(
            'required' => array('name'),
            'handler'  => function($row, $update_existing) {
                $name = trim($row['name'] ?? '');
                if (!$name) return 'skip';

                $existing = SMM_Leagues::get_by_name($name);
                if ($existing) {
                    if (!$update_existing) return 'skip';
                    SMM_Leagues::update(
                        $existing->id, $name,
                        $row['season'] ?? '',
                        $row['age_group'] ?? '',
                        $row['color'] ?? '#0d6efd',
                        $row['notes'] ?? ''
                    );
                    return 'updated';
                }

                SMM_Leagues::add(
                    $name,
                    $row['season'] ?? '',
                    $row['age_group'] ?? '',
                    $row['color'] ?? '#0d6efd',
                    $row['notes'] ?? ''
                );
                return 'added';
            },
        ));
    }

    public static function import_locations($file_path, $update_existing = true) {
        return self::import_generic($file_path, array(
            'required' => array('name'),
            'handler'  => function($row, $update_existing) {
                $name = trim($row['name'] ?? '');
                if (!$name) return 'skip';

                $existing = self::find_location($name);
                $lat = (isset($row['latitude']) && $row['latitude'] !== '') ? floatval($row['latitude']) : null;
                $lng = (isset($row['longitude']) && $row['longitude'] !== '') ? floatval($row['longitude']) : null;
                $buf = isset($row['travel_buffer_minutes']) && $row['travel_buffer_minutes'] !== ''
                    ? intval($row['travel_buffer_minutes']) : 30;

                if ($existing) {
                    if (!$update_existing) return 'skip';
                    SMM_Locations::update(
                        $existing->id, $name,
                        $row['address'] ?? '',
                        $lat, $lng, $buf
                    );
                    return 'updated';
                }

                SMM_Locations::add($name, $row['address'] ?? '', $lat, $lng, $buf);
                return 'added';
            },
        ));
    }

    public static function import_competitions($file_path, $update_existing = true) {
        return self::import_generic($file_path, array(
            'required' => array('name'),
            'handler'  => function($row, $update_existing) {
                $name = trim($row['name'] ?? '');
                if (!$name) return 'skip';

                // Look up league by name (find-or-create)
                $league_id = 0;
                $league_name = trim($row['league'] ?? '');
                if ($league_name) {
                    $l = SMM_Leagues::get_by_name($league_name);
                    if ($l) {
                        $league_id = intval($l->id);
                    } else {
                        SMM_Leagues::add($league_name);
                        global $wpdb;
                        $league_id = intval($wpdb->insert_id);
                    }
                }

                $periods    = isset($row['periods']) && $row['periods'] !== '' ? intval($row['periods']) : 2;
                $period_min = isset($row['period_minutes']) && $row['period_minutes'] !== '' ? intval($row['period_minutes']) : 45;
                $break_min  = isset($row['break_minutes']) && $row['break_minutes'] !== '' ? intval($row['break_minutes']) : 5;
                $half_min   = isset($row['halftime_minutes']) && $row['halftime_minutes'] !== '' ? intval($row['halftime_minutes']) : 15;
                $water_min  = isset($row['water_break_minutes']) && $row['water_break_minutes'] !== '' ? intval($row['water_break_minutes']) : 0;

                $existing = SMM_Competitions::get_by_name($name);
                if ($existing) {
                    if (!$update_existing) return 'skip';
                    SMM_Competitions::update(
                        $existing->id, $name,
                        $row['short_label'] ?? '',
                        $row['season'] ?? '',
                        $row['age_group'] ?? '',
                        $row['color'] ?? '#0d6efd',
                        $row['notes'] ?? '',
                        $league_id,
                        $periods, $period_min, $break_min, $half_min, $water_min
                    );
                    return 'updated';
                }

                SMM_Competitions::add(
                    $name,
                    $row['short_label'] ?? '',
                    $row['season'] ?? '',
                    $row['age_group'] ?? '',
                    $row['color'] ?? '#0d6efd',
                    $row['notes'] ?? '',
                    $league_id,
                    $periods, $period_min, $break_min, $half_min, $water_min
                );
                return 'added';
            },
        ));
    }

    public static function import_teams($file_path, $update_existing = true) {
        return self::import_generic($file_path, array(
            'required' => array('name'),
            'handler'  => function($row, $update_existing) {
                global $wpdb;
                $name = trim($row['name'] ?? '');
                if (!$name) return 'skip';

                // League: find-or-create
                $league_id = 0;
                $league_name = trim($row['league'] ?? '');
                if ($league_name) {
                    $l = SMM_Leagues::get_by_name($league_name);
                    if ($l) {
                        $league_id = intval($l->id);
                    } else {
                        SMM_Leagues::add($league_name);
                        $league_id = intval($wpdb->insert_id);
                    }
                }

                $table = $wpdb->prefix . 'soccer_teams';
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE team_name = %s", $name
                ));

                $duration = isset($row['default_duration']) && $row['default_duration'] !== ''
                    ? intval($row['default_duration']) : null;
                if ($duration !== null && $duration <= 0) $duration = null;

                $team_id = 0;
                if ($existing_id) {
                    if (!$update_existing) return 'skip';
                    $existing = SMM_Teams::get($existing_id);
                    SMM_Teams::update($existing_id, $name, $existing->team_logo_id, $duration, $league_id);
                    $team_id = intval($existing_id);
                } else {
                    SMM_Teams::add($name, 0, $duration, $league_id);
                    $team_id = intval($wpdb->insert_id);
                }

                // Venues: parse "Name|*PrimaryName|OtherName"
                if ($team_id && isset($row['venues']) && trim($row['venues']) !== '') {
                    $venue_names = array_filter(array_map('trim', explode('|', $row['venues'])));
                    $venue_rows = array();
                    foreach ($venue_names as $vn) {
                        $is_primary = false;
                        if (strpos($vn, '*') === 0) {
                            $is_primary = true;
                            $vn = trim(substr($vn, 1));
                        }
                        if (!$vn) continue;

                        $loc = self::find_location($vn);
                        $loc_id = 0;
                        if ($loc) {
                            $loc_id = intval($loc->id);
                        } else {
                            // Auto-create missing locations
                            SMM_Locations::add($vn, '', null, null, 30);
                            $loc_id = intval($wpdb->insert_id);
                        }
                        if ($loc_id) {
                            $venue_rows[] = array(
                                'location_id' => $loc_id,
                                'is_primary'  => $is_primary,
                            );
                        }
                    }
                    if (!empty($venue_rows)) {
                        SMM_Teams::set_venues($team_id, $venue_rows);
                    }
                } elseif ($team_id && $existing_id) {
                    // Row has no venues column (legacy CSV) — leave existing venues alone.
                    // Only clear venues if the column is explicitly present but empty.
                    if (array_key_exists('venues', $row) && trim($row['venues']) === '') {
                        SMM_Teams::set_venues($team_id, array());
                    }
                }

                return $existing_id ? 'updated' : 'added';
            },
        ));
    }

    public static function import_players($file_path, $update_existing = true) {
        return self::import_generic($file_path, array(
            'required' => array('name'),
            'handler'  => function($row, $update_existing) {
                global $wpdb;
                $name = trim($row['name'] ?? '');
                if (!$name) return 'skip';

                $team_name = trim($row['team'] ?? '');
                $team_id = 0;
                if ($team_name) {
                    $t = self::find_team($team_name);
                    if ($t) {
                        $team_id = intval($t->id);
                    } else {
                        SMM_Teams::add($team_name);
                        $team_id = intval($wpdb->insert_id);
                    }
                }

                $availability = sanitize_key($row['availability'] ?? 'available');
                if (!array_key_exists($availability, SMM_Helpers::availabilities())) {
                    $availability = 'available';
                }
                $is_active = !isset($row['is_active'])
                    || $row['is_active'] === ''
                    || (int) $row['is_active'] !== 0;

                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}soccer_players WHERE player_name = %s", $name
                ));

                if ($existing_id) {
                    if (!$update_existing) return 'skip';
                    SMM_Players::update(
                        $existing_id, $name,
                        $row['email'] ?? '',
                        $team_id,
                        $availability,
                        $is_active
                    );
                    return 'updated';
                }

                SMM_Players::add($name, $row['email'] ?? '', $team_id, $availability);
                if (!$is_active) {
                    $wpdb->update(
                        $wpdb->prefix . 'soccer_players',
                        array('is_active' => 0),
                        array('id' => $wpdb->insert_id)
                    );
                }
                return 'added';
            },
        ));
    }

    private static function import_generic($file_path, $opts) {
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

        foreach ($opts['required'] as $col) {
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

            try {
                $status = call_user_func($opts['handler'], $data, true);
            } catch (Exception $e) {
                $result['errors'][] = "Row $row_num: " . $e->getMessage();
                $result['skipped']++;
                continue;
            }

            if ($status === 'added')        $result['added']++;
            elseif ($status === 'updated')  $result['updated']++;
            else                            $result['skipped']++;
        }

        fclose($handle);
        return $result;
    }

    private static function find_location($name) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_locations WHERE location_name = %s",
            $name
        ));
    }

    private static function find_team($name) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}soccer_teams WHERE team_name = %s",
            $name
        ));
    }
}