<?php

namespace ARM\Payments;

if (!defined('ABSPATH')) exit;

/**
 * PayPal Orders v2 (no SDK). Creates and captures orders; marks invoices paid.
 */
final class PayPalController
{
    public static function boot(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('arm/v1', '/paypal/order', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_order'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('arm/v1', '/paypal/capture', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_capture'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('arm/v1', '/paypal/webhook', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_webhook'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /** Create a PayPal order; returns {id} */
    public static function rest_order(\WP_REST_Request $req): \WP_REST_Response
    {
        $invoice_id = (int) $req->get_param('invoice_id');
        if ($invoice_id <= 0) {
            return new \WP_REST_Response(['error' => 'invoice_id required'], 400);
        }

        $resp = PayPalService::create_order($invoice_id);
        if (!empty($resp['error'])) {
            $code = $resp['code'] ?? '';
            $status = 502;
            if ($code === 'invoice_not_found') {
                $status = 404;
            } elseif ($code === 'already_paid') {
                $status = 400;
            } elseif ($code === 'not_configured') {
                $status = 500;
            }
            return new \WP_REST_Response($resp, $status);
        }
        return new \WP_REST_Response($resp, 200);
    }

    /** Capture an order and mark invoice paid */
    public static function rest_capture(\WP_REST_Request $req): \WP_REST_Response
    {
        $order_id = sanitize_text_field((string) $req->get_param('order_id'));
        if ($order_id === '') {
            return new \WP_REST_Response(['error' => 'order_id required'], 400);
        }

        $resp = PayPalService::capture_order($order_id);
        if (!empty($resp['error'])) {
            $code = $resp['code'] ?? '';
            $status = 502;
            if ($code === 'missing_order') {
                $status = 400;
            } elseif ($code === 'not_configured') {
                $status = 500;
            }
            return new \WP_REST_Response($resp, $status);
        }
        return new \WP_REST_Response($resp, 200);
    }

    /** PayPal webhook */
    public static function rest_webhook(\WP_REST_Request $req): \WP_REST_Response
    {
        $payload = $req->get_body();
        $headers = [];

        
        foreach ($req->get_headers() as $name => $values) {
            $key = strtoupper(str_replace('_', '-', $name));
            $headers[$key] = is_array($values) ? reset($values) : $values;
        }

        
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_PAYPAL') === 0) {
                $normalized = strtoupper(str_replace(['HTTP_', '_'], ['', '-'], $key));
                if (!isset($headers[$normalized])) {
                    $headers[$normalized] = $value;
                }
            }
        }
        $result = PayPalService::handle_webhook($payload, $headers);
        $status = empty($result['error']) ? 200 : (in_array($result['code'] ?? '', ['not_configured','webhook_not_configured'], true) ? 500 : 400);
        return new \WP_REST_Response($result, $status);
    }

    /** Helpers */

    private static function base(): string { return PayPalService::base_url(); }
}
