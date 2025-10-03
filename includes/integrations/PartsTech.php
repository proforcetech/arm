<?php
namespace ARM\Integrations;

use ARM\Audit\Logger;

if (!defined('ABSPATH')) exit;

class PartsTech {
    public static function boot() {
        add_action('wp_ajax_arm_partstech_vin', [__CLASS__, 'ajax_vin']);
        add_action('wp_ajax_arm_partstech_search', [__CLASS__, 'ajax_search']);
        add_action('admin_notices', [__CLASS__, 'render_admin_notices']);
    }

    public static function register_settings() {
        register_setting('arm_re_settings','arm_partstech_base',    ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        register_setting('arm_re_settings','arm_partstech_api_key', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_markup_tiers',   ['type'=>'string','sanitize_callback'=>'wp_kses_post']);
    }

    public static function is_configured(): bool {
        return trim((string) get_option('arm_partstech_api_key', '')) !== '';
    }

    public static function render_admin_notices(): void {
        if (!current_user_can('manage_options')) return;
        $notice = get_transient('arm_re_notice_partstech');
        if (!$notice || empty($notice['message'])) return;
        delete_transient('arm_re_notice_partstech');
        $class = ($notice['type'] ?? 'error') === 'success' ? 'notice notice-success' : 'notice notice-error';
        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    public static function ajax_vin(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['error' => 'forbidden'], 403);
        check_ajax_referer('arm_re_est_admin');
        $vin = strtoupper(sanitize_text_field($_POST['vin'] ?? ''));
        if ($vin === '') wp_send_json_error(['error' => 'missing vin'], 400);
        $resp = self::request('GET', '/catalog/v1/vehicles/lookup', ['vin' => $vin]);
        if (!empty($resp['error'])) wp_send_json_error(['error' => $resp['error']], 500);
        if (empty($resp['data'])) wp_send_json_error(['error' => 'no results'], 404);
        $vehicle = $resp['data'];
        $label = trim(($vehicle['year'] ?? '') . ' ' . ($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        wp_send_json_success([
            'label' => $label,
            'vehicle' => [
                'year'  => $vehicle['year'] ?? '',
                'make'  => $vehicle['make'] ?? '',
                'model' => $vehicle['model'] ?? '',
                'engine'=> $vehicle['engine'] ?? '',
            ]
        ]);
    }

    public static function ajax_search(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['error' => 'forbidden'], 403);
        check_ajax_referer('arm_re_est_admin');
        $query = sanitize_text_field($_POST['query'] ?? '');
        if ($query === '') wp_send_json_error(['error' => 'query required'], 400);
        $vehicle = isset($_POST['vehicle']) && is_array($_POST['vehicle']) ? array_map('sanitize_text_field', $_POST['vehicle']) : [];
        $payload = [
            'keyword' => $query,
            'vehicle' => array_filter([
                'year'  => $vehicle['year'] ?? null,
                'make'  => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'engine'=> $vehicle['engine'] ?? null,
            ]),
            'includePricing' => true,
        ];
        $resp = self::request('POST', '/catalog/v1/parts/search', $payload);
        if (!empty($resp['error'])) wp_send_json_error(['error' => $resp['error']], 500);
        $results = [];
        foreach (($resp['items'] ?? []) as $item) {
            $results[] = [
                'name'          => $item['description'] ?? ($item['partNumber'] ?? ''),
                'brand'         => $item['brand'] ?? '',
                'partNumber'    => $item['partNumber'] ?? '',
                'price'         => isset($item['price']) ? (float) $item['price'] : null,
                'priceFormatted'=> isset($item['price']) ? '$' . number_format((float) $item['price'], 2) : '',
            ];
        }
        wp_send_json_success(['results' => $results]);
    }

    private static function request(string $method, string $path, $body): array {
        if (!self::is_configured()) {
            return ['error' => __('PartsTech API key missing', 'arm-repair-estimates')];
        }
        $base = rtrim((string) get_option('arm_partstech_base', 'https://api.partstech.com'), '/');
        $url = $base . $path;
        $apiKey = trim((string) get_option('arm_partstech_api_key', ''));
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
                'X-Api-Key'    => $apiKey,
            ],
        ];
        if ($method === 'GET') {
            $url = add_query_arg($body, $url);
        } else {
            $args['body'] = wp_json_encode($body);
        }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            self::log_error('request_failed', $resp->get_error_message());
            return ['error' => $resp->get_error_message()];
        }
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($data)) {
            self::log_error('invalid_response', wp_remote_retrieve_body($resp));
            return ['error' => __('Invalid response from PartsTech', 'arm-repair-estimates')];
        }
        return $data;
    }

    private static function log_error(string $code, $detail): void {
        set_transient('arm_re_notice_partstech', [
            'type' => 'error',
            'message' => sprintf(__('PartsTech error (%s). See logs for details.', 'arm-repair-estimates'), $code),
        ], MINUTE_IN_SECONDS * 10);
        Logger::log('integration', 0, 'partstech_error', 'system', [
            'code'   => $code,
            'detail' => $detail,
        ]);
    }
}
