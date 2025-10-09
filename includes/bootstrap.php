<?php

if (!defined('ABSPATH')) exit;

spl_autoload_register(function ($class) {
    if (strpos($class, 'ARM\\') !== 0) return;

    $relative = substr($class, 4);
    $parts    = explode('\\', $relative);
    $className = array_pop($parts);

    $dir = implode('/', array_map('strtolower', $parts));

    $slug = strtolower(
        preg_replace(['~/~', '/__+/', '/([a-z])([A-Z])/'], ['-', '_', '$1-$2'], $className)
    );
    $candidate = 'class-' . $slug . '.php';

    $tries = [];

    if ($dir !== '') {
        $tries[] = ARM_RE_PATH . 'includes/' . $dir . '/' . $candidate;
        $tries[] = ARM_RE_PATH . 'includes/' . $dir . '/' . $className . '.php';
    }

    if ($dir !== '' && $dir !== strtolower($dir)) {
        $tries[] = ARM_RE_PATH . 'includes/' . strtolower($dir) . '/' . $candidate;
    }

    $tries[] = ARM_RE_PATH . 'includes/' . $candidate;

    foreach ($tries as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
