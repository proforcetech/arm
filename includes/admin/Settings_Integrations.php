<?php

namespace ARM\Admin;
if (!defined('ABSPATH')) exit;


if (!class_exists(__NAMESPACE__ . '\\Settings_Integrations')) {
    final class Settings_Integrations {
        public static function boot(): void {}
    }
}
