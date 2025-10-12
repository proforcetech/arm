<?php

namespace ARM\Setup;

if (!defined('ABSPATH')) exit;

class SchemaFix {
    public static function run(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $estimates_table = $wpdb->prefix . 'arm_estimates';
        self::addColumn($estimates_table, 'vehicle_id', 'BIGINT UNSIGNED NULL');
        self::addColumn($estimates_table, 'vehicle_year', 'SMALLINT UNSIGNED NULL');
        self::addColumn($estimates_table, 'vehicle_make', 'VARCHAR(80) NULL');
        self::addColumn($estimates_table, 'vehicle_model', 'VARCHAR(120) NULL');
        self::addColumn($estimates_table, 'vehicle_engine', 'VARCHAR(120) NULL');
        self::addColumn($estimates_table, 'vehicle_transmission', 'VARCHAR(80) NULL');
        self::addColumn($estimates_table, 'vehicle_drive', 'VARCHAR(32) NULL');
        self::addColumn($estimates_table, 'vehicle_trim', 'VARCHAR(120) NULL');

        self::ensurePrimaryKey($wpdb->prefix . 'arm_customers', 'id');
        self::ensurePrimaryKey($wpdb->prefix . 'arm_appointments', 'id');

        
        self::modifyColumn(
            $wpdb->prefix . 'arm_availability',
            'day_of_week',
            "TINYINT NULL COMMENT '0=Sunday,6=Saturday (for hours)'"
        );
        self::modifyColumn(
            $wpdb->prefix . 'arm_availability',
            'date',
            "DATE NULL COMMENT 'for holiday single day'"
        );

        
        self::addIndex($estimates_table, 'idx_arm_estimates_customer_id', ['customer_id']);
        self::addIndex($estimates_table, 'idx_arm_estimates_request_id', ['request_id']);
        self::addIndex($estimates_table, 'idx_arm_estimates_vehicle_id', ['vehicle_id']);

        self::addIndex($wpdb->prefix . 'arm_estimate_jobs', 'idx_arm_estimate_jobs_estimate_id', ['estimate_id']);

        self::addIndex($wpdb->prefix . 'arm_estimate_items', 'idx_arm_estimate_items_estimate_id', ['estimate_id']);
        self::addIndex($wpdb->prefix . 'arm_estimate_items', 'idx_arm_estimate_items_job_id', ['job_id']);

        self::addIndex($wpdb->prefix . 'arm_invoices', 'idx_arm_invoices_customer_id', ['customer_id']);
        self::addIndex($wpdb->prefix . 'arm_invoices', 'idx_arm_invoices_estimate_id', ['estimate_id']);

        self::addIndex($wpdb->prefix . 'arm_invoice_items', 'idx_arm_invoice_items_invoice_id', ['invoice_id']);

        self::addIndex($wpdb->prefix . 'arm_service_bundles', 'idx_arm_service_bundles_service_type_id', ['service_type_id']);
        self::addIndex($wpdb->prefix . 'arm_service_bundles', 'idx_arm_service_bundles_is_active', ['is_active']);

        self::addIndex($wpdb->prefix . 'arm_service_bundle_items', 'idx_arm_service_bundle_items_bundle_id', ['bundle_id']);
    }

    private static function ensurePrimaryKey(string $table, string $column): void {
        global $wpdb;
        $hasPk = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_TYPE='PRIMARY KEY'
                   AND TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME=%s
                 LIMIT 1",
                 $table
            )
        );
        if (!$hasPk) {
            
            $wpdb->query("ALTER TABLE `$table` ADD PRIMARY KEY (`$column`)");
        }
    }

    private static function modifyColumn(string $table, string $column, string $definition): void {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME=%s
                   AND COLUMN_NAME=%s
                 LIMIT 1",
                $table, $column
            )
        );
        if ($exists) {
            $wpdb->query("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
        }
    }

    private static function addColumn(string $table, string $column, string $definition): void {
        global $wpdb;

        $column = sanitize_key($column);
        $definition = trim($definition);
        $table_clean = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        if ($column === '' || $definition === '' || $table_clean === '') {
            return;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME=%s
                   AND COLUMN_NAME=%s
                 LIMIT 1",
                $table_clean,
                $column
            )
        );
        if ($exists) {
            return;
        }

        $wpdb->query("ALTER TABLE `$table_clean` ADD COLUMN `$column` $definition");
    }

    private static function addIndex(string $table, string $indexName, array $columns, string $type = 'INDEX'): void {
        global $wpdb;
        if (empty($columns)) return;


        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME=%s
                   AND INDEX_NAME=%s
                 LIMIT 1",
                $table, $indexName
            )
        );
        if ($exists) return;

        
        $cols = implode('`,`', array_map('sanitize_key', $columns));
        $sql  = "ALTER TABLE `$table` ADD $type `$indexName` (`$cols`)";
        $wpdb->query($sql);
    }
}
