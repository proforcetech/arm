<?php
// file: includes/admin/InventoryAlerts.php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Inventory Alerts page showing items at/below threshold.
 * Why: quick triage with direct links to edit.
 */
final class InventoryAlerts
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'arm-repair-estimates',
            __('Inventory Alerts', 'arm-repair-estimates'),
            __('Inventory Alerts', 'arm-repair-estimates'),
            'manage_options',
            'arm-inventory-alerts',
            [__CLASS__, 'render'],
            21
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'arm-repair-estimates'));
        }

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_inventory';
        $cols = self::schema_map($tbl);

        if (!$cols['qty'] || !$cols['threshold'] || !$cols['name']) {
            echo '<div class="wrap"><h1>' . esc_html__('Inventory Alerts', 'arm-repair-estimates') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Required inventory columns not found.', 'arm-repair-estimates') . '</p></div></div>';
            return;
        }

        $fields = array_filter([$cols['id'] ?? 'id', $cols['name'], $cols['sku'] ?? 'sku', $cols['qty'], $cols['threshold']]);
        $select = implode(',', array_map(static fn($c) => "$c AS `$c`", $fields));
        $sql = "SELECT $select FROM $tbl WHERE {$cols['qty']} <= {$cols['threshold']} ORDER BY {$cols['qty']} ASC, {$cols['name']} ASC";
        $items = $wpdb->get_results($sql);

        $back = admin_url('admin.php?page=arm-inventory');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Inventory Alerts', 'arm-repair-estimates'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url($back); ?>"><?php echo esc_html__('Back to Inventory', 'arm-repair-estimates'); ?></a>
            <hr class="wp-header-end">

            <table class="widefat striped">
              <thead>
                <tr>
                  <th><?php echo esc_html__('ID', 'arm-repair-estimates'); ?></th>
                  <th><?php echo esc_html__('Name', 'arm-repair-estimates'); ?></th>
                  <th><?php echo esc_html__('SKU', 'arm-repair-estimates'); ?></th>
                  <th><?php echo esc_html__('Qty', 'arm-repair-estimates'); ?></th>
                  <th><?php echo esc_html__('Threshold', 'arm-repair-estimates'); ?></th>
                  <th><?php echo esc_html__('Action', 'arm-repair-estimates'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php if ($items): ?>
                    <?php foreach ($items as $r): ?>
                        <?php
                          $id  = (int) ($r->{$cols['id'] ?? 'id'} ?? 0);
                          $nm  = (string) ($r->{$cols['name']} ?? '');
                          $sku = (string) ($r->{$cols['sku'] ?? 'sku'} ?? '');
                          $qty = (float)  ($r->{$cols['qty']} ?? 0);
                          $thr = (float)  ($r->{$cols['threshold']} ?? 0);
                          $edit = admin_url('admin.php?page=arm-inventory&action=edit&id=' . $id);
                        ?>
                        <tr>
                          <td>#<?php echo (int) $id; ?></td>
                          <td><?php echo esc_html($nm); ?></td>
                          <td><?php echo esc_html($sku); ?></td>
                          <td><?php echo esc_html(number_format_i18n($qty)); ?></td>
                          <td><?php echo esc_html(number_format_i18n($thr)); ?></td>
                          <td><a class="button button-small" href="<?php echo esc_url($edit); ?>"><?php echo esc_html__('Edit', 'arm-repair-estimates'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6"><?php echo esc_html__('No low-stock items. Nice!', 'arm-repair-estimates'); ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
        </div>
        <?php
    }

    /** Minimal schema detector re-used here */
    private static function schema_map(string $table): array
    {
        global $wpdb;
        $cols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        ) ?: [];
        $has = array_change_key_case(array_flip($cols), CASE_LOWER);

        $pick = function(array $cands) use ($has) {
            foreach ($cands as $c) if (isset($has[strtolower($c)])) return $c;
            return null;
        };

        return [
            'id'        => $pick(['id','item_id','inventory_id']),
            'name'      => $pick(['name','item_name','title']),
            'sku'       => $pick(['sku','item_sku','code']),
            'qty'       => $pick(['qty_on_hand','quantity','stock_qty','qty']),
            'threshold' => $pick(['low_stock_threshold','reorder_level','low_threshold','threshold']),
        ];
    }
}
