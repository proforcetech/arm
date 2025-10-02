<?php
namespace ARM\Bundles;

if (!defined('ABSPATH')) exit;

class Controller {

    /** --------------------------------------------------------------
     * Boot hooks
     * --------------------------------------------------------------*/
    public static function boot() {
        // AJAX: fetch bundle items for the estimate builder
        add_action('wp_ajax_arm_re_get_bundle_items', [__CLASS__, 'ajax_get_bundle_items']);
    }

    public static function menu_page(): array {
        return [
            'page_title' => __('Preset Bundles','arm-repair-estimates'),
            'menu_title' => __('Preset Bundles','arm-repair-estimates'),
            'capability' => 'manage_options',
            'menu_slug'  => 'arm-repair-bundles',
            'callback'   => [__CLASS__, 'render_admin'],
        ];
    }

    /** --------------------------------------------------------------
     * DB install/upgrade
     * --------------------------------------------------------------*/
    public static function install_tables() {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $bT  = $wpdb->prefix . 'arm_service_bundles';
        $biT = $wpdb->prefix . 'arm_service_bundle_items';

        dbDelta("CREATE TABLE $bT (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service_type_id BIGINT UNSIGNED NULL,
            name VARCHAR(128) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY(id),
            INDEX(service_type_id), INDEX(is_active)
        ) $charset;");

        dbDelta("CREATE TABLE $biT (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bundle_id BIGINT UNSIGNED NOT NULL,
            item_type ENUM('LABOR','PART','FEE','DISCOUNT') NOT NULL DEFAULT 'LABOR',
            description VARCHAR(255) NOT NULL,
            qty DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            taxable TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(id),
            INDEX(bundle_id)
        ) $charset;");
    }

    /** --------------------------------------------------------------
     * AJAX: return items for a bundle (used by estimate builder)
     * --------------------------------------------------------------*/
    public static function ajax_get_bundle_items() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        check_ajax_referer('arm_re_est_admin', '_ajax_nonce');

