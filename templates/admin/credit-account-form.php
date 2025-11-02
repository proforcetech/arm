<?php
/**
 * Admin: Credit Account Form
 *
 * @package ARM_Repair_Estimates
 */

defined( 'ABSPATH' ) || exit;

$is_edit = ! empty( $account );
?>

<div class="wrap">
	<h1><?php echo $is_edit ? esc_html__( 'Edit Credit Account', 'arm-repair-estimates' ) : esc_html__( 'New Credit Account', 'arm-repair-estimates' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'arm_save_credit_account' ); ?>
		<input type="hidden" name="action" value="arm_save_credit_account" />
		<?php if ( $is_edit ) : ?>
			<input type="hidden" name="account_id" value="<?php echo esc_attr( $account->id ); ?>" />
		<?php endif; ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="customer_id"><?php esc_html_e( 'Customer', 'arm-repair-estimates' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<?php if ( $is_edit ) : ?>
						<?php
						$customer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}arm_customers WHERE id = %d", $account->customer_id ) );
						?>
						<strong><?php echo esc_html( $customer->first_name . ' ' . $customer->last_name . ' (' . $customer->email . ')' ); ?></strong>
						<input type="hidden" name="customer_id" value="<?php echo esc_attr( $account->customer_id ); ?>" />
					<?php else : ?>
						<select name="customer_id" id="customer_id" required class="regular-text">
							<option value=""><?php esc_html_e( 'Select Customer', 'arm-repair-estimates' ); ?></option>
							<?php foreach ( $customers as $cust ) : ?>
								<option value="<?php echo esc_attr( $cust->id ); ?>">
									<?php echo esc_html( $cust->first_name . ' ' . $cust->last_name . ' (' . $cust->email . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Select the customer for this credit account.', 'arm-repair-estimates' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="credit_limit"><?php esc_html_e( 'Credit Limit', 'arm-repair-estimates' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="number" name="credit_limit" id="credit_limit" step="0.01" min="0"
						value="<?php echo $is_edit ? esc_attr( $account->credit_limit ) : '0.00'; ?>"
						required class="regular-text" />
					<p class="description"><?php esc_html_e( 'Maximum credit available to this customer.', 'arm-repair-estimates' ); ?></p>
				</td>
			</tr>

			<?php if ( $is_edit ) : ?>
				<tr>
					<th scope="row">
						<label for="current_balance"><?php esc_html_e( 'Current Balance', 'arm-repair-estimates' ); ?></label>
					</th>
					<td>
						<input type="number" name="current_balance" id="current_balance" step="0.01"
							value="<?php echo esc_attr( $account->current_balance ); ?>"
							class="regular-text" readonly />
						<p class="description"><?php esc_html_e( 'Current outstanding balance. Use transactions to modify this.', 'arm-repair-estimates' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>

			<tr>
				<th scope="row">
					<label for="payment_terms"><?php esc_html_e( 'Payment Terms (Days)', 'arm-repair-estimates' ); ?></label>
				</th>
				<td>
					<input type="number" name="payment_terms" id="payment_terms" min="0"
						value="<?php echo $is_edit ? esc_attr( $account->payment_terms ) : '30'; ?>"
						class="regular-text" />
					<p class="description"><?php esc_html_e( 'Net payment terms in days (e.g., Net 30).', 'arm-repair-estimates' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="status"><?php esc_html_e( 'Status', 'arm-repair-estimates' ); ?></label>
				</th>
				<td>
					<select name="status" id="status" class="regular-text">
						<option value="active" <?php selected( $is_edit ? $account->status : 'active', 'active' ); ?>>
							<?php esc_html_e( 'Active', 'arm-repair-estimates' ); ?>
						</option>
						<option value="inactive" <?php selected( $is_edit && $account->status, 'inactive' ); ?>>
							<?php esc_html_e( 'Inactive', 'arm-repair-estimates' ); ?>
						</option>
						<option value="suspended" <?php selected( $is_edit && $account->status, 'suspended' ); ?>>
							<?php esc_html_e( 'Suspended', 'arm-repair-estimates' ); ?>
						</option>
					</select>
					<p class="description"><?php esc_html_e( 'Account status. Suspended accounts cannot be charged.', 'arm-repair-estimates' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="notes"><?php esc_html_e( 'Notes', 'arm-repair-estimates' ); ?></label>
				</th>
				<td>
					<textarea name="notes" id="notes" rows="4" class="large-text"><?php echo $is_edit ? esc_textarea( $account->notes ) : ''; ?></textarea>
					<p class="description"><?php esc_html_e( 'Internal notes about this credit account.', 'arm-repair-estimates' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php echo $is_edit ? esc_html__( 'Update Account', 'arm-repair-estimates' ) : esc_html__( 'Create Account', 'arm-repair-estimates' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=arm-credit-accounts' ) ); ?>" class="button">
				<?php esc_html_e( 'Cancel', 'arm-repair-estimates' ); ?>
			</a>
		</p>
	</form>
</div>

<style>
.required {
	color: #d63638;
}
</style>
