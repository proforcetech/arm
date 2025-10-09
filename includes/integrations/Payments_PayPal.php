<?php
namespace ARM\Integrations;

use ARM\Payments\PayPalController;
use ARM\Payments\PayPalService;

if (!defined('ABSPATH')) exit;

class Payments_PayPal {
    public static function boot() {
        PayPalController::boot();
        add_action('admin_notices', [__CLASS__, 'render_admin_notices']);
    }

    public static function install_tables() {  }

    public static function is_configured(): bool {
        return PayPalService::is_configured();
    }

    public static function render_admin_notices(): void {
        if (!current_user_can('manage_options')) return;
        $notice = get_transient('arm_re_notice_paypal');
        if (!$notice || empty($notice['message'])) return;
        delete_transient('arm_re_notice_paypal');
        $class = ($notice['type'] ?? 'error') === 'success' ? 'notice notice-success' : 'notice notice-error';
        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    public static function settings_fields() {
        register_setting('arm_re_settings','arm_re_paypal_env',       ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_paypal_client_id', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_paypal_secret',    ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_paypal_webhook_id',['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    }
}
