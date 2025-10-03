<?php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

class Settings {
    public static function boot() {
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function register() {
        register_setting('arm_re_settings', 'arm_re_terms_html',  ['type'=>'string','sanitize_callback'=>'wp_kses_post']);
        register_setting('arm_re_settings', 'arm_re_notify_email',['type'=>'string','sanitize_callback'=>'sanitize_email']);
        register_setting('arm_re_settings', 'arm_re_tax_rate',    ['type'=>'number','sanitize_callback'=>function($v){return is_numeric($v)? $v:0;}]);
        register_setting('arm_re_settings', 'arm_re_labor_rate',  ['type'=>'number','sanitize_callback'=>function($v){return is_numeric($v)? $v:0;}]);

        // Branding
        register_setting('arm_re_settings','arm_re_shop_name',['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_shop_address',['type'=>'string','sanitize_callback'=>'wp_kses_post']);
        register_setting('arm_re_settings','arm_re_shop_phone',['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('arm_re_settings','arm_re_shop_email',['type'=>'string','sanitize_callback'=>'sanitize_email']);
        register_setting('arm_re_settings','arm_re_logo_url',['type'=>'string','sanitize_callback'=>'esc_url_raw']);

	// Appointments
	register_setting('arm_re_settings','arm_appt_slot_length',['type'=>'number','default'=>60]);
	register_setting('arm_re_settings','arm_appt_buffer',['type'=>'number','default'=>0]);


        // Payments/Integrations delegate hooks:
        \ARM\Integrations\Payments_Stripe::settings_fields();
        \ARM\Integrations\Payments_PayPal::settings_fields();
        \ARM\Integrations\Zoho::settings_fields();
        \ARM\Integrations\PartsTech::register_settings();

        // New tax application + callout/mileage defaults
        register_setting('arm_re_settings', 'arm_re_tax_apply', [
            'type'=>'string',
            'sanitize_callback'=>function($v){
                $v = is_string($v) ? $v : '';
                return in_array($v, ['parts_labor','parts_only'], true) ? $v : 'parts_labor';
            }
        ]);
        register_setting('arm_re_settings', 'arm_re_callout_default', [
            'type'=>'number','sanitize_callback'=>function($v){ return is_numeric($v)? $v:0; }
        ]);
        register_setting('arm_re_settings', 'arm_re_mileage_rate_default', [
            'type'=>'number','sanitize_callback'=>function($v){ return is_numeric($v)? $v:0; }
        ]);
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_availability';

        if (!empty($_POST['arm_avail_nonce']) && wp_verify_nonce($_POST['arm_avail_nonce'],'arm_avail_save')) {
            $wpdb->query("DELETE FROM $tbl WHERE type='hours'");
            foreach ($_POST['arm_hours'] as $dow=>$h) {
                if (empty($h['start'])||empty($h['end'])) continue;
                $wpdb->insert($tbl,[
                    'type'=>'hours','day_of_week'=>$dow,
                    'start_time'=>$h['start'],'end_time'=>$h['end']
                ]);
            }
        }
        if (!empty($_POST['arm_holiday_nonce']) && wp_verify_nonce($_POST['arm_holiday_nonce'],'arm_holiday_add')) {
            $wpdb->insert($tbl,[
                'type'=>'holiday',
                'date'=>sanitize_text_field($_POST['date']),
                'label'=>sanitize_text_field($_POST['label'])
            ]);
        }
        if (!empty($_GET['del_holiday']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'arm_holiday_del')) {
            $wpdb->delete($tbl,['id'=>intval($_GET['del_holiday'])]);
        }
        
        ?>
        <div class="wrap">
          <h1><?php _e('ARM Repair Estimates  Settings','arm-repair-estimates'); ?></h1>
          <form method="post" action="options.php">
            <?php settings_fields('arm_re_settings'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th><label for="arm_re_notify_email"><?php _e('Notification Email','arm-repair-estimates'); ?></label></th>
                <td><input type="email" class="regular-text" name="arm_re_notify_email" id="arm_re_notify_email" value="<?php echo esc_attr(get_option('arm_re_notify_email', get_option('admin_email'))); ?>"></td>
              </tr>
              <tr>
                <th><?php _e('Terms & Conditions (HTML allowed)','arm-repair-estimates'); ?></th>
                <td><?php
                    $content = wp_kses_post(get_option('arm_re_terms_html',''));
                    wp_editor($content, 'arm_re_terms_html', ['textarea_name'=>'arm_re_terms_html','media_buttons'=>false,'textarea_rows'=>10]);
                ?></td>
              </tr>
              <tr>
                <th><label for="arm_re_tax_rate"><?php _e('Default Tax Rate (%)','arm-repair-estimates'); ?></label></th>
                <td><input type="number" step="0.01" class="small-text" name="arm_re_tax_rate" id="arm_re_tax_rate" value="<?php echo esc_attr(get_option('arm_re_tax_rate','0')); ?>"> %</td>
              </tr>
              <tr>
                <th><label for="arm_re_labor_rate"><?php _e('Default Labor Rate','arm-repair-estimates'); ?></label></th>
                <td><input type="number" step="0.01" class="regular-text" name="arm_re_labor_rate" id="arm_re_labor_rate" value="<?php echo esc_attr(get_option('arm_re_labor_rate','125')); ?>"></td>
              </tr>

              <tr><th><?php _e('Shop Name','arm-repair-estimates'); ?></th><td><input type="text" class="regular-text" name="arm_re_shop_name" value="<?php echo esc_attr(get_option('arm_re_shop_name','')); ?>"></td></tr>
              <tr><th><?php _e('Shop Address','arm-repair-estimates'); ?></th><td><textarea name="arm_re_shop_address" rows="3" class="large-text"><?php echo esc_textarea(get_option('arm_re_shop_address','')); ?></textarea></td></tr>
              <tr><th><?php _e('Shop Phone','arm-repair-estimates'); ?></th><td><input type="text" name="arm_re_shop_phone" value="<?php echo esc_attr(get_option('arm_re_shop_phone','')); ?>"></td></tr>
              <tr><th><?php _e('Shop Email','arm-repair-estimates'); ?></th><td><input type="email" name="arm_re_shop_email" value="<?php echo esc_attr(get_option('arm_re_shop_email','')); ?>"></td></tr>
              <tr><th><?php _e('Logo URL','arm-repair-estimates'); ?></th><td><input type="text" class="regular-text" name="arm_re_logo_url" value="<?php echo esc_attr(get_option('arm_re_logo_url','')); ?>"></td></tr>

            </table>

            <h2><?php _e('Payments & Integrations','arm-repair-estimates'); ?></h2>
            <table class="form-table" role="presentation">
              <tr>
                <th><?php _e('Currency','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="small-text" name="arm_re_currency" value="<?php echo esc_attr(get_option('arm_re_currency','usd')); ?>">
                  <p class="description"><?php _e('Use a 3-letter ISO code (e.g. usd, cad). Stripe and PayPal must use the same currency.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Stripe Publishable Key','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="regular-text" name="arm_re_stripe_pk" value="<?php echo esc_attr(get_option('arm_re_stripe_pk','')); ?>">
                  <p class="description"><?php printf(__('Create a <a href="%s" target="_blank">Stripe account</a> and copy the Publishable key from Developers → API keys.', 'arm-repair-estimates'), 'https://dashboard.stripe.com/apikeys'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Stripe Secret Key','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="regular-text" name="arm_re_stripe_sk" value="<?php echo esc_attr(get_option('arm_re_stripe_sk','')); ?>">
                  <p class="description"><?php _e('Paste the Secret key that matches the publishable key above. Use a restricted key with permissions for Checkout Sessions and Payment Intents if possible.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Stripe Webhook Secret','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="regular-text" name="arm_re_stripe_whsec" value="<?php echo esc_attr(get_option('arm_re_stripe_whsec','')); ?>">
                  <p class="description"><?php printf(__('Create a webhook endpoint in Stripe pointing to <code>%s</code> and copy the signing secret here so invoice statuses update automatically.', 'arm-repair-estimates'), esc_html(rest_url('arm/v1/stripe/webhook'))); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Payment Success URL','arm-repair-estimates'); ?></th>
                <td>
                  <input type="url" class="regular-text" name="arm_re_pay_success" value="<?php echo esc_attr(get_option('arm_re_pay_success', home_url('/'))); ?>">
                  <p class="description"><?php _e('Customers are redirected here after successful payment. Query parameters <code>paid</code> and <code>inv</code> are added automatically.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Payment Cancel URL','arm-repair-estimates'); ?></th>
                <td>
                  <input type="url" class="regular-text" name="arm_re_pay_cancel" value="<?php echo esc_attr(get_option('arm_re_pay_cancel', home_url('/'))); ?>">
                </td>
              </tr>

              <tr>
                <th><?php _e('PayPal Environment','arm-repair-estimates'); ?></th>
                <td>
                  <select name="arm_re_paypal_env">
                    <?php foreach (['sandbox'=>__('Sandbox','arm-repair-estimates'),'live'=>__('Live','arm-repair-estimates')] as $k=>$label): ?>
                      <option value="<?php echo esc_attr($k); ?>" <?php selected(get_option('arm_re_paypal_env','sandbox'), $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="description"><?php printf(__('Log in to the <a href="%s" target="_blank">PayPal developer dashboard</a> to create REST API credentials. Use Sandbox while testing.', 'arm-repair-estimates'), 'https://developer.paypal.com/dashboard/applications'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('PayPal Client ID','arm-repair-estimates'); ?></th>
                <td><input type="text" class="regular-text" name="arm_re_paypal_client_id" value="<?php echo esc_attr(get_option('arm_re_paypal_client_id','')); ?>"></td>
              </tr>
              <tr>
                <th><?php _e('PayPal Secret','arm-repair-estimates'); ?></th>
                <td><input type="text" class="regular-text" name="arm_re_paypal_secret" value="<?php echo esc_attr(get_option('arm_re_paypal_secret','')); ?>">
                  <p class="description"><?php printf(__('Add a webhook in PayPal using <code>%s</code> for the listener URL and paste the Webhook ID below.', 'arm-repair-estimates'), esc_html(rest_url('arm/v1/paypal/webhook'))); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('PayPal Webhook ID','arm-repair-estimates'); ?></th>
                <td><input type="text" class="regular-text" name="arm_re_paypal_webhook_id" value="<?php echo esc_attr(get_option('arm_re_paypal_webhook_id','')); ?>"></td>
              </tr>

              <tr>
                <th><?php _e('Zoho Data Center','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="small-text" name="arm_zoho_dc" value="<?php echo esc_attr(get_option('arm_zoho_dc','com')); ?>">
                  <p class="description"><?php _e('Examples: com, eu, in, com.au. This determines the Zoho API host.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Zoho Client ID','arm-repair-estimates'); ?></th>
                <td><input type="text" class="regular-text" name="arm_zoho_client_id" value="<?php echo esc_attr(get_option('arm_zoho_client_id','')); ?>"></td>
              </tr>
              <tr>
                <th><?php _e('Zoho Client Secret','arm-repair-estimates'); ?></th>
                <td><input type="text" class="regular-text" name="arm_zoho_client_secret" value="<?php echo esc_attr(get_option('arm_zoho_client_secret','')); ?>"></td>
              </tr>
              <tr>
                <th><?php _e('Zoho Refresh Token','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="regular-text" name="arm_zoho_refresh" value="<?php echo esc_attr(get_option('arm_zoho_refresh','')); ?>">
                  <p class="description"><?php _e('Generate a self-client grant in Zoho CRM, exchange it for a refresh token, and paste it here. The plugin refreshes access tokens automatically.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Zoho Deals Module','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="regular-text" name="arm_zoho_module_deal" value="<?php echo esc_attr(get_option('arm_zoho_module_deal','Deals')); ?>">
                  <p class="description"><?php _e('Change only if your Zoho CRM uses a renamed Deals module.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>

              <tr>
                <th><?php _e('PartsTech API Base','arm-repair-estimates'); ?></th>
                <td>
                  <input type="url" class="regular-text" name="arm_partstech_base" value="<?php echo esc_attr(get_option('arm_partstech_base','https://api.partstech.com')); ?>">
                  <p class="description"><?php _e('Leave as default unless PartsTech support directs you to a regional endpoint.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('PartsTech API Key','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" class="regular-text" name="arm_partstech_api_key" value="<?php echo esc_attr(get_option('arm_partstech_api_key','')); ?>">
                  <p class="description"><?php printf(__('Request API credentials from <a href="%s" target="_blank">PartsTech</a>. The key is required for VIN decoding and catalog search.', 'arm-repair-estimates'), 'https://www.partstech.com/'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Parts Markup Tiers (JSON)','arm-repair-estimates'); ?></th>
                <td>
                  <textarea name="arm_re_markup_tiers" rows="5" class="large-text"><?php echo esc_textarea(get_option('arm_re_markup_tiers','[\n  {"min":0,"max":25,"pct":40},\n  {"min":25,"max":100,"pct":35},\n  {"min":100,"max":999999,"pct":30}\n]')); ?></textarea>
                  <p class="description"><?php _e('Optional tiers that are applied to catalog results when adding parts to an estimate.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
            </table>

            <table class="form-table" role="presentation">
            <tr>
              <th><?php _e('Sales Tax Applies To','arm-repair-estimates'); ?></th>
              <td>
                  <?php $tax_apply = get_option('arm_re_tax_apply','parts_labor'); ?>
                  <label style="margin-right:16px;"><input type="radio" name="arm_re_tax_apply" value="parts_labor" <?php checked($tax_apply,'parts_labor'); ?>> <?php _e('Parts & Labor','arm-repair-estimates'); ?></label>
                  <label><input type="radio" name="arm_re_tax_apply" value="parts_only" <?php checked($tax_apply,'parts_only'); ?>> <?php _e('Parts Only','arm-repair-estimates'); ?></label>
                </td>
              </tr>
              <tr>
                <th><label for="arm_re_callout_default"><?php _e('Default Call-out Fee','arm-repair-estimates'); ?></label></th>
                <td><input type="number" step="0.01" class="regular-text" name="arm_re_callout_default" id="arm_re_callout_default" value="<?php echo esc_attr(get_option('arm_re_callout_default','0')); ?>"></td>
              </tr>
              <tr>
                <th><label for="arm_re_mileage_rate_default"><?php _e('Default Mileage Rate (per mile)','arm-repair-estimates'); ?></label></th>
                <td><input type="number" step="0.01" class="regular-text" name="arm_re_mileage_rate_default" id="arm_re_mileage_rate_default" value="<?php echo esc_attr(get_option('arm_re_mileage_rate_default','0')); ?>"></td>
              </tr>
            </table>
<h2><?php _e('Appointment Hours','arm-repair-estimates'); ?></h2>
<?php
$hours = $wpdb->get_results("SELECT * FROM $tbl WHERE type='hours'", OBJECT_K);
$days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
?>
<table class="widefat striped">
  <thead><tr><th><?php _e('Day'); ?></th><th><?php _e('Start'); ?></th><th><?php _e('End'); ?></th></tr></thead>
  <tbody>
  <?php foreach ($days as $i=>$d): $row=$hours[$i]??null; ?>
    <tr>
      <td><?php echo esc_html($d); ?></td>
      <td><input type="time" name="arm_hours[<?php echo $i; ?>][start]" value="<?php echo esc_attr($row->start_time??''); ?>"></td>
      <td><input type="time" name="arm_hours[<?php echo $i; ?>][end]" value="<?php echo esc_attr($row->end_time??''); ?>"></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2><?php _e('Holidays','arm-repair-estimates'); ?></h2>
<form method="post">
  <?php wp_nonce_field('arm_holiday_add','arm_holiday_nonce'); ?>
  <p>
    <label><?php _e('Date'); ?> <input type="date" name="date"></label>
    <label><?php _e('Label'); ?> <input type="text" name="label"></label>
    <?php submit_button(__('Add Holiday'),'secondary','',false); ?>
  </p>
</form>

<table class="widefat striped">
  <thead><tr><th><?php _e('Date'); ?></th><th><?php _e('Label'); ?></th><th><?php _e('Actions'); ?></th></tr></thead>
  <tbody>
    <?php
    $holidays=$wpdb->get_results("SELECT * FROM $tbl WHERE type='holiday' ORDER BY date ASC");
    foreach ($holidays as $h):
      $del=wp_nonce_url(add_query_arg(['del_holiday'=>$h->id]),'arm_holiday_del');
    ?>
    <tr>
      <td><?php echo esc_html($h->date); ?></td>
      <td><?php echo esc_html($h->label); ?></td>
      <td><a href="<?php echo esc_url($del); ?>" onclick="return confirm('Delete holiday?')">Delete</a></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2><?php _e('Slot Settings','arm-repair-estimates'); ?></h2>
<table class="form-table">
  <tr>
    <th><?php _e('Slot Length (minutes)'); ?></th>
    <td><input type="number" name="arm_appt_slot_length" value="<?php echo esc_attr(get_option('arm_appt_slot_length',60)); ?>"></td>
  </tr>
  <tr>
    <th><?php _e('Buffer Between Appointments (minutes)'); ?></th>
    <td><input type="number" name="arm_appt_buffer" value="<?php echo esc_attr(get_option('arm_appt_buffer',0)); ?>"></td>
  </tr>
</table>

            <?php submit_button(); ?>
          </form>
        </div>
        <?php
    }
}