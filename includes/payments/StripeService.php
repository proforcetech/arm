<?php
namespace ARM\Payments;

use ARM\Audit\Logger;

if (!defined('ABSPATH')) exit;

/**
 * Low-level helper around the Stripe API for invoice payments.
 */
class StripeService {

    /** Return true when credentials are present. */
    public static function is_configured(): bool {
        $sk = trim((string) get_option('arm_re_stripe_sk', ''));
        return $sk !== '';
    }

    /** Return publishable key */
    public static function publishable_key(): string {
        return (string) get_option('arm_re_stripe_pk', '');
    }

    /** Create a Checkout Session for the invoice. */
    public static function create_checkout_session(int $invoice_id): array {
        $invoice = self::get_invoice($invoice_id);
        if (!$invoice) {
            return ['error' => __('Invoice not found', 'arm-repair-estimates'), 'code' => 'invoice_not_found'];
        }
        if ($invoice->status === 'PAID') {
            return ['error' => __('Invoice already paid', 'arm-repair-estimates'), 'code' => 'already_paid'];
        }

        $amount_cents = (int) round(((float) $invoice->total) * 100);
        if ($amount_cents < 1) {
            return ['error' => __('Invalid invoice total', 'arm-repair-estimates'), 'code' => 'invalid_total'];
        }

        $currency = strtolower((string) get_option('arm_re_currency', 'usd')) ?: 'usd';
        $secret   = trim((string) get_option('arm_re_stripe_sk', ''));
        if ($secret === '') {
            return ['error' => __('Stripe secret key missing', 'arm-repair-estimates'), 'code' => 'not_configured'];
        }

        $success = add_query_arg([
            'paid' => '1',
            'inv'  => $invoice->token,
        ], get_option('arm_re_pay_success', home_url('/')));
        $cancel = add_query_arg([
            'canceled' => '1',
            'inv'      => $invoice->token,
        ], get_option('arm_re_pay_cancel', home_url('/')));

        $params = [
            'mode' => 'payment',
            'success_url' => $success,
            'cancel_url'  => $cancel,
            'metadata' => ['invoice_id' => (string) $invoice->id],
            'line_items' => [[
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => ['name' => sprintf(__('Invoice %s', 'arm-repair-estimates'), $invoice->invoice_no)],
                    'unit_amount'  => $amount_cents,
                ],
                'quantity' => 1,
            ]],
        ];

