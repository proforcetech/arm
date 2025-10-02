<?php
namespace ARM\Invoices;

if (!defined('ABSPATH')) exit;

class Controller {

    /** --------------------------------------------------------------
     * Boot hooks (submenu, actions, public view)
     * --------------------------------------------------------------*/
    public static function boot() {
        self::maybe_upgrade_schema();

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

        // Convert from Estimate action
        add_action('admin_post_arm_re_convert_estimate_to_invoice', [__CLASS__, 'convert_from_estimate']);

        // Public viewing via token
        add_filter('query_vars', function ($vars) { $vars[] = 'arm_invoice'; return $vars; });
        add_action('template_redirect', [__CLASS__, 'render_public_if_requested']);
    }

    private static function maybe_upgrade_schema(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_invoices';
        $has_vehicle_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'vehicle_id'",
                $table
            )
        );
        if ($has_vehicle_id) {
            return;
        }
        $wpdb->query(
            "ALTER TABLE `$table`
                ADD COLUMN vehicle_id BIGINT UNSIGNED NULL AFTER customer_id,
                ADD COLUMN vehicle_year SMALLINT UNSIGNED NULL AFTER vehicle_id,
                ADD COLUMN vehicle_make VARCHAR(80) NULL AFTER vehicle_year,
                ADD COLUMN vehicle_model VARCHAR(120) NULL AFTER vehicle_make,
                ADD COLUMN vehicle_engine VARCHAR(120) NULL AFTER vehicle_model,
                ADD COLUMN vehicle_trim VARCHAR(120) NULL AFTER vehicle_engine,
                ADD COLUMN vehicle_vin VARCHAR(64) NULL AFTER vehicle_trim,
                ADD COLUMN vehicle_plate VARCHAR(32) NULL AFTER vehicle_vin,
                ADD COLUMN vehicle_mileage VARCHAR(32) NULL AFTER vehicle_plate,
                ADD KEY idx_arm_invoices_vehicle_id (vehicle_id)"
        );
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
            vehicle_id BIGINT UNSIGNED NULL,
            vehicle_year SMALLINT UNSIGNED NULL,
            vehicle_make VARCHAR(80) NULL,
            vehicle_model VARCHAR(120) NULL,
            vehicle_engine VARCHAR(120) NULL,
            vehicle_trim VARCHAR(120) NULL,
            vehicle_vin VARCHAR(64) NULL,
            vehicle_plate VARCHAR(32) NULL,
            vehicle_mileage VARCHAR(32) NULL,
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
            INDEX(customer_id), INDEX(estimate_id),
            INDEX(vehicle_id),
            PRIMARY KEY(id)
        ) $charset;");

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
            'estimate_id'    => $e->id,
            'customer_id'    => $e->customer_id,
            'vehicle_id'     => $e->vehicle_id ?: null,
            'vehicle_year'   => $e->vehicle_year ?: null,
            'vehicle_make'   => $e->vehicle_make ?: null,
            'vehicle_model'  => $e->vehicle_model ?: null,
            'vehicle_engine' => $e->vehicle_engine ?: null,
            'vehicle_trim'   => $e->vehicle_trim ?: null,
            'vehicle_vin'    => $e->vehicle_vin ?: null,
            'vehicle_plate'  => $e->vehicle_plate ?: null,
            'vehicle_mileage'=> $e->vehicle_mileage ?: null,
            'invoice_no'     => self::next_invoice_no(),
            'status'         => 'UNPAID',
            'subtotal'       => $e->subtotal,
            'tax_rate'       => $e->tax_rate,
            'tax_amount'     => $e->tax_amount,
            'total'          => $e->total,
            'notes'          => $e->notes,
            'token'          => self::token(),
            'created_at'     => current_time('mysql'),
        ]);
        $inv_id = (int)$wpdb->insert_id;

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
        global $wpdb;
        $invT = $wpdb->prefix . 'arm_invoices';
        $cT   = $wpdb->prefix . 'arm_customers';

        $rows = $wpdb->get_results("
            SELECT i.*, CONCAT(c.first_name,' ',c.last_name) AS customer, c.email
            FROM $invT i JOIN $cT c ON c.id=i.customer_id
            ORDER BY i.created_at DESC
            LIMIT 300
        ");
        ?>
        <div class="wrap">
          <h1><?php _e('Invoices', 'arm-repair-estimates'); ?></h1>
          <table class="widefat striped">
            <thead>
              <tr>
                <th>#</th>
                <th><?php _e('Customer','arm-repair-estimates'); ?></th>
                <th><?php _e('Email','arm-repair-estimates'); ?></th>
                <th><?php _e('Total','arm-repair-estimates'); ?></th>
                <th><?php _e('Status','arm-repair-estimates'); ?></th>
                <th><?php _e('Created','arm-repair-estimates'); ?></th>
                <th><?php _e('Actions','arm-repair-estimates'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rows): foreach ($rows as $r):
                $view = add_query_arg(['arm_invoice' => $r->token], home_url('/'));
				$short_url = \ARM\Links\Shortlinks::get_or_create_for_invoice((int)$r->id, (string)$r->token);
              ?>
              <tr>
                <td><?php echo esc_html($r->invoice_no); ?></td>
                <td><?php echo esc_html($r->customer); ?></td>
                <td><?php echo esc_html($r->email); ?></td>
                <td><?php echo esc_html(number_format((float)$r->total, 2)); ?></td>
                <td><?php echo esc_html($r->status); ?></td>
                <td><?php echo esc_html($r->created_at); ?></td>
                <td>
                  <a href="<?php echo esc_url($view); ?>" target="_blank"><?php _e('View','arm-repair-estimates'); ?></a>
                </td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="7"><?php _e('No invoices yet.','arm-repair-estimates'); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
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
