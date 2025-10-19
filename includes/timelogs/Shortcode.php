<?php
namespace ARM\TimeLogs;

use WP_User;

if (!defined('ABSPATH')) exit;

final class Shortcode
{
    private const SHORTCODE = 'arm_technician_time';

    public static function boot(): void
    {
        add_action('init', [__CLASS__, 'register']);
    }

    public static function register(): void
    {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render']);
    }

    /**
     * @param array|string $atts
     * @param string|null  $content
     */
    public static function render($atts = [], $content = null): string
    {
        if (!is_user_logged_in()) {
            $login_url = wp_login_url();
            $message   = sprintf(
                /* translators: %s: login URL */
                __('Please <a href="%s">log in</a> to view your time tracking dashboard.', 'arm-repair-estimates'),
                esc_url($login_url)
            );

            return self::render_notice($message, 'info', true);
        }

        $user = wp_get_current_user();
        if (!$user instanceof WP_User || !Technician_Page::is_visible_to($user)) {
            return self::render_notice(__('You do not have permission to view this content.', 'arm-repair-estimates'), 'error');
        }

        return Technician_Page::render_portal($user, false);
    }

    private static function render_notice(string $message, string $type = 'info', bool $allow_html = false): string
    {
        $type   = in_array($type, ['error', 'success', 'info', 'warning'], true) ? $type : 'info';
        $notice = 'notice-' . $type;

        ob_start();
        ?>
        <div class="arm-tech-time arm-tech-time--shortcode">
            <div class="notice <?php echo esc_attr($notice); ?>">
                <p><?php echo $allow_html ? wp_kses_post($message) : esc_html($message); ?></p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
