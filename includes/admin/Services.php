<?php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

class Services {
    public static function boot() {}

    public static function render() {
        if (!current_user_can('manage_options')) return;
        global $wpdb; $tbl = $wpdb->prefix.'arm_service_types';

        if (!empty($_POST['arm_services_nonce']) && wp_verify_nonce($_POST['arm_services_nonce'],'arm_services_save')) {
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name'=>sanitize_text_field($_POST['name']),
                'is_active'=>!empty($_POST['is_active']) ? 1 : 0,
                'sort_order'=>(int)($_POST['sort_order'] ?? 0),
                'updated_at'=>current_time('mysql')
            ];
            if ($id) { $wpdb->update($tbl,$data,['id'=>$id]); echo '<div class="updated"><p>Updated.</p></div>'; }
            else { $data['created_at']=current_time('mysql'); $wpdb->insert($tbl,$data); echo '<div class="updated"><p>Added.</p></div>'; }
        }
        if (!empty($_GET['del']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'arm_services_del')) {
            $wpdb->delete($tbl, ['id'=>(int)$_GET['del']]); echo '<div class="updated"><p>Deleted.</p></div>';
        }

        $edit = null; if (!empty($_GET['edit'])) $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d",(int)$_GET['edit']));
        $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY sort_order ASC, name ASC");
        ?>
        <div class="wrap">
          <h1><?php _e('Service Types','arm-repair-estimates'); ?></h1>
          <h2><?php echo $edit ? __('Edit Service Type') : __('Add Service Type'); ?></h2>
          <form method="post">
            <?php wp_nonce_field('arm_services_save','arm_services_nonce'); ?>
            <input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
            <table class="form-table">
              <tr><th><?php _e('Name'); ?></th><td><input type="text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></td></tr>
              <tr><th><?php _e('Active'); ?></th><td><label><input type="checkbox" name="is_active" value="1" <?php checked(($edit->is_active ?? 1),1); ?>> <?php _e('Active'); ?></label></td></tr>
              <tr><th><?php _e('Sort Order'); ?></th><td><input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order ?? 0); ?>"></td></tr>
            </table>
            <?php submit_button($edit ? __('Update') : __('Add')); ?>
          </form>

          <h2><?php _e('Service Types'); ?></h2>
          <table class="widefat striped">
            <thead><tr><th>ID</th><th><?php _e('Name'); ?></th><th><?php _e('Active'); ?></th><th><?php _e('Sort'); ?></th><th><?php _e('Actions'); ?></th></tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r):
                $del = wp_nonce_url(add_query_arg(['del'=>$r->id]), 'arm_services_del');
                $edit_url = add_query_arg(['edit'=>$r->id]);
            ?>
              <tr>
                <td><?php echo (int)$r->id; ?></td>
                <td><?php echo esc_html($r->name); ?></td>
                <td><?php echo $r->is_active ? __('Yes') : __('No'); ?></td>
                <td><?php echo (int)$r->sort_order; ?></td>
                <td><a href="<?php echo esc_url($edit_url); ?>"><?php _e('Edit'); ?></a> | <a href="<?php echo esc_url($del); ?>" onclick="return confirm('<?php echo esc_js(__('Delete?')); ?>');"><?php _e('Delete'); ?></a></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5"><?php _e('No service types found.'); ?></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}
