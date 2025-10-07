<?php
/**
 * Installer / Activator
 *
 * Creates core tables, seeds defaults, and invokes module installers.
 */
namespace ARM\Install;

if (!defined('ABSPATH')) exit;

final class Activator {

    public static function activate() {
        global $wpdb;

        // Make sure dbDelta is available.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // If constants/files arent available yet (defensive), define/require them.
        if (!defined('ARM_RE_PATH')) {
            define('ARM_RE_PATH', plugin_dir_path(dirname(__FILE__, 2)));
        }

        // Ensure modules are loaded so we can call their installers safely.
        self::require_modules();

        $charset         = $wpdb->get_charset_collate();
        $vehicle_table   = $wpdb->prefix . 'arm_vehicle_data';
        $vehicles_table  = $wpdb->prefix . 'arm_vehicles';
        $service_table   = $wpdb->prefix . 'arm_service_types';
        $requests_table  = $wpdb->prefix . 'arm_estimate_requests';

        dbDelta ("CREATE TABLE {$wpdb->prefix}arm_customers (
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
          created_at DATETIME NOT NULL,
          updated_at DATETIME NULL
        ) $charset;");


        // Vehicle dimension table
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

        dbDelta("CREATE TABLE $vehicles_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            year SMALLINT NULL,
            make VARCHAR(80) NULL,
            model VARCHAR(120) NULL,
            engine VARCHAR(120) NULL,
            trim VARCHAR(120) NULL,
            vin VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY cust (customer_id),
            KEY user (user_id),
            KEY yr (year)
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
        if (class_exists('\\ARM\\Appointments\\Installer')) {
            \ARM\Appointments\Installer::maybe_upgrade_legacy_schema();
            \ARM\Appointments\Installer::install_tables();
        }
        if (class_exists('\\ARM\\Estimates\\Controller')) {
            \ARM\Estimates\Controller::install_tables();
        }
        if (class_exists('\\ARM\\Audit\\Logger')) {
            \ARM\Audit\Logger::install_tables();
        }
        if (class_exists('\\ARM\\PDF\\Controller')) {
            \ARM\PDF\Controller::install_tables();
        }
        if (class_exists('\\ARM\\Invoices\\Controller')) {
            \ARM\Invoices\Controller::install_tables();
        }
        if (class_exists('\\ARM\\Bundles\\Controller')) {
            \ARM\Bundles\Controller::install_tables();
        }
        if (class_exists('\\ARM\\Integrations\\Payments_Stripe')) {
            \ARM\Integrations\Payments_Stripe::install_tables();
        }
        if (class_exists('\\ARM\\Integrations\\Payments_PayPal')) {
            \ARM\Integrations\Payments_PayPal::install_tables();
        }
        if (class_exists('\\ARM\\Links\\Shortlinks')) {
            \ARM\Links\Shortlinks::install_tables();
            \ARM\Links\Shortlinks::add_rewrite_rules();
            flush_rewrite_rules();
        }

        if (defined('ARM_RE_VERSION')) {
            update_option('arm_re_version', ARM_RE_VERSION);
        }

    }

    public static function maybe_upgrade(): void
    {
        if (!function_exists('get_option')) {
            return;
        }

        $installed_version = get_option('arm_re_version');
        if ($installed_version && defined('ARM_RE_VERSION') && version_compare($installed_version, ARM_RE_VERSION, '>=')) {
            return;
        }

        self::require_modules();

        if (class_exists('\\ARM\\Appointments\\Installer')) {
            \ARM\Appointments\Installer::maybe_upgrade_legacy_schema();
            \ARM\Appointments\Installer::install_tables();
        }

        if (defined('ARM_RE_VERSION')) {
            update_option('arm_re_version', ARM_RE_VERSION);
        }
    }

    private static function require_modules() {
        // Load only if not already loaded (require_once is idempotent).
        $map = [
            '\\ARM\\Appointments\\Installer' => 'includes/appointments/Installer.php',
            '\\ARM\\Estimates\\Controller' => 'includes/estimates/Controller.php',
            '\\ARM\\Invoices\\Controller'  => 'includes/invoices/Controller.php',
            '\\ARM\\Bundles\\Controller'   => 'includes/bundles/Controller.php',
            '\\ARM\\Audit\\Logger'     => 'includes/audit/Logger.php',
            '\\ARM\\PDF\\Controller'       => 'includes/pdf/Controller.php',
            '\\ARM\\Integrations\\Payments_Stripe'  => 'includes/integrations/Payments_Stripe.php',
            '\\ARM\\Integrations\\Payments_PayPal'    => 'includes/integrations/Payments_PayPal.php',
            '\\ARM\\Links\\Shortlinks'      => 'includes/links/class-shortlinks.php',
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
