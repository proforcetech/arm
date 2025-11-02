<?php
/**
 * Credit Management Controller
 *
 * @package ARM_Repair_Estimates
 */

namespace ARM\Credit;

/**
 * Main controller for credit management.
 */
class Controller {
	/**
	 * Bootstrap the module.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_arm_save_credit_account', array( __CLASS__, 'handle_save_account' ) );
		add_action( 'admin_post_arm_save_credit_transaction', array( __CLASS__, 'handle_save_transaction' ) );
		add_action( 'admin_post_arm_save_credit_payment', array( __CLASS__, 'handle_save_payment' ) );
		add_action( 'wp_ajax_arm_get_credit_account', array( __CLASS__, 'ajax_get_account' ) );
		add_action( 'wp_ajax_arm_get_credit_transactions', array( __CLASS__, 'ajax_get_transactions' ) );
	}

	/**
	 * Register admin menu.
	 */
	public static function register_menu() {
		add_submenu_page(
			'arm-repair-estimates',
			__( 'Credit Accounts', 'arm-repair-estimates' ),
			__( 'Credit Accounts', 'arm-repair-estimates' ),
			'manage_options',
			'arm-credit-accounts',
			array( __CLASS__, 'render_admin' )
		);
	}

	/**
	 * Render admin page.
	 */
	public static function render_admin() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		$id     = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;

