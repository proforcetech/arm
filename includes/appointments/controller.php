<?php
namespace ARM\Appointments;

class Controller {
    public static function boot() {
        add_action('init', [__CLASS__, 'register_post_type']);
    }

    public static function register_post_type() {
        // Optional: register a CPT "arm_appointment" for better WP integration
    }

    public static function create($customer_id, $estimate_id, $start, $end) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_appointments';
        $wpdb->insert($tbl, [
            'customer_id'   => $customer_id,
            'estimate_id'   => $estimate_id,
            'start_datetime'=> $start,
            'end_datetime'  => $end,
            'status'        => 'pending'
        ]);
        return $wpdb->insert_id;
    }

    public static function update_status($id, $status) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_appointments';
        $wpdb->update($tbl, ['status'=>$status], ['id'=>$id]);
    }

    public static function get_for_customer($customer_id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_appointments';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE customer_id=%d", $customer_id));
    }
}
