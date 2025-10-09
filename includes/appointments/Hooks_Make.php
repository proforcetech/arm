<?php
namespace ARM\Appointments;

if (!defined('ABSPATH')) exit;

final class Hooks_Make
{
    public static function boot(): void
    {
        add_action('arm/appt/created', [__CLASS__, 'on_created'], 10, 1); 
        add_action('arm/appointment/booked', [__CLASS__, 'on_created'], 10, 4);
    }

    public static function on_created(int $appointment_id, int $estimate_id = 0, string $start = '', string $end = ''): void
    {
        $hook = get_option('arm_make_calendar_webhook', '');
        if (!$hook) return;

        global $wpdb;
        $table = $wpdb->prefix . 'arm_appointments';
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $appointment_id));
        if (!$row) return;

        if (!$start) $start = (string) ($row->start_datetime ?? '');
        if (!$end)   $end   = (string) ($row->end_datetime   ?? '');
        if (!$estimate_id) {
            $estimate_id = (int) ($row->estimate_id ?? 0);
        }

        wp_remote_post($hook, [
            'timeout' => 8,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'type'       => 'appointment.created',
                'id'         => (int) $appointment_id,
                'estimateId' => (int) $estimate_id,
                'customerId' => (int) ($row->customer_id ?? 0),
                'start'      => $start,
                'end'        => $end,
                'status'     => (string) ($row->status ?? ''),
                'site'       => home_url('/'),
            ]),
        ]);
    }
}
