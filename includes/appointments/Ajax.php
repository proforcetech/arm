<?php
namespace ARM\Appointments;

if (!defined('ABSPATH')) exit;

final class Ajax
{
    public static function boot(): void
    {
        add_action('wp_ajax_arm_get_slots', [__CLASS__, 'get_slots']);
        add_action('wp_ajax_nopriv_arm_get_slots', [__CLASS__, 'get_slots']);
    }

    public static function get_slots(): void
    {
        check_ajax_referer('arm_re_nonce', 'nonce');
        global $wpdb;

        $table_avail = $wpdb->prefix . 'arm_availability';
        $table_appt  = $wpdb->prefix . 'arm_appointments';

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(['message' => __('Invalid date supplied.', 'arm-repair-estimates')]);
        }

        $dow = (int) date('w', strtotime($date));

        $holiday = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE type='holiday' AND date=%s", $date));
        if ($holiday) {
            wp_send_json_success([
                'slots'   => [],
                'holiday' => true,
                'label'   => $holiday->label,
            ]);
        }

        $hours = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE type='hours' AND day_of_week=%d", $dow));
        if (!$hours) {
            wp_send_json_success(['slots' => []]);
        }

        $start = strtotime("$date {$hours->start_time}");
        $end   = strtotime("$date {$hours->end_time}");
        $slot_length = HOUR_IN_SECONDS;

        $slots = [];
        for ($t = $start; $t + $slot_length <= $end; $t += $slot_length) {
            $slot_time = date('H:i', $t);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_appt WHERE DATE(start_datetime)=%s AND TIME(start_datetime)=%s AND status NOT IN ('cancelled')",
                $date,
                $slot_time
            ));
            if (!$exists) {
                $slots[] = $slot_time;
            }
        }

        wp_send_json_success(['slots' => $slots]);
    }
}
