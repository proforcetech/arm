<?php
if (!defined('ABSPATH')) exit;
/** @var stdClass $inv */
/** @var stdClass $cust */
/** @var array     $items */

$logo_url  = esc_url(get_option('arm_re_logo_url',''));
$shop_name = esc_html(get_option('arm_re_shop_name',''));
$shop_addr = wp_kses_post(get_option('arm_re_shop_address',''));
$shop_phone= esc_html(get_option('arm_re_shop_phone',''));
$shop_email= esc_html(get_option('arm_re_shop_email',''));

$pdf_url   = add_query_arg(['arm_invoice_pdf' => $inv->token], home_url('/'));

// Payment config (Stripe Checkout + PayPal SDK)
$stripe_checkout   = rest_url('arm/v1/stripe/checkout');       // server returns {url}
$has_stripe        = (bool) get_option('arm_re_stripe_sk');    // show button only if configured
$paypal_client_id  = trim(get_option('arm_re_paypal_client_id',''));
$paypal_env        = get_option('arm_re_paypal_env','sandbox');
$paypal_order_api  = rest_url('arm/v1/paypal/order');
$paypal_capture    = rest_url('arm/v1/paypal/capture');
$currency          = strtoupper(get_option('arm_re_currency','usd'));
?>
<div class="arm-doc arm-invoice">
  <div class="arm-doc__header" style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
    <div class="arm-brand" style="display:flex;align-items:center;gap:12px;">
      <?php if ($logo_url): ?>
        <img src="<?php echo $logo_url; ?>" alt="" style="height:48px;width:auto;">
      <?php endif; ?>
      <div>
        <?php if ($shop_name): ?><div style="font-size:18px;font-weight:600;"><?php echo $shop_name; ?></div><?php endif; ?>
        <?php if ($shop_addr): ?><div><?php echo $shop_addr; ?></div><?php endif; ?>
        <?php if ($shop_phone): ?><div><?php echo esc_html($shop_phone); ?></div><?php endif; ?>
        <?php if ($shop_email): ?><div><?php echo esc_html($shop_email); ?></div><?php endif; ?>
      </div>
    </div>
    <div class="arm-doc__meta" style="text-align:right;">
      <div style="font-size:22px;font-weight:700;"><?php _e('Invoice','arm-repair-estimates'); ?></div>
      <div><?php _e('Invoice #','arm-repair-estimates'); ?> <?php echo esc_html($inv->invoice_no); ?></div>
      <div><?php _e('Status','arm-repair-estimates'); ?>: <strong><?php echo esc_html($inv->status); ?></strong></div>
      <div><?php _e('Date','arm-repair-estimates'); ?>: <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($inv->created_at))); ?></div>
    </div>
  </div>

  <hr>

  <div class="arm-doc__two" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
    <div>
      <h3 style="margin:.2em 0;"><?php _e('Bill To','arm-repair-estimates'); ?></h3>
      <div><?php echo esc_html(trim("{$cust->first_name} {$cust->last_name}")); ?></div>
      <?php if (!empty($cust->address)): ?><div><?php echo esc_html($cust->address); ?></div><?php endif; ?>
      <?php if (!empty($cust->city) || !empty($cust->zip)): ?><div><?php echo esc_html(trim("{$cust->city} {$cust->zip}")); ?></div><?php endif; ?>
      <?php if (!empty($cust->phone)): ?><div><?php echo esc_html($cust->phone); ?></div><?php endif; ?>
      <?php if (!empty($cust->email)): ?><div><?php echo esc_html($cust->email); ?></div><?php endif; ?>
      <?php
        $invoice_vehicle_parts = array_filter([
            $inv->vehicle_year ?? '',
            $inv->vehicle_make ?? '',
            $inv->vehicle_model ?? '',
            $inv->vehicle_engine ?? '',
            $inv->vehicle_trim ?? '',
        ], function($part){ return $part !== null && $part !== ''; });
        $invoice_vehicle_summary = trim(implode(' ', $invoice_vehicle_parts));
      ?>
      <?php if ($invoice_vehicle_summary || !empty($inv->vehicle_plate) || !empty($inv->vehicle_vin) || !empty($inv->vehicle_mileage)): ?>
        <div style="margin-top:8px;">
          <strong><?php _e('Vehicle','arm-repair-estimates'); ?>:</strong>
          <?php if ($invoice_vehicle_summary): ?><div><?php echo esc_html($invoice_vehicle_summary); ?></div><?php endif; ?>
          <?php if (!empty($inv->vehicle_plate)): ?><div><?php _e('License Plate','arm-repair-estimates'); ?>: <?php echo esc_html($inv->vehicle_plate); ?></div><?php endif; ?>
          <?php if (!empty($inv->vehicle_vin)): ?><div><?php _e('VIN','arm-repair-estimates'); ?>: <?php echo esc_html($inv->vehicle_vin); ?></div><?php endif; ?>
          <?php if (!empty($inv->vehicle_mileage)): ?><div><?php _e('Mileage','arm-repair-estimates'); ?>: <?php echo esc_html($inv->vehicle_mileage); ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;display:flex;flex-direction:column;gap:10px;align-items:flex-end;">
      <a class="button" href="<?php echo esc_url($pdf_url); ?>"><?php _e('Download PDF','arm-repair-estimates'); ?></a>

      <?php if ($inv->status !== 'PAID'): ?>
        <?php if ($has_stripe): ?>
          <button id="arm-stripe-pay" class="button button-primary"><?php _e('Pay with Card (Stripe)','arm-repair-estimates'); ?></button>
        <?php endif; ?>

        <?php if ($paypal_client_id): ?>
          <div id="paypal-button-container"></div>
        <?php endif; ?>
      <?php else: ?>
        <div style="padding:6px 10px;background:#e6ffed;border:1px solid #b7eb8f;border-radius:4px;">
          <?php _e('This invoice is paid.','arm-repair-estimates'); ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <h3 style="margin-top:18px;"><?php _e('Line Items','arm-repair-estimates'); ?></h3>
  <table class="arm-table" style="width:100%;border-collapse:collapse;">
    <thead>
      <tr>
        <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;"><?php _e('Type','arm-repair-estimates'); ?></th>
        <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;"><?php _e('Description','arm-repair-estimates'); ?></th>
        <th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;"><?php _e('Qty','arm-repair-estimates'); ?></th>
        <th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;"><?php _e('Unit','arm-repair-estimates'); ?></th>
        <th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;"><?php _e('Line Total','arm-repair-estimates'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($items): foreach ($items as $it): ?>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #f1f1f1;"><?php echo esc_html($it->item_type); ?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f1f1;"><?php echo esc_html($it->description); ?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f1f1;text-align:right;"><?php echo esc_html($it->qty); ?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f1f1;text-align:right;"><?php echo esc_html(number_format((float)$it->unit_price,2)); ?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f1f1;text-align:right;"><?php echo esc_html(number_format((float)$it->line_total,2)); ?></td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="5" style="padding:12px;text-align:center;color:#666;"><?php _e('No line items.','arm-repair-estimates'); ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="arm-doc__totals" style="margin-top:16px;display:grid;grid-template-columns:1fr 300px;gap:16px;">
    <div>
      <?php if (!empty($inv->notes)): ?>
        <h3 style="margin:.2em 0;"><?php _e('Notes','arm-repair-estimates'); ?></h3>
        <div><?php echo wpautop(wp_kses_post($inv->notes)); ?></div>
      <?php endif; ?>
    </div>
    <table style="width:100%;">
      <tr>
        <td style="text-align:left;padding:6px 8px;"><?php _e('Subtotal','arm-repair-estimates'); ?></td>
        <td style="text-align:right;padding:6px 8px;"><?php echo esc_html(number_format((float)$inv->subtotal,2)); ?></td>
      </tr>
      <tr>
        <td style="text-align:left;padding:6px 8px;"><?php printf(__('Tax (%s%%)','arm-repair-estimates'), esc_html($inv->tax_rate)); ?></td>
        <td style="text-align:right;padding:6px 8px;"><?php echo esc_html(number_format((float)$inv->tax_amount,2)); ?></td>
      </tr>
      <tr>
        <td style="text-align:left;padding:6px 8px;font-weight:700;"><?php _e('Total','arm-repair-estimates'); ?></td>
        <td style="text-align:right;padding:6px 8px;font-weight:700;"><?php echo esc_html(number_format((float)$inv->total,2)); ?></td>
      </tr>
    </table>
  </div>
