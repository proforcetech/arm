<?php

namespace ARM\Integrations;

if (!defined('ABSPATH')) exit;

/**
 * Canonical Make (Integromat) webhook integration.
 * Why: single class with options + helpers to send/export data.
 */
final class Make_Webhooks
{
    public const OPT_CAL_HOOK   = 'arm_make_calendar_webhook';
    public const OPT_EMAIL_HOOK = 'arm_make_email_webhook';
    public const OPT_SMS_HOOK   = 'arm_make_sms_webhook';
    public const OPT_DEFAULT    = 'arm_make_webhook_url';

    public static function boot(): void
    {
        
    }

    /** Fire a webhook to the configured URL. */
    public static function send(string $type, array $data, ?string $url = null): bool
    {
        $hook = $url ?? get_option(self::OPT_DEFAULT, '');
        if (!$hook) return false;

        $body = [
            'type' => $type,
            'site' => home_url('/'),
            'data' => $data,
        ];
        $resp = wp_remote_post($hook, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
        return !is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) < 400;
    }

    /** ---- Bulk exports (schema-tolerant) ---- */

    public static function export_customers(int $limit = 0, int $offset = 0): bool
    {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}arm_customers";
        if ($limit > 0) $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return false;
        return self::send('export.customers', ['customers' => $rows]);
    }

    public static function export_invoices(int $limit = 0, int $offset = 0): bool
    {
        global $wpdb;
        $sql = "
            SELECT i.*, c.first_name, c.last_name, c.email, c.phone
            FROM {$wpdb->prefix}arm_invoices i
            LEFT JOIN {$wpdb->prefix}arm_customers c ON c.id = i.customer_id";
        if ($limit > 0) $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return false;
        return self::send('export.invoices', ['invoices' => $rows]);
    }

    public static function export_estimates(int $limit = 0, int $offset = 0): bool
    {
        global $wpdb;
        $sql = "
            SELECT e.*, c.first_name, c.last_name, c.email, c.phone
            FROM {$wpdb->prefix}arm_estimates e
            LEFT JOIN {$wpdb->prefix}arm_customers c ON c.id = e.customer_id";
        if ($limit > 0) $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return false;
        return self::send('export.estimates', ['estimates' => $rows]);
    }

    public static function export_appointments(): bool
    {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT a.*, c.first_name, c.last_name, c.email, c.phone
            FROM {$wpdb->prefix}arm_appointments a
            LEFT JOIN {$wpdb->prefix}arm_customers c ON c.id = a.customer_id
        ", ARRAY_A);
        if (!$rows) return false;
        return self::send('export.appointments', ['appointments' => $rows]);
    }
}


if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
    \WP_CLI::add_command('arm:export', function($args, $assoc){
        $type   = $args[0] ?? '';
        $limit  = isset($assoc['limit'])  ? (int) $assoc['limit']  : 0;
        $offset = isset($assoc['offset']) ? (int) $assoc['offset'] : 0;
        $ok = false;
        switch ($type) {
            case 'customers':    $ok = Make_Webhooks::export_customers($limit, $offset); break;
            case 'invoices':     $ok = Make_Webhooks::export_invoices($limit, $offset);  break;
            case 'estimates':    $ok = Make_Webhooks::export_estimates($limit, $offset); break;
            case 'appointments': $ok = Make_Webhooks::export_appointments();             break;
            default: \WP_CLI::error('Valid types: customers | invoices | estimates | appointments');
        }
        if ($ok) \WP_CLI::success('Export queued to webhook.'); else \WP_CLI::warning('No data found or webhook failed.');
    });
}
