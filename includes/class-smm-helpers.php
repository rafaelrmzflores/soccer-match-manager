<?php
class SMM_Helpers {

    /** Site timezone object (from WP settings). */
    public static function tz() {
        return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    }

    /**
     * Format a date+time stored in local site time.
     * We store the raw strings the user typed (which are already in the site's local tz)
     * and never convert on the way in/out. This helper is for rendering only.
     */
    public static function fmt_date($ymd) {
        $ts = strtotime($ymd . ' 00:00:00');
        return $ts ? wp_date('M j, Y', $ts) : $ymd;
    }

    public static function fmt_time($his) {
        $ts = strtotime('2000-01-01 ' . $his);
        return $ts ? wp_date('g:i A', $ts) : $his;
    }

    /**
     * Combine date + time strings into a unix timestamp in the SITE timezone.
     * Used for conflict math. Never uses UTC.
     */
    public static function to_ts($ymd, $his) {
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

    /** Statuses that participate in conflict detection. */
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

    /** Players that count as "attending" for conflict purposes. */
    public static function availability_counts_for_conflict($availability) {
        return in_array($availability, array('available', 'maybe'), true);
    }
}