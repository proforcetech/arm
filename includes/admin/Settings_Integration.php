<?php

namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

/**
 * Integrations: Make (Integromat) webhooks.
 * Why: allow admin to paste webhook URLs for Calendar/Email/SMS automations.
 */
final class Settings_Integrations {
    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function menu(): void {
        add_submenu_page(
            'arm-repair-estimates',
            __('Integrations','arm-repair-estimates'),
            __('Integrations','arm-repair-estimates'),
            'manage_options',
            'arm-integrations',
            [__CLASS__, 'render']
        );
    }

    private static function opt_keys(): array {
        
        $class = 'ARM\\Integrations\\Make_Webhooks';
        if (class_exists($class)) {
            return [
                'cal'   => constant($class . '::OPT_CAL_HOOK'),
                'email' => constant($class . '::OPT_EMAIL_HOOK'),
                'sms'   => constant($class . '::OPT_SMS_HOOK'),
            ];
        }
        return [
            'cal'   => 'arm_make_calendar_webhook',
            'email' => 'arm_make_email_webhook',
            'sms'   => 'arm_make_sms_webhook',
        ];
    }

    public static function register(): void {
        $k = self::opt_keys();
        register_setting('arm_re_make', $k['cal'], [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ]);
        register_setting('arm_re_make', $k['email'], [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ]);
        register_setting('arm_re_make', $k['sms'], [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ]);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.','arm-repair-estimates'));
        }
        $k = self::opt_keys();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Integrations — Make (Integromat)', 'arm-repair-estimates'); ?></h1>
          <form method="post" action="options.php">
            <?php settings_fields('arm_re_make'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="arm_make_calendar_webhook"><?php echo esc_html__('Calendar Webhook URL','arm-repair-estimates'); ?></label></th>
                <td>
                  <input type="url" class="regular-text" id="arm_make_calendar_webhook" name="<?php echo esc_attr($k['cal']); ?>" value="<?php echo esc_attr(get_option($k['cal'], '')); ?>">
                  <p class="description"><?php echo esc_html__('Make scenario that creates Google Calendar events from appointments.','arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="arm_make_email_webhook"><?php echo esc_html__('Email Webhook URL','arm-repair-estimates'); ?></label></th>
                <td>
                  <input type="url" class="regular-text" id="arm_make_email_webhook" name="<?php echo esc_attr($k['email']); ?>" value="<?php echo esc_attr(get_option($k['email'], '')); ?>">
                  <p class="description"><?php echo esc_html__('Make scenario that sends transactional emails.','arm-repair-estimates'); ?></p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="arm_make_sms_webhook"><?php echo esc_html__('SMS Webhook URL','arm-repair-estimates'); ?></label></th>
                <td>
                  <input type="url" class="regular-text" id="arm_make_sms_webhook" name="<?php echo esc_attr($k['sms']); ?>" value="<?php echo esc_attr(get_option($k['sms'], '')); ?>">
                  <p class="description"><?php echo esc_html__('Make scenario that sends SMS updates.','arm-repair-estimates'); ?></p>
                </td>
              </tr>
            </table>
            <?php submit_button(); ?>
          </form>
        </div>
        <?php
    }
}
