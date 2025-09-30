<?php
// file: templates/pdf/customer-estimate-single.php
// Expects: $row (estimate), $customer (object), $items (array of line objects)
?>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color:#111; }
    h1 { text-align: center; margin: 0 0 12px; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0; }
    th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
    th { background: #f5f5f5; }
    .logo { text-align:center; margin-bottom: 14px; }
  </style>
</head>
<body>
  <div class="logo">
    <?php $logo = get_option('arm_company_logo'); if ($logo): ?>
      <img src="<?php echo esc_url($logo); ?>" height="60" alt="">
    <?php endif; ?>
    <h2><?php echo esc_html(get_option('arm_company_name', '')); ?></h2>
    <p><?php echo nl2br(esc_html(get_option('arm_company_address', ''))); ?></p>
  </div>

  <h1><?php esc_html_e('Estimate','arm-repair-estimates'); ?> #<?php echo esc_html( (string)($row->estimate_no ?? $row->id ?? '') ); ?></h1>
  <p><strong><?php echo esc_html( trim( (string)($customer->first_name ?? '') . ' ' . (string)($customer->last_name ?? '') ) ); ?></strong></p>

  <table>
    <thead>
      <tr>
        <th><?php esc_html_e('Description','arm-repair-estimates'); ?></th>
        <th style="text-align:right;"><?php esc_html_e('Qty/Hrs','arm-repair-estimates'); ?></th>
        <th style="text-align:right;"><?php esc_html_e('Unit Price','arm-repair-estimates'); ?></th>
        <th style="text-align:right;"><?php esc_html_e('Total','arm-repair-estimates'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($items)): foreach ($items as $it):
        $t = strtolower((string)($it->type ?? 'part'));
        $qty = $t === 'labor' ? (float)($it->hours ?? 0) : (float)($it->qty ?? 0);
        $unit= (float)($it->unit_price ?? 0);
        $line= $qty * $unit;
      ?>
        <tr>
          <td><?php echo esc_html((string)($it->description ?? '')); ?></td>
          <td style="text-align:right;"><?php echo esc_html(number_format_i18n($qty, $t==='labor'?2:0)); ?></td>
          <td style="text-align:right;"><?php echo esc_html(number_format_i18n($unit, 2)); ?></td>
          <td style="text-align:right;"><?php echo esc_html(number_format_i18n($line, 2)); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="4"><?php esc_html_e('No items.','arm-repair-estimates'); ?></td></tr>
      <?php endif; ?>
    </tbody>
    <?php
      $subtotal = (float)($row->subtotal ?? 0);
      $tax      = (float)($row->tax_total ?? 0);
      $total    = (float)($row->total ?? ($subtotal + $tax));
    ?>
    <tfoot>
      <tr><td colspan="3" style="text-align:right;"><?php esc_html_e('Subtotal','arm-repair-estimates'); ?></td><td style="text-align:right;"><?php echo esc_html(number_format_i18n($subtotal, 2)); ?></td></tr>
      <tr><td colspan="3" style="text-align:right;"><?php esc_html_e('Tax','arm-repair-estimates'); ?></td><td style="text-align:right;"><?php echo esc_html(number_format_i18n($tax, 2)); ?></td></tr>
      <tr><td colspan="3" style="text-align:right;"><?php esc_html_e('Total','arm-repair-estimates'); ?></td><td style="text-align:right;"><?php echo esc_html(number_format_i18n($total, 2)); ?></td></tr>
    </tfoot>
  </table>
</body>
</html>
