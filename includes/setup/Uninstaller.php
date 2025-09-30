<?php
// file: includes/setup/Uninstaller.php
namespace ARM\Setup;

if (!defined('ABSPATH')) exit;

/**
 * Cleans options on uninstall. Tables are preserved unless ARM_RE_DROP_TABLES is truthy.
 * Why: avoid accidental data loss by default.
 */
final class Uninstaller
{
    public static function uninstall(): void
    {
        // Options
        $opts = [
            'arm_re_labor_rate','arm_re_tax_rate','arm_re_currency',
            'arm_re_pay_success','arm_re_pay_cancel',
            'arm_company_logo','arm_company_name','arm_company_address','arm_company_phone','arm_company_email',
            'arm_re_page_estimate_form','arm_re_page_customer_dashboard',
            'arm_make_calendar_webhook','arm_make_email_webhook','arm_make_sms_webhook','arm_make_webhook_url',
            'arm_re_stripe_pk','arm_re_stripe_sk','arm_re_stripe_whsec',
            'arm_re_paypal_env','arm_re_paypal_client_id','arm_re_paypal_secret',
            'arm_partstech_base','arm_partstech_api_key','arm_re_markup_tiers',
            'arm_zoho_dc','arm_zoho_client_id','arm_zoho_client_secret','arm_zoho_refresh','arm_zoho_module_deals',
        ];
        foreach ($opts as $o) delete_option($o);

        // Optional table drop
        if (defined('ARM_RE_DROP_TABLES') && ARM_RE_DROP_TABLES) {
            global $wpdb;
            $tables = [
                'arm_estimates','arm_estimate_items','arm_estimate_submissions',
                'arm_invoices','arm_invoice_items',
                'arm_vehicle_data','arm_service_types','arm_appointments',
            ];
            foreach ($tables as $t) {
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}$t");
            }
        }
    }
}
