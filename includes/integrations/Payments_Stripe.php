<?php
namespace ARM\Integrations;
if (!defined('ABSPATH')) exit;

class Payments_Stripe {
    public static function boot() {
        // Add REST route / webhooks if needed.
    }
    public static function install_tables() { /* no-op safe */ }

    public static function settings_fields() {
        register_setting('arm_re_settings', 'arm_re_currency',     ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_stripe_pk',    ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_stripe_sk',    ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_stripe_whsec', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings', 'arm_re_pay_success',  ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        register_setting('arm_re_settings', 'arm_re_pay_cancel',   ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        // Fields render within Settings page automatically (you’re using plain inputs there)
    }
}
