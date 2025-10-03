<?php
namespace ARM\Integrations;

use ARM\Payments\StripeController;
use ARM\Payments\StripeService;

if (!defined('ABSPATH')) exit;

class Payments_Stripe {
    public static function boot() {
        StripeController::boot();
        add_action('admin_notices', [__CLASS__, 'render_admin_notices']);
    }

    public static function install_tables() { /* no-op safe */ }

    public static function is_configured(): bool {
        return StripeService::is_configured();
    }

    public static function render_admin_notices(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = get_transient('arm_re_notice_stripe');
        if (!$notice || empty($notice['message'])) {
            return;
        }
        delete_transient('arm_re_notice_stripe');
        $class = ($notice['type'] ?? 'error') === 'success' ? 'notice notice-success' : 'notice notice-error';
        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    public static function settings_fields() {
        register_setting('arm_re_settings', 'arm_re_currency',     ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_stripe_pk',    ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_stripe_sk',    ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_stripe_whsec', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_pay_success',  ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        register_setting('arm_re_settings', 'arm_re_pay_cancel',   ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
    }
}
