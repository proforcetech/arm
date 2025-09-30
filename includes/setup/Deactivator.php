<?php
// file: includes/setup/Deactivator.php
namespace ARM\Setup;

if (!defined('ABSPATH')) exit;

/**
 * Why: undo runtime wiring on deactivate (keep data).
 */
final class Deactivator
{
    public static function deactivate(): void
    {
        // Unschedule cleanup
        $ts = wp_next_scheduled('arm_re_cleanup');
        if ($ts) wp_unschedule_event($ts, 'arm_re_cleanup');
        flush_rewrite_rules();
    }
}
