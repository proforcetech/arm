<?php
namespace ARM\Utils;

if (!defined('ABSPATH')) exit;

class Impersonation {
    private const META_KEY    = '_arm_re_impersonate_customer';
    private const SESSION_KEY = 'arm_re_impersonate_customer';

    public static function boot(): void {
        \add_action('init', [__CLASS__, 'ensure_session'], 1);
        \add_action('init', [__CLASS__, 'sync_session_from_meta'], 5);
        \add_action('admin_notices', [__CLASS__, 'render_admin_notice']);
        \add_action('network_admin_notices', [__CLASS__, 'render_admin_notice']);
        \add_action('wp_footer', [__CLASS__, 'render_front_notice']);
    }

    public static function ensure_session(): void {
        if (php_sapi_name() === 'cli') return;
        if (headers_sent()) return;
        if (session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
    }

    public static function sync_session_from_meta(): void {
        if (!\is_user_logged_in()) {
            self::clear_session();
            return;
        }

        $user_id = (int) \get_current_user_id();
        $stored  = (int) \get_user_meta($user_id, self::META_KEY, true);
        if ($stored > 0) {
            $_SESSION[self::SESSION_KEY] = $stored;
        } elseif (isset($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    public static function start(int $customer_id): void {
        if ($customer_id <= 0 || !\is_user_logged_in()) return;
        self::ensure_session();
        $_SESSION[self::SESSION_KEY] = $customer_id;
        \update_user_meta(\get_current_user_id(), self::META_KEY, $customer_id);

        if (\class_exists('\\ARM\\Audit\\Logger')) {
            \ARM\Audit\Logger::log_impersonation(\get_current_user_id(), $customer_id, 'start', self::get_ip_address());
        }
    }

    public static function stop(): void {
        if (!\is_user_logged_in()) return;
        $customer_id = self::get_impersonated_customer_id();
        self::clear_session();
        \delete_user_meta(\get_current_user_id(), self::META_KEY);

        if ($customer_id > 0 && \class_exists('\\ARM\\Audit\\Logger')) {
            \ARM\Audit\Logger::log_impersonation(\get_current_user_id(), $customer_id, 'stop', self::get_ip_address());
        }
    }

    public static function get_impersonated_customer_id(): int {
        if (!empty($_SESSION[self::SESSION_KEY])) {
            return (int) $_SESSION[self::SESSION_KEY];
        }
        if (!\is_user_logged_in()) return 0;
        return (int) \get_user_meta(\get_current_user_id(), self::META_KEY, true);
    }

    public static function is_impersonating(): bool {
        return self::get_impersonated_customer_id() > 0;
    }

    public static function stop_url(): string {
        return \wp_nonce_url(\admin_url('admin-post.php?action=arm_re_customer_impersonate_stop'), 'arm_re_customer_impersonate_stop');
    }

    public static function get_customer(int $customer_id): ?\stdClass {
        if ($customer_id <= 0) return null;
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $customer_id));
    }

    public static function render_admin_notice(): void {
        if (!self::is_impersonating()) return;
        if (!\current_user_can('manage_options')) return;
        $customer_id = self::get_impersonated_customer_id();
        $customer    = self::get_customer($customer_id);
        $name        = self::format_customer_label($customer_id, $customer);
        $stop_url    = esc_url(self::stop_url());
        echo '<div class="notice notice-warning"><p>'
            . esc_html(sprintf(__('You are impersonating %s.', 'arm-repair-estimates'), $name))
            . ' <a href="'.$stop_url.'" class="button button-small">'
            . esc_html__('Stop impersonating', 'arm-repair-estimates')
            . '</a></p></div>';
    }

    public static function render_front_notice(): void {
        if (!self::is_impersonating()) return;
        if (\is_admin()) return;
        if (!\current_user_can('manage_options')) return;
        $customer_id = self::get_impersonated_customer_id();
        $customer    = self::get_customer($customer_id);
        $name        = esc_html(self::format_customer_label($customer_id, $customer));
        $stop_url    = esc_url(self::stop_url());
        echo '<div class="arm-impersonation-banner" style="position:fixed;bottom:20px;right:20px;z-index:9999;background:#c13515;color:#fff;padding:16px 24px;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.2);">'
            . '<strong>'.esc_html__('Impersonation active', 'arm-repair-estimates').'</strong><br>'
            . sprintf(esc_html__('Viewing as %s.', 'arm-repair-estimates'), $name)
            . ' <a href="'.$stop_url.'" style="color:#fff;font-weight:bold;text-decoration:underline;">'
            . esc_html__('Stop impersonating', 'arm-repair-estimates')
            . '</a></div>';
    }

    private static function clear_session(): void {
        if (isset($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    private static function format_customer_label(int $customer_id, ?\stdClass $customer): string {
        if ($customer) {
            $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
            if ($name === '') $name = $customer->email ?? '';
            if ($name !== '') return $name;
        }
        return sprintf(__('Customer #%d', 'arm-repair-estimates'), $customer_id);
    }

    private static function get_ip_address(): string {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                return sanitize_text_field($ip);
            }
        }
        return '';
    }
}
