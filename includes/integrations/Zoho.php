<?php
namespace ARM\Integrations;

use ARM\Audit\Logger;

if (!defined('ABSPATH')) exit;

class Zoho {
    public static function boot() {
        add_filter('arm_re_crm_fetch_customers', [__CLASS__, 'filter_fetch_contacts']);
        add_action('arm_re_customer_saved', [__CLASS__, 'handle_customer_saved'], 10, 3);
        add_action('arm/invoice/paid', [__CLASS__, 'handle_invoice_paid'], 10, 3);
        add_action('admin_notices', [__CLASS__, 'render_admin_notices']);
    }

    public static function settings_fields() {
        register_setting('arm_re_settings','arm_zoho_dc',            ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_client_id',     ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_client_secret', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_refresh',       ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_zoho_module_deal',   ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    }

    public static function render_admin_notices(): void {
        if (!current_user_can('manage_options')) return;
        $notice = get_transient('arm_re_notice_zoho');
        if (!$notice || empty($notice['message'])) return;
        delete_transient('arm_re_notice_zoho');
        $class = ($notice['type'] ?? 'error') === 'success' ? 'notice notice-success' : 'notice notice-error';
        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    public static function filter_fetch_contacts(array $contacts): array {
        $remote = self::fetch_contacts();
        return array_merge($contacts, $remote);
    }

    public static function fetch_contacts(): array {
        $resp = self::request('GET', '/crm/v2/Contacts', [
            'fields' => 'First_Name,Last_Name,Email,Phone,Mailing_Street,Mailing_City,Mailing_Zip',
            'per_page' => 200,
        ]);
        if (!empty($resp['error'])) {
            self::log_error('fetch_contacts', $resp['error']);
            return [];
        }
        $results = [];
        foreach (($resp['data'] ?? []) as $row) {
            $results[] = [
                'first_name' => $row['First_Name'] ?? '',
                'last_name'  => $row['Last_Name'] ?? '',
                'email'      => $row['Email'] ?? '',
                'phone'      => $row['Phone'] ?? '',
                'address'    => $row['Mailing_Street'] ?? '',
                'city'       => $row['Mailing_City'] ?? '',
                'zip'        => $row['Mailing_Zip'] ?? '',
            ];
        }
        return $results;
    }

    public static function handle_customer_saved(int $customer_id, array $data, string $source = ''): void {
        if (!self::is_configured()) return;
        $contact_id = self::upsert_contact($customer_id, $data);
        if ($contact_id) {
            Logger::log('customer', $customer_id, 'zoho_synced', 'system', ['source' => $source, 'contact_id' => $contact_id]);
        }
    }

    public static function handle_invoice_paid(int $invoice_id, string $gateway, string $reference): void {
        if (!self::is_configured()) return;
        global $wpdb;
        $tblInv = $wpdb->prefix . 'arm_invoices';
        $tblCust= $wpdb->prefix . 'arm_customers';
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblInv WHERE id=%d", $invoice_id));
        if (!$invoice) return;
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblCust WHERE id=%d", (int) $invoice->customer_id));
        if (!$customer) return;
        $contact_id = self::upsert_contact((int) $customer->id, [
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
            'email'      => $customer->email,
            'phone'      => $customer->phone,
            'address'    => $customer->address,
            'city'       => $customer->city,
            'zip'        => $customer->zip,
        ]);
        $module = get_option('arm_zoho_module_deal', 'Deals') ?: 'Deals';
        $records = [[
            'Deal_Name'    => sprintf('Invoice %s', $invoice->invoice_no ?? $invoice_id),
            'Stage'        => 'Closed Won',
            'Amount'       => (float) $invoice->total,
            'Closing_Date' => date('Y-m-d'),
            'Description'  => sprintf('Paid via %s (%s)', $gateway, $reference),
        ]];
        if ($contact_id) {
            $records[0]['Contact_Name'] = ['id' => $contact_id];
        }
        self::request('POST', '/crm/v2/' . rawurlencode($module), ['data' => $records]);
    }

    public static function estimate_approved($estimate): void {
        if (!self::is_configured() || !$estimate || ($estimate->status ?? '') !== 'APPROVED') {
            return;
        }
        global $wpdb;
        $tblCust = $wpdb->prefix . 'arm_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblCust WHERE id=%d", (int) $estimate->customer_id));
        if ($customer) {
            self::upsert_contact((int) $customer->id, [
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name,
                'email'      => $customer->email,
                'phone'      => $customer->phone,
                'address'    => $customer->address,
                'city'       => $customer->city,
                'zip'        => $customer->zip,
            ]);
        }
    }

    private static function is_configured(): bool {
        return (bool) (get_option('arm_zoho_client_id') && get_option('arm_zoho_client_secret') && get_option('arm_zoho_refresh'));
    }

    private static function upsert_contact(int $customer_id, array $data): ?string {
        if (empty($data['email'])) return null;
        $record = [
            'First_Name'      => $data['first_name'] ?? '',
            'Last_Name'       => $data['last_name'] ?? '',
            'Email'           => $data['email'] ?? '',
            'Phone'           => $data['phone'] ?? '',
            'Mailing_Street'  => $data['address'] ?? '',
            'Mailing_City'    => $data['city'] ?? '',
            'Mailing_Zip'     => $data['zip'] ?? '',
        ];
        $response = self::request('POST', '/crm/v2/Contacts/upsert', [
            'data' => [
                array_merge($record, [
                    'External_Contact_ID' => $customer_id,
                ])
            ],
            'duplicate_check_fields' => ['Email'],
        ]);
        if (!empty($response['data'][0]['details']['id'])) {
            return (string) $response['data'][0]['details']['id'];
        }
        if (!empty($response['error'])) {
            self::log_error('upsert_contact', $response['error']);
        }
        return null;
    }

    private static function base_url(): string {
        $dc = trim((string) get_option('arm_zoho_dc', 'com')) ?: 'com';
        return sprintf('https://www.zohoapis.%s', $dc);
    }

    private static function access_token(): ?string {
        $cache = get_transient('arm_zoho_token');
        if ($cache) return $cache;
        $dc = trim((string) get_option('arm_zoho_dc', 'com')) ?: 'com';
        $refresh = trim((string) get_option('arm_zoho_refresh', ''));
        $client  = trim((string) get_option('arm_zoho_client_id', ''));
        $secret  = trim((string) get_option('arm_zoho_client_secret', ''));
        if (!$refresh || !$client || !$secret) return null;
        $resp = wp_remote_post(sprintf('https://accounts.zoho.%s/oauth/v2/token', $dc), [
            'timeout' => 15,
            'body' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id'     => $client,
                'client_secret' => $secret,
            ],
        ]);
        if (is_wp_error($resp)) {
            self::log_error('token_request', $resp->get_error_message());
            return null;
        }
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($json) || empty($json['access_token'])) {
            self::log_error('token_decode', $json);
            return null;
        }
        set_transient('arm_zoho_token', $json['access_token'], max(1, ((int) ($json['expires_in'] ?? 3600)) - 60));
        return (string) $json['access_token'];
    }

