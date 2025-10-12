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

        
        $vehicle_fields = ['year', 'make', 'model', 'engine', 'transmission', 'drive', 'trim'];
        $vehicle_input = array_fill_keys($vehicle_fields, '');

        if (!empty($_POST['arm_add_vehicle_nonce']) && wp_verify_nonce($_POST['arm_add_vehicle_nonce'], 'arm_add_vehicle')) {
            foreach ($vehicle_fields as $field) {
                $key = 'vehicle_' . $field;
                if (isset($_POST[$key])) {
                    $value = sanitize_text_field(wp_unslash($_POST[$key]));
                    $vehicle_input[$field] = $value;
                }
            }

            if ($vehicle_input['year'] === '' || $vehicle_input['make'] === '' || $vehicle_input['model'] === '') {
                echo '<div class="notice notice-error"><p>' . esc_html__('Please select a Year, Make, and Model before adding a vehicle.', 'arm-repair-estimates') . '</p></div>';
            } else {
                $data = [
                    'customer_id'   => $customer_id,
                    'year'          => $vehicle_input['year'] !== '' ? absint($vehicle_input['year']) : null,
                    'make'          => $vehicle_input['make'],
                    'model'         => $vehicle_input['model'],
                    'engine'        => $vehicle_input['engine'],
                    'transmission'  => $vehicle_input['transmission'],
                    'drive'         => $vehicle_input['drive'],
                    'trim'          => $vehicle_input['trim'],
                    'created_at'    => current_time('mysql'),
                    'updated_at'    => current_time('mysql'),
                    'user_id'       => null,
                    'deleted_at'    => null,
                ];

                $inserted = $wpdb->insert($tbl_veh, $data);
                if ($inserted) {
                    echo '<div class="updated"><p>' . esc_html__('Vehicle added successfully.', 'arm-repair-estimates') . '</p></div>';
                    $vehicle_input = array_fill_keys($vehicle_fields, '');
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Unable to add vehicle. Please try again.', 'arm-repair-estimates') . '</p></div>';
                }
            }
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

        $field_configs = [
            'year' => [
                'label'      => esc_html__('Year', 'arm-repair-estimates'),
                'name'       => 'vehicle_year',
                'class'      => 'small-text',
                'style'      => '',
                'required'   => true,
                'placeholder'=> esc_html__('Select Year', 'arm-repair-estimates'),
                'label_style'=> '',
            ],
            'make' => [
                'label'      => esc_html__('Make', 'arm-repair-estimates'),
                'name'       => 'vehicle_make',
                'class'      => 'regular-text',
                'style'      => 'width:120px;',
                'required'   => true,
                'placeholder'=> esc_html__('Select Make', 'arm-repair-estimates'),
                'label_style'=> 'margin-left:10px;',
            ],
            'model' => [
                'label'      => esc_html__('Model', 'arm-repair-estimates'),
                'name'       => 'vehicle_model',
                'class'      => 'regular-text',
                'style'      => 'width:140px;',
                'required'   => true,
                'placeholder'=> esc_html__('Select Model', 'arm-repair-estimates'),
                'label_style'=> 'margin-left:10px;',
            ],
            'engine' => [
                'label'      => esc_html__('Engine', 'arm-repair-estimates'),
                'name'       => 'vehicle_engine',
                'class'      => 'regular-text',
                'style'      => 'width:140px;',
                'required'   => false,
                'placeholder'=> esc_html__('Select Engine', 'arm-repair-estimates'),
                'label_style'=> 'margin-left:10px;',
            ],
            'transmission' => [
                'label'      => esc_html__('Transmission', 'arm-repair-estimates'),
                'name'       => 'vehicle_transmission',
                'class'      => 'regular-text',
                'style'      => 'width:150px;',
                'required'   => false,
                'placeholder'=> esc_html__('Select Transmission', 'arm-repair-estimates'),
                'label_style'=> 'margin-left:10px;',
            ],
            'drive' => [
                'label'      => esc_html__('Drive', 'arm-repair-estimates'),
                'name'       => 'vehicle_drive',
                'class'      => 'regular-text',
                'style'      => 'width:120px;',
                'required'   => false,
                'placeholder'=> esc_html__('Select Drive', 'arm-repair-estimates'),
                'label_style'=> 'margin-left:10px;',
            ],
            'trim' => [
                'label'      => esc_html__('Trim', 'arm-repair-estimates'),
                'name'       => 'vehicle_trim',
                'class'      => 'regular-text',
                'style'      => 'width:150px;',
                'required'   => false,
                'placeholder'=> esc_html__('Select Trim', 'arm-repair-estimates'),
                'label_style'=> 'margin-left:10px;',
            ],
        ];

        foreach ($field_configs as $field => $config) {
            $select_id = 'arm-vehicle-' . $field;
            $label_style = $config['label_style'] ? ' style="' . esc_attr($config['label_style']) . '"' : '';
            $select_style = $config['style'] ? ' style="' . esc_attr($config['style']) . '"' : '';
            $required = $config['required'] ? ' required' : '';
            $placeholder_attr = esc_attr($config['placeholder']);
            $selected_value = esc_attr($vehicle_input[$field]);

            echo '<label' . $label_style . '>' . $config['label'];
            echo ' <select id="' . esc_attr($select_id) . '" name="' . esc_attr($config['name']) . '" class="' . esc_attr($config['class']) . '"' . $select_style . ' data-selected="' . $selected_value . '" data-placeholder="' . $placeholder_attr . '"' . $required . '>';
            echo '<option value="">' . esc_html($config['placeholder']) . '</option>';
            echo '</select></label>';
        }

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
