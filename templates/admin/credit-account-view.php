<?php
/**
 * Admin: Credit Account Detail View
 *
 * @package ARM_Repair_Estimates
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap">
	<h1>
		<?php esc_html_e( 'Credit Account Details', 'arm-repair-estimates' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=arm-credit-accounts&action=edit&id=' . $account->id ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Edit', 'arm-repair-estimates' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=arm-credit-accounts' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Back to List', 'arm-repair-estimates' ); ?>
		</a>
	</h1>

	<?php if ( isset( $_GET['msg'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ( $_GET['msg'] === 'saved' ) {
					esc_html_e( 'Credit account saved successfully.', 'arm-repair-estimates' );
				} elseif ( $_GET['msg'] === 'transaction_added' ) {
					esc_html_e( 'Transaction added successfully.', 'arm-repair-estimates' );
				} elseif ( $_GET['msg'] === 'payment_added' ) {
					esc_html_e( 'Payment recorded successfully.', 'arm-repair-estimates' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<div style="display: flex; gap: 20px; margin-top: 20px;">
		<!-- Left Column: Account Info -->
		<div style="flex: 1;">
			<div class="postbox">
				<div class="postbox-header">
					<h2><?php esc_html_e( 'Account Information', 'arm-repair-estimates' ); ?></h2>
				</div>
				<div class="inside">
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Customer', 'arm-repair-estimates' ); ?></th>
							<td>
								<strong><?php echo esc_html( $account->first_name . ' ' . $account->last_name ); ?></strong><br />
								<?php echo esc_html( $account->email ); ?><br />
								<?php echo esc_html( $account->phone ); ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'arm-repair-estimates' ); ?></th>
							<td>
								<span class="badge badge-<?php echo esc_attr( $account->status ); ?>">
									<?php echo esc_html( ucfirst( $account->status ) ); ?>
								</span>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Payment Terms', 'arm-repair-estimates' ); ?></th>
							<td><?php echo esc_html( sprintf( __( 'Net %d days', 'arm-repair-estimates' ), $account->payment_terms ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Last Payment', 'arm-repair-estimates' ); ?></th>
							<td>
								<?php
								echo $account->last_payment_date
									? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $account->last_payment_date ) ) )
									: '—';
								?>
							</td>
						</tr>
						<?php if ( $account->notes ) : ?>
							<tr>
								<th><?php esc_html_e( 'Notes', 'arm-repair-estimates' ); ?></th>
								<td><?php echo wp_kses_post( nl2br( $account->notes ) ); ?></td>
							</tr>
						<?php endif; ?>
					</table>
				</div>
			</div>
		</div>

		<!-- Right Column: Credit Summary -->
		<div style="flex: 1;">
			<div class="postbox">
				<div class="postbox-header">
					<h2><?php esc_html_e( 'Credit Summary', 'arm-repair-estimates' ); ?></h2>
				</div>
				<div class="inside">
					<div class="credit-summary-box">
						<div class="summary-item">
							<span class="label"><?php esc_html_e( 'Credit Limit', 'arm-repair-estimates' ); ?></span>
							<span class="value"><?php echo esc_html( '$' . number_format( $account->credit_limit, 2 ) ); ?></span>
						</div>
						<div class="summary-item">
							<span class="label"><?php esc_html_e( 'Current Balance', 'arm-repair-estimates' ); ?></span>
							<span class="value balance"><?php echo esc_html( '$' . number_format( $account->current_balance, 2 ) ); ?></span>
						</div>
						<div class="summary-item">
							<span class="label"><?php esc_html_e( 'Available Credit', 'arm-repair-estimates' ); ?></span>
							<span class="value available"><?php echo esc_html( '$' . number_format( $account->available_credit, 2 ) ); ?></span>
						</div>
					</div>
				</div>
			</div>

			<div class="postbox" style="margin-top: 20px;">
				<div class="postbox-header">
					<h2><?php esc_html_e( 'Quick Actions', 'arm-repair-estimates' ); ?></h2>
				</div>
				<div class="inside">
					<p>
						<button type="button" class="button button-primary" onclick="showAddTransactionModal()">
							<?php esc_html_e( 'Add Transaction', 'arm-repair-estimates' ); ?>
						</button>
						<button type="button" class="button button-secondary" onclick="showAddPaymentModal()">
							<?php esc_html_e( 'Record Payment', 'arm-repair-estimates' ); ?>
						</button>
					</p>
				</div>
			</div>
		</div>
	</div>

	<!-- Transactions Table -->
	<div class="postbox" style="margin-top: 20px;">
		<div class="postbox-header">
			<h2><?php esc_html_e( 'Transaction History', 'arm-repair-estimates' ); ?></h2>
		</div>
		<div class="inside">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Type', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Description', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Balance After', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Reference', 'arm-repair-estimates' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $transactions ) ) : ?>
						<tr>
							<td colspan="6" style="text-align: center;">
								<?php esc_html_e( 'No transactions yet.', 'arm-repair-estimates' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $transactions as $txn ) : ?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $txn->transaction_date ) ) ); ?></td>
								<td>
									<span class="badge badge-<?php echo esc_attr( $txn->transaction_type ); ?>">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', $txn->transaction_type ) ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $txn->description ); ?></td>
								<td>
									<strong style="color: <?php echo in_array( $txn->transaction_type, array( 'charge', 'adjustment' ) ) ? '#d63638' : '#00a32a'; ?>">
										<?php echo in_array( $txn->transaction_type, array( 'charge', 'adjustment' ) ) ? '+' : '-'; ?>
										<?php echo esc_html( '$' . number_format( $txn->amount, 2 ) ); ?>
									</strong>
								</td>
								<td><?php echo esc_html( '$' . number_format( $txn->balance_after, 2 ) ); ?></td>
								<td>
									<?php if ( $txn->reference_type && $txn->reference_id ) : ?>
										<?php echo esc_html( ucfirst( $txn->reference_type ) . ' #' . $txn->reference_id ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Payments Table -->
	<div class="postbox" style="margin-top: 20px;">
		<div class="postbox-header">
			<h2><?php esc_html_e( 'Payment History', 'arm-repair-estimates' ); ?></h2>
		</div>
		<div class="inside">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Payment Date', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Method', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Reference', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Status', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'arm-repair-estimates' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $payments ) ) : ?>
						<tr>
							<td colspan="6" style="text-align: center;">
								<?php esc_html_e( 'No payments yet.', 'arm-repair-estimates' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $payments as $payment ) : ?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $payment->payment_date ) ) ); ?></td>
								<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->payment_method ) ) ); ?></td>
								<td><strong><?php echo esc_html( '$' . number_format( $payment->amount, 2 ) ); ?></strong></td>
								<td><?php echo esc_html( $payment->reference_number ?: '—' ); ?></td>
								<td>
									<span class="badge badge-<?php echo esc_attr( $payment->status ); ?>">
										<?php echo esc_html( ucfirst( $payment->status ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $payment->notes ?: '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<!-- Add Transaction Modal -->
<div id="transaction-modal" class="arm-modal" style="display: none;">
	<div class="arm-modal-content">
		<span class="arm-modal-close" onclick="closeModal('transaction-modal')">&times;</span>
		<h2><?php esc_html_e( 'Add Transaction', 'arm-repair-estimates' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'arm_save_credit_transaction' ); ?>
			<input type="hidden" name="action" value="arm_save_credit_transaction" />
			<input type="hidden" name="account_id" value="<?php echo esc_attr( $account->id ); ?>" />

			<table class="form-table">
				<tr>
					<th><label for="transaction_type"><?php esc_html_e( 'Type', 'arm-repair-estimates' ); ?></label></th>
					<td>
						<select name="transaction_type" id="transaction_type" required class="regular-text">
							<option value="charge"><?php esc_html_e( 'Charge', 'arm-repair-estimates' ); ?></option>
							<option value="payment"><?php esc_html_e( 'Payment', 'arm-repair-estimates' ); ?></option>
							<option value="adjustment"><?php esc_html_e( 'Adjustment', 'arm-repair-estimates' ); ?></option>
							<option value="refund"><?php esc_html_e( 'Refund', 'arm-repair-estimates' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="amount"><?php esc_html_e( 'Amount', 'arm-repair-estimates' ); ?></label></th>
					<td><input type="number" name="amount" id="amount" step="0.01" min="0" required class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="description"><?php esc_html_e( 'Description', 'arm-repair-estimates' ); ?></label></th>
					<td><textarea name="description" id="description" rows="3" required class="large-text"></textarea></td>
				</tr>
				<tr>
					<th><label for="reference_type"><?php esc_html_e( 'Reference Type', 'arm-repair-estimates' ); ?></label></th>
					<td>
						<select name="reference_type" id="reference_type" class="regular-text">
							<option value=""><?php esc_html_e( 'None', 'arm-repair-estimates' ); ?></option>
							<option value="invoice"><?php esc_html_e( 'Invoice', 'arm-repair-estimates' ); ?></option>
							<option value="estimate"><?php esc_html_e( 'Estimate', 'arm-repair-estimates' ); ?></option>
							<option value="manual"><?php esc_html_e( 'Manual', 'arm-repair-estimates' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="reference_id"><?php esc_html_e( 'Reference ID', 'arm-repair-estimates' ); ?></label></th>
					<td><input type="number" name="reference_id" id="reference_id" class="regular-text" /></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Transaction', 'arm-repair-estimates' ); ?></button>
				<button type="button" class="button" onclick="closeModal('transaction-modal')"><?php esc_html_e( 'Cancel', 'arm-repair-estimates' ); ?></button>
			</p>
		</form>
	</div>
</div>

<!-- Add Payment Modal -->
<div id="payment-modal" class="arm-modal" style="display: none;">
	<div class="arm-modal-content">
		<span class="arm-modal-close" onclick="closeModal('payment-modal')">&times;</span>
		<h2><?php esc_html_e( 'Record Payment', 'arm-repair-estimates' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'arm_save_credit_payment' ); ?>
			<input type="hidden" name="action" value="arm_save_credit_payment" />
			<input type="hidden" name="account_id" value="<?php echo esc_attr( $account->id ); ?>" />

			<table class="form-table">
				<tr>
					<th><label for="payment_method"><?php esc_html_e( 'Payment Method', 'arm-repair-estimates' ); ?></label></th>
					<td>
						<select name="payment_method" id="payment_method" required class="regular-text">
							<option value="cash"><?php esc_html_e( 'Cash', 'arm-repair-estimates' ); ?></option>
							<option value="check"><?php esc_html_e( 'Check', 'arm-repair-estimates' ); ?></option>
							<option value="card"><?php esc_html_e( 'Credit/Debit Card', 'arm-repair-estimates' ); ?></option>
							<option value="bank_transfer"><?php esc_html_e( 'Bank Transfer', 'arm-repair-estimates' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'arm-repair-estimates' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="payment_amount"><?php esc_html_e( 'Amount', 'arm-repair-estimates' ); ?></label></th>
					<td><input type="number" name="amount" id="payment_amount" step="0.01" min="0" required class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="payment_date"><?php esc_html_e( 'Payment Date', 'arm-repair-estimates' ); ?></label></th>
					<td><input type="datetime-local" name="payment_date" id="payment_date" required class="regular-text" value="<?php echo esc_attr( date( 'Y-m-d\TH:i' ) ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="reference_number"><?php esc_html_e( 'Reference Number', 'arm-repair-estimates' ); ?></label></th>
					<td><input type="text" name="reference_number" id="reference_number" class="regular-text" placeholder="<?php esc_attr_e( 'Check #, Transaction ID, etc.', 'arm-repair-estimates' ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="payment_notes"><?php esc_html_e( 'Notes', 'arm-repair-estimates' ); ?></label></th>
					<td><textarea name="notes" id="payment_notes" rows="3" class="large-text"></textarea></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Record Payment', 'arm-repair-estimates' ); ?></button>
				<button type="button" class="button" onclick="closeModal('payment-modal')"><?php esc_html_e( 'Cancel', 'arm-repair-estimates' ); ?></button>
			</p>
		</form>
	</div>
</div>

<style>
.credit-summary-box {
	padding: 10px 0;
}
.summary-item {
	display: flex;
	justify-content: space-between;
	padding: 10px 0;
	border-bottom: 1px solid #ddd;
}
.summary-item:last-child {
	border-bottom: none;
}
.summary-item .label {
	font-weight: 600;
}
.summary-item .value {
	font-size: 18px;
	font-weight: bold;
}
.summary-item .value.balance {
	color: #d63638;
}
.summary-item .value.available {
	color: #00a32a;
}
.badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.badge-active, .badge-completed {
	background: #d4edda;
	color: #155724;
}
.badge-inactive, .badge-failed {
	background: #f8d7da;
	color: #721c24;
}
.badge-suspended, .badge-pending {
	background: #fff3cd;
	color: #856404;
}
.badge-charge, .badge-adjustment {
	background: #f8d7da;
	color: #721c24;
}
.badge-payment, .badge-refund {
	background: #d4edda;
	color: #155724;
}
.arm-modal {
	position: fixed;
	z-index: 100000;
	left: 0;
	top: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0,0,0,0.4);
}
.arm-modal-content {
	background-color: #fefefe;
	margin: 5% auto;
	padding: 20px;
	border: 1px solid #888;
	width: 80%;
	max-width: 600px;
	border-radius: 5px;
}
.arm-modal-close {
	color: #aaa;
	float: right;
	font-size: 28px;
	font-weight: bold;
	cursor: pointer;
}
.arm-modal-close:hover,
.arm-modal-close:focus {
	color: black;
}
</style>

<script>
function showAddTransactionModal() {
	document.getElementById('transaction-modal').style.display = 'block';
}
function showAddPaymentModal() {
	document.getElementById('payment-modal').style.display = 'block';
}
function closeModal(modalId) {
	document.getElementById(modalId).style.display = 'none';
}
window.onclick = function(event) {
	if (event.target.classList.contains('arm-modal')) {
		event.target.style.display = 'none';
	}
}
</script>
