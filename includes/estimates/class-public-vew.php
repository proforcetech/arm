<?php
namespace ARM\Estimates;

if (!defined('ABSPATH')) exit;

// Back-compat shim for misspelled class/file.
if (!class_exists(__NAMESPACE__ . '\\PublicView')) {
    require_once __DIR__ . '/class-public-view.php';
}

if (!class_exists(__NAMESPACE__ . '\\Public_Vew')) {
    class Public_Vew extends PublicView {}
}
