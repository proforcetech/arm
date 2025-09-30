<?php
namespace ARM\Appointments;

class Ajax {
    public static function boot() {
        add_action('wp_ajax_arm_get_slots', [__CLASS__, 'get_slots']);
        add_action('wp_ajax_nopriv_arm_get_slots', [__CLASS__, 'get_slots']);
    }

    public static function get_slots() {
        check_ajax_referer('arm_re_nonce','nonce');
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_availability';
        $apptTbl = $wpdb->prefix.'arm_appointments';

        $date = sanitize_text_field($_POST['date'] ?? '');
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
            wp_send_json_error(['message'=>'Invalid date']);
        }
        $dow = (int) date('w', strtotime($date));

        // check if date is holiday
        $holiday = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE type='holiday' AND date=%s",$date));
        if ($holiday) wp_send_json_success(['slots'=>[],'holiday'=>true,'label'=>$holiday->label]);

        // get hours for this day
        $hours = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE type='hours' AND day_of_week=%d",$dow));
        if (!$hours) wp_send_json_success(['slots'=>[]]);

        $start = strtotime("$date {$hours->start_time}");
        $end   = strtotime("$date {$hours->end_time}");
        $slotLength = 60*60; // 1h slots

        $slots=[];
        for ($t=$start; $t+$slotLength<=$end; $t+=$slotLength) {
            $slotTime = date('H:i',$t);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $apptTbl WHERE DATE(start_datetime)=%s AND TIME(start_datetime)=%s AND status NOT IN ('cancelled')",$date,$slotTime));
            if (!$exists) $slots[]=$slotTime;
        }

        wp_send_json_success(['slots'=>$slots]);
    }
}
