<?php
namespace ARM\Audit;
if (!defined('ABSPATH')) exit;

class Logger {
    public static function boot() {}
    public static function install_tables() {
        
    }
    public static function log($entity, $entity_id, $action, $actor='system', $meta=[]) {
        
        do_action('arm_re_audit_log', compact('entity','entity_id','action','actor','meta'));
    }
}
