<?php
namespace ARM\Appointments;

if (!defined('ABSPATH')) exit;

final class Installer
{
    public static function install_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset      = $wpdb->get_charset_collate();
        $appointments = $wpdb->prefix . 'arm_appointments';
        $availability = $wpdb->prefix . 'arm_availability';

        dbDelta("CREATE TABLE $appointments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NULL,
            estimate_id BIGINT UNSIGNED NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_customer (customer_id),
            KEY idx_estimate (estimate_id),
            KEY idx_start (start_datetime),
            KEY idx_status (status)
        ) $charset;");

        dbDelta("CREATE TABLE $availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type ENUM('hours','holiday') NOT NULL,
            day_of_week TINYINT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            date DATE NULL,
            label VARCHAR(128) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_type (type),
            KEY idx_day (day_of_week),
            KEY idx_date (date)
        ) $charset;");
    }
}
