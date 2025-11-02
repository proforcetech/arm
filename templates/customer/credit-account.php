<?php
/**
 * Customer: Credit Account View
 *
 * @package ARM_Repair_Estimates
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="arm-credit-account">
	<h2><?php esc_html_e( 'My Credit Account', 'arm-repair-estimates' ); ?></h2>

	<!-- Credit Summary -->
	<div class="arm-credit-summary">
		<div class="summary-card">
			<div class="card-label"><?php esc_html_e( 'Credit Limit', 'arm-repair-estimates' ); ?></div>
			<div class="card-value"><?php echo esc_html( '$' . number_format( $account->credit_limit, 2 ) ); ?></div>
		</div>
		<div class="summary-card balance">
			<div class="card-label"><?php esc_html_e( 'Current Balance', 'arm-repair-estimates' ); ?></div>
			<div class="card-value"><?php echo esc_html( '$' . number_format( $account->current_balance, 2 ) ); ?></div>
		</div>
		<div class="summary-card available">
			<div class="card-label"><?php esc_html_e( 'Available Credit', 'arm-repair-estimates' ); ?></div>
			<div class="card-value"><?php echo esc_html( '$' . number_format( $account->available_credit, 2 ) ); ?></div>
		</div>
	</div>

	<!-- Account Details -->
	<div class="arm-credit-details">
		<h3><?php esc_html_e( 'Account Details', 'arm-repair-estimates' ); ?></h3>
		<table class="arm-details-table">
			<tr>
				<td><strong><?php esc_html_e( 'Account Status:', 'arm-repair-estimates' ); ?></strong></td>
				<td>
					<span class="status-badge status-<?php echo esc_attr( $account->status ); ?>">
						<?php echo esc_html( ucfirst( $account->status ) ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Payment Terms:', 'arm-repair-estimates' ); ?></strong></td>
				<td>
					<?php
					$term_type = isset( $account->payment_term_type ) ? $account->payment_term_type : 'net_terms';
					switch ( $term_type ) {
						case 'same_as_cash':
							echo esc_html( sprintf( __( 'Same-as-Cash (%d days)', 'arm-repair-estimates' ), $account->payment_terms ) );
							break;
						case 'revolving':
							echo esc_html__( 'Revolving', 'arm-repair-estimates' );
							break;
						case 'net_terms':
						default:
							echo esc_html( sprintf( __( 'Net %d days', 'arm-repair-estimates' ), $account->payment_terms ) );
							break;
					}
					?>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Last Payment:', 'arm-repair-estimates' ); ?></strong></td>
				<td>
					<?php
					echo $account->last_payment_date
						? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $account->last_payment_date ) ) )
						: esc_html__( 'No payments yet', 'arm-repair-estimates' );
					?>
				</td>
			</tr>
			<?php if ( $payment_summary->total_payments > 0 ) : ?>
				<tr>
					<td><strong><?php esc_html_e( 'Total Payments:', 'arm-repair-estimates' ); ?></strong></td>
					<td><?php echo esc_html( $payment_summary->total_payments . ' payments totaling $' . number_format( $payment_summary->total_paid, 2 ) ); ?></td>
				</tr>
			<?php endif; ?>
		</table>
	</div>

	<!-- Transaction History -->
	<div class="arm-credit-transactions">
		<h3><?php esc_html_e( 'Recent Transactions', 'arm-repair-estimates' ); ?></h3>
		<?php if ( empty( $transactions ) ) : ?>
			<p class="no-data"><?php esc_html_e( 'No transactions yet.', 'arm-repair-estimates' ); ?></p>
		<?php else : ?>
			<table class="arm-transactions-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Type', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Description', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'arm-repair-estimates' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'arm-repair-estimates' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $transactions as $txn ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $txn->transaction_date ) ) ); ?></td>
							<td>
								<span class="txn-type txn-<?php echo esc_attr( $txn->transaction_type ); ?>">
									<?php echo esc_html( ucfirst( str_replace( '_', ' ', $txn->transaction_type ) ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $txn->description ); ?></td>
							<td class="txn-amount <?php echo in_array( $txn->transaction_type, array( 'charge', 'adjustment' ) ) ? 'debit' : 'credit'; ?>">
								<?php echo in_array( $txn->transaction_type, array( 'charge', 'adjustment' ) ) ? '+' : '-'; ?>
								<?php echo esc_html( '$' . number_format( $txn->amount, 2 ) ); ?>
							</td>
							<td><?php echo esc_html( '$' . number_format( $txn->balance_after, 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Payment Information -->
	<div class="arm-payment-info">
		<h3><?php esc_html_e( 'Need to Make a Payment?', 'arm-repair-estimates' ); ?></h3>
		<p><?php esc_html_e( 'Please contact us to make a payment on your account or set up automatic payments.', 'arm-repair-estimates' ); ?></p>
		<p>
			<a href="mailto:<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="arm-button">
				<?php esc_html_e( 'Contact Us About Payment', 'arm-repair-estimates' ); ?>
			</a>
		</p>
	</div>
</div>

<style>
.arm-credit-account {
	max-width: 1200px;
	margin: 0 auto;
	padding: 20px;
}
.arm-credit-summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 20px;
	margin-bottom: 30px;
}
.summary-card {
	background: #fff;
	border: 2px solid #e0e0e0;
	border-radius: 8px;
	padding: 20px;
	text-align: center;
}
.summary-card.balance {
	border-color: #d63638;
}
.summary-card.available {
	border-color: #00a32a;
}
.card-label {
	font-size: 14px;
	color: #666;
	margin-bottom: 10px;
	font-weight: 600;
}
.card-value {
	font-size: 28px;
	font-weight: bold;
	color: #333;
}
.summary-card.balance .card-value {
	color: #d63638;
}
.summary-card.available .card-value {
	color: #00a32a;
}
.arm-credit-details,
.arm-credit-transactions,
.arm-payment-info {
	background: #fff;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 20px;
}
.arm-credit-details h3,
.arm-credit-transactions h3,
.arm-payment-info h3 {
	margin-top: 0;
	color: #333;
	border-bottom: 2px solid #e0e0e0;
	padding-bottom: 10px;
}
.arm-details-table {
	width: 100%;
	border-collapse: collapse;
}
.arm-details-table td {
	padding: 10px 0;
	border-bottom: 1px solid #f0f0f0;
}
.arm-details-table td:first-child {
	width: 200px;
}
.status-badge {
	display: inline-block;
	padding: 4px 12px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}
.status-active {
	background: #d4edda;
	color: #155724;
}
.status-inactive {
	background: #f8d7da;
	color: #721c24;
}
.status-suspended {
	background: #fff3cd;
	color: #856404;
}
.arm-transactions-table {
	width: 100%;
	border-collapse: collapse;
}
.arm-transactions-table thead {
	background: #f8f9fa;
}
.arm-transactions-table th,
.arm-transactions-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid #e0e0e0;
}
.arm-transactions-table th {
	font-weight: 600;
	color: #333;
}
.txn-type {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}
.txn-charge,
.txn-adjustment {
	background: #f8d7da;
	color: #721c24;
}
.txn-payment,
.txn-refund {
	background: #d4edda;
	color: #155724;
}
.txn-amount.debit {
	color: #d63638;
	font-weight: 600;
}
.txn-amount.credit {
	color: #00a32a;
	font-weight: 600;
}
.no-data {
	text-align: center;
	padding: 40px;
	color: #666;
	font-style: italic;
}
.arm-button {
	display: inline-block;
	padding: 10px 20px;
	background: #2271b1;
	color: #fff;
	text-decoration: none;
	border-radius: 4px;
	font-weight: 600;
	transition: background 0.3s;
}
.arm-button:hover {
	background: #135e96;
	color: #fff;
}
@media (max-width: 768px) {
	.arm-transactions-table {
		font-size: 14px;
	}
	.arm-transactions-table th,
	.arm-transactions-table td {
		padding: 8px;
	}
}
</style>
