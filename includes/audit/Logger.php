<?php
namespace ARM\Audit;
if (!defined('ABSPATH')) exit;

class Logger {
    public static function boot() {}
    public static function install_tables() {
        // Optional: add an audit table later
    }
    public static function log($entity, $entity_id, $action, $actor='system', $meta=[]) {
        // Placeholder stub
        do_action('arm_re_audit_log', compact('entity','entity_id','action','actor','meta'));
    }
}
