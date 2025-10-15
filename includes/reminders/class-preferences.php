<?php
namespace ARM\Reminders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD helpers for customer reminder preferences.
 */
final class Preferences
{
    /**
     * Fetch the preference row for a given customer id.
     */
    public static function get_for_customer(int $customer_id): ?object
    {
        if ($customer_id <= 0) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_preferences';

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE customer_id=%d", $customer_id)
        ) ?: null;
    }

    /**
     * Fetch the preference row for a given email address.
     */
    public static function get_for_email(string $email): ?object
    {
        $email = sanitize_email($email);
        if ($email === '') {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_preferences';

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE email=%s", $email)
        ) ?: null;
    }

    /**
     * Create or update a preference row based on the provided data.
     */
    public static function upsert(array $data): ?object
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_preferences';

        $normalized = self::normalize($data);

        $columns = [
            'customer_id'       => $normalized['customer_id'],
            'email'             => $normalized['email'],
            'phone'             => $normalized['phone'],
            'timezone'          => $normalized['timezone'],
            'preferred_channel' => $normalized['preferred_channel'],
            'lead_days'         => $normalized['lead_days'],
            'preferred_hour'    => $normalized['preferred_hour'],
            'is_active'         => $normalized['is_active'],
            'source'            => $normalized['source'],
        ];

        $fields       = [];
        $placeholders = [];
        $values       = [];
        foreach ($columns as $column => $value) {
            $fields[] = $column;
            if ($value === null || $value === '') {
                $placeholders[] = 'NULL';
            } else {
                if (is_int($value)) {
                    $placeholders[] = '%d';
                } else {
                    $placeholders[] = '%s';
                }
                $values[] = $value;
            }
        }

        $now = current_time('mysql');
        $values[] = $now;
        $values[] = $now;

        $sql = "INSERT INTO $table (" . implode(',', $fields) . ", created_at, updated_at)
                VALUES (" . implode(',', $placeholders) . ", %s, %s)
                ON DUPLICATE KEY UPDATE
                    phone = VALUES(phone),
                    timezone = VALUES(timezone),
                    preferred_channel = VALUES(preferred_channel),
                    lead_days = VALUES(lead_days),
                    preferred_hour = VALUES(preferred_hour),
                    is_active = VALUES(is_active),
                    source = VALUES(source),
                    updated_at = VALUES(updated_at)";

        $prepared = $wpdb->prepare($sql, $values);
        if ($prepared === null) {
            return null;
        }

        $wpdb->query($prepared);

        if ($normalized['customer_id']) {
            return self::get_for_customer((int) $normalized['customer_id']);
        }

        if ($normalized['email']) {
            return self::get_for_email($normalized['email']);
        }

        return null;
    }

    /**
     * Convenience wrapper that accepts either a customer id or contact info.
     */
    public static function upsert_for_contact(array $data): ?object
    {
        return self::upsert($data);
    }

    /**
     * Normalize arbitrary data into the column types we expect.
     */
    private static function normalize(array $data): array
    {
        $customer_id = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        $email       = isset($data['email']) ? sanitize_email($data['email']) : '';
        $phone       = isset($data['phone']) ? sanitize_text_field($data['phone']) : '';

        $channel = isset($data['preferred_channel']) ? sanitize_key($data['preferred_channel']) : 'email';
        if (!in_array($channel, ['none', 'email', 'sms', 'both'], true)) {
            $channel = 'email';
        }

        $lead_days = isset($data['lead_days']) ? (int) $data['lead_days'] : 3;
        if ($lead_days < 0) {
            $lead_days = 0;
        }

        $preferred_hour = isset($data['preferred_hour']) ? (int) $data['preferred_hour'] : 9;
        if ($preferred_hour < 0) {
            $preferred_hour = 0;
        }
        if ($preferred_hour > 23) {
            $preferred_hour = 23;
        }

        $tz = isset($data['timezone']) ? sanitize_text_field($data['timezone']) : '';
        if ($tz === '') {
            $tz = wp_timezone_string();
        }

        $active = isset($data['is_active']) ? (int) $data['is_active'] : 1;
        if ($channel === 'none') {
            $active = 0;
        }

        $source = isset($data['source']) ? sanitize_text_field($data['source']) : '';

        return [
            'customer_id'       => $customer_id > 0 ? $customer_id : null,
            'email'             => $email !== '' ? $email : null,
            'phone'             => $phone !== '' ? $phone : null,
            'timezone'          => $tz,
            'preferred_channel' => $channel,
            'lead_days'         => $lead_days,
            'preferred_hour'    => $preferred_hour,
            'is_active'         => $active ? 1 : 0,
            'source'            => $source !== '' ? $source : null,
        ];
    }
}
