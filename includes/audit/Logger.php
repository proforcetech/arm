<?php
namespace ARM\Audit;

use ARM\Utils\Impersonation;

if (!defined('ABSPATH')) exit;

class Logger {
    private const TABLE = 'arm_audit_log';

    public static function boot(): void {
        \add_action('admin_menu', [__CLASS__, 'register_menu']);
    }

    public static function register_menu(): void {
        \add_submenu_page(
            'arm-repair-estimates',
            __('Audit Log', 'arm-repair-estimates'),
            __('Audit Log', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-audit-log',
            [__CLASS__, 'render_admin']
        );
    }

    public static function install_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            ip_address VARCHAR(100) NULL,
            details LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY actor_user_id (actor_user_id),
            KEY customer_id (customer_id),
            KEY created_at (created_at)
        ) $charset;";
        \dbDelta($sql);
    }

    public static function log($entity, $entity_id, $action, $actor = 'system', $meta = []): void {
        do_action('arm_re_audit_log', compact('entity', 'entity_id', 'action', 'actor', 'meta'));
    }

    public static function log_impersonation(int $actor_user_id, int $customer_id, string $action, string $ip_address = ''): void {
        $event = $action === 'start' ? 'impersonation_start' : 'impersonation_stop';
        self::insert([
            'event_type'    => $event,
            'actor_user_id' => $actor_user_id,
            'customer_id'   => $customer_id,
            'ip_address'    => $ip_address,
            'details'       => '',
        ]);
    }

    public static function render_admin(): void {
        if (!\current_user_can('manage_options')) return;
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100");

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Audit Log', 'arm-repair-estimates').'</h1>';

        if (!$rows) {
            echo '<p>'.esc_html__('No audit entries found.', 'arm-repair-estimates').'</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Timestamp', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Event', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Actor', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Customer', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('IP Address', 'arm-repair-estimates').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $actor = \get_userdata((int) $row->actor_user_id);
            $actor_label = $actor ? $actor->display_name : sprintf(__('User #%d', 'arm-repair-estimates'), (int) $row->actor_user_id);

            $customer_label = __('None', 'arm-repair-estimates');
            if (!empty($row->customer_id)) {
                $customer = Impersonation::get_customer((int) $row->customer_id);
                if ($customer) {
                    $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                    $customer_label = $name !== '' ? $name : ($customer->email ?? sprintf(__('Customer #%d', 'arm-repair-estimates'), (int) $row->customer_id));
                } else {
                    $customer_label = sprintf(__('Customer #%d', 'arm-repair-estimates'), (int) $row->customer_id);
                }
            }

            $event_label = $row->event_type === 'impersonation_start'
                ? __('Impersonation started', 'arm-repair-estimates')
                : __('Impersonation stopped', 'arm-repair-estimates');

            echo '<tr>';
            echo '<td>'.esc_html($row->created_at).'</td>';
            echo '<td>'.esc_html($event_label).'</td>';
            echo '<td>'.esc_html($actor_label).'</td>';
            echo '<td>'.esc_html($customer_label).'</td>';
            echo '<td>'.esc_html($row->ip_address ?? '').'</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    private static function insert(array $data): void {
        global $wpdb;
        $defaults = [
            'event_type'    => '',
            'actor_user_id' => 0,
            'customer_id'   => 0,
            'ip_address'    => '',
            'details'       => '',
            'created_at'    => \current_time('mysql'),
        ];
        $data = array_merge($defaults, $data);
        $wpdb->insert(
            self::table_name(),
            $data,
            [
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );
    }
}
