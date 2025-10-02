<?php
/**
 * Installer / Activator
 *
 * Creates core tables, seeds defaults, and invokes module installers.
 */
namespace ARM\Install;

if (!defined('ABSPATH')) exit;

final class Activator {

    private const SCHEMA_VERSION = '2024.09.20';

    public static function activate() {
        self::run_migrations();
        update_option('arm_re_schema_version', self::SCHEMA_VERSION);
    }

    /**
     * Run schema upgrades when the plugin is updated without a re-activation.
     */
    public static function maybe_upgrade(): void {
        $installed = get_option('arm_re_schema_version');
        if ($installed && version_compare($installed, self::SCHEMA_VERSION, '>=')) {
            return;
        }

        self::run_migrations();
        update_option('arm_re_schema_version', self::SCHEMA_VERSION);
    }

    private static function run_migrations(): void {
        global $wpdb;

        // Make sure dbDelta is available.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // If constants/files arent available yet (defensive), define/require them.
        if (!defined('ARM_RE_PATH')) {
            define('ARM_RE_PATH', plugin_dir_path(dirname(__FILE__, 2)));
        }

        // Ensure modules are loaded so we can call their installers safely.
        self::require_modules();

        $charset            = $wpdb->get_charset_collate();
        $vehicle_table      = $wpdb->prefix . 'arm_vehicle_data';
        $service_table      = $wpdb->prefix . 'arm_service_types';
        $requests_table     = $wpdb->prefix . 'arm_estimate_requests';
        $customer_table     = $wpdb->prefix . 'arm_customers';
        $customer_vehicles  = $wpdb->prefix . 'arm_vehicles';

        dbDelta("CREATE TABLE {$customer_table} (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          first_name VARCHAR(100) NOT NULL,
          last_name VARCHAR(100) NOT NULL,
          email VARCHAR(200) NOT NULL,
          phone VARCHAR(50) NULL,
          address VARCHAR(200) NULL,
          city VARCHAR(100) NULL,
          state VARCHAR(100) NULL,
          zip VARCHAR(20) NULL,
          notes TEXT NULL,
          tax_exempt TINYINT(1) NOT NULL DEFAULT 0,
          wp_user_id BIGINT UNSIGNED NULL,
          created_at DATETIME NOT NULL,
          updated_at DATETIME NULL,
          UNIQUE KEY uniq_wp_user (wp_user_id)
        ) $charset;");

        // Vehicle dimension table (reference data for make/model combos)
        dbDelta("CREATE TABLE $vehicle_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year SMALLINT NOT NULL,
            make VARCHAR(64) NOT NULL,
            model VARCHAR(64) NOT NULL,
            engine VARCHAR(128) NOT NULL,
            drive VARCHAR(32) NOT NULL,
            trim VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY combo (year, make, model, engine, drive, trim),
            KEY yr (year),
            KEY mk (make),
            KEY mdl (model),
            KEY eng (engine),
            KEY drv (drive),
            KEY trm (trim)
        ) $charset;");

        // Customer vehicles table (per-customer garage)
        dbDelta("CREATE TABLE $customer_vehicles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NULL,
            year SMALLINT NULL,
            make VARCHAR(120) NULL,
            model VARCHAR(120) NULL,
            trim VARCHAR(120) NULL,
            engine VARCHAR(150) NULL,
            drive VARCHAR(60) NULL,
            vin VARCHAR(32) NULL,
            license_plate VARCHAR(32) NULL,
            current_mileage BIGINT UNSIGNED NULL,
            previous_service_mileage BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_customer (customer_id),
            KEY idx_year (year),
            KEY idx_vin (vin),
            KEY idx_license_plate (license_plate)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}arm_appointments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            estimate_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            start DATETIME NOT NULL,
            end DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'booked',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY est (estimate_id),
            KEY cust (customer_id),
            KEY start (start)
        ) $charset;");

        // Service types
        dbDelta("CREATE TABLE $service_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(128) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name),
            KEY active (is_active),
            KEY sort (sort_order)
        ) $charset;");

