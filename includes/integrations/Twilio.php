<?php
namespace ARM\Integrations;

use WP_REST_Request;
use WP_Error;

if (!defined('ABSPATH')) exit;

/**
 * Twilio integration responsible for sending SMS notifications, handling
 * inbound callbacks, and persisting message history for auditing/metrics.
 */
final class Twilio {
    private const OPTION_SID                 = 'arm_re_twilio_sid';
    private const OPTION_TOKEN               = 'arm_re_twilio_token';
    private const OPTION_FROM                = 'arm_re_twilio_from';
    private const OPTION_ENABLE_ESTIMATE     = 'arm_re_twilio_enable_estimate';
    private const OPTION_ENABLE_INVOICE      = 'arm_re_twilio_enable_invoice';
    private const OPTION_ENABLE_APPOINTMENT  = 'arm_re_twilio_enable_appointment';
    private const OPTION_TEMPLATE_ESTIMATE   = 'arm_re_twilio_template_estimate';
    private const OPTION_TEMPLATE_INVOICE    = 'arm_re_twilio_template_invoice';
    private const OPTION_TEMPLATE_APPOINTMENT= 'arm_re_twilio_template_appointment';
    private const OPTION_REMINDER_MINUTES    = 'arm_re_twilio_appt_lead_minutes';
    private const OPTION_OPT_OUT_NUMBERS     = 'arm_re_twilio_opt_out_numbers';

    private const TABLE                      = 'arm_sms_messages';
    private const RATE_LIMIT_PER_MINUTE      = 30;
    private const RATE_LIMIT_TRANSIENT       = 'arm_re_twilio_rate_counter';
    private const CRON_HOOK                  = 'arm_re_twilio_send_appt_reminder';

    private const DEFAULT_TEMPLATE_ESTIMATE = 'Hi {{customer_first_name}}, your estimate {{estimate_number}} is ready: {{estimate_link}}. Total: ${{estimate_total}}. Reply STOP to opt out.';
    private const DEFAULT_TEMPLATE_INVOICE  = 'Hi {{customer_first_name}}, invoice {{invoice_number}} total ${{invoice_total}} is available: {{invoice_link}}. Reply STOP to opt out.';
    private const DEFAULT_TEMPLATE_APPOINTMENT = 'Reminder: Appointment on {{appointment_date}} at {{appointment_time}}. Reply STOP to opt out.';

    public static function boot(): void {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action(self::CRON_HOOK, [__CLASS__, 'handle_appointment_reminder'], 10, 1);
    }

