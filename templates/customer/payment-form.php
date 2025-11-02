<?php
/**
 * Customer: Payment Form
 *
 * @package ARM_Repair_Estimates
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="arm-payment-form-wrapper">
	<h2><?php esc_html_e( 'Make a Payment', 'arm-repair-estimates' ); ?></h2>

	<div class="arm-account-summary">
		<div class="summary-item">
			<span class="label"><?php esc_html_e( 'Current Balance:', 'arm-repair-estimates' ); ?></span>
			<span class="value balance"><?php echo esc_html( '$' . number_format( $account->current_balance, 2 ) ); ?></span>
		</div>
		<div class="summary-item">
			<span class="label"><?php esc_html_e( 'Available Credit:', 'arm-repair-estimates' ); ?></span>
			<span class="value available"><?php echo esc_html( '$' . number_format( $account->available_credit, 2 ) ); ?></span>
		</div>
	</div>

	<form id="arm-payment-form" class="arm-payment-form">
		<?php wp_nonce_field( 'arm_payment_form', 'nonce' ); ?>

		<div class="form-row">
			<label for="payment_amount">
				<?php esc_html_e( 'Payment Amount', 'arm-repair-estimates' ); ?> <span class="required">*</span>
			</label>
			<input type="number" id="payment_amount" name="amount" step="0.01" min="0.01"
				max="<?php echo esc_attr( $account->current_balance ); ?>" required
				placeholder="0.00" class="form-control" />
			<p class="description"><?php esc_html_e( 'Enter the amount you wish to pay.', 'arm-repair-estimates' ); ?></p>
		</div>

		<div class="form-row">
			<label for="payment_method">
				<?php esc_html_e( 'Payment Method', 'arm-repair-estimates' ); ?> <span class="required">*</span>
			</label>
			<select id="payment_method" name="payment_method" required class="form-control">
				<option value=""><?php esc_html_e( 'Select Payment Method', 'arm-repair-estimates' ); ?></option>
				<option value="cash"><?php esc_html_e( 'Cash', 'arm-repair-estimates' ); ?></option>
				<option value="check"><?php esc_html_e( 'Check', 'arm-repair-estimates' ); ?></option>
				<option value="card"><?php esc_html_e( 'Credit/Debit Card', 'arm-repair-estimates' ); ?></option>
				<option value="bank_transfer"><?php esc_html_e( 'Bank Transfer', 'arm-repair-estimates' ); ?></option>
				<option value="other"><?php esc_html_e( 'Other', 'arm-repair-estimates' ); ?></option>
			</select>
		</div>

		<div class="form-row">
			<label for="reference_number">
				<?php esc_html_e( 'Reference Number', 'arm-repair-estimates' ); ?>
			</label>
			<input type="text" id="reference_number" name="reference" class="form-control"
				placeholder="<?php esc_attr_e( 'Check number, transaction ID, etc.', 'arm-repair-estimates' ); ?>" />
			<p class="description"><?php esc_html_e( 'Optional reference number for your records.', 'arm-repair-estimates' ); ?></p>
		</div>

		<div class="form-row">
			<label for="payment_notes">
				<?php esc_html_e( 'Notes', 'arm-repair-estimates' ); ?>
			</label>
			<textarea id="payment_notes" name="notes" rows="3" class="form-control"
				placeholder="<?php esc_attr_e( 'Any additional information...', 'arm-repair-estimates' ); ?>"></textarea>
		</div>

		<div class="form-actions">
			<button type="submit" class="btn-primary" id="submit-payment">
				<?php esc_html_e( 'Submit Payment', 'arm-repair-estimates' ); ?>
			</button>
		</div>

		<div id="payment-message" class="payment-message"></div>
	</form>
</div>

<style>
.arm-payment-form-wrapper {
	max-width: 600px;
	margin: 0 auto;
	padding: 20px;
}
.arm-payment-form-wrapper h2 {
	margin-top: 0;
	color: #333;
	border-bottom: 2px solid #2271b1;
	padding-bottom: 10px;
}
.arm-account-summary {
	background: #f8f9fa;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 30px;
}
.arm-account-summary .summary-item {
	display: flex;
	justify-content: space-between;
	padding: 10px 0;
	border-bottom: 1px solid #e0e0e0;
}
.arm-account-summary .summary-item:last-child {
	border-bottom: none;
}
.arm-account-summary .label {
	font-weight: 600;
	color: #666;
}
.arm-account-summary .value {
	font-size: 18px;
	font-weight: bold;
}
.arm-account-summary .value.balance {
	color: #d63638;
}
.arm-account-summary .value.available {
	color: #00a32a;
}
.arm-payment-form .form-row {
	margin-bottom: 20px;
}
.arm-payment-form label {
	display: block;
	margin-bottom: 8px;
	font-weight: 600;
	color: #333;
}
.arm-payment-form .required {
	color: #d63638;
}
.arm-payment-form .form-control {
	width: 100%;
	padding: 10px;
	border: 1px solid #ccc;
	border-radius: 4px;
	font-size: 14px;
}
.arm-payment-form .form-control:focus {
	outline: none;
	border-color: #2271b1;
	box-shadow: 0 0 0 1px #2271b1;
}
.arm-payment-form .description {
	margin: 5px 0 0;
	font-size: 12px;
	color: #666;
	font-style: italic;
}
.arm-payment-form .form-actions {
	margin-top: 30px;
}
.arm-payment-form .btn-primary {
	background: #2271b1;
	color: #fff;
	border: none;
	padding: 12px 30px;
	font-size: 16px;
	font-weight: 600;
	border-radius: 4px;
	cursor: pointer;
	transition: background 0.3s;
}
.arm-payment-form .btn-primary:hover {
	background: #135e96;
}
.arm-payment-form .btn-primary:disabled {
	background: #ccc;
	cursor: not-allowed;
}
.payment-message {
	margin-top: 20px;
	padding: 15px;
	border-radius: 4px;
	display: none;
}
.payment-message.success {
	background: #d4edda;
	color: #155724;
	border: 1px solid #c3e6cb;
	display: block;
}
.payment-message.error {
	background: #f8d7da;
	color: #721c24;
	border: 1px solid #f5c6cb;
	display: block;
}
@media (max-width: 768px) {
	.arm-payment-form-wrapper {
		padding: 10px;
	}
}
</style>

<script>
jQuery(document).ready(function($) {
	$('#arm-payment-form').on('submit', function(e) {
		e.preventDefault();

		var $form = $(this);
		var $submitBtn = $('#submit-payment');
		var $message = $('#payment-message');

		// Disable submit button
		$submitBtn.prop('disabled', true).text('<?php esc_html_e( 'Processing...', 'arm-repair-estimates' ); ?>');
		$message.hide().removeClass('success error');

		// Prepare data
		var formData = {
			action: 'arm_submit_payment',
			nonce: $('input[name="nonce"]').val(),
			amount: $('#payment_amount').val(),
			payment_method: $('#payment_method').val(),
			reference: $('#reference_number').val(),
			notes: $('#payment_notes').val()
		};

		// Submit via AJAX
		$.ajax({
			url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			type: 'POST',
			data: formData,
			success: function(response) {
				if (response.success) {
					$message.addClass('success').text(response.data.message).show();
					$form[0].reset();

					// Reload page after 2 seconds
					setTimeout(function() {
						location.reload();
					}, 2000);
				} else {
					$message.addClass('error').text(response.data.message).show();
					$submitBtn.prop('disabled', false).text('<?php esc_html_e( 'Submit Payment', 'arm-repair-estimates' ); ?>');
				}
			},
			error: function() {
				$message.addClass('error').text('<?php esc_html_e( 'An error occurred. Please try again.', 'arm-repair-estimates' ); ?>').show();
				$submitBtn.prop('disabled', false).text('<?php esc_html_e( 'Submit Payment', 'arm-repair-estimates' ); ?>');
			}
		});
	});
});
</script>