</div>

<?php if ($inv->status !== 'PAID'): ?>
  <?php if ($paypal_client_id): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr($paypal_client_id); ?>&currency=<?php echo esc_attr($currency); ?>&intent=capture"></script>
    <script>
    (function(){
      if (!window.paypal) return;
      paypal.Buttons({
        style:{ layout:'horizontal', color:'gold', shape:'rect', label:'paypal' },
        createOrder: function(){
          return fetch('<?php echo esc_url($paypal_order_api); ?>', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ invoice_id: <?php echo (int)$inv->id; ?> })
          }).then(r=>r.json()).then(function(d){
            if (d && d.id) return d.id;
            throw new Error('Order creation failed');
          });
        },
        onApprove: function(data){
          return fetch('<?php echo esc_url($paypal_capture); ?>', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ order_id: data.orderID })
          }).then(r=>r.json()).then(function(d){
            if (d && d.status === 'PAID'){ location.reload(); }
            else { alert('Payment capture failed.'); console.log(d); }
          });
        },
        onError: function(err){ console.error(err); alert('PayPal error.'); }
      }).render('#paypal-button-container');
    })();
    </script>
  <?php endif; ?>

  <?php if ($has_stripe): ?>
    <script>
    (function(){
      var btn = document.getElementById('arm-stripe-pay');
      if (!btn) return;
      btn.addEventListener('click', function(){
        btn.disabled = true;
        fetch('<?php echo esc_url($stripe_checkout); ?>', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ invoice_id: <?php echo (int)$inv->id; ?> })
        }).then(r=>r.json()).then(function(d){
          if (d && d.url){ window.location = d.url; }
          else { alert('Unable to start payment.'); console.log(d); btn.disabled=false; }
        }).catch(function(err){ console.error(err); alert('Network error.'); btn.disabled=false; });
      });
    })();
    </script>
  <?php endif; ?>
<?php endif; ?>
