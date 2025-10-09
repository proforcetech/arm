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
<h1>Invoice <?php echo esc_html($inv->invoice_no); ?></h1>
<p class="small">
Customer: <?php echo esc_html($cust->first_name.' '.$cust->last_name); ?> — <?php echo esc_html($cust->email); ?><br>
Status: <?php echo esc_html($inv->status); ?>
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
<tr><td>Subtotal</td><td class="right"><?php echo number_format((float)$inv->subtotal,2); ?></td></tr>
<tr><td>Tax (<?php echo number_format((float)$inv->tax_rate,2); ?>%)</td><td class="right"><?php echo number_format((float)$inv->tax_amount,2); ?></td></tr>
<tr><td><strong>Total</strong></td><td class="right"><strong><?php echo number_format((float)$inv->total,2); ?></strong></td></tr>
</table>
</body></html>