        $resp = self::api_request('POST', '/v1/checkout/sessions', $params, $secret);
        if (!empty($resp['error'])) {
            self::log_error('stripe_checkout_error', $resp['error']);
            return ['error' => __('Stripe error', 'arm-repair-estimates'), 'detail' => $resp['error'], 'code' => 'api_error'];
        }
        if (empty($resp['id']) || empty($resp['url'])) {
            self::log_error('stripe_checkout_invalid', $resp);
            return ['error' => __('Unexpected Stripe response', 'arm-repair-estimates'), 'code' => 'api_error'];
        }
        return ['id' => (string) $resp['id'], 'url' => (string) $resp['url']];
    }

    /** Create a PaymentIntent for the invoice and return client_secret */
    public static function create_payment_intent(int $invoice_id): array {
        $invoice = self::get_invoice($invoice_id);
        if (!$invoice) return ['error' => __('Invoice not found', 'arm-repair-estimates'), 'code' => 'invoice_not_found'];
        if ($invoice->status === 'PAID') return ['error' => __('Invoice already paid', 'arm-repair-estimates'), 'code' => 'already_paid'];

        $amount_cents = (int) round(((float) $invoice->total) * 100);
        if ($amount_cents < 1) return ['error' => __('Invalid invoice total', 'arm-repair-estimates'), 'code' => 'invalid_total'];

        $currency = strtolower((string) get_option('arm_re_currency', 'usd')) ?: 'usd';
        $secret   = trim((string) get_option('arm_re_stripe_sk', ''));
        if ($secret === '') return ['error' => __('Stripe secret key missing', 'arm-repair-estimates'), 'code' => 'not_configured'];

        $params = [
            'amount' => $amount_cents,
            'currency' => $currency,
            'metadata' => ['invoice_id' => (string) $invoice->id],
            'description' => sprintf(__('Invoice %s', 'arm-repair-estimates'), $invoice->invoice_no),
            'automatic_payment_methods[enabled]' => 'true',
        ];

        $resp = self::api_request('POST', '/v1/payment_intents', $params, $secret);
        if (!empty($resp['error'])) {
            self::log_error('stripe_intent_error', $resp['error']);
            return ['error' => __('Stripe error', 'arm-repair-estimates'), 'detail' => $resp['error'], 'code' => 'api_error'];
        }
        if (empty($resp['id']) || empty($resp['client_secret'])) {
            self::log_error('stripe_intent_invalid', $resp);
            return ['error' => __('Unexpected Stripe response', 'arm-repair-estimates'), 'code' => 'api_error'];
        }
        return [
            'id' => (string) $resp['id'],
            'client_secret' => (string) $resp['client_secret'],
        ];
    }

    /** Handle webhook payload, returning ['ok'=>true] or ['error'=>...] */
    public static function handle_webhook(string $payload, string $signature): array {
        $secret = trim((string) get_option('arm_re_stripe_whsec', ''));
        if ($secret !== '' && $signature !== '') {
            if (!self::verify_signature($payload, $signature, $secret)) {
                self::log_error('stripe_webhook_signature_failed', ['signature' => $signature]);
                return ['error' => __('Invalid signature', 'arm-repair-estimates')];
            }
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            self::log_error('stripe_webhook_json_failed', ['payload' => $payload]);
            return ['error' => __('Malformed webhook payload', 'arm-repair-estimates')];
        }

        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];
        if (!is_array($object)) $object = [];

        if ($type === 'checkout.session.completed') {
            $invoice_id = isset($object['metadata']['invoice_id']) ? (int) $object['metadata']['invoice_id'] : 0;
            if ($invoice_id) self::mark_invoice_paid($invoice_id, 'stripe_checkout', $object['id'] ?? '');
        } elseif ($type === 'payment_intent.succeeded') {
            $invoice_id = isset($object['metadata']['invoice_id']) ? (int) $object['metadata']['invoice_id'] : 0;
            if ($invoice_id) self::mark_invoice_paid($invoice_id, 'stripe_intent', $object['id'] ?? '');
        }

        return ['ok' => true];
    }

    /** Verify Stripe signature header */
    private static function verify_signature(string $payload, string $header, string $secret): bool {
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            if ($k !== '') $parts[$k] = $v;
        }
        if (empty($parts['t']) || empty($parts['v1'])) return false;
        $signed_payload = $parts['t'] . '.' . $payload;
        $expected = hash_hmac('sha256', $signed_payload, $secret);
        return hash_equals($expected, $parts['v1']);
    }

    /** Basic API helper */
    private static function api_request(string $method, string $path, array $params, string $secret): array {
        $url = 'https://api.stripe.com' . $path;
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
            ],
        ];
        if ($method === 'GET') {
            $url = add_query_arg($params, $url);
        } else {
            $args['body'] = http_build_query($params, '', '&');
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return ['error' => $resp->get_error_message()];
        }
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['error' => $body];
        }
        return $json;
    }

    private static function get_invoice(int $id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_invoices';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $id));
    }

    private static function mark_invoice_paid(int $invoice_id, string $source, string $reference): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_invoices';
        $wpdb->update($tbl, [
            'status'     => 'PAID',
            'updated_at' => current_time('mysql'),
        ], ['id' => $invoice_id]);

        Logger::log('invoice', $invoice_id, 'paid', 'stripe', [
            'source'    => $source,
            'reference' => $reference,
        ]);

        do_action('arm/invoice/paid', $invoice_id, 'stripe', $reference);
    }

    private static function log_error(string $code, $detail): void {
        set_transient('arm_re_notice_stripe', [
            'type' => 'error',
            'message' => sprintf(__('Stripe error (%s). See logs for details.', 'arm-repair-estimates'), $code),
        ], MINUTE_IN_SECONDS * 10);
        Logger::log('integration', 0, 'stripe_error', 'system', [
            'code'   => $code,
            'detail' => $detail,
        ]);
    }
}
