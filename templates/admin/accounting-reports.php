<?php
/** @var array $filters */
/** @var array $totals */
/** @var array $monthly */
?>
<div class="wrap">
  <h1><?php esc_html_e('Financial Reports', 'arm-repair-estimates'); ?></h1>

  <form method="get" class="arm-accounting-filter">
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
    </table>
    <?php submit_button(__('Apply Filters', 'arm-repair-estimates')); ?>
    <a class="button" href="<?php echo esc_url(add_query_arg('export', 'csv')); ?>"><?php esc_html_e('Export Monthly Summary', 'arm-repair-estimates'); ?></a>
  </form>

  <h2><?php esc_html_e('Totals', 'arm-repair-estimates'); ?></h2>
  <table class="widefat striped">
    <tbody>
      <tr>
        <th><?php esc_html_e('Income', 'arm-repair-estimates'); ?></th>
        <td><?php echo esc_html(number_format_i18n($totals['income'], 2)); ?></td>
      </tr>
      <tr>
        <th><?php esc_html_e('Expenses', 'arm-repair-estimates'); ?></th>
        <td><?php echo esc_html(number_format_i18n($totals['expenses'], 2)); ?></td>
      </tr>
      <tr>
        <th><?php esc_html_e('Purchases', 'arm-repair-estimates'); ?></th>
        <td><?php echo esc_html(number_format_i18n($totals['purchases'], 2)); ?></td>
      </tr>
      <tr>
        <th><?php esc_html_e('Net Income', 'arm-repair-estimates'); ?></th>
        <td><strong><?php echo esc_html(number_format_i18n($totals['net'], 2)); ?></strong></td>
      </tr>
    </tbody>
  </table>

  <h2><?php esc_html_e('Monthly Summary', 'arm-repair-estimates'); ?></h2>
  <table class="widefat striped">
    <thead>
      <tr>
        <th><?php esc_html_e('Period', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Income', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Expenses', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Purchases', 'arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Net', 'arm-repair-estimates'); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if ($monthly): foreach ($monthly as $row): ?>
      <tr>
        <td><?php echo esc_html($row['period']); ?></td>
        <td><?php echo esc_html(number_format_i18n($row['income'], 2)); ?></td>
        <td><?php echo esc_html(number_format_i18n($row['expenses'], 2)); ?></td>
        <td><?php echo esc_html(number_format_i18n($row['purchases'], 2)); ?></td>
        <td><strong><?php echo esc_html(number_format_i18n($row['net'], 2)); ?></strong></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="5"><?php esc_html_e('No data for selected period.', 'arm-repair-estimates'); ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
