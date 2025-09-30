<?php
namespace ARM\PublicSite;
if (!defined('ABSPATH')) exit;

class Assets {
    public static function boot() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_footer', [__CLASS__, 'inject_initial_years']);
    }

    public static function enqueue() {
        if (!is_singular()) return;
        wp_enqueue_style('arm-re-frontend', ARM_RE_URL.'assets/css/arm-frontend.css', [], ARM_RE_VERSION);
        wp_enqueue_script('arm-re-frontend', ARM_RE_URL.'assets/js/arm-frontend.js', ['jquery'], ARM_RE_VERSION, true);
        wp_localize_script('arm-re-frontend', 'ARM_RE', [
            'ajax_url'=> admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('arm_re_nonce'),
            'msgs'    => [
                'required'=> __('Please fill all required fields and accept Terms.','arm-repair-estimates'),
                'ok'      => __('Thanks! Your estimate request has been submitted.','arm-repair-estimates'),
                'error'   => __('Unable to submit right now. Please try again.','arm-repair-estimates')
            ]
        ]);
    }

    public static function inject_initial_years() {
        if (!is_singular()) return;
        global $post; if (!$post) return;
        if (!has_shortcode($post->post_content ?? '', 'arm_repair_estimate_form')) return;
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_vehicle_data';
        $years = $wpdb->get_col("SELECT DISTINCT year FROM $tbl ORDER BY year DESC");
        echo '<script>window.ARM_RE_INIT_YEARS = ' . wp_json_encode(array_values($years)) . ';</script>';
    }
}
