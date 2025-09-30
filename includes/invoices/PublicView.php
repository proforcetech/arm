<?php
namespace ARM\Invoices;
if (!defined('ABSPATH')) exit;

class PublicView {
    public static function boot() {
        add_filter('query_vars', function($v){ $v[]='arm_invoice'; return $v; });
        add_action('template_redirect', [__CLASS__, 'maybe_render']);
    }

    public static function maybe_render() {
        $token = get_query_var('arm_invoice');
        if (!$token) return;
        global $wpdb;
        $invT=$wpdb->prefix.'arm_invoices'; $itT=$wpdb->prefix.'arm_invoice_items'; $cT=$wpdb->prefix.'arm_customers';
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $invT WHERE token=%s", $token));
        if (!$inv) { status_header(404); wp_die('Invoice not found'); }
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $itT WHERE invoice_id=%d ORDER BY sort_order ASC, id ASC", $inv->id));
        $cust  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cT WHERE id=%d", $inv->customer_id));

        get_header();
        echo '<div class="arm-invoice-view">';
        echo '<h1>'.esc_html(sprintf(__('Invoice %s','arm-repair-estimates'), $inv->invoice_no)).'</h1>';
        echo '<p><strong>'.esc_html($cust->first_name.' '.$cust->last_name).'</strong> &lt;'.esc_html($cust->email).'&gt;</p>';
        echo '<table class="widefat"><thead><tr><th>'.__('Type').'</th><th>'.__('Description').'</th><th>'.__('Qty').'</th><th>'.__('Unit').'</th><th>'.__('Total').'</th></tr></thead><tbody>';
        foreach ($items as $it) {
            echo '<tr><td>'.esc_html($it->item_type).'</td><td>'.esc_html($it->description).'</td><td>'.esc_html($it->qty).'</td><td>'.esc_html(number_format((float)$it->unit_price,2)).'</td><td>'.esc_html(number_format((float)$it->line_total,2)).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>'.__('Subtotal').':</strong> $'.number_format((float)$inv->subtotal,2).'</p>';
        echo '<p><strong>'.__('Tax').':</strong> $'.number_format((float)$inv->tax_amount,2).'</p>';
        echo '<p><strong>'.__('Total').':</strong> $'.number_format((float)$inv->total,2).'</p>';
        echo '</div>';
        get_footer();
        exit;
    }
}
