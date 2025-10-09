<?php
namespace ARM\Estimates;

if (!defined('ABSPATH')) exit;

class Controller {

    /** ----------------------------------------------------------------
     * Boot: hooks (admin + public actions used by the estimates module)
     * -----------------------------------------------------------------*/
    public static function boot() {
        add_action('admin_post_arm_re_save_estimate',   [__CLASS__, 'handle_save_estimate']);
        add_action('admin_post_arm_re_send_estimate',   [__CLASS__, 'handle_send_estimate']);
        add_action('admin_post_arm_re_mark_status',     [__CLASS__, 'handle_mark_status']);

        add_action('wp_ajax_arm_re_search_customers',   [__CLASS__, 'ajax_search_customers']);
    }

    /** ----------------------------------------------------------------
     * DB install/upgrade for estimates, items, jobs, customers, signatures
     * -----------------------------------------------------------------*/
    public static function install_tables() {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset  = $wpdb->get_charset_collate();
        $customers= $wpdb->prefix.'arm_customers';
        $estimates= $wpdb->prefix.'arm_estimates';
        $items    = $wpdb->prefix.'arm_estimate_items';
        $jobs     = $wpdb->prefix.'arm_estimate_jobs';
        $sigs     = $wpdb->prefix.'arm_signatures';

        dbDelta("CREATE TABLE $customers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(64) NOT NULL,
            last_name VARCHAR(64) NOT NULL,
            email VARCHAR(128) NOT NULL,
            phone VARCHAR(32) NULL,
            address VARCHAR(200) NULL,
            city VARCHAR(100) NULL,
            zip VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY email(email)
        ) $charset;");

        dbDelta("CREATE TABLE $estimates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            estimate_no VARCHAR(32) NOT NULL,
            status ENUM('DRAFT','SENT','APPROVED','DECLINED','EXPIRED','NEEDS_REAPPROVAL') NOT NULL DEFAULT 'DRAFT',
            version INT NOT NULL DEFAULT 1,
            approved_at DATETIME NULL,
            signature_id BIGINT UNSIGNED NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            callout_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            mileage_miles DECIMAL(12,2) NOT NULL DEFAULT 0,
            mileage_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
            mileage_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            expires_at DATE NULL,
            token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY estimate_no (estimate_no),
            UNIQUE KEY token (token),
            INDEX(customer_id), INDEX(request_id),
            PRIMARY KEY(id)
        ) $charset;");

        dbDelta("CREATE TABLE $jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            estimate_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            is_optional TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(id),
            INDEX(estimate_id)
        ) $charset;");

        dbDelta("CREATE TABLE $items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            estimate_id BIGINT UNSIGNED NOT NULL,
            job_id BIGINT UNSIGNED NULL,
            item_type ENUM('LABOR','PART','FEE','DISCOUNT','MILEAGE','CALLOUT') NOT NULL DEFAULT 'LABOR',
            description VARCHAR(255) NOT NULL,
            qty DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            taxable TINYINT(1) NOT NULL DEFAULT 1,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(id),
            INDEX(estimate_id), INDEX(job_id)
        ) $charset;");

        dbDelta("CREATE TABLE $sigs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            estimate_id BIGINT UNSIGNED NOT NULL,
            signer_name VARCHAR(128) NOT NULL,
            image_url TEXT NOT NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id), INDEX(estimate_id)
        ) $charset;");
    }

    /** ----------------------------------------------------------------
     * Admin entry point
     * -----------------------------------------------------------------*/
    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        $action = sanitize_key($_GET['action'] ?? 'list');
        switch ($action) {
            case 'new':  self::render_form(); break;
            case 'edit': self::render_form(intval($_GET['id']??0)); break;
            default:     self::render_list(); break;
        }
    }

    /** List screen */
    private static function render_list() {
        global $wpdb;
        $tblE = $wpdb->prefix.'arm_estimates';
        $tblC = $wpdb->prefix.'arm_customers';

        $page = max(1, intval($_GET['paged'] ?? 1));
        $per  = 20; $off = ($page-1)*$per;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT e.*, CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.email
            FROM $tblE e
            JOIN $tblC c ON c.id=e.customer_id
            ORDER BY e.created_at DESC
            LIMIT %d OFFSET %d
        ", $per, $off));

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tblE");
        $pages = max(1, ceil($total/$per));

        $new_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new');
        ?>
        <div class="wrap">
          <h1 class="wp-heading-inline"><?php _e('Estimates','arm-repair-estimates'); ?></h1>
          <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php _e('Add New','arm-repair-estimates'); ?></a>
          <hr class="wp-header-end">

          <table class="widefat striped">
            <thead><tr>
              <th>#</th><th><?php _e('Customer','arm-repair-estimates'); ?></th><th><?php _e('Email','arm-repair-estimates'); ?></th>
              <th><?php _e('Total','arm-repair-estimates'); ?></th><th><?php _e('Status','arm-repair-estimates'); ?></th><th><?php _e('Created','arm-repair-estimates'); ?></th><th><?php _e('Actions','arm-repair-estimates'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r):
                $edit    = admin_url('admin.php?page=arm-repair-estimates-builder&action=edit&id='.(int)$r->id);
                $send    = wp_nonce_url(admin_url('admin-post.php?action=arm_re_send_estimate&id='.(int)$r->id), 'arm_re_send_estimate');
                $view    = add_query_arg(['arm_estimate'=>$r->token], home_url('/'));
				$short_url = \ARM\Links\Shortlinks::get_or_create_for_estimate((int)$r->id, (string)$r->token);
                $approve = wp_nonce_url(admin_url('admin-post.php?action=arm_re_mark_status&id='.(int)$r->id.'&status=APPROVED'), 'arm_re_mark_status');
                $decline = wp_nonce_url(admin_url('admin-post.php?action=arm_re_mark_status&id='.(int)$r->id.'&status=DECLINED'), 'arm_re_mark_status');
            ?>
              <tr>
                <td><?php echo esc_html($r->estimate_no); ?></td>
                <td><?php echo esc_html($r->customer_name); ?></td>
                <td><?php echo esc_html($r->email); ?></td>
                <td><?php echo esc_html(number_format((float)$r->total, 2)); ?></td>
                <td><?php echo esc_html($r->status); ?></td>
                <td><?php echo esc_html($r->created_at); ?></td>
                <td>
                  <a href="<?php echo esc_url($edit); ?>"><?php _e('Edit','arm-repair-estimates'); ?></a> |
                  <a href="<?php echo esc_url($view); ?>" target="_blank"><?php _e('View','arm-repair-estimates'); ?></a> |
                  <a href="<?php echo esc_url($send); ?>"><?php _e('Send Email','arm-repair-estimates'); ?></a> |
                  <a href="<?php echo esc_url($approve); ?>"><?php _e('Mark Approved','arm-repair-estimates'); ?></a> |
                  <a href="<?php echo esc_url($decline); ?>"><?php _e('Mark Declined','arm-repair-estimates'); ?></a> |
                  <a href="<?php echo esc_url($short_url); ?>" target="_blank"><?php _e('Short Link','arm-repair-estimates'); ?></a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="7"><?php _e('No estimates yet.','arm-repair-estimates'); ?></td></tr>
            <?php endif; ?>
            </tbody>
          </table>

          <?php if ($pages>1): ?>
            <p>
            <?php for ($i=1;$i<=$pages;$i++):
                $url = esc_url(add_query_arg(['paged'=>$i]));
                echo $i==$page ? "<strong>$i</strong> " : "<a href='$url'>$i</a> ";
            endfor; ?>
            </p>
          <?php endif; ?>
        </div>
        <?php
    }

    /** Form (new/edit) */
    private static function render_form($id = 0) {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $tblE = $wpdb->prefix.'arm_estimates';
        $tblC = $wpdb->prefix.'arm_customers';
        $tblI = $wpdb->prefix.'arm_estimate_items';
        $tblJ = $wpdb->prefix.'arm_estimate_jobs';
        $tblR = $wpdb->prefix.'arm_estimate_requests';

        $defaults = [
            'id'=>0,
            'estimate_no'=> self::generate_estimate_no(),
            'status'=>'DRAFT',
            'customer_id'=>0,
            'request_id'=>null,
            'tax_rate'=> (float) get_option('arm_re_tax_rate',0),
            'expires_at'=>'',
            'notes'=>'',
            'subtotal'=>0,
            'tax_amount'=>0,
            'total'=>0,
            'callout_fee'=> (float)get_option('arm_re_callout_default',0),
            'mileage_miles'=> 0,
            'mileage_rate'=> (float)get_option('arm_re_mileage_rate_default',0),
            'mileage_total'=>0
        ];
        $estimate = (object)$defaults;
        $jobs = [];
        $items = [];

        if ($id) {
            $estimate = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblE WHERE id=%d", $id));
            if (!$estimate) { echo '<div class="notice notice-error"><p>Estimate not found.</p></div>'; return; }
            $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tblJ WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC", $id));
            $items= $wpdb->get_results($wpdb->prepare("SELECT * FROM $tblI WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC", $id));
        } elseif (!empty($_GET['from_request'])) {
            $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblR WHERE id=%d", intval($_GET['from_request'])));
            if ($req) {
                
                $prefill_customer = [
                    'first_name'=>$req->first_name,'last_name'=>$req->last_name,'email'=>$req->email,'phone'=>$req->phone,
                    'address'=>$req->customer_address,'city'=>$req->customer_city,'zip'=>$req->customer_zip
                ];
            }
        }

        
        $customer = null;
        if ($estimate->customer_id) $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblC WHERE id=%d", $estimate->customer_id));

        $action_url = admin_url('admin-post.php');
        $save_nonce = wp_create_nonce('arm_re_save_estimate');
        $send_url   = $id ? wp_nonce_url(admin_url('admin-post.php?action=arm_re_send_estimate&id='.(int)$id), 'arm_re_send_estimate') : '';
        ?>
        <div class="wrap">
          <h1><?php echo $id ? __('Edit Estimate','arm-repair-estimates') : __('New Estimate','arm-repair-estimates'); ?></h1>

          <form method="post" action="<?php echo esc_url($action_url); ?>" id="arm-re-est-form">
            <input type="hidden" name="action" value="arm_re_save_estimate">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($save_nonce); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$estimate->id; ?>">

            <h2><?php _e('Header','arm-repair-estimates'); ?></h2>
            <table class="form-table" role="presentation">
              <tr>
                <th><label><?php _e('Estimate #','arm-repair-estimates'); ?></label></th>
                <td><input type="text" name="estimate_no" value="<?php echo esc_attr($estimate->estimate_no); ?>" class="regular-text" required></td>
              </tr>
              <tr>
                <th><label><?php _e('Status','arm-repair-estimates'); ?></label></th>
                <td>
                  <select name="status">
                    <?php foreach (['DRAFT','SENT','APPROVED','DECLINED','EXPIRED','NEEDS_REAPPROVAL'] as $s): ?>
                      <option value="<?php echo esc_attr($s); ?>" <?php selected($estimate->status, $s); ?>><?php echo esc_html($s); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>

              <!-- Customer Search / Select -->
              <tr>
                <th><label><?php _e('Customer','arm-repair-estimates'); ?></label></th>
                <td>
                  <input type="hidden" name="customer_id" id="arm-customer-id" value="<?php echo (int)$estimate->customer_id; ?>">
                  <input type="text" id="arm-customer-search" class="regular-text" placeholder="<?php esc_attr_e('Search email, phone or name','arm-repair-estimates'); ?>">
                  <button type="button" class="button" id="arm-customer-search-btn"><?php _e('Search','arm-repair-estimates'); ?></button>
                  <div id="arm-customer-results" class="description" style="margin-top:6px;"></div>
                  <p class="description"><?php _e('Pick an existing customer or leave blank to create a new one using the fields below.','arm-repair-estimates'); ?></p>
                </td>
              </tr>
            </table>
            <h2><?php _e('Vehicle & VIN','arm-repair-estimates'); ?></h2>
            <table class="form-table" role="presentation">
              <tr>
                <th><?php _e('Vehicle Details','arm-repair-estimates'); ?></th>
                <td>
                  <label><?php _e('Year','arm-repair-estimates'); ?> <input type="text" id="arm-vehicle-year" name="vehicle_year" class="small-text"></label>
                  <label style="margin-left:10px;"><?php _e('Make','arm-repair-estimates'); ?> <input type="text" id="arm-vehicle-make" name="vehicle_make" class="regular-text" style="width:120px;"></label>
                  <label style="margin-left:10px;"><?php _e('Model','arm-repair-estimates'); ?> <input type="text" id="arm-vehicle-model" name="vehicle_model" class="regular-text" style="width:140px;"></label>
                  <label style="margin-left:10px;"><?php _e('Engine','arm-repair-estimates'); ?> <input type="text" id="arm-vehicle-engine" name="vehicle_engine" class="regular-text" style="width:140px;"></label>
                </td>
              </tr>
              <tr>
                <th><?php _e('VIN Lookup','arm-repair-estimates'); ?></th>
                <td>
                  <input type="text" id="arm-partstech-vin" class="regular-text" maxlength="17" placeholder="<?php esc_attr_e('VIN (17 characters)','arm-repair-estimates'); ?>">
                  <button type="button" class="button" id="arm-partstech-vin-btn"><?php _e('Decode VIN','arm-repair-estimates'); ?></button>
                  <span id="arm-partstech-vin-result" class="description" style="margin-left:10px;"></span>
                </td>
              </tr>
            </table>

            <?php if (\ARM\Integrations\PartsTech::is_configured()): ?>
            <div id="arm-partstech-panel" style="border:1px solid #e5e5e5;padding:15px;border-radius:6px;margin-bottom:20px;">
              <h3><?php _e('PartsTech Catalog','arm-repair-estimates'); ?></h3>
              <p class="description"><?php _e('Look up parts using VIN or keyword search. Results can be added directly to the first job in your estimate.', 'arm-repair-estimates'); ?></p>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" id="arm-partstech-search" class="regular-text" style="width:240px;" placeholder="<?php esc_attr_e('Search parts by keyword or part number','arm-repair-estimates'); ?>">
                <button type="button" class="button" id="arm-partstech-search-btn"><?php _e('Search Catalog','arm-repair-estimates'); ?></button>
              </div>
              <div id="arm-partstech-results" style="margin-top:12px;"></div>
            </div>
            <?php else: ?>
            <div class="notice notice-warning" style="padding:12px;margin:12px 0;">
              <p><?php _e('PartsTech API credentials are not configured. Add your API key in Settings to enable catalog search.', 'arm-repair-estimates'); ?></p>
            </div>
            <?php endif; ?>

            <h2><?php _e('Customer Details','arm-repair-estimates'); ?></h2>
            <?php
              $c = $customer ?: (object)($prefill_customer ?? ['first_name'=>'','last_name'=>'','email'=>'','phone'=>'','address'=>'','city'=>'','zip'=>'']);
            ?>
            <table class="form-table" role="presentation" id="arm-customer-fields">
              <tr><th><?php _e('First Name','arm-repair-estimates'); ?></th><td><input type="text" name="c_first_name" value="<?php echo esc_attr($c->first_name ?? ''); ?>"></td></tr>
              <tr><th><?php _e('Last Name','arm-repair-estimates'); ?></th><td><input type="text" name="c_last_name" value="<?php echo esc_attr($c->last_name ?? ''); ?>"></td></tr>
              <tr><th><?php _e('Email','arm-repair-estimates'); ?></th><td><input type="email" name="c_email" value="<?php echo esc_attr($c->email ?? ''); ?>"></td></tr>
              <tr><th><?php _e('Phone','arm-repair-estimates'); ?></th><td><input type="text" name="c_phone" value="<?php echo esc_attr($c->phone ?? ''); ?>"></td></tr>
              <tr><th><?php _e('Address','arm-repair-estimates'); ?></th><td><input type="text" name="c_address" value="<?php echo esc_attr($c->address ?? ''); ?>"></td></tr>
              <tr><th><?php _e('City','arm-repair-estimates'); ?></th><td><input type="text" name="c_city" value="<?php echo esc_attr($c->city ?? ''); ?>"></td></tr>
              <tr><th><?php _e('Zip','arm-repair-estimates'); ?></th><td><input type="text" name="c_zip" value="<?php echo esc_attr($c->zip ?? ''); ?>"></td></tr>
            </table>

            <h2><?php _e('Jobs & Line Items','arm-repair-estimates'); ?></h2>
            <p class="description"><?php _e('Group related parts/labor/fees into a Job. Each job can be accepted or rejected independently by the customer.','arm-repair-estimates'); ?></p>

            <div id="arm-jobs-wrap">
              <?php
              
              if ($jobs) {
                  foreach ($jobs as $j) {
                      self::render_job_block($j->id, $j->title, (int)$j->is_optional, (int)$j->sort_order, $items);
                  }
              } else {
                  
                  self::render_job_block(0, '', 0, 0, []);
              }
              ?>
            </div>
            <p><button type="button" class="button" id="arm-add-job"><?php _e('Add Job','arm-repair-estimates'); ?></button></p>

            <h2><?php _e('Fees & Totals','arm-repair-estimates'); ?></h2>
            <table class="form-table" role="presentation">
              <tr>
                <th><?php _e('Call-out Fee','arm-repair-estimates'); ?></th>
                <td><input type="number" step="0.01" name="callout_fee" id="arm-callout-fee" value="<?php echo esc_attr($estimate->callout_fee); ?>"></td>
              </tr>
              <tr>
                <th><?php _e('Mileage','arm-repair-estimates'); ?></th>
                <td>
                  <label><?php _e('Miles:','arm-repair-estimates'); ?> <input type="number" step="0.01" name="mileage_miles" id="arm-mileage-miles" value="<?php echo esc_attr($estimate->mileage_miles); ?>" class="small-text"></label>
                  &nbsp;
                  <label><?php _e('Rate/mi:','arm-repair-estimates'); ?> <input type="number" step="0.01" name="mileage_rate" id="arm-mileage-rate" value="<?php echo esc_attr($estimate->mileage_rate); ?>" class="small-text"></label>
                  <p class="description" style="margin-top:6px;">
                    <?php _e('Calculated mileage total:','arm-repair-estimates'); ?>
                    <strong>$<span id="arm-mileage-total-display"><?php echo esc_html(number_format((float)$estimate->mileage_total, 2)); ?></span></strong>
                  </p>
                </td>
              </tr>
              <tr>
                <th><?php _e('Tax Rate','arm-repair-estimates'); ?></th>
                <td>
                  <label>
                    <input type="number" step="0.01" name="tax_rate" id="arm-tax-rate" value="<?php echo esc_attr($estimate->tax_rate); ?>" class="small-text">
                    %
                  </label>
                </td>
              </tr>
              <tr>
                <th><?php _e('Expires','arm-repair-estimates'); ?></th>
                <td><input type="date" name="expires_at" value="<?php echo esc_attr($estimate->expires_at ? date('Y-m-d', strtotime($estimate->expires_at)) : ''); ?>"></td>
              </tr>
              <tr>
                <th><?php _e('Notes','arm-repair-estimates'); ?></th>
                <td><textarea name="notes" rows="5" class="large-text"><?php echo esc_textarea($estimate->notes); ?></textarea></td>
              </tr>
              <tr>
                <th><?php _e('Totals','arm-repair-estimates'); ?></th>
                <td>
                  <p><?php _e('Subtotal','arm-repair-estimates'); ?>: $<span id="arm-subtotal-display"><?php echo esc_html(number_format((float)$estimate->subtotal, 2)); ?></span></p>
                  <p><?php _e('Tax','arm-repair-estimates'); ?>: $<span id="arm-tax-display"><?php echo esc_html(number_format((float)$estimate->tax_amount, 2)); ?></span></p>
                  <p><strong><?php _e('Total','arm-repair-estimates'); ?>: $<span id="arm-total-display"><?php echo esc_html(number_format((float)$estimate->total, 2)); ?></span></strong></p>
                </td>
              </tr>
            </table>

            <p class="submit">
              <button type="submit" class="button button-primary"><?php _e('Save Estimate','arm-repair-estimates'); ?></button>
              <?php if ($id): ?>
                <a href="<?php echo esc_url($send_url); ?>" class="button"><?php _e('Send Email to Customer','arm-repair-estimates'); ?></a>
              <?php endif; ?>
            </p>
          </form>
        </div>

        <script>
        (function($){
          'use strict';

          var jobTemplate = <?php echo wp_json_encode(self::job_block_template()); ?>;
          var rowTemplate = <?php echo wp_json_encode(self::item_row_template()); ?>;
          var customerNonce = '<?php echo wp_create_nonce('arm_re_est_admin'); ?>';
          var taxApply = '<?php echo esc_js(get_option('arm_re_tax_apply','parts_labor')); ?>';

          function parseNum(value) {
            var n = parseFloat(value);
            return isNaN(n) ? 0 : n;
          }

          function nextJobIndex() {
            var max = -1;
            $('.arm-job-block').each(function(){
              var idx = parseInt($(this).data('job-index'), 10);
              if (!isNaN(idx) && idx > max) {
                max = idx;
              }
            });
            return max + 1;
          }

          function buildJobHtml(index) {
            var rows = rowTemplate
              .replace(/__JOB_INDEX__/g, index)
              .replace(/__ROW_INDEX__/g, 0);
            return jobTemplate
              .replace(/__JOB_INDEX__/g, index)
              .replace(/__JOB_TITLE__/g, '')
              .replace(/__JOB_OPT_CHECKED__/g, '')
              .replace('__JOB_ROWS__', rows);
          }

          function isLineTaxable(type, taxableChecked) {
            if (!taxableChecked) {
              return false;
            }
            if (taxApply === 'parts_only') {
              return type === 'PART';
            }
            return true;
          }

          function updateRowTotal($row) {
            var qty = parseNum($row.find('.arm-it-qty').val());
            var price = parseNum($row.find('.arm-it-price').val());
            var type = String($row.find('.arm-it-type').val() || '').toUpperCase();
            var line = qty * price;
            if (type === 'DISCOUNT') {
              line = -line;
            }
            $row.find('.arm-it-total').text(line.toFixed(2));
            return { amount: line, type: type, taxable: $row.find('.arm-it-taxable').is(':checked') };
          }

          function recalcTotals() {
            var subtotal = 0;
            var taxableBase = 0;

            $('.arm-job-block tbody tr').each(function(){
              var result = updateRowTotal($(this));
              subtotal += result.amount;
              if (isLineTaxable(result.type, result.taxable)) {
                taxableBase += Math.max(0, result.amount);
              }
            });

            var callout = parseNum($('#arm-callout-fee').val());
            var mileageMiles = parseNum($('#arm-mileage-miles').val());
            var mileageRate = parseNum($('#arm-mileage-rate').val());
            var mileageTotal = mileageMiles * mileageRate;

            if (callout > 0) {
              subtotal += callout;
            }
            if (mileageTotal > 0) {
              subtotal += mileageTotal;
            }

            var taxRate = parseNum($('#arm-tax-rate').val());
            var taxAmount = +(taxableBase * (taxRate / 100)).toFixed(2);
            var total = +(subtotal + taxAmount).toFixed(2);

            $('#arm-mileage-total-display').text(mileageTotal.toFixed(2));
            $('#arm-subtotal-display').text(subtotal.toFixed(2));
            $('#arm-tax-display').text(taxAmount.toFixed(2));
            $('#arm-total-display').text(total.toFixed(2));
          }

          $('#arm-add-job').on('click', function(){
            var idx = nextJobIndex();
            $('#arm-jobs-wrap').append(buildJobHtml(idx));
            recalcTotals();
          });

          $(document).on('click', '.arm-add-item', function(){
            var $job = $(this).closest('.arm-job-block');
            var idx = parseInt($job.data('job-index'), 10);
            if (isNaN(idx)) {
              idx = nextJobIndex();
              $job.attr('data-job-index', idx);
            }
            var rowCount = $job.find('tbody tr').length;
            var row = rowTemplate
              .replace(/__JOB_INDEX__/g, idx)
              .replace(/__ROW_INDEX__/g, rowCount);
            $job.find('tbody').append(row);
            recalcTotals();
          });

          $(document).on('click', '.arm-remove-item', function(){
            $(this).closest('tr').remove();
            recalcTotals();
          });

          $(document).on('input change', '.arm-it-qty, .arm-it-price, .arm-it-type, .arm-it-taxable', recalcTotals);
          $('#arm-callout-fee, #arm-mileage-miles, #arm-mileage-rate, #arm-tax-rate').on('input change', recalcTotals);

          $('#arm-customer-search').on('keydown', function(e){
            if (e.key === 'Enter') {
              e.preventDefault();
              $('#arm-customer-search-btn').trigger('click');
            }
          });

          $('#arm-customer-search-btn').on('click', function(e){
            e.preventDefault();
            var q = $('#arm-customer-search').val().trim();
            if (!q) {
              return;
            }
            var $out = $('#arm-customer-results');
            $out.text('<?php echo esc_js(__('Searching','arm-repair-estimates')); ?>');
            $.post(ajaxurl, {
              action: 'arm_re_search_customers',
              _ajax_nonce: customerNonce,
              q: q
            }).done(function(res){
              $out.empty();
              if (!res || !res.success || !res.data || !res.data.length) {
                $out.text('<?php echo esc_js(__('No matches.','arm-repair-estimates')); ?>');
                return;
              }
              res.data.forEach(function(r){
                var label = '#' + r.id + ' ' + (r.name || '').trim();
                if (r.email) {
                  label += ' ' + r.email;
                }
                var $a = $('<a href="#" class="button" style="margin:0 6px 6px 0;"></a>').text(label.trim());
                $a.on('click', function(ev){
                  ev.preventDefault();
                  $('#arm-customer-id').val(r.id);
                  $('#arm-customer-fields [name=c_first_name]').val(r.first_name || '');
                  $('#arm-customer-fields [name=c_last_name]').val(r.last_name || '');
                  $('#arm-customer-fields [name=c_email]').val(r.email || '');
                  $('#arm-customer-fields [name=c_phone]').val(r.phone || '');
                  $('#arm-customer-fields [name=c_address]').val(r.address || '');
                  $('#arm-customer-fields [name=c_city]').val(r.city || '');
                  $('#arm-customer-fields [name=c_zip]').val(r.zip || '');
                  $out.empty();
                });
                $out.append($a);
              });
            }).fail(function(){
              $out.text('<?php echo esc_js(__('Search failed. Please try again.','arm-repair-estimates')); ?>');
            });
          });

          recalcTotals();
        })(jQuery);
        </script>
        <?php
    }

    /** Render a Job block with its items (filtered by job_id) */
    private static function render_job_block($job_id, $title, $is_optional, $sort_order, $all_items) {
        $index = max(0, (int)$sort_order); 
        $items = array_filter($all_items, function($it) use ($job_id){
            return (int)$it->job_id === (int)$job_id;
        });

        
        $rows_html = '';
        $rowi = 0;
        if ($items) {
            foreach ($items as $it) {
                $rows_html .= self::render_item_row($index, $rowi++, $it);
            }
        } else {
            $rows_html .= self::render_item_row($index, 0, null);
        }

        $html = self::job_block_template();
        $html = str_replace('__JOB_INDEX__', esc_attr($index), $html);
        $html = str_replace('__JOB_TITLE__', esc_attr($title), $html);
        $html = str_replace('__JOB_OPT_CHECKED__', $is_optional ? 'checked' : '', $html);
        $html = str_replace('__JOB_ROWS__', $rows_html, $html);
        echo $html;
    }

     
    private static function render_item_row($job_index, $row_index, $it = null) {
        $job_index = (int) $job_index;
        $row_index = (int) $row_index;
        $it = $it ? (object) $it : (object) [];

        $types = [
            'LABOR'    => __('Labor', 'arm-repair-estimates'),
            'PART'     => __('Part', 'arm-repair-estimates'),
            'FEE'      => __('Fee', 'arm-repair-estimates'),
            'DISCOUNT' => __('Discount', 'arm-repair-estimates'),
        ];
        $type  = $it->item_type ?? 'LABOR';
        $desc  = $it->description ?? '';
        $qty   = isset($it->qty) ? (float) $it->qty : 1;
        $price = isset($it->unit_price) ? (float) $it->unit_price : (float) get_option('arm_re_labor_rate', 125);
        $tax   = isset($it->taxable) ? (int) $it->taxable : 1;
        $line  = isset($it->line_total) ? (float) $it->line_total : (($type === 'DISCOUNT' ? -1 : 1) * $qty * $price);
        ob_start();
        ?>
            <td>
              <select name="jobs[<?php echo esc_attr($job_index); ?>][items][<?php echo esc_attr($row_index); ?>][type]" class="arm-it-type">
                <?php foreach ($types as $key => $label): ?>
                  <option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="jobs[<?php echo esc_attr($job_index); ?>][items][<?php echo esc_attr($row_index); ?>][desc]" value="<?php echo esc_attr($desc); ?>" class="widefat"></td>
            <td><input type="number" step="0.01" name="jobs[<?php echo esc_attr($job_index); ?>][items][<?php echo esc_attr($row_index); ?>][qty]" value="<?php echo esc_attr($qty); ?>" class="small-text arm-it-qty"></td>
            <td><input type="number" step="0.01" name="jobs[<?php echo esc_attr($job_index); ?>][items][<?php echo esc_attr($row_index); ?>][price]" value="<?php echo esc_attr($price); ?>" class="regular-text arm-it-price"></td>
            <td><input type="checkbox" name="jobs[<?php echo esc_attr($job_index); ?>][items][<?php echo esc_attr($row_index); ?>][taxable]" value="1" <?php checked($tax, 1); ?> class="arm-it-taxable"></td>
            <td class="arm-it-total"><?php echo esc_html(number_format((float) $line, 2)); ?></td>
          </tr>
        <?php
        return ob_get_clean();
    }
    /**
     * Tiny raw template used by inline JS to add rows dynamically.
     */

     
