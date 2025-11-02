<?php
/**
 * Credit Management Installer
 *
 * @package ARM_Repair_Estimates
 */

namespace ARM\Credit;

/**
 * Handles installation and database schema for credit management.
 */
class Installer {
	/**
	 * Install credit management tables.
	 */
	public static function install_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// Credit Accounts Table
		$sql_accounts = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_credit_accounts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			credit_limit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			current_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			available_credit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			notes TEXT,
			payment_term_type VARCHAR(20) NOT NULL DEFAULT 'net_terms' COMMENT 'net_terms|same_as_cash|revolving',
			payment_terms INT UNSIGNED DEFAULT 30 COMMENT 'Net payment terms in days',
			last_payment_date DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY customer_id (customer_id),
			KEY status (status)
		) $charset_collate;";

		// Credit Transactions Table
		$sql_transactions = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_credit_transactions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			transaction_type VARCHAR(20) NOT NULL COMMENT 'charge|payment|adjustment|refund',
			amount DECIMAL(10,2) NOT NULL,
			balance_after DECIMAL(10,2) NOT NULL,
			reference_type VARCHAR(50) DEFAULT NULL COMMENT 'invoice|estimate|manual',
			reference_id BIGINT UNSIGNED DEFAULT NULL,
			description TEXT,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY account_id (account_id),
			KEY customer_id (customer_id),
			KEY transaction_date (transaction_date),
			KEY reference (reference_type, reference_id)
		) $charset_collate;";

		// Credit Payments Table
		$sql_payments = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_credit_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			payment_method VARCHAR(50) NOT NULL COMMENT 'cash|check|card|bank_transfer|other',
			amount DECIMAL(10,2) NOT NULL,
			payment_date DATETIME NOT NULL,
			reference_number VARCHAR(100) DEFAULT NULL,
			notes TEXT,
			processed_by BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'completed' COMMENT 'pending|completed|failed|reversed',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY account_id (account_id),
			KEY customer_id (customer_id),
			KEY payment_date (payment_date),
			KEY status (status)
		) $charset_collate;";

		// Credit Payment Reminders Table
		$sql_reminders = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_credit_reminders (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			reminder_type VARCHAR(20) NOT NULL COMMENT 'due_soon|overdue|custom',
			days_before_due INT DEFAULT NULL,
			days_past_due INT DEFAULT NULL,
			sent_at DATETIME NOT NULL,
			sent_via VARCHAR(20) NOT NULL COMMENT 'email|sms|both',
			message TEXT,
			status VARCHAR(20) NOT NULL DEFAULT 'sent' COMMENT 'sent|failed|bounced',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY account_id (account_id),
			KEY customer_id (customer_id),
			KEY sent_at (sent_at),
			KEY reminder_type (reminder_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_accounts );
		dbDelta( $sql_transactions );
		dbDelta( $sql_payments );
		dbDelta( $sql_reminders );
	}
}
