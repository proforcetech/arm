<?php
// file: includes/setup/SchemaFix.php
namespace ARM\Setup;

if (!defined('ABSPATH')) exit;

class SchemaFix {
    public static function run(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1) Never try to re-add PKs via CHANGE COLUMN.
        //    If you truly need to add a PK, only do it if missing.
        self::ensurePrimaryKey($wpdb->prefix . 'arm_customers', 'id');
        self::ensurePrimaryKey($wpdb->prefix . 'arm_appointments', 'id');

        // Customer profile extensions
        $custTable = $wpdb->prefix . 'arm_customers';
        self::ensureColumn($custTable, 'business_name', "VARCHAR(200) NULL AFTER phone");
        self::ensureColumn($custTable, 'tax_id', "VARCHAR(100) NULL AFTER business_name");
        self::ensureColumn($custTable, 'billing_address1', "VARCHAR(200) NULL AFTER zip");
        self::ensureColumn($custTable, 'billing_address2', "VARCHAR(200) NULL AFTER billing_address1");
        self::ensureColumn($custTable, 'billing_city', "VARCHAR(100) NULL AFTER billing_address2");
        self::ensureColumn($custTable, 'billing_state', "VARCHAR(100) NULL AFTER billing_city");
        self::ensureColumn($custTable, 'billing_zip', "VARCHAR(20) NULL AFTER billing_state");

        // 2) Fix the availability column definitions (remove inline comments & trailing commas)
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

        // 3) Add well-named indexes (replaces any ADD COLUMN INDEX(...) logic)
        self::addIndex($wpdb->prefix . 'arm_estimates', 'idx_arm_estimates_customer_id', ['customer_id']);
        self::addIndex($wpdb->prefix . 'arm_estimates', 'idx_arm_estimates_request_id', ['request_id']);

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
            // Only add a PK if it doesn't exist
            $wpdb->query("ALTER TABLE `$table` ADD PRIMARY KEY (`$column`)");
        }
    }

    private static function ensureColumn(string $table, string $column, string $definition): void {
        global $wpdb;
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME=%s
                   AND COLUMN_NAME=%s
                 LIMIT 1",
                $table, $column
            )
        );
        if (!$exists) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    private static function modifyColumn(string $table, string $column, string $definition): void {
        global $wpdb;
        // Only attempt if the column exists
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

    private static function addIndex(string $table, string $indexName, array $columns, string $type = 'INDEX'): void {
        global $wpdb;
        if (empty($columns)) return; // guard against bad callers

        // Check if index already exists
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

        // Build column list with backticks
        $cols = implode('`,`', array_map('sanitize_key', $columns));
        $sql  = "ALTER TABLE `$table` ADD $type `$indexName` (`$cols`)";
        $wpdb->query($sql);
    }
}
