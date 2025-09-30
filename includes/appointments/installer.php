<?php
namespace ARM\Appointments;

class Installer {
    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $appointments = $wpdb->prefix . 'arm_appointments';
        $availability = $wpdb->prefix . 'arm_availability';
        $holidays     = $wpdb->prefix . 'arm_holidays';

        dbDelta("CREATE TABLE $appointments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NULL,
            estimate_id BIGINT UNSIGNED NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;");

        dbDelta("CREATE TABLE $availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day_of_week TINYINT NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset;");

	dbDelta("CREATE TABLE {$wpdb->prefix}arm_availability (
	    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	    type ENUM('hours','holiday') NOT NULL,
	    day_of_week TINYINT NULL,  -- 0=Sunday,6=Saturday (for hours)
	    start_time TIME NULL,
	    end_time TIME NULL,
	    date DATE NULL,            -- for holiday single day
	    label VARCHAR(128) NULL,
	    PRIMARY KEY (id),
	    KEY type (type),
	    KEY day (day_of_week),
	    KEY date (date)
	) $charset;");

	dbDelta ("CREATE TABLE wp_arm_appointments (
	  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	  customer_id BIGINT UNSIGNED NOT NULL,
	  estimate_id BIGINT UNSIGNED NULL,
	  start_datetime DATETIME NOT NULL,
	  end_datetime DATETIME NOT NULL,
	  status ENUM('pending','confirmed','cancelled','completed') DEFAULT 'pending',
	  notes TEXT,
	  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
	) $charset;");

        // Correct and final schema for availability (includes hours and holidays)
        dbDelta("CREATE TABLE $availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type ENUM('hours','holiday') NOT NULL,
            day_of_week TINYINT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            date DATE NULL,
            label VARCHAR(128) NULL,
            PRIMARY KEY (id),
            KEY type (type),
            KEY day (day_of_week),
            KEY date (date)
        ) $charset;");
    }
}
