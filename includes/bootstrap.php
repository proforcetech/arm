<?php
// Robust PSR-4-ish autoloader for the ARM\* namespace.
// Maps: ARM\Public\Assets  => includes/public/class-assets.php
//       ARM\Admin\Settings => includes/admin/class-settings.php
//       ARM\Install\Activator => includes/install/class-activator.php
// Also tolerates CamelCase class names by kebab-casing the filename.

if (!defined('ABSPATH')) exit;

spl_autoload_register(function ($class) {
    if (strpos($class, 'ARM\\') !== 0) return;

    // Strip root namespace and split parts
    $relative = substr($class, 4); // after 'ARM\'
    $parts    = explode('\\', $relative);
    $className = array_pop($parts);

    // dir path is lowercased segments (e.g., Public => public)
    $dir = implode('/', array_map('strtolower', $parts));

    // convert ClassName or Class_Name to 'class-class-name.php'
    $slug = strtolower(
        preg_replace(['~/~', '/__+/', '/([a-z])([A-Z])/'], ['-', '_', '$1-$2'], $className)
    );
    $candidate = 'class-' . $slug . '.php';

    // Build candidate file paths to try
    $tries = [];

    // Preferred: includes/<dir>/class-<slug>.php
    if ($dir !== '') {
        $tries[] = ARM_RE_PATH . 'includes/' . $dir . '/' . $candidate;
        // Also try exact class name as file (rare)
        $tries[] = ARM_RE_PATH . 'includes/' . $dir . '/' . $className . '.php';
    }

    // Fallback: includes/<lowerdir>/class-<slug>.php (in case of odd casing)
    if ($dir !== '' && $dir !== strtolower($dir)) {
        $tries[] = ARM_RE_PATH . 'includes/' . strtolower($dir) . '/' . $candidate;
    }

    // Last resort: includes/class-<slug>.php (flat)
    $tries[] = ARM_RE_PATH . 'includes/' . $candidate;

    foreach ($tries as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
