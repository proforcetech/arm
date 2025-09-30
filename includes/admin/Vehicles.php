<?php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

class Vehicles {
    public static function boot() {
        // nothing extra yet
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        global $wpdb; $tbl = $wpdb->prefix.'arm_vehicle_data';

        // Save row
        if (!empty($_POST['arm_vehicle_nonce']) && wp_verify_nonce($_POST['arm_vehicle_nonce'],'arm_vehicle_save')) {
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'year'=>(int)$_POST['year'],
                'make'=>sanitize_text_field($_POST['make']),
                'model'=>sanitize_text_field($_POST['model']),
                'engine'=>sanitize_text_field($_POST['engine']),
                'drive'=>sanitize_text_field($_POST['drive']),
                'trim'=>sanitize_text_field($_POST['trim']),
                'updated_at'=>current_time('mysql'),
            ];
            if ($id) { $wpdb->update($tbl,$data,['id'=>$id]); echo '<div class="updated"><p>Updated.</p></div>'; }
            else { $data['created_at']=current_time('mysql'); $wpdb->insert($tbl,$data); echo '<div class="updated"><p>Added.</p></div>'; }
        }

        // Delete
        if (!empty($_GET['del']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'arm_vehicle_del')) {
            $wpdb->delete($tbl, ['id'=>(int)$_GET['del']]); echo '<div class="updated"><p>Deleted.</p></div>';
        }

        // CSV Upload
        if (!empty($_POST['arm_vehicle_csv_nonce']) && wp_verify_nonce($_POST['arm_vehicle_csv_nonce'],'arm_vehicle_csv_upload') && !empty($_FILES['vehicle_csv']['tmp_name'])) {
            $count=0; $fh = fopen($_FILES['vehicle_csv']['tmp_name'],'r');
            if ($fh) {
                // Expect header: year,make,model,engine,drive,trim
                $header = fgetcsv($fh);
                while (($row = fgetcsv($fh)) !== false) {
                    $map = array_combine(array_map('strtolower',$header), $row);
                    if (!$map) continue;
                    $data = [
                        'year'=>(int)($map['year'] ?? 0),
                        'make'=>sanitize_text_field($map['make'] ?? ''),
                        'model'=>sanitize_text_field($map['model'] ?? ''),
                        'engine'=>sanitize_text_field($map['engine'] ?? ''),
                        'drive'=>sanitize_text_field($map['drive'] ?? ''),
                        'trim'=>sanitize_text_field($map['trim'] ?? ''),
                        'created_at'=>current_time('mysql'),
                    ];
                    if ($data['year'] && $data['make'] && $data['model']) {
                        $wpdb->insert($tbl, $data);
                        $count++;
                    }
                }
                fclose($fh);
            }
            echo '<div class="updated"><p>'.esc_html($count).' rows imported.</p></div>';
        }

        $edit = null; if (!empty($_GET['edit'])) $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d",(int)$_GET['edit']));

        $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY year DESC, make ASC, model ASC, engine ASC, drive ASC, trim ASC LIMIT 200");
        ?>
        <div class="wrap">
          <h1><?php _e('Vehicle Data','arm-repair-estimates'); ?></h1>

          <h2><?php echo $edit ? __('Edit Entry','arm-repair-estimates') : __('Add Entry','arm-repair-estimates'); ?></h2>
          <form method="post">
            <?php wp_nonce_field('arm_vehicle_save','arm_vehicle_nonce'); ?>
            <input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
            <table class="form-table">
              <tr><th>Year</th><td><input type="number" name="year" required value="<?php echo esc_attr($edit->year ?? ''); ?>"></td></tr>
              <tr><th>Make</th><td><input type="text" name="make" required value="<?php echo esc_attr($edit->make ?? ''); ?>"></td></tr>
              <tr><th>Model</th><td><input type="text" name="model" required value="<?php echo esc_attr($edit->model ?? ''); ?>"></td></tr>
              <tr><th>Engine</th><td><input type="text" name="engine" required value="<?php echo esc_attr($edit->engine ?? ''); ?>"></td></tr>
              <tr><th>Drive</th><td><input type="text" name="drive" required value="<?php echo esc_attr($edit->drive ?? ''); ?>"></td></tr>
              <tr><th>Trim</th><td><input type="text" name="trim" required value="<?php echo esc_attr($edit->trim ?? ''); ?>"></td></tr>
            </table>
            <?php submit_button($edit ? __('Update Entry','arm-repair-estimates') : __('Add Entry','arm-repair-estimates')); ?>
          </form>

          <h2><?php _e('CSV Import','arm-repair-estimates'); ?></h2>
          <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('arm_vehicle_csv_upload','arm_vehicle_csv_nonce'); ?>
            <p><input type="file" name="vehicle_csv" accept=".csv"> <button class="button"><?php _e('Upload CSV','arm-repair-estimates'); ?></button></p>
            <p class="description"><?php _e('Header: year,make,model,engine,drive,trim','arm-repair-estimates'); ?></p>
          </form>

          <h2><?php _e('Entries','arm-repair-estimates'); ?></h2>
          <table class="widefat striped">
            <thead><tr><th>ID</th><th>Year</th><th>Make</th><th>Model</th><th>Engine</th><th>Drive</th><th>Trim</th><th><?php _e('Actions'); ?></th></tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r):
                $del = wp_nonce_url(add_query_arg(['del'=>$r->id]),'arm_vehicle_del');
                $edit_url = add_query_arg(['edit'=>$r->id]);
            ?>
              <tr>
                <td><?php echo (int)$r->id; ?></td>
                <td><?php echo esc_html($r->year); ?></td>
                <td><?php echo esc_html($r->make); ?></td>
                <td><?php echo esc_html($r->model); ?></td>
                <td><?php echo esc_html($r->engine); ?></td>
                <td><?php echo esc_html($r->drive); ?></td>
                <td><?php echo esc_html($r->trim); ?></td>
                <td><a href="<?php echo esc_url($edit_url); ?>"><?php _e('Edit'); ?></a> | <a href="<?php echo esc_url($del); ?>" onclick="return confirm('<?php echo esc_js(__('Delete?')); ?>');"><?php _e('Delete'); ?></a></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="8"><?php _e('No data.'); ?></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}
