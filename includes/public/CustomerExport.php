<?php

namespace ARM\Public;

if (!defined('ABSPATH')) exit;

/**
 * Customer data export (GDPR-ish) for logged-in users.
 * Exports their estimates & invoices in CSV.
 */
final class CustomerExport
{
    public static function boot(): void
    {
        add_action('admin_post_arm_customer_export', [__CLASS__, 'export']);          
        add_action('admin_post_nopriv_arm_customer_export', [__CLASS__, 'deny']);    
    }

    public static function export(): void
    {
        if (!is_user_logged_in()) {
            self::deny();
        }

        $user = wp_get_current_user();
        $customer = Customer_Dashboard::resolve_customer_for_user($user, false);

        global $wpdb;
        if ($customer) {
            $est = $wpdb->get_results($wpdb->prepare(
                "SELECT id,status,total,created_at FROM {$wpdb->prefix}arm_estimates WHERE customer_id=%d ORDER BY created_at DESC",
                (int) $customer->id
            ), ARRAY_A) ?: [];
            $inv = $wpdb->get_results($wpdb->prepare(
                "SELECT id,status,total,created_at FROM {$wpdb->prefix}arm_invoices WHERE customer_id=%d ORDER BY created_at DESC",
                (int) $customer->id
            ), ARRAY_A) ?: [];
        } else {
            $email = sanitize_email($user->user_email);
            if (!$email) {
                self::deny();
            }
            $est = $wpdb->get_results($wpdb->prepare(
                "SELECT id,status,total,created_at FROM {$wpdb->prefix}arm_estimates WHERE email=%s ORDER BY created_at DESC",
                $email
            ), ARRAY_A) ?: [];
            $inv = $wpdb->get_results($wpdb->prepare(
                "SELECT id,status,total,created_at FROM {$wpdb->prefix}arm_invoices WHERE email=%s ORDER BY created_at DESC",
                $email
            ), ARRAY_A) ?: [];
        }

        $rows = [['type','id','status','total','created_at']];
        foreach ($est as $r) $rows[] = ['estimate', $r['id'], $r['status'], $r['total'], $r['created_at']];
        foreach ($inv as $r) $rows[] = ['invoice',  $r['id'], $r['status'], $r['total'], $r['created_at']];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-data.csv"');

        $out = fopen('php://output', 'w');
        foreach ($rows as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }

    public static function deny(): void
    {
        wp_die(__('You must be logged in to export your data.', 'arm-repair-estimates'));
    }
}
