<?php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Customers admin: CRUD UI + CSV import + CRM import hook.
 */
final class Customers {

    public static function boot(): void {
        // Menu
        \add_action('admin_menu', [__CLASS__, 'menu']);
        \add_action('admin_menu', [__CLASS__, 'register_detail_page']);

        // Handlers
        \add_action('admin_post_arm_re_customer_save',         [__CLASS__, 'handle_save']);
        \add_action('admin_post_arm_re_customer_delete',       [__CLASS__, 'handle_delete']);
        \add_action('admin_post_arm_re_customer_import_csv',   [__CLASS__, 'handle_import_csv']);
        \add_action('admin_post_arm_re_customer_import_crm',   [__CLASS__, 'handle_import_crm']);

        // AJAX search (for dynamic selectors elsewhere)
        \add_action('wp_ajax_arm_re_customer_search',          [__CLASS__, 'ajax_search']);
    }

    /** Add submenu under main plugin menu */
    public static function menu(): void {
        \add_submenu_page(
            'arm-repair-estimates',
            __('Customers', 'arm-repair-estimates'),
            __('Customers', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-customers',
            [__CLASS__, 'render_admin']
        );
    }

    public static function register_detail_page(): void {
        \add_submenu_page(
            null,
            __('Customer Details', 'arm-repair-estimates'),
            __('Customer Details', 'arm-repair-estimates'),
            'manage_options',
            'arm-customer-detail',
            function () {
                $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                CustomerDetail::render($id);
            }
        );
    }

    /** Admin screen router */
    public static function render_admin(): void {
        if (!\current_user_can('manage_options')) return;

        $action = isset($_GET['action']) ? \sanitize_key($_GET['action']) : 'list';
        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        echo '<div class="wrap">';

        // Notices
        if (!empty($_GET['imported'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
               . esc_html($_GET['imported']) . ' ' . esc_html__('customers imported.', 'arm-repair-estimates')
               . '</p></div>';
        }
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
               . esc_html__('Customer saved.', 'arm-repair-estimates')
               . '</p></div>';
        }
        if (!empty($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
               . esc_html__('Customer deleted.', 'arm-repair-estimates')
               . '</p></div>';
        }
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>'
               . esc_html($_GET['error'])
               . '</p></div>';
        }

        switch ($action) {
            case 'new':
            case 'edit':
                self::render_form($id);
                break;
            case 'import':
                self::render_import();
                break;
            default:
                self::render_list();
                break;
        }

        echo '</div>';
    }

    /** ===== List view with search & pagination ===== */
    private static function render_list(): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';

        $q     = isset($_GET['s']) ? \sanitize_text_field($_GET['s']) : '';
        $page  = max(1, (int)($_GET['paged'] ?? 1));
        $per   = 25;
        $off   = ($page - 1) * $per;

        $where = 'WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $like   = '%' . $wpdb->esc_like($q) . '%';
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)";
            $params = [$like, $like, $like, $like];
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM $tbl $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per, $off])))
            : $wpdb->get_results($wpdb->prepare($sql, $per, $off));
        $total = (int) $wpdb->get_var("SELECT FOUND_ROWS()");

        $pages = max(1, (int) ceil($total / $per));
        $new_url    = \admin_url('admin.php?page=arm-repair-customers&action=new');
        $import_url = \admin_url('admin.php?page=arm-repair-customers&action=import');

        echo '<h1 class="wp-heading-inline">'.esc_html__('Customers', 'arm-repair-estimates').'</h1>';
        echo ' <a href="'.esc_url($new_url).'" class="page-title-action">'.esc_html__('Add New', 'arm-repair-estimates').'</a>';
        echo ' <a href="'.esc_url($import_url).'" class="page-title-action">'.esc_html__('Import', 'arm-repair-estimates').'</a>';
        echo '<hr class="wp-header-end">';

        // Search box
        echo '<form method="get" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="page" value="arm-repair-customers">';
        echo '<input type="search" name="s" value="'.esc_attr($q).'" class="regular-text" placeholder="'.esc_attr__('Search name, email, phone', 'arm-repair-estimates').'"> ';
        submit_button(__('Search'), 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Name', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Email', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Phone', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Address', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Created', 'arm-repair-estimates').'</th>';
        echo '<th>'.esc_html__('Actions', 'arm-repair-estimates').'</th>';
        echo '</tr></thead><tbody>';

        if ($rows) {
            foreach ($rows as $r) {
                $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                $addr = trim(($r->address ?? '') . ( ($r->city ?? '') ? ', '.$r->city : '' ) . ( ($r->state ?? '') ? ', '.$r->state : '' ) . ( ($r->zip ?? '') ? ' '.$r->zip : '' ));
                $detail = \admin_url('admin.php?page=arm-customer-detail&id='.(int)$r->id);
                $edit = \admin_url('admin.php?page=arm-repair-customers&action=edit&id='.(int)$r->id);
                $del  = \wp_nonce_url(\admin_url('admin-post.php?action=arm_re_customer_delete&id='.(int)$r->id), 'arm_re_customer_delete');
                echo '<tr>';
                echo '<td>'.esc_html($name ?: '').'</td>';
                echo '<td>'.esc_html($r->email ?: '').'</td>';
                echo '<td>'.esc_html($r->phone ?: '').'</td>';
                echo '<td>'.esc_html($addr ?: '').'</td>';
                echo '<td>'.esc_html($r->created_at ?: '').'</td>';
                echo '<td><a href="'.esc_url($detail).'">'.esc_html__('Details', 'arm-repair-estimates').'</a> | <a href="'.esc_url($edit).'">'.esc_html__('Edit', 'arm-repair-estimates').'</a> | '
                   . '<a href="'.esc_url($del).'" onclick="return confirm(\''.esc_js(__('Delete this customer?', 'arm-repair-estimates')).'\');">'.esc_html__('Delete', 'arm-repair-estimates').'</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">'.esc_html__('No customers found.', 'arm-repair-estimates').'</td></tr>';
        }
        echo '</tbody></table>';

        // Pagination
        if ($pages > 1) {
            echo '<p>';
            for ($i = 1; $i <= $pages; $i++) {
                $url = esc_url(\add_query_arg(['paged' => $i]));
                echo $i == $page ? "<strong>$i</strong> " : "<a href='$url'>$i</a> ";
            }
            echo '</p>';
        }
    }

    /** ===== Add/Edit form ===== */
    private static function render_form(int $id = 0): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';

        $row = null;
        if ($id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $id));
            if (!$row) {
                echo '<div class="notice notice-error"><p>'.esc_html__('Customer not found.', 'arm-repair-estimates').'</p></div>';
                return;
            }
        }

        $title = $id ? __('Edit Customer', 'arm-repair-estimates') : __('Add Customer', 'arm-repair-estimates');
        $nonce = \wp_create_nonce('arm_re_customer_save');

        echo '<h1>'.esc_html($title).'</h1>';
        echo '<form method="post" action="'.esc_url(\admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="arm_re_customer_save">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<input type="hidden" name="id" value="'.(int)($row->id ?? 0).'">';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>'.esc_html__('First Name', 'arm-repair-estimates').'</th><td><input type="text" name="first_name" value="'.esc_attr($row->first_name ?? '').'" class="regular-text" required></td></tr>';
        echo '<tr><th>'.esc_html__('Last Name', 'arm-repair-estimates').'</th><td><input type="text" name="last_name" value="'.esc_attr($row->last_name ?? '').'" class="regular-text" required></td></tr>';
        echo '<tr><th>'.esc_html__('Business Name', 'arm-repair-estimates').'</th><td><input type="text" name="business_name" value="'.esc_attr($row->business_name ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Tax ID', 'arm-repair-estimates').'</th><td><input type="text" name="tax_id" value="'.esc_attr($row->tax_id ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Email', 'arm-repair-estimates').'</th><td><input type="email" name="email" value="'.esc_attr($row->email ?? '').'" class="regular-text" required></td></tr>';
        echo '<tr><th>'.esc_html__('Phone', 'arm-repair-estimates').'</th><td><input type="text" name="phone" value="'.esc_attr($row->phone ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Address', 'arm-repair-estimates').'</th><td><input type="text" name="address" value="'.esc_attr($row->address ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('City', 'arm-repair-estimates').'</th><td><input type="text" name="city" value="'.esc_attr($row->city ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('State', 'arm-repair-estimates').'</th><td><input type="text" name="state" value="'.esc_attr($row->state ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Zip', 'arm-repair-estimates').'</th><td><input type="text" name="zip" value="'.esc_attr($row->zip ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Billing Address Line 1', 'arm-repair-estimates').'</th><td><input type="text" name="billing_address1" value="'.esc_attr($row->billing_address1 ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Billing Address Line 2', 'arm-repair-estimates').'</th><td><input type="text" name="billing_address2" value="'.esc_attr($row->billing_address2 ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Billing City', 'arm-repair-estimates').'</th><td><input type="text" name="billing_city" value="'.esc_attr($row->billing_city ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Billing State', 'arm-repair-estimates').'</th><td><input type="text" name="billing_state" value="'.esc_attr($row->billing_state ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Billing Zip', 'arm-repair-estimates').'</th><td><input type="text" name="billing_zip" value="'.esc_attr($row->billing_zip ?? '').'" class="regular-text"></td></tr>';
        echo '<tr><th>'.esc_html__('Tax Exempt', 'arm-repair-estimates').'</th><td><label><input type="checkbox" name="tax_exempt" value="1" '.(!empty($row->tax_exempt) ? 'checked' : '').'> '.esc_html__('Mark customer as tax exempt', 'arm-repair-estimates').'</label></td></tr>';
        echo '<tr><th>'.esc_html__('Notes', 'arm-repair-estimates').'</th><td><textarea name="notes" rows="4" class="large-text">'.esc_textarea($row->notes ?? '').'</textarea></td></tr>';
        echo '</table>';

        \submit_button($id ? __('Update Customer', 'arm-repair-estimates') : __('Save Customer', 'arm-repair-estimates'));
        echo '</form>';
    }

    /** ===== Import screen (CSV + CRM) ===== */
    private static function render_import(): void {
        if (!\current_user_can('manage_options')) return;

        echo '<h1>'.esc_html__('Import Customers', 'arm-repair-estimates').'</h1>';

        // CSV
        $csv_nonce = \wp_create_nonce('arm_re_customer_import_csv');
        echo '<h2>'.esc_html__('CSV Import', 'arm-repair-estimates').'</h2>';
        echo '<p>'.esc_html__('Upload a CSV with these headers: first_name,last_name,email,phone,address,city,zip', 'arm-repair-estimates').'</p>';
        echo '<form method="post" action="'.esc_url(\admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-bottom:20px;">';
        echo '<input type="hidden" name="action" value="arm_re_customer_import_csv">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($csv_nonce).'">';
        echo '<input type="file" name="csv" accept=".csv" required> ';
        echo '<label><input type="checkbox" name="update_existing" value="1" checked> '.esc_html__('Update existing by email', 'arm-repair-estimates').'</label> ';
        echo '<label><input type="checkbox" name="skip_blank_overwrite" value="1" checked> '.esc_html__('Do not overwrite existing non-empty fields with blank CSV values', 'arm-repair-estimates').'</label> ';
        \submit_button(__('Import CSV', 'arm-repair-estimates'), 'primary', '', false);
        echo '</form>';

        // CRM
        $crm_nonce = \wp_create_nonce('arm_re_customer_import_crm');
        echo '<h2>'.esc_html__('CRM Import', 'arm-repair-estimates').'</h2>';
        echo '<p>'.esc_html__('If your CRM integration is configured (e.g., Zoho), you can pull contacts into the customer list.', 'arm-repair-estimates').'</p>';
        echo '<form method="post" action="'.esc_url(\admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="arm_re_customer_import_crm">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($crm_nonce).'">';
        \submit_button(__('Import from CRM', 'arm-repair-estimates'));
        echo '</form>';
    }

    /** ===== Handlers ===== */

    public static function handle_save(): void {
        if (!\current_user_can('manage_options')) \wp_die('Nope');
        \check_admin_referer('arm_re_customer_save');

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';

        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'first_name'       => \sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name'        => \sanitize_text_field($_POST['last_name'] ?? ''),
            'business_name'    => \sanitize_text_field($_POST['business_name'] ?? ''),
            'tax_id'           => \sanitize_text_field($_POST['tax_id'] ?? ''),
            'email'            => \sanitize_email($_POST['email'] ?? ''),
            'phone'            => \sanitize_text_field($_POST['phone'] ?? ''),
            'address'          => \sanitize_text_field($_POST['address'] ?? ''),
            'city'             => \sanitize_text_field($_POST['city'] ?? ''),
            'state'            => \sanitize_text_field($_POST['state'] ?? ''),
            'zip'              => \sanitize_text_field($_POST['zip'] ?? ''),
            'billing_address1' => \sanitize_text_field($_POST['billing_address1'] ?? ''),
            'billing_address2' => \sanitize_text_field($_POST['billing_address2'] ?? ''),
            'billing_city'     => \sanitize_text_field($_POST['billing_city'] ?? ''),
            'billing_state'    => \sanitize_text_field($_POST['billing_state'] ?? ''),
            'billing_zip'      => \sanitize_text_field($_POST['billing_zip'] ?? ''),
            'tax_exempt'       => !empty($_POST['tax_exempt']) ? 1 : 0,
            'notes'            => \sanitize_textarea_field($_POST['notes'] ?? ''),
            'updated_at'       => \current_time('mysql'),
        ];

        if (!$data['email']) {
            self::redirect_with_error(__('Email is required.', 'arm-repair-estimates'), $id ? 'edit' : 'new', $id);
        }

        if ($id) {
            $wpdb->update($tbl, $data, ['id' => $id]);
        } else {
            $data['created_at'] = \current_time('mysql');
            // If email exists, update instead of duplicate
            $exists_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE email=%s", $data['email']));
            if ($exists_id) {
                $wpdb->update($tbl, $data, ['id' => $exists_id]);
                $id = $exists_id;
            } else {
                $wpdb->insert($tbl, $data);
                $id = (int) $wpdb->insert_id;
            }
        }

        \wp_redirect(\admin_url('admin.php?page=arm-repair-customers&action=edit&id='.$id.'&updated=1'));
        exit;
    }

    public static function handle_delete(): void {
        if (!\current_user_can('manage_options')) \wp_die('Nope');
        \check_admin_referer('arm_re_customer_delete');

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';
        $id  = (int)($_GET['id'] ?? 0);
        if ($id) {
            $wpdb->delete($tbl, ['id' => $id]);
        }
        \wp_redirect(\admin_url('admin.php?page=arm-repair-customers&deleted=1'));
        exit;
    }

    public static function handle_import_csv(): void {
        if (!\current_user_can('manage_options')) \wp_die('Nope');
        \check_admin_referer('arm_re_customer_import_csv');

        if (empty($_FILES['csv']['tmp_name'])) {
            self::redirect_with_error(__('No file uploaded.', 'arm-repair-estimates'), 'import');
        }

        $update_existing       = !empty($_POST['update_existing']);
        $skip_blank_overwrite  = !empty($_POST['skip_blank_overwrite']);

        $fh = \fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            self::redirect_with_error(__('Unable to open CSV.', 'arm-repair-estimates'), 'import');
        }

        $header = \fgetcsv($fh);
        if (!$header) {
            \fclose($fh);
            self::redirect_with_error(__('Empty CSV.', 'arm-repair-estimates'), 'import');
        }

        // Normalize headers
        $map = self::csv_header_map($header);
        $required = ['email'];
        foreach ($required as $need) {
            if (!isset($map[$need])) {
                \fclose($fh);
                self::redirect_with_error(sprintf(__('Missing required column: %s', 'arm-repair-estimates'), $need), 'import');
            }
        }

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';
        $inserted = 0; $updated = 0;

        while (($row = \fgetcsv($fh)) !== false) {
            $rec = [
                'first_name' => self::csv_val($row, $map, 'first_name'),
                'last_name'  => self::csv_val($row, $map, 'last_name'),
                'email'      => \sanitize_email(self::csv_val($row, $map, 'email')),
                'phone'      => self::csv_val($row, $map, 'phone'),
                'address'    => self::csv_val($row, $map, 'address'),
                'city'       => self::csv_val($row, $map, 'city'),
                'zip'        => self::csv_val($row, $map, 'zip'),
            ];
            if (!$rec['email']) continue;

            // Existing?
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE email=%s", $rec['email']), ARRAY_A);
            if ($existing) {
                if ($update_existing) {
                    $update = $rec;
                    if ($skip_blank_overwrite) {
                        foreach ($update as $k => $v) {
                            if ($v === '' || $v === null) unset($update[$k]);
                        }
                    }
                    if (!empty($update)) {
                        $update['updated_at'] = \current_time('mysql');
                        $wpdb->update($tbl, $update, ['id' => (int)$existing['id']]);
                        $updated++;
                    }
                }
            } else {
                $rec['created_at'] = \current_time('mysql');
                $rec['updated_at'] = \current_time('mysql');
                $wpdb->insert($tbl, $rec);
                $inserted++;
            }
        }
        \fclose($fh);

        \wp_redirect(\admin_url('admin.php?page=arm-repair-customers&action=import&imported=' . ($inserted + $updated)));
        exit;
    }

    public static function handle_import_crm(): void {
        if (!\current_user_can('manage_options')) \wp_die('Nope');
        \check_admin_referer('arm_re_customer_import_crm');

        $contacts = \apply_filters('arm_re_crm_fetch_customers', []);

        if (empty($contacts) && \class_exists('\ARM\Integrations\Zoho') && \method_exists('\ARM\Integrations\Zoho', 'fetch_contacts')) {
            try {
                $contacts = \ARM\Integrations\Zoho::fetch_contacts();
            } catch (\Throwable $e) {
                self::redirect_with_error(__('CRM fetch failed: ', 'arm-repair-estimates') . $e->getMessage(), 'import');
            }
        }

        if (empty($contacts) || !is_array($contacts)) {
            self::redirect_with_error(__('No contacts returned from CRM.', 'arm-repair-estimates'), 'import');
        }

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';
        $count = 0;

        foreach ($contacts as $c) {
            $rec = [
                'first_name' => \sanitize_text_field($c['first_name'] ?? ''),
                'last_name'  => \sanitize_text_field($c['last_name'] ?? ''),
                'email'      => \sanitize_email($c['email'] ?? ''),
                'phone'      => \sanitize_text_field($c['phone'] ?? ''),
                'address'    => \sanitize_text_field($c['address'] ?? ''),
                'city'       => \sanitize_text_field($c['city'] ?? ''),
                'zip'        => \sanitize_text_field($c['zip'] ?? ''),
            ];
            if (!$rec['email']) continue;

            $exists_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE email=%s", $rec['email']));
            $rec['updated_at'] = \current_time('mysql');

            if ($exists_id) {
                $wpdb->update($tbl, $rec, ['id' => $exists_id]);
            } else {
                $rec['created_at'] = \current_time('mysql');
                $wpdb->insert($tbl, $rec);
            }
            $count++;
        }

        \do_action('arm_re_customers_imported', $count, 'crm');

        \wp_redirect(\admin_url('admin.php?page=arm-repair-customers&action=import&imported=' . $count));
        exit;
    }

    /** ===== AJAX search for customers (admin use & estimate builder search) ===== */
    public static function ajax_search(): void {
        if (!\current_user_can('manage_options')) \wp_send_json_error();
        $term = isset($_GET['q']) ? \sanitize_text_field($_GET['q']) : '';
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';

        if ($term === '') \wp_send_json_success(['results' => []]);

        $like = '%' . $wpdb->esc_like($term) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, first_name, last_name, email, phone FROM $tbl
             WHERE first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s
             ORDER BY created_at DESC LIMIT 20",
             $like, $like, $like, $like
        ));

        $out = [];
        foreach ($rows as $r) {
            $label = trim("{$r->first_name} {$r->last_name}");
            $label = $label ?: $r->email;
            if ($r->phone) $label .= "  {$r->phone}";
            $out[] = [
                'id'    => (int)$r->id,
                'label' => $label,
                'email' => $r->email,
                'phone' => $r->phone,
            ];
        }
        \wp_send_json_success(['results' => $out]);
    }

    /** ===== Helpers ===== */

    private static function csv_header_map(array $header): array {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtolower(trim($h));
            // normalize common aliases
            $key = str_replace([' ', '-'], '_', $key);
            if (in_array($key, ['first_name','firstname'], true)) $key = 'first_name';
            if (in_array($key, ['last_name','lastname'], true))   $key = 'last_name';
            $map[$key] = $i;
        }
        return $map;
    }

    private static function csv_val(array $row, array $map, string $key): string {
        if (!isset($map[$key])) return '';
        $idx = (int) $map[$key];
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    }

    private static function redirect_with_error(string $msg, string $action = 'list', int $id = 0): void {
        $args = ['page' => 'arm-repair-customers', 'error' => rawurlencode($msg)];
        if ($action) $args['action'] = $action;
        if ($id)     $args['id'] = $id;
        \wp_redirect(\admin_url('admin.php?' . \http_build_query($args)));
        exit;
    }
}