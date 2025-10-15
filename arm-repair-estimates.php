<?php
/**
 * Plugin Name: ARM Repair Estimates
 * ...
 */
if (!defined('ABSPATH')) exit;

define('ARM_RE_VERSION', '1.2.0');
define('ARM_RE_PATH', plugin_dir_path(__FILE__));
define('ARM_RE_URL',  plugin_dir_url(__FILE__));

require_once ARM_RE_PATH.'includes/bootstrap.php';
require_once ARM_RE_PATH . 'includes/install/class-activator.php';

register_activation_hook(__FILE__, ['ARM\\Install\\Activator', 'activate']);
register_uninstall_hook(__FILE__,  ['ARM\\Install\\Activator', 'uninstall']);

add_action('plugins_loaded', ['ARM\\Install\\Activator', 'maybe_upgrade'], 1);


add_action('plugins_loaded', function () {
    ARM\Admin\Dashboard::boot();
    ARM\Admin\Menu::boot();
    ARM\Admin\Assets::boot();
    ARM\Admin\Customers::boot();
    ARM\Admin\Settings::boot();
    ARM\Admin\Services::boot();
    ARM\Admin\Income::boot();
    ARM\Admin\Expenses::boot();
    ARM\Admin\Purchases::boot();
    ARM\Admin\FinancialReports::boot();
    ARM\Admin\Vehicles::boot();
    ARM\Admin\WarrantyClaims::boot();
    ARM\Admin\Reminders::boot();
    ARM\Customer\WarrantyClaims::boot();
    ARM\Appointments\Admin::boot();
    ARM\Appointments\Admin_Availability::boot();

    ARM\Public\Assets::boot();
    ARM\Public\Shortcode_Form::boot();
    ARM\Public\Ajax_Submit::boot();
    ARM\Public\Customer_Dashboard::boot();
    ARM\Public\CustomerExport::boot();
    ARM\Appointments\Frontend::boot();
    ARM\Appointments\Ajax::boot();

    ARM\Estimates\Controller::boot();
    ARM\Estimates\PublicView::boot();
    ARM\Estimates\Ajax::boot();
    ARM\Appointments\Controller::boot();
    ARM\Appointments\Hooks_Make::boot();

    ARM\Invoices\Controller::boot();
    ARM\Invoices\PublicView::boot();

    ARM\Links\Shortlinks::boot();

    ARM\Bundles\Controller::boot();
    ARM\Bundles\Ajax::boot();

    ARM\Integrations\Payments_Stripe::boot();
    ARM\Integrations\Payments_PayPal::boot();
    ARM\Integrations\PartsTech::boot();
    ARM\Integrations\Zoho::boot();
    ARM\Integrations\Appointments_Make::boot();

    ARM\PDF\Generator::boot();
    ARM\Audit\Logger::boot();
    ARM\TimeLogs\Controller::boot();
    ARM\Reminders\Scheduler::boot();
});