    public static function install_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::TABLE;

        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            direction ENUM('outbound','inbound') NOT NULL,
            channel VARCHAR(32) NOT NULL,
            related_id BIGINT UNSIGNED NULL,
            to_number VARCHAR(32) NULL,
            from_number VARCHAR(32) NULL,
            body TEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            provider_sid VARCHAR(64) NULL,
            error_code VARCHAR(32) NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY provider (provider_sid),
            KEY channel (channel),
            KEY created (created_at)
        ) $charset;");
    }

    public static function settings_fields(): void {
        register_setting('arm_re_settings', self::OPTION_SID, ['type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_sid']]);
        register_setting('arm_re_settings', self::OPTION_TOKEN, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('arm_re_settings', self::OPTION_FROM, ['type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_phone']]);
        register_setting('arm_re_settings', self::OPTION_ENABLE_ESTIMATE, ['type' => 'boolean', 'default' => false, 'sanitize_callback' => [__CLASS__, 'sanitize_bool']]);
        register_setting('arm_re_settings', self::OPTION_ENABLE_INVOICE, ['type' => 'boolean', 'default' => false, 'sanitize_callback' => [__CLASS__, 'sanitize_bool']]);
        register_setting('arm_re_settings', self::OPTION_ENABLE_APPOINTMENT, ['type' => 'boolean', 'default' => false, 'sanitize_callback' => [__CLASS__, 'sanitize_bool']]);
        register_setting('arm_re_settings', self::OPTION_TEMPLATE_ESTIMATE, ['type' => 'string', 'default' => self::DEFAULT_TEMPLATE_ESTIMATE, 'sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('arm_re_settings', self::OPTION_TEMPLATE_INVOICE, ['type' => 'string', 'default' => self::DEFAULT_TEMPLATE_INVOICE, 'sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('arm_re_settings', self::OPTION_TEMPLATE_APPOINTMENT, ['type' => 'string', 'default' => self::DEFAULT_TEMPLATE_APPOINTMENT, 'sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('arm_re_settings', self::OPTION_REMINDER_MINUTES, ['type' => 'integer', 'default' => 60, 'sanitize_callback' => [__CLASS__, 'sanitize_int']]);
        register_setting('arm_re_settings', self::OPTION_OPT_OUT_NUMBERS, ['type' => 'array', 'default' => []]);
    }

    public static function render_settings_section(): void {
        $sid        = esc_attr(get_option(self::OPTION_SID, ''));
        $token      = esc_attr(get_option(self::OPTION_TOKEN, ''));
        $from       = esc_attr(get_option(self::OPTION_FROM, ''));
        $enableEst  = (bool) get_option(self::OPTION_ENABLE_ESTIMATE, false);
        $enableInv  = (bool) get_option(self::OPTION_ENABLE_INVOICE, false);
        $enableAppt = (bool) get_option(self::OPTION_ENABLE_APPOINTMENT, false);
        $tmplEst    = esc_textarea(get_option(self::OPTION_TEMPLATE_ESTIMATE, self::DEFAULT_TEMPLATE_ESTIMATE));
        $tmplInv    = esc_textarea(get_option(self::OPTION_TEMPLATE_INVOICE, self::DEFAULT_TEMPLATE_INVOICE));
        $tmplAppt   = esc_textarea(get_option(self::OPTION_TEMPLATE_APPOINTMENT, self::DEFAULT_TEMPLATE_APPOINTMENT));
        $lead       = (int) get_option(self::OPTION_REMINDER_MINUTES, 60);
        $optOuts    = self::get_opt_out_numbers();
        ?>
        <h2><?php esc_html_e('Twilio SMS Notifications', 'arm-repair-estimates'); ?></h2>
        <p><?php esc_html_e('Configure Twilio credentials, templates, and opt-out numbers for SMS notifications.', 'arm-repair-estimates'); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="arm_re_twilio_sid"><?php esc_html_e('Account SID', 'arm-repair-estimates'); ?></label></th>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SID); ?>" id="arm_re_twilio_sid" value="<?php echo $sid; ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="arm_re_twilio_token"><?php esc_html_e('Auth Token', 'arm-repair-estimates'); ?></label></th>
                <td><input type="password" class="regular-text" name="<?php echo esc_attr(self::OPTION_TOKEN); ?>" id="arm_re_twilio_token" value="<?php echo $token; ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="arm_re_twilio_from"><?php esc_html_e('From Number', 'arm-repair-estimates'); ?></label></th>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_FROM); ?>" id="arm_re_twilio_from" value="<?php echo $from; ?>" placeholder="+15551234567"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Send For', 'arm-repair-estimates'); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_ENABLE_ESTIMATE); ?>" value="1" <?php checked($enableEst); ?>> <?php esc_html_e('Estimates', 'arm-repair-estimates'); ?></label><br>
                    <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_ENABLE_INVOICE); ?>" value="1" <?php checked($enableInv); ?>> <?php esc_html_e('Invoices', 'arm-repair-estimates'); ?></label><br>
                    <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_ENABLE_APPOINTMENT); ?>" value="1" <?php checked($enableAppt); ?>> <?php esc_html_e('Appointment Reminders', 'arm-repair-estimates'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="arm_re_twilio_template_estimate"><?php esc_html_e('Estimate Template', 'arm-repair-estimates'); ?></label></th>
                <td>
                    <textarea class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_TEMPLATE_ESTIMATE); ?>" id="arm_re_twilio_template_estimate"><?php echo $tmplEst; ?></textarea>
                    <p class="description"><?php esc_html_e('Available tags: {{customer_first_name}}, {{customer_last_name}}, {{estimate_number}}, {{estimate_total}}, {{estimate_link}}', 'arm-repair-estimates'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="arm_re_twilio_template_invoice"><?php esc_html_e('Invoice Template', 'arm-repair-estimates'); ?></label></th>
                <td>
                    <textarea class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_TEMPLATE_INVOICE); ?>" id="arm_re_twilio_template_invoice"><?php echo $tmplInv; ?></textarea>
                    <p class="description"><?php esc_html_e('Available tags: {{customer_first_name}}, {{customer_last_name}}, {{invoice_number}}, {{invoice_total}}, {{invoice_link}}', 'arm-repair-estimates'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="arm_re_twilio_template_appointment"><?php esc_html_e('Appointment Reminder Template', 'arm-repair-estimates'); ?></label></th>
                <td>
                    <textarea class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_TEMPLATE_APPOINTMENT); ?>" id="arm_re_twilio_template_appointment"><?php echo $tmplAppt; ?></textarea>
                    <p class="description"><?php esc_html_e('Available tags: {{customer_first_name}}, {{customer_last_name}}, {{appointment_date}}, {{appointment_time}}, {{appointment_link}}', 'arm-repair-estimates'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="arm_re_twilio_appt_lead_minutes"><?php esc_html_e('Reminder Lead Time (minutes)', 'arm-repair-estimates'); ?></label></th>
                <td><input type="number" class="small-text" min="0" name="<?php echo esc_attr(self::OPTION_REMINDER_MINUTES); ?>" id="arm_re_twilio_appt_lead_minutes" value="<?php echo esc_attr($lead); ?>"></td>
            </tr>
            <?php if (!empty($optOuts)): ?>
            <tr>
                <th scope="row"><?php esc_html_e('Opted-out Numbers', 'arm-repair-estimates'); ?></th>
                <td>
                    <ul>
                        <?php foreach ($optOuts as $num): ?>
                            <li><?php echo esc_html($num); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    public static function send_estimate_notification($estimate, $customer, string $link): void {
        if (!self::is_enabled_for('estimate')) {
            return;
        }
        if (!$customer || empty($customer->phone)) {
            return;
        }
        $message = self::render_template(
            get_option(self::OPTION_TEMPLATE_ESTIMATE, self::DEFAULT_TEMPLATE_ESTIMATE),
            [
                'customer_first_name' => $customer->first_name ?? '',
                'customer_last_name'  => $customer->last_name ?? '',
                'estimate_number'     => $estimate->estimate_no ?? '',
                'estimate_total'      => number_format((float) ($estimate->total ?? 0), 2),
                'estimate_link'       => $link,
            ]
        );
        self::send_message('estimate', $customer->phone, $message, [
            'estimate_id' => (int) ($estimate->id ?? 0),
            'customer_id' => (int) ($customer->id ?? 0),
        ]);
    }

    public static function send_invoice_notification($invoice, $customer, string $link): void {
        if (!self::is_enabled_for('invoice')) {
            return;
        }
        if (!$customer || empty($customer->phone)) {
            return;
        }
        $message = self::render_template(
            get_option(self::OPTION_TEMPLATE_INVOICE, self::DEFAULT_TEMPLATE_INVOICE),
            [
                'customer_first_name' => $customer->first_name ?? '',
                'customer_last_name'  => $customer->last_name ?? '',
                'invoice_number'      => $invoice->invoice_no ?? '',
                'invoice_total'       => number_format((float) ($invoice->total ?? 0), 2),
                'invoice_link'        => $link,
            ]
        );
        self::send_message('invoice', $customer->phone, $message, [
            'invoice_id'  => (int) ($invoice->id ?? 0),
            'customer_id' => (int) ($customer->id ?? 0),
        ]);
    }

    public static function schedule_appointment_reminder(int $appointment_id, string $start_datetime): void {
        if (!self::is_enabled_for('appointment')) {
            return;
        }
        $timestamp = strtotime($start_datetime);
        if (!$timestamp) {
            return;
        }
        $lead = (int) get_option(self::OPTION_REMINDER_MINUTES, 60);
        $send_at = $timestamp - ($lead * MINUTE_IN_SECONDS);
        if ($send_at <= time()) {
            // If in the past, fire immediately via cron.
            $send_at = time() + MINUTE_IN_SECONDS;
        }
        wp_clear_scheduled_hook(self::CRON_HOOK, [$appointment_id]);
        wp_schedule_single_event($send_at, self::CRON_HOOK, [$appointment_id]);
    }

    public static function handle_appointment_reminder(int $appointment_id): void {
        global $wpdb;
        $appt_table = $wpdb->prefix . 'arm_appointments';
        $cust_table = $wpdb->prefix . 'arm_customers';

        $appointment = $wpdb->get_row($wpdb->prepare("SELECT * FROM $appt_table WHERE id=%d", $appointment_id));
        if (!$appointment) {
            return;
        }
        if (!self::is_enabled_for('appointment')) {
            return;
        }
        if (strtoupper((string) $appointment->status) === 'CANCELLED') {
            return;
        }
        $customer = null;
        if (!empty($appointment->customer_id)) {
            $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cust_table WHERE id=%d", (int) $appointment->customer_id));
        }
        if (!$customer || empty($customer->phone)) {
            return;
        }
        $link = home_url('/');
        $start = strtotime($appointment->start_datetime ?? '');
        $message = self::render_template(
            get_option(self::OPTION_TEMPLATE_APPOINTMENT, self::DEFAULT_TEMPLATE_APPOINTMENT),
            [
                'customer_first_name' => $customer->first_name ?? '',
                'customer_last_name'  => $customer->last_name ?? '',
                'appointment_date'    => $start ? wp_date(get_option('date_format', 'M j, Y'), $start) : '',
                'appointment_time'    => $start ? wp_date(get_option('time_format', 'g:ia'), $start) : '',
                'appointment_link'    => $link,
            ]
        );
        self::send_message('appointment', $customer->phone, $message, [
            'appointment_id' => (int) $appointment_id,
            'customer_id'    => (int) ($appointment->customer_id ?? 0),
        ]);
    }

    public static function register_rest_routes(): void {
        register_rest_route('arm/v1', '/twilio/status', [
            'methods'  => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'rest_status_callback'],
        ]);
        register_rest_route('arm/v1', '/twilio/inbound', [
            'methods'  => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'rest_inbound_callback'],
        ]);
    }

    public static function rest_status_callback(WP_REST_Request $request) {
        $sid    = sanitize_text_field($request->get_param('MessageSid'));
        $status = sanitize_text_field($request->get_param('MessageStatus'));
        $error  = sanitize_text_field($request->get_param('ErrorCode'));
        if (!$sid || !$status) {
            return new WP_Error('bad_request', __('Missing MessageSid or MessageStatus', 'arm-repair-estimates'), ['status' => 400]);
        }
        self::update_message_status($sid, $status, $error ?: null);
        return ['ok' => true];
    }

    public static function rest_inbound_callback(WP_REST_Request $request) {
        $from = self::sanitize_phone($request->get_param('From'));
        $to   = self::sanitize_phone($request->get_param('To'));
        $body = sanitize_textarea_field($request->get_param('Body'));
        $sid  = sanitize_text_field($request->get_param('MessageSid'));
        if (!$from || !$body) {
            return new WP_Error('bad_request', __('Missing from or body', 'arm-repair-estimates'), ['status' => 400]);
        }
        $status = 'received';
        $meta   = [
            'provider' => 'twilio',
            'sid'      => $sid,
        ];
        self::log_message('inbound', 'inbound', $to, $from, $body, $status, $meta, $sid);

        if (in_array(strtoupper(trim($body)), ['STOP', 'CANCEL', 'UNSUBSCRIBE', 'QUIT'], true)) {
            self::add_opt_out($from);
        } elseif (in_array(strtoupper(trim($body)), ['START', 'UNSTOP'], true)) {
            self::remove_opt_out($from);
        }

        return ['ok' => true];
    }

    private static function send_message(string $channel, string $to_raw, string $body, array $context = []): void {
        $to   = self::sanitize_phone($to_raw);
        $from = self::sanitize_phone(get_option(self::OPTION_FROM, ''));
        if (!$to || !$from) {
            self::log_message('outbound', $channel, $to_raw, $from, $body, 'not_configured', $context);
            return;
        }
        if (!self::has_credentials()) {
            self::log_message('outbound', $channel, $to, $from, $body, 'not_configured', $context);
            return;
        }
        if (self::is_opted_out($to)) {
            $meta = array_merge($context, ['reason' => 'opted_out']);
            self::log_message('outbound', $channel, $to, $from, $body, 'skipped', $meta);
            return;
        }
        if (!self::check_rate_limit()) {
            $meta = array_merge($context, ['reason' => 'rate_limited']);
            self::log_message('outbound', $channel, $to, $from, $body, 'rate_limited', $meta);
            return;
        }
        $account = get_option(self::OPTION_SID, '');
        $token   = get_option(self::OPTION_TOKEN, '');
        $url     = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($account));
        $args    = [
            'timeout' => 15,
            'body'    => [
                'To'   => $to,
                'From' => $from,
                'Body' => $body,
            ],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($account . ':' . $token),
            ],
        ];
        $status_callback = rest_url('arm/v1/twilio/status');
        if ($status_callback) {
            $args['body']['StatusCallback'] = $status_callback;
        }

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            $meta = array_merge($context, ['error' => $response->get_error_message()]);
            self::log_message('outbound', $channel, $to, $from, $body, 'error', $meta);
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body_json = json_decode((string) wp_remote_retrieve_body($response));
        if ($code >= 200 && $code < 300 && isset($body_json->sid)) {
            $meta = array_merge($context, ['provider_response' => $body_json]);
            self::log_message('outbound', $channel, $to, $from, $body, (string) ($body_json->status ?? 'queued'), $meta, (string) $body_json->sid);
        } else {
            $err = is_object($body_json) && isset($body_json->message) ? (string) $body_json->message : 'HTTP ' . $code;
            $meta = array_merge($context, ['error' => $err]);
            self::log_message('outbound', $channel, $to, $from, $body, 'error', $meta, isset($body_json->sid) ? (string) $body_json->sid : null);
        }
    }

    private static function log_message(string $direction, string $channel, ?string $to, ?string $from, string $body, string $status, array $meta = [], ?string $provider_sid = null, ?string $error_code = null): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $related = null;
        foreach (['estimate_id', 'invoice_id', 'appointment_id'] as $key) {
            if (isset($meta[$key])) {
                $related = (int) $meta[$key];
                break;
            }
        }
        $wpdb->insert($table, [
            'direction'    => $direction,
            'channel'      => $channel,
            'related_id'   => $related,
            'to_number'    => $to,
            'from_number'  => $from,
            'body'         => $body,
            'status'       => $status,
            'provider_sid' => $provider_sid,
            'error_code'   => $error_code,
            'meta'         => !empty($meta) ? wp_json_encode($meta) : null,
            'created_at'   => current_time('mysql'),
        ], [
            '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'
        ]);
    }

    private static function update_message_status(string $sid, string $status, ?string $error_code = null): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->update($table, [
            'status'     => $status,
            'error_code' => $error_code,
            'updated_at' => current_time('mysql'),
        ], [
            'provider_sid' => $sid,
        ], [
            '%s','%s','%s'
        ], [
            '%s'
        ]);
    }

    private static function render_template(string $template, array $data): string {
        $replace = [];
        foreach ($data as $key => $value) {
            $replace['{{' . $key . '}}'] = $value;
        }
        return strtr($template, $replace);
    }

    private static function sanitize_sid($value): string {
        $value = is_string($value) ? trim($value) : '';
        return preg_match('/^AC[a-f0-9]{32}$/i', $value) ? $value : sanitize_text_field($value);
    }

    public static function sanitize_phone($value): string {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^\d\+]/', '', $value);
        if ($value && $value[0] !== '+') {
            // assume US country code if not provided
            if (strlen($value) === 10) {
                $value = '+1' . $value;
            } elseif (strlen($value) === 11 && $value[0] === '1') {
                $value = '+' . $value;
            }
        }
        return $value;
    }

    private static function sanitize_bool($value): bool {
        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function sanitize_int($value): int {
        return max(0, (int) $value);
    }

    private static function has_credentials(): bool {
        return (bool) (get_option(self::OPTION_SID) && get_option(self::OPTION_TOKEN));
    }

    private static function is_enabled_for(string $channel): bool {
        switch ($channel) {
            case 'estimate':
                return (bool) get_option(self::OPTION_ENABLE_ESTIMATE, false);
            case 'invoice':
                return (bool) get_option(self::OPTION_ENABLE_INVOICE, false);
            case 'appointment':
                return (bool) get_option(self::OPTION_ENABLE_APPOINTMENT, false);
            default:
                return false;
        }
    }

    private static function get_opt_out_numbers(): array {
        $nums = get_option(self::OPTION_OPT_OUT_NUMBERS, []);
        return is_array($nums) ? array_values(array_unique(array_filter(array_map([__CLASS__, 'sanitize_phone'], $nums)))) : [];
    }

    private static function is_opted_out(string $number): bool {
        $list = self::get_opt_out_numbers();
        return in_array(self::sanitize_phone($number), $list, true);
    }

    private static function add_opt_out(string $number): void {
        $number = self::sanitize_phone($number);
        if (!$number) {
            return;
        }
        $list = self::get_opt_out_numbers();
        if (!in_array($number, $list, true)) {
            $list[] = $number;
            update_option(self::OPTION_OPT_OUT_NUMBERS, $list);
        }
    }

    private static function remove_opt_out(string $number): void {
        $number = self::sanitize_phone($number);
        if (!$number) {
            return;
        }
        $list = self::get_opt_out_numbers();
        $new  = array_values(array_diff($list, [$number]));
        update_option(self::OPTION_OPT_OUT_NUMBERS, $new);
    }

    private static function check_rate_limit(): bool {
        $bucket = get_transient(self::RATE_LIMIT_TRANSIENT);
        if (!is_array($bucket)) {
            $bucket = ['count' => 0, 'expires' => time() + MINUTE_IN_SECONDS];
        }
        if ($bucket['expires'] < time()) {
            $bucket = ['count' => 0, 'expires' => time() + MINUTE_IN_SECONDS];
        }
        if ($bucket['count'] >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }
        $bucket['count']++;
        $ttl = max(1, $bucket['expires'] - time());
        set_transient(self::RATE_LIMIT_TRANSIENT, $bucket, $ttl);
        return true;
    }
}
