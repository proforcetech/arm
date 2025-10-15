<?php
namespace ARM\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI for managing reminder campaigns and reviewing delivery logs.
 */
final class Reminders
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_arm_re_reminder_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_arm_re_reminder_toggle', [__CLASS__, 'handle_toggle']);
        add_action('admin_post_arm_re_reminder_delete', [__CLASS__, 'handle_delete']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'arm-repair-estimates',
            __('Reminder Campaigns', 'arm-repair-estimates'),
            __('Reminders', 'arm-repair-estimates'),
            'manage_options',
            'arm-reminders',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';
        $id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        echo '<div class="wrap">';

        if (!empty($_GET['message'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(wp_unslash($_GET['message'])) . '</p></div>';
        }
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(wp_unslash($_GET['error'])) . '</p></div>';
        }

        switch ($view) {
            case 'edit':
                self::render_form($id);
                break;
            case 'logs':
                self::render_logs($id);
                break;
            default:
                self::render_list();
                break;
        }

        echo '</div>';
    }

    private static function render_list(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_campaigns';

        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        $new_url = admin_url('admin.php?page=arm-reminders&view=edit');

        echo '<h1 class="wp-heading-inline">' . esc_html__('Reminder Campaigns', 'arm-repair-estimates') . '</h1>';
        echo ' <a href="' . esc_url($new_url) . '" class="page-title-action">' . esc_html__('Add New', 'arm-repair-estimates') . '</a>';
        echo '<hr class="wp-header-end">';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        $headers = [
            __('Name', 'arm-repair-estimates'),
            __('Status', 'arm-repair-estimates'),
            __('Channel', 'arm-repair-estimates'),
            __('Frequency', 'arm-repair-estimates'),
            __('Next Run', 'arm-repair-estimates'),
            __('Last Run', 'arm-repair-estimates'),
            __('Actions', 'arm-repair-estimates'),
        ];
        foreach ($headers as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="7">' . esc_html__('No reminder campaigns yet.', 'arm-repair-estimates') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $edit_url = add_query_arg(['page' => 'arm-reminders', 'view' => 'edit', 'id' => (int) $row->id], admin_url('admin.php'));
                $logs_url = add_query_arg(['page' => 'arm-reminders', 'view' => 'logs', 'id' => (int) $row->id], admin_url('admin.php'));
                $toggle_action = $row->status === 'active' ? 'pause' : 'activate';
                $toggle_label  = $row->status === 'active' ? __('Pause', 'arm-repair-estimates') : __('Activate', 'arm-repair-estimates');
                $toggle_nonce  = wp_create_nonce('arm_re_reminder_toggle');
                $toggle_url    = admin_url('admin-post.php');

                $freq = ucfirst($row->frequency_unit);
                if ($row->frequency_unit !== 'one_time') {
                    $freq = sprintf(
                        /* translators: 1: number of intervals, 2: interval label */
                        _n('%1$d %2$s', '%1$d %2$ss', $row->frequency_interval, 'arm-repair-estimates'),
                        (int) $row->frequency_interval,
                        $row->frequency_unit
                    );
                }

                echo '<tr>';
                echo '<td>' . esc_html($row->name) . '</td>';
                echo '<td><span class="arm-status arm-status-' . esc_attr($row->status) . '">' . esc_html(ucfirst($row->status)) . '</span></td>';
                echo '<td>' . esc_html(ucfirst($row->channel)) . '</td>';
                echo '<td>' . esc_html($freq) . '</td>';
                echo '<td>' . esc_html($row->next_run_at ?: '—') . '</td>';
                echo '<td>' . esc_html($row->last_run_at ?: '—') . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'arm-repair-estimates') . '</a> | ';
                echo '<a href="' . esc_url($logs_url) . '">' . esc_html__('Logs', 'arm-repair-estimates') . '</a> | ';
                echo '<form method="post" action="' . esc_url($toggle_url) . '" style="display:inline;">';
                echo '<input type="hidden" name="action" value="arm_re_reminder_toggle">';
                echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($toggle_nonce) . '">';
                echo '<input type="hidden" name="id" value="' . (int) $row->id . '">';
                echo '<input type="hidden" name="state" value="' . esc_attr($toggle_action) . '">';
                submit_button($toggle_label, 'link', '', false);
                echo '</form> | ';
                $delete_nonce = wp_create_nonce('arm_re_reminder_delete');
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js(__('Archive this campaign?', 'arm-repair-estimates')) . '\');">';
                echo '<input type="hidden" name="action" value="arm_re_reminder_delete">';
                echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($delete_nonce) . '">';
                echo '<input type="hidden" name="id" value="' . (int) $row->id . '">';
                submit_button(__('Archive', 'arm-repair-estimates'), 'link-delete', '', false);
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    private static function render_form(int $id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_campaigns';
        $row = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id)) : null;

        $title = $id ? __('Edit Reminder Campaign', 'arm-repair-estimates') : __('Add Reminder Campaign', 'arm-repair-estimates');
        $nonce = wp_create_nonce('arm_re_reminder_save');

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="arm_re_reminder_save">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="hidden" name="id" value="' . (int) ($row->id ?? 0) . '">';

        $next_value = '';
        if (!empty($row->next_run_at)) {
            $ts = strtotime($row->next_run_at);
            if ($ts) {
                $next_value = date('Y-m-d\TH:i', $ts);
            }
        }

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th><label for="arm_reminder_name">' . esc_html__('Name', 'arm-repair-estimates') . '</label></th><td><input type="text" id="arm_reminder_name" name="name" value="' . esc_attr($row->name ?? '') . '" class="regular-text" required></td></tr>';
        echo '<tr><th><label for="arm_reminder_description">' . esc_html__('Description', 'arm-repair-estimates') . '</label></th><td><textarea id="arm_reminder_description" name="description" rows="3" class="large-text">' . esc_textarea($row->description ?? '') . '</textarea></td></tr>';

        $status = $row->status ?? 'draft';
        echo '<tr><th><label for="arm_reminder_status">' . esc_html__('Status', 'arm-repair-estimates') . '</label></th><td><select id="arm_reminder_status" name="status">';
        foreach (['draft','active','paused','archived'] as $opt) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($opt), selected($status, $opt, false), esc_html(ucfirst($opt)));
        }
        echo '</select></td></tr>';

        $channel = $row->channel ?? 'email';
        echo '<tr><th><label for="arm_reminder_channel">' . esc_html__('Channel', 'arm-repair-estimates') . '</label></th><td><select id="arm_reminder_channel" name="channel">';
        foreach (['email','sms','both'] as $opt) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($opt), selected($channel, $opt, false), esc_html(ucfirst($opt)));
        }
        echo '</select></td></tr>';

        $freq_unit = $row->frequency_unit ?? 'one_time';
        $freq_interval = (int) ($row->frequency_interval ?? 1);
        echo '<tr><th><label for="arm_reminder_frequency_unit">' . esc_html__('Frequency', 'arm-repair-estimates') . '</label></th><td>';
        echo '<select id="arm_reminder_frequency_unit" name="frequency_unit">';
        $units = [
            'one_time' => __('One Time', 'arm-repair-estimates'),
            'daily'    => __('Daily', 'arm-repair-estimates'),
            'weekly'   => __('Weekly', 'arm-repair-estimates'),
            'monthly'  => __('Monthly', 'arm-repair-estimates'),
        ];
        foreach ($units as $value => $label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected($freq_unit, $value, false), esc_html($label));
        }
        echo '</select> ';
        echo '<input type="number" min="1" id="arm_reminder_frequency_interval" name="frequency_interval" value="' . max(1, $freq_interval) . '" style="width:80px;">';
        echo '</td></tr>';

        echo '<tr><th><label for="arm_reminder_next_run">' . esc_html__('Next Run (site time)', 'arm-repair-estimates') . '</label></th><td><input type="datetime-local" id="arm_reminder_next_run" name="next_run_at" value="' . esc_attr($next_value) . '"></td></tr>';

        echo '<tr><th><label for="arm_reminder_email_subject">' . esc_html__('Email Subject', 'arm-repair-estimates') . '</label></th><td><input type="text" id="arm_reminder_email_subject" name="email_subject" value="' . esc_attr($row->email_subject ?? '') . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="arm_reminder_email_body">' . esc_html__('Email Body', 'arm-repair-estimates') . '</label></th><td><textarea id="arm_reminder_email_body" name="email_body" rows="6" class="large-text code">' . esc_textarea($row->email_body ?? '') . '</textarea><p class="description">' . esc_html__('Available tags: {{customer.first_name}}, {{customer.full_name}}, {{campaign.name}}, {{site.name}}, {{scheduled_for}}', 'arm-repair-estimates') . '</p></td></tr>';
        echo '<tr><th><label for="arm_reminder_sms_body">' . esc_html__('SMS Body', 'arm-repair-estimates') . '</label></th><td><textarea id="arm_reminder_sms_body" name="sms_body" rows="4" class="large-text code">' . esc_textarea($row->sms_body ?? '') . '</textarea></td></tr>';

        echo '</table>';
        submit_button($id ? __('Update Campaign', 'arm-repair-estimates') : __('Create Campaign', 'arm-repair-estimates'));
        echo '</form>';
    }

    private static function render_logs(int $id): void
    {
        if ($id <= 0) {
            echo '<p>' . esc_html__('Invalid campaign.', 'arm-repair-estimates') . '</p>';
            return;
        }

        global $wpdb;
        $campaigns_table = $wpdb->prefix . 'arm_reminder_campaigns';
        $logs_table      = $wpdb->prefix . 'arm_reminder_logs';
        $prefs_table     = $wpdb->prefix . 'arm_reminder_preferences';

        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns_table WHERE id=%d", $id));
        if (!$campaign) {
            echo '<p>' . esc_html__('Campaign not found.', 'arm-repair-estimates') . '</p>';
            return;
        }

        echo '<h1>' . esc_html(sprintf(__('Reminder Logs — %s', 'arm-repair-estimates'), $campaign->name)) . '</h1>';

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, p.email, p.phone FROM $logs_table l LEFT JOIN $prefs_table p ON l.preference_id=p.id WHERE l.campaign_id=%d ORDER BY l.created_at DESC LIMIT 250",
                $id
            )
        );

        if (!$logs) {
            echo '<p>' . esc_html__('No reminder activity recorded yet.', 'arm-repair-estimates') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        $headers = [
            __('Created', 'arm-repair-estimates'),
            __('Channel', 'arm-repair-estimates'),
            __('Status', 'arm-repair-estimates'),
            __('Scheduled For', 'arm-repair-estimates'),
            __('Sent At', 'arm-repair-estimates'),
            __('Recipient', 'arm-repair-estimates'),
            __('Message', 'arm-repair-estimates'),
            __('Error', 'arm-repair-estimates'),
        ];
        foreach ($headers as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '<td>' . esc_html(ucfirst($log->channel)) . '</td>';
            echo '<td>' . esc_html(ucfirst($log->status)) . '</td>';
            echo '<td>' . esc_html($log->scheduled_for) . '</td>';
            echo '<td>' . esc_html($log->sent_at ?: '—') . '</td>';
            $recipient = $log->channel === 'sms' ? ($log->phone ?: '—') : ($log->email ?: '—');
            echo '<td>' . esc_html($recipient) . '</td>';
            echo '<td><code>' . esc_html(wp_trim_words($log->message_body ?? '', 30)) . '</code></td>';
            echo '<td>' . esc_html($log->error_message ?: '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public static function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'arm-repair-estimates'));
        }

        check_admin_referer('arm_re_reminder_save');

        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_campaigns';

        $id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name        = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));
        $status      = sanitize_key($_POST['status'] ?? 'draft');
        $channel     = sanitize_key($_POST['channel'] ?? 'email');
        $freq_unit   = sanitize_key($_POST['frequency_unit'] ?? 'one_time');
        $freq_int    = max(1, (int) ($_POST['frequency_interval'] ?? 1));
        $next_run    = self::sanitize_datetime($_POST['next_run_at'] ?? '');
        $email_subj  = sanitize_text_field(wp_unslash($_POST['email_subject'] ?? ''));
        $email_body  = wp_kses_post(wp_unslash($_POST['email_body'] ?? ''));
        $sms_body    = sanitize_textarea_field(wp_unslash($_POST['sms_body'] ?? ''));

        if ($name === '') {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Name is required.', 'arm-repair-estimates')), wp_get_referer()));
            exit;
        }

        $data = [
            'name'               => $name,
            'description'        => $description,
            'status'             => $status,
            'channel'            => in_array($channel, ['email','sms','both'], true) ? $channel : 'email',
            'frequency_unit'     => in_array($freq_unit, ['one_time','daily','weekly','monthly'], true) ? $freq_unit : 'one_time',
            'frequency_interval' => $freq_int,
            'next_run_at'        => $next_run,
            'email_subject'      => $email_subj,
            'email_body'         => $email_body,
            'sms_body'           => $sms_body,
            'updated_at'         => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $id = (int) $wpdb->insert_id;
        }

        $redirect = add_query_arg(
            [
                'page'    => 'arm-reminders',
                'view'    => 'edit',
                'id'      => $id,
                'message' => rawurlencode(__('Campaign saved.', 'arm-repair-estimates')),
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_toggle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'arm-repair-estimates'));
        }

        check_admin_referer('arm_re_reminder_toggle');

        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_campaigns';
        $id    = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $state = sanitize_key($_POST['state'] ?? '');

        if ($id <= 0) {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Invalid campaign.', 'arm-repair-estimates')), wp_get_referer()));
            exit;
        }

        $status = $state === 'activate' ? 'active' : 'paused';
        $wpdb->update(
            $table,
            [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id]
        );

        wp_safe_redirect(add_query_arg('message', rawurlencode(__('Status updated.', 'arm-repair-estimates')), wp_get_referer()));
        exit;
    }

    public static function handle_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'arm-repair-estimates'));
        }

        check_admin_referer('arm_re_reminder_delete');

        global $wpdb;
        $table = $wpdb->prefix . 'arm_reminder_campaigns';
        $id    = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if ($id <= 0) {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Invalid campaign.', 'arm-repair-estimates')), wp_get_referer()));
            exit;
        }

        $wpdb->update(
            $table,
            [
                'status'     => 'archived',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id]
        );

        wp_safe_redirect(add_query_arg('message', rawurlencode(__('Campaign archived.', 'arm-repair-estimates')), admin_url('admin.php?page=arm-reminders')));
        exit;
    }

    private static function sanitize_datetime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = is_array($value) ? reset($value) : $value;
        $value = sanitize_text_field(wp_unslash($value));
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
