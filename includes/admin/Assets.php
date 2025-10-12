<?php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

class Assets {
    public static function boot() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }
    public static function enqueue($hook) {
        if (!self::should_enqueue_for($hook)) return;
        global $wpdb;
        $ajax_url = admin_url('admin-ajax.php');
        $vehicle_years = [];
        if ($wpdb instanceof \wpdb) {
            $table = $wpdb->prefix.'arm_vehicle_data';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($table_exists) {
                $vehicle_years = $wpdb->get_col("SELECT DISTINCT year FROM $table ORDER BY year DESC");
            }
        }
        wp_enqueue_style('arm-re-admin', ARM_RE_URL.'assets/css/arm-frontend.css', [], ARM_RE_VERSION);
        wp_enqueue_script('arm-admin', ARM_RE_URL.'assets/js/arm-admin.js', ['jquery'], ARM_RE_VERSION, true);
        wp_localize_script('arm-admin', 'ARM_RE_EST', [
            'nonce'      => wp_create_nonce('arm_re_est_admin'),
            'ajax_url'   => $ajax_url,
            'rest'       => [
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
            'vehicle' => [
                'ajax_url'  => $ajax_url,
                'nonce'     => wp_create_nonce('arm_re_nonce'),
                'initYears' => array_map('strval', $vehicle_years),
                'selectorNewValue' => \ARM\Estimates\Controller::vehicle_selector_new_value(),
            ],
            'itemRowTemplate' => \ARM\Estimates\Controller::item_row_template(),
            'i18n' => [
                'copied'        => __('Link copied to clipboard.', 'arm-repair-estimates'),
                'copyFailed'    => __('Unable to copy link.', 'arm-repair-estimates'),
                'startingPay'   => __('Generating payment session…', 'arm-repair-estimates'),
                'vinPlaceholder'=> __('Enter a 17-digit VIN', 'arm-repair-estimates'),
                'vinError'      => __('VIN lookup failed. Check the VIN and try again.', 'arm-repair-estimates'),
                'searchError'   => __('Parts search failed. Please try again.', 'arm-repair-estimates'),
                'selectSavedVehicle' => __('Select a saved vehicle', 'arm-repair-estimates'),
                'addNewVehicle'     => __('Add new vehicle', 'arm-repair-estimates'),
            ],
        ]);
    }
    protected static function should_enqueue_for($value) {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (strpos($value, 'arm-repair') !== false) {
            return true;
        }

        if (strpos($value, 'arm-customer-detail') !== false) {
            return true;
        }

        return strncmp($value, 'arm-', 4) === 0;
    }
}
