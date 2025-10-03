<?php
namespace ARM\Payments;

use ARM\Audit\Logger;

if (!defined('ABSPATH')) exit;

class PayPalService {

    public static function is_configured(): bool {
        $id  = trim((string) get_option('arm_re_paypal_client_id', ''));
        $sec = trim((string) get_option('arm_re_paypal_secret', ''));
        return $id !== '' && $sec !== '';
    }

    public static function create_order(int $invoice_id): array {
        $invoice = self::get_invoice($invoice_id);
        if (!$invoice) {
            return ['error' => __('Invoice not found', 'arm-repair-estimates'), 'code' => 'invoice_not_found'];
        }
        if ($invoice->status === 'PAID') {
            return ['error' => __('Invoice already paid', 'arm-repair-estimates'), 'code' => 'already_paid'];
        }

        $token = self::oauth_token();
        if (!$token) {
            return ['error' => __('PayPal not configured', 'arm-repair-estimates'), 'code' => 'not_configured'];
        }

        $endpoint = self::base_url() . '/v2/checkout/orders';
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $invoice->id,
                'amount' => [
                    'currency_code' => strtoupper((string) get_option('arm_re_currency', 'USD')),
                    'value'         => number_format((float) $invoice->total, 2, '.', ''),
                ],
            ]],
        ];

        $resp = self::http('POST', $endpoint, $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        if (!empty($resp['error'])) {
            self::log_error('paypal_order_error', $resp['error']);
            return ['error' => __('PayPal error', 'arm-repair-estimates'), 'code' => 'api_error'];
        }
        if (empty($resp['id'])) {
            self::log_error('paypal_order_invalid', $resp);
            return ['error' => __('Unexpected PayPal response', 'arm-repair-estimates'), 'code' => 'api_error'];
        }
        return ['id' => (string) $resp['id']];
    }

    public static function capture_order(string $order_id): array {
        $order_id = trim($order_id);
        if ($order_id === '') {
            return ['error' => __('order_id required', 'arm-repair-estimates'), 'code' => 'missing_order'];
        }
        $token = self::oauth_token();
        if (!$token) {
            return ['error' => __('PayPal not configured', 'arm-repair-estimates'), 'code' => 'not_configured'];
        }
        $endpoint = self::base_url() . '/v2/checkout/orders/' . rawurlencode($order_id) . '/capture';
        $resp = self::http('POST', $endpoint, (object) [], [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        if (!empty($resp['error'])) {
            self::log_error('paypal_capture_error', $resp['error']);
            return ['error' => __('PayPal error', 'arm-repair-estimates'), 'code' => 'api_error'];
        }
        $status = (string) ($resp['status'] ?? '');
        if ($status === 'COMPLETED') {
            $invoice_id = 0;
            if (!empty($resp['purchase_units'][0]['reference_id'])) {
                $invoice_id = (int) $resp['purchase_units'][0]['reference_id'];
            }
            if ($invoice_id > 0) {
                self::mark_invoice_paid($invoice_id, $order_id, 'paypal');
            }
            return ['status' => 'PAID'];
        }
        self::log_error('paypal_capture_unexpected', $resp);
        return ['error' => __('Capture failed', 'arm-repair-estimates'), 'detail' => $status, 'code' => 'api_error'];
    }

    public static function handle_webhook(string $payload, array $headers): array {
        $webhook_id = trim((string) get_option('arm_re_paypal_webhook_id', ''));
        if ($webhook_id === '') {
            return ['error' => __('Webhook ID missing', 'arm-repair-estimates'), 'code' => 'webhook_not_configured'];
        }
        $token = self::oauth_token();
        if (!$token) {
            return ['error' => __('PayPal not configured', 'arm-repair-estimates'), 'code' => 'not_configured'];
        }
        $body = json_decode($payload, true);
        if (!is_array($body)) {
            self::log_error('paypal_webhook_json', $payload);
            return ['error' => __('Invalid payload', 'arm-repair-estimates'), 'code' => 'invalid_payload'];
        }

        $verify_payload = [
            'auth_algo'         => $headers['PAYPAL-AUTH-ALGO'] ?? $headers['Paypal-Auth-Algo'] ?? '',
            'cert_url'          => $headers['PAYPAL-CERT-URL'] ?? $headers['Paypal-Cert-Url'] ?? '',
            'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID'] ?? $headers['Paypal-Transmission-Id'] ?? '',
            'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG'] ?? $headers['Paypal-Transmission-Sig'] ?? '',
            'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? $headers['Paypal-Transmission-Time'] ?? '',
            'webhook_id'        => $webhook_id,
            'webhook_event'     => $body,
        ];

        $verify = self::http('POST', self::base_url() . '/v1/notifications/verify-webhook-signature', $verify_payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        if (empty($verify['verification_status']) || $verify['verification_status'] !== 'SUCCESS') {
            self::log_error('paypal_webhook_verify', $verify);
            return ['error' => __('Webhook verification failed', 'arm-repair-estimates'), 'code' => 'invalid_signature'];
        }

        $event_type = (string) ($body['event_type'] ?? '');
        $resource   = $body['resource'] ?? [];
        if (!is_array($resource)) {
            $resource = [];
        }

        if ($event_type === 'CHECKOUT.ORDER.APPROVED' || $event_type === 'PAYMENT.CAPTURE.COMPLETED') {
            $invoice_id = 0;
            if (!empty($resource['purchase_units'][0]['reference_id'])) {
                $invoice_id = (int) $resource['purchase_units'][0]['reference_id'];
            }
            if ($invoice_id > 0) {
                $reference = $resource['id'] ?? ($resource['purchase_units'][0]['payments']['captures'][0]['id'] ?? '');
                self::mark_invoice_paid($invoice_id, (string) $reference, 'paypal-webhook');
            }
        }

        return ['ok' => true];
    }

    public static function base_url(): string {
        $env = strtolower((string) get_option('arm_re_paypal_env', 'sandbox'));
        return $env === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private static function oauth_token(): ?string {
        $id  = (string) get_option('arm_re_paypal_client_id', '');
        $sec = (string) get_option('arm_re_paypal_secret', '');
        if ($id === '' || $sec === '') {
            return null;
        }
        $resp = wp_remote_post(self::base_url() . '/v1/oauth2/token', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($id . ':' . $sec),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => ['grant_type' => 'client_credentials'],
        ]);
        if (is_wp_error($resp)) {
            self::log_error('paypal_oauth_wp_error', $resp->get_error_message());
            return null;
        }
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($json) || empty($json['access_token'])) {
            self::log_error('paypal_oauth_invalid', $json);
            return null;
        }
        return (string) $json['access_token'];
    }

    private static function http(string $method, string $url, $body, array $headers): array {
        $args = ['method' => $method, 'timeout' => 20, 'headers' => []];
        foreach ($headers as $header) {
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $args['headers'][trim($name)] = trim($value);
            }
        }
        if ($method !== 'GET') {
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return ['error' => $resp->get_error_message()];
        }
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($json)) {
            return ['error' => wp_remote_retrieve_body($resp)];
        }
        return $json;
    }

    private static function get_invoice(int $id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_invoices';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $id));
    }

    private static function mark_invoice_paid(int $invoice_id, string $reference, string $gateway): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_invoices';
        $wpdb->update($tbl, [
            'status'     => 'PAID',
            'updated_at' => current_time('mysql'),
        ], ['id' => $invoice_id]);

        Logger::log('invoice', $invoice_id, 'paid', $gateway, [
            'reference' => $reference,
        ]);

        do_action('arm/invoice/paid', $invoice_id, 'paypal', $reference);
    }

    private static function log_error(string $code, $detail): void {
        set_transient('arm_re_notice_paypal', [
            'type' => 'error',
            'message' => sprintf(__('PayPal error (%s). See logs for details.', 'arm-repair-estimates'), $code),
        ], MINUTE_IN_SECONDS * 10);
        Logger::log('integration', 0, 'paypal_error', 'system', [
            'code'   => $code,
            'detail' => $detail,
        ]);
    }
}
