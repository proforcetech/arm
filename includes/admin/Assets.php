<?php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

class Assets {
    public static function boot() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }
    public static function enqueue($hook) {
        if (strpos($hook, 'arm-repair') === false) return;
        wp_enqueue_style('arm-re-admin', ARM_RE_URL.'assets/css/arm-frontend.css', [], ARM_RE_VERSION);
        wp_enqueue_script('arm-estimate-admin', ARM_RE_URL.'assets/js/arm-estimate-admin.js', ['jquery'], ARM_RE_VERSION, true);
        wp_localize_script('arm-estimate-admin', 'ARM_RE_EST', [
            'nonce'=> wp_create_nonce('arm_re_est_admin')
        ]);
    }
}
