<?php

namespace ARM\Setup;

if (!defined('ABSPATH')) exit;

/**
 * Install-time setup: requirements, tables, options, roles, pages, seeds.
 * Why: one place to guarantee a working install.
 */
final class Activator
{
    /** Runs on plugin activation. */
    public static function activate(): void
    {
        self::check_requirements_or_die();
        self::create_tables();
        self::register_default_options();
        self::seed_service_types();
        self::create_pages();
        self::add_roles_and_caps();
        self::schedule_cron();
        flush_rewrite_rules();
    }

    /** Daily cleanup cron target. */
    public static function cleanup(): void
    {
        global $wpdb;
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}arm_estimate_submissions WHERE created_at < (NOW() - INTERVAL %d DAY)",
                180
            )
        );
    }

    /** ---- Internals ---- */

    private static function check_requirements_or_die(): void
    {
        global $wp_version;
        $req_php = '8.0';   
        $req_wp  = '6.0';

        if (version_compare(PHP_VERSION, $req_php, '<')) {
            deactivate_plugins(plugin_basename(defined('ARM_RE_FILE') ? ARM_RE_FILE : __FILE__));
            wp_die(
                esc_html(sprintf('ARM Repair Estimates requires PHP %s or newer. You have %s.', $req_php, PHP_VERSION))
            );
        }
        if (version_compare($wp_version, $req_wp, '<')) {
            deactivate_plugins(plugin_basename(defined('ARM_RE_FILE') ? ARM_RE_FILE : __FILE__));
            wp_die(
                esc_html(sprintf('ARM Repair Estimates requires WordPress %s or newer. You have %s.', $req_wp, $wp_version))
            );
        }
    }

    private static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $sql = [];

        
        $sql[] = "CREATE TABLE {$p}arm_estimates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NULL,
            first_name VARCHAR(100) NULL,
            last_name VARCHAR(100) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(50) NULL,
            vehicle_year SMALLINT UNSIGNED NULL,
            vehicle_make VARCHAR(80) NULL,
            vehicle_model VARCHAR(120) NULL,
            vin VARCHAR(32) NULL,
            plate VARCHAR(32) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
            labor_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_customer (customer_id),
            KEY idx_created (created_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}arm_estimate_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            estimate_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(16) NOT NULL DEFAULT 'part',
            description TEXT NULL,
            qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            hours DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NULL,
            PRIMARY KEY (id),
            KEY idx_estimate (estimate_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}arm_estimate_submissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            service_type_id BIGINT UNSIGNED NULL,
            year SMALLINT UNSIGNED NULL,
            make VARCHAR(80) NULL,
            model VARCHAR(120) NULL,
            message LONGTEXT NULL,
            source VARCHAR(60) NULL,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_created (created_at)
        ) $charset;";

        
        $sql[] = "CREATE TABLE {$p}arm_invoices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NULL,
            first_name VARCHAR(100) NULL,
            last_name VARCHAR(100) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(50) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'DUE',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
            tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            paid_at DATETIME NULL,
            payment_gateway VARCHAR(40) NULL,
            payment_ref VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            public_token VARCHAR(190) NULL,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_customer (customer_id),
            KEY idx_created (created_at),
            KEY idx_token (public_token)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}arm_invoice_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(16) NOT NULL DEFAULT 'part',
            description TEXT NULL,
            qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            hours DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NULL,
            PRIMARY KEY (id),
            KEY idx_invoice (invoice_id)
        ) $charset;";

        
        $sql[] = "CREATE TABLE {$p}arm_vehicle_data (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year SMALLINT UNSIGNED NOT NULL,
            make VARCHAR(80) NOT NULL,
            model VARCHAR(120) NOT NULL,
            engine VARCHAR(120) NOT NULL,
            drive VARCHAR(80) NOT NULL,
            trim  VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_vehicle (year, make, model, engine, drive, trim),
            KEY idx_year (year),
            KEY idx_make (make),
            KEY idx_model (model)
        ) $charset;";

        
        $sql[] = "CREATE TABLE {$p}arm_service_types (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_active (is_active, sort_order)
        ) $charset;";

        
        $sql[] = "CREATE TABLE {$p}arm_appointments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NULL,
            estimate_id BIGINT UNSIGNED NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'BOOKED',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_customer (customer_id),
            KEY idx_estimate (estimate_id),
            KEY idx_start (start_datetime)
        ) $charset;";

        foreach ($sql as $q) dbDelta($q);
    }

    private static function register_default_options(): void
    {
        add_option('arm_re_labor_rate', '120');         
        add_option('arm_re_tax_rate', '6.0');
        add_option('arm_re_currency', 'usd');
        add_option('arm_re_pay_success', home_url('/'));
        add_option('arm_re_pay_cancel',  home_url('/'));
        add_option('arm_company_logo', '');
        add_option('arm_company_name', get_bloginfo('name'));
        add_option('arm_company_address', '');
        add_option('arm_company_phone', '');
        add_option('arm_company_email', get_bloginfo('admin_email'));
    }

    private static function seed_service_types(): void
    {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_service_types';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tbl");
        if ($count > 0) return;

        $rows = [
            ['Diagnostics', 1, 10],
            ['Oil Change',  1, 20],
            ['Brakes',      1, 30],
            ['Suspension',  1, 40],
            ['AC / Heating',1, 50],
        ];
        foreach ($rows as [$name, $active, $sort]) {
            $wpdb->insert($tbl, ['name' => $name, 'is_active' => $active, 'sort_order' => $sort], ['%s','%d','%d']);
        }
    }

    private static function create_pages(): void
    {
        
        if (!get_option('arm_re_page_estimate_form')) {
            $pid = wp_insert_post([
                'post_title'   => 'Request a Repair Estimate',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[arm_repair_estimate_form]',
            ], true);
            if (!is_wp_error($pid)) add_option('arm_re_page_estimate_form', (int) $pid);
        }

        
        if (!get_option('arm_re_page_customer_dashboard')) {
            $pid = wp_insert_post([
                'post_title'   => 'My Vehicle Service',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[arm_customer_dashboard]',
            ], true);
            if (!is_wp_error($pid)) add_option('arm_re_page_customer_dashboard', (int) $pid);
        }
    }

    private static function add_roles_and_caps(): void
    {
        
        $caps = [
            'arm_manage_estimates'    => true,
            'arm_manage_invoices'     => true,
            'arm_manage_appointments' => true,
            'arm_export_data'         => true,
        ];

        add_role('arm_re_manager', 'Repair Estimates Manager', $caps + [
            'read' => true,
        ]);

        $admin = get_role('administrator');
        if ($admin) {
            foreach (array_keys($caps) as $cap) $admin->add_cap($cap);
        }
    }

    private static function schedule_cron(): void
    {
        if (!wp_next_scheduled('arm_re_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'arm_re_cleanup');
        }
    }
}
