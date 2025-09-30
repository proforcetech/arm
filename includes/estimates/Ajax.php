<?php
namespace ARM\Estimates;
if (!defined('ABSPATH')) exit;

class Ajax {
    public static function boot() {
        add_action('wp_ajax_nopriv_arm_re_est_accept', [__CLASS__, 'accept']);
        add_action('wp_ajax_nopriv_arm_re_est_decline',[__CLASS__, 'decline']);
    }

    public static function accept() {
        check_ajax_referer('arm_re_est_action','nonce');
        global $wpdb; $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$token) wp_send_json_error(['msg'=>'Missing token']);
        $eT = $wpdb->prefix.'arm_estimates';
        $est = $wpdb->get_row($wpdb->prepare("SELECT * FROM $eT WHERE token=%s", $token));
        if (!$est) wp_send_json_error(['msg'=>'Estimate not found']);
        if (in_array($est->status, ['APPROVED','DECLINED'], true)) wp_send_json_success(['status'=>$est->status]);
        $wpdb->update($eT, ['status'=>'APPROVED','approved_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')], ['id'=>$est->id]);
        \ARM\Audit\Logger::log('estimate', $est->id, 'approved', 'customer', []);
        wp_send_json_success(['status'=>'APPROVED']);
    }

    public static function decline() {
        check_ajax_referer('arm_re_est_action','nonce');
        global $wpdb; $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$token) wp_send_json_error(['msg'=>'Missing token']);
        $eT = $wpdb->prefix.'arm_estimates';
        $est = $wpdb->get_row($wpdb->prepare("SELECT * FROM $eT WHERE token=%s", $token));
        if (!$est) wp_send_json_error(['msg'=>'Estimate not found']);
        if (in_array($est->status, ['APPROVED','DECLINED'], true)) wp_send_json_success(['status'=>$est->status]);
        $wpdb->update($eT, ['status'=>'DECLINED','updated_at'=>current_time('mysql')], ['id'=>$est->id]);
        \ARM\Audit\Logger::log('estimate', $est->id, 'declined', 'customer', []);
        wp_send_json_success(['status'=>'DECLINED']);
    }
}
