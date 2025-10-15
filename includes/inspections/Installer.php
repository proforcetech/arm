<?php
namespace ARM\Inspections;

if (!defined('ABSPATH')) exit;

final class Installer
{
    public static function install_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset   = $wpdb->get_charset_collate();
        $templates = $wpdb->prefix . 'arm_inspection_templates';
        $items     = $wpdb->prefix . 'arm_inspection_template_items';
        $reports   = $wpdb->prefix . 'arm_inspections';
        $responses = $wpdb->prefix . 'arm_inspection_responses';

        dbDelta("CREATE TABLE $templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            description TEXT NULL,
            default_scoring VARCHAR(32) NOT NULL DEFAULT 'scale',
            scale_min SMALLINT NULL,
            scale_max SMALLINT NULL,
            pass_label VARCHAR(100) NULL,
            fail_label VARCHAR(100) NULL,
            pass_value SMALLINT NULL,
            fail_value SMALLINT NULL,
            include_notes_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY active (is_active)
        ) $charset;");

        dbDelta("CREATE TABLE $items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(190) NOT NULL,
            description TEXT NULL,
            item_type ENUM('scale','pass_fail','note') NOT NULL DEFAULT 'scale',
            scale_min SMALLINT NULL,
            scale_max SMALLINT NULL,
            pass_label VARCHAR(100) NULL,
            fail_label VARCHAR(100) NULL,
            pass_value SMALLINT NULL,
            fail_value SMALLINT NULL,
            include_notes TINYINT(1) NOT NULL DEFAULT 0,
            note_label VARCHAR(190) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY template (template_id),
            KEY sort (template_id, sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE $reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id BIGINT UNSIGNED NOT NULL,
            technician_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            vehicle_id BIGINT UNSIGNED NULL,
            estimate_id BIGINT UNSIGNED NULL,
            inspector_name VARCHAR(190) NULL,
            inspector_email VARCHAR(190) NULL,
            customer_name VARCHAR(190) NULL,
            customer_email VARCHAR(190) NULL,
            customer_phone VARCHAR(50) NULL,
            summary TEXT NULL,
            score_total DECIMAL(10,2) NULL,
            score_max DECIMAL(10,2) NULL,
            result VARCHAR(32) NULL,
            share_token VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'completed',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY template (template_id),
            KEY token (share_token),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE $responses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inspection_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            value_text TEXT NULL,
            numeric_value DECIMAL(10,2) NULL,
            score_value DECIMAL(10,2) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY inspection (inspection_id),
            KEY item (item_id)
        ) $charset;");
    }
}
