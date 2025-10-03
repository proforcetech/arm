<?php
namespace ARM\Invoices;

if (!defined('ABSPATH')) exit;

class Controller {

    /** --------------------------------------------------------------
     * Boot hooks (submenu, actions, public view)
     * --------------------------------------------------------------*/
    public static function boot() {
        // Admin UI
        add_action('admin_menu', function () {
            add_submenu_page(
                'arm-repair-estimates',
                __('Invoices', 'arm-repair-estimates'),
                __('Invoices', 'arm-repair-estimates'),
                'manage_options',
                'arm-repair-invoices',
                [__CLASS__, 'render_admin']
            );
        });

        add_action('admin_post_arm_re_save_invoice', [__CLASS__, 'handle_save_invoice']);

        // Convert from Estimate action
        add_action('admin_post_arm_re_convert_estimate_to_invoice', [__CLASS__, 'convert_from_estimate']);

        // AJAX helpers for admin UI
        add_action('wp_ajax_arm_re_invoice_customer_vehicles', [__CLASS__, 'ajax_customer_vehicles']);

        // Public viewing via token
        add_filter('query_vars', function ($vars) { $vars[] = 'arm_invoice'; return $vars; });
        add_action('template_redirect', [__CLASS__, 'render_public_if_requested']);
    }

    /** --------------------------------------------------------------
     * DB tables for invoices and items
     * --------------------------------------------------------------*/
    public static function install_tables() {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $invT = $wpdb->prefix . 'arm_invoices';
        $itT  = $wpdb->prefix . 'arm_invoice_items';

        dbDelta("CREATE TABLE $invT (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            estimate_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            vehicle_estimate_id BIGINT UNSIGNED NULL,
            vin VARCHAR(64) NULL,
            license_plate VARCHAR(32) NULL,
            current_mileage INT UNSIGNED NULL,
            last_service_mileage INT UNSIGNED NULL,
            invoice_no VARCHAR(32) NOT NULL,
            status ENUM('UNPAID','PAID','VOID') NOT NULL DEFAULT 'UNPAID',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY invoice_no (invoice_no),
            UNIQUE KEY token (token),
            INDEX(customer_id), INDEX(estimate_id), INDEX(vehicle_estimate_id),
            PRIMARY KEY(id)
        ) $charset;");

        // Upgrade path for legacy installs (pre vehicle metadata)
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $invT", 0);
        $columns = $columns ? array_flip($columns) : [];

        if (!isset($columns['vehicle_estimate_id'])) {
            $wpdb->query("ALTER TABLE $invT ADD vehicle_estimate_id BIGINT UNSIGNED NULL AFTER customer_id");
        }
        if (!isset($columns['vin'])) {
            $wpdb->query("ALTER TABLE $invT ADD vin VARCHAR(64) NULL AFTER vehicle_estimate_id");
        }
        if (!isset($columns['license_plate'])) {
            $wpdb->query("ALTER TABLE $invT ADD license_plate VARCHAR(32) NULL AFTER vin");
        }
        if (!isset($columns['current_mileage'])) {
            $wpdb->query("ALTER TABLE $invT ADD current_mileage INT UNSIGNED NULL AFTER license_plate");
        }
        if (!isset($columns['last_service_mileage'])) {
            $wpdb->query("ALTER TABLE $invT ADD last_service_mileage INT UNSIGNED NULL AFTER current_mileage");
        }
        $hasIndex = $wpdb->get_var("SHOW INDEX FROM $invT WHERE Key_name='vehicle_estimate_id'");
        if (!$hasIndex) {
            $wpdb->query("ALTER TABLE $invT ADD INDEX vehicle_estimate_id (vehicle_estimate_id)");
        }

        // Allow the same item types as estimates (plus legacy)
        dbDelta("CREATE TABLE $itT (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            item_type ENUM('LABOR','PART','FEE','DISCOUNT','MILEAGE','CALLOUT') NOT NULL DEFAULT 'LABOR',
            description VARCHAR(255) NOT NULL,
            qty DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            taxable TINYINT(1) NOT NULL DEFAULT 1,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(id),
            INDEX(invoice_id)
        ) $charset;");
    }

    /** --------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------*/
    private static function next_invoice_no() {
        return 'INV-' . date('Ymd') . '-' . wp_rand(1000, 9999);
    }
    private static function token() {
        return bin2hex(random_bytes(16));
    }

    private static function item_types(): array {
        return [
            'LABOR'    => __('Labor', 'arm-repair-estimates'),
            'PART'     => __('Part', 'arm-repair-estimates'),
            'FEE'      => __('Fee', 'arm-repair-estimates'),
            'DISCOUNT' => __('Discount', 'arm-repair-estimates'),
            'MILEAGE'  => __('Mileage', 'arm-repair-estimates'),
            'CALLOUT'  => __('Call-out', 'arm-repair-estimates'),
        ];
    }

    /** --------------------------------------------------------------
     * Convert an APPROVED estimate into an invoice
     * --------------------------------------------------------------*/
    public static function convert_from_estimate() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_convert_estimate_to_invoice');

        global $wpdb;
        $eid  = (int)($_GET['id'] ?? 0);

        $eT   = $wpdb->prefix . 'arm_estimates';
        $eiT  = $wpdb->prefix . 'arm_estimate_items';
        $invT = $wpdb->prefix . 'arm_invoices';
        $iiT  = $wpdb->prefix . 'arm_invoice_items';

