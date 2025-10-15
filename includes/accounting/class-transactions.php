<?php
namespace ARM\Accounting;

use wpdb;

if (!defined('ABSPATH')) exit;

class Transactions
{
    private const TYPES = [
        'income'   => 'arm_income',
        'expense'  => 'arm_expenses',
        'purchase' => 'arm_purchases',
    ];

    public static function capability(): string
    {
        return apply_filters('arm/accounting/capability', 'manage_options');
    }

    public static function install_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta(self::build_table_sql($wpdb, 'income', $charset));
        dbDelta(self::build_table_sql($wpdb, 'expense', $charset));
        dbDelta(self::build_table_sql($wpdb, 'purchase', $charset));
    }

    private static function build_table_sql(wpdb $wpdb, string $type, string $charset): string
    {
        $table = self::table_name($wpdb, $type);
        $extra = '';

        if ($type === 'expense') {
            $extra = "\n          vendor_name VARCHAR(191) NULL,";
        } elseif ($type === 'purchase') {
            $extra = "\n          vendor_name VARCHAR(191) NULL,\n          purchase_order VARCHAR(191) NULL,";
        }

        return "CREATE TABLE $table (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          transaction_date DATE NOT NULL,
          category VARCHAR(120) NOT NULL DEFAULT '',
          amount DECIMAL(12,2) NOT NULL DEFAULT 0,
          reference VARCHAR(191) NULL,
          description TEXT NULL,$extra
          created_by BIGINT UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL,
          PRIMARY KEY  (id),
          KEY idx_date (transaction_date),
          KEY idx_category (category)
        ) $charset;";
    }

    public static function save(string $type, array $data)
    {
        global $wpdb;

        $type = self::validate_type($type);
        $table = self::table_name($wpdb, $type);
        $payload = self::sanitize_payload($type, $data);
        $id = isset($data['id']) ? (int) $data['id'] : 0;

        if ($id > 0) {
            $payload['updated_at'] = current_time('mysql');
            $updated = $wpdb->update($table, $payload, ['id' => $id]);
            return $updated !== false ? $id : false;
        }

        $payload['created_at'] = current_time('mysql');
        $payload['created_by'] = get_current_user_id();
        $inserted = $wpdb->insert($table, $payload);
        return $inserted !== false ? (int) $wpdb->insert_id : false;
    }

    public static function delete(string $type, int $id): bool
    {
        global $wpdb;

        $type = self::validate_type($type);
        $table = self::table_name($wpdb, $type);
        return (bool) $wpdb->delete($table, ['id' => $id]);
    }

    public static function get(string $type, int $id)
    {
        global $wpdb;

        $type = self::validate_type($type);
        $table = self::table_name($wpdb, $type);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    public static function query(string $type, array $args = []): array
    {
        global $wpdb;

        $type = self::validate_type($type);
        $table = self::table_name($wpdb, $type);

        $where = [];
        $params = [];

        if (!empty($args['from'])) {
            $where[] = 'transaction_date >= %s';
            $params[] = $args['from'];
        }
        if (!empty($args['to'])) {
            $where[] = 'transaction_date <= %s';
            $params[] = $args['to'];
        }
        if (!empty($args['category'])) {
            $where[] = 'category = %s';
            $params[] = $args['category'];
        }

        $sql = "SELECT * FROM $table";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY transaction_date DESC, id DESC';

        if (!empty($args['number'])) {
            $sql .= $wpdb->prepare(' LIMIT %d', (int) $args['number']);
        }

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function totals(string $type, array $args = []): float
    {
        global $wpdb;

        $type = self::validate_type($type);
        $table = self::table_name($wpdb, $type);

        $where = [];
        $params = [];

        if (!empty($args['from'])) {
            $where[] = 'transaction_date >= %s';
            $params[] = $args['from'];
        }
        if (!empty($args['to'])) {
            $where[] = 'transaction_date <= %s';
            $params[] = $args['to'];
        }

        $sql = "SELECT SUM(amount) FROM $table";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $total = $wpdb->get_var($sql);
        return $total ? (float) $total : 0.0;
    }

    public static function monthly_summary(string $type, array $args = []): array
    {
        global $wpdb;

        $type = self::validate_type($type);
        $table = self::table_name($wpdb, $type);

        $where = [];
        $params = [];

        if (!empty($args['from'])) {
            $where[] = 'transaction_date >= %s';
            $params[] = $args['from'];
        }
        if (!empty($args['to'])) {
            $where[] = 'transaction_date <= %s';
            $params[] = $args['to'];
        }

        $sql = "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS period, SUM(amount) AS total
                FROM $table";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY period ORDER BY period DESC';

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    private static function validate_type(string $type): string
    {
        $type = strtolower($type);
        if (!array_key_exists($type, self::TYPES)) {
            wp_die(esc_html__('Unknown transaction type.', 'arm-repair-estimates'));
        }
        return $type;
    }

    private static function table_name(wpdb $wpdb, string $type): string
    {
        return $wpdb->prefix . self::TYPES[$type];
    }

    private static function sanitize_payload(string $type, array $data): array
    {
        $payload = [
            'transaction_date' => !empty($data['transaction_date'])
                ? sanitize_text_field($data['transaction_date'])
                : wp_date('Y-m-d', current_time('timestamp')),
            'category'    => sanitize_text_field($data['category'] ?? ''),
            'amount'      => isset($data['amount']) ? round((float) $data['amount'], 2) : 0,
            'reference'   => sanitize_text_field($data['reference'] ?? ''),
            'description' => wp_kses_post($data['description'] ?? ''),
        ];

        if ($type === 'expense') {
            $payload['vendor_name'] = sanitize_text_field($data['vendor_name'] ?? '');
        }
        if ($type === 'purchase') {
            $payload['vendor_name'] = sanitize_text_field($data['vendor_name'] ?? '');
            $payload['purchase_order'] = sanitize_text_field($data['purchase_order'] ?? '');
        }

        return $payload;
    }
}
