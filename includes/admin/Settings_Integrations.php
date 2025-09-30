<?php
// file: includes/admin/Settings_Integration.php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

// why: ensure compatibility if this file is loaded directly
if (!class_exists(__NAMESPACE__ . '\\Settings_Integrations')) {
    final class Settings_Integrations {
        public static function boot(): void {}
    }
}
