<?php
namespace ARM\TimeLogs;

use WP_Error;
use wpdb;

if (!defined('ABSPATH')) exit;

final class Controller
{
    public static function boot(): void
    {
        Rest::boot();
        Technician_Page::boot();
        Admin::boot();
        Assets::boot();
    }

    public static function install_tables(): void
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset            = $wpdb->get_charset_collate();
        $time_entries_table = self::table_entries();
        $time_adjust_table  = self::table_adjustments();

        dbDelta("CREATE TABLE $time_entries_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            estimate_id BIGINT UNSIGNED NOT NULL,
            technician_id BIGINT UNSIGNED NOT NULL,
            source ENUM('technician','admin') NOT NULL DEFAULT 'technician',
            start_at DATETIME NOT NULL,
            end_at DATETIME NULL,
            duration_minutes INT UNSIGNED NULL,
            notes TEXT NULL,
            start_location LONGTEXT NULL,
            end_location LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_job (job_id),
            KEY idx_estimate (estimate_id),
            KEY idx_technician (technician_id),
            KEY idx_open (technician_id, end_at)
        ) $charset;");

        dbDelta("CREATE TABLE $time_adjust_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            time_entry_id BIGINT UNSIGNED NOT NULL,
            admin_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL DEFAULT 'update',
            previous_start DATETIME NULL,
            previous_end DATETIME NULL,
            previous_duration INT NULL,
            new_start DATETIME NULL,
            new_end DATETIME NULL,
            new_duration INT NULL,
            reason TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_entry (time_entry_id),
            KEY idx_admin (admin_id)
        ) $charset;
        ");
    }

    public static function table_entries(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arm_time_entries';
    }

    public static function table_adjustments(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arm_time_adjustments';
    }

    private static function encode_location($location): ?string
    {
        $normalized = self::normalize_location($location);
        if ($normalized === null) {
            return null;
        }

        $json = wp_json_encode($normalized);
        return is_string($json) ? $json : null;
    }

    private static function normalize_location($location): ?array
    {
        if ($location instanceof \stdClass) {
            $location = (array) $location;
        }

        if (is_string($location)) {
            $decoded = json_decode($location, true);
            if (!is_array($decoded)) {
                return null;
            }
            $location = $decoded;
        }

        if (!is_array($location)) {
            return null;
        }

        $normalized = [];
        $numeric_fields = [
            'latitude',
            'longitude',
            'accuracy',
            'altitude',
            'altitudeAccuracy',
            'heading',
            'speed',
        ];

        foreach ($numeric_fields as $field) {
            if (isset($location[$field]) && is_numeric($location[$field])) {
                $normalized[$field] = (float) $location[$field];
            }
        }

        $timestamp = null;
        if (!empty($location['recorded_at'])) {
            $timestamp = strtotime((string) $location['recorded_at']);
        } elseif (!empty($location['timestamp'])) {
            if (is_numeric($location['timestamp'])) {
                $raw = (float) $location['timestamp'];
                if ($raw > 0) {
                    if ($raw > 9999999999) {
                        $raw = $raw / 1000;
                    }
                    $timestamp = (int) round($raw);
                }
            } else {
                $timestamp = strtotime((string) $location['timestamp']);
            }
        }

        if ($timestamp) {
            $normalized['recorded_at'] = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
        }

        if (isset($location['error'])) {
            $error = strtoupper(preg_replace('/[^A-Z0-9_:-]/', '', (string) $location['error']));
            if ($error !== '') {
                $normalized['error'] = $error;
            }
        }

        if (!empty($location['message'])) {
            $message = self::sanitize_location_message($location['message']);
            if ($message !== '') {
                $normalized['message'] = $message;
            }
        }

        if (!empty($location['source'])) {
            $source = sanitize_key((string) $location['source']);
            if ($source !== '') {
                $normalized['source'] = $source;
            }
        }

        if ($normalized && !isset($normalized['recorded_at'])) {
            $normalized['recorded_at'] = gmdate('Y-m-d\TH:i:s\Z', current_time('timestamp', true));
        }

        return $normalized ?: null;
    }

    private static function sanitize_location_message($message): string
    {
        $message = is_scalar($message) ? (string) $message : '';
        if ($message === '') {
            return '';
        }

        if (function_exists('sanitize_textarea_field')) {
            $message = sanitize_textarea_field($message);
        } else {
            $message = sanitize_text_field($message);
        }

        if ($message === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            $message = mb_substr($message, 0, 500);
        } else {
            $message = substr($message, 0, 500);
        }

        return $message;
    }

    private static function decode_location($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function decode_location_value($value): ?array
    {
        return self::decode_location($value);
    }

    public static function start_entry(int $job_id, int $user_id, string $source = 'technician', string $note = '', array $location = [])
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return new WP_Error('arm_time_db', __('Database connection not available.', 'arm-repair-estimates'));
        }

        $job = self::get_job($job_id);
        if (!$job) {
            return new WP_Error('arm_time_job_missing', __('Job not found.', 'arm-repair-estimates'), ['status' => 404]);
        }

        if (!self::user_can_track_job($user_id, $job)) {
            return new WP_Error('arm_time_forbidden', __('You are not allowed to log time for this job.', 'arm-repair-estimates'), ['status' => 403]);
        }

        $existing_open = self::get_user_open_entry($user_id);
        if ($existing_open && (int) $existing_open['job_id'] !== $job_id) {
            return new WP_Error(
                'arm_time_active_job',
                __('You are currently tracking time on another job. Please stop it before starting a new one.', 'arm-repair-estimates'),
                ['status' => 409]
            );
        }

        $open_entry = self::get_open_entry($job_id, $user_id);
        if ($open_entry) {
            return new WP_Error('arm_time_already_open', __('A time entry is already running for this job.', 'arm-repair-estimates'), ['status' => 409]);
        }

        $now = current_time('mysql');
        if (function_exists('sanitize_textarea_field')) {
            $note = sanitize_textarea_field($note);
        }

        $start_location = self::encode_location($location);

        $data = [
            'job_id'        => $job_id,
            'estimate_id'   => (int) $job->estimate_id,
            'technician_id' => $user_id,
            'source'        => $source === 'admin' ? 'admin' : 'technician',
            'start_at'      => $now,
            'notes'         => $note,
            'created_at'    => $now,
        ];

        $formats = ['%d','%d','%d','%s','%s','%s','%s'];

        if ($start_location !== null) {
            $data['start_location'] = $start_location;
            $formats[] = '%s';
        }

        if (!$wpdb->insert(self::table_entries(), $data, $formats)) {
            return new WP_Error('arm_time_insert_failed', __('Unable to start time entry.', 'arm-repair-estimates'));
        }

        $entry_id = (int) $wpdb->insert_id;
        $entry    = self::get_entry($entry_id);

        self::log_audit('time_entry', $entry_id, 'started', $user_id, [
            'job_id'      => $job_id,
            'estimate_id' => (int) $job->estimate_id,
        ]);

        return [
            'entry'  => $entry,
            'totals' => self::get_job_totals($job_id, $user_id),
            'summary'=> self::get_technician_summary($user_id),
        ];
    }

    public static function end_entry_by_job(int $job_id, int $user_id, array $location = [])
    {
        $entry = self::get_open_entry($job_id, $user_id);
        if (!$entry) {
            return new WP_Error('arm_time_not_running', __('No running time entry found for this job.', 'arm-repair-estimates'), ['status' => 404]);
        }

        return self::close_entry((int) $entry['id'], $user_id, false, $location);
    }

    public static function close_entry(int $entry_id, int $user_id, bool $force = false, array $location = [])
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return new WP_Error('arm_time_db', __('Database connection not available.', 'arm-repair-estimates'));
        }

        $entry = self::get_entry($entry_id);
        if (!$entry) {
            return new WP_Error('arm_time_entry_missing', __('Time entry not found.', 'arm-repair-estimates'), ['status' => 404]);
        }

        if (!$force && (int) $entry['technician_id'] !== $user_id && !current_user_can('manage_options')) {
            return new WP_Error('arm_time_forbidden', __('You are not allowed to update this entry.', 'arm-repair-estimates'), ['status' => 403]);
        }

        if ($entry['end_at']) {
            return new WP_Error('arm_time_already_closed', __('This time entry has already been completed.', 'arm-repair-estimates'), ['status' => 409]);
        }

        $now       = current_time('mysql');
        $start_ts  = strtotime($entry['start_at']);
        $end_ts    = max($start_ts, current_time('timestamp'));
        $duration  = max(1, (int) floor(($end_ts - $start_ts) / 60));

        $end_location = self::encode_location($location);

        $update_data = [
            'end_at'          => $now,
            'duration_minutes'=> $duration,
            'updated_at'      => $now,
        ];
        $update_formats = ['%s','%d','%s'];

        if ($end_location !== null) {
            $update_data['end_location'] = $end_location;
            $update_formats[] = '%s';
        }

        $updated = $wpdb->update(
            self::table_entries(),
            $update_data,
            ['id' => $entry_id],
            $update_formats,
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error('arm_time_update_failed', __('Unable to finish time entry.', 'arm-repair-estimates'));
        }

        $entry = self::get_entry($entry_id);

        self::log_audit('time_entry', $entry_id, 'stopped', $user_id, [
            'job_id'      => (int) $entry['job_id'],
            'duration'    => (int) $entry['duration_minutes'],
        ]);

        return [
            'entry'  => $entry,
            'totals' => self::get_job_totals((int) $entry['job_id'], (int) $entry['technician_id']),
            'summary'=> self::get_technician_summary((int) $entry['technician_id']),
        ];
    }

    public static function update_entry(int $entry_id, array $data, int $admin_id, string $reason = '')
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return new WP_Error('arm_time_db', __('Database connection not available.', 'arm-repair-estimates'));
        }

        $current = self::get_entry($entry_id);
        if (!$current) {
            return new WP_Error('arm_time_entry_missing', __('Time entry not found.', 'arm-repair-estimates'), ['status' => 404]);
        }

        $set   = [];
        $params = [];

        if (array_key_exists('start_at', $data)) {
            if ($data['start_at'] === null) {
                $set[] = 'start_at = NULL';
            } else {
                $set[]    = 'start_at = %s';
                $params[] = $data['start_at'];
            }
        }

        if (array_key_exists('end_at', $data)) {
            if ($data['end_at'] === null) {
                $set[] = 'end_at = NULL';
            } else {
                $set[]    = 'end_at = %s';
                $params[] = $data['end_at'];
            }
        }

        if (array_key_exists('duration_minutes', $data)) {
            if ($data['duration_minutes'] === null) {
                $set[] = 'duration_minutes = NULL';
            } else {
                $set[]    = 'duration_minutes = %d';
                $params[] = (int) $data['duration_minutes'];
            }
        }

        if (array_key_exists('notes', $data)) {
            if ($data['notes'] === null) {
                $set[] = 'notes = NULL';
            } else {
                $set[]    = 'notes = %s';
                $params[] = $data['notes'];
            }
        }

        if (!$set) {
            return new WP_Error('arm_time_nothing_to_update', __('No changes supplied.', 'arm-repair-estimates'));
        }

        $set[]    = 'updated_at = %s';
        $params[] = current_time('mysql');
        $params[] = $entry_id;

        $sql = 'UPDATE ' . self::table_entries() . ' SET ' . implode(', ', $set) . ' WHERE id = %d';
        $prepared = $wpdb->prepare($sql, $params);
        if ($prepared === false) {
            return new WP_Error('arm_time_update_failed', __('Unable to update the time entry.', 'arm-repair-estimates'));
        }

        $result = $wpdb->query($prepared);
        if ($result === false) {
            return new WP_Error('arm_time_update_failed', __('Unable to update the time entry.', 'arm-repair-estimates'));
        }

        $updated = self::get_entry($entry_id);
        if ($updated) {
            self::record_adjustment($entry_id, $admin_id, 'update', $current, $updated, $reason);
        }

        return $updated;
    }

    public static function create_manual_entry(int $job_id, int $technician_id, string $start_at, ?string $end_at, string $notes, int $admin_id, string $reason = '')
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return new WP_Error('arm_time_db', __('Database connection not available.', 'arm-repair-estimates'));
        }

        $job = self::get_job($job_id);
        if (!$job) {
            return new WP_Error('arm_time_job_missing', __('Job not found.', 'arm-repair-estimates'), ['status' => 404]);
        }

        if (!get_userdata($technician_id)) {
            return new WP_Error('arm_time_user_missing', __('Technician account not found.', 'arm-repair-estimates'), ['status' => 404]);
        }

        $duration = null;
        if ($end_at) {
            $start_ts = strtotime($start_at);
            $end_ts   = strtotime($end_at);
            if ($start_ts === false || $end_ts === false || $end_ts < $start_ts) {
                return new WP_Error('arm_time_invalid_range', __('The end time must be after the start time.', 'arm-repair-estimates'), ['status' => 400]);
            }
            $duration = max(1, (int) floor(($end_ts - $start_ts) / 60));
        }

        $now = current_time('mysql');
        if (function_exists('sanitize_textarea_field')) {
            $notes = sanitize_textarea_field($notes);
        }
        $data = [
            'job_id'        => $job_id,
            'estimate_id'   => (int) $job->estimate_id,
            'technician_id' => $technician_id,
            'source'        => 'admin',
            'start_at'      => $start_at,
            'notes'         => $notes,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $formats = ['%d','%d','%d','%s','%s','%s','%s','%s'];

        if ($end_at) {
            $data['end_at'] = $end_at;
            $formats[] = '%s';
        }

        if ($duration !== null) {
            $data['duration_minutes'] = $duration;
            $formats[] = '%d';
        }

        if (!$wpdb->insert(self::table_entries(), $data, $formats)) {
            return new WP_Error('arm_time_insert_failed', __('Unable to create the time entry.', 'arm-repair-estimates'));
        }

        $entry_id = (int) $wpdb->insert_id;
        $entry    = self::get_entry($entry_id);

        if ($entry) {
            self::record_adjustment($entry_id, $admin_id, 'create', [], $entry, $reason);
        }

        return $entry;
    }

    public static function get_entry(int $entry_id): ?array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table_entries() . ' WHERE id = %d', $entry_id),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return self::format_entry($row);
    }

    public static function get_open_entry(int $job_id, int $user_id): ?array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_entries() . ' WHERE job_id = %d AND technician_id = %d AND end_at IS NULL ORDER BY start_at DESC LIMIT 1',
                $job_id,
                $user_id
            ),
            ARRAY_A
        );

        return $row ? self::format_entry($row) : null;
    }

    public static function get_job_totals(int $job_id, int $user_id): array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return ['minutes' => 0, 'formatted' => '0:00', 'open_entry' => null];
        }

        $minutes = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(duration_minutes),0) FROM ' . self::table_entries() . ' WHERE job_id = %d AND technician_id = %d AND duration_minutes IS NOT NULL',
                $job_id,
                $user_id
            )
        );

        $open = self::get_open_entry($job_id, $user_id);
        if ($open) {
            $minutes += $open['elapsed_minutes'];
        }

        return [
            'minutes'       => $minutes,
            'formatted'     => self::format_minutes($minutes),
            'open_entry'    => $open,
        ];
    }

    public static function get_open_entries_for_user(int $user_id): array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_entries() . ' WHERE technician_id = %d AND end_at IS NULL ORDER BY start_at ASC',
                $user_id
            ),
            ARRAY_A
        );

        if (!$rows) {
            return [];
        }

        return array_map([__CLASS__, 'format_entry'], $rows);
    }

    public static function get_user_open_entry(int $user_id): ?array
    {
        $entries = self::get_open_entries_for_user($user_id);
        return $entries[0] ?? null;
    }

    public static function get_total_minutes_for_technician(int $user_id): int
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return 0;
        }

        $minutes = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(duration_minutes),0) FROM ' . self::table_entries() . ' WHERE technician_id = %d AND duration_minutes IS NOT NULL',
                $user_id
            )
        );

        $open_entries = self::get_open_entries_for_user($user_id);
        foreach ($open_entries as $entry) {
            $minutes += (int) ($entry['elapsed_minutes'] ?? 0);
        }

        return $minutes;
    }

    private static function get_invoice_hours_column(): string
    {
        static $column = null;

        if ($column !== null) {
            return $column;
        }

        global $wpdb;
        $column = 'qty';
        if (!$wpdb instanceof wpdb) {
            return $column;
        }

        $table = $wpdb->prefix . 'arm_invoice_items';

        $qty_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'qty' LIMIT 1",
                $table
            )
        );

        if ($qty_exists) {
            $column = 'qty';
            return $column;
        }

        $hours_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'hours' LIMIT 1",
                $table
            )
        );

        if ($hours_exists) {
            $column = 'hours';
        }

        return $column;
    }

    public static function get_billable_hours_for_technician(int $user_id): float
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return 0.0;
        }

        $invoice_items = $wpdb->prefix . 'arm_invoice_items';
        $invoices      = $wpdb->prefix . 'arm_invoices';
        $estimates     = $wpdb->prefix . 'arm_estimates';
        $column        = self::get_invoice_hours_column();

        $sql = "SELECT COALESCE(SUM(ii.$column), 0)
                FROM $invoice_items ii
                INNER JOIN $invoices i ON i.id = ii.invoice_id
                INNER JOIN $estimates e ON e.id = i.estimate_id
                WHERE ii.item_type = %s
                  AND e.technician_id = %d
                  AND (i.status IS NULL OR i.status <> %s)";

        $value = $wpdb->get_var($wpdb->prepare($sql, 'LABOR', $user_id, 'VOID'));

        if ($value === null) {
            return 0.0;
        }

        return (float) $value;
    }

    public static function get_technician_summary(int $user_id): array
    {
        $minutes  = self::get_total_minutes_for_technician($user_id);
        $billable = self::get_billable_hours_for_technician($user_id);
        $decimal  = $minutes / 60;

        return [
            'work_minutes'           => $minutes,
            'work_formatted'         => self::format_minutes($minutes),
            'work_decimal'           => $decimal,
            'work_decimal_formatted' => number_format((float) $decimal, 2, '.', ''),
            'billable_hours'         => $billable,
            'billable_formatted'     => number_format((float) $billable, 2, '.', ''),
        ];
    }

    public static function format_minutes(int $minutes): string
    {
        $hours = (int) floor($minutes / 60);
        $mins  = $minutes % 60;
        return sprintf('%d:%02d', $hours, $mins);
    }

    public static function get_job(int $job_id)
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return null;
        }

        $jobs      = $wpdb->prefix . 'arm_estimate_jobs';
        $estimates = $wpdb->prefix . 'arm_estimates';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT j.*, e.technician_id AS estimate_technician, e.estimate_no, e.status AS estimate_status FROM $jobs j INNER JOIN $estimates e ON e.id = j.estimate_id WHERE j.id = %d",
                $job_id
            )
        );
    }

    public static function get_jobs_for_technician(int $user_id): array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return [];
        }

        $jobs      = $wpdb->prefix . 'arm_estimate_jobs';
        $estimates = $wpdb->prefix . 'arm_estimates';
        $customers = $wpdb->prefix . 'arm_customers';

        $sql = "SELECT j.id AS job_id, j.title, j.status AS job_status, j.estimate_id, e.estimate_no, e.status AS estimate_status, e.customer_id, c.first_name, c.last_name
                FROM $jobs j
                INNER JOIN $estimates e ON e.id = j.estimate_id
                LEFT JOIN $customers c ON c.id = e.customer_id
                WHERE j.technician_id = %d
                ORDER BY e.created_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, $user_id), ARRAY_A) ?: [];
    }

    public static function record_adjustment(int $entry_id, int $admin_id, string $action, array $previous, array $next, string $reason = ''): void
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return;
        }

        $data = [
            'time_entry_id'    => $entry_id,
            'admin_id'         => $admin_id,
            'action'           => $action,
            'previous_start'   => $previous['start_at'] ?? null,
            'previous_end'     => $previous['end_at'] ?? null,
            'previous_duration'=> $previous['duration_minutes'] ?? null,
            'new_start'        => $next['start_at'] ?? null,
            'new_end'          => $next['end_at'] ?? null,
            'new_duration'     => $next['duration_minutes'] ?? null,
            'reason'           => $reason,
            'created_at'       => current_time('mysql'),
        ];

        $columns      = [];
        $placeholders = [];
        $params       = [];

        foreach ($data as $column => $value) {
            $columns[] = $column;
            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }

            if (in_array($column, ['time_entry_id', 'admin_id', 'previous_duration', 'new_duration'], true)) {
                $placeholders[] = '%d';
                $params[]       = (int) $value;
            } else {
                $placeholders[] = '%s';
                $params[]       = $value;
            }
        }

        $sql = 'INSERT INTO ' . self::table_adjustments() . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $prepared = $params ? $wpdb->prepare($sql, $params) : $sql;
        if ($prepared !== false) {
            $wpdb->query($prepared);
        }

        self::log_audit('time_entry', $entry_id, 'adjusted', $admin_id, [
            'action' => $action,
            'reason' => $reason,
        ]);
    }

    public static function format_entry(array $row): array
    {
        if (array_key_exists('id', $row)) {
            $row['id'] = (int) $row['id'];
        }
        if (array_key_exists('job_id', $row)) {
            $row['job_id'] = (int) $row['job_id'];
        }
        if (array_key_exists('estimate_id', $row)) {
            $row['estimate_id'] = (int) $row['estimate_id'];
        }
        if (array_key_exists('technician_id', $row)) {
            $row['technician_id'] = (int) $row['technician_id'];
        }

        $is_open = empty($row['end_at']);
        $elapsed = 0;
        if ($is_open && !empty($row['start_at'])) {
            $start_ts = strtotime($row['start_at']);
            $elapsed  = max(0, (int) floor((current_time('timestamp') - $start_ts) / 60));
        }

        if (array_key_exists('duration_minutes', $row) && $row['duration_minutes'] !== null) {
            $row['duration_minutes'] = (int) $row['duration_minutes'];
        }

        if (array_key_exists('start_location', $row)) {
            $row['start_location'] = self::decode_location($row['start_location']);
        }

        if (array_key_exists('end_location', $row)) {
            $row['end_location'] = self::decode_location($row['end_location']);
        }

        $row['is_open']         = $is_open;
        $row['elapsed_minutes'] = $elapsed;
        $row['human_duration']  = self::format_minutes((int) ($row['duration_minutes'] ?? 0) + ($is_open ? $elapsed : 0));

        return $row;
    }

    public static function user_can_track_job(int $user_id, $job): bool
    {
        if (!$job) {
            return false;
        }

        if ((int) $job->technician_id === $user_id) {
            return true;
        }

        if ((int) ($job->estimate_technician ?? 0) === $user_id) {
            return true;
        }

        return user_can($user_id, 'manage_options');
    }

    public static function log_audit(string $entity, int $entity_id, string $action, int $actor_id, array $meta = []): void
    {
        if (class_exists('\\ARM\\Audit\\Logger')) {
            $actor = get_user_by('id', $actor_id);
            $label = $actor ? $actor->user_login : 'system';
            \ARM\Audit\Logger::log($entity, $entity_id, $action, $label, $meta);
        }
    }
}
