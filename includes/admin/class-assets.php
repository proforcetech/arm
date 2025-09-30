<?php
// file: includes/admin/Class-Assets.php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

if (!class_exists(__NAMESPACE__ . '\\Assets')) {
/**
 * Enqueues admin CSS/JS for ARM Repair pages.
 * Why: load only on our plugin admin screens to keep WP admin fast.
 */
final class Assets {

    public static function boot(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue(string $hook): void {
        if (!is_admin()) return;

        // Detect our screens reliably.
        $should_load = false;
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && is_object($screen) && isset($screen->id) && is_string($screen->id)) {
                $should_load = (strpos($screen->id, 'arm-repair') !== false);
            }
        }
        if (!$should_load && is_string($hook)) {
            $should_load = (strpos($hook, 'arm-repair') !== false);
        }
        if (!$should_load) return;

        // Version from filemtime when available (why: better cache busting).
        $css_ver = self::asset_version('assets/css/arm-admin.css');
        $js_ver  = self::asset_version('assets/js/arm-admin.js');

        wp_enqueue_style(
            'arm-repair-admin',
            \ARM_RE_URL . 'assets/css/arm-admin.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'arm-repair-admin',
            \ARM_RE_URL . 'assets/js/arm-admin.js',
            ['jquery'],
            $js_ver,
            true
        );

        // Provide common data to JS (why: avoid hardcoding ajax URLs & nonces).
        wp_localize_script('arm-repair-admin', 'ARM_RE_ADMIN', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('arm_re_admin'),
            'version' => \ARM_RE_VERSION,
        ]);
    }

    /** Resolve version by filemtime; fall back to plugin version. */
    private static function asset_version(string $relative): string {
        $path = rtrim(\ARM_RE_PATH, '/\\') . '/' . ltrim($relative, '/');
        $mtime = @filemtime($path);
        return $mtime ? (string) $mtime : (string) \ARM_RE_VERSION;
    }
}
}
