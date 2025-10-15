<?php
namespace ARM\PDF;

if (!defined('ABSPATH')) exit;

/**
 * PDF generation for Estimates and Invoices.
 * Tries Dompdf -> mPDF -> TCPDF if available. Falls back to printable HTML.
 */
class Controller {

    /** keep schema extensible (no tables needed today) */
    public static function install_tables() {  }

    public static function boot() {
        
        add_action('admin_post_arm_re_pdf_estimate', [__CLASS__, 'admin_pdf_estimate']);
        add_action('admin_post_arm_re_pdf_invoice',  [__CLASS__, 'admin_pdf_invoice']);
        add_action('admin_post_arm_re_pdf_inspection', [__CLASS__, 'admin_pdf_inspection']);

        
        add_filter('query_vars', function($vars){
            $vars[] = 'arm_estimate_pdf';
            $vars[] = 'arm_invoice_pdf';
            $vars[] = 'arm_inspection_pdf';
            return $vars;
        });
        add_action('template_redirect', [__CLASS__, 'public_pdf_if_requested']);
    }

    /** ------------------------- Entry points ------------------------ */

    public static function admin_pdf_estimate() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_pdf_estimate');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) wp_die('Missing estimate id');

        $html = self::render_estimate_html_by_id($id);
        self::stream_pdf_or_html($html, "estimate-$id.pdf");
    }

    public static function admin_pdf_invoice() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_pdf_invoice');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) wp_die('Missing invoice id');

        $html = self::render_invoice_html_by_id($id);
        self::stream_pdf_or_html($html, "invoice-$id.pdf");
    }

    public static function admin_pdf_inspection() {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_re_pdf_inspection');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) wp_die('Missing inspection id');

        $html = self::render_inspection_html_by_id($id);
        self::stream_pdf_or_html($html, "inspection-$id.pdf");
    }

    public static function public_pdf_if_requested() {
        $est_token = get_query_var('arm_estimate_pdf');
        $inv_token = get_query_var('arm_invoice_pdf');
        $insp_token = get_query_var('arm_inspection_pdf');
        if (!$est_token && !$inv_token && !$insp_token) return;

        if ($est_token) {
            $html = self::render_estimate_html_by_token($est_token);
            self::stream_pdf_or_html($html, "estimate.pdf");
        } elseif ($inv_token) {
            $html = self::render_invoice_html_by_token($inv_token);
            self::stream_pdf_or_html($html, "invoice.pdf");
        } else {
            $html = self::render_inspection_html_by_token($insp_token);
            self::stream_pdf_or_html($html, "inspection.pdf");
        }
        exit;
    }

    /** ------------------------- HTML builders ----------------------- */

    public static function shop_header_html() {
        $logo = esc_url(get_option('arm_re_logo_url',''));
        $name = esc_html(get_option('arm_re_shop_name',''));
        $addr = wp_kses_post(get_option('arm_re_shop_address',''));
        $phone= esc_html(get_option('arm_re_shop_phone',''));
        $email= esc_html(get_option('arm_re_shop_email',''));

        ob_start(); ?>
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
          <tr>
            <td style="width:60%;">
              <?php if ($name): ?><h2 style="margin:0 0 6px;"><?php echo $name; ?></h2><?php endif; ?>
              <?php if ($addr): ?><div style="font-size:12px;line-height:1.4;"><?php echo $addr; ?></div><?php endif; ?>
              <?php if ($phone || $email): ?>
                <div style="font-size:12px;margin-top:6px;">
                <?php if ($phone) echo esc_html__('Phone: ','arm-repair-estimates') . esc_html($phone) . '  '; ?>
                <?php if ($email) echo esc_html__('Email: ','arm-repair-estimates') . esc_html($email); ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="width:40%;text-align:right;">
              <?php if ($logo): ?><img src="<?php echo $logo; ?>" style="max-width:220px;height:auto;"><?php endif; ?>
            </td>
          </tr>
        </table>
        <?php
        return ob_get_clean();
    }

    private static function render_estimate_html_by_id($id) {
        global $wpdb;
        $eT = $wpdb->prefix.'arm_estimates';
        $iT = $wpdb->prefix.'arm_estimate_items';
        $jT = $wpdb->prefix.'arm_estimate_jobs';
        $cT = $wpdb->prefix.'arm_customers';
        $est = $wpdb->get_row($wpdb->prepare("SELECT * FROM $eT WHERE id=%d", $id));
        if (!$est) wp_die('Estimate not found');

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $iT WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC", $est->id));
        $jobs  = $wpdb->get_results($wpdb->prepare("SELECT * FROM $jT WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC", $est->id));
        $cust  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cT WHERE id=%d", $est->customer_id));
        return self::estimate_html($est, $items, $cust, $jobs);
    }

    private static function render_estimate_html_by_token($token) {
        global $wpdb;
        $eT = $wpdb->prefix.'arm_estimates';
        $iT = $wpdb->prefix.'arm_estimate_items';
        $jT = $wpdb->prefix.'arm_estimate_jobs';
        $cT = $wpdb->prefix.'arm_customers';
        $est = $wpdb->get_row($wpdb->prepare("SELECT * FROM $eT WHERE token=%s", $token));
        if (!$est) wp_die('Estimate not found');

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $iT WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC", $est->id));
        $jobs  = $wpdb->get_results($wpdb->prepare("SELECT * FROM $jT WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC", $est->id));
        $cust  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cT WHERE id=%d", $est->customer_id));
        return self::estimate_html($est, $items, $cust, $jobs);
    }

    private static function render_invoice_html_by_id($id) {
        global $wpdb;
        $iT  = $wpdb->prefix.'arm_invoices';
        $itT = $wpdb->prefix.'arm_invoice_items';
        $cT  = $wpdb->prefix.'arm_customers';
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $iT WHERE id=%d", $id));
        if (!$inv) wp_die('Invoice not found');

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $itT WHERE invoice_id=%d ORDER BY sort_order ASC, id ASC", $inv->id));
        $cust  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cT WHERE id=%d", $inv->customer_id));
        return self::invoice_html($inv, $items, $cust);
    }

    private static function render_invoice_html_by_token($token) {
        global $wpdb;
        $iT  = $wpdb->prefix.'arm_invoices';
        $itT = $wpdb->prefix.'arm_invoice_items';
        $cT  = $wpdb->prefix.'arm_customers';
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $iT WHERE token=%s", $token));
        if (!$inv) wp_die('Invoice not found');

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $itT WHERE invoice_id=%d ORDER BY sort_order ASC, id ASC", $inv->id));
        $cust  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cT WHERE id=%d", $inv->customer_id));
        return self::invoice_html($inv, $items, $cust);
    }

    private static function render_inspection_html_by_id($id) {
        $inspection = \ARM\Inspections\Reports::get_with_details((int) $id);
        if (!$inspection) {
            wp_die('Inspection not found');
        }
        return \ARM\Inspections\Reports::render_html($inspection);
    }

    private static function render_inspection_html_by_token($token) {
        $inspection = \ARM\Inspections\Reports::get_by_token((string) $token);
        if (!$inspection) {
            wp_die('Inspection not found');
        }
        return \ARM\Inspections\Reports::render_html($inspection);
    }

    /** HTML templates */

    private static function estimate_html($est, $items, $cust, $jobs = []) {
        $header = self::shop_header_html();
        $terms  = wp_kses_post(get_option('arm_re_terms_html',''));
        $technicians = \ARM\Estimates\Controller::get_technician_directory();
        $assigned_label = '';
        if (!empty($est->technician_id) && isset($technicians[(int) $est->technician_id])) {
            $assigned_label = self::formatTechnicianLabel($technicians[(int) $est->technician_id]);
        }
        $job_assignments = [];
        if ($jobs) {
            foreach ($jobs as $job) {
                $job_title = trim((string) ($job->title ?? ''));
                if ($job_title === '') {
                    $job_title = __('Untitled Job', 'arm-repair-estimates');
                }
                $label = __('Unassigned', 'arm-repair-estimates');
                if (!empty($job->technician_id) && isset($technicians[(int) $job->technician_id])) {
                    $formatted = self::formatTechnicianLabel($technicians[(int) $job->technician_id]);
                    if ($formatted !== '') {
                        $label = $formatted;
                    }
                }
                $job_assignments[] = sprintf('%s — %s', $job_title, $label);
            }
        }
        ob_start(); ?>
        <html><head><meta charset="utf-8"><title><?php echo esc_html($est->estimate_no); ?></title>
        <style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#222;margin:18px;}
            h1,h2,h3{margin:0 0 8px;}
            .muted{color:#666}
            table.tbl{width:100%;border-collapse:collapse;}
            table.tbl th, table.tbl td{border:1px solid #ddd;padding:6px;}
            .right{text-align:right}
        </style>
        </head><body>
        <?php echo $header; ?>
        <h2><?php echo esc_html(sprintf(__('Estimate %s','arm-repair-estimates'), $est->estimate_no)); ?></h2>
        <p class="muted"><?php echo esc_html(sprintf(__('Status: %s','arm-repair-estimates'), $est->status)); ?>
        <?php if(!empty($est->expires_at)) echo ' • ' . esc_html(sprintf(__('Expires: %s','arm-repair-estimates'), $est->expires_at)); ?></p>
        <?php if ($assigned_label !== ''): ?>
          <p class="muted"><?php _e('Assigned Technician','arm-repair-estimates'); ?>: <?php echo esc_html($assigned_label); ?></p>
        <?php endif; ?>

        <?php if ($cust): ?>
          <p><strong><?php echo esc_html($cust->first_name.' '.$cust->last_name); ?></strong><br>
             <?php echo esc_html($cust->email); ?>
             <?php if(!empty($cust->phone)) echo '<br>'.esc_html($cust->phone); ?>
          </p>
        <?php endif; ?>

        <table class="tbl">
          <thead><tr>
            <th><?php _e('Type','arm-repair-estimates'); ?></th>
            <th><?php _e('Description','arm-repair-estimates'); ?></th>
            <th class="right"><?php _e('Qty','arm-repair-estimates'); ?></th>
            <th class="right"><?php _e('Unit','arm-repair-estimates'); ?></th>
            <th class="right"><?php _e('Line Total','arm-repair-estimates'); ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?php echo esc_html($it->item_type); ?></td>
              <td><?php echo esc_html($it->description); ?></td>
              <td class="right"><?php echo esc_html(number_format((float)$it->qty,2)); ?></td>
              <td class="right"><?php echo esc_html(number_format((float)$it->unit_price,2)); ?></td>
              <td class="right"><?php echo esc_html(number_format((float)$it->line_total,2)); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($job_assignments): ?>
        <h3 style="margin-top:12px;"><?php _e('Job Assignments','arm-repair-estimates'); ?></h3>
        <ul>
          <?php foreach ($job_assignments as $line): ?>
            <li><?php echo esc_html($line); ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <p class="right" style="margin-top:10px;">
          <?php _e('Subtotal','arm-repair-estimates'); ?>: <?php echo esc_html(number_format((float)$est->subtotal,2)); ?><br>
          <?php _e('Tax','arm-repair-estimates'); ?>: <?php echo esc_html(number_format((float)$est->tax_amount,2)); ?><br>
          <strong><?php _e('Total','arm-repair-estimates'); ?>: <?php echo esc_html(number_format((float)$est->total,2)); ?></strong>
        </p>

        <?php if (!empty($est->notes)): ?>
          <h3><?php _e('Notes','arm-repair-estimates'); ?></h3>
          <div><?php echo wpautop(wp_kses_post($est->notes)); ?></div>
        <?php endif; ?>

        <?php if ($terms): ?>
          <h3 style="margin-top:18px;"><?php _e('Terms & Conditions','arm-repair-estimates'); ?></h3>
          <div><?php echo $terms; ?></div>
        <?php endif; ?>

        </body></html>
        <?php
        return ob_get_clean();
    }

    private static function formatTechnicianLabel(array $tech): string
    {
        $name = trim((string) ($tech['name'] ?? ''));
        $email = trim((string) ($tech['email'] ?? ''));
        if ($name === '' && $email === '') {
            return '';
        }
        if ($name !== '' && $email !== '') {
            return sprintf('%s (%s)', $name, $email);
        }
        return $name !== '' ? $name : $email;
    }

    private static function invoice_html($inv, $items, $cust) {
        $header = self::shop_header_html();
        ob_start(); ?>
        <html><head><meta charset="utf-8"><title><?php echo esc_html($inv->invoice_no); ?></title>
        <style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#222;margin:18px;}
            h1,h2,h3{margin:0 0 8px;}
            .muted{color:#666}
            table.tbl{width:100%;border-collapse:collapse;}
            table.tbl th, table.tbl td{border:1px solid #ddd;padding:6px;}
            .right{text-align:right}
        </style>
        </head><body>
        <?php echo $header; ?>
        <h2><?php echo esc_html(sprintf(__('Invoice %s','arm-repair-estimates'), $inv->invoice_no)); ?></h2>
        <p class="muted"><?php echo esc_html(sprintf(__('Status: %s','arm-repair-estimates'), $inv->status)); ?></p>

        <?php if ($cust): ?>
          <p><strong><?php echo esc_html($cust->first_name.' '.$cust->last_name); ?></strong><br>
             <?php echo esc_html($cust->email); ?>
             <?php if(!empty($cust->phone)) echo '<br>'.esc_html($cust->phone); ?>
          </p>
        <?php endif; ?>

        <table class="tbl">
          <thead><tr>
            <th><?php _e('Type','arm-repair-estimates'); ?></th>
            <th><?php _e('Description','arm-repair-estimates'); ?></th>
            <th class="right"><?php _e('Qty','arm-repair-estimates'); ?></th>
            <th class="right"><?php _e('Unit','arm-repair-estimates'); ?></th>
            <th class="right"><?php _e('Line Total','arm-repair-estimates'); ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?php echo esc_html($it->item_type); ?></td>
              <td><?php echo esc_html($it->description); ?></td>
              <td class="right"><?php echo esc_html(number_format((float)$it->qty,2)); ?></td>
              <td class="right"><?php echo esc_html(number_format((float)$it->unit_price,2)); ?></td>
              <td class="right"><?php echo esc_html(number_format((float)$it->line_total,2)); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <p class="right" style="margin-top:10px;">
          <?php _e('Subtotal','arm-repair-estimates'); ?>: <?php echo esc_html(number_format((float)$inv->subtotal,2)); ?><br>
          <?php _e('Tax','arm-repair-estimates'); ?>: <?php echo esc_html(number_format((float)$inv->tax_amount,2)); ?><br>
          <strong><?php _e('Total','arm-repair-estimates'); ?>: <?php echo esc_html(number_format((float)$inv->total,2)); ?></strong>
        </p>
        </body></html>
        <?php
        return ob_get_clean();
    }

    /** ------------------------- Render engine ----------------------- */

    private static function stream_pdf_or_html($html, $filename) {
        
        if (class_exists('\\Dompdf\\Dompdf')) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true,'defaultFont'=>'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->stream($filename, ['Attachment'=>true]);
            exit;
        }
        
        if (class_exists('\\Mpdf\\Mpdf')) {
            $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($filename, 'D'); 
            exit;
        }
        
        if (class_exists('\\TCPDF')) {
            $pdf = new \TCPDF();
            $pdf->AddPage();
            $pdf->writeHTML($html);
            $pdf->Output($filename, 'D');
            exit;
        }

        
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo "<div style='padding:12px;background:#fff3cd;border:1px solid #ffeeba;margin:12px 0;font-family:sans-serif;'>".
             esc_html__('PDF engine not found. Install Dompdf, mPDF, or TCPDF for true PDF downloads. Showing printable HTML instead.','arm-repair-estimates').
             "</div>";
        echo $html;
        exit;
    }
}