        $e = $wpdb->get_row($wpdb->prepare("SELECT * FROM $eT WHERE id=%d", $eid));
        if (!$e) wp_die('Estimate not found');

        // Require APPROVED; relax by removing these two lines if desired
        if ($e->status !== 'APPROVED') {
            wp_die('Estimate must be APPROVED before conversion.');
        }

        // Create invoice shell
        $wpdb->insert($invT, [
            'estimate_id' => $e->id,
            'customer_id' => $e->customer_id,
            'invoice_no'  => self::next_invoice_no(),
            'status'      => 'UNPAID',
            'subtotal'    => $e->subtotal,
            'tax_rate'    => $e->tax_rate,
            'tax_amount'  => $e->tax_amount,
            'total'       => $e->total,
            'notes'       => $e->notes,
            'token'       => self::token(),
            'created_at'  => current_time('mysql'),
        ]);
        $inv_id = (int)$wpdb->insert_id;

        self::apply_estimate_vehicle_meta($inv_id, $e);

        // Copy estimate items
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $eiT WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC", $eid
        ));
        $i = 0;
        foreach ($items as $it) {
            $wpdb->insert($iiT, [
                'invoice_id' => $inv_id,
                'item_type'  => $it->item_type,
                'description'=> $it->description,
                'qty'        => $it->qty,
                'unit_price' => $it->unit_price,
                'taxable'    => $it->taxable,
                'line_total' => $it->line_total,
                'sort_order' => $i++,
            ]);
        }

        // If estimate had callout/mileage in totals but not as explicit lines, add them as fee items for clarity.
        // (Estimates module stores these in the estimates table.)
        $addedExtras = 0;
        if (!empty($e->callout_fee) && (float)$e->callout_fee > 0) {
            $wpdb->insert($iiT, [
                'invoice_id' => $inv_id,
                'item_type'  => 'CALLOUT',
                'description'=> __('Call-out Fee','arm-repair-estimates'),
                'qty'        => 1,
                'unit_price' => (float)$e->callout_fee,
                'taxable'    => 0,
                'line_total' => (float)$e->callout_fee,
                'sort_order' => $i++,
            ]);
            $addedExtras++;
        }
        if (!empty($e->mileage_total) && (float)$e->mileage_total > 0) {
            $desc = sprintf(
                /* translators: 1: miles, 2: rate */
                __('Mileage (%1$s mi @ %2$s/mi)', 'arm-repair-estimates'),
                number_format_i18n((float)$e->mileage_miles, 2),
                number_format_i18n((float)$e->mileage_rate, 2)
            );
            $wpdb->insert($iiT, [
                'invoice_id' => $inv_id,
                'item_type'  => 'MILEAGE',
                'description'=> $desc,
                'qty'        => (float)$e->mileage_miles,
                'unit_price' => (float)$e->mileage_rate,
                'taxable'    => 0,
                'line_total' => (float)$e->mileage_total,
                'sort_order' => $i++,
            ]);
            $addedExtras++;
        }

        // Audit log (namespaced audit if available)
        if (class_exists('\\ARM\\Audit\\Logger')) {
            \ARM\Audit\Logger::log('estimate', $eid, 'converted_to_invoice', 'admin', ['invoice_id' => $inv_id, 'extras' => $addedExtras]);
        }

        wp_redirect(admin_url('admin.php?page=arm-repair-invoices&converted=' . $inv_id));
        exit;
    }

    /** --------------------------------------------------------------
     * Admin list UI
     * --------------------------------------------------------------*/
    public static function render_admin() {
        if (!current_user_can('manage_options')) return;

        $action = sanitize_key($_GET['action'] ?? 'list');
        if ($action === 'new') {
            $customer = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
            self::render_form(0, $customer);
            return;
        }
        if ($action === 'edit') {
            self::render_form((int) ($_GET['id'] ?? 0));
            return;
        }

        self::render_list();
    }

    private static function render_list() {
        global $wpdb;
        $invT = $wpdb->prefix . 'arm_invoices';
        $cT   = $wpdb->prefix . 'arm_customers';

        $rows = $wpdb->get_results("
            SELECT i.*, CONCAT(c.first_name,' ',c.last_name) AS customer, c.email
            FROM $invT i JOIN $cT c ON c.id=i.customer_id
            ORDER BY i.created_at DESC
            LIMIT 300
        ");

        $new_url = admin_url('admin.php?page=arm-repair-invoices&action=new');
        ?>
        <div class="wrap">
          <h1 class="wp-heading-inline"><?php _e('Invoices', 'arm-repair-estimates'); ?></h1>
          <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php _e('Add New', 'arm-repair-estimates'); ?></a>
          <hr class="wp-header-end">
          <table class="widefat striped">
            <thead>
              <tr>
                <th>#</th>
                <th><?php _e('Customer','arm-repair-estimates'); ?></th>
                <th><?php _e('Email','arm-repair-estimates'); ?></th>
                <th><?php _e('VIN','arm-repair-estimates'); ?></th>
                <th><?php _e('License Plate','arm-repair-estimates'); ?></th>
                <th><?php _e('Mileage','arm-repair-estimates'); ?></th>
                <th><?php _e('Total','arm-repair-estimates'); ?></th>
                <th><?php _e('Status','arm-repair-estimates'); ?></th>
                <th><?php _e('Created','arm-repair-estimates'); ?></th>
                <th><?php _e('Actions','arm-repair-estimates'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rows): foreach ($rows as $r):
                $view = add_query_arg(['arm_invoice' => $r->token], home_url('/'));
                $edit = admin_url('admin.php?page=arm-repair-invoices&action=edit&id=' . (int)$r->id);
                                $short_url = \ARM\Links\Shortlinks::get_or_create_for_invoice((int)$r->id, (string)$r->token);
                $mileage = [];
                if ($r->current_mileage !== null && $r->current_mileage !== '') {
                    $mileage[] = sprintf(__('Current: %s','arm-repair-estimates'), number_format_i18n((float)$r->current_mileage));
                }
                if ($r->last_service_mileage !== null && $r->last_service_mileage !== '') {
                    $mileage[] = sprintf(__('Last Service: %s','arm-repair-estimates'), number_format_i18n((float)$r->last_service_mileage));
                }
              ?>
              <tr>
                <td><?php echo esc_html($r->invoice_no); ?></td>
                <td><?php echo esc_html($r->customer); ?></td>
                <td><?php echo esc_html($r->email); ?></td>
                <td><?php echo esc_html($r->vin); ?></td>
                <td><?php echo esc_html($r->license_plate); ?></td>
                <td><?php echo esc_html(implode(' / ', $mileage)); ?></td>
                <td><?php echo esc_html(number_format((float)$r->total, 2)); ?></td>
                <td><?php echo esc_html($r->status); ?></td>
                <td><?php echo esc_html($r->created_at); ?></td>
                <td>
                  <a href="<?php echo esc_url($edit); ?>"><?php _e('Edit','arm-repair-estimates'); ?></a> |
                  <a href="<?php echo esc_url($view); ?>" target="_blank"><?php _e('View','arm-repair-estimates'); ?></a>
                </td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="10"><?php _e('No invoices yet.','arm-repair-estimates'); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

    private static function render_form(int $id = 0, int $preset_customer = 0) {
        global $wpdb;
        $invT = $wpdb->prefix . 'arm_invoices';
        $itT  = $wpdb->prefix . 'arm_invoice_items';
        $cT   = $wpdb->prefix . 'arm_customers';

        $invoice = null;
        $items   = [];
        $customer_id = $preset_customer;
        $estimate_id = 0;

        if ($id) {
            $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM $invT WHERE id=%d", $id));
            if (!$invoice) {
                echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Invoice not found.', 'arm-repair-estimates') . '</p></div></div>';
                return;
            }
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $itT WHERE invoice_id=%d ORDER BY sort_order ASC, id ASC", $id));
            $customer_id = (int) $invoice->customer_id;
            $estimate_id = (int) $invoice->estimate_id;
        }

        if (!$invoice) {
            $invoice = (object) [];
        }

        $customers = $wpdb->get_results("SELECT id, first_name, last_name, email FROM $cT ORDER BY first_name ASC, last_name ASC LIMIT 500");
        $vehicles  = $customer_id ? self::fetch_customer_vehicles($customer_id) : [];

        $tax_rate  = isset($invoice->tax_rate) ? (float) $invoice->tax_rate : (float) get_option('arm_re_tax_rate', 0);
        $status    = isset($invoice->status) ? $invoice->status : 'UNPAID';
        $invoice_no = isset($invoice->invoice_no) ? $invoice->invoice_no : '';
        $subtotal = isset($invoice->subtotal) ? (float) $invoice->subtotal : 0.0;
        $tax_amount = isset($invoice->tax_amount) ? (float) $invoice->tax_amount : 0.0;
        $total = isset($invoice->total) ? (float) $invoice->total : 0.0;

        if (!$items) {
            $items = [ (object) ['item_type' => 'LABOR', 'description' => '', 'qty' => 1, 'unit_price' => 0, 'taxable' => 1] ];
        }

        $ajax_nonce = wp_create_nonce('arm_re_invoice_vehicle_lookup');
        $action_url = admin_url('admin-post.php');
        $title = $id ? __('Edit Invoice', 'arm-repair-estimates') : __('New Invoice', 'arm-repair-estimates');
        ?>
        <div class="wrap">
          <h1><?php echo esc_html($title); ?></h1>
          <?php if (!empty($_GET['updated'])): ?>
            <div class="notice notice-success"><p><?php _e('Invoice saved.', 'arm-repair-estimates'); ?></p></div>
          <?php endif; ?>
          <form method="post" action="<?php echo esc_url($action_url); ?>">
            <input type="hidden" name="action" value="arm_re_save_invoice">
            <input type="hidden" name="invoice_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="estimate_id" value="<?php echo esc_attr($estimate_id); ?>">
            <input type="hidden" name="vehicle_estimate_id" id="arm-invoice-vehicle-id" value="<?php echo esc_attr($invoice->vehicle_estimate_id ?? 0); ?>">
            <?php wp_nonce_field('arm_re_save_invoice', 'arm_re_invoice_nonce'); ?>

            <table class="form-table">
              <tr>
                <th scope="row"><label for="arm-invoice-customer"><?php _e('Customer', 'arm-repair-estimates'); ?></label></th>
                <td>
                  <select name="customer_id" id="arm-invoice-customer" required>
                    <option value=""><?php _e('Select a customer', 'arm-repair-estimates'); ?></option>
                    <?php foreach ($customers as $cust):
                        $label = trim($cust->first_name . ' ' . $cust->last_name);
                        if ($cust->email) $label .= ' &lt;' . $cust->email . '&gt;';
                    ?>
                    <option value="<?php echo (int) $cust->id; ?>" <?php selected($customer_id, (int)$cust->id); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (!$customers): ?>
                    <p class="description"><?php _e('No customers found. Create a customer first.', 'arm-repair-estimates'); ?></p>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-vehicle"><?php _e('Vehicle', 'arm-repair-estimates'); ?></label></th>
                <td>
                  <select id="arm-invoice-vehicle">
                    <option value=""><?php _e('— Select vehicle —', 'arm-repair-estimates'); ?></option>
                    <?php foreach ($vehicles as $veh): ?>
                      <option value="<?php echo (int) $veh['id']; ?>" data-meta="<?php echo esc_attr(wp_json_encode($veh)); ?>" <?php selected(($invoice->vehicle_estimate_id ?? 0), (int)$veh['id']); ?>><?php echo esc_html($veh['label']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="description"><?php _e('Selecting a vehicle will populate the VIN, license plate, and mileage fields. These can be edited afterwards.', 'arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-vin"><?php _e('VIN', 'arm-repair-estimates'); ?></label></th>
                <td><input type="text" id="arm-invoice-vin" name="vin" value="<?php echo esc_attr($invoice->vin ?? ''); ?>" class="regular-text"></td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-plate"><?php _e('License Plate', 'arm-repair-estimates'); ?></label></th>
                <td><input type="text" id="arm-invoice-plate" name="license_plate" value="<?php echo esc_attr($invoice->license_plate ?? ''); ?>" class="regular-text"></td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-current-mileage"><?php _e('Current Mileage', 'arm-repair-estimates'); ?></label></th>
                <td><input type="number" id="arm-invoice-current-mileage" name="current_mileage" value="<?php echo esc_attr($invoice->current_mileage ?? ''); ?>" min="0" step="1"></td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-last-mileage"><?php _e('Last Service Mileage', 'arm-repair-estimates'); ?></label></th>
                <td><input type="number" id="arm-invoice-last-mileage" name="last_service_mileage" value="<?php echo esc_attr($invoice->last_service_mileage ?? ''); ?>" min="0" step="1"></td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-number"><?php _e('Invoice #', 'arm-repair-estimates'); ?></label></th>
                <td><input type="text" id="arm-invoice-number" name="invoice_no" value="<?php echo esc_attr($invoice_no); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Autogenerated if left blank', 'arm-repair-estimates'); ?>"></td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-status"><?php _e('Status', 'arm-repair-estimates'); ?></label></th>
                <td>
                  <select name="status" id="arm-invoice-status">
                    <?php foreach (['UNPAID' => __('Unpaid', 'arm-repair-estimates'), 'PAID' => __('Paid', 'arm-repair-estimates'), 'VOID' => __('Void', 'arm-repair-estimates')] as $key => $label): ?>
                      <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="arm-invoice-tax-rate"><?php _e('Tax Rate (%)', 'arm-repair-estimates'); ?></label></th>
                <td><input type="number" step="0.01" name="tax_rate" id="arm-invoice-tax-rate" value="<?php echo esc_attr($tax_rate); ?>" min="0"></td>
              </tr>
            </table>

            <h2><?php _e('Line Items', 'arm-repair-estimates'); ?></h2>
            <table class="widefat striped" id="arm-invoice-items">
              <thead>
                <tr>
                  <th><?php _e('Type', 'arm-repair-estimates'); ?></th>
                  <th><?php _e('Description', 'arm-repair-estimates'); ?></th>
                  <th><?php _e('Qty', 'arm-repair-estimates'); ?></th>
                  <th><?php _e('Unit Price', 'arm-repair-estimates'); ?></th>
                  <th><?php _e('Taxable', 'arm-repair-estimates'); ?></th>
                  <th><?php _e('Line Total', 'arm-repair-estimates'); ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $index => $it): echo self::render_item_row_form($index, $it); endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="7"><button type="button" class="button" id="arm-add-invoice-item"><?php _e('Add Item', 'arm-repair-estimates'); ?></button></td>
                </tr>
              </tfoot>
            </table>

            <h2><?php _e('Totals', 'arm-repair-estimates'); ?></h2>
            <table class="form-table">
              <tr>
                <th scope="row"><?php _e('Subtotal', 'arm-repair-estimates'); ?></th>
                <td><span id="arm-invoice-subtotal" data-default="<?php echo esc_attr(number_format($subtotal, 2, '.', '')); ?>"><?php echo esc_html(number_format($subtotal, 2)); ?></span></td>
              </tr>
              <tr>
                <th scope="row"><?php _e('Tax', 'arm-repair-estimates'); ?></th>
                <td><span id="arm-invoice-tax" data-default="<?php echo esc_attr(number_format($tax_amount, 2, '.', '')); ?>"><?php echo esc_html(number_format($tax_amount, 2)); ?></span></td>
              </tr>
              <tr>
                <th scope="row"><?php _e('Total', 'arm-repair-estimates'); ?></th>
                <td><strong id="arm-invoice-total" data-default="<?php echo esc_attr(number_format($total, 2, '.', '')); ?>"><?php echo esc_html(number_format($total, 2)); ?></strong></td>
              </tr>
            </table>

            <h2><?php _e('Notes', 'arm-repair-estimates'); ?></h2>
            <textarea name="notes" rows="6" class="large-text"><?php echo esc_textarea($invoice->notes ?? ''); ?></textarea>

            <?php submit_button($id ? __('Update Invoice', 'arm-repair-estimates') : __('Create Invoice', 'arm-repair-estimates')); ?>
          </form>
        </div>
        <script>
        (function($){
            var rowIndex = $('#arm-invoice-items tbody tr').length;
            function recalc(){
                var subtotal = 0, taxable = 0, taxRate = parseFloat($('#arm-invoice-tax-rate').val() || '0');
                $('#arm-invoice-items tbody tr').each(function(){
                    var $row = $(this);
                    var qty = parseFloat($row.find('.arm-item-qty').val() || '0');
                    var price = parseFloat($row.find('.arm-item-price').val() || '0');
                    var type = $row.find('.arm-item-type').val();
                    var total = qty * price;
                    if (type === 'DISCOUNT') total = total * -1;
                    subtotal += total;
                    if ($row.find('.arm-item-taxable').is(':checked')) {
                        taxable += total;
                    }
                    $row.find('.arm-item-total').text(total.toFixed(2));
                });
                var tax = taxable * (taxRate/100);
                $('#arm-invoice-subtotal').text(subtotal.toFixed(2));
                $('#arm-invoice-tax').text(tax.toFixed(2));
                $('#arm-invoice-total').text((subtotal + tax).toFixed(2));
            }

            function bindRow($row){
                $row.on('input change', '.arm-item-qty, .arm-item-price, .arm-item-taxable, .arm-item-type', recalc);
                $row.find('.arm-item-remove').on('click', function(e){
                    e.preventDefault();
                    if ($('#arm-invoice-items tbody tr').length === 1) {
                        $row.find('input, select').val('');
                        $row.find('.arm-item-taxable').prop('checked', true);
                        recalc();
                    } else {
                        $row.remove();
                        recalc();
                    }
                });
            }

            $('#arm-add-invoice-item').on('click', function(){
                var tmpl = <?php echo wp_json_encode(self::render_item_row_form('__INDEX__', null)); ?>;
                var html = tmpl.replace(/__INDEX__/g, rowIndex++);
                var $row = $(html).appendTo('#arm-invoice-items tbody');
                bindRow($row);
            });

            $('#arm-invoice-items tbody tr').each(function(){ bindRow($(this)); });
            $('#arm-invoice-tax-rate').on('input', recalc);

            var vehicles = <?php echo wp_json_encode(array_values($vehicles)); ?>;
            var $vehicleSelect = $('#arm-invoice-vehicle');
            var $vehicleId = $('#arm-invoice-vehicle-id');
            function populateVehicles(list){
                var current = $vehicleId.val();
                $vehicleSelect.find('option').not(':first').remove();
                list.forEach(function(v){
                    var opt = $('<option></option>').val(v.id).text(v.label).attr('data-meta', JSON.stringify(v)).data('meta', v);
                    if (current && parseInt(current,10) === parseInt(v.id,10)) opt.prop('selected', true);
                    $vehicleSelect.append(opt);
                });
            }
            populateVehicles(vehicles);

            $vehicleSelect.on('change', function(){
                var val = $(this).val();
                if (!val) { $vehicleId.val('0'); return; }
                var data = $(this).find(':selected').data('meta');
                if (typeof data === 'string') {
                    try { data = JSON.parse(data); } catch(e) { data = null; }
                }
                if (data) {
                    $vehicleId.val(data.id || 0);
                    if (data.vin) $('#arm-invoice-vin').val(data.vin);
                    if (data.license_plate) $('#arm-invoice-plate').val(data.license_plate);
                    if (typeof data.current_mileage !== 'undefined' && data.current_mileage !== null) $('#arm-invoice-current-mileage').val(data.current_mileage);
                    if (typeof data.last_service_mileage !== 'undefined' && data.last_service_mileage !== null) $('#arm-invoice-last-mileage').val(data.last_service_mileage);
                }
            });

            $('#arm-invoice-customer').on('change', function(){
                var customerId = $(this).val();
                $vehicleId.val('0');
                populateVehicles([]);
                if (!customerId) return;
                $.post(ajaxurl, {
                    action: 'arm_re_invoice_customer_vehicles',
                    nonce: '<?php echo esc_js($ajax_nonce); ?>',
                    customer_id: customerId
                }).done(function(resp){
                    if (resp && resp.success && Array.isArray(resp.data)) {
                        vehicles = resp.data;
                        populateVehicles(vehicles);
                    }
                });
            });

            recalc();
        })(jQuery);
        </script>
        <?php
    }

    private static function render_item_row_form($index, $item): string {
        $item = is_object($item) ? $item : (object) [];
        $types = self::item_types();
        $type = $item->item_type ?? 'LABOR';
        if (!isset($types[$type])) {
            $type = 'LABOR';
        }
        $desc = $item->description ?? '';
        $qty  = isset($item->qty) ? (float) $item->qty : 1;
        $price= isset($item->unit_price) ? (float) $item->unit_price : 0;
        $tax  = isset($item->taxable) ? (int) $item->taxable : 1;
        $total= isset($item->line_total) ? (float) $item->line_total : ($qty * $price);
        if ($type === 'DISCOUNT') {
            $total = -1 * abs($total);
        }

        $idx = is_numeric($index) ? (int) $index : $index;

        $options = '';
        foreach ($types as $key => $label) {
            $options .= '<option value="' . esc_attr($key) . '"' . selected($type, $key, false) . '>' . esc_html($label) . '</option>';
        }

        $row  = '<tr>';
        $row .= '<td><select name="items[' . esc_attr($idx) . '][type]" class="arm-item-type">' . $options . '</select></td>';
        $row .= '<td><input type="text" name="items[' . esc_attr($idx) . '][desc]" value="' . esc_attr($desc) . '" class="widefat"></td>';
        $row .= '<td><input type="number" step="0.01" min="0" name="items[' . esc_attr($idx) . '][qty]" value="' . esc_attr($qty) . '" class="arm-item-qty small-text"></td>';
        $row .= '<td><input type="number" step="0.01" name="items[' . esc_attr($idx) . '][price]" value="' . esc_attr(number_format($price, 2, '.', '')) . '" class="arm-item-price"></td>';
        $row .= '<td style="text-align:center;"><input type="checkbox" name="items[' . esc_attr($idx) . '][taxable]" value="1" class="arm-item-taxable"' . checked($tax, 1, false) . '></td>';
        $row .= '<td class="arm-item-total" style="text-align:right;">' . esc_html(number_format($total, 2)) . '</td>';
        $row .= '<td><button type="button" class="button arm-item-remove">&times;</button></td>';
        $row .= '</tr>';

        return $row;
    }

    public static function handle_save_invoice() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_save_invoice', 'arm_re_invoice_nonce');

        global $wpdb;
        $invT = $wpdb->prefix . 'arm_invoices';
        $itT  = $wpdb->prefix . 'arm_invoice_items';

        $id          = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
        $customer_id = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
        if ($customer_id <= 0) {
            wp_die(__('Customer is required.', 'arm-repair-estimates'));
        }

        $status = isset($_POST['status']) && in_array($_POST['status'], ['UNPAID','PAID','VOID'], true)
            ? $_POST['status'] : 'UNPAID';
        $tax_rate = isset($_POST['tax_rate']) ? (float) $_POST['tax_rate'] : 0.0;
        $invoice_no = isset($_POST['invoice_no']) ? sanitize_text_field($_POST['invoice_no']) : '';
        $estimate_id = isset($_POST['estimate_id']) ? (int) $_POST['estimate_id'] : 0;

        $vehicle_estimate_id = isset($_POST['vehicle_estimate_id']) ? (int) $_POST['vehicle_estimate_id'] : 0;
        $vin          = sanitize_text_field($_POST['vin'] ?? '');
        $license_plate= sanitize_text_field($_POST['license_plate'] ?? '');
        $current_mileage = $_POST['current_mileage'] === '' ? null : max(0, (int) $_POST['current_mileage']);
        $last_service_mileage = $_POST['last_service_mileage'] === '' ? null : max(0, (int) $_POST['last_service_mileage']);

        $items_post = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
        $allowed_types = array_keys(self::item_types());
        $prepared_items = [];
        $subtotal = 0.0;
        $taxable_total = 0.0;
        $sort = 0;

        foreach ($items_post as $row) {
            $desc = isset($row['desc']) ? sanitize_text_field($row['desc']) : '';
            if ($desc === '') continue;
            $type = isset($row['type']) && in_array($row['type'], $allowed_types, true) ? $row['type'] : 'LABOR';
            $qty  = isset($row['qty']) ? (float) $row['qty'] : 1.0;
            $price= isset($row['price']) ? (float) $row['price'] : 0.0;
            $taxable = !empty($row['taxable']) ? 1 : 0;
            $line_total = round($qty * $price, 2);
            if ($type === 'DISCOUNT') {
                $line_total = -1 * abs($line_total);
            }
            $subtotal += $line_total;
            if ($taxable) {
                $taxable_total += $line_total;
            }
            $prepared_items[] = [
                'item_type'  => $type,
                'description'=> $desc,
                'qty'        => $qty,
                'unit_price' => $price,
                'taxable'    => $taxable,
                'line_total' => $line_total,
                'sort_order' => $sort++,
            ];
        }

        $tax_amount = round($taxable_total * ($tax_rate / 100), 2);
        $total = round($subtotal + $tax_amount, 2);

        $data = [
            'customer_id' => $customer_id,
            'status'      => $status,
            'tax_rate'    => round($tax_rate, 2),
            'subtotal'    => round($subtotal, 2),
            'tax_amount'  => $tax_amount,
            'total'       => $total,
            'notes'       => wp_kses_post($_POST['notes'] ?? ''),
            'vehicle_estimate_id' => $vehicle_estimate_id ?: null,
            'vin'         => $vin ?: null,
            'license_plate' => $license_plate ?: null,
            'current_mileage' => $current_mileage,
            'last_service_mileage' => $last_service_mileage,
            'updated_at'  => current_time('mysql'),
        ];

        if ($estimate_id) {
            $data['estimate_id'] = $estimate_id;
        }

        if ($id) {
            if ($invoice_no === '') {
                $invoice_no = $wpdb->get_var($wpdb->prepare("SELECT invoice_no FROM $invT WHERE id=%d", $id));
            }
            $data['invoice_no'] = $invoice_no;
            $wpdb->update($invT, $data, ['id' => $id]);
        } else {
            if ($invoice_no === '') {
                $invoice_no = self::next_invoice_no();
            }
            $data['invoice_no'] = $invoice_no;
            $data['token'] = self::token();
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($invT, $data);
            $id = (int) $wpdb->insert_id;
        }

        if ($id) {
            $wpdb->query($wpdb->prepare("DELETE FROM $itT WHERE invoice_id=%d", $id));
            foreach ($prepared_items as $item) {
                $item['invoice_id'] = $id;
                $wpdb->insert($itT, $item);
            }
        }

        wp_redirect(admin_url('admin.php?page=arm-repair-invoices&action=edit&id=' . (int) $id . '&updated=1'));
        exit;
    }

    public static function ajax_customer_vehicles() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'arm-repair-estimates')]);
        }
        check_ajax_referer('arm_re_invoice_vehicle_lookup', 'nonce');

        $customer_id = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
        if ($customer_id <= 0) {
            wp_send_json_success([]);
        }

        $vehicles = self::fetch_customer_vehicles($customer_id);
        wp_send_json_success(array_values($vehicles));
    }

    private static function fetch_customer_vehicles(int $customer_id): array {
        global $wpdb;
        $eT = $wpdb->prefix . 'arm_estimates';

        // Determine available columns once per request
        static $columns_cache = null;
        if ($columns_cache === null) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM $eT", 0);
            $columns_cache = $cols ? array_flip($cols) : [];
        }
        $cols = $columns_cache;
        if (!$cols) return [];

        $select = ['id', 'estimate_no'];
        foreach (['vehicle_year','vehicle_make','vehicle_model','vehicle_trim','vehicle_engine','vehicle_other','vin','plate','license_plate','vehicle_vin','vehicle_plate','current_mileage','last_service_mileage','mileage_current','mileage_last_service'] as $col) {
            if (isset($cols[$col])) $select[] = $col;
        }
        if (!isset($cols['customer_id'])) {
            return [];
        }

        $orderBy = isset($cols['created_at']) ? '`created_at`' : '`id`';
        $sql = sprintf(
            'SELECT %s FROM %s WHERE customer_id=%%d ORDER BY %s DESC LIMIT 100',
            implode(',', array_map(function ($c) { return '`' . esc_sql($c) . '`'; }, $select)),
            '`' . esc_sql($eT) . '`',
            $orderBy
        );
        $rows = $wpdb->get_results($wpdb->prepare($sql, $customer_id));
        if (!$rows) return [];

        $vehicles = [];
        foreach ($rows as $row) {
            $vehicles[] = self::normalize_vehicle_row($row);
        }
        return $vehicles;
    }

    private static function normalize_vehicle_row($row): array {
        $label_parts = [];
        foreach (['vehicle_year','vehicle_make','vehicle_model','vehicle_trim','vehicle_engine'] as $col) {
            if (isset($row->$col) && $row->$col !== '') {
                $label_parts[] = $row->$col;
            }
        }
        if (empty($label_parts) && isset($row->vehicle_other) && $row->vehicle_other) {
            $label_parts[] = $row->vehicle_other;
        }
        if (empty($label_parts) && isset($row->estimate_no)) {
            $label_parts[] = sprintf(__('Estimate #%s', 'arm-repair-estimates'), $row->estimate_no);
        }
        $label = trim(implode(' ', $label_parts));

        $vin = null;
        foreach (['vin','vehicle_vin'] as $key) {
            if (isset($row->$key) && $row->$key !== '') { $vin = $row->$key; break; }
        }
        $plate = null;
        foreach (['license_plate','plate','vehicle_plate'] as $key) {
            if (isset($row->$key) && $row->$key !== '') { $plate = $row->$key; break; }
        }
        $current = null;
        foreach (['current_mileage','mileage_current'] as $key) {
            if (isset($row->$key) && $row->$key !== '') { $current = (int) $row->$key; break; }
        }
        $last = null;
        foreach (['last_service_mileage','mileage_last_service'] as $key) {
            if (isset($row->$key) && $row->$key !== '') { $last = (int) $row->$key; break; }
        }

        return [
            'id' => (int) $row->id,
            'label' => $label,
            'vin' => $vin,
            'license_plate' => $plate,
            'current_mileage' => $current,
            'last_service_mileage' => $last,
        ];
    }

    private static function apply_estimate_vehicle_meta(int $invoice_id, $estimate): void {
        $meta = self::extract_vehicle_meta_from_estimate($estimate);
        if (!$meta) return;

        global $wpdb;
        $invT = $wpdb->prefix . 'arm_invoices';
        $wpdb->update($invT, $meta, ['id' => $invoice_id]);
    }

    private static function extract_vehicle_meta_from_estimate($estimate): array {
        if (!$estimate) return [];
        $meta = [];
        if (isset($estimate->id)) {
            $meta['vehicle_estimate_id'] = (int) $estimate->id;
        }

        foreach (['vin','vehicle_vin'] as $field) {
            if (isset($estimate->$field) && $estimate->$field !== '') {
                $meta['vin'] = sanitize_text_field($estimate->$field);
                break;
            }
        }
        foreach (['license_plate','plate','vehicle_plate'] as $field) {
            if (isset($estimate->$field) && $estimate->$field !== '') {
                $meta['license_plate'] = sanitize_text_field($estimate->$field);
                break;
            }
        }
        foreach (['current_mileage','mileage_current'] as $field) {
            if (isset($estimate->$field) && $estimate->$field !== '') {
                $meta['current_mileage'] = (int) $estimate->$field;
                break;
            }
        }
        foreach (['last_service_mileage','mileage_last_service'] as $field) {
            if (isset($estimate->$field) && $estimate->$field !== '') {
                $meta['last_service_mileage'] = (int) $estimate->$field;
                break;
            }
        }

        return $meta;
    }

    /** --------------------------------------------------------------
     * Public invoice view by token
     * --------------------------------------------------------------*/
    public static function render_public_if_requested() {
        $token = get_query_var('arm_invoice');
        if (!$token) return;

        global $wpdb;
        $invT = $wpdb->prefix . 'arm_invoices';
        $itT  = $wpdb->prefix . 'arm_invoice_items';
        $cT   = $wpdb->prefix . 'arm_customers';

        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $invT WHERE token=%s", $token));
        if (!$inv) { status_header(404); wp_die('Invoice not found'); }

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $itT WHERE invoice_id=%d ORDER BY sort_order ASC, id ASC", (int)$inv->id));
        $cust  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cT WHERE id=%d", (int)$inv->customer_id));

        // Reuse your existing template if present
        if (defined('ARM_RE_PATH') && file_exists(ARM_RE_PATH . 'templates/invoice-view.php')) {
            include ARM_RE_PATH . 'templates/invoice-view.php';
        } else {
            // Minimal fallback
            echo '<div class="arm-invoice">';
            echo '<h2>' . esc_html(sprintf(__('Invoice %s', 'arm-repair-estimates'), $inv->invoice_no)) . '</h2>';
            if ($cust) {
                echo '<p><strong>' . esc_html($cust->first_name . ' ' . $cust->last_name) . '</strong><br>' . esc_html($cust->email) . '</p>';
            }
            $has_vehicle = ($inv->vin !== null && $inv->vin !== '') || ($inv->license_plate !== null && $inv->license_plate !== '') || ($inv->current_mileage !== null && $inv->current_mileage !== '') || ($inv->last_service_mileage !== null && $inv->last_service_mileage !== '');
            if ($has_vehicle) {
                echo '<p><strong>' . esc_html__('Vehicle Details', 'arm-repair-estimates') . '</strong><br>';
                if ($inv->vin !== null && $inv->vin !== '') {
                    echo esc_html__('VIN:', 'arm-repair-estimates') . ' ' . esc_html($inv->vin) . '<br>';
                }
                if ($inv->license_plate !== null && $inv->license_plate !== '') {
                    echo esc_html__('Plate:', 'arm-repair-estimates') . ' ' . esc_html($inv->license_plate) . '<br>';
                }
                if ($inv->current_mileage !== null && $inv->current_mileage !== '') {
                    echo esc_html__('Current Mileage:', 'arm-repair-estimates') . ' ' . esc_html(number_format_i18n((float)$inv->current_mileage)) . '<br>';
                }
                if ($inv->last_service_mileage !== null && $inv->last_service_mileage !== '') {
                    echo esc_html__('Last Service Mileage:', 'arm-repair-estimates') . ' ' . esc_html(number_format_i18n((float)$inv->last_service_mileage)) . '<br>';
                }
                echo '</p>';
            }
            echo '<table class="arm-table" style="width:100%;border-collapse:collapse;" border="1" cellpadding="6">';
            echo '<thead><tr><th>' . esc_html__('Type','arm-repair-estimates') . '</th><th>' . esc_html__('Description','arm-repair-estimates') . '</th><th>' . esc_html__('Qty','arm-repair-estimates') . '</th><th>' . esc_html__('Unit','arm-repair-estimates') . '</th><th>' . esc_html__('Line Total','arm-repair-estimates') . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($items as $it) {
                echo '<tr>';
                echo '<td>' . esc_html($it->item_type) . '</td>';
                echo '<td>' . esc_html($it->description) . '</td>';
                echo '<td>' . esc_html(number_format((float)$it->qty,2)) . '</td>';
                echo '<td>' . esc_html(number_format((float)$it->unit_price,2)) . '</td>';
                echo '<td>' . esc_html(number_format((float)$it->line_total,2)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '<p style="text-align:right;margin-top:12px;">';
            echo esc_html__('Subtotal:','arm-repair-estimates') . ' ' . esc_html(number_format((float)$inv->subtotal,2)) . '<br>';
            echo esc_html__('Tax:','arm-repair-estimates') . ' ' . esc_html(number_format((float)$inv->tax_amount,2)) . '<br>';
            echo '<strong>' . esc_html__('Total:','arm-repair-estimates') . ' ' . esc_html(number_format((float)$inv->total,2)) . '</strong>';
            echo '</p>';
            echo '</div>';
        }
        exit;
    }
}
