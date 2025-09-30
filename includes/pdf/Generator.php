<?php
namespace ARM\PDF;
if (!defined('ABSPATH')) exit;

class Generator {
    public static function boot() {
        // add_action('admin_post_arm_re_pdf_estimate', [__CLASS__,'pdf_estimate']);
        // add_action('admin_post_arm_re_pdf_invoice',  [__CLASS__,'pdf_invoice']);
    }
    public static function install_tables() { /* no-op */ }

    // public static function pdf_estimate() { /* TODO: implement Dompdf/Mpdf generation */ }
    // public static function pdf_invoice()  { /* TODO: implement */ }
}
