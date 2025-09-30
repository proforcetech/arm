<?php
// file: includes/appointments/Hooks_Make.php
namespace ARM\Appointments;

if (!defined('ABSPATH')) exit;

/**
 * Posts appointment events to Make (Integromat) webhooks if configured.
 */
final class Hooks_Make
{
    public static function boot(): void
    {
        add_action('arm/appt/created', [__CLASS__, 'on_created'], 10, 1);
    }

    public static function on_created(int $appointment_id): void
    {
        $hook = get_option('arm_make_calendar_webhook', '');
        if (!$hook) return;

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_appointments';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $appointment_id));
        if (!$row) return;

        wp_remote_post($hook, [
            'timeout' => 8,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'type'       => 'appointment.created',
                'id'         => (int) $appointment_id,
                'estimateId' => (int) $row->estimate_id,
                'customerId' => (int) $row->customer_id,
                'start'      => (string) $row->start_datetime,
                'end'        => (string) $row->end_datetime,
                'status'     => (string) $row->status,
                'site'       => home_url('/'),
            ]),
        ]);
    }
}