        // Incoming estimate requests
        dbDelta("CREATE TABLE $requests_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_year SMALLINT NULL,
            vehicle_make VARCHAR(64) NULL,
            vehicle_model VARCHAR(64) NULL,
            vehicle_engine VARCHAR(128) NULL,
            vehicle_drive VARCHAR(32) NULL,
            vehicle_trim VARCHAR(128) NULL,
            vehicle_other TEXT NULL,
            service_type_id BIGINT UNSIGNED NULL,
            issue_description TEXT NULL,
            first_name VARCHAR(64) NOT NULL,
            last_name VARCHAR(64) NOT NULL,
            email VARCHAR(128) NOT NULL,
            phone VARCHAR(32) NULL,
            customer_address VARCHAR(200) NOT NULL,
            customer_city VARCHAR(100) NOT NULL,
            customer_zip VARCHAR(20) NOT NULL,
            service_same_as_customer TINYINT(1) NOT NULL DEFAULT 0,
            service_address VARCHAR(200) NOT NULL,
            service_city VARCHAR(100) NOT NULL,
            service_zip VARCHAR(20) NOT NULL,
            delivery_email TINYINT(1) NOT NULL DEFAULT 0,
            delivery_sms TINYINT(1) NOT NULL DEFAULT 0,
            delivery_both TINYINT(1) NOT NULL DEFAULT 0,
            terms_accepted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY stype (service_type_id),
            KEY created_at (created_at)
        ) $charset;");

        self::sync_customer_wp_users($customer_table);
        self::migrate_vehicle_customer_links($customer_vehicles, $customer_table);

        // Seed example service types
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $service_table");
        if ($count === 0) {
            $wpdb->insert($service_table, ['name' => 'General Diagnostics', 'is_active' => 1, 'sort_order' => 10]);
            $wpdb->insert($service_table, ['name' => 'Brake Service',        'is_active' => 1, 'sort_order' => 20]);
            $wpdb->insert($service_table, ['name' => 'AC Service',           'is_active' => 1, 'sort_order' => 30]);
        }

        // Defaults (terms, notify, pricing & tax options, call-out/mileage)
        if (!get_option('arm_re_terms_html')) {
            update_option('arm_re_terms_html',
                '<h3>Terms & Conditions</h3><p><strong>Please read:</strong> Estimates are based on provided information and initial inspection; final pricing may vary after diagnostics.</p>'
            );
        }
        if (!get_option('arm_re_notify_email')) {
            update_option('arm_re_notify_email', get_option('admin_email'));
        }
        if (!get_option('arm_re_labor_rate')) update_option('arm_re_labor_rate', 125);
        if (!get_option('arm_re_tax_rate'))   update_option('arm_re_tax_rate', 0);

        if (!get_option('arm_re_tax_apply'))             update_option('arm_re_tax_apply', 'parts_labor');
        if (!get_option('arm_re_callout_default'))       update_option('arm_re_callout_default', '0');
        if (!get_option('arm_re_mileage_rate_default'))  update_option('arm_re_mileage_rate_default', '0');

