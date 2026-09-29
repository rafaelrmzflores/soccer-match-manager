<?php
class SMM_Helpers {

    public static function tz() {
        return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    }

    public static function fmt_date($ymd) {
        if (empty($ymd)) return '';
        $ts = strtotime($ymd . ' 00:00:00');
        return $ts ? wp_date('M j, Y', $ts) : $ymd;
    }

    /**
     * Format a time. Returns 'TBD' for null/empty.
     */
    public static function fmt_time($his) {
        if (empty($his)) return 'TBD';
        $ts = strtotime('2000-01-01 ' . $his);
        return $ts ? wp_date('g:i A', $ts) : $his;
    }

    /**
     * True if the match has no time set. Accepts either a match row or a raw
     * time string.
     */
    public static function is_time_tbd($match_or_time) {
        if (is_object($match_or_time)) {
            return empty($match_or_time->match_time);
        }
        return empty($match_or_time);
    }

    public static function to_ts($ymd, $his) {
        if (empty($ymd) || empty($his)) return null;
        $dt = date_create_from_format('Y-m-d H:i:s', $ymd . ' ' . $his, self::tz());
        return $dt ? $dt->getTimestamp() : strtotime($ymd . ' ' . $his);
    }

    public static function now_ts() {
        return (new DateTime('now', self::tz()))->getTimestamp();
    }

    public static function today_ymd() {
        return wp_date('Y-m-d', self::now_ts(), self::tz());
    }

    public static function statuses() {
        return array(
            'scheduled' => 'Scheduled',
            'confirmed' => 'Confirmed',
            'completed' => 'Completed',
            'postponed' => 'Postponed',
            'canceled'  => 'Canceled',
        );
    }

    public static function status_badge($status) {
        $map = array(
            'scheduled' => '#6c757d',
            'confirmed' => '#0d6efd',
            'completed' => '#198754',
            'postponed' => '#fd7e14',
            'canceled'  => '#dc3545',
        );
        $color = $map[$status] ?? '#6c757d';
        $label = self::statuses()[$status] ?? ucfirst($status);
        return sprintf(
            '<span class="smm-status-badge" style="background:%s">%s</span>',
            esc_attr($color), esc_html($label)
        );
    }

    public static function is_conflict_relevant($status) {
        return in_array($status, array('scheduled', 'confirmed'), true);
    }

    public static function availabilities() {
        return array(
            'available'   => 'Available',
            'maybe'       => 'Maybe',
            'unavailable' => 'Unavailable',
            'injured'     => 'Injured',
        );
    }

    public static function availability_counts_for_conflict($availability) {
        return in_array($availability, array('available', 'maybe'), true);
    }

    public static function travel_speed_kmh() {
        $v = intval(get_option('smm_travel_speed_kmh', 50));
        return $v < 5 ? 50 : $v;
    }

    /**
     * Format a duration in minutes as "1 hr and 30 mins" style.
     *
     * Examples:
     *   30   → "30 mins"
     *   60   → "1 hr"
     *   75   → "1 hr and 15 mins"
     *   90   → "1 hr and 30 mins"
     *   120  → "2 hrs"
     *   145  → "2 hrs and 25 mins"
     *   0    → ""
     *   null → ""
     */
    public static function fmt_duration($minutes) {
        $minutes = intval($minutes);
        if ($minutes <= 0) return '';

        $hrs  = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hrs === 0) {
            return $mins . ' min' . ($mins === 1 ? '' : 's');
        }

        $hr_label = $hrs . ' hr' . ($hrs === 1 ? '' : 's');

        if ($mins === 0) {
            return $hr_label;
        }

        return $hr_label . ' ' . $mins . ' min' . ($mins === 1 ? '' : 's');
    }

        /**
     * Short form: "1h 30m", "2h", "45m".
     */
    public static function fmt_duration_short($minutes) {
        $minutes = intval($minutes);
        if ($minutes <= 0) return '';

        $hrs  = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hrs === 0) return $mins . 'm';
        if ($mins === 0) return $hrs . 'h';
        return $hrs . 'h ' . $mins . 'm';
    }
}