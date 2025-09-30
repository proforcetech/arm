<?php
namespace ARM\Integrations;
if (!defined('ABSPATH')) exit;

class Payments_PayPal {
    public static function boot() {}
    public static function install_tables() { /* no-op */ }

    public static function settings_fields() {
        register_setting('arm_re_settings','arm_re_paypal_env',      ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_paypal_client_id',['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_paypal_secret',   ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    }
}
