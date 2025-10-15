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

    public static function start_entry(int $job_id, int $user_id, string $source = 'technician', string $note = '')
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

        $open_entry = self::get_open_entry($job_id, $user_id);
        if ($open_entry) {
            return new WP_Error('arm_time_already_open', __('A time entry is already running for this job.', 'arm-repair-estimates'), ['status' => 409]);
        }

        $now = current_time('mysql');
        if (function_exists('sanitize_textarea_field')) {
            $note = sanitize_textarea_field($note);
        }
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
        ];
    }

    public static function end_entry_by_job(int $job_id, int $user_id)
    {
        $entry = self::get_open_entry($job_id, $user_id);
        if (!$entry) {
            return new WP_Error('arm_time_not_running', __('No running time entry found for this job.', 'arm-repair-estimates'), ['status' => 404]);
        }

        return self::close_entry((int) $entry['id'], $user_id);
    }

    public static function close_entry(int $entry_id, int $user_id, bool $force = false)
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

        $updated = $wpdb->update(
            self::table_entries(),
            [
                'end_at'          => $now,
                'duration_minutes'=> $duration,
                'updated_at'      => $now,
            ],
            ['id' => $entry_id],
            ['%s','%d','%s'],
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
        $is_open = empty($row['end_at']);
        $elapsed = 0;
        if ($is_open && !empty($row['start_at'])) {
            $start_ts = strtotime($row['start_at']);
            $elapsed  = max(0, (int) floor((current_time('timestamp') - $start_ts) / 60));
        }

        if (array_key_exists('duration_minutes', $row) && $row['duration_minutes'] !== null) {
            $row['duration_minutes'] = (int) $row['duration_minutes'];
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
