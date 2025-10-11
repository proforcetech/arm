<?php
namespace ARM\Public;

if (!defined('ABSPATH')) exit;

/**
 * Public AJAX handlers:
 *  - arm_get_vehicle_options  (cascading Year/Make/Model/Engine/Transmission/Drive/Trim)
 *  - arm_submit_estimate      (stores public request, emails admin)
 */
final class Ajax_Submit {

    /**
     * Register AJAX routes.
     */
    public static function boot(): void {
        
        \add_action('wp_ajax_arm_get_vehicle_options',        [__CLASS__, 'ajax_get_vehicle_options']);
        \add_action('wp_ajax_nopriv_arm_get_vehicle_options', [__CLASS__, 'ajax_get_vehicle_options']);

        
        \add_action('wp_ajax_arm_submit_estimate',        [__CLASS__, 'ajax_submit_estimate']);
        \add_action('wp_ajax_nopriv_arm_submit_estimate', [__CLASS__, 'ajax_submit_estimate']);
    }

    /**
     * AJAX: return next-level vehicle options based on current filters.
     * POST: nonce, next, (year, make, model, engine, transmission, drive)
     */
    public static function ajax_get_vehicle_options(): void {
        \check_ajax_referer('arm_re_nonce', 'nonce');

        global $wpdb;
        $tbl   = $wpdb->prefix . 'arm_vehicle_data';
        $hier  = ['year','make','model','engine','transmission','drive','trim'];
        $next  = \sanitize_text_field($_POST['next'] ?? '');

        if (!in_array($next, $hier, true)) {
            \wp_send_json_error(['message' => 'Invalid level']);
        }

        
        $filters = [];
        foreach ($hier as $level) {
            if ($level === $next) break;
            if (isset($_POST[$level]) && $_POST[$level] !== '') {
                $filters[$level] = \sanitize_text_field(\wp_unslash($_POST[$level]));
            }
        }

        $col     = \esc_sql($next);
        $where   = [];
        $params  = [];
        foreach ($filters as $k => $v) {
            $where[]  = "`$k` = %s";
            $params[] = $v;
        }
        $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql      = "SELECT DISTINCT `$col` AS v FROM `$tbl` $wheresql ORDER BY v ASC";

        $results = $params ? $wpdb->get_col($wpdb->prepare($sql, $params)) : $wpdb->get_col($sql);
        \wp_send_json_success(['options' => array_values(array_filter((array)$results))]);
    }

