<?php
namespace ARM\TimeLogs;

use DateTime;
use WP_Error;
use wpdb;

if (!defined('ABSPATH')) exit;

final class Admin
{
    private const MENU_SLUG = 'arm-time-logs';

    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_arm_time_entry_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_arm_time_entry_create', [__CLASS__, 'handle_create']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'arm-repair-estimates',
            __('Time Logs', 'arm-repair-estimates'),
            __('Time Logs', 'arm-repair-estimates'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'arm-repair-estimates'));
        }

        global $wpdb;

        [$start_filter, $end_filter, $start_mysql, $end_mysql] = self::get_date_range();

        $entries = self::query_entries($start_mysql, $end_mysql);
        $adjustments = self::query_adjustments($start_mysql, $end_mysql);

        $edit_id = isset($_GET['entry']) ? absint($_GET['entry']) : 0;
        $entry_to_edit = $edit_id ? self::get_entry_with_context($edit_id) : null;

        $notice_type = isset($_GET['time_type']) ? sanitize_key(wp_unslash($_GET['time_type'])) : '';
        $notice_msg  = isset($_GET['time_msg']) ? sanitize_text_field(wp_unslash(urldecode($_GET['time_msg']))) : '';

        $admin_post = admin_url('admin-post.php');

        ?>
        <div class="wrap arm-time-logs">
            <h1><?php esc_html_e('Technician Time Logs', 'arm-repair-estimates'); ?></h1>

            <?php if ($notice_msg) : ?>
                <div class="notice notice-<?php echo $notice_type === 'error' ? 'error' : 'success'; ?>"><p><?php echo esc_html($notice_msg); ?></p></div>
            <?php endif; ?>

            <form method="get" class="arm-time-logs__filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <label>
                    <?php esc_html_e('Start date', 'arm-repair-estimates'); ?>
                    <input type="date" name="start" value="<?php echo esc_attr($start_filter); ?>">
                </label>
                <label>
                    <?php esc_html_e('End date', 'arm-repair-estimates'); ?>
                    <input type="date" name="end" value="<?php echo esc_attr($end_filter); ?>">
                </label>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'arm-repair-estimates'); ?></button>
            </form>

            <hr>

            <h2><?php esc_html_e('Add Manual Time Entry', 'arm-repair-estimates'); ?></h2>
            <form method="post" action="<?php echo esc_url($admin_post); ?>" class="arm-time-logs__create">
                <?php wp_nonce_field('arm_time_entry_create'); ?>
                <input type="hidden" name="action" value="arm_time_entry_create">
                <p>
                    <label for="arm-time-job-id"><?php esc_html_e('Job ID', 'arm-repair-estimates'); ?></label><br>
                    <input type="number" id="arm-time-job-id" name="job_id" min="1" required>
                </p>
                <p>
                    <label for="arm-time-tech-id"><?php esc_html_e('Technician User ID', 'arm-repair-estimates'); ?></label><br>
                    <input type="number" id="arm-time-tech-id" name="technician_id" min="1" required>
                </p>
                <p>
                    <label for="arm-time-start"><?php esc_html_e('Start time', 'arm-repair-estimates'); ?></label><br>
                    <input type="datetime-local" id="arm-time-start" name="start_at" required>
                </p>
                <p>
                    <label for="arm-time-end"><?php esc_html_e('End time', 'arm-repair-estimates'); ?></label><br>
                    <input type="datetime-local" id="arm-time-end" name="end_at">
                    <span class="description"><?php esc_html_e('Leave blank for an in-progress entry.', 'arm-repair-estimates'); ?></span>
                </p>
                <p>
                    <label for="arm-time-notes"><?php esc_html_e('Notes', 'arm-repair-estimates'); ?></label><br>
                    <textarea id="arm-time-notes" name="notes" rows="3" cols="50"></textarea>
                </p>
                <p>
                    <label for="arm-time-reason-create"><?php esc_html_e('Adjustment reason', 'arm-repair-estimates'); ?></label><br>
                    <textarea id="arm-time-reason-create" name="reason" rows="2" cols="50" required></textarea>
                </p>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Create Entry', 'arm-repair-estimates'); ?></button>
                </p>
            </form>

            <?php if ($entry_to_edit) : ?>
                <hr>
                <h2><?php esc_html_e('Edit Time Entry', 'arm-repair-estimates'); ?></h2>
                <form method="post" action="<?php echo esc_url($admin_post); ?>" class="arm-time-logs__edit">
                    <?php wp_nonce_field('arm_time_entry_save'); ?>
                    <input type="hidden" name="action" value="arm_time_entry_save">
                    <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry_to_edit['id']); ?>">
                    <p><strong><?php echo esc_html(sprintf(__('Entry #%d', 'arm-repair-estimates'), $entry_to_edit['id'])); ?></strong></p>
                    <p>
                        <label><?php esc_html_e('Technician', 'arm-repair-estimates'); ?></label><br>
                        <?php echo esc_html($entry_to_edit['technician_name'] ?? __('Unknown', 'arm-repair-estimates')); ?>
                    </p>
                    <p>
                        <label for="arm-time-edit-start"><?php esc_html_e('Start time', 'arm-repair-estimates'); ?></label><br>
                        <input type="datetime-local" id="arm-time-edit-start" name="start_at" value="<?php echo esc_attr(self::format_for_input($entry_to_edit['start_at'] ?? '')); ?>" required>
                    </p>
                    <p>
                        <label for="arm-time-edit-end"><?php esc_html_e('End time', 'arm-repair-estimates'); ?></label><br>
                        <input type="datetime-local" id="arm-time-edit-end" name="end_at" value="<?php echo esc_attr(self::format_for_input($entry_to_edit['end_at'] ?? '')); ?>">
                        <span class="description"><?php esc_html_e('Leave blank to keep the entry open.', 'arm-repair-estimates'); ?></span>
                    </p>
                    <p>
                        <label for="arm-time-edit-notes"><?php esc_html_e('Notes', 'arm-repair-estimates'); ?></label><br>
                        <textarea id="arm-time-edit-notes" name="notes" rows="3" cols="60"><?php echo esc_textarea($entry_to_edit['notes'] ?? ''); ?></textarea>
                    </p>
                    <p>
                        <label for="arm-time-edit-reason"><?php esc_html_e('Adjustment reason', 'arm-repair-estimates'); ?></label><br>
                        <textarea id="arm-time-edit-reason" name="reason" rows="2" cols="60" required></textarea>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Changes', 'arm-repair-estimates'); ?></button>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"><?php esc_html_e('Cancel', 'arm-repair-estimates'); ?></a>
                    </p>
                </form>
            <?php endif; ?>

            <hr>
            <h2><?php esc_html_e('Logged Entries', 'arm-repair-estimates'); ?></h2>
            <table class="widefat striped arm-time-logs__table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Entry', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Technician', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Job', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Start', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('End', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Duration', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Notes', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Actions', 'arm-repair-estimates'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No time entries found for the selected range.', 'arm-repair-estimates'); ?></td></tr>
                    <?php else : foreach ($entries as $entry) : ?>
                        <tr>
                            <td>#<?php echo esc_html($entry['id']); ?></td>
                            <td><?php echo esc_html($entry['technician_name'] ?: __('Unknown', 'arm-repair-estimates')); ?></td>
                            <td>
                                <?php echo esc_html($entry['job_title'] ?: __('Job', 'arm-repair-estimates')); ?><br>
                                <span class="description"><?php echo esc_html(sprintf(__('Job ID %d', 'arm-repair-estimates'), $entry['job_id'])); ?></span>
                            </td>
                            <td><?php echo esc_html(self::format_admin_datetime($entry['start_at'])); ?></td>
                            <td><?php echo $entry['end_at'] ? esc_html(self::format_admin_datetime($entry['end_at'])) : '&mdash;'; ?></td>
                            <td><?php echo esc_html(self::format_duration_display($entry)); ?></td>
                            <td><?php echo $entry['notes'] ? esc_html($entry['notes']) : '&mdash;'; ?></td>
                            <td><a class="button" href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'entry' => (int) $entry['id'], 'start' => $start_filter, 'end' => $end_filter], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'arm-repair-estimates'); ?></a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <hr>
            <h2><?php esc_html_e('Adjustment History', 'arm-repair-estimates'); ?></h2>
            <table class="widefat striped arm-time-logs__adjustments">
                <thead>
                    <tr>
                        <th><?php esc_html_e('When', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Entry', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Admin', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Action', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('From', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('To', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Reason', 'arm-repair-estimates'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adjustments)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No adjustments recorded for the selected range.', 'arm-repair-estimates'); ?></td></tr>
                    <?php else : foreach ($adjustments as $row) : ?>
                        <tr>
                            <td><?php echo esc_html(self::format_admin_datetime($row['created_at'])); ?></td>
                            <td>#<?php echo esc_html($row['time_entry_id']); ?></td>
                            <td><?php echo esc_html($row['admin_name'] ?: __('Unknown', 'arm-repair-estimates')); ?></td>
                            <td><?php echo esc_html(ucfirst($row['action'])); ?></td>
                            <td><?php echo esc_html(self::format_adjustment_block($row['previous_start'], $row['previous_end'], $row['previous_duration'])); ?></td>
                            <td><?php echo esc_html(self::format_adjustment_block($row['new_start'], $row['new_end'], $row['new_duration'])); ?></td>
                            <td><?php echo $row['reason'] ? esc_html($row['reason']) : '&mdash;'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'arm-repair-estimates'));
        }

        check_admin_referer('arm_time_entry_save');

        $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
        if (!$entry_id) {
            self::redirect('error', __('Missing entry ID.', 'arm-repair-estimates'));
        }

        $start_raw = isset($_POST['start_at']) ? sanitize_text_field(wp_unslash($_POST['start_at'])) : '';
        $end_raw   = isset($_POST['end_at']) ? sanitize_text_field(wp_unslash($_POST['end_at'])) : '';
        $notes     = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $reason    = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if ($reason === '') {
            self::redirect('error', __('Please provide an adjustment reason.', 'arm-repair-estimates'));
        }

        $start_at = self::parse_local_datetime($start_raw);
        if (!$start_at) {
            self::redirect('error', __('Invalid start time provided.', 'arm-repair-estimates'));
        }

        $end_at = null;
        if ($end_raw !== '') {
            $end_at = self::parse_local_datetime($end_raw);
            if (!$end_at) {
                self::redirect('error', __('Invalid end time provided.', 'arm-repair-estimates'));
            }
        }

        if ($end_at && strtotime($end_at) <= strtotime($start_at)) {
            self::redirect('error', __('End time must be after the start time.', 'arm-repair-estimates'));
        }

        $data = [
            'start_at' => $start_at,
            'end_at'   => $end_at,
            'notes'    => $notes,
        ];

        if ($end_at) {
            $data['duration_minutes'] = self::calculate_duration($start_at, $end_at);
        } else {
            $data['duration_minutes'] = null;
        }

        $result = Controller::update_entry($entry_id, $data, get_current_user_id(), $reason);
        if ($result instanceof WP_Error) {
            self::redirect('error', $result->get_error_message());
        }

        self::redirect('success', __('Time entry updated.', 'arm-repair-estimates'));
    }

    public static function handle_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'arm-repair-estimates'));
        }

        check_admin_referer('arm_time_entry_create');

        $job_id        = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        $technician_id = isset($_POST['technician_id']) ? absint($_POST['technician_id']) : 0;
        $start_raw     = isset($_POST['start_at']) ? sanitize_text_field(wp_unslash($_POST['start_at'])) : '';
        $end_raw       = isset($_POST['end_at']) ? sanitize_text_field(wp_unslash($_POST['end_at'])) : '';
        $notes         = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $reason        = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if (!$job_id || !$technician_id) {
            self::redirect('error', __('Job ID and technician ID are required.', 'arm-repair-estimates'));
        }
        if ($start_raw === '') {
            self::redirect('error', __('Start time is required.', 'arm-repair-estimates'));
        }
        if ($reason === '') {
            self::redirect('error', __('Please provide an adjustment reason.', 'arm-repair-estimates'));
        }

        $start_at = self::parse_local_datetime($start_raw);
        if (!$start_at) {
            self::redirect('error', __('Invalid start time provided.', 'arm-repair-estimates'));
        }

        $end_at = null;
        if ($end_raw !== '') {
            $end_at = self::parse_local_datetime($end_raw);
            if (!$end_at) {
                self::redirect('error', __('Invalid end time provided.', 'arm-repair-estimates'));
            }
        }

        if ($end_at && strtotime($end_at) <= strtotime($start_at)) {
            self::redirect('error', __('End time must be after the start time.', 'arm-repair-estimates'));
        }

        $result = Controller::create_manual_entry($job_id, $technician_id, $start_at, $end_at, $notes, get_current_user_id(), $reason);
        if ($result instanceof WP_Error) {
            self::redirect('error', $result->get_error_message());
        }

        self::redirect('success', __('Time entry created.', 'arm-repair-estimates'));
    }

    private static function redirect(string $type, string $message): void
    {
        $referer = wp_get_referer();
        $target  = $referer && strpos($referer, 'page=' . self::MENU_SLUG) !== false
            ? $referer
            : admin_url('admin.php?page=' . self::MENU_SLUG);

        $target = add_query_arg([
            'time_type' => $type,
            'time_msg'  => rawurlencode($message),
        ], $target);

        wp_safe_redirect($target);
        exit;
    }

    private static function get_date_range(): array
    {
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);

        $start_raw = isset($_GET['start']) ? sanitize_text_field(wp_unslash($_GET['start'])) : '';
        $end_raw   = isset($_GET['end']) ? sanitize_text_field(wp_unslash($_GET['end'])) : '';

        $start = $start_raw ? DateTime::createFromFormat('Y-m-d', $start_raw, $tz) : null;
        $end   = $end_raw ? DateTime::createFromFormat('Y-m-d', $end_raw, $tz) : null;

        if (!$start) {
            $start = clone $now;
            $start->modify('-6 days');
        }
        if (!$end) {
            $end = clone $now;
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $start_filter = $start->format('Y-m-d');
        $end_filter   = $end->format('Y-m-d');

        $start_mysql = $start->format('Y-m-d 00:00:00');
        $end_mysql   = $end->format('Y-m-d 23:59:59');

        return [$start_filter, $end_filter, $start_mysql, $end_mysql];
    }

    private static function query_entries(string $start, string $end): array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return [];
        }

        $entries   = Controller::table_entries();
        $jobs      = $wpdb->prefix . 'arm_estimate_jobs';
        $estimates = $wpdb->prefix . 'arm_estimates';
        $users     = $wpdb->users;

        $sql = "SELECT t.*, j.title AS job_title, u.display_name AS technician_name
                FROM $entries t
                LEFT JOIN $jobs j ON j.id = t.job_id
                LEFT JOIN $users u ON u.ID = t.technician_id
                WHERE t.start_at BETWEEN %s AND %s
                ORDER BY t.start_at DESC
                LIMIT 200";

        return $wpdb->get_results($wpdb->prepare($sql, $start, $end), ARRAY_A) ?: [];
    }

    private static function query_adjustments(string $start, string $end): array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return [];
        }

        $adjust = Controller::table_adjustments();
        $users  = $wpdb->users;

        $sql = "SELECT a.*, u.display_name AS admin_name
                FROM $adjust a
                LEFT JOIN $users u ON u.ID = a.admin_id
                WHERE a.created_at BETWEEN %s AND %s
                ORDER BY a.created_at DESC
                LIMIT 200";

        return $wpdb->get_results($wpdb->prepare($sql, $start, $end), ARRAY_A) ?: [];
    }

    private static function get_entry_with_context(int $entry_id): ?array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return null;
        }

        $entries = Controller::table_entries();
        $users   = $wpdb->users;

        $sql = "SELECT t.*, u.display_name AS technician_name
                FROM $entries t
                LEFT JOIN $users u ON u.ID = t.technician_id
                WHERE t.id = %d";

        $row = $wpdb->get_row($wpdb->prepare($sql, $entry_id), ARRAY_A);
        if (!$row) {
            return null;
        }

        return $row;
    }

    private static function parse_local_datetime(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $tz = wp_timezone();
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value, $tz);
        if (!$dt) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value, $tz);
        }
        if (!$dt) {
            return null;
        }

        return $dt->format('Y-m-d H:i:s');
    }

    private static function format_for_input(string $value): string
    {
        if (!$value) {
            return '';
        }
        $tz = wp_timezone();
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value, $tz);
        if (!$dt) {
            return '';
        }
        return $dt->format('Y-m-d\TH:i');
    }

    private static function format_admin_datetime(?string $value): string
    {
        if (!$value) {
            return '';
        }
        $timestamp = strtotime($value);
        if (!$timestamp) {
            return $value;
        }
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private static function format_duration_display(array $entry): string
    {
        $minutes = isset($entry['duration_minutes']) && $entry['duration_minutes'] !== null
            ? (int) $entry['duration_minutes']
            : 0;

        if (!$minutes && empty($entry['end_at']) && !empty($entry['start_at'])) {
            $minutes = max(0, (int) floor((current_time('timestamp') - strtotime($entry['start_at'])) / 60));
        }

        return Controller::format_minutes($minutes);
    }

    private static function format_adjustment_block($start, $end, $minutes): string
    {
        if (!$start && !$end && !$minutes) {
            return __('—', 'arm-repair-estimates');
        }

        $parts = [];
        if ($start) {
            $parts[] = sprintf(__('Start: %s', 'arm-repair-estimates'), self::format_admin_datetime($start));
        }
        if ($end) {
            $parts[] = sprintf(__('End: %s', 'arm-repair-estimates'), self::format_admin_datetime($end));
        }
        if ($minutes) {
            $parts[] = sprintf(__('Minutes: %d', 'arm-repair-estimates'), (int) $minutes);
        }

        return implode(' | ', $parts);
    }

    private static function calculate_duration(string $start, string $end): int
    {
        $start_ts = strtotime($start);
        $end_ts   = strtotime($end);
        if (!$start_ts || !$end_ts || $end_ts <= $start_ts) {
            return 1;
        }
        return max(1, (int) floor(($end_ts - $start_ts) / 60));
    }
}