        // Install submodule tables (idempotent)
        if (class_exists('\\ARM\\Appointments\\Installer')) \ARM\Appointments\Installer::install_tables();
        if (class_exists('\\ARM\\Estimates\\Controller')) \ARM\Estimates\Controller::install_tables();
        if (class_exists('\\ARM\\Audit\\Logger'))     \ARM\Audit\Logger::install_tables();
        if (class_exists('\\ARM\\PDF\\Controller'))       \ARM\PDF\Controller::install_tables();
        if (class_exists('\\ARM\\Invoices\\Controller'))  \ARM\Invoices\Controller::install_tables();
        if (class_exists('\\ARM\\Bundles\\Controller'))   \ARM\Bundles\Controller::install_tables();
        if (class_exists('\\ARM\\Integrations\\Payments_Stripe'))  \ARM\Integrations\Payments_Stripe::install_tables();
        if (class_exists('\\ARM\\Integrations\\Payments_PayPal'))    \ARM\Integrations\Payments_PayPal::install_tables();
    }

    private static function sync_customer_wp_users(string $customer_table): void {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT id, email FROM {$customer_table} WHERE wp_user_id IS NULL AND email <> ''", ARRAY_A);
        if (!$rows) {
            return;
        }

        $now = current_time('mysql');
        $claimed = [];
        foreach ($rows as $row) {
            $user = get_user_by('email', $row['email']);
            if (!$user) {
                continue;
            }
            if (isset($claimed[$user->ID])) {
                continue;
            }

            $updated = $wpdb->update(
                $customer_table,
                [
                    'wp_user_id' => (int) $user->ID,
                    'updated_at' => $now,
                ],
                ['id' => (int) $row['id']]
            );
            if ($updated !== false) {
                $claimed[$user->ID] = true;
            }
        }
    }

    private static function migrate_vehicle_customer_links(string $customer_vehicles, string $customer_table): void {
        global $wpdb;

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $customer_vehicles
        ));
        if (!$table_exists) {
            return;
        }

        $has_user_column = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'user_id'",
            $customer_vehicles
        ));

        if ($has_user_column) {
            // First, map any vehicles where a linked customer now exists.
            $wpdb->query("UPDATE {$customer_vehicles} v INNER JOIN {$customer_table} c ON c.wp_user_id = v.user_id SET v.customer_id = c.id WHERE v.customer_id IS NULL AND v.user_id IS NOT NULL");

            $remaining_users = $wpdb->get_col("SELECT DISTINCT v.user_id FROM {$customer_vehicles} v WHERE v.user_id IS NOT NULL AND v.customer_id IS NULL");
            if ($remaining_users) {
                $now = current_time('mysql');
                foreach ($remaining_users as $user_id) {
                    $user = get_user_by('id', (int) $user_id);
                    if (!$user) {
                        continue;
                    }

                    $customer_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$customer_table} WHERE wp_user_id = %d", (int) $user->ID));
                    if (!$customer_id && !empty($user->user_email)) {
                        $customer_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$customer_table} WHERE email = %s", $user->user_email));
                        if ($customer_id) {
                            $wpdb->update(
                                $customer_table,
                                [
                                    'wp_user_id' => (int) $user->ID,
                                    'updated_at' => $now,
                                ],
                                ['id' => (int) $customer_id]
                            );
                        }
                    }

                    if (!$customer_id) {
                        $first = sanitize_text_field(get_user_meta($user->ID, 'first_name', true));
                        $last  = sanitize_text_field(get_user_meta($user->ID, 'last_name', true));
                        $display = trim((string) $user->display_name);
                        if ($first === '') {
                            $parts = preg_split('/\s+/', $display, 2);
                            $first = sanitize_text_field($parts[0] ?? $user->user_login ?? 'Customer');
                        }
                        if ($last === '') {
                            $parts = isset($parts) ? $parts : preg_split('/\s+/', $display, 2);
                            $last  = sanitize_text_field($parts[1] ?? 'Account');
                        }

                        $wpdb->insert(
                            $customer_table,
                            [
                                'first_name' => $first !== '' ? $first : 'Customer',
                                'last_name'  => $last !== '' ? $last : 'Account',
                                'email'      => $user->user_email,
                                'wp_user_id' => (int) $user->ID,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]
                        );
                        $customer_id = (int) $wpdb->insert_id;
                    }

                    if ($customer_id) {
                        $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE {$customer_vehicles} SET customer_id = %d, updated_at = %s WHERE user_id = %d AND customer_id IS NULL",
                                (int) $customer_id,
                                $now,
                                (int) $user->ID
                            )
                        );
                    }
                }
            }
        }
    }

    private static function require_modules() {
        // Load only if not already loaded (require_once is idempotent).
        $map = [
            '\ARM\Appointments\Installer' => 'includes/appointments/installer.php',
            '\ARM\Estimates\Controller' => 'includes/estimates/Controller.php',
            '\ARM\Invoices\Controller'  => 'includes/invoices/Controller.php',
            '\ARM\Bundles\Controller'   => 'includes/bundles/Controller.php',
            '\ARM\Audit\Logger'     => 'includes/audit/Logger.php',
            '\ARM\PDF\Controller'       => 'includes/pdf/Controller.php',
            '\ARM\Integrations\Payments_Stripe'  => 'includes/integrations/Payments_Stripe.php',
            '\ARM\Integrations\Payments_PayPal'    => 'includes/integrations/Payments_PayPal.php',
        ];
        foreach ($map as $class => $rel) {
            if (!class_exists($class) && file_exists(ARM_RE_PATH . $rel)) {
                require_once ARM_RE_PATH . $rel;
            }
        }
    }

    public static function uninstall() {
        // Intentionally left blank to preserve data on uninstall
    }
}
