<?php
// file: includes/invoices/class-public-view.php
namespace ARM\Invoices;

if (!defined('ABSPATH')) exit;

/**
 * Back-compat shim for misspelled file/class. 
 * Why: some installs referenced this file; we forward to canonical PublicView.
 */
if (!class_exists(__NAMESPACE__ . '\\PublicView')) {
    require_once __DIR__ . '/PublicView.php';
}

if (!class_exists(__NAMESPACE__ . '\\Public_Vew')) {
    final class Public_Vew extends PublicView {}
}
