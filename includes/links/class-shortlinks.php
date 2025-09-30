<?php
namespace ARM\Links;

if (!defined('ABSPATH')) exit;

/**
 * Lightweight shortlink service for estimates & invoices.
 *
 * Creates single-segment paths like /estABC12 and /inv8ZQ5K that redirect
 * to the secure tokenized URLs (?arm_estimate=... or ?arm_invoice=...).
 */
final class Shortlinks {

    /** Boot hooks */
    public static function boot(): void {
        add_action('init',                [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars',          [__CLASS__, 'register_query_var']);
        add_action('template_redirect',   [__CLASS__, 'maybe_redirect']);

        // Optional: admin-side helper endpoints to create short links on demand
        add_action('admin_post_arm_make_est_short', [__CLASS__, 'admin_make_est_short']);
        add_action('admin_post_arm_make_inv_short', [__CLASS__, 'admin_make_inv_short']);
    }

    /** DB table */
    public static function install_tables(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $tbl = $wpdb->prefix.'arm_shortlinks';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $tbl (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind ENUM('EST','INV') NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(32) NOT NULL,
            target_url TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY code (code),
            KEY kind_obj (kind, object_id)
        ) $charset;");
    }

    /** Rewrite rules: /estXXXXX and /invXXXXX -> arm_short_code */
    public static function add_rewrite_rules(): void {
        // capture entire code string into $matches[1]
        add_rewrite_rule('^((?:est|inv)[A-Za-z0-9]+)$', 'index.php?arm_short_code=$matches[1]', 'top');
        // Optional 2-segment fallback (/est/XXXXX):
        add_rewrite_rule('^(est|inv)/([A-Za-z0-9]+)$', 'index.php?arm_short_code=$matches[1]$matches[2]', 'top');
    }

    public static function register_query_var(array $vars): array {
        $vars[] = 'arm_short_code';
        return $vars;
    }

    /** Template redirect handler: lookup code and 302 -> target_url */
    public static function maybe_redirect(): void {
        $code = get_query_var('arm_short_code');
        if (!$code) return;

        $row = self::find_by_code($code);
        if ($row && !empty($row->target_url)) {
            wp_safe_redirect($row->target_url, 302);
            exit;
        }
        // Not found: 404
        status_header(404);
        wp_die(esc_html__('Link not found.', 'arm-repair-estimates'));
    }

    /** === Public API ===================================================== */

    /** Create or fetch a short link URL for an estimate ID */
    public static function get_or_create_for_estimate(int $estimate_id, string $token): string {
        $existing = self::find_by_kind_obj('EST', $estimate_id);
        if ($existing) return home_url('/'.$existing->code);

        // build target and create
        $target = add_query_arg(['arm_estimate' => $token], home_url('/'));
        $code   = self::unique_code('est');
        self::store('EST', $estimate_id, $code, $target);
        return home_url('/'.$code);
    }

    /** Create or fetch a short link URL for an invoice ID */
    public static function get_or_create_for_invoice(int $invoice_id, string $token): string {
        $existing = self::find_by_kind_obj('INV', $invoice_id);
        if ($existing) return home_url('/'.$existing->code);

        $target = add_query_arg(['arm_invoice' => $token], home_url('/'));
        $code   = self::unique_code('inv');
        self::store('INV', $invoice_id, $code, $target);
        return home_url('/'.$code);
    }

    /** === Admin helpers (optional buttons you can link to) =============== */

    public static function admin_make_est_short(): void {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_make_est_short');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) wp_die('Missing id');

        global $wpdb;
        $tblE = $wpdb->prefix.'arm_estimates';
        $est  = $wpdb->get_row($wpdb->prepare("SELECT id, token FROM $tblE WHERE id=%d", $id));
        if (!$est) wp_die('Estimate not found');
        $short = self::get_or_create_for_estimate((int)$est->id, (string)$est->token);

        wp_safe_redirect(add_query_arg(['short_created'=>1,'short'=>$short], admin_url('admin.php?page=arm-repair-estimates-builder&action=edit&id='.$id)));
        exit;
    }

    public static function admin_make_inv_short(): void {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer('arm_make_inv_short');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) wp_die('Missing id');

        global $wpdb;
        $tblI = $wpdb->prefix.'arm_invoices';
        $inv  = $wpdb->get_row($wpdb->prepare("SELECT id, token FROM $tblI WHERE id=%d", $id));
        if (!$inv) wp_die('Invoice not found');
        $short = self::get_or_create_for_invoice((int)$inv->id, (string)$inv->token);

        wp_safe_redirect(add_query_arg(['short_created'=>1,'short'=>$short], admin_url('admin.php?page=arm-repair-invoices')));
        exit;
    }

    /** === Internals ====================================================== */

    private static function find_by_code(string $code) {
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_shortlinks';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE code=%s", $code));
    }

    private static function find_by_kind_obj(string $kind, int $object_id) {
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_shortlinks';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE kind=%s AND object_id=%d", $kind, $object_id));
    }

    private static function store(string $kind, int $object_id, string $code, string $target_url): void {
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_shortlinks';
        $wpdb->insert($tbl, [
            'kind'       => $kind,
            'object_id'  => $object_id,
            'code'       => $code,
            'target_url' => $target_url,
            'created_at' => current_time('mysql'),
        ]);
    }

    /** Generate a unique code with a prefix (est|inv) + 5 base36 chars */
    private static function unique_code(string $prefix): string {
        $prefix = (in_array($prefix, ['est','inv'], true) ? $prefix : 'est');
        do {
            $rand = strtoupper(self::base36(random_int(1679616, 60466175))); // 5 chars base36
            $code = $prefix . $rand;
        } while (self::find_by_code($code));
        return $code;
    }

    /** base36 helper */
    private static function base36(int $n): string {
        return base_convert((string)$n, 10, 36);
    }
}
