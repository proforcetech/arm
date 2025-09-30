<?php
namespace ARM\Estimates;
if (!defined('ABSPATH')) exit;

class PublicView {
    public static function boot() {
        add_filter('query_vars', function($vars){ $vars[]='arm_estimate'; return $vars; });
        add_action('template_redirect', [__CLASS__, 'maybe_render']);
    }

    public static function maybe_render() {
        $token = get_query_var('arm_estimate');
        if (!$token) return;
        global $wpdb;
        $eT=$wpdb->prefix.'arm_estimates'; $iT=$wpdb->prefix.'arm_estimate_items'; $cT=$wpdb->prefix.'arm_customers';
        $est = $wpdb->get_row($wpdb->prepare("SELECT * FROM $eT WHERE token=%s", $token));
        if (!$est) { status_header(404); wp_die('Estimate not found'); }
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $iT WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC",$est->id));
        $cust  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cT WHERE id=%d", $est->customer_id));
        $terms = wp_kses_post(get_option('arm_re_terms_html',''));

        // Minimal inline template (replace with your template file)
        get_header();
        echo '<div class="arm-estimate-view">';
        echo '<h1>'.esc_html(sprintf(__('Estimate %s','arm-repair-estimates'), $est->estimate_no)).'</h1>';
        echo '<p><strong>'.esc_html($cust->first_name.' '.$cust->last_name).'</strong> &lt;'.esc_html($cust->email).'&gt;</p>';
        echo '<table class="widefat"><thead><tr><th>'.__('Type').'</th><th>'.__('Description').'</th><th>'.__('Qty').'</th><th>'.__('Unit').'</th><th>'.__('Total').'</th></tr></thead><tbody>';
        foreach ($items as $it) {
            echo '<tr><td>'.esc_html($it->item_type).'</td><td>'.esc_html($it->description).'</td><td>'.esc_html($it->qty).'</td><td>'.esc_html(number_format((float)$it->unit_price,2)).'</td><td>'.esc_html(number_format((float)$it->line_total,2)).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>'.__('Subtotal').':</strong> $'.number_format((float)$est->subtotal,2).'</p>';
        echo '<p><strong>'.__('Tax').':</strong> $'.number_format((float)$est->tax_amount,2).'</p>';
        echo '<p><strong>'.__('Total').':</strong> $'.number_format((float)$est->total,2).'</p>';
        echo '<div class="arm-terms">'.$terms.'</div>';
        echo '</div>';
        get_footer();
        exit;
    }
}
