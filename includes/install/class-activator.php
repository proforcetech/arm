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

        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        
        if (!defined('ARM_RE_PATH')) {
            define('ARM_RE_PATH', plugin_dir_path(dirname(__FILE__, 2)));
        }

        
        self::require_modules();

        $charset                = $wpdb->get_charset_collate();
        $vehicles_table         = $wpdb->prefix . 'arm_vehicles';
        $service_table          = $wpdb->prefix . 'arm_service_types';
        $time_entries_table     = $wpdb->prefix . 'arm_time_entries';
        $time_adjust_table      = $wpdb->prefix . 'arm_time_adjustments';
        $reminder_pref_table    = $wpdb->prefix . 'arm_reminder_preferences';
        $reminder_campaigns_tbl = $wpdb->prefix . 'arm_reminder_campaigns';
        $reminder_logs_table    = $wpdb->prefix . 'arm_reminder_logs';

        self::install_vehicle_schema();

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


        dbDelta("CREATE TABLE $time_entries_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            estimate_id BIGINT UNSIGNED NOT NULL,
            technician_id BIGINT UNSIGNED NOT NULL,
            source ENUM('technician','admin') NOT NULL DEFAULT 'technician',
            start_at DATETIME NOT NULL,
            end_at DATETIME NULL,
            duration_minutes INT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_job (job_id),
            KEY idx_estimate (estimate_id),
            KEY idx_technician (technician_id),
            KEY idx_open (technician_id, end_at)
        ) $charset;");


        dbDelta("CREATE TABLE $time_adjust_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            time_entry_id BIGINT UNSIGNED NOT NULL,
            admin_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL DEFAULT 'update',
            previous_start DATETIME NULL,
            previous_end DATETIME NULL,
            previous_duration INT NULL,
            new_start DATETIME NULL,
            new_end DATETIME NULL,
            new_duration INT NULL,
            reason TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_entry (time_entry_id),
            KEY idx_admin (admin_id)
        ) $charset;");


        dbDelta("CREATE TABLE $reminder_pref_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NULL,
            email VARCHAR(200) NULL,
            phone VARCHAR(50) NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
            preferred_channel ENUM('none','email','sms','both') NOT NULL DEFAULT 'email',
            lead_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
            preferred_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            source VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_customer (customer_id),
            UNIQUE KEY uniq_email (email),
            KEY idx_channel (preferred_channel),
            KEY idx_active (is_active)
        ) $charset;");


        dbDelta("CREATE TABLE $reminder_campaigns_tbl (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
            channel ENUM('email','sms','both') NOT NULL DEFAULT 'email',
            frequency_unit ENUM('one_time','daily','weekly','monthly') NOT NULL DEFAULT 'one_time',
            frequency_interval SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            next_run_at DATETIME NULL,
            last_run_at DATETIME NULL,
            email_subject VARCHAR(190) NULL,
            email_body LONGTEXT NULL,
            sms_body TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_status (status),
            KEY idx_next (next_run_at)
        ) $charset;");


        dbDelta("CREATE TABLE $reminder_logs_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            preference_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            channel ENUM('email','sms') NOT NULL DEFAULT 'email',
            status ENUM('queued','pending','sent','failed','skipped') NOT NULL DEFAULT 'queued',
            scheduled_for DATETIME NOT NULL,
            sent_at DATETIME NULL,
            message_body LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_campaign (campaign_id),
            KEY idx_pref (preference_id),
            KEY idx_status (status),
            KEY idx_scheduled (scheduled_for)
        ) $charset;");

        
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $service_table");
        if ($count === 0) {
            $wpdb->insert($service_table, ['name' => 'General Diagnostics', 'is_active' => 1, 'sort_order' => 10]);
            $wpdb->insert($service_table, ['name' => 'Brake Service',        'is_active' => 1, 'sort_order' => 20]);
            $wpdb->insert($service_table, ['name' => 'AC Service',           'is_active' => 1, 'sort_order' => 30]);
        }

        
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
        if (class_exists('\\ARM\\TimeLogs\\Controller')) {
            \ARM\TimeLogs\Controller::install_tables();
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
        \ARM\Accounting\Transactions::install_tables();
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

    private static function install_vehicle_schema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset        = $wpdb->get_charset_collate();
        $vehicle_table  = $wpdb->prefix . 'arm_vehicle_data';
        $requests_table = $wpdb->prefix . 'arm_estimate_requests';

        dbDelta("CREATE TABLE $vehicle_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year SMALLINT NOT NULL,
            make VARCHAR(64) NOT NULL,
            model VARCHAR(64) NOT NULL,
            engine VARCHAR(128) NOT NULL,
            transmission VARCHAR(80) NOT NULL,
            drive VARCHAR(32) NOT NULL,
            trim VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY combo (year, make, model, engine, transmission, drive, trim),
            KEY yr (year),
            KEY mk (make),
            KEY mdl (model),
            KEY eng (engine),
            KEY trn (transmission),
            KEY drv (drive),
            KEY trm (trim)
        ) $charset;");

        dbDelta("CREATE TABLE $requests_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_year SMALLINT NULL,
            vehicle_make VARCHAR(64) NULL,
            vehicle_model VARCHAR(64) NULL,
            vehicle_engine VARCHAR(128) NULL,
            vehicle_transmission VARCHAR(80) NULL,
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
        self::install_vehicle_schema();

        if (class_exists('\\ARM\\Appointments\\Installer')) {
            \ARM\Appointments\Installer::maybe_upgrade_legacy_schema();
            \ARM\Appointments\Installer::install_tables();
        }

        if (class_exists('\\ARM\\Estimates\\Controller')) {
            \ARM\Estimates\Controller::install_tables();
        }

        \ARM\Accounting\Transactions::install_tables();

        if (defined('ARM_RE_VERSION')) {
            update_option('arm_re_version', ARM_RE_VERSION);
        }
    }

    private static function require_modules() {
        
        $map = [
            '\\ARM\\Appointments\\Installer' => 'includes/appointments/Installer.php',
            '\\ARM\\Estimates\\Controller' => 'includes/estimates/Controller.php',
            '\\ARM\\Invoices\\Controller'  => 'includes/invoices/Controller.php',
            '\\ARM\\Bundles\\Controller'   => 'includes/bundles/Controller.php',
            '\\ARM\\Audit\\Logger'     => 'includes/audit/Logger.php',
            '\\ARM\\TimeLogs\\Controller' => 'includes/timelogs/Controller.php',
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
        
    }
}
