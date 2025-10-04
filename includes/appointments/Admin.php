<?php
namespace ARM\Appointments;

if (!defined('ABSPATH')) exit;

/**
 * Admin UI for managing appointments (calendar view).
 */
final class Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_page']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_arm_admin_events', [__CLASS__, 'ajax_events']);
        add_action('wp_ajax_arm_save_event', [__CLASS__, 'ajax_save_event']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'arm-repair-estimates',
            __('Appointments', 'arm-repair-estimates'),
            __('Appointments', 'arm-repair-estimates'),
            'manage_options',
            'arm-appointments',
            [__CLASS__, 'render_calendar']
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'arm-appointments') === false) return;

        wp_enqueue_style('fullcalendar-css', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css');
        wp_enqueue_script('fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js', [], null, true);
        wp_enqueue_script(
            'arm-appointments-admin',
            ARM_RE_URL . 'assets/js/arm-appointments-admin.js',
            ['jquery', 'fullcalendar-js'],
            ARM_RE_VERSION,
            true
        );
        wp_localize_script('arm-appointments-admin', 'ARM_APPT', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('arm_appt_nonce'),
        ]);
    }

    public static function render_calendar(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
          <h1><?php _e('Appointments Calendar', 'arm-repair-estimates'); ?></h1>
          <div id="arm-calendar"></div>
        </div>
        <?php
    }

    public static function ajax_events(): void
    {
        check_ajax_referer('arm_appt_nonce', 'nonce');
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_appointments';
        $rows = $wpdb->get_results("SELECT id, start_datetime, end_datetime, status FROM $tbl");

        $events = [];
        foreach ($rows as $row) {
            $color = 'blue';
            if ($row->status === 'confirmed') {
                $color = 'green';
            } elseif ($row->status === 'cancelled') {
                $color = 'red';
            }

            $events[] = [
                'id'    => (int) $row->id,
                'title' => sprintf(__('Appointment #%d', 'arm-repair-estimates'), $row->id),
                'start' => $row->start_datetime,
                'end'   => $row->end_datetime,
                'color' => $color,
            ];
        }

        wp_send_json($events);
    }

    public static function ajax_save_event(): void
    {
        check_ajax_referer('arm_appt_nonce', 'nonce');
        $id    = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '';
        $end   = isset($_POST['end']) ? sanitize_text_field($_POST['end']) : '';

        if ($id && $start && $end) {
            Controller::update_times($id, $start, $end);
        }

        wp_send_json_success();
    }
}