    /**
     * AJAX: handle public estimate request submission.
     * POST expects the fields from the public form + nonces.
     */
    public static function ajax_submit_estimate(): void {
        \check_ajax_referer('arm_re_nonce', 'nonce');

        if (empty($_POST['arm_re_submit_nonce']) || !\wp_verify_nonce($_POST['arm_re_submit_nonce'], 'arm_re_submit')) {
            \wp_send_json_error(['message' => 'Bad nonce']);
        }

        
        $required = [
            'first_name','last_name','email',
            'customer_address','customer_city','customer_zip',
            'service_address','service_city','service_zip'
        ];
        foreach ($required as $r) {
            if (empty($_POST[$r])) {
                \wp_send_json_error(['message' => 'Missing required fields']);
            }
        }
        if (empty($_POST['terms_accepted'])) {
            \wp_send_json_error(['message' => 'Terms not accepted']);
        }

        
        $other = !empty($_POST['vehicle_other']);
        if (!$other) {
            foreach ([
                'vehicle_year','vehicle_make','vehicle_model','vehicle_engine',
                'vehicle_transmission','vehicle_drive','vehicle_trim'
            ] as $r) {
                if (empty($_POST[$r])) {
                    \wp_send_json_error(['message' => 'Missing vehicle selection']);
                }
            }
        }

        
        $del_email = !empty($_POST['delivery_email']) ? 1 : 0;
        $del_sms   = !empty($_POST['delivery_sms'])   ? 1 : 0;
        $del_both  = !empty($_POST['delivery_both'])  ? 1 : 0;
        if (($del_email + $del_sms + $del_both) === 0) {
            \wp_send_json_error(['message' => 'Select a delivery preference']);
        }
        if ($del_email && $del_sms) {
            $del_both  = 1;
            $del_email = 0;
            $del_sms   = 0;
        }

        global $wpdb;
        $tbl  = $wpdb->prefix . 'arm_estimate_requests';

        $data = [
            
            'vehicle_year'   => $other ? null : (int)($_POST['vehicle_year'] ?? 0),
            'vehicle_make'   => $other ? null : \sanitize_text_field($_POST['vehicle_make'] ?? ''),
            'vehicle_model'  => $other ? null : \sanitize_text_field($_POST['vehicle_model'] ?? ''),
            'vehicle_engine' => $other ? null : \sanitize_text_field($_POST['vehicle_engine'] ?? ''),
            'vehicle_transmission' => $other ? null : \sanitize_text_field($_POST['vehicle_transmission'] ?? ''),
            'vehicle_drive'  => $other ? null : \sanitize_text_field($_POST['vehicle_drive'] ?? ''),
            'vehicle_trim'   => $other ? null : \sanitize_text_field($_POST['vehicle_trim'] ?? ''),
            'vehicle_other'  => $other ? \sanitize_textarea_field($_POST['vehicle_other']) : null,

            
            'service_type_id'   => !empty($_POST['service_type_id']) ? (int)$_POST['service_type_id'] : null,
            'issue_description' => isset($_POST['issue_description']) ? \wp_kses_post($_POST['issue_description']) : null,

            
            'first_name' => \sanitize_text_field($_POST['first_name']),
            'last_name'  => \sanitize_text_field($_POST['last_name']),
            'email'      => \sanitize_email($_POST['email']),
            'phone'      => \sanitize_text_field($_POST['phone'] ?? ''),

            
            'customer_address' => \sanitize_text_field($_POST['customer_address']),
            'customer_city'    => \sanitize_text_field($_POST['customer_city']),
            'customer_zip'     => \sanitize_text_field($_POST['customer_zip']),
            'service_same_as_customer' => !empty($_POST['service_same_as_customer']) ? 1 : 0,
            'service_address'  => \sanitize_text_field($_POST['service_address']),
            'service_city'     => \sanitize_text_field($_POST['service_city']),
            'service_zip'      => \sanitize_text_field($_POST['service_zip']),

            
            'delivery_email'  => $del_email,
            'delivery_sms'    => $del_sms,
            'delivery_both'   => $del_both,
            'terms_accepted'  => 1,
        ];

        $ok = $wpdb->insert($tbl, $data);
        if (!$ok) {
            \wp_send_json_error(['message' => 'DB error']);
        }

        
        $admin_email = \sanitize_email(\get_option('arm_re_notify_email', \get_option('admin_email')));
        if ($admin_email) {
            $veh = $other
                ? ($data['vehicle_other'] ?? '')
                : trim("{$data['vehicle_year']} {$data['vehicle_make']} {$data['vehicle_model']} {$data['vehicle_engine']} {$data['vehicle_transmission']} {$data['vehicle_drive']} {$data['vehicle_trim']}");
            $delivery = $del_both ? 'Both' : trim(($del_email ? 'Email ' : '') . ($del_sms ? 'SMS' : ''));

            $subj = sprintf('[Estimate Request] %s %s', $data['first_name'], $data['last_name']);
            $body = "New estimate request:\n\n"
                  . "Name: {$data['first_name']} {$data['last_name']}\nEmail: {$data['email']}\nPhone: {$data['phone']}\n\n"
                  . "Vehicle: {$veh}\nService Type ID: {$data['service_type_id']}\n\n"
                  . "Issue:\n" . \wp_strip_all_tags($data['issue_description']) . "\n\n"
                  . "Customer Address: {$data['customer_address']}, {$data['customer_city']} {$data['customer_zip']}\n"
                  . "Service Address: {$data['service_address']}, {$data['service_city']} {$data['service_zip']}\n\n"
                  . "Delivery: {$delivery}\n"
                  . "Submitted: " . \current_time('mysql');

            \wp_mail($admin_email, $subj, $body);
        }

        \wp_send_json_success(['message' => 'OK']);
    }
}
