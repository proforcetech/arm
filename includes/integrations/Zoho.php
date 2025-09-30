<?php
namespace ARM\Integrations;
if (!defined('ABSPATH')) exit;

class Zoho {
    public static function boot() {}
    public static function settings_fields() {
        register_setting('arm_re_settings','arm_zoho_dc',            ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_client_id',     ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_client_secret', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_refresh',       ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_module_deal',   ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    }
}
