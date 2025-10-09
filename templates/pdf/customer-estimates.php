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

  <h1><?php esc_html_e('Customer Estimates','arm-repair-estimates'); ?></h1>
  <p><strong><?php echo esc_html( trim( (string)($customer->first_name ?? '') . ' ' . (string)($customer->last_name ?? '') ) ); ?></strong></p>

  <table>
    <thead>
      <tr>
        <th><?php esc_html_e('Estimate #','arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Date','arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Status','arm-repair-estimates'); ?></th>
        <th><?php esc_html_e('Total','arm-repair-estimates'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r): ?>
        <tr>
          <td><?php echo esc_html( (string)($r->estimate_no ?? $r->id ?? '') ); ?></td>
          <td><?php echo esc_html( (string)($r->created_at ?? '') ); ?></td>
          <td><?php echo esc_html( (string)($r->status ?? '') ); ?></td>
          <td><?php echo esc_html( number_format_i18n( (float)($r->total ?? 0), 2 ) ); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="4"><?php esc_html_e('No estimates found.','arm-repair-estimates'); ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
