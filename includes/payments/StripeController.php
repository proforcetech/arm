<?php
namespace ARM\Payments;

if (!defined('ABSPATH')) exit;

/**
 * Stripe Checkout integration without SDK (cURL).
 * Endpoints:
 *  - POST /wp-json/arm/v1/stripe/checkout?invoice_id=123  => {url: "..."}
 *  - POST /wp-json/arm/v1/stripe/webhook                  (Stripe events)
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
        $invoice_id = (int)$req->get_param('invoice_id');
        if (!$invoice_id) return new \WP_REST_Response(['error'=>'invoice_id required'], 400);

        global $wpdb;
        $iT = $wpdb->prefix.'arm_invoices';
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $iT WHERE id=%d", $invoice_id));
        if (!$inv) return new \WP_REST_Response(['error'=>'Invoice not found'], 404);
        if ($inv->status === 'PAID') return new \WP_REST_Response(['error'=>'Invoice already paid'], 409);

        $amount_cents = (int) round(((float)$inv->total) * 100);
        if ($amount_cents < 1) return new \WP_REST_Response(['error'=>'Invalid amount'], 400);

        $currency   = get_option('arm_re_currency', 'usd');
        $secret_key = trim(get_option('arm_re_stripe_sk',''));
        if (!$secret_key) return new \WP_REST_Response(['error'=>'Stripe not configured'], 500);

        $success = get_option('arm_re_pay_success', home_url('/'));
        $cancel  = get_option('arm_re_pay_cancel', home_url('/'));

        // Build request to Stripe
        $params = [
            'mode' => 'payment',
            'success_url' => add_query_arg(['paid'=>'1','inv'=>$inv->token], $success),
            'cancel_url'  => add_query_arg(['canceled'=>'1','inv'=>$inv->token], $cancel),
            'metadata[invoice_id]' => (string)$inv->id,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][product_data][name]' => sprintf('Invoice %s', $inv->invoice_no),
            'line_items[0][price_data][unit_amount]' => $amount_cents,
            'line_items[0][quantity]' => 1,
        ];

        $resp = self::stripe_api('/v1/checkout/sessions', $params, $secret_key);
        if (empty($resp['id']) || empty($resp['url'])) {
            return new \WP_REST_Response(['error'=>'Stripe error','detail'=>$resp], 502);
        }
        return new \WP_REST_Response(['url'=>$resp['url']], 200);
    }

    /** Stripe webhook to mark invoices PAID */
    public static function rest_webhook(\WP_REST_Request $req) {
        $whsec = trim(get_option('arm_re_stripe_whsec',''));
        $payload = $req->get_body();
        $sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        // Best-effort verification (v1 signature)
        if ($whsec && $sig) {
            $parts = [];
            foreach (explode(',', $sig) as $kv) {
                list($k,$v) = array_pad(explode('=', trim($kv), 2), 2, '');
                $parts[$k] = $v;
            }
            if (!empty($parts['t']) && !empty($parts['v1'])) {
                $signed_payload = $parts['t'].'.'.$payload;
                $expected = hash_hmac('sha256', $signed_payload, $whsec);
                // constant-time compare
                if (!hash_equals($expected, $parts['v1'])) {
                    return new \WP_REST_Response(['error'=>'Invalid signature'], 400);
                }
            }
        }

        $evt = json_decode($payload, true);
        if (isset($evt['type']) && $evt['type'] === 'checkout.session.completed') {
            $invoice_id = isset($evt['data']['object']['metadata']['invoice_id']) ? (int)$evt['data']['object']['metadata']['invoice_id'] : 0;
            if ($invoice_id) {
                global $wpdb; $iT = $wpdb->prefix.'arm_invoices';
                $wpdb->update($iT, ['status'=>'PAID','updated_at'=>current_time('mysql')], ['id'=>$invoice_id]);
            }
        }
        return new \WP_REST_Response(['ok'=>true], 200);
    }

    /** Low-level Stripe API helper using cURL */
    private static function stripe_api($path, array $params, $secret_key) {
        $ch = curl_init('https://api.stripe.com'.$path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&'),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$secret_key,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $out = curl_exec($ch);
        $err = curl_error($ch); curl_close($ch);
        if ($err) return ['error'=>$err];
        $json = json_decode($out, true);
        return is_array($json) ? $json : ['raw'=>$out];
    }
}
