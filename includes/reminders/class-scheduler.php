<?php
namespace ARM\Reminders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron driven scheduler that materialises reminder notifications.
 */
final class Scheduler
{
    public static function boot(): void
    {
        add_action('arm_re_send_reminders', [__CLASS__, 'run']);
    }

    /**
     * Process due campaigns and generate/send reminder notifications.
     */
    public static function run(): void
    {
        global $wpdb;

        $campaigns_table = $wpdb->prefix . 'arm_reminder_campaigns';
        $prefs_table     = $wpdb->prefix . 'arm_reminder_preferences';
        $logs_table      = $wpdb->prefix . 'arm_reminder_logs';
        $customers_table = $wpdb->prefix . 'arm_customers';

        $now = current_time('mysql');
        $campaigns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $campaigns_table WHERE status='active' AND next_run_at IS NOT NULL AND next_run_at <= %s",
                $now
            )
        );

        if (!$campaigns) {
            return;
        }

        foreach ($campaigns as $campaign) {
            $preferences = $wpdb->get_results(
                "SELECT p.*, c.first_name, c.last_name FROM $prefs_table p " .
                "LEFT JOIN $customers_table c ON p.customer_id = c.id " .
                "WHERE p.is_active=1 AND p.preferred_channel <> 'none'"
            );

            if (!$preferences) {
                self::advance_campaign($campaign);
                continue;
            }

            foreach ($preferences as $pref) {
                $channels = self::resolve_channels($campaign->channel, $pref->preferred_channel);
                if (!$channels) {
                    continue;
                }

                foreach ($channels as $channel) {
                    $exists = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM $logs_table WHERE campaign_id=%d AND preference_id=%d AND channel=%s AND scheduled_for=%s LIMIT 1",
                            $campaign->id,
                            $pref->id,
                            $channel,
                            $campaign->next_run_at
                        )
                    );

                    if ($exists) {
                        continue;
                    }

                    $message = self::build_message($campaign, $pref, $channel);
                    $status  = 'queued';
                    $sent_at = null;
                    $error   = '';

                    if ($channel === 'email') {
                        if (empty($pref->email)) {
                            $status = 'skipped';
                            $error  = __('Missing email address', 'arm-repair-estimates');
                        } else {
                            $subject = $campaign->email_subject ?: $campaign->name;
                            if (wp_mail($pref->email, $subject, $message)) {
                                $status = 'sent';
                                $sent_at = current_time('mysql');
                            } else {
                                $status = 'failed';
                                $error  = __('Email delivery failed', 'arm-repair-estimates');
                            }
                        }
                    } elseif ($channel === 'sms') {
                        if (empty($pref->phone)) {
                            $status = 'skipped';
                            $error  = __('Missing phone number', 'arm-repair-estimates');
                        } else {
                            // SMS integrations differ; mark as pending for external processors.
                            $status = 'pending';
                        }
                    }

                    self::insert_log(
                        $logs_table,
                        [
                            'campaign_id'   => (int) $campaign->id,
                            'preference_id' => (int) $pref->id,
                            'customer_id'   => $pref->customer_id ? (int) $pref->customer_id : null,
                            'channel'       => $channel,
                            'status'        => $status,
                            'scheduled_for' => $campaign->next_run_at,
                            'sent_at'       => $sent_at,
                            'message_body'  => $message,
                            'error_message' => $error ? sanitize_textarea_field($error) : null,
                            'created_at'    => current_time('mysql'),
                        ]
                    );
                }
            }

            self::advance_campaign($campaign);
        }
    }

    /**
     * Determine which channels to use for a given preference + campaign pairing.
     */
    private static function resolve_channels(string $campaign_channel, string $preference_channel): array
    {
        $campaign_channel   = $campaign_channel ?: 'email';
        $preference_channel = $preference_channel ?: 'email';

        $campaign_modes = $campaign_channel === 'both' ? ['email', 'sms'] : [$campaign_channel];
        $preference_modes = $preference_channel === 'both'
            ? ['email', 'sms']
            : ($preference_channel === 'none' ? [] : [$preference_channel]);

        if (!$preference_modes) {
            return [];
        }

        return array_values(array_intersect($campaign_modes, $preference_modes));
    }

    /**
     * Render the outbound message body with simple merge-tags.
     */
    private static function build_message(object $campaign, object $pref, string $channel): string
    {
        $full_name = trim(($pref->first_name ?? '') . ' ' . ($pref->last_name ?? ''));
        if ($full_name === '') {
            $full_name = __('Customer', 'arm-repair-estimates');
        }

        $body = $channel === 'sms'
            ? ($campaign->sms_body ?: __('This is a friendly service reminder.', 'arm-repair-estimates'))
            : ($campaign->email_body ?: __('This is a friendly service reminder from {{site.name}}.', 'arm-repair-estimates'));

        $replacements = [
            '{{customer.first_name}}' => $pref->first_name ?? '',
            '{{customer.last_name}}'  => $pref->last_name ?? '',
            '{{customer.full_name}}'  => $full_name,
            '{{campaign.name}}'       => $campaign->name,
            '{{site.name}}'           => get_bloginfo('name'),
            '{{scheduled_for}}'       => mysql2date(
                get_option('date_format') . ' ' . get_option('time_format'),
                $campaign->next_run_at
            ),
        ];

        return strtr($body, $replacements);
    }

    /**
     * Move a campaign forward to its next run time or archive one-off campaigns.
     */
    private static function advance_campaign(object $campaign): void
    {
        global $wpdb;
        $campaigns_table = $wpdb->prefix . 'arm_reminder_campaigns';

        $now      = current_time('mysql');
        $interval = max(1, (int) ($campaign->frequency_interval ?? 1));
        $unit     = $campaign->frequency_unit ?: 'one_time';

        $next_run = null;
        if ($campaign->next_run_at) {
            $current_ts = strtotime($campaign->next_run_at);
            if ($current_ts === false) {
                $current_ts = current_time('timestamp');
            }

            switch ($unit) {
                case 'daily':
                    $next_run = date('Y-m-d H:i:s', strtotime("+{$interval} day", $current_ts));
                    break;
                case 'weekly':
                    $next_run = date('Y-m-d H:i:s', strtotime("+{$interval} week", $current_ts));
                    break;
                case 'monthly':
                    $next_run = date('Y-m-d H:i:s', strtotime("+{$interval} month", $current_ts));
                    break;
                default:
                    $next_run = null;
                    break;
            }
        }

        $updates = [
            'last_run_at' => $now,
            'updated_at'  => $now,
        ];

        if ($unit === 'one_time' || !$next_run) {
            $updates['status']      = 'archived';
            $updates['next_run_at'] = null;
        } else {
            $updates['next_run_at'] = $next_run;
        }

        $wpdb->update(
            $campaigns_table,
            $updates,
            ['id' => (int) $campaign->id],
            null,
            ['%d']
        );
    }

    /**
     * Insert a reminder log row while respecting nullable columns.
     */
    private static function insert_log(string $table, array $data): void
    {
        global $wpdb;

        $columns = array_keys($data);
        $placeholders = [];
        $values = [];

        foreach ($data as $value) {
            if ($value === null || $value === '') {
                $placeholders[] = 'NULL';
            } elseif (is_int($value)) {
                $placeholders[] = '%d';
                $values[] = $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $placeholders[] = '%s';
                $values[] = $value->format('Y-m-d H:i:s');
            } else {
                $placeholders[] = '%s';
                $values[] = $value;
            }
        }

        $sql = "INSERT INTO $table (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ')';
        $prepared = $wpdb->prepare($sql, $values);
        if ($prepared !== null) {
            $wpdb->query($prepared);
        }
    }
}
