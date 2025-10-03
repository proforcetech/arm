<?php
namespace ARM\Admin;

use wpdb;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/Inventory.php';

/**
 * Collection of helper queries that power the admin dashboard KPIs.
 * Each method defends against missing tables and normalises return structures
 * so they can be asserted in isolation during unit tests.
 */
final class DashboardMetrics
{
    private const SMS_TABLE_CANDIDATES = [
        'arm_sms_logs',
        'arm_sms_log',
        'arm_twilio_logs',
        'arm_twilio_log',
        'arm_message_log',
    ];

    public static function estimate_counts(wpdb $wpdb): array
    {
        $table = $wpdb->prefix . 'arm_estimates';
        if (!self::table_exists($wpdb, $table)) {
            return [
                'exists'   => false,
                'pending'  => 0,
                'approved' => 0,
                'rejected' => 0,
            ];
        }

        return [
            'exists'   => true,
            'pending'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='PENDING'"),
            'approved' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='APPROVED'"),
            'rejected' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='REJECTED'"),
        ];
    }

    public static function invoice_counts(wpdb $wpdb): array
    {
        $table = $wpdb->prefix . 'arm_invoices';
        if (!self::table_exists($wpdb, $table)) {
            return [
                'exists'      => false,
                'total'       => 0,
                'paid'        => 0,
                'unpaid'      => 0,
                'void'        => 0,
                'avg_paid'    => 0.0,
                'sum_paid'    => 0.0,
                'sum_unpaid'  => 0.0,
                'sum_tax'     => 0.0,
            ];
        }

        return [
            'exists'      => true,
            'total'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'paid'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='PAID'"),
            'unpaid'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='UNPAID'"),
            'void'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='VOID'"),
            'avg_paid'    => (float) $wpdb->get_var("SELECT AVG(total) FROM $table WHERE status='PAID'"),
            'sum_paid'    => (float) $wpdb->get_var("SELECT SUM(total) FROM $table WHERE status='PAID'"),
            'sum_unpaid'  => (float) $wpdb->get_var("SELECT SUM(total) FROM $table WHERE status='UNPAID'"),
            'sum_tax'     => (float) $wpdb->get_var("SELECT SUM(tax_amount) FROM $table WHERE status='PAID'"),
        ];
    }

