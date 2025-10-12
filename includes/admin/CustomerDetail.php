<?php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

class CustomerDetail {

    public static function render($customer_id) {
        global $wpdb;

        $tbl_cust = $wpdb->prefix . 'arm_customers';
        $tbl_est  = $wpdb->prefix . 'arm_estimates';
        $tbl_inv  = $wpdb->prefix . 'arm_invoices';
        $tbl_veh  = $wpdb->prefix . 'arm_vehicles';

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tbl_cust WHERE id=%d",
            $customer_id
        ));

        if (!$customer) {
            echo '<div class="notice notice-error"><p>Customer not found.</p></div>';
            return;
        }

        
        if (!empty($_POST['arm_add_vehicle_nonce']) && wp_verify_nonce($_POST['arm_add_vehicle_nonce'], 'arm_add_vehicle')) {
            $data = [
                'customer_id' => $customer_id,
                'year'        => intval($_POST['year']),
                'make'        => sanitize_text_field($_POST['make']),
                'model'       => sanitize_text_field($_POST['model']),
                'engine'      => sanitize_text_field($_POST['engine']),
                'trim'        => sanitize_text_field($_POST['trim']),
                'created_at'  => current_time('mysql'),
                'updated_at'  => current_time('mysql'),
                'user_id'     => null,
                'deleted_at'  => null,
            ];
            $wpdb->insert($tbl_veh, $data);
            echo '<div class="updated"><p>Vehicle added successfully.</p></div>';
        }

        
        if (!empty($_POST['arm_import_csv_nonce']) && wp_verify_nonce($_POST['arm_import_csv_nonce'], 'arm_import_csv') && !empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                $row = 0; $imported = 0;
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row++;
                    if ($row === 1) continue; 
                    [$year, $make, $model, $engine, $trim] = array_pad($data, 5, '');
                    if (!$year || !$make || !$model) continue; 
                    $wpdb->insert($tbl_veh, [
                        'customer_id' => $customer_id,
                        'year'        => intval($year),
                        'make'        => sanitize_text_field($make),
                        'model'       => sanitize_text_field($model),
                        'engine'      => sanitize_text_field($engine),
                        'trim'        => sanitize_text_field($trim),
                        'created_at'  => current_time('mysql'),
                        'updated_at'  => current_time('mysql'),
                        'user_id'     => null,
                        'deleted_at'  => null,
                    ]);
                    $imported++;
                }
                fclose($handle);
                echo '<div class="updated"><p>Imported '.$imported.' vehicles from CSV.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Unable to read CSV file.</p></div>';
            }
        }

        
        if (!empty($_GET['arm_export_csv']) && check_admin_referer('arm_export_csv_'.$customer_id)) {
        $vehicles = $wpdb->get_results($wpdb->prepare(
            "SELECT year, make, model, engine, trim FROM $tbl_veh WHERE customer_id=%d AND (deleted_at IS NULL OR deleted_at='0000-00-00 00:00:00') ORDER BY year DESC, make ASC, model ASC",
            $customer_id
        ), ARRAY_A);

            if ($vehicles) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="customer_'.$customer_id.'_vehicles.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['year','make','model','engine','trim']);
                foreach ($vehicles as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
                exit;
            } else {
                echo '<div class="notice notice-warning"><p>No vehicles to export.</p></div>';
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($customer->first_name . ' ' . $customer->last_name) . '</h1>';
        echo '<p><strong>Email:</strong> ' . esc_html($customer->email) . '<br>';
        echo '<strong>Phone:</strong> ' . esc_html($customer->phone) . '<br>';
        echo '<strong>Address:</strong> ' . esc_html($customer->address . ', ' . $customer->city . ' ' . $customer->zip) . '</p>';

        
        $export_url = wp_nonce_url(
            add_query_arg(['arm_export_csv'=>1]),
            'arm_export_csv_'.$customer_id
        );
        echo '<p><a href="'.esc_url($export_url).'" class="button">Export Vehicles (CSV)</a></p>';

        
        $new_est_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&customer_id='.$customer->id);
        $new_inv_url = admin_url('admin.php?page=arm-repair-invoices&action=new&customer_id='.$customer->id);

        echo '<p>';
        echo '<a href="'.esc_url($new_est_url).'" class="button button-primary">+ New Estimate</a> ';
        echo '<a href="'.esc_url($new_inv_url).'" class="button">+ New Invoice</a>';
        echo '</p>';

        
        echo '<h2>Vehicles</h2>';

        
        echo '<h3>' . esc_html__('Add Vehicle', 'arm-repair-estimates') . '</h3>';
        echo '<form method="post" class="arm-add-vehicle">';
        wp_nonce_field('arm_add_vehicle', 'arm_add_vehicle_nonce');
        echo '<div id="arm-vehicle-cascading" class="arm-vehicle-cascading" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">';
        echo '  <label>' . esc_html__('Year', 'arm-repair-estimates') .
             ' <select id="arm-vehicle-year" name="year" class="small-text" data-selected="" data-placeholder="' . esc_attr__('Select Year', 'arm-repair-estimates') . '" required>' .
             '<option value="">' . esc_html__('Select Year', 'arm-repair-estimates') . '</option></select></label>';
        echo '  <label style="margin-left:10px;">' . esc_html__('Make', 'arm-repair-estimates') .
             ' <select id="arm-vehicle-make" name="make" class="regular-text" style="width:120px;" data-selected="" data-placeholder="' . esc_attr__('Select Make', 'arm-repair-estimates') . '" required>' .
             '<option value="">' . esc_html__('Select Make', 'arm-repair-estimates') . '</option></select></label>';
        echo '  <label style="margin-left:10px;">' . esc_html__('Model', 'arm-repair-estimates') .
             ' <select id="arm-vehicle-model" name="model" class="regular-text" style="width:140px;" data-selected="" data-placeholder="' . esc_attr__('Select Model', 'arm-repair-estimates') . '" required>' .
             '<option value="">' . esc_html__('Select Model', 'arm-repair-estimates') . '</option></select></label>';
        echo '  <label style="margin-left:10px;">' . esc_html__('Engine', 'arm-repair-estimates') .
             ' <select id="arm-vehicle-engine" name="engine" class="regular-text" style="width:140px;" data-selected="" data-placeholder="' . esc_attr__('Select Engine', 'arm-repair-estimates') . '">' .
             '<option value="">' . esc_html__('Select Engine', 'arm-repair-estimates') . '</option></select></label>';
        echo '  <label style="margin-left:10px;">' . esc_html__('Trim', 'arm-repair-estimates') .
             ' <select id="arm-vehicle-trim" name="trim" class="regular-text" style="width:150px;" data-selected="" data-placeholder="' . esc_attr__('Select Trim', 'arm-repair-estimates') . '">' .
             '<option value="">' . esc_html__('Select Trim', 'arm-repair-estimates') . '</option></select></label>';
        echo '</div>';
        submit_button(__('Add Vehicle', 'arm-repair-estimates'));
        echo '</form>';

        
        echo '<h3>Import Vehicles from CSV</h3>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('arm_import_csv', 'arm_import_csv_nonce');
        echo '<input type="file" name="csv_file" accept=".csv" required> ';
        submit_button('Upload & Import CSV');
        echo '<p class="description">CSV format: year, make, model, engine, trim</p>';
        echo '</form>';

        
        $vehicles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tbl_veh WHERE customer_id=%d AND (deleted_at IS NULL OR deleted_at='0000-00-00 00:00:00') ORDER BY year DESC, make ASC, model ASC",
            $customer_id
        ));
        if ($vehicles) {
            echo '<table class="widefat striped"><thead><tr><th>Year</th><th>Make</th><th>Model</th><th>Engine</th><th>Trim</th><th>Actions</th></tr></thead><tbody>';
            foreach ($vehicles as $v) {
                $reuse_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&customer_id='.$customer->id.'&vehicle_id='.$v->id);
                echo '<tr>
                        <td>'.esc_html($v->year).'</td>
                        <td>'.esc_html($v->make).'</td>
                        <td>'.esc_html($v->model).'</td>
                        <td>'.esc_html($v->engine).'</td>
                        <td>'.esc_html($v->trim).'</td>
                        <td><a href="'.esc_url($reuse_url).'" class="button">Use in New Estimate</a></td>
                      </tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No vehicles saved for this customer.</p>';
        }

        
        

        echo '</div>'; 
    }
}