        global $wpdb;
        $id  = (int)($_POST['bundle_id'] ?? 0);
        $biT = $wpdb->prefix . 'arm_service_bundle_items';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT item_type, description, qty, unit_price, taxable, sort_order
             FROM $biT WHERE bundle_id=%d
             ORDER BY sort_order ASC, id ASC", $id
        ), ARRAY_A);
        wp_send_json_success(['items' => $rows ?: []]);
    }

    /** --------------------------------------------------------------
     * Admin UI (create/edit bundles + list)
     * --------------------------------------------------------------*/
    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $bT  = $wpdb->prefix . 'arm_service_bundles';
        $biT = $wpdb->prefix . 'arm_service_bundle_items';
        $sT  = $wpdb->prefix . 'arm_service_types';

        // Save bundle (+ replace items)
        if (!empty($_POST['arm_bndl_nonce']) && wp_verify_nonce($_POST['arm_bndl_nonce'], 'arm_bndl_save')) {
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'service_type_id' => (int)($_POST['service_type_id'] ?? 0) ?: null,
                'name'            => sanitize_text_field($_POST['name']),
                'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
                'sort_order'      => (int)($_POST['sort_order'] ?? 0),
                'updated_at'      => current_time('mysql'),
            ];
            if ($id) {
                $wpdb->update($bT, $data, ['id' => $id]);
            } else {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($bT, $data);
                $id = (int)$wpdb->insert_id;
            }

            // Replace items
            $wpdb->query($wpdb->prepare("DELETE FROM $biT WHERE bundle_id=%d", $id));
            $items = $_POST['items'] ?? [];
            $i = 0;
            foreach ($items as $it) {
                if (empty($it['desc'])) continue;
                $wpdb->insert($biT, [
                    'bundle_id'  => $id,
                    'item_type'  => in_array(($it['type'] ?? 'LABOR'), ['LABOR','PART','FEE','DISCOUNT'], true) ? $it['type'] : 'LABOR',
                    'description'=> sanitize_text_field($it['desc']),
                    'qty'        => (float)($it['qty'] ?? 1),
                    'unit_price' => (float)($it['price'] ?? 0),
                    'taxable'    => !empty($it['taxable']) ? 1 : 0,
                    'sort_order' => $i++,
                ]);
            }
            echo '<div class="updated"><p>' . esc_html__('Saved.','arm-repair-estimates') . '</p></div>';
        }

        // Data for UI
        $services = $wpdb->get_results("SELECT id, name FROM $sT WHERE is_active=1 ORDER BY name ASC");
        $bundles  = $wpdb->get_results("SELECT * FROM $bT ORDER BY sort_order ASC, name ASC");
        ?>
        <div class="wrap">
          <h1><?php _e('Preset Bundles','arm-repair-estimates'); ?></h1>
          <p><?php _e('Create common job bundles and insert them into estimates with one click.','arm-repair-estimates'); ?></p>

          <h2><?php _e('Add / Edit Bundle','arm-repair-estimates'); ?></h2>
          <form method="post">
            <?php wp_nonce_field('arm_bndl_save', 'arm_bndl_nonce'); ?>
            <input type="hidden" name="id" value="">
            <table class="form-table" role="presentation">
              <tr>
                <th><label for="arm_bndl_name"><?php _e('Name','arm-repair-estimates'); ?></label></th>
                <td><input id="arm_bndl_name" type="text" name="name" required class="regular-text"></td>
              </tr>
              <tr>
                <th><label for="arm_bndl_service_type"><?php _e('Service Type (optional)','arm-repair-estimates'); ?></label></th>
                <td>
                  <select id="arm_bndl_service_type" name="service_type_id">
                    <option value=""><?php _e('— none —','arm-repair-estimates'); ?></option>
                    <?php foreach ($services as $s): ?>
                      <option value="<?php echo (int)$s->id; ?>"><?php echo esc_html($s->name); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <th><?php _e('Active','arm-repair-estimates'); ?></th>
                <td><label><input type="checkbox" name="is_active" value="1" checked> <?php _e('Active','arm-repair-estimates'); ?></label></td>
              </tr>
              <tr>
                <th><label for="arm_bndl_sort"><?php _e('Sort Order','arm-repair-estimates'); ?></label></th>
                <td><input id="arm_bndl_sort" type="number" name="sort_order" value="0"></td>
              </tr>
            </table>

            <h3><?php _e('Bundle Items','arm-repair-estimates'); ?></h3>
            <table class="widefat striped" id="arm-bundle-items">
              <thead>
                <tr>
                  <th><?php _e('Type','arm-repair-estimates'); ?></th>
                  <th><?php _e('Description','arm-repair-estimates'); ?></th>
                  <th><?php _e('Qty','arm-repair-estimates'); ?></th>
                  <th><?php _e('Unit','arm-repair-estimates'); ?></th>
                  <th><?php _e('Tax','arm-repair-estimates'); ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody></tbody>
              <tfoot>
                <tr><td colspan="6"><button type="button" class="button" id="arm-bndl-add"><?php _e('Add Item','arm-repair-estimates'); ?></button></td></tr>
              </tfoot>
            </table>

            <p class="submit"><button class="button button-primary"><?php _e('Save Bundle','arm-repair-estimates'); ?></button></p>
          </form>

          <h2><?php _e('Existing Bundles','arm-repair-estimates'); ?></h2>
          <ul>
            <?php foreach ($bundles as $b): ?>
              <li>#<?php echo (int)$b->id; ?> — <?php echo esc_html($b->name); ?> (<?php echo $b->is_active ? esc_html__('Active','arm-repair-estimates') : esc_html__('Inactive','arm-repair-estimates'); ?>)</li>
            <?php endforeach; ?>
          </ul>
        </div>

        <script>
        (function($){
          function row(i){
            return `<tr>
              <td>
                <select name="items[${i}][type]">
                  <option>LABOR</option>
                  <option>PART</option>
                  <option>FEE</option>
                  <option>DISCOUNT</option>
                </select>
              </td>
              <td><input name="items[${i}][desc]" class="widefat"></td>
              <td><input type="number" step="0.01" name="items[${i}][qty]" value="1"></td>
              <td><input type="number" step="0.01" name="items[${i}][price]" value="0.00"></td>
              <td><input type="checkbox" name="items[${i}][taxable]" value="1" checked></td>
              <td><button type="button" class="button arm-del">&times;</button></td>
            </tr>`;
          }
          $('#arm-bndl-add').on('click', function(){
            var i = $('#arm-bundle-items tbody tr').length;
            $('#arm-bundle-items tbody').append(row(i));
          });
          $(document).on('click', '.arm-del', function(){ $(this).closest('tr').remove(); });
        })(jQuery);
        </script>
        <?php
    }
}
