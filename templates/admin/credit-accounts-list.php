<?php
/**
 * Admin: Credit Accounts List
 *
 * @package ARM_Repair_Estimates
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Credit Accounts', 'arm-repair-estimates' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=arm-credit-accounts&action=new' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Add New', 'arm-repair-estimates' ); ?>
	</a>
	<hr class="wp-heading-inline" />

	<?php if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'saved' ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Credit account saved successfully.', 'arm-repair-estimates' ); ?></p>
		</div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Customer', 'arm-repair-estimates' ); ?></th>
				<th><?php esc_html_e( 'Email', 'arm-repair-estimates' ); ?></th>
				<th><?php esc_html_e( 'Credit Limit', 'arm-repair-estimates' ); ?></th>
				<th><?php esc_html_e( 'Current Balance', 'arm-repair-estimates' ); ?></th>
				<th><?php esc_html_e( 'Available Credit', 'arm-repair-estimates' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arm-repair-estimates' ); ?></th>
				<th><?php esc_html_e( 'Last Payment', 'arm-repair-estimates' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arm-repair-estimates' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $accounts ) ) : ?>
				<tr>
					<td colspan="8" style="text-align: center;">
						<?php esc_html_e( 'No credit accounts found.', 'arm-repair-estimates' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $accounts as $account ) : ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=arm-credit-accounts&action=view&id=' . $account->id ) ); ?>">
									<?php echo esc_html( $account->first_name . ' ' . $account->last_name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $account->email ); ?></td>
						<td><?php echo esc_html( '$' . number_format( $account->credit_limit, 2 ) ); ?></td>
						<td>
							<strong style="color: <?php echo $account->current_balance > 0 ? '#d63638' : '#00a32a'; ?>">
								<?php echo esc_html( '$' . number_format( $account->current_balance, 2 ) ); ?>
							</strong>
						</td>
						<td><?php echo esc_html( '$' . number_format( $account->available_credit, 2 ) ); ?></td>
						<td>
							<span class="badge badge-<?php echo esc_attr( $account->status ); ?>">
								<?php echo esc_html( ucfirst( $account->status ) ); ?>
							</span>
						</td>
						<td>
							<?php
							echo $account->last_payment_date
								? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $account->last_payment_date ) ) )
								: '—';
							?>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=arm-credit-accounts&action=view&id=' . $account->id ) ); ?>" class="button button-small">
								<?php esc_html_e( 'View', 'arm-repair-estimates' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=arm-credit-accounts&action=edit&id=' . $account->id ) ); ?>" class="button button-small">
								<?php esc_html_e( 'Edit', 'arm-repair-estimates' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<style>
.badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.badge-active {
	background: #d4edda;
	color: #155724;
}
.badge-inactive {
	background: #f8d7da;
	color: #721c24;
}
.badge-suspended {
	background: #fff3cd;
	color: #856404;
}
</style>
