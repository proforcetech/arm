<?php
namespace ARM\TimeLogs;

use WP_User;

if (!defined('ABSPATH')) exit;

final class Technician_Page
{
    public const MENU_SLUG = 'arm-tech-time';

    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
    }

    public static function register_menu(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user instanceof WP_User) {
            return;
        }

        if (!self::is_visible_to($user)) {
            return;
        }

        add_menu_page(
            __('My Time Tracking', 'arm-repair-estimates'),
            __('My Time', 'arm-repair-estimates'),
            'read',
            self::MENU_SLUG,
            [__CLASS__, 'render'],
            'dashicons-clock',
            57
        );
    }

    public static function render(): void
    {
        if (!is_user_logged_in()) {
            wp_die(__('You must be logged in to access this page.', 'arm-repair-estimates'));
        }

        $user = wp_get_current_user();
        if (!$user instanceof WP_User || !self::is_visible_to($user)) {
            wp_die(__('You do not have permission to view this page.', 'arm-repair-estimates'));
        }

        $jobs          = Controller::get_jobs_for_technician($user->ID);
        $assigned_rows = [];
        $completed_rows = [];
        $active_job_id = 0;

        foreach ($jobs as $job) {
            $totals = Controller::get_job_totals((int) $job['job_id'], (int) $user->ID);
            $row    = [
                'job'    => $job,
                'totals' => $totals,
            ];

            if (self::is_completed_status($job['job_status'] ?? '')) {
                $completed_rows[] = $row;
            } else {
                $assigned_rows[] = $row;
                if (!$active_job_id && !empty($totals['open_entry'])) {
                    $active_job_id = (int) $job['job_id'];
                }
            }
        }

        $summary = Controller::get_technician_summary((int) $user->ID);
        $summary['assigned_count']  = count($assigned_rows);
        $summary['completed_count'] = count($completed_rows);

        $nonce = wp_create_nonce('wp_rest');
        $rest  = [
            'start' => rest_url('arm/v1/time-entries/start'),
            'stop'  => rest_url('arm/v1/time-entries/stop'),
        ];

        wp_localize_script('arm-tech-time', 'ARM_RE_TIME', [
            'rest'        => $rest,
            'nonce'       => $nonce,
            'activeJobId' => $active_job_id,
            'summary'     => $summary,
            'i18n'        => [
                'startError'         => __('Unable to start the timer. Please try again.', 'arm-repair-estimates'),
                'stopError'          => __('Unable to stop the timer. Please try again.', 'arm-repair-estimates'),
                'started'            => __('Timer started.', 'arm-repair-estimates'),
                'stopped'            => __('Timer stopped.', 'arm-repair-estimates'),
                'runningSince'       => __('Running since %s', 'arm-repair-estimates'),
                'decimalLabel'       => __('Decimal: %s hrs', 'arm-repair-estimates'),
                'locationDenied'     => __('Location access was denied. Time will still be tracked, but location could not be saved.', 'arm-repair-estimates'),
                'locationUnavailable'=> __('We were unable to capture your location. Time entries will continue without it.', 'arm-repair-estimates'),
            ],
        ]);

        wp_enqueue_style('arm-re-admin');
        wp_enqueue_script('arm-tech-time');

        ?>
        <div class="wrap arm-tech-time">
            <h1><?php esc_html_e('Technician Portal', 'arm-repair-estimates'); ?></h1>
            <p class="description"><?php esc_html_e('Review your assigned work orders, log time for active jobs, and monitor your billable hours.', 'arm-repair-estimates'); ?></p>

            <div id="arm-tech-time__notice" class="notice" style="display:none;"></div>

            <div class="arm-tech-time__summary" id="arm-tech-time__summary">
                <div class="arm-tech-time__summary-card" data-summary="work">
                    <span class="arm-tech-time__summary-label"><?php esc_html_e('Total Hours Worked', 'arm-repair-estimates'); ?></span>
                    <span class="arm-tech-time__summary-value" data-summary-field="value"><?php echo esc_html($summary['work_formatted']); ?></span>
                    <span class="arm-tech-time__summary-subtext" data-summary-field="decimal"><?php echo esc_html(sprintf(__('Decimal: %s hrs', 'arm-repair-estimates'), $summary['work_decimal_formatted'])); ?></span>
                </div>
                <div class="arm-tech-time__summary-card" data-summary="billable">
                    <span class="arm-tech-time__summary-label"><?php esc_html_e('Billable Hours', 'arm-repair-estimates'); ?></span>
                    <span class="arm-tech-time__summary-value" data-summary-field="value"><?php echo esc_html($summary['billable_formatted']); ?></span>
                </div>
                <div class="arm-tech-time__summary-card" data-summary="assigned">
                    <span class="arm-tech-time__summary-label"><?php esc_html_e('Assigned Work Orders', 'arm-repair-estimates'); ?></span>
                    <span class="arm-tech-time__summary-value" data-summary-field="value"><?php echo esc_html(number_format_i18n(count($assigned_rows))); ?></span>
                </div>
                <div class="arm-tech-time__summary-card" data-summary="completed">
                    <span class="arm-tech-time__summary-label"><?php esc_html_e('Completed Work Orders', 'arm-repair-estimates'); ?></span>
                    <span class="arm-tech-time__summary-value" data-summary-field="value"><?php echo esc_html(number_format_i18n(count($completed_rows))); ?></span>
                </div>
            </div>

            <?php if (empty($assigned_rows)) : ?>
                <div class="notice notice-info"><p><?php esc_html_e('No work orders are currently assigned to you.', 'arm-repair-estimates'); ?></p></div>
            <?php else : ?>
                <h2><?php esc_html_e('Active Work Orders', 'arm-repair-estimates'); ?></h2>
                <table class="widefat striped arm-tech-time__table" data-active-job="<?php echo esc_attr($active_job_id ?: ''); ?>">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Job', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Estimate', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Customer', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Status', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Logged Time', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Actions', 'arm-repair-estimates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assigned_rows as $row) :
                            $job    = $row['job'];
                            $totals = $row['totals'];
                            $open   = $totals['open_entry'];
                            $is_open = is_array($open);
                            $customer = trim(($job['first_name'] ?? '') . ' ' . ($job['last_name'] ?? ''));
                            $global_disabled = $active_job_id && $active_job_id !== (int) $job['job_id'];
                            $start_disabled = $is_open || $global_disabled;
                            ?>
                            <tr data-job-id="<?php echo esc_attr($job['job_id']); ?>">
                                <td>
                                    <strong><?php echo esc_html($job['title']); ?></strong><br>
                                    <span class="description"><?php echo esc_html(sprintf(__('Job ID #%d', 'arm-repair-estimates'), $job['job_id'])); ?></span>
                                </td>
                                <td>
                                    <?php echo esc_html($job['estimate_no'] ?: __('N/A', 'arm-repair-estimates')); ?><br>
                                    <span class="description"><?php echo esc_html($job['estimate_status']); ?></span>
                                </td>
                                <td><?php echo $customer ? esc_html($customer) : '&mdash;'; ?></td>
                                <td>
                                    <?php echo esc_html(self::format_status_label($job['job_status'] ?? '')); ?><br>
                                    <?php if ($is_open && !empty($open['start_at'])) : ?>
                                        <span class="description arm-tech-time__running" data-entry-id="<?php echo esc_attr($open['id']); ?>">
                                            <?php printf(
                                                esc_html__('Running since %s', 'arm-repair-estimates'),
                                                esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $open['start_at']))
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="arm-tech-time__total" data-total-minutes="<?php echo esc_attr($totals['minutes']); ?>">
                                    <strong><?php echo esc_html($totals['formatted']); ?></strong>
                                </td>
                                <td class="arm-tech-time__actions">
                                    <div class="arm-tech-time__buttons">
                                        <button type="button" class="button button-primary arm-time-start" data-job="<?php echo esc_attr($job['job_id']); ?>"<?php if ($start_disabled) echo ' disabled'; ?>><?php esc_html_e('Start', 'arm-repair-estimates'); ?></button>
                                        <button type="button" class="button arm-time-stop" data-job="<?php echo esc_attr($job['job_id']); ?>" data-entry="<?php echo $is_open ? esc_attr($open['id']) : ''; ?>"<?php if (!$is_open) echo ' disabled'; ?>><?php esc_html_e('Stop', 'arm-repair-estimates'); ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($completed_rows)) : ?>
                <h2><?php esc_html_e('Recently Completed Work Orders', 'arm-repair-estimates'); ?></h2>
                <table class="widefat striped arm-tech-time__table arm-tech-time__completed-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Job', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Estimate', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Customer', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Status', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Logged Time', 'arm-repair-estimates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed_rows as $row) :
                            $job    = $row['job'];
                            $totals = $row['totals'];
                            $customer = trim(($job['first_name'] ?? '') . ' ' . ($job['last_name'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($job['title']); ?></strong><br>
                                    <span class="description"><?php echo esc_html(sprintf(__('Job ID #%d', 'arm-repair-estimates'), $job['job_id'])); ?></span>
                                </td>
                                <td>
                                    <?php echo esc_html($job['estimate_no'] ?: __('N/A', 'arm-repair-estimates')); ?><br>
                                    <span class="description"><?php echo esc_html($job['estimate_status']); ?></span>
                                </td>
                                <td><?php echo $customer ? esc_html($customer) : '&mdash;'; ?></td>
                                <td><?php echo esc_html(self::format_status_label($job['job_status'] ?? '')); ?></td>
                                <td class="arm-tech-time__total" data-total-minutes="<?php echo esc_attr($totals['minutes']); ?>">
                                    <strong><?php echo esc_html($totals['formatted']); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function format_status_label(?string $status): string
    {
        $status = $status !== null ? trim((string) $status) : '';
        if ($status === '') {
            return __('Pending', 'arm-repair-estimates');
        }

        $status = str_replace(['_', '-'], ' ', $status);
        return ucwords(strtolower($status));
    }

    private static function is_completed_status(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        $normalized = strtoupper(trim((string) $status));
        if ($normalized === '') {
            return false;
        }

        $completed = ['COMPLETED', 'COMPLETE', 'DONE', 'FINISHED', 'CLOSED'];
        return in_array($normalized, $completed, true);
    }

    private static function is_visible_to(WP_User $user): bool
    {
        if (user_can($user, 'manage_options')) {
            return true;
        }

        $roles = (array) $user->roles;
        return in_array('arm_technician', $roles, true) || in_array('technician', $roles, true);
    }
}