    public static function invoice_monthly_totals(wpdb $wpdb, int $months = 6): array
    {
        $table = $wpdb->prefix . 'arm_invoices';
        if (!self::table_exists($wpdb, $table)) {
            return [
                'labels' => [],
                'totals' => [],
            ];
        }

        $sql = $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at,'%%Y-%%m') AS ym, SUM(total) AS total
             FROM $table WHERE status='PAID'
             GROUP BY ym ORDER BY ym DESC LIMIT %d",
            max(1, $months)
        );

        $rows   = $wpdb->get_results($sql);
        $labels = [];
        $totals = [];
        foreach (array_reverse($rows ?: []) as $row) {
            $labels[] = (string) $row->ym;
            $totals[] = (float) $row->total;
        }

        return compact('labels', 'totals');
    }

    public static function estimate_trends(wpdb $wpdb, int $months = 6): array
    {
        $table = $wpdb->prefix . 'arm_estimates';
        if (!self::table_exists($wpdb, $table)) {
            return [
                'labels'   => [],
                'approved' => [],
                'rejected' => [],
            ];
        }

        $sql = $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at,'%%Y-%%m') AS ym,
                    SUM(CASE WHEN status='APPROVED' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status='REJECTED' THEN 1 ELSE 0 END) AS rejected
             FROM $table GROUP BY ym ORDER BY ym DESC LIMIT %d",
            max(1, $months)
        );

        $rows     = $wpdb->get_results($sql);
        $labels   = [];
        $approved = [];
        $rejected = [];
        foreach (array_reverse($rows ?: []) as $row) {
            $labels[]   = (string) $row->ym;
            $approved[] = (int) $row->approved;
            $rejected[] = (int) $row->rejected;
        }

        return compact('labels', 'approved', 'rejected');
    }

    public static function inventory_value(wpdb $wpdb): array
    {
        $table = $wpdb->prefix . 'arm_inventory';
        if (!self::table_exists($wpdb, $table)) {
            return [
                'exists' => false,
                'value'  => 0.0,
            ];
        }

        $cols = Inventory::schema_columns($table);
        $qty  = $cols['qty'] ?? 'qty_on_hand';
        $price = $cols['price'] ?? 'price';

        $sql = "SELECT SUM(COALESCE($qty,0) * COALESCE($price,0)) FROM $table";
        $value = (float) $wpdb->get_var($sql);

        return [
            'exists' => true,
            'value'  => $value,
        ];
    }

    public static function warranty_claim_counts(wpdb $wpdb): array
    {
        $table = $wpdb->prefix . 'arm_warranty_claims';
        if (!self::table_exists($wpdb, $table)) {
            return [
                'exists'  => false,
                'open'    => 0,
                'resolved'=> 0,
            ];
        }

        $sql = "SELECT
                    SUM(CASE WHEN UPPER(status) IN ('RESOLVED','CLOSED') THEN 1 ELSE 0 END) AS resolved,
                    SUM(CASE WHEN UPPER(status) IN ('RESOLVED','CLOSED') THEN 0 ELSE 1 END) AS open
                FROM $table";
        $row = $wpdb->get_row($sql);

        return [
            'exists'   => true,
            'open'     => (int) ($row->open ?? 0),
            'resolved' => (int) ($row->resolved ?? 0),
        ];
    }

    public static function sms_totals(wpdb $wpdb): array
    {
        $table = self::resolve_sms_table($wpdb);
        if (!$table) {
            return [
                'exists'   => false,
                'channels' => [],
            ];
        }

        $columns = self::column_map($wpdb, $table, [
            'status'  => ['status', 'delivery_status', 'state'],
            'channel' => ['channel', 'context', 'category', 'hook'],
        ]);

        if (empty($columns['status'])) {
            return [
                'exists'   => true,
                'channels' => [],
            ];
        }

        $statusCol  = $columns['status'];
        $channelCol = $columns['channel'];

        if ($channelCol) {
            $sql = "SELECT COALESCE($channelCol, 'unknown') AS channel,
                           SUM(CASE WHEN UPPER($statusCol)='SENT' THEN 1 ELSE 0 END) AS sent,
                           SUM(CASE WHEN UPPER($statusCol)='DELIVERED' THEN 1 ELSE 0 END) AS delivered,
                           SUM(CASE WHEN UPPER($statusCol)='FAILED' THEN 1 ELSE 0 END) AS failed
                    FROM $table GROUP BY channel ORDER BY channel";
            $rows = $wpdb->get_results($sql);
            $channels = [];
            foreach ($rows ?: [] as $row) {
                $label = (string) $row->channel;
                $channels[$label] = [
                    'sent'      => (int) $row->sent,
                    'delivered' => (int) $row->delivered,
                    'failed'    => (int) $row->failed,
                ];
            }
        } else {
            $sql = "SELECT
                        SUM(CASE WHEN UPPER($statusCol)='SENT' THEN 1 ELSE 0 END) AS sent,
                        SUM(CASE WHEN UPPER($statusCol)='DELIVERED' THEN 1 ELSE 0 END) AS delivered,
                        SUM(CASE WHEN UPPER($statusCol)='FAILED' THEN 1 ELSE 0 END) AS failed
                    FROM $table";
            $row = $wpdb->get_row($sql);
            $channels = [
                __('All Channels', 'arm-repair-estimates') => [
                    'sent'      => (int) ($row->sent ?? 0),
                    'delivered' => (int) ($row->delivered ?? 0),
                    'failed'    => (int) ($row->failed ?? 0),
                ],
            ];
        }

        return [
            'exists'   => true,
            'channels' => $channels,
        ];
    }

    private static function table_exists(wpdb $wpdb, string $table): bool
    {
        $like = $wpdb->esc_like($table);
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    }

    private static function resolve_sms_table(wpdb $wpdb): ?string
    {
        foreach (self::SMS_TABLE_CANDIDATES as $candidate) {
            $table = $wpdb->prefix . $candidate;
            if (self::table_exists($wpdb, $table)) {
                return $table;
            }
        }
        return null;
    }

    private static function column_map(wpdb $wpdb, string $table, array $map): array
    {
        $cols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        ) ?: [];
        $lookup = array_change_key_case(array_flip($cols), CASE_LOWER);
        $picked = [];
        foreach ($map as $key => $candidates) {
            $picked[$key] = null;
            foreach ($candidates as $candidate) {
                $normalized = strtolower($candidate);
                if (isset($lookup[$normalized])) {
                    $picked[$key] = $candidate;
                    break;
                }
            }
        }
        return $picked;
    }
}
