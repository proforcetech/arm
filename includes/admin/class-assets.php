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
        $ajax_url = admin_url('admin-ajax.php');

        wp_localize_script('arm-repair-admin', 'ARM_RE_EST', [
            'nonce'              => wp_create_nonce('arm_re_est_admin'),
            'ajax_url'           => $ajax_url,
            'ajaxUrl'            => $ajax_url,
            'version'            => \ARM_RE_VERSION,
            'taxApply'           => get_option('arm_re_tax_apply', 'parts_labor'),
            'defaultLabor'       => (float) get_option('arm_re_labor_rate', 0),
            'calloutDefault'     => (float) get_option('arm_re_callout_default', 0),
            'mileageRateDefault' => (float) get_option('arm_re_mileage_rate_default', 0),
            'rest' => [
                'stripeCheckout' => rest_url('arm/v1/stripe/checkout'),
                'stripeIntent'   => rest_url('arm/v1/stripe/payment-intent'),
                'paypalOrder'    => rest_url('arm/v1/paypal/order'),
                'paypalCapture'  => rest_url('arm/v1/paypal/capture'),
            ],
            'integrations' => [
                'stripe'    => \ARM\Integrations\Payments_Stripe::is_configured(),
                'paypal'    => \ARM\Integrations\Payments_PayPal::is_configured(),
                'partstech' => !empty(get_option('arm_partstech_api_key')),
            ],
            'partstech' => [
                'vin'    => add_query_arg(['action' => 'arm_partstech_vin'], $ajax_url),
                'search' => add_query_arg(['action' => 'arm_partstech_search'], $ajax_url),
            ],
            'itemRowTemplate' => \ARM\Estimates\Controller::item_row_template(),
            'i18n' => [
                'copied'         => __('Link copied to clipboard.', 'arm-repair-estimates'),
                'copyFailed'     => __('Unable to copy link.', 'arm-repair-estimates'),
                'startingPay'    => __('Generating payment session…', 'arm-repair-estimates'),
                'vinPlaceholder' => __('Enter a 17-digit VIN', 'arm-repair-estimates'),
                'vinError'       => __('VIN lookup failed. Check the VIN and try again.', 'arm-repair-estimates'),
                'searchError'    => __('Parts search failed. Please try again.', 'arm-repair-estimates'),
            ],
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
