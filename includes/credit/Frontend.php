<?php
/**
 * Credit Account Frontend
 *
 * @package ARM_Repair_Estimates
 */

namespace ARM\Credit;

/**
 * Customer-facing credit account functionality.
 */
class Frontend {
	/**
	 * Bootstrap the module.
	 */
	public static function boot() {
		add_shortcode( 'arm_credit_account', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_ajax_arm_customer_credit_history', array( __CLASS__, 'ajax_get_history' ) );
	}

	/**
	 * Render credit account shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . __( 'Please log in to view your credit account.', 'arm-repair-estimates' ) . '</p>';
		}

		global $wpdb;

		$user        = wp_get_current_user();
		$customer_id = self::get_customer_id_from_user( $user->ID );

		if ( ! $customer_id ) {
			return '<p>' . __( 'No customer account found.', 'arm-repair-estimates' ) . '</p>';
		}

		// Get credit account
		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_accounts WHERE customer_id = %d",
				$customer_id
			)
		);

		if ( ! $account ) {
			return '<p>' . __( 'You do not have a credit account. Please contact us to set up credit terms.', 'arm-repair-estimates' ) . '</p>';
		}

		// Get recent transactions
		$transactions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_transactions
				WHERE account_id = %d
				ORDER BY transaction_date DESC
				LIMIT 50",
				$account->id
			)
		);

		// Get payment summary
		$payment_summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_payments,
					SUM(amount) as total_paid
				FROM {$wpdb->prefix}arm_credit_payments
				WHERE account_id = %d AND status = 'completed'",
				$account->id
			)
		);

		ob_start();
		include ARM_RE_PLUGIN_DIR . 'templates/customer/credit-account.php';
		return ob_get_clean();
	}

	/**
	 * Get customer ID from user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int|null Customer ID or null.
	 */
	private static function get_customer_id_from_user( $user_id ) {
		global $wpdb;
		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}arm_customers WHERE email = (SELECT user_email FROM {$wpdb->users} WHERE ID = %d)",
				$user_id
			)
		);
		return $customer ? $customer->id : null;
	}

	/**
	 * AJAX: Get credit history.
	 */
	public static function ajax_get_history() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'arm-repair-estimates' ) ) );
		}

		check_ajax_referer( 'arm_credit_customer_ajax', 'nonce' );

		global $wpdb;
		$user        = wp_get_current_user();
		$customer_id = self::get_customer_id_from_user( $user->ID );

		if ( ! $customer_id ) {
			wp_send_json_error( array( 'message' => __( 'Customer not found.', 'arm-repair-estimates' ) ) );
		}

		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}arm_credit_accounts WHERE customer_id = %d",
				$customer_id
			)
		);

		if ( ! $account ) {
			wp_send_json_error( array( 'message' => __( 'Account not found.', 'arm-repair-estimates' ) ) );
		}

		$limit  = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 50;
		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

		$transactions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_transactions
				WHERE account_id = %d
				ORDER BY transaction_date DESC
				LIMIT %d OFFSET %d",
				$account->id,
				$limit,
				$offset
			)
		);

		wp_send_json_success( $transactions );
	}
}
