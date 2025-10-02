<?php
namespace ARM\Public;

if (!defined('ABSPATH')) exit;

use WP_User;

final class Customer_Dashboard {
    private const AJAX_ACTION = 'arm_vehicle_crud';
    private const NONCE       = 'arm_customer_nonce';

    /**
     * Register shortcode, assets, and AJAX handlers.
     */
    public static function boot(): void {
        add_shortcode('arm_customer_dashboard', [__CLASS__, 'render_dashboard']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_vehicle_crud']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [__CLASS__, 'ajax_vehicle_crud']);
    }

    /**
     * Enqueue CSS/JS only when the dashboard shortcode is present.
     */
    public static function enqueue_assets(): void {
        if (!self::is_dashboard_request()) {
            return;
        }

        wp_enqueue_style(
            'arm-customer-dashboard',
            \ARM_RE_URL . 'assets/css/arm-customer-dashboard.css',
            [],
            \ARM_RE_VERSION
        );

        wp_enqueue_script(
            'arm-customer-dashboard',
            \ARM_RE_URL . 'assets/js/arm-customer-dashboard.js',
            ['jquery'],
            \ARM_RE_VERSION,
            true
        );

        $context        = self::resolve_customer_context();
        $vehicleColumns = self::get_vehicle_columns();
        $vehicleFields  = array_keys(self::get_available_vehicle_fields($vehicleColumns));

        wp_localize_script('arm-customer-dashboard', 'ARM_CUSTOMER', [
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE),
            'vehicle_fields' => $vehicleFields,
            'impersonating'  => (bool) $context['impersonating'],
            'i18n'           => [
                'deleteConfirm' => __('Delete this vehicle?', 'arm-repair-estimates'),
                'genericError'  => __('Something went wrong. Please try again.', 'arm-repair-estimates'),
                'required'      => __('Please complete the required fields.', 'arm-repair-estimates'),
            ],
        ]);
    }

    /**
     * Shortcode callback for the customer dashboard.
     */
    public static function render_dashboard(): string {
        if (!self::is_dashboard_request()) {
            return '';
        }

        $context = self::resolve_customer_context();
        $customer = $context['customer'];

        if (!$customer) {
            if ($context['impersonating']) {
                return '<p>' . esc_html__("The impersonation session has expired. Please refresh and try again.", 'arm-repair-estimates') . '</p>';
            }

            if (!$context['user']) {
                return '<p>' . esc_html__('Please log in to access your dashboard.', 'arm-repair-estimates') . '</p>';
            }

            return '<p>' . esc_html__('We could not find a customer record linked to your account.', 'arm-repair-estimates') . '</p>';
        }

        $customerId     = (int) $customer->id;
        $vehicleColumns = self::get_vehicle_columns();
        $vehicleFields  = self::get_available_vehicle_fields($vehicleColumns);

        $vehicles  = self::fetch_customer_vehicles($customerId, $vehicleColumns, $vehicleFields);
        $estimates = self::fetch_customer_estimates($customerId);
        $invoices  = self::fetch_customer_invoices($customerId);

        $globalEmpty = empty($vehicles) && empty($estimates) && empty($invoices);

        $displayName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        if ($displayName === '' && $context['user'] instanceof WP_User) {
            $displayName = $context['user']->display_name ?: $context['user']->user_login;
        }

        $templateContext = [
            'user'                     => $context['user'],
            'customer'                 => $customer,
            'customer_display_name'    => $displayName,
            'vehicles'                 => $vehicles,
            'vehicle_fields'           => $vehicleFields,
            'vehicles_table_available' => (bool) self::get_vehicle_table(),
            'estimates'                => $estimates,
            'invoices'                 => $invoices,
            'global_empty'             => $globalEmpty,
            'impersonating'            => (bool) $context['impersonating'],
        ];

        return self::render_template('dashboard.php', $templateContext);
    }

