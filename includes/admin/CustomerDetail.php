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

        // Handle vehicle add
        if (!empty($_POST['arm_add_vehicle_nonce']) && wp_verify_nonce($_POST['arm_add_vehicle_nonce'], 'arm_add_vehicle')) {
            $now = current_time('mysql');
            $data = [
                'customer_id' => $customer_id,
                'year'        => intval($_POST['year']),
                'make'        => self::clean_text($_POST['make'] ?? '', 120),
                'model'       => self::clean_text($_POST['model'] ?? '', 120),
                'engine'      => self::clean_text($_POST['engine'] ?? '', 150),
                'trim'        => self::clean_text($_POST['trim'] ?? '', 120),
                'drive'       => self::clean_text($_POST['drive'] ?? '', 60),
                'vin'         => self::clean_text($_POST['vin'] ?? '', 32),
                'license_plate' => self::clean_text($_POST['license_plate'] ?? '', 32),
                'current_mileage' => self::normalize_mileage($_POST['current_mileage'] ?? ''),
                'previous_service_mileage' => self::normalize_mileage($_POST['previous_service_mileage'] ?? ''),
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
            if ($data['year'] <= 0 || empty($data['make']) || empty($data['model'])) {
                echo '<div class="notice notice-error"><p>Year, make, and model are required.</p></div>';
            } else {
                $wpdb->insert($tbl_veh, $data);
                echo '<div class="updated"><p>Vehicle added successfully.</p></div>';
            }
        }

        // Handle CSV import
        if (!empty($_POST['arm_import_csv_nonce']) && wp_verify_nonce($_POST['arm_import_csv_nonce'], 'arm_import_csv') && !empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                $row = 0; $imported = 0; $cols = [];
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row++;
                    if ($row === 1) {
                        $cols = array_map(function($c){ return strtolower(trim($c)); }, $data);
                        continue;
                    }
                    if (!$cols) { continue; }
                    $mapped = self::map_vehicle_row($cols, $data);
                    if (!$mapped) { continue; }
                    $year  = intval($mapped['year']);
                    $make  = $mapped['make'] ?? '';
                    $model = $mapped['model'] ?? '';
                    if ($year <= 0 || $make === '' || $model === '') continue;
                    $now = current_time('mysql');
                    $wpdb->insert($tbl_veh, [
                        'customer_id' => $customer_id,
                        'year'        => $year,
                        'make'        => $make,
                        'model'       => $model,
                        'engine'      => $mapped['engine'],
                        'trim'        => $mapped['trim'],
                        'drive'       => $mapped['drive'],
                        'vin'         => $mapped['vin'],
                        'license_plate' => $mapped['license_plate'],
                        'current_mileage' => $mapped['current_mileage'],
                        'previous_service_mileage' => $mapped['previous_service_mileage'],
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);
                    $imported++;
                }
                fclose($handle);
                echo '<div class="updated"><p>Imported '.$imported.' vehicles from CSV.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Unable to read CSV file.</p></div>';
            }
        }

        // Handle CSV export
        if (!empty($_GET['arm_export_csv']) && check_admin_referer('arm_export_csv_'.$customer_id)) {
            $vehicles = $wpdb->get_results($wpdb->prepare(
                "SELECT year, make, model, trim, engine, drive, vin, license_plate, current_mileage, previous_service_mileage FROM $tbl_veh WHERE customer_id=%d AND (deleted_at IS NULL) ORDER BY year DESC, make ASC, model ASC",
                $customer_id
            ), ARRAY_A);

            if ($vehicles) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="customer_'.$customer_id.'_vehicles.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['year','make','model','trim','engine','drive','vin','license_plate','current_mileage','previous_service_mileage']);
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

        // Export link
        $export_url = wp_nonce_url(
            add_query_arg(['arm_export_csv'=>1]),
            'arm_export_csv_'.$customer_id
        );
        echo '<p><a href="'.esc_url($export_url).'" class="button">Export Vehicles (CSV)</a></p>';

        // Action buttons
        $new_est_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&customer_id='.$customer->id);
        $new_inv_url = admin_url('admin.php?page=arm-repair-invoices&action=new&customer_id='.$customer->id);

        echo '<p>';
        echo '<a href="'.esc_url($new_est_url).'" class="button button-primary">+ New Estimate</a> ';
        echo '<a href="'.esc_url($new_inv_url).'" class="button">+ New Invoice</a>';
        echo '</p>';

        // Vehicle history
        echo '<h2>Vehicles</h2>';

        // Add vehicle form
        echo '<h3>Add Vehicle</h3>';
        echo '<form method="post" class="arm-add-vehicle">';
        wp_nonce_field('arm_add_vehicle', 'arm_add_vehicle_nonce');
        echo '<table class="form-table">
                <tr><th>Year</th><td><input type="number" name="year" required></td></tr>
                <tr><th>Make</th><td><input type="text" name="make" required></td></tr>
                <tr><th>Model</th><td><input type="text" name="model" required></td></tr>
                <tr><th>Trim</th><td><input type="text" name="trim"></td></tr>
                <tr><th>Engine</th><td><input type="text" name="engine"></td></tr>
                <tr><th>Drive</th><td><input type="text" name="drive"></td></tr>
                <tr><th>VIN</th><td><input type="text" name="vin"></td></tr>
                <tr><th>License Plate</th><td><input type="text" name="license_plate"></td></tr>
                <tr><th>Current Mileage</th><td><input type="number" name="current_mileage" min="0" step="1"></td></tr>
                <tr><th>Previous Service Mileage</th><td><input type="number" name="previous_service_mileage" min="0" step="1"></td></tr>
              </table>';
        submit_button('Add Vehicle');
        echo '</form>';

        // CSV import form
        echo '<h3>Import Vehicles from CSV</h3>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('arm_import_csv', 'arm_import_csv_nonce');
        echo '<input type="file" name="csv_file" accept=".csv" required> ';
        submit_button('Upload & Import CSV');
        echo '<p class="description">CSV format: year, make, model, trim, engine, drive, vin, license_plate, current_mileage, previous_service_mileage</p>';
        echo '</form>';

        // Existing vehicles table
        $vehicles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tbl_veh WHERE customer_id=%d AND (deleted_at IS NULL) ORDER BY year DESC, make ASC, model ASC",
            $customer_id
        ));
        if ($vehicles) {
            echo '<table class="widefat striped"><thead><tr><th>Year</th><th>Make</th><th>Model</th><th>Trim</th><th>Engine</th><th>Drive</th><th>VIN</th><th>License Plate</th><th>Current Mileage</th><th>Previous Service Mileage</th><th>Actions</th></tr></thead><tbody>';
            foreach ($vehicles as $v) {
                $reuse_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&customer_id='.$customer->id.'&vehicle_id='.$v->id);
                echo '<tr>
                        <td>'.esc_html($v->year).'</td>
                        <td>'.esc_html($v->make).'</td>
                        <td>'.esc_html($v->model).'</td>
                        <td>'.esc_html($v->trim).'</td>
                        <td>'.esc_html($v->engine).'</td>
                        <td>'.esc_html($v->drive).'</td>
                        <td>'.esc_html($v->vin).'</td>
                        <td>'.esc_html($v->license_plate).'</td>
                        <td>'.esc_html($v->current_mileage).'</td>
                        <td>'.esc_html($v->previous_service_mileage).'</td>
                        <td><a href="'.esc_url($reuse_url).'" class="button">Use in New Estimate</a></td>
                      </tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No vehicles saved for this customer.</p>';
        }

        // Estimates and invoices stay the same...
        // [Code for Estimates and Invoices as in previous version]

        echo '</div>'; // .wrap
    }
    private static function clean_text($value, int $maxLength = 191) {
        $value = sanitize_text_field((string) ($value ?? ''));
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }
        return substr($value, 0, $maxLength);
    }

    private static function normalize_mileage($value) {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $numeric = preg_replace('/[^0-9]/', '', $value);
        if ($numeric === '') {
            return null;
        }
        return (int) $numeric;
    }

    private static function map_vehicle_row(array $cols, array $row) {
        $lookup = [];
        foreach ($cols as $index => $name) {
            $lookup[$name] = $index;
        }

        $get = function(string $key) use ($lookup, $row) {
            if (!isset($lookup[$key])) {
                return '';
            }
            $value = $row[$lookup[$key]] ?? '';
            return is_string($value) ? $value : (string) $value;
        };

        $data = [
            'year'        => (int) $get('year'),
            'make'        => self::clean_text($get('make'), 120),
            'model'       => self::clean_text($get('model'), 120),
            'trim'        => self::clean_text($get('trim'), 120),
            'engine'      => self::clean_text($get('engine'), 150),
            'drive'       => self::clean_text($get('drive'), 60),
            'vin'         => self::clean_text($get('vin'), 32),
            'license_plate' => self::clean_text($get('license_plate'), 32),
            'current_mileage' => self::normalize_mileage($get('current_mileage')),
            'previous_service_mileage' => self::normalize_mileage($get('previous_service_mileage')),
        ];

        if (!$data['year'] && empty($data['make']) && empty($data['model'])) {
            return null;
        }

        return $data;
    }
}
