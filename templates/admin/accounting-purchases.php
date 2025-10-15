<?php
/** @var string $message */
/** @var array $rows */
/** @var array $filters */
/** @var object|null $edit */
/** @var string $message_type */
?>
<div class="wrap">
  <h1><?php esc_html_e('Purchases', 'arm-repair-estimates'); ?></h1>

  <?php if ($message): ?>
    <div class="notice notice-<?php echo $message_type === 'error' ? 'error' : 'success'; ?>"><p><?php echo esc_html($message); ?></p></div>
  <?php endif; ?>

  <h2><?php echo $edit ? esc_html__('Edit Purchase', 'arm-repair-estimates') : esc_html__('Log Purchase', 'arm-repair-estimates'); ?></h2>
  <form method="post">
    <?php wp_nonce_field('arm_purchase_save', 'arm_purchase_nonce'); ?>
    <input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
    <table class="form-table">
      <tr>
        <th scope="row"><label for="purchase-date"><?php esc_html_e('Date', 'arm-repair-estimates'); ?></label></th>
        <td><input type="date" id="purchase-date" name="transaction_date" value="<?php echo esc_attr($edit->transaction_date ?? wp_date('Y-m-d', current_time('timestamp'))); ?>" required></td>
      </tr>
      <tr>
        <th scope="row"><label for="purchase-vendor"><?php esc_html_e('Vendor', 'arm-repair-estimates'); ?></label></th>
        <td><input type="text" id="purchase-vendor" name="vendor_name" value="<?php echo esc_attr($edit->vendor_name ?? ''); ?>"></td>
      </tr>
      <tr>
        <th scope="row"><label for="purchase-order"><?php esc_html_e('Purchase Order / Reference', 'arm-repair-estimates'); ?></label></th>
        <td><input type="text" id="purchase-order" name="purchase_order" value="<?php echo esc_attr($edit->purchase_order ?? ''); ?>"></td>
      </tr>
      <tr>
        <th scope="row"><label for="purchase-category"><?php esc_html_e('Category', 'arm-repair-estimates'); ?></label></th>
        <td><input type="text" id="purchase-category" name="category" value="<?php echo esc_attr($edit->category ?? ''); ?>" required></td>
      </tr>
      <tr>
        <th scope="row"><label for="purchase-amount"><?php esc_html_e('Amount', 'arm-repair-estimates'); ?></label></th>
        <td><input type="number" step="0.01" id="purchase-amount" name="amount" value="<?php echo esc_attr($edit->amount ?? '0.00'); ?>" required></td>
      </tr>
      <tr>
        <th scope="row"><label for="purchase-reference"><?php esc_html_e('Reference', 'arm-repair-estimates'); ?></label></th>
        <td><input type="text" id="purchase-reference" name="reference" value="<?php echo esc_attr($edit->reference ?? ''); ?>"></td>
      </tr>
      <tr>
        <th scope="row"><label for="purchase-description"><?php esc_html_e('Description', 'arm-repair-estimates'); ?></label></th>
        <td><textarea id="purchase-description" name="description" rows="4" class="large-text"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></td>
      </tr>
    </table>
    <?php submit_button($edit ? __('Update Purchase', 'arm-repair-estimates') : __('Log Purchase', 'arm-repair-estimates')); ?>
  </form>

  <h2><?php esc_html_e('Filter', 'arm-repair-estimates'); ?></h2>
  <form method="get">
    <input type="hidden" name="page" value="<?php echo isset($_GET['page']) ? esc_attr(sanitize_text_field(wp_unslash($_GET['page']))):''; ?>">
    <table class="form-table">
      <tr>
        <th scope="row"><?php esc_html_e('From', 'arm-repair-estimates'); ?></th>
        <td><input type="date" name="from" value="<?php echo esc_attr($filters['from'] ?? ''); ?>"></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('To', 'arm-repair-estimates'); ?></th>
        <td><input type="date" name="to" value="<?php echo esc_attr($filters['to'] ?? ''); ?>"></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Category', 'arm-repair-estimates'); ?></th>
        <td><input type="text" name="category" value="<?php echo esc_attr($filters['category'] ?? ''); ?>"></td>
      </tr>
    </table>
    <?php submit_button(__('Apply Filters', 'arm-repair-estimates')); ?>
    <a class="button" href="<?php echo esc_url(add_query_arg('export', 'csv')); ?>"><?php esc_html_e('Export CSV', 'arm-repair-estimates'); ?></a>
  </form>

  <h2><?php esc_html_e('Recent Purchases', 'arm-repair-estimates'); ?></h2>
  <table class="widefat striped">
    <thead>
      <tr>
        <th><?php esc_html_e('Date', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Vendor', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('PO / Reference', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Category', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Amount', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Description', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Actions', 'arm-repair-estimates'); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if ($rows): foreach ($rows as $row): ?>
      <tr>
        <td><?php echo esc_html($row['transaction_date']); ?></td>
        <td><?php echo esc_html($row['vendor_name'] ?? ''); ?></td>
        <td><?php echo esc_html($row['purchase_order'] ?: $row['reference']); ?></td>
        <td><?php echo esc_html($row['category']); ?></td>
        <td><?php echo esc_html(number_format_i18n((float) $row['amount'], 2)); ?></td>
        <td><?php echo esc_html(wp_trim_words(wp_strip_all_tags($row['description']), 12)); ?></td>
        <td>
          <?php $edit_link = add_query_arg('edit', (int) $row['id']); ?>
          <?php $delete_link = wp_nonce_url(add_query_arg('delete', (int) $row['id']), 'arm_purchase_delete'); ?>
          <a href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Edit', 'arm-repair-estimates'); ?></a> |
          <a href="<?php echo esc_url($delete_link); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this purchase?', 'arm-repair-estimates')); ?>');"><?php esc_html_e('Delete', 'arm-repair-estimates'); ?></a>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7"><?php esc_html_e('No purchases recorded.', 'arm-repair-estimates'); ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
