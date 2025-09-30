<?php
namespace ARM\Integrations;
if (!defined('ABSPATH')) exit;

class PartsTech {
    public static function boot() {
        // Add AJAX or REST helpers for VIN decode / search as you implement.
    }
    public static function register_settings() {
        register_setting('arm_re_settings','arm_partstech_base',    ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        register_setting('arm_re_settings','arm_partstech_api_key', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_markup_tiers',   ['type'=>'string','sanitize_callback'=>'wp_kses_post']);
    }
}