    /**
     * AJAX handler for vehicle CRUD.
     */
    public static function ajax_vehicle_crud(): void {
        check_ajax_referer(self::NONCE, 'nonce');

        $context = self::resolve_customer_context();
        $customer = $context['customer'];
        if (!$customer) {
            wp_send_json_error(['message' => __('Your session has expired. Please refresh and try again.', 'arm-repair-estimates')]);
        }

        $table = self::get_vehicle_table();
        if (!$table) {
            wp_send_json_error(['message' => __('Vehicle storage is not available.', 'arm-repair-estimates')]);
        }

        $columns = self::get_vehicle_columns();
        if (!in_array('customer_id', $columns, true)) {
            wp_send_json_error(['message' => __('Vehicles cannot be updated because the table is missing the customer reference.', 'arm-repair-estimates')]);
        }

        $fieldsConfig = self::get_available_vehicle_fields($columns);
        $actionType   = sanitize_key($_POST['action_type'] ?? '');
        $customerId   = (int) $customer->id;
        global $wpdb;

        if ($actionType === 'add' || $actionType === 'edit') {
            $payload = self::map_vehicle_payload($_POST, $fieldsConfig);
            if ($payload['errors']) {
                wp_send_json_error(['message' => implode(' ', $payload['errors'])]);
            }

            if (!$payload['data']) {
                wp_send_json_error(['message' => __('Nothing to save.', 'arm-repair-estimates')]);
            }

            $payload['data']['customer_id'] = $customerId;
            $payload['format'][] = '%d';

            if (in_array('updated_at', $columns, true)) {
                $payload['data']['updated_at'] = current_time('mysql');
                $payload['format'][] = '%s';
            }

            if ($actionType === 'add') {
                if (in_array('created_at', $columns, true) && !isset($payload['data']['created_at'])) {
                    $payload['data']['created_at'] = current_time('mysql');
                    $payload['format'][] = '%s';
                }

                $inserted = $wpdb->insert($table, $payload['data'], $payload['format']);
                if (!$inserted) {
                    wp_send_json_error(['message' => __('Unable to save the vehicle.', 'arm-repair-estimates')]);
                }
                wp_send_json_success(['message' => __('Vehicle saved.', 'arm-repair-estimates')]);
            }

            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                wp_send_json_error(['message' => __('Missing vehicle identifier.', 'arm-repair-estimates')]);
            }

            $owned = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE id = %d AND customer_id = %d",
                $id,
                $customerId
            ));

            if ($owned <= 0) {
                wp_send_json_error(['message' => __('Vehicle not found.', 'arm-repair-estimates')]);
            }

            $updated = $wpdb->update($table, $payload['data'], ['id' => $id], $payload['format'], ['%d']);
            if ($updated === false) {
                wp_send_json_error(['message' => __('Unable to update the vehicle.', 'arm-repair-estimates')]);
            }

            wp_send_json_success(['message' => __('Vehicle updated.', 'arm-repair-estimates')]);
        }

        if ($actionType === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                wp_send_json_error(['message' => __('Missing vehicle identifier.', 'arm-repair-estimates')]);
            }

            $owned = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE id = %d AND customer_id = %d",
                $id,
                $customerId
            ));

            if ($owned <= 0) {
                wp_send_json_error(['message' => __('Vehicle not found.', 'arm-repair-estimates')]);
            }

            if (in_array('deleted_at', $columns, true)) {
                $result = $wpdb->update(
                    $table,
                    ['deleted_at' => current_time('mysql')],
                    ['id' => $id],
                    ['%s'],
                    ['%d']
                );
            } else {
                $result = $wpdb->delete($table, ['id' => $id], ['%d']);
            }

            if ($result === false) {
                wp_send_json_error(['message' => __('Unable to delete the vehicle.', 'arm-repair-estimates')]);
            }

            wp_send_json_success(['message' => __('Vehicle removed.', 'arm-repair-estimates')]);
        }

        wp_send_json_error(['message' => __('Invalid request.', 'arm-repair-estimates')]);
    }

    /**
     * Map POST payload into sanitized data + formats.
     */
    private static function map_vehicle_payload(array $source, array $fieldsConfig): array {
        $data   = [];
        $format = [];
        $errors = [];

        foreach ($fieldsConfig as $key => $config) {
            $raw = $source[$key] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;

            $clean = call_user_func($config['sanitize'], $raw);

            if (($clean === null || $clean === '') && !empty($config['required'])) {
                $errors[] = sprintf(__('%s is required.', 'arm-repair-estimates'), $config['label']);
                continue;
            }

            if ($clean === null && empty($config['allow_null'])) {
                continue;
            }

            $data[$key] = $clean;
            $format[]   = $config['format'];
        }

        return compact('data', 'format', 'errors');
    }

    /**
     * Resolve current customer context (logged-in or impersonated).
     */
    private static function resolve_customer_context(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $context = [
            'user'          => null,
            'customer'      => null,
            'impersonating' => false,
        ];

        $impersonatedId = self::detect_impersonated_customer_id();
        if ($impersonatedId) {
            $customer = self::get_customer_by_id($impersonatedId);
            if ($customer) {
                $context['customer']      = $customer;
                $context['impersonating'] = true;
                return $cached = $context;
            }
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user instanceof WP_User) {
                $context['user'] = $user;
                $customer        = self::get_customer_for_user($user);
                if ($customer) {
                    $context['customer'] = $customer;
                    return $cached = $context;
                }
            }
        }

        /**
         * Allow external code to supply a customer context.
         */
        $filtered = apply_filters('arm_customer_dashboard_context', $context);
        if (is_array($filtered)) {
            $context = array_merge($context, $filtered);
        }

        return $cached = $context;
    }

    /**
     * Determine if the request is for the dashboard shortcode.
     */
    private static function is_dashboard_request(): bool {
        if (!is_singular()) {
            return false;
        }

        global $post;
        if (!$post) {
            return false;
        }

        return has_shortcode($post->post_content ?? '', 'arm_customer_dashboard');
    }

    /**
     * Fetch customer record by ID.
     */
    private static function get_customer_by_id(int $id): ?object {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'arm_customers';
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        return $row ?: null;
    }

    /**
     * Locate customer by WordPress user.
     */
    private static function get_customer_for_user(WP_User $user): ?object {
        global $wpdb;
        $table   = $wpdb->prefix . 'arm_customers';
        $columns = self::get_customer_columns();

        if (in_array('user_id', $columns, true)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", (int) $user->ID));
            if ($row) {
                return $row;
            }
        }

        if (in_array('email', $columns, true)) {
            $email = (string) $user->user_email;
            if ($email !== '') {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email = %s", $email));
                if ($row) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Determine if a customer impersonation session exists.
     */
    private static function detect_impersonated_customer_id(): ?int {
        $candidate = apply_filters('arm_customer_dashboard_impersonated_id', null);
        if (is_numeric($candidate) && (int) $candidate > 0) {
            return (int) $candidate;
        }

        $sources = [
            ['type' => 'cookie', 'key' => 'arm_customer_impersonation'],
            ['type' => 'cookie', 'key' => 'arm_customer_session'],
            ['type' => 'cookie', 'key' => 'arm_impersonated_customer_id'],
            ['type' => 'request', 'key' => 'arm_customer_impersonation'],
            ['type' => 'session', 'key' => 'arm_customer_impersonation'],
        ];

        foreach ($sources as $source) {
            $value = null;
            switch ($source['type']) {
                case 'cookie':
                    $value = $_COOKIE[$source['key']] ?? null;
                    break;
                case 'request':
                    $value = $_REQUEST[$source['key']] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    break;
                case 'session':
                    $value = isset($_SESSION) ? ($_SESSION[$source['key']] ?? null) : null; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.session_vars_session
                    break;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }

            $token = preg_replace('/[^A-Za-z0-9:_-]/', '', (string) $value);
            if ($token === '') {
                continue;
            }

            $resolved = self::lookup_customer_id_by_token($token);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Attempt to resolve a customer id from known impersonation session tables.
     */
    private static function lookup_customer_id_by_token(string $token): ?int {
        global $wpdb;
        $candidates = [
            $wpdb->prefix . 'arm_customer_sessions'       => 'token',
            $wpdb->prefix . 'arm_customer_portal_sessions'=> 'token',
            $wpdb->prefix . 'arm_customer_impersonations' => 'token',
        ];

        foreach ($candidates as $table => $column) {
            if (!self::table_exists($table)) {
                continue;
            }

            $columns = self::get_table_columns($table);
            $sql     = "SELECT customer_id FROM $table WHERE $column = %s";
            $params  = [$token];

            if (in_array('expires_at', $columns, true)) {
                $sql    .= " AND (expires_at IS NULL OR expires_at > %s)";
                $params[] = current_time('mysql');
            }

            $customerId = (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
            if ($customerId > 0) {
                return $customerId;
            }
        }

        return null;
    }

    /**
     * Fetch dashboard vehicles as associative arrays.
     */
    private static function fetch_customer_vehicles(int $customerId, array $columns, array $fields): array {
        $table = self::get_vehicle_table();
        if (!$table || !in_array('customer_id', $columns, true)) {
            return [];
        }

        global $wpdb;
        $selectable = array_unique(array_merge(['id'], array_keys($fields)));
        $selectable = array_values(array_filter($selectable, static fn($c) => in_array($c, $columns, true)));
        if (!$selectable) {
            $selectable = ['id'];
        }

        $selectSql = implode(', ', array_map([__CLASS__, 'wrap_identifier'], $selectable));
        $sql       = "SELECT $selectSql FROM $table WHERE customer_id = %d";
        $args      = [$customerId];

        if (in_array('deleted_at', $columns, true)) {
            $sql .= " AND (deleted_at IS NULL OR deleted_at = '' OR deleted_at = '0000-00-00 00:00:00')";
        }

        $order = in_array('created_at', $columns, true) ? 'created_at DESC, id DESC' : 'id DESC';
        $sql  .= " ORDER BY $order";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        if (!$rows) {
            return [];
        }

        return array_map(static function (array $row): array {
            return array_map(static function ($value) {
                return is_string($value) ? wp_unslash($value) : $value;
            }, $row);
        }, $rows);
    }

    /**
     * Fetch estimates for the customer.
     */
    private static function fetch_customer_estimates(int $customerId): array {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_estimates';
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, estimate_no, status, total, created_at, token FROM $table WHERE customer_id = %d ORDER BY created_at DESC",
            $customerId
        ));

        if (!$rows) {
            return [];
        }

        return array_map(static function ($row): array {
            $link = $row->token ? add_query_arg(['arm_estimate' => $row->token], home_url('/')) : '';
            return [
                'id'          => (int) $row->id,
                'estimate_no' => (string) ($row->estimate_no ?? ''),
                'status'      => (string) ($row->status ?? ''),
                'total'       => (float) ($row->total ?? 0),
                'created_at'  => (string) ($row->created_at ?? ''),
                'link'        => $link,
            ];
        }, $rows);
    }

    /**
     * Fetch invoices for the customer.
     */
    private static function fetch_customer_invoices(int $customerId): array {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_invoices';
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, invoice_no, status, total, created_at, token FROM $table WHERE customer_id = %d ORDER BY created_at DESC",
            $customerId
        ));

        if (!$rows) {
            return [];
        }

        return array_map(static function ($row): array {
            $link = $row->token ? add_query_arg(['arm_invoice' => $row->token], home_url('/')) : '';
            return [
                'id'          => (int) $row->id,
                'invoice_no'  => (string) ($row->invoice_no ?? ''),
                'status'      => (string) ($row->status ?? ''),
                'total'       => (float) ($row->total ?? 0),
                'created_at'  => (string) ($row->created_at ?? ''),
                'link'        => $link,
            ];
        }, $rows);
    }

    /**
     * Locate template path with theme override support.
     */
    private static function render_template(string $template, array $context): string {
        $paths = [
            'arm/customer/' . $template,
            'arm/' . $template,
            $template,
        ];

        $located = locate_template($paths);
        if (!$located) {
            $located = \ARM_RE_PATH . 'templates/customer/' . $template;
        }

        if (!file_exists($located)) {
            return '';
        }

        ob_start();
        extract($context, EXTR_SKIP);
        include $located;
        return (string) ob_get_clean();
    }

    /**
     * Determine vehicles table name (if present).
     */
    private static function get_vehicle_table(): ?string {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'arm_vehicles';
        if (self::table_exists($table)) {
            return $cached = $table;
        }

        return $cached = null;
    }

    /**
     * Retrieve vehicle table columns.
     */
    private static function get_vehicle_columns(): array {
        $table = self::get_vehicle_table();
        if (!$table) {
            return [];
        }
        return self::get_table_columns($table);
    }

    /**
     * Retrieve customer table columns.
     */
    private static function get_customer_columns(): array {
        global $wpdb;
        return self::get_table_columns($wpdb->prefix . 'arm_customers');
    }

    /**
     * Cached lookup of table columns.
     */
    private static function get_table_columns(string $table): array {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ));

        $cache[$table] = array_map('strtolower', $rows ?: []);
        return $cache[$table];
    }

    /**
     * Check if a table exists.
     */
    private static function table_exists(string $table): bool {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        )) > 0;

        return $cache[$table] = $exists;
    }

    /**
     * Vehicle field definitions keyed by column.
     */
    private static function vehicle_field_definitions(): array {
        return [
            'year' => [
                'label'      => __('Year', 'arm-repair-estimates'),
                'type'       => 'number',
                'required'   => true,
                'allow_null' => false,
                'format'     => '%d',
                'sanitize'   => static function ($value) {
                    if ($value === null || $value === '') {
                        return null;
                    }
                    return (int) $value;
                },
            ],
            'make' => [
                'label'      => __('Make', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => true,
                'allow_null' => false,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'model' => [
                'label'      => __('Model', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => true,
                'allow_null' => false,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'engine' => [
                'label'      => __('Engine', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'drive' => [
                'label'      => __('Drive', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'trim' => [
                'label'      => __('Trim', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'vin' => [
                'label'      => __('VIN', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'plate' => [
                'label'      => __('Plate', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'color' => [
                'label'      => __('Color', 'arm-repair-estimates'),
                'type'       => 'text',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_text_field($value);
                },
            ],
            'mileage' => [
                'label'      => __('Mileage', 'arm-repair-estimates'),
                'type'       => 'number',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%d',
                'sanitize'   => static function ($value) {
                    if ($value === null || $value === '') {
                        return null;
                    }
                    return (int) $value;
                },
            ],
            'notes' => [
                'label'      => __('Notes', 'arm-repair-estimates'),
                'type'       => 'textarea',
                'required'   => false,
                'allow_null' => true,
                'format'     => '%s',
                'sanitize'   => static function ($value) {
                    return $value === null ? null : sanitize_textarea_field($value);
                },
            ],
        ];
    }

    /**
     * Filter vehicle fields by available columns.
     */
    private static function get_available_vehicle_fields(array $columns): array {
        if (!$columns) {
            return [];
        }

        $definitions = self::vehicle_field_definitions();
        $filtered    = [];

        foreach ($definitions as $key => $config) {
            if (in_array($key, $columns, true)) {
                $filtered[$key] = $config;
            }
        }

        return $filtered;
    }

    /**
     * Wrap identifier with backticks.
     */
    private static function wrap_identifier(string $column): string {
        return '`' . str_replace('`', '``', $column) . '`';
    }
}
