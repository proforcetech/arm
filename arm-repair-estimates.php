<?php
/**
 * Plugin Name: ARM Repair Estimates
 * ...
 */
if (!defined('ABSPATH')) exit;

define('ARM_RE_VERSION', '1.2.0');
define('ARM_RE_PATH', plugin_dir_path(__FILE__));
define('ARM_RE_URL',  plugin_dir_url(__FILE__));

require_once ARM_RE_PATH.'includes/bootstrap.php';   // sets up autoloading and helpers
// --- Add this line to load the activator class file ---
require_once ARM_RE_PATH . 'includes/install/class-activator.php';

// Activation hooks
register_activation_hook(__FILE__, ['ARM\\Install\\Activator', 'activate']);
register_uninstall_hook(__FILE__,  ['ARM\\Install\\Activator', 'uninstall']);


// Boot in phases (admin vs public)
add_action('plugins_loaded', function () {
    ARM\Admin\Dashboard::boot();
    ARM\Admin\Menu::boot();
    ARM\Admin\Assets::boot();
    ARM\Admin\Customers::boot();
    ARM\Admin\Settings::boot();
    ARM\Admin\Services::boot();
    ARM\Admin\Vehicles::boot();               // includes CSV import

    ARM\Public\Assets::boot();
    ARM\Public\Shortcode_Form::boot();
    ARM\Public\Ajax_Submit::boot();

    ARM\Estimates\Controller::boot();
    ARM\Estimates\PublicView::boot();
    ARM\Estimates\Ajax::boot();

    ARM\Invoices\Controller::boot();
    ARM\Invoices\PublicView::boot();

    ARM\Bundles\Controller::boot();
    ARM\Bundles\Ajax::boot();

    ARM\Integrations\Payments_Stripe::boot();
    ARM\Integrations\Payments_PayPal::boot();
    ARM\Integrations\PartsTech::boot();
    ARM\Integrations\Zoho::boot();

    ARM\PDF\Generator::boot();
    ARM\Audit\Logger::boot();
});
