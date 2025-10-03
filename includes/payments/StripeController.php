<?php
namespace ARM\Payments;

if (!defined('ABSPATH')) exit;

/**
 * REST surface for the StripeService.
 * Endpoints:
 *  - POST /wp-json/arm/v1/stripe/checkout       { invoice_id }
 *  - POST /wp-json/arm/v1/stripe/payment-intent { invoice_id }
 *  - POST /wp-json/arm/v1/stripe/webhook        (raw Stripe event)
 */
class StripeController {

    public static function settings_fields() {
        register_setting('arm_re_settings','arm_re_currency',   ['type'=>'string','sanitize_callback'=>function($v){ $v=strtolower(sanitize_text_field($v)); return $v?:'usd'; }]);
        register_setting('arm_re_settings','arm_re_stripe_pk',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_stripe_sk',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_stripe_whsec',['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_pay_success',['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        register_setting('arm_re_settings','arm_re_pay_cancel', ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
    }

    public static function boot() {
        add_action('rest_api_init', function(){
            register_rest_route('arm/v1', '/stripe/checkout', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_checkout'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('arm/v1', '/stripe/payment-intent', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_payment_intent'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('arm/v1', '/stripe/webhook', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_webhook'],
                'permission_callback' => '__return_true',
                'args' => []
            ]);
        });
    }

    /** Create a Stripe Checkout Session and return its URL */
    public static function rest_checkout(\WP_REST_Request $req) {
        $invoice_id = (int) $req->get_param('invoice_id');
        if ($invoice_id <= 0) {
            return new \WP_REST_Response(['error' => 'invoice_id required'], 400);
        }

        $result = StripeService::create_checkout_session($invoice_id);
        if (!empty($result['error'])) {
            $code = $result['code'] ?? '';
            $status = 502;
            if ($code === 'invoice_not_found') {
                $status = 404;
            } elseif (in_array($code, ['invalid_total', 'already_paid'], true)) {
                $status = 400;
            } elseif ($code === 'not_configured') {
                $status = 500;
            }
            return new \WP_REST_Response($result, $status);
        }
        return new \WP_REST_Response($result, 200);
    }

    /** Create a PaymentIntent and return client_secret */
    public static function rest_payment_intent(\WP_REST_Request $req) {
        $invoice_id = (int) $req->get_param('invoice_id');
        if ($invoice_id <= 0) {
            return new \WP_REST_Response(['error' => 'invoice_id required'], 400);
        }
        $result = StripeService::create_payment_intent($invoice_id);
        if (!empty($result['error'])) {
            $code = $result['code'] ?? '';
            $status = 502;
            if ($code === 'invoice_not_found') {
                $status = 404;
            } elseif (in_array($code, ['invalid_total', 'already_paid'], true)) {
                $status = 400;
            } elseif ($code === 'not_configured') {
                $status = 500;
            }
            return new \WP_REST_Response($result, $status);
        }
        return new \WP_REST_Response($result, 200);
    }

    /** Stripe webhook to mark invoices PAID */
    public static function rest_webhook(\WP_REST_Request $req) {
        $payload   = $req->get_body();
        $signature = (string) $req->get_header('stripe-signature');
        if ($signature === '') {
            $signature = (string) ($req->get_header('Stripe-Signature') ?? '');
        }
        if ($signature === '' && isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $signature = (string) $_SERVER['HTTP_STRIPE_SIGNATURE'];
        }
        $result = StripeService::handle_webhook($payload, $signature);
        $status = empty($result['error']) ? 200 : 400;
        return new \WP_REST_Response($result, $status);
    }
}
