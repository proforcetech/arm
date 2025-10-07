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

    public static function maybe_upgrade_legacy_schema(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'arm_appointments';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return;
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        $column_map = [];
        foreach ($columns as $column) {
            $column_map[$column['Field']] = $column;
        }

        if (isset($column_map['start']) && !isset($column_map['start_datetime'])) {
            $wpdb->query("ALTER TABLE $table CHANGE COLUMN `start` `start_datetime` DATETIME NOT NULL");
        }

        if (isset($column_map['end']) && !isset($column_map['end_datetime'])) {
            $wpdb->query("ALTER TABLE $table CHANGE COLUMN `end` `end_datetime` DATETIME NOT NULL");
        }

        $indexes     = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        $index_names = [];
        foreach ($indexes as $index) {
            $index_names[$index['Key_name']] = true;
        }

        if (isset($index_names['start'])) {
            $wpdb->query("ALTER TABLE $table DROP INDEX `start`");
            unset($index_names['start']);
        }

        if (!isset($index_names['idx_start']) && isset($column_map['start_datetime'])) {
            $wpdb->query("ALTER TABLE $table ADD KEY `idx_start` (`start_datetime`)");
        }

        if (!isset($index_names['idx_status']) && isset($column_map['status'])) {
            $wpdb->query("ALTER TABLE $table ADD KEY `idx_status` (`status`)");
        }
    }
}
