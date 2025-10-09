<?php
/**
 * Front-end assets & helpers (namespaced)
 */
namespace ARM\Public;

if (!defined('ABSPATH')) exit;

final class Assets {

    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_footer', [__CLASS__, 'inject_initial_years']);
    }

    /**
     * Enqueue front-end CSS/JS and localize messages.
     */
    public static function enqueue(): void {
        if (!is_singular()) return;

        
        wp_enqueue_style(
            'arm-re-frontend',
            \ARM_RE_URL . 'assets/css/arm-frontend.css',
            [],
            \ARM_RE_VERSION
        );

        wp_enqueue_script(
            'arm-re-frontend',
            \ARM_RE_URL . 'assets/js/arm-frontend.js',
            ['jquery'],
            \ARM_RE_VERSION,
            true
        );

        wp_localize_script('arm-re-frontend', 'ARM_RE', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('arm_re_nonce'),
            'msgs'     => [
                'required' => __('Please fill all required fields and accept Terms.', 'arm-repair-estimates'),
                'ok'       => __('Thanks! Your estimate request has been submitted.', 'arm-repair-estimates'),
                'error'    => __('Unable to submit right now. Please try again.', 'arm-repair-estimates'),
            ],
        ]);
    }

    /**
     * Inject initial Years array only on pages that contain the shortcode.
     * Mirrors earlier inline footer logic.
     */
    public static function inject_initial_years(): void {
        if (!is_singular()) return;

        global $post, $wpdb;
        if (!$post) return;

        
        if (!has_shortcode($post->post_content ?? '', 'arm_repair_estimate_form')) return;

        $tbl   = $wpdb->prefix . 'arm_vehicle_data';
        $years = $wpdb->get_col("SELECT DISTINCT year FROM $tbl ORDER BY year DESC");

        
        echo '<script>window.ARM_RE_INIT_YEARS = ' . wp_json_encode(array_values($years)) . ';</script>';
    }
}
