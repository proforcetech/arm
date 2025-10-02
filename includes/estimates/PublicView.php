<?php
namespace ARM\Estimates;

if (!defined('ABSPATH')) exit;

if (!class_exists(__NAMESPACE__ . '\\PublicView')) {
    require_once __DIR__ . '/class-public-view.php';
}
