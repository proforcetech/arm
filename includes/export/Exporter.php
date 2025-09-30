<?php
namespace ARM\Export;
if (!defined('ABSPATH')) exit;

class Exporter {
    public static function boot() {
        if (!is_admin()) return;
        add_action('admin_post_arm_re_export_requests', [__CLASS__,'export_requests']);
    }
    public static function export_requests() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        global $wpdb; $tbl = $wpdb->prefix.'arm_estimate_requests';
        $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC", ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="estimate-requests.csv"');
        $out = fopen('php://output','w');
        if ($rows) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $r) fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
}
