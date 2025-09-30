<?php
// file: includes/payments/PayPalController.php
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
        });
    }

    /** Create a PayPal order; returns {id} */
    public static function rest_order(\WP_REST_Request $req): \WP_REST_Response
    {
        $invoice_id = (int) $req->get_param('invoice_id');
        if ($invoice_id <= 0) return new \WP_REST_Response(['error' => 'invoice_id required'], 400);

        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}arm_invoices WHERE id=%d", $invoice_id));
        if (!$inv) return new \WP_REST_Response(['error' => 'invoice not found'], 404);

        $currency = strtoupper(get_option('arm_re_currency', 'USD'));
        $amount   = number_format((float) ($inv->total ?? 0), 2, '.', '');

        $token = self::oauth_token();
        if (!$token) return new \WP_REST_Response(['error' => 'PayPal not configured'], 500);

        $endpoint = self::base() . '/v2/checkout/orders';
        $payload  = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) (int) $inv->id,
                'amount' => ['currency_code' => $currency, 'value' => $amount],
            ]],
        ];
        $resp = self::http('POST', $endpoint, $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        if (empty($resp['id'])) {
            return new \WP_REST_Response(['error' => 'paypal_error', 'detail' => $resp], 502);
        }
        return new \WP_REST_Response(['id' => $resp['id']], 200);
    }

    /** Capture an order and mark invoice paid */
    public static function rest_capture(\WP_REST_Request $req): \WP_REST_Response
    {
        $order_id = sanitize_text_field((string) $req->get_param('order_id'));
        if ($order_id === '') return new \WP_REST_Response(['error' => 'order_id required'], 400);

        $token = self::oauth_token();
        if (!$token) return new \WP_REST_Response(['error' => 'PayPal not configured'], 500);

        $endpoint = self::base() . '/v2/checkout/orders/' . rawurlencode($order_id) . '/capture';
        $resp = self::http('POST', $endpoint, (object) [], [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);

        $completed = !empty($resp['status']) && $resp['status'] === 'COMPLETED';
        if ($completed) {
            $ref = $order_id;
            $invoice_id = 0;
            if (!empty($resp['purchase_units'][0]['reference_id'])) {
                $invoice_id = (int) $resp['purchase_units'][0]['reference_id'];
            }
            if ($invoice_id > 0) self::mark_invoice_paid($invoice_id, $ref, 'paypal');
        }

        return new \WP_REST_Response(['status' => $resp['status'] ?? ''], $completed ? 200 : 502);
    }

    /** Helpers */

    private static function base(): string
    {
        $env = strtolower((string) get_option('arm_re_paypal_env', 'sandbox'));
        return $env === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private static function oauth_token(): ?string
    {
        $id  = (string) get_option('arm_re_paypal_client_id', '');
        $sec = (string) get_option('arm_re_paypal_secret', '');
        if ($id === '' || $sec === '') return null;

        $resp = wp_remote_post(self::base() . '/v1/oauth2/token', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($id . ':' . $sec),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => ['grant_type' => 'client_credentials'],
        ]);
        if (is_wp_error($resp)) return null;
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($json) && !empty($json['access_token']) ? (string) $json['access_token'] : null;
    }

    private static function http(string $method, string $url, $body, array $headers): array
    {
        $args = ['method' => $method, 'timeout' => 15, 'headers' => $headers];
        if ($method !== 'GET') $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) return ['error' => $resp->get_error_message()];
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($json) ? $json : ['raw' => wp_remote_retrieve_body($resp)];
    }

    private static function mark_invoice_paid(int $invoice_id, string $ref, string $gateway): void
    {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_invoices';

        $cols = array_map('strtolower', $wpdb->get_col(
            $wpdb->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s", $tbl)
        ) ?: []);

        $data = [];
        $fmt  = [];
        if (in_array('status', $cols, true)) { $data['status'] = 'PAID'; $fmt[] = '%s'; }
        if (in_array('paid_at', $cols, true)) { $data['paid_at'] = current_time('mysql'); $fmt[] = '%s'; }
        foreach (['payment_ref','payment_reference','transaction_id','paypal_order_id'] as $c) {
            if (in_array($c, $cols, true)) { $data[$c] = $ref; $fmt[] = '%s'; break; }
        }
        if (in_array('payment_gateway', $cols, true)) { $data['payment_gateway'] = $gateway; $fmt[] = '%s'; }

        if ($data) $wpdb->update($tbl, $data, ['id' => $invoice_id], $fmt, ['%d']);
        do_action('arm/invoice/paid', $invoice_id, $gateway, $ref);
    }
}
