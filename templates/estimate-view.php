<?php
if (!defined('ABSPATH')) exit;
/** @var array $ARM_RE_ESTIMATE_CONTEXT */
extract($ARM_RE_ESTIMATE_CONTEXT);

$label = [
    'DRAFT'            => __('Draft', 'arm-repair-estimates'),
    'SENT'             => __('Sent', 'arm-repair-estimates'),
    'APPROVED'         => __('Approved', 'arm-repair-estimates'),
    'DECLINED'         => __('Declined', 'arm-repair-estimates'),
    'EXPIRED'          => __('Expired', 'arm-repair-estimates'),
    'NEEDS_REAPPROVAL' => __('Needs Re-Approval', 'arm-repair-estimates'),
];
$can_act = in_array($est->status, ['SENT','NEEDS_REAPPROVAL'], true);
?>
<div class="arm-estimate-wrap arm-container" style="max-width:960px;margin:24px auto;">
  <div class="arm-card" style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:24px;box-shadow:0 1px 2px rgba(0,0,0,0.04)">

    <div class="arm-flex arm-justify-between arm-items-center" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
      <div class="arm-shop">
        <?php if (!empty($shop->logo)): ?>
          <img src="<?php echo esc_url($shop->logo); ?>" alt="<?php echo esc_attr($shop->name); ?>" style="max-height:64px;display:block">
        <?php endif; ?>
        <h2 style="margin:8px 0 0;font-size:22px;"><?php echo esc_html($shop->name ?: get_bloginfo('name')); ?></h2>
        <div style="color:#666;white-space:pre-line;"><?php echo esc_html($shop->address ?: ''); ?></div>
        <div style="color:#666;">
          <?php if ($shop->phone): ?><span><?php echo esc_html($shop->phone); ?></span><?php endif; ?>
          <?php if ($shop->email): ?> • <span><?php echo esc_html($shop->email); ?></span><?php endif; ?>
        </div>
      </div>
      <div class="arm-meta" style="text-align:right;">
        <h1 style="margin:0;font-size:28px;"><?php echo esc_html(__('Estimate', 'arm-repair-estimates')); ?></h1>
        <div><?php echo esc_html($est->estimate_no); ?></div>
        <div>
          <span class="arm-badge" style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:14px;border:1px solid #ddd;background:#fafafa;">
            <?php echo esc_html($label[$est->status] ?? $est->status); ?>
          </span>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:24px;margin-top:24px;flex-wrap:wrap;">
      <div style="flex:1 1 280px;">
        <h3 style="margin:0 0 8px;"><?php _e('Bill To', 'arm-repair-estimates'); ?></h3>
        <div><strong><?php echo esc_html(trim(($cust->first_name ?? '').' '.($cust->last_name ?? '')) ?: ''); ?></strong></div>
        <div><?php echo esc_html($cust->email ?? ''); ?></div>
        <div><?php echo esc_html($cust->phone ?? ''); ?></div>
        <div><?php echo esc_html($cust->address ?? ''); ?></div>
        <div><?php echo esc_html(trim(($cust->city ?? '').' '.($cust->zip ?? ''))); ?></div>
      </div>
      <div style="flex:1 1 280px;">
        <h3 style="margin:0 0 8px;"><?php _e('Details', 'arm-repair-estimates'); ?></h3>
        <?php if (!empty($est->expires_at)): ?>
          <div><strong><?php _e('Expires', 'arm-repair-estimates'); ?>:</strong> <?php echo esc_html($est->expires_at); ?></div>
        <?php endif; ?>
        <div><strong><?php _e('Created', 'arm-repair-estimates'); ?>:</strong> <?php echo esc_html($est->created_at); ?></div>
      </div>
    </div>

    <div style="margin-top:16px;overflow:auto;">
      <table class="widefat striped" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th style="text-align:left;"><?php _e('Type','arm-repair-estimates'); ?></th>
            <th style="text-align:left;"><?php _e('Description','arm-repair-estimates'); ?></th>
            <th style="text-align:right;"><?php _e('Qty','arm-repair-estimates'); ?></th>
            <th style="text-align:right;"><?php _e('Unit','arm-repair-estimates'); ?></th>
            <th style="text-align:right;"><?php _e('Line Total','arm-repair-estimates'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($items): foreach ($items as $it): ?>
            <tr>
              <td><?php echo esc_html($it->item_type); ?></td>
              <td><?php echo esc_html($it->description); ?></td>
              <td style="text-align:right;"><?php echo esc_html(number_format((float)$it->qty, 2)); ?></td>
              <td style="text-align:right;"><?php echo esc_html(number_format((float)$it->unit_price, 2)); ?></td>
              <td style="text-align:right;"><?php echo esc_html(number_format((float)$it->line_total, 2)); ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5"><?php _e('No items.', 'arm-repair-estimates'); ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="4" style="text-align:right;"><?php _e('Subtotal','arm-repair-estimates'); ?></th>
            <th style="text-align:right;"><?php echo esc_html(number_format((float)$est->subtotal, 2)); ?></th>
          </tr>
          <tr>
            <th colspan="4" style="text-align:right;"><?php _e('Tax','arm-repair-estimates'); ?> (<?php echo esc_html(number_format((float)$est->tax_rate,2)); ?>%)</th>
            <th style="text-align:right;"><?php echo esc_html(number_format((float)$est->tax_amount, 2)); ?></th>
          </tr>
          <tr>
            <th colspan="4" style="text-align:right;font-size:17px;"><?php _e('Total','arm-repair-estimates'); ?></th>
            <th style="text-align:right;font-size:17px;"><?php echo esc_html(number_format((float)$est->total, 2)); ?></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php if (!empty($est->notes)): ?>
      <div style="margin-top:12px;padding:12px;border-left:3px solid #e5e5e5;background:#fafafa;">
        <?php echo wpautop(wp_kses_post($est->notes)); ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($terms)): ?>
      <div style="margin-top:18px;">
        <h3 style="margin:0 0 6px;"><?php _e('Terms & Conditions', 'arm-repair-estimates'); ?></h3>
        <div class="arm-terms-content"><?php echo $terms; ?></div>
      </div>
    <?php endif; ?>

    <div style="margin-top:24px;">
      <?php if ($can_act): ?>
        <div class="arm-approval-box" style="border:1px solid #e5e5e5;border-radius:8px;padding:16px;">
          <h3 style="margin-top:0"><?php _e('Approve Estimate', 'arm-repair-estimates'); ?></h3>

          <p style="margin:8px 0;"><?php _e('Type your name and sign below to approve this estimate.', 'arm-repair-estimates'); ?></p>

          <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;">
            <div>
              <label for="arm-sig-name"><strong><?php _e('Your Name','arm-repair-estimates'); ?></strong></label><br>
              <input id="arm-sig-name" type="text" style="width:260px;">
            </div>
            <div>
              <label><strong><?php _e('Signature','arm-repair-estimates'); ?></strong></label>
              <div style="border:1px dashed #bbb;border-radius:6px;background:#fff;">
                <canvas id="arm-sig-pad" width="500" height="160" style="display:block;"></canvas>
                <div style="text-align:right;padding:4px 6px;">
                  <button type="button" class="button" id="arm-sig-clear"><?php _e('Clear','arm-repair-estimates'); ?></button>
                </div>
              </div>
            </div>
          </div>

          <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="button button-primary" id="arm-approve-btn"><?php _e('Accept & Sign','arm-repair-estimates'); ?></button>
            <button type="button" class="button" id="arm-decline-btn"><?php _e('Decline','arm-repair-estimates'); ?></button>
            <span id="arm-est-msg" style="margin-left:8px;"></span>
          </div>
        </div>
      <?php else: ?>
        <?php if ($est->status === 'APPROVED'): ?>
          <div class="notice notice-success" style="margin-top:16px;"><p><?php _e('This estimate has been approved.', 'arm-repair-estimates'); ?></p></div>
        <?php elseif ($est->status === 'DECLINED'): ?>
          <div class="notice notice-error" style="margin-top:16px;"><p><?php _e('This estimate has been declined.', 'arm-repair-estimates'); ?></p></div>
        <?php elseif ($est->status === 'EXPIRED'): ?>
          <div class="notice notice-warning" style="margin-top:16px;"><p><?php _e('This estimate has expired.', 'arm-repair-estimates'); ?></p></div>
        <?php else: ?>
          <div class="notice" style="margin-top:16px;"><p><?php _e('This estimate is not currently actionable.', 'arm-repair-estimates'); ?></p></div>
        <?php endif; ?>
  <?php if ($estimate->status === 'PENDING'): ?>
    <div id="arm-estimate-approval">
      <h3><?php _e('Review & Approve','arm-repair-estimates'); ?></h3>
      <p><?php _e('Please review the estimate below and either approve or reject.','arm-repair-estimates'); ?></p>

      <label>
        <input type="checkbox" id="arm_accept_checkbox"> 
        <?php _e('I approve this estimate and authorize the work.','arm-repair-estimates'); ?>
      </label>

      <div id="arm_signature_wrap">
        <p><?php _e('Signature:','arm-repair-estimates'); ?></p>
        <canvas id="arm_signature_pad" width="400" height="150" style="border:1px solid #ccc;"></canvas>
        <button type="button" id="arm_clear_signature"><?php _e('Clear','arm-repair-estimates'); ?></button>
      </div>

      <div class="arm-actions">
        <button type="button" id="arm_btn_approve" class="arm-btn"><?php _e('Approve Estimate','arm-repair-estimates'); ?></button>
        <button type="button" id="arm_btn_reject" class="arm-btn arm-reject"><?php _e('Reject Estimate','arm-repair-estimates'); ?></button>
      </div>
    </div>
  <?php endif; ?>

  <div id="arm-appointment-form" style="display:none;">
    <h3><?php _e('Book an Appointment','arm-repair-estimates'); ?></h3>
    <label for="arm_appt_date"><?php _e('Choose Date','arm-repair-estimates'); ?></label>
    <input type="date" id="arm_appt_date" name="appt_date">

    <div id="arm_appt_slots_wrap" style="margin-top:1em; display:none;">
      <label for="arm_appt_slot"><?php _e('Choose Time','arm-repair-estimates'); ?></label>
      <select id="arm_appt_slot" name="appt_slot"></select>
    </div>

    <button type="button" id="arm_book_appt" class="arm-btn"><?php _e('Book Appointment','arm-repair-estimates'); ?></button>
    <div id="arm_appt_msg" class="arm-msg"></div>
  </div>
</div>
      <?php endif; ?>
    </div>

  </div>
</div>
