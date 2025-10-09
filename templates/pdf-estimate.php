<?php  ?>
<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, Arial, sans-serif;font-size:12px}
h1{font-size:20px;margin:0 0 10px}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #ddd;padding:6px;text-align:left}
.right{text-align:right}
.small{color:#555}
</style></head>
<body>
<?php
$logo = get_option('arm_re_logo_url','');
$shop = [
  'name'=> get_option('arm_re_shop_name',''),
  'addr'=> wp_kses_post(get_option('arm_re_shop_address','')),
  'phone'=> get_option('arm_re_shop_phone',''),
  'email'=> get_option('arm_re_shop_email',''),
];
if (class_exists('ARM_RE_Zoho')) { ARM_RE_Zoho::estimate_approved($est); }

?>
<table style="width:100%;margin-bottom:10px;"><tr>
  <td style="width:60%;">
    <?php if ($logo): ?><img src="<?php echo esc_url($logo); ?>" style="max-height:60px"><?php endif; ?>
    <div><strong><?php echo esc_html($shop['name']); ?></strong></div>
    <div class="small"><?php echo wp_kses_post(nl2br($shop['addr'])); ?></div>
    <div class="small"><?php echo esc_html($shop['phone']); ?> · <?php echo esc_html($shop['email']); ?></div>
  </td>
  <td style="text-align:right;"><h1 style="margin:0;">
    <?php echo isset($est) ? 'Estimate '.esc_html($est->estimate_no) : 'Invoice '.esc_html($inv->invoice_no); ?>
  </h1></td>
</tr></table>

<h1>Estimate <?php echo esc_html($est->estimate_no); ?></h1>
<p class="small">
Customer: <?php echo esc_html($cust->first_name.' '.$cust->last_name); ?> — <?php echo esc_html($cust->email); ?><br>
Status: <?php echo esc_html($est->status); ?><?php if ($est->expires_at) echo ' — Expires: '.esc_html($est->expires_at); ?>
</p>

<table>
<thead><tr><th>Type</th><th>Description</th><th class="right">Qty</th><th class="right">Unit</th><th class="right">Line</th></tr></thead>
<tbody>
<?php foreach($items as $it): ?>
<tr>
  <td><?php echo esc_html($it->item_type); ?></td>
  <td><?php echo esc_html($it->description); ?></td>
  <td class="right"><?php echo number_format((float)$it->qty,2); ?></td>
  <td class="right"><?php echo number_format((float)$it->unit_price,2); ?></td>
  <td class="right"><?php echo number_format((float)$it->line_total,2); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<table style="margin-top:10px;width:40%;float:right">
<tr><td>Subtotal</td><td class="right"><?php echo number_format((float)$est->subtotal,2); ?></td></tr>
<tr><td>Tax (<?php echo number_format((float)$est->tax_rate,2); ?>%)</td><td class="right"><?php echo number_format((float)$est->tax_amount,2); ?></td></tr>
<tr><td><strong>Total</strong></td><td class="right"><strong><?php echo number_format((float)$est->total,2); ?></strong></td></tr>
</table>

<div style="clear:both"></div>
<?php if (!empty($est->notes)): ?>
<p><strong>Notes:</strong><br><?php echo wp_kses_post($est->notes); ?></p>
<?php endif; ?>
</body></html>
