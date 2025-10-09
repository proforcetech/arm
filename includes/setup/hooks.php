<?php


if (!defined('ABSPATH')) exit;

spl_autoload_register(static function($class){
    if (strpos($class, 'ARM\\Setup\\') !== 0) return;
    $rel = str_replace(['ARM\\', '\\'], ['', '/'], $class);
    $file = defined('ARM_RE_PATH') ? ARM_RE_PATH . 'includes/' . $rel . '.php' : plugin_dir_path(__FILE__) . '../' . $rel . '.php';
    if (is_readable($file)) require_once $file;
});

register_activation_hook(defined('ARM_RE_FILE') ? ARM_RE_FILE : __FILE__, ['ARM\\Setup\\Activator', 'activate']);
register_deactivation_hook(defined('ARM_RE_FILE') ? ARM_RE_FILE : __FILE__, ['ARM\\Setup\\Deactivator', 'deactivate']);
register_uninstall_hook(defined('ARM_RE_FILE') ? ARM_RE_FILE : __FILE__, ['ARM\\Setup\\Uninstaller', 'uninstall']);


add_action('arm_re_cleanup', ['ARM\\Setup\\Activator', 'cleanup']);
