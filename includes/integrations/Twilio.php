<?php
namespace ARM\Integrations;

if (!defined('ABSPATH')) exit;

class Twilio {
    public static function boot(): void {
        global $wpdb;
        if (!isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . 'arm_sms_messages';
        $schema = $wpdb->dbname ?? (defined('DB_NAME') ? DB_NAME : null);

        if (!$schema) {
            return;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                $schema,
                $table
            )
        );

        if ($exists !== $table) {
            self::install_tables();
        }
    }

    public static function install_tables(): void {
        global $wpdb;
        if (!function_exists('maybe_create_table')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table   = $wpdb->prefix . 'arm_sms_messages';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_sid VARCHAR(191) NULL,
            related_type VARCHAR(32) NULL,
            related_id BIGINT UNSIGNED NULL,
            direction ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
            to_number VARCHAR(32) NULL,
            from_number VARCHAR(32) NULL,
            body TEXT NULL,
            status VARCHAR(32) NULL,
            error_code VARCHAR(32) NULL,
            error_message TEXT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY message_sid (message_sid),
            KEY related (related_type, related_id),
            KEY created_at (created_at)
        ) $charset;";

        maybe_create_table($table, $sql);
    }
}