public static function item_row_template() {
    $types = ['LABOR'=>'Labor','PART'=>'Part','FEE'=>'Fee','DISCOUNT'=>'Discount'];
    $opts = '';
    foreach ($types as $k=>$v) {
        $opts .= '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>';
    }
    return '<tr>
      <td><select name="jobs[__JOB_INDEX__][items][__ROW_INDEX__][type]" class="arm-it-type">'.$opts.'</select></td>
      <td><input type="text" name="jobs[__JOB_INDEX__][items][__ROW_INDEX__][desc]" class="widefat"></td>
      <td><input type="number" step="0.01" name="jobs[__JOB_INDEX__][items][__ROW_INDEX__][qty]" value="1" class="small-text arm-it-qty"></td>
      <td><input type="number" step="0.01" name="jobs[__JOB_INDEX__][items][__ROW_INDEX__][price]" value="0.00" class="regular-text arm-it-price"></td>
      <td><input type="checkbox" name="jobs[__JOB_INDEX__][items][__ROW_INDEX__][taxable]" value="1" checked class="arm-it-taxable"></td>
      <td class="arm-it-total">0.00</td>
      <td><button type="button" class="button arm-remove-item">&times;</button></td>
    </tr>';
}

    /** Tiny raw template used by inline JS to add rows dynamically */

    /** ----------------------------------------------------------------
     * Handlers
     * -----------------------------------------------------------------*/

    /** Save estimate (create/update), handle customer create/update, items & jobs, totals */
    public static function handle_save_estimate() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_save_estimate');

        global $wpdb;
        $tblE = $wpdb->prefix.'arm_estimates';
        $tblC = $wpdb->prefix.'arm_customers';
        $tblI = $wpdb->prefix.'arm_estimate_items';
        $tblJ = $wpdb->prefix.'arm_estimate_jobs';

        $id = intval($_POST['id'] ?? 0);
        $estimate_no = sanitize_text_field($_POST['estimate_no']);
        $status = in_array($_POST['status'] ?? 'DRAFT', ['DRAFT','SENT','APPROVED','DECLINED','EXPIRED','NEEDS_REAPPROVAL'], true) ? $_POST['status'] : 'DRAFT';
        $customer_id = intval($_POST['customer_id'] ?? 0);

        
        $cdata = [
            'first_name'=>sanitize_text_field($_POST['c_first_name'] ?? ''),
            'last_name' =>sanitize_text_field($_POST['c_last_name'] ?? ''),
            'email'     =>sanitize_email($_POST['c_email'] ?? ''),
            'phone'     =>sanitize_text_field($_POST['c_phone'] ?? ''),
            'address'   =>sanitize_text_field($_POST['c_address'] ?? ''),
            'city'      =>sanitize_text_field($_POST['c_city'] ?? ''),
            'zip'       =>sanitize_text_field($_POST['c_zip'] ?? ''),
            'updated_at'=>current_time('mysql')
        ];
        if (!$customer_id) {
            
            if (!empty($cdata['first_name']) || !empty($cdata['last_name']) || !empty($cdata['email'])) {
                $cdata['created_at'] = current_time('mysql');
                $wpdb->insert($tblC, $cdata);
                $customer_id = (int) $wpdb->insert_id;
            } else {
                wp_die('Select or create a customer.');
            }
        } else {
            
            $wpdb->update($tblC, $cdata, ['id'=>$customer_id]);
        }

        
        $jobs_post = $_POST['jobs'] ?? null;
        $prepared_items = [];  
        $jobs_to_insert = [];  
        $job_index_to_id = []; 

        $rowGlobal = 0;
        if (is_array($jobs_post)) {
            $sortj = 0;
            foreach ($jobs_post as $jIdx => $job) {
                $title = sanitize_text_field($job['title'] ?? '');
                $is_optional = !empty($job['is_optional']) ? 1 : 0;
                $jobs_to_insert[] = ['title'=>$title ?: sprintf(__('Job %d','arm-repair-estimates'), $sortj+1), 'is_optional'=>$is_optional, 'sort'=>$sortj++];
                $items = $job['items'] ?? [];
                $rowi = 0;
                foreach ($items as $row) {
                    $desc = sanitize_text_field($row['desc'] ?? '');
                    if ($desc === '') continue;
                    $type = in_array($row['type'] ?? 'LABOR', ['LABOR','PART','FEE','DISCOUNT'], true) ? $row['type'] : 'LABOR';
                    $qty  = (float) ($row['qty'] ?? 1);
                    $price= (float) ($row['price'] ?? 0);
                    $tax  = !empty($row['taxable']) ? 1 : 0;
                    $ltot = ($type==='DISCOUNT' ? -1 : 1) * ($qty * $price);
                    $prepared_items[] = ['type'=>$type,'desc'=>$desc,'qty'=>$qty,'price'=>$price,'tax'=>$tax,'ltot'=>$ltot,'sort'=>$rowGlobal++,'job_local_index'=>($sortj-1)];
                    $rowi++;
                }
            }
        } else {
            
            $items = $_POST['items'] ?? [];
            $rowi = 0;
            foreach ($items as $row) {
                $desc = sanitize_text_field($row['desc'] ?? '');
                if ($desc === '') continue;
                $type = in_array($row['type'] ?? 'LABOR', ['LABOR','PART','FEE','DISCOUNT'], true) ? $row['type'] : 'LABOR';
                $qty  = (float) ($row['qty'] ?? 1);
                $price= (float) ($row['price'] ?? 0);
                $tax  = !empty($row['taxable']) ? 1 : 0;
                $ltot = ($type==='DISCOUNT' ? -1 : 1) * ($qty * $price);
                $prepared_items[] = ['type'=>$type,'desc'=>$desc,'qty'=>$qty,'price'=>$price,'tax'=>$tax,'ltot'=>$ltot,'sort'=>$rowGlobal++,'job_local_index'=>0];
                $rowi++;
            }
            $jobs_to_insert[] = ['title'=>__('Job 1','arm-repair-estimates'), 'is_optional'=>0, 'sort'=>0];
        }

        
        $callout_fee   = (float) ($_POST['callout_fee'] ?? 0);
        $mileage_miles = (float) ($_POST['mileage_miles'] ?? 0);
        $mileage_rate  = (float) ($_POST['mileage_rate'] ?? 0);
        $mileage_total = round($mileage_miles * $mileage_rate, 2);

        $tax_rate   = (float) ($_POST['tax_rate'] ?? 0);
        $tax_apply  = get_option('arm_re_tax_apply','parts_labor'); 

        
        $totals = Totals::compute($prepared_items, $tax_rate, $tax_apply, $callout_fee, $mileage_miles, $mileage_rate);
        $subtotal   = $totals['subtotal'];
        $tax_amount = $totals['tax_amount'];
        $total      = $totals['total'];

        $data = [
            'estimate_no'=>$estimate_no,'status'=>$status,'customer_id'=>$customer_id,
            'tax_rate'=>$tax_rate,'subtotal'=>round($subtotal,2),'tax_amount'=>$tax_amount,'total'=>$total,
            'callout_fee'=>round($callout_fee,2),
            'mileage_miles'=>round($mileage_miles,2),
            'mileage_rate'=>round($mileage_rate,2),
            'mileage_total'=>round($totals['mileage_total'],2),
            'notes'=>wp_kses_post($_POST['notes'] ?? ''),'expires_at'=>($_POST['expires_at'] ?? null) ?: null,
            'updated_at'=>current_time('mysql')
        ];

        if ($id) {
            
            $prev = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblE WHERE id=%d", $id));
            $wpdb->update($tblE, $data, ['id'=>$id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $data['token'] = self::generate_token();
            $data['request_id'] = isset($_GET['from_request']) ? intval($_GET['from_request']) : null;
            if (empty($data['estimate_no'])) $data['estimate_no'] = self::generate_estimate_no();
            $wpdb->insert($tblE, $data);
            $id = (int)$wpdb->insert_id;
        }

        
        $wpdb->query($wpdb->prepare("DELETE FROM $tblI WHERE estimate_id=%d", $id));
        $wpdb->query($wpdb->prepare("DELETE FROM $tblJ WHERE estimate_id=%d", $id));

        
        $job_db_ids = [];
        foreach ($jobs_to_insert as $j) {
            $wpdb->insert($tblJ, [
                'estimate_id'=>$id,
                'title'=>$j['title'],
                'is_optional'=>$j['is_optional'],
                'status'=>'PENDING',
                'sort_order'=>$j['sort']
            ]);
            $job_db_ids[] = (int)$wpdb->insert_id;
        }

        
        foreach ($prepared_items as $pi) {
            $mapped_job_id = $job_db_ids[ $pi['job_local_index'] ] ?? null;
            $wpdb->insert($tblI, [
                'estimate_id'=>$id,
                'job_id'=>$mapped_job_id,
                'item_type'=>$pi['type'],
                'description'=>$pi['desc'],
                'qty'=>$pi['qty'],
                'unit_price'=>$pi['price'],
                'taxable'=>$pi['tax'],
                'line_total'=>round($pi['ltot'],2),
                'sort_order'=>$pi['sort']
            ]);
        }

        
        if (!empty($prev) && $prev->status === 'APPROVED') {
            $changed = (abs($prev->subtotal - $subtotal) > 0.009) ||
                       (abs($prev->tax_amount - $tax_amount) > 0.009) ||
                       (abs($prev->total - $total) > 0.009);
            if ($changed) {
                $wpdb->update($tblE, [
                    'status'=>'NEEDS_REAPPROVAL',
                    'version'=>(int)$prev->version + 1,
                    'approved_at'=>null,
                    'signature_id'=>null,
                    'updated_at'=>current_time('mysql')
                ], ['id'=>$id]);
                \ARM\Audit\Logger::log('estimate', $id, 'approval_revoked', 'admin', ['reason'=>'edited','prev_status'=>$prev->status]);
            }
        }

        wp_redirect(admin_url('admin.php?page=arm-repair-estimates-builder&action=edit&id='.$id.'&saved=1'));
        exit;
    }

    /** Send estimate email to customer with public link */
    public static function handle_send_estimate() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_send_estimate');
        global $wpdb;
        $tblE = $wpdb->prefix.'arm_estimates';
        $tblC = $wpdb->prefix.'arm_customers';
        $id = intval($_GET['id'] ?? 0);
        $est = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblE WHERE id=%d", $id));
        if (!$est) wp_die('Estimate not found');

        $cust = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblC WHERE id=%d", $est->customer_id));
        if (!$cust || !$cust->email) wp_die('Customer email missing');

        $link = add_query_arg(['arm_estimate'=>$est->token], home_url('/'));
        $subj = sprintf('Estimate %s from %s', $est->estimate_no, wp_parse_url(home_url(), PHP_URL_HOST));
        $body = "Hello {$cust->first_name},\n\n"
              . "Please review your estimate {$est->estimate_no} here:\n$link\n\n"
              . "Total: $" . number_format((float)$est->total,2) . "\n\n"
              . "You can accept or decline on that page.\n\nThank you!";
        wp_mail($cust->email, $subj, $body);

        if ($est->status === 'DRAFT') {
            $wpdb->update($tblE, ['status'=>'SENT','updated_at'=>current_time('mysql')], ['id'=>$id]);
        }

        wp_redirect(admin_url('admin.php?page=arm-repair-estimates-builder&action=edit&id='.$id.'&sent=1'));
        exit;
    }

    /** Admin mark status */
    public static function handle_mark_status() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_mark_status');
        global $wpdb;
        $id = intval($_GET['id'] ?? 0);
        $status = in_array($_GET['status'] ?? '', ['APPROVED','DECLINED','EXPIRED'], true) ? $_GET['status'] : '';
        if (!$status) wp_die('Invalid status');
        $tblE = $wpdb->prefix.'arm_estimates';
        $wpdb->update($tblE, ['status'=>$status,'updated_at'=>current_time('mysql')], ['id'=>$id]);
        wp_redirect(admin_url('admin.php?page=arm-repair-estimates-builder&action=edit&id='.$id.'&marked=1'));
        exit;
    }

    /** Customer search (email/phone/name) */
    public static function ajax_search_customers() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        check_ajax_referer('arm_re_est_admin');
        global $wpdb; $tbl = $wpdb->prefix.'arm_customers';
        $q = trim(sanitize_text_field($_POST['q'] ?? ''));
        if ($q === '') wp_send_json_success([]);
        $like = '%'.$wpdb->esc_like($q).'%';
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT id, first_name, last_name, email, phone, address, city, zip
            FROM $tbl
            WHERE email LIKE %s OR phone LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s
            ORDER BY id DESC LIMIT 20
        ", $like, $like, $like), ARRAY_A);
        $out = array_map(function($r){
            $r['name'] = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
            return $r;
        }, $rows ?: []);
        wp_send_json_success($out);
    }

    /** Helpers */
    private static function generate_token() {
        return bin2hex(random_bytes(16));
    }
    private static function generate_estimate_no() {
        return 'EST-' . date('Ymd') . '-' . wp_rand(1000,9999);
    }
}