		switch ( $action ) {
			case 'edit':
			case 'new':
				self::render_form( $id );
				break;
			case 'view':
				self::render_view( $id );
				break;
			default:
				self::render_list();
				break;
		}
	}

	/**
	 * Render list of credit accounts.
	 */
	private static function render_list() {
		global $wpdb;

		// Get all credit accounts with customer info
		$accounts = $wpdb->get_results(
			"SELECT
				ca.*,
				c.first_name,
				c.last_name,
				c.email,
				c.phone
			FROM {$wpdb->prefix}arm_credit_accounts ca
			LEFT JOIN {$wpdb->prefix}arm_customers c ON ca.customer_id = c.id
			ORDER BY ca.updated_at DESC"
		);

		include ARM_RE_PLUGIN_DIR . 'templates/admin/credit-accounts-list.php';
	}

	/**
	 * Render form for creating/editing credit account.
	 *
	 * @param int $id Account ID.
	 */
	private static function render_form( $id = 0 ) {
		global $wpdb;

		$account = null;
		if ( $id ) {
			$account = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}arm_credit_accounts WHERE id = %d",
					$id
				)
			);
		}

		// Get all customers for dropdown
		$customers = $wpdb->get_results(
			"SELECT id, first_name, last_name, email
			FROM {$wpdb->prefix}arm_customers
			ORDER BY last_name, first_name"
		);

		include ARM_RE_PLUGIN_DIR . 'templates/admin/credit-account-form.php';
	}

	/**
	 * Render detailed view of a credit account.
	 *
	 * @param int $id Account ID.
	 */
	private static function render_view( $id ) {
		global $wpdb;

		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ca.*, c.first_name, c.last_name, c.email, c.phone, c.address, c.city, c.state, c.zip
				FROM {$wpdb->prefix}arm_credit_accounts ca
				LEFT JOIN {$wpdb->prefix}arm_customers c ON ca.customer_id = c.id
				WHERE ca.id = %d",
				$id
			)
		);

		if ( ! $account ) {
			wp_die( __( 'Credit account not found.', 'arm-repair-estimates' ) );
		}

		// Get transactions
		$transactions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_transactions
				WHERE account_id = %d
				ORDER BY transaction_date DESC, id DESC",
				$id
			)
		);

		// Get payments
		$payments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_payments
				WHERE account_id = %d
				ORDER BY payment_date DESC, id DESC",
				$id
			)
		);

		// Get reminders
		$reminders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_reminders
				WHERE account_id = %d
				ORDER BY sent_at DESC",
				$id
			)
		);

		include ARM_RE_PLUGIN_DIR . 'templates/admin/credit-account-view.php';
	}

	/**
	 * Handle save credit account.
	 */
	public static function handle_save_account() {
		check_admin_referer( 'arm_save_credit_account' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'arm-repair-estimates' ) );
		}

		global $wpdb;

		$id               = isset( $_POST['account_id'] ) ? intval( $_POST['account_id'] ) : 0;
		$customer_id      = intval( $_POST['customer_id'] );
		$credit_limit     = floatval( $_POST['credit_limit'] );
		$current_balance  = isset( $_POST['current_balance'] ) ? floatval( $_POST['current_balance'] ) : 0.00;
		$status           = sanitize_text_field( $_POST['status'] );
		$payment_term_type = isset( $_POST['payment_term_type'] ) ? sanitize_text_field( $_POST['payment_term_type'] ) : 'net_terms';
		$payment_terms    = intval( $_POST['payment_terms'] );
		$notes            = sanitize_textarea_field( $_POST['notes'] );

		$available_credit = $credit_limit - $current_balance;

		$data = array(
			'customer_id'       => $customer_id,
			'credit_limit'      => $credit_limit,
			'current_balance'   => $current_balance,
			'available_credit'  => $available_credit,
			'status'            => $status,
			'payment_term_type' => $payment_term_type,
			'payment_terms'     => $payment_terms,
			'notes'             => $notes,
		);

		if ( $id ) {
			// Update existing
			$wpdb->update(
				$wpdb->prefix . 'arm_credit_accounts',
				$data,
				array( 'id' => $id ),
				array( '%d', '%f', '%f', '%f', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			// Insert new
			$wpdb->insert(
				$wpdb->prefix . 'arm_credit_accounts',
				$data,
				array( '%d', '%f', '%f', '%f', '%s', '%s', '%d', '%s' )
			);
			$id = $wpdb->insert_id;
		}

		wp_redirect(
			add_query_arg(
				array(
					'page'   => 'arm-credit-accounts',
					'action' => 'view',
					'id'     => $id,
					'msg'    => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle save credit transaction.
	 */
	public static function handle_save_transaction() {
		check_admin_referer( 'arm_save_credit_transaction' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'arm-repair-estimates' ) );
		}

		global $wpdb;

		$account_id       = intval( $_POST['account_id'] );
		$transaction_type = sanitize_text_field( $_POST['transaction_type'] );
		$amount           = floatval( $_POST['amount'] );
		$description      = sanitize_textarea_field( $_POST['description'] );
		$reference_type   = ! empty( $_POST['reference_type'] ) ? sanitize_text_field( $_POST['reference_type'] ) : null;
		$reference_id     = ! empty( $_POST['reference_id'] ) ? intval( $_POST['reference_id'] ) : null;

		// Get account details
		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_accounts WHERE id = %d",
				$account_id
			)
		);

		if ( ! $account ) {
			wp_die( __( 'Account not found.', 'arm-repair-estimates' ) );
		}

		// Calculate new balance
		$current_balance = floatval( $account->current_balance );
		if ( in_array( $transaction_type, array( 'charge', 'adjustment' ), true ) ) {
			$new_balance = $current_balance + $amount;
		} else {
			// payment or refund
			$new_balance = $current_balance - $amount;
		}

		// Insert transaction
		$wpdb->insert(
			$wpdb->prefix . 'arm_credit_transactions',
			array(
				'account_id'       => $account_id,
				'customer_id'      => $account->customer_id,
				'transaction_type' => $transaction_type,
				'amount'           => $amount,
				'balance_after'    => $new_balance,
				'reference_type'   => $reference_type,
				'reference_id'     => $reference_id,
				'description'      => $description,
				'created_by'       => get_current_user_id(),
			),
			array( '%d', '%d', '%s', '%f', '%f', '%s', '%d', '%s', '%d' )
		);

		// Update account balance
		$available_credit = floatval( $account->credit_limit ) - $new_balance;
		$wpdb->update(
			$wpdb->prefix . 'arm_credit_accounts',
			array(
				'current_balance'  => $new_balance,
				'available_credit' => $available_credit,
			),
			array( 'id' => $account_id ),
			array( '%f', '%f' ),
			array( '%d' )
		);

		wp_redirect(
			add_query_arg(
				array(
					'page'   => 'arm-credit-accounts',
					'action' => 'view',
					'id'     => $account_id,
					'msg'    => 'transaction_added',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle save credit payment.
	 */
	public static function handle_save_payment() {
		check_admin_referer( 'arm_save_credit_payment' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'arm-repair-estimates' ) );
		}

		global $wpdb;

		$account_id       = intval( $_POST['account_id'] );
		$payment_method   = sanitize_text_field( $_POST['payment_method'] );
		$amount           = floatval( $_POST['amount'] );
		$payment_date     = sanitize_text_field( $_POST['payment_date'] );
		$reference_number = sanitize_text_field( $_POST['reference_number'] );
		$notes            = sanitize_textarea_field( $_POST['notes'] );

		// Get account details
		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_accounts WHERE id = %d",
				$account_id
			)
		);

		if ( ! $account ) {
			wp_die( __( 'Account not found.', 'arm-repair-estimates' ) );
		}

		// Insert payment record
		$wpdb->insert(
			$wpdb->prefix . 'arm_credit_payments',
			array(
				'account_id'       => $account_id,
				'customer_id'      => $account->customer_id,
				'payment_method'   => $payment_method,
				'amount'           => $amount,
				'payment_date'     => $payment_date,
				'reference_number' => $reference_number,
				'notes'            => $notes,
				'processed_by'     => get_current_user_id(),
				'status'           => 'completed',
			),
			array( '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s' )
		);

		// Create corresponding transaction
		$new_balance      = floatval( $account->current_balance ) - $amount;
		$available_credit = floatval( $account->credit_limit ) - $new_balance;

		$wpdb->insert(
			$wpdb->prefix . 'arm_credit_transactions',
			array(
				'account_id'       => $account_id,
				'customer_id'      => $account->customer_id,
				'transaction_type' => 'payment',
				'amount'           => $amount,
				'balance_after'    => $new_balance,
				'reference_type'   => 'payment',
				'reference_id'     => $wpdb->insert_id,
				'description'      => sprintf( 'Payment via %s - Ref: %s', $payment_method, $reference_number ),
				'created_by'       => get_current_user_id(),
			),
			array( '%d', '%d', '%s', '%f', '%f', '%s', '%d', '%s', '%d' )
		);

		// Update account
		$wpdb->update(
			$wpdb->prefix . 'arm_credit_accounts',
			array(
				'current_balance'   => $new_balance,
				'available_credit'  => $available_credit,
				'last_payment_date' => $payment_date,
			),
			array( 'id' => $account_id ),
			array( '%f', '%f', '%s' ),
			array( '%d' )
		);

		wp_redirect(
			add_query_arg(
				array(
					'page'   => 'arm-credit-accounts',
					'action' => 'view',
					'id'     => $account_id,
					'msg'    => 'payment_added',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX: Get credit account details.
	 */
	public static function ajax_get_account() {
		check_ajax_referer( 'arm_credit_ajax', 'nonce' );

		$account_id = intval( $_POST['account_id'] );

		global $wpdb;
		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_accounts WHERE id = %d",
				$account_id
			)
		);

		if ( ! $account ) {
			wp_send_json_error( array( 'message' => __( 'Account not found.', 'arm-repair-estimates' ) ) );
		}

		wp_send_json_success( $account );
	}

	/**
	 * AJAX: Get credit transactions.
	 */
	public static function ajax_get_transactions() {
		check_ajax_referer( 'arm_credit_ajax', 'nonce' );

		$account_id = intval( $_POST['account_id'] );

		global $wpdb;
		$transactions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_transactions
				WHERE account_id = %d
				ORDER BY transaction_date DESC, id DESC",
				$account_id
			)
		);

		wp_send_json_success( $transactions );
	}

	/**
	 * Get credit account by customer ID.
	 *
	 * @param int $customer_id Customer ID.
	 * @return object|null
	 */
	public static function get_account_by_customer( $customer_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}arm_credit_accounts WHERE customer_id = %d",
				$customer_id
			)
		);
	}

	/**
	 * Create charge on credit account.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param float  $amount Amount to charge.
	 * @param string $description Description.
	 * @param string $reference_type Reference type (invoice, estimate, etc).
	 * @param int    $reference_id Reference ID.
	 * @return bool|int Transaction ID on success, false on failure.
	 */
	public static function create_charge( $customer_id, $amount, $description, $reference_type = null, $reference_id = null ) {
		global $wpdb;

		$account = self::get_account_by_customer( $customer_id );
		if ( ! $account || $account->status !== 'active' ) {
			return false;
		}

		$new_balance      = floatval( $account->current_balance ) + $amount;
		$available_credit = floatval( $account->credit_limit ) - $new_balance;

		// Insert transaction
		$wpdb->insert(
			$wpdb->prefix . 'arm_credit_transactions',
			array(
				'account_id'       => $account->id,
				'customer_id'      => $customer_id,
				'transaction_type' => 'charge',
				'amount'           => $amount,
				'balance_after'    => $new_balance,
				'reference_type'   => $reference_type,
				'reference_id'     => $reference_id,
				'description'      => $description,
				'created_by'       => get_current_user_id(),
			),
			array( '%d', '%d', '%s', '%f', '%f', '%s', '%d', '%s', '%d' )
		);

		// Update account
		$wpdb->update(
			$wpdb->prefix . 'arm_credit_accounts',
			array(
				'current_balance'  => $new_balance,
				'available_credit' => $available_credit,
			),
			array( 'id' => $account->id ),
			array( '%f', '%f' ),
			array( '%d' )
		);

		return $wpdb->insert_id;
	}
}