    private static function request(string $method, string $path, array $params = []): array {
        if (!self::is_configured()) {
            return ['error' => __('Zoho CRM not configured', 'arm-repair-estimates')];
        }
        $token = self::access_token();
        if (!$token) {
            return ['error' => __('Unable to authenticate with Zoho', 'arm-repair-estimates')];
        }
        $url = self::base_url() . $path;
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type'  => 'application/json',
            ],
        ];
        if ($method === 'GET') {
            $url = add_query_arg($params, $url);
        } else {
            $args['body'] = wp_json_encode($params);
        }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            self::log_error('request', $resp->get_error_message());
            return ['error' => $resp->get_error_message()];
        }
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($json)) {
            self::log_error('response', wp_remote_retrieve_body($resp));
            return ['error' => __('Invalid response from Zoho', 'arm-repair-estimates')];
        }
        return $json;
    }

    private static function log_error(string $code, $detail): void {
        set_transient('arm_re_notice_zoho', [
            'type' => 'error',
            'message' => sprintf(__('Zoho error (%s). See logs for details.', 'arm-repair-estimates'), $code),
        ], MINUTE_IN_SECONDS * 10);
        Logger::log('integration', 0, 'zoho_error', 'system', [
            'code'   => $code,
            'detail' => $detail,
        ]);
    }
}
