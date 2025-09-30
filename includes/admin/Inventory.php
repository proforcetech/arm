<?php
// file: includes/admin/Inventory.php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Inventory admin (list, create, edit, delete).
 * Why: single entry controls + schema tolerance to avoid fatals on differing installs.
 */
final class Inventory
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_arm_inventory_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_arm_inventory_delete', [__CLASS__, 'handle_delete']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'arm-repair-estimates',
            __('Inventory', 'arm-repair-estimates'),
            __('Inventory', 'arm-repair-estimates'),
            'manage_options',
            'arm-inventory',
            [__CLASS__, 'render_router'],
            20
        );
    }

    /** Simple action router: list | add | edit */
    public static function render_router(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'arm-repair-estimates'));
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'add' || $action === 'edit') {
            self::render_form();
        } else {
            self::render_list();
        }
    }

    /** List with search + pagination */
    private static function render_list(): void
    {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_inventory';

        $cols = self::schema_map($tbl);

        $search = isset($_GET['s']) ? wp_unslash((string) $_GET['s']) : '';
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $pp     = 20;
        $offset = ($page - 1) * $pp;

        $where  = 'WHERE 1=1';
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $by   = [];
            foreach (['name','sku','vendor','notes'] as $k) {
                if (!empty($cols[$k])) { $by[] = "{$cols[$k]} LIKE %s"; $params[] = $like; }
            }
            if ($by) { $where .= ' AND (' . implode(' OR ', $by) . ')'; }
        }

        $count_sql = "SELECT COUNT(*) FROM $tbl $where";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, ...$params) : $count_sql);

        $fields = array_filter([$cols['id'] ?? 'id', $cols['name'] ?? 'name', $cols['sku'] ?? 'sku',
                               $cols['qty'] ?? 'qty_on_hand', $cols['threshold'] ?? 'low_stock_threshold',
                               $cols['price'] ?? 'price']);
        $select = implode(',', array_map(static fn($c) => "$c AS `$c`", $fields));

        $list_sql = "SELECT $select FROM $tbl $where ORDER BY " . ($cols['name'] ?? 'name') . " ASC LIMIT %d OFFSET %d";
        $result   = $params ? $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($params, [$pp, $offset]))) :
                              $wpdb->get_results($wpdb->prepare($list_sql, $pp, $offset));

        $alerts_url = admin_url('admin.php?page=arm-inventory-alerts');
        $add_url    = admin_url('admin.php?page=arm-inventory&action=add');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Inventory', 'arm-repair-estimates'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url($add_url); ?>"><?php echo esc_html__('Add New', 'arm-repair-estimates'); ?></a>
            <a class="page-title-action" href="<?php echo esc_url($alerts_url); ?>"><?php echo esc_html__('Low Stock Alerts', 'arm-repair-estimates'); ?></a>
            <hr class="wp-header-end">

            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="arm-inventory" />
                <p class="search-box">
                    <label class="screen-reader-text" for="post-search-input"><?php echo esc_html__('Search Inventory', 'arm-repair-estimates'); ?></label>
                    <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr($search); ?>" />
                    <input type="submit" class="button" value="<?php echo esc_attr__('Search', 'arm-repair-estimates'); ?>" />
                </p>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'arm-repair-estimates'); ?></th>
                        <th><?php echo esc_html__('Name', 'arm-repair-estimates'); ?></th>
                        <th><?php echo esc_html__('SKU', 'arm-repair-estimates'); ?></th>
                        <th><?php echo esc_html__('Qty', 'arm-repair-estimates'); ?></th>
                        <th><?php echo esc_html__('Threshold', 'arm-repair-estimates'); ?></th>
                        <th><?php echo esc_html__('Price', 'arm-repair-estimates'); ?></th>
                        <th><?php echo esc_html__('Actions', 'arm-repair-estimates'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result): ?>
                        <?php foreach ($result as $row): ?>
                            <?php
                              $id  = (int) ($row->{$cols['id'] ?? 'id'} ?? 0);
                              $nm  = (string) ($row->{$cols['name'] ?? 'name'} ?? '');
                              $sku = (string) ($row->{$cols['sku'] ?? 'sku'} ?? '');
                              $qty = (float)  ($row->{$cols['qty'] ?? 'qty_on_hand'} ?? 0);
                              $thr = (float)  ($row->{$cols['threshold'] ?? 'low_stock_threshold'} ?? 0);
                              $pr  = (float)  ($row->{$cols['price'] ?? 'price'} ?? 0);
                              $edit_url = admin_url('admin.php?page=arm-inventory&action=edit&id=' . $id);
                            ?>
                            <tr>
                                <td>#<?php echo (int) $id; ?></td>
                                <td><?php echo esc_html($nm); ?></td>
                                <td><?php echo esc_html($sku); ?></td>
                                <td><?php echo esc_html(number_format_i18n($qty)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($thr)); ?></td>
                                <td>$<?php echo esc_html(number_format_i18n($pr, 2)); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html__('Edit', 'arm-repair-estimates'); ?></a>
                                    <?php self::delete_button($id); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7"><?php echo esc_html__('No items found.', 'arm-repair-estimates'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $total_pages = max(1, (int) ceil($total / $pp));
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total'     => $total_pages,
                    'current'   => $page,
                ]);
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    /** Add/Edit form */
    private static function render_form(): void
    {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'arm_inventory';
        $cols = self::schema_map($tbl);

        $id   = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
        $is_edit = $id > 0;

        $row = null;
        if ($is_edit) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE " . ($cols['id'] ?? 'id') . "=%d", $id));
            if (!$row) {
                echo '<div class="wrap"><h1>' . esc_html__('Inventory', 'arm-repair-estimates') . '</h1>';
                echo '<div class="notice notice-error"><p>' . esc_html__('Item not found.', 'arm-repair-estimates') . '</p></div></div>';
                return;
            }
        }

        $back = admin_url('admin.php?page=arm-inventory');
        ?>
        <div class="wrap">
          <h1><?php echo $is_edit ? esc_html__('Edit Item', 'arm-repair-estimates') : esc_html__('Add Item', 'arm-repair-estimates'); ?></h1>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('arm_inventory_save', '_arm_inv_nonce'); ?>
            <input type="hidden" name="action" value="arm_inventory_save" />
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />

            <table class="form-table" role="presentation">
              <tbody>
                <tr>
                  <th><label for="name"><?php echo esc_html__('Name', 'arm-repair-estimates'); ?></label></th>
                  <td><input name="name" id="name" type="text" class="regular-text" required value="<?php echo esc_attr($row->{$cols['name'] ?? 'name'} ?? ''); ?>"></td>
                </tr>
                <tr>
                  <th><label for="sku"><?php echo esc_html__('SKU', 'arm-repair-estimates'); ?></label></th>
                  <td><input name="sku" id="sku" type="text" class="regular-text" value="<?php echo esc_attr($row->{$cols['sku'] ?? 'sku'} ?? ''); ?>"></td>
                </tr>
                <tr>
                  <th><label for="qty"><?php echo esc_html__('Quantity on Hand', 'arm-repair-estimates'); ?></label></th>
                  <td><input name="qty" id="qty" type="number" step="1" min="0" value="<?php echo esc_attr((string) ($row->{$cols['qty'] ?? 'qty_on_hand'} ?? '0')); ?>"></td>
                </tr>
                <tr>
                  <th><label for="threshold"><?php echo esc_html__('Low Stock Threshold', 'arm-repair-estimates'); ?></label></th>
                  <td><input name="threshold" id="threshold" type="number" step="1" min="0" value="<?php echo esc_attr((string) ($row->{$cols['threshold'] ?? 'low_stock_threshold'} ?? '0')); ?>"></td>
                </tr>
                <tr>
                  <th><label for="cost"><?php echo esc_html__('Cost', 'arm-repair-estimates'); ?></label></th>
                  <td><input name="cost" id="cost" type="number" step="0.01" min="0" value="<?php echo esc_attr((string) ($row->{$cols['cost'] ?? 'cost'} ?? '0')); ?>"></td>
                </tr>
                <tr>
                  <th><label for="price"><?php echo esc_html__('Price', 'arm-repair-estimates'); ?></label></th>
                  <td><input name="price" id="price" type="number" step="0.01" min="0" value="<?php echo esc_attr((string) ($row->{$cols['price'] ?? 'price'} ?? '0')); ?>"></td>
                </tr>
                <tr>
                  <th><label for="vendor"><?php echo esc_html__('Vendor', 'arm-repair-estimates'); ?></label></th>
                  <td><input name="vendor" id="vendor" type="text" class="regular-text" value="<?php echo esc_attr($row->{$cols['vendor'] ?? 'vendor'} ?? ''); ?>"></td>
                </tr>
                <tr>
                  <th><label for="notes"><?php echo esc_html__('Notes', 'arm-repair-estimates'); ?></label></th>
                  <td><textarea name="notes" id="notes" class="large-text" rows="4"><?php echo esc_textarea($row->{$cols['notes'] ?? 'notes'} ?? ''); ?></textarea></td>
                </tr>
              </tbody>
            </table>

            <p class="submit">
              <a class="button" href="<?php echo esc_url($back); ?>">&larr; <?php echo esc_html__('Back', 'arm-repair-estimates'); ?></a>
              <?php if ($is_edit): ?>
                  <button type="submit" class="button button-primary"><?php echo esc_html__('Update Item', 'arm-repair-estimates'); ?></button>
              <?php else: ?>
                  <button type="submit" class="button button-primary"><?php echo esc_html__('Create Item', 'arm-repair-estimates'); ?></button>
              <?php endif; ?>
            </p>
          </form>
        </div>
        <?php
    }

    /** POST handler for create/update */
    public static function handle_save(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'arm-repair-estimates'));
        check_admin_referer('arm_inventory_save', '_arm_inv_nonce');

        global $wpdb;
        $tbl  = $wpdb->prefix . 'arm_inventory';
        $cols = self::schema_map($tbl);

        $id   = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;

        // Build data map only for existing columns (why: tolerate schema variance)
        $payload = [
            'name'      => sanitize_text_field($_POST['name'] ?? ''),
            'sku'       => sanitize_text_field($_POST['sku'] ?? ''),
            'vendor'    => sanitize_text_field($_POST['vendor'] ?? ''),
            'notes'     => wp_kses_post($_POST['notes'] ?? ''),
            'qty'       => (int) ($_POST['qty'] ?? 0),
            'threshold' => (int) ($_POST['threshold'] ?? 0),
            'cost'      => (float) ($_POST['cost'] ?? 0),
            'price'     => (float) ($_POST['price'] ?? 0),
        ];

        $data  = [];
        $types = [];

        $map_to_col = [
            'name'      => $cols['name'] ?? null,
            'sku'       => $cols['sku'] ?? null,
            'vendor'    => $cols['vendor'] ?? null,
            'notes'     => $cols['notes'] ?? null,
            'qty'       => $cols['qty'] ?? null,
            'threshold' => $cols['threshold'] ?? null,
            'cost'      => $cols['cost'] ?? null,
            'price'     => $cols['price'] ?? null,
        ];

        foreach ($map_to_col as $key => $column) {
            if (!$column) continue;
            $val = $payload[$key];
            $data[$column] = $val;
            $types[] = is_int($val) ? '%d' : (is_float($val) ? '%f' : '%s');
        }

        if ($id > 0) {
            // Update
            $wpdb->update(
                $tbl,
                $data,
                [ $cols['id'] ?? 'id' => $id ],
                $types,
                ['%d']
            );
        } else {
            // Insert
            $wpdb->insert($tbl, $data, $types);
            $id = (int) $wpdb->insert_id;
        }

        $dest = admin_url('admin.php?page=arm-inventory&updated=1');
        if ($id > 0) $dest = add_query_arg(['action' => 'edit', 'id' => $id, 'updated' => 1], admin_url('admin.php?page=arm-inventory'));
        wp_safe_redirect($dest);
        exit;
    }

    /** POST handler for delete */
    public static function handle_delete(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'arm-repair-estimates'));
        check_admin_referer('arm_inventory_delete', '_arm_inv_del_nonce');

        global $wpdb;
        $tbl  = $wpdb->prefix . 'arm_inventory';
        $cols = self::schema_map($tbl);

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=arm-inventory&error=1'));
            exit;
        }

        $wpdb->delete($tbl, [ $cols['id'] ?? 'id' => $id ], ['%d']);
        wp_safe_redirect(admin_url('admin.php?page=arm-inventory&deleted=1'));
        exit;
    }

    /** Renders a secure delete button for a row */
    private static function delete_button(int $id): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js(__('Delete this item?', 'arm-repair-estimates')); ?>');">
            <?php wp_nonce_field('arm_inventory_delete', '_arm_inv_del_nonce'); ?>
            <input type="hidden" name="action" value="arm_inventory_delete" />
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
            <button type="submit" class="button button-small button-link-delete"><?php echo esc_html__('Delete', 'arm-repair-estimates'); ?></button>
        </form>
        <?php
    }

    /**
     * Schema map: resolves commonly used columns.
     * Returns keys: id,name,sku,qty,threshold,cost,price,vendor,notes
     */
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
            'cost'      => $pick(['cost','unit_cost']),
            'price'     => $pick(['price','unit_price','sale_price']),
            'vendor'    => $pick(['vendor','supplier']),
            'notes'     => $pick(['notes','description']),
        ];
    }
}
