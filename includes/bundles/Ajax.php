<?php
namespace ARM\Bundles;
if (!defined('ABSPATH')) exit;

class Ajax {
    public static function boot() {
        add_action('wp_ajax_arm_re_get_bundle_items', [__CLASS__, 'get_items']);
    }
    public static function get_items() {
        check_ajax_referer('arm_re_nonce','nonce');
        global $wpdb; $id = (int)($_POST['bundle_id'] ?? 0);
        $biT = $wpdb->prefix.'arm_service_bundle_items';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $biT WHERE bundle_id=%d ORDER BY sort_order ASC, id ASC", $id), ARRAY_A);
        wp_send_json_success(['items'=>$rows ?: []]);
    }
}
