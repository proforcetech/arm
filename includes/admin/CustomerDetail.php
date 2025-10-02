<?php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

class CustomerDetail {

    public static function render($customer_id) {
        global $wpdb;

        $tbl_cust   = $wpdb->prefix . 'arm_customers';
        $tbl_est    = $wpdb->prefix . 'arm_estimates';
        $tbl_inv    = $wpdb->prefix . 'arm_invoices';
        $tbl_veh    = $wpdb->prefix . 'arm_vehicles';
        $tbl_claims = $wpdb->prefix . 'arm_warranty_claims';

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tbl_cust WHERE id=%d",
            $customer_id
        ));

        if (!$customer) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Customer not found.', 'arm-repair-estimates') . '</p></div>';
            return;
        }

        // Handle vehicle add
        if (!empty($_POST['arm_add_vehicle_nonce']) && wp_verify_nonce($_POST['arm_add_vehicle_nonce'], 'arm_add_vehicle')) {
            $data = [
                'customer_id' => $customer_id,
                'year'        => intval($_POST['year'] ?? 0),
                'make'        => sanitize_text_field($_POST['make'] ?? ''),
                'model'       => sanitize_text_field($_POST['model'] ?? ''),
                'engine'      => sanitize_text_field($_POST['engine'] ?? ''),
                'trim'        => sanitize_text_field($_POST['trim'] ?? ''),
                'created_at'  => current_time('mysql'),
            ];
            $wpdb->insert($tbl_veh, $data);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Vehicle added successfully.', 'arm-repair-estimates') . '</p></div>';
        }

        // Handle CSV import
        if (!empty($_POST['arm_import_csv_nonce']) && wp_verify_nonce($_POST['arm_import_csv_nonce'], 'arm_import_csv') && !empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                $row = 0; $imported = 0;
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
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
                    ]);
                    $imported++;
                }
                fclose($handle);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Imported %d vehicles from CSV.', 'arm-repair-estimates'), $imported)) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('Unable to read CSV file.', 'arm-repair-estimates') . '</p></div>';
            }
        }

        // Handle CSV export
        if (!empty($_GET['arm_export_csv']) && check_admin_referer('arm_export_csv_' . $customer_id)) {
            $vehicles_export = $wpdb->get_results($wpdb->prepare(
                "SELECT year, make, model, engine, trim FROM $tbl_veh WHERE customer_id=%d ORDER BY year DESC, make ASC, model ASC",
                $customer_id
            ), ARRAY_A);

            if ($vehicles_export) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="customer_' . $customer_id . '_vehicles.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['year','make','model','engine','trim']);
                foreach ($vehicles_export as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
                exit;
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('No vehicles to export.', 'arm-repair-estimates') . '</p></div>';
            }
        }

        $full_name   = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        $business    = trim((string)($customer->business_name ?? ''));
        $tax_id      = trim((string)($customer->tax_id ?? ''));
        $email       = trim((string)($customer->email ?? ''));
        $phone       = trim((string)($customer->phone ?? ''));
        $tax_label   = !empty($customer->tax_exempt) ? esc_html__('Yes', 'arm-repair-estimates') : esc_html__('No', 'arm-repair-estimates');
        $contact_address = array_filter([
            trim((string)($customer->address ?? '')),
            trim(implode(', ', array_filter([$customer->city ?? '', $customer->state ?? ''], 'strlen'))),
            trim((string)($customer->zip ?? '')),
        ], 'strlen');
        $billing_address = array_filter([
            trim((string)($customer->billing_address1 ?? '')),
            trim((string)($customer->billing_address2 ?? '')),
            trim(implode(', ', array_filter([$customer->billing_city ?? '', $customer->billing_state ?? ''], 'strlen'))),
            trim((string)($customer->billing_zip ?? '')),
        ], 'strlen');

        $vehicle_columns = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s",
            $tbl_veh
        )) ?: [];
        $vehicle_columns = array_map('strtolower', $vehicle_columns);
        $vehicle_sql = "SELECT * FROM $tbl_veh WHERE customer_id=%d";
        if (in_array('deleted_at', $vehicle_columns, true)) {
            $vehicle_sql .= " AND (deleted_at IS NULL OR deleted_at='0000-00-00 00:00:00')";
        }
        $vehicle_sql .= " ORDER BY year DESC, make ASC, model ASC";
        $vehicles = $wpdb->get_results($wpdb->prepare($vehicle_sql, $customer_id));

        $estimates = $wpdb->get_results($wpdb->prepare(
            "SELECT id, estimate_no, status, total, token, created_at FROM $tbl_est WHERE customer_id=%d ORDER BY created_at DESC LIMIT 50",
            $customer_id
        ));

        $invoices = $wpdb->get_results($wpdb->prepare(
            "SELECT id, invoice_no, status, total, token, created_at FROM $tbl_inv WHERE customer_id=%d ORDER BY created_at DESC LIMIT 50",
            $customer_id
        ));

        $claims = [];
        $claims_table_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s",
            $tbl_claims
        )) > 0;
        $claim_token_column = null;
        if ($claims_table_exists) {
            $claim_columns = $wpdb->get_col($wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s",
                $tbl_claims
            )) ?: [];
            $claim_columns = array_map('strtolower', $claim_columns);
            foreach (['public_token', 'token', 'claim_token'] as $candidate) {
                if (in_array($candidate, $claim_columns, true)) {
                    $claim_token_column = $candidate;
                    break;
                }
            }
            $claim_where = '';
            $claim_params = [];
            if (in_array('customer_id', $claim_columns, true)) {
                $claim_where = 'customer_id=%d';
                $claim_params[] = $customer_id;
            } elseif (in_array('email', $claim_columns, true) && $email !== '') {
                $claim_where = 'email=%s';
                $claim_params[] = $email;
            }
            if ($claim_where) {
                $claim_select = "SELECT id, invoice_id, subject, status, created_at";
                if ($claim_token_column) {
                    $claim_select .= ", $claim_token_column AS claim_token";
                }
                $claim_sql = "$claim_select FROM $tbl_claims WHERE $claim_where ORDER BY created_at DESC";
                $claims = $wpdb->get_results($claim_params ? $wpdb->prepare($claim_sql, ...$claim_params) : $claim_sql);
            }
        }

        $new_est_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&customer_id=' . (int) $customer->id);
        $new_inv_url = admin_url('admin.php?page=arm-repair-invoices&action=new&customer_id=' . (int) $customer->id);
        $edit_url    = admin_url('admin.php?page=arm-repair-customers&action=edit&id=' . (int) $customer->id);
        $export_url  = wp_nonce_url(add_query_arg(['arm_export_csv' => 1]), 'arm_export_csv_' . $customer_id);

        $contact_address_html = $contact_address ? implode('<br>', array_map('esc_html', $contact_address)) : esc_html__('Not provided', 'arm-repair-estimates');
        $billing_address_html = $billing_address ? implode('<br>', array_map('esc_html', $billing_address)) : esc_html__('Not provided', 'arm-repair-estimates');
        $business_html = $business !== '' ? esc_html($business) : esc_html__('Not provided', 'arm-repair-estimates');
        $tax_id_html   = $tax_id !== '' ? esc_html($tax_id) : esc_html__('Not provided', 'arm-repair-estimates');
        $email_html    = $email !== '' ? esc_html($email) : esc_html__('Not provided', 'arm-repair-estimates');
        $phone_html    = $phone !== '' ? esc_html($phone) : esc_html__('Not provided', 'arm-repair-estimates');
        $created_html  = !empty($customer->created_at) ? esc_html($customer->created_at) : esc_html__('Unknown', 'arm-repair-estimates');
        $updated_html  = !empty($customer->updated_at) ? esc_html($customer->updated_at) : esc_html__('—', 'arm-repair-estimates');
        $notes_html    = ($customer->notes ?? '') !== '' ? wpautop(esc_html((string) $customer->notes)) : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($full_name ?: __('Customer', 'arm-repair-estimates')) . '</h1>';
        echo '<p class="arm-customer-actions">';
        echo '<a href="' . esc_url($new_est_url) . '" class="button button-primary">' . esc_html__('+ New Estimate', 'arm-repair-estimates') . '</a> ';
        echo '<a href="' . esc_url($new_inv_url) . '" class="button">' . esc_html__('+ New Invoice', 'arm-repair-estimates') . '</a> ';
        echo '<a href="' . esc_url($edit_url) . '" class="button">' . esc_html__('Edit Customer', 'arm-repair-estimates') . '</a> ';
        echo '<a href="' . esc_url($export_url) . '" class="button">' . esc_html__('Export Vehicles (CSV)', 'arm-repair-estimates') . '</a>';
        echo '</p>';

        echo '<div class="arm-customer-profile" style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;">';
        echo '<div class="arm-card" style="flex:1 1 300px;background:#fff;padding:20px;border:1px solid #ccd0d4;">';
        echo '<h2>' . esc_html__('Contact Information', 'arm-repair-estimates') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('Name', 'arm-repair-estimates') . '</th><td>' . esc_html($full_name) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Business', 'arm-repair-estimates') . '</th><td>' . $business_html . '</td></tr>';
        echo '<tr><th>' . esc_html__('Email', 'arm-repair-estimates') . '</th><td>' . $email_html . '</td></tr>';
        echo '<tr><th>' . esc_html__('Phone', 'arm-repair-estimates') . '</th><td>' . $phone_html . '</td></tr>';
        echo '<tr><th>' . esc_html__('Customer Address', 'arm-repair-estimates') . '</th><td>' . $contact_address_html . '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="arm-card" style="flex:1 1 300px;background:#fff;padding:20px;border:1px solid #ccd0d4;">';
        echo '<h2>' . esc_html__('Billing Details', 'arm-repair-estimates') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('Billing Address', 'arm-repair-estimates') . '</th><td>' . $billing_address_html . '</td></tr>';
        echo '<tr><th>' . esc_html__('Tax ID', 'arm-repair-estimates') . '</th><td>' . $tax_id_html . '</td></tr>';
        echo '<tr><th>' . esc_html__('Tax Exempt', 'arm-repair-estimates') . '</th><td>' . $tax_label . '</td></tr>';
        echo '<tr><th>' . esc_html__('Created', 'arm-repair-estimates') . '</th><td>' . $created_html . '</td></tr>';
        echo '<tr><th>' . esc_html__('Updated', 'arm-repair-estimates') . '</th><td>' . $updated_html . '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        if ($notes_html !== '') {
            echo '<div class="arm-card" style="flex:1 1 300px;background:#fff;padding:20px;border:1px solid #ccd0d4;">';
            echo '<h2>' . esc_html__('Internal Notes', 'arm-repair-estimates') . '</h2>';
            echo $notes_html;
            echo '</div>';
        }
        echo '</div>';

        echo '<h2>' . esc_html__('Vehicles', 'arm-repair-estimates') . '</h2>';
        if ($vehicles) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Year', 'arm-repair-estimates') . '</th><th>' . esc_html__('Make', 'arm-repair-estimates') . '</th><th>' . esc_html__('Model', 'arm-repair-estimates') . '</th><th>' . esc_html__('Engine', 'arm-repair-estimates') . '</th><th>' . esc_html__('Trim', 'arm-repair-estimates') . '</th><th>' . esc_html__('Actions', 'arm-repair-estimates') . '</th></tr></thead><tbody>';
            foreach ($vehicles as $v) {
                $reuse_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&customer_id=' . (int) $customer->id . '&vehicle_id=' . (int) $v->id);
                echo '<tr>';
                echo '<td>' . esc_html($v->year) . '</td>';
                echo '<td>' . esc_html($v->make) . '</td>';
                echo '<td>' . esc_html($v->model) . '</td>';
                echo '<td>' . esc_html($v->engine) . '</td>';
                echo '<td>' . esc_html($v->trim) . '</td>';
                echo '<td><a class="button" href="' . esc_url($reuse_url) . '">' . esc_html__('Use in New Estimate', 'arm-repair-estimates') . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No vehicles saved for this customer.', 'arm-repair-estimates') . '</p>';
        }

        echo '<div style="display:flex;flex-wrap:wrap;gap:30px;margin-top:20px;">';
        echo '<div style="flex:1 1 300px;">';
        echo '<h3>' . esc_html__('Add Vehicle', 'arm-repair-estimates') . '</h3>';
        echo '<form method="post" class="arm-add-vehicle">';
        wp_nonce_field('arm_add_vehicle', 'arm_add_vehicle_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('Year', 'arm-repair-estimates') . '</th><td><input type="number" name="year" required></td></tr>';
        echo '<tr><th>' . esc_html__('Make', 'arm-repair-estimates') . '</th><td><input type="text" name="make" required></td></tr>';
        echo '<tr><th>' . esc_html__('Model', 'arm-repair-estimates') . '</th><td><input type="text" name="model" required></td></tr>';
        echo '<tr><th>' . esc_html__('Engine', 'arm-repair-estimates') . '</th><td><input type="text" name="engine"></td></tr>';
        echo '<tr><th>' . esc_html__('Trim', 'arm-repair-estimates') . '</th><td><input type="text" name="trim"></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Add Vehicle', 'arm-repair-estimates'));
        echo '</form>';
        echo '</div>';

        echo '<div style="flex:1 1 300px;">';
        echo '<h3>' . esc_html__('Import Vehicles from CSV', 'arm-repair-estimates') . '</h3>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('arm_import_csv', 'arm_import_csv_nonce');
        echo '<p><input type="file" name="csv_file" accept=".csv" required> ';
        submit_button(__('Upload & Import CSV', 'arm-repair-estimates'), 'secondary', '', false);
        echo '</p>';
        echo '<p class="description">' . esc_html__('CSV format: year, make, model, engine, trim', 'arm-repair-estimates') . '</p>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<h2>' . esc_html__('Estimates', 'arm-repair-estimates') . '</h2>';
        if ($estimates) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Number', 'arm-repair-estimates') . '</th><th>' . esc_html__('Status', 'arm-repair-estimates') . '</th><th>' . esc_html__('Total', 'arm-repair-estimates') . '</th><th>' . esc_html__('Created', 'arm-repair-estimates') . '</th><th>' . esc_html__('Actions', 'arm-repair-estimates') . '</th></tr></thead><tbody>';
            foreach ($estimates as $est) {
                $admin_link  = admin_url('admin.php?page=arm-repair-estimates-builder&action=edit&id=' . (int) $est->id);
                $public_link = !empty($est->token) ? add_query_arg(['arm_estimate' => $est->token], home_url('/')) : '';
                echo '<tr>';
                echo '<td>' . esc_html($est->estimate_no) . '</td>';
                echo '<td>' . esc_html($est->status) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $est->total, 2)) . '</td>';
                echo '<td>' . esc_html($est->created_at) . '</td>';
                echo '<td><a href="' . esc_url($admin_link) . '">' . esc_html__('Admin View', 'arm-repair-estimates') . '</a>';
                if ($public_link) {
                    echo ' | <a href="' . esc_url($public_link) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Public View', 'arm-repair-estimates') . '</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No estimates found.', 'arm-repair-estimates') . '</p>';
        }

        echo '<h2>' . esc_html__('Invoices', 'arm-repair-estimates') . '</h2>';
        if ($invoices) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Number', 'arm-repair-estimates') . '</th><th>' . esc_html__('Status', 'arm-repair-estimates') . '</th><th>' . esc_html__('Total', 'arm-repair-estimates') . '</th><th>' . esc_html__('Created', 'arm-repair-estimates') . '</th><th>' . esc_html__('Actions', 'arm-repair-estimates') . '</th></tr></thead><tbody>';
            foreach ($invoices as $inv) {
                $public_link = !empty($inv->token) ? add_query_arg(['arm_invoice' => $inv->token], home_url('/')) : '';
                echo '<tr>';
                echo '<td>' . esc_html($inv->invoice_no) . '</td>';
                echo '<td>' . esc_html($inv->status) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $inv->total, 2)) . '</td>';
                echo '<td>' . esc_html($inv->created_at) . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url(admin_url('admin.php?page=arm-repair-invoices')) . '">' . esc_html__('Admin View', 'arm-repair-estimates') . '</a>';
                if ($public_link) {
                    echo ' | <a href="' . esc_url($public_link) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Public View', 'arm-repair-estimates') . '</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No invoices found.', 'arm-repair-estimates') . '</p>';
        }

        echo '<h2>' . esc_html__('Warranty Claims', 'arm-repair-estimates') . '</h2>';
        if ($claims) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('ID', 'arm-repair-estimates') . '</th><th>' . esc_html__('Invoice', 'arm-repair-estimates') . '</th><th>' . esc_html__('Subject', 'arm-repair-estimates') . '</th><th>' . esc_html__('Status', 'arm-repair-estimates') . '</th><th>' . esc_html__('Created', 'arm-repair-estimates') . '</th><th>' . esc_html__('Actions', 'arm-repair-estimates') . '</th></tr></thead><tbody>';
            foreach ($claims as $claim) {
                $admin_claim = admin_url('admin.php?page=arm-warranty-claims&view=' . (int) $claim->id);
                $public_claim = (!empty($claim->claim_token)) ? add_query_arg(['arm_warranty_claim' => $claim->claim_token], home_url('/')) : '';
                echo '<tr>';
                echo '<td>#' . esc_html($claim->id) . '</td>';
                echo '<td>' . esc_html($claim->invoice_id) . '</td>';
                echo '<td>' . esc_html($claim->subject) . '</td>';
                echo '<td>' . esc_html($claim->status) . '</td>';
                echo '<td>' . esc_html($claim->created_at) . '</td>';
                echo '<td><a href="' . esc_url($admin_claim) . '">' . esc_html__('Admin View', 'arm-repair-estimates') . '</a>';
                if ($public_claim) {
                    echo ' | <a href="' . esc_url($public_claim) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Public View', 'arm-repair-estimates') . '</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No warranty claims.', 'arm-repair-estimates') . '</p>';
        }

        echo '</div>';
    }

}
