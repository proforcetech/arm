<?php
// file: includes/integrations/Appointments_Make.php
namespace ARM\Integrations;

if (!defined('ABSPATH')) exit;

/**
 * Sends appointment lifecycle events to Make (Integromat).
 * Why: match arm-appointments-make.json router on "type".
 */
final class Appointments_Make
{
    public static function boot(): void
    {
        add_action('arm/appointment/booked',   [__CLASS__, 'on_booked'],   10, 4);
        add_action('arm/appointment/updated',  [__CLASS__, 'on_updated'],  10, 3);
        add_action('arm/appointment/canceled', [__CLASS__, 'on_canceled'], 10, 2);
    }

    public static function on_booked(int $appt_id, int $estimate_id, string $start, string $end): void
    {
        self::send('appointment.created', $appt_id, $estimate_id, $start, $end);
    }

    public static function on_updated(int $appt_id, string $start, string $end): void
    {
        // Look up estimate_id
        global $wpdb;
        $estimate_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT estimate_id FROM {$wpdb->prefix}arm_appointments WHERE id=%d", $appt_id
        ));
        self::send('appointment.updated', $appt_id, $estimate_id, $start, $end);
    }

    public static function on_canceled(int $appt_id, int $estimate_id): void
    {
        self::send('appointment.canceled', $appt_id, $estimate_id, '', '');
    }

    /** Compose payload to match the Make scenario. */
    private static function send(string $type, int $appt_id, int $estimate_id, string $start, string $end): void
    {
        global $wpdb;
        $pfx = $wpdb->prefix;

        // Join appointment + estimate + customer (fallback to estimate's contact fields)
        $appt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}arm_appointments WHERE id=%d", $appt_id));
        $est  = $estimate_id > 0
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}arm_estimates WHERE id=%d", $estimate_id))
            : null;

        $customer = null;
        if ($est && isset($est->customer_id) && (int) $est->customer_id > 0) {
            $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}arm_customers WHERE id=%d", (int) $est->customer_id));
        }

        $cust_name = trim(
            (string) ($customer->first_name ?? $est->first_name ?? '') . ' ' .
            (string) ($customer->last_name  ?? $est->last_name  ?? '')
        );

        $payload = [
            'appointment' => [
                'id'          => (int) ($appt->id ?? $appt_id),
                'estimate_id' => (int) $estimate_id,
                'start_time'  => $start ?: (string) ($appt->start_datetime ?? ''),
                'end_time'    => $end   ?: (string) ($appt->end_datetime   ?? ''),
                'status'      => (string) ($appt->status ?? ($type === 'appointment.canceled' ? 'CANCELED' : 'BOOKED')),
                'notes'       => (string) ($est->notes ?? ''),
            ],
            'customer' => [
                'id'      => (int) ($customer->id ?? 0),
                'name'    => $cust_name,
                'email'   => (string) ($customer->email ?? $est->email ?? ''),
                'phone'   => (string) ($customer->phone ?? $est->phone ?? ''),
                'address' => (string) ($customer->address ?? ''),
            ],
        ];

        // Prefer dedicated Appointment webhook option; fallback to default
        $url = get_option(Make_Webhooks::OPT_CAL_HOOK, '') ?: get_option(Make_Webhooks::OPT_DEFAULT, '');
        Make_Webhooks::send($type, $payload, $url);
    }
}
