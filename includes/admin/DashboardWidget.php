<?php
// file: includes/admin/DashboardWidget.php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

/**
 * WP Dashboard widget showing low-stock count with a link to inventory alerts.
 */
final class DashboardWidget
{
    public static function boot(): void
    {
        add_action('wp_dashboard_setup', [__CLASS__, 'register']);
    }

    public static function register(): void
    {
        wp_add_dashboard_widget(
            'arm_low_stock_widget',
            __('Low Stock Alerts', 'arm-repair-estimates'),
            [__CLASS__, 'render']
        );
    }

    public static function render(): void
    {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_inventory';

        // Safe defaults if table/columns vary by install.
        $col_qty   = self::first_existing_column($tbl, ['qty_on_hand', 'quantity', 'stock_qty']);
        $col_low   = self::first_existing_column($tbl, ['low_stock_threshold', 'reorder_level', 'low_threshold']);

        if (!$col_qty || !$col_low) {
            echo '<p>' . esc_html__('Inventory schema not found.', 'arm-repair-estimates') . '</p>';
            return;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tbl WHERE $col_qty <= $col_low");

        if ($count <= 0) {
            echo '<p>' . esc_html__('All parts are above threshold.', 'arm-repair-estimates') . '</p>';
            return;
        }

        $url = admin_url('admin.php?page=arm-inventory-alerts');

        printf(
            '<p>%s %s <a class="button button-small" href="%s">%s</a></p>',
            esc_html(number_format_i18n($count)),
            esc_html(_n('part is below threshold', 'parts are below threshold', $count, 'arm-repair-estimates')),
            esc_url($url),
            esc_html__('View Alerts', 'arm-repair-estimates')
        );
    }

    /** Find the first existing column from a list. */
    private static function first_existing_column(string $table, array $candidates): ?string
    {
        global $wpdb;
        $schema = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        );
        if (!$schema) return null;
        $map = array_change_key_case(array_flip($schema), CASE_LOWER);
        foreach ($candidates as $c) {
            if (isset($map[strtolower($c)])) return $c;
        }
        return null;
    }
}
