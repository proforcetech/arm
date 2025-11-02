<?php
namespace ARM\Inspections;

if (!defined('ABSPATH')) exit;

class Reports
{
    public static function boot(): void
    {
        add_action('admin_post_arm_re_submit_inspection', [__CLASS__, 'handle_submission']);
        // Removed nopriv hook - only logged-in users with proper roles can submit
    }

    public static function create(array $template, array $submission, array $responses): int
    {
        global $wpdb;
        $reports   = $wpdb->prefix . 'arm_inspections';
        $responses_table = $wpdb->prefix . 'arm_inspection_responses';
        $now = current_time('mysql');

        $score_total = 0;
        $score_max   = 0;
        $has_fail    = false;

        foreach ($responses as &$response) {
            $score_total += (float) ($response['score_value'] ?? 0);
            $score_max   += (float) ($response['max_value'] ?? 0);
            if (!empty($response['failed'])) {
                $has_fail = true;
            }
        }
        unset($response);

        $result = 'completed';
        if ($score_max > 0) {
            $percentage = $score_total / $score_max;
            if ($has_fail) {
                $result = 'fail';
            } elseif ($percentage >= 0.5) {
                $result = 'pass';
            } else {
                $result = 'needs-review';
            }
        } elseif ($has_fail) {
            $result = 'fail';
        }

        $token = wp_generate_password(32, false, false);

        $data = [
            'template_id'    => (int) $template['id'],
            'technician_id'  => !empty($submission['technician_id']) ? (int) $submission['technician_id'] : null,
            'customer_id'    => !empty($submission['customer_id']) ? (int) $submission['customer_id'] : null,
            'vehicle_id'     => !empty($submission['vehicle_id']) ? (int) $submission['vehicle_id'] : null,
            'estimate_id'    => !empty($submission['estimate_id']) ? (int) $submission['estimate_id'] : null,
            'inspector_name' => sanitize_text_field($submission['inspector_name'] ?? ''),
            'inspector_email'=> sanitize_email($submission['inspector_email'] ?? ''),
            'customer_name'  => sanitize_text_field($submission['customer_name'] ?? ''),
            'customer_email' => sanitize_email($submission['customer_email'] ?? ''),
            'customer_phone' => sanitize_text_field($submission['customer_phone'] ?? ''),
            'summary'        => wp_kses_post($submission['summary'] ?? ''),
            'score_total'    => $score_total,
            'score_max'      => $score_max,
            'result'         => $result,
            'share_token'    => $token,
            'status'         => 'completed',
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $formats = ['%d','%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%f','%f','%s','%s','%s','%s'];
        $wpdb->insert($reports, $data, $formats);
        $inspection_id = (int) $wpdb->insert_id;

        foreach ($responses as $response) {
            $wpdb->insert($responses_table, [
                'inspection_id' => $inspection_id,
                'item_id'       => (int) $response['item_id'],
                'value_text'    => $response['value_text'],
                'numeric_value' => $response['numeric_value'],
                'score_value'   => $response['score_value'],
                'note'          => $response['note'],
                'created_at'    => $now,
                'updated_at'    => $now,
            ], ['%d','%d','%s','%s','%s','%s','%s','%s']);
        }

        return $inspection_id;
    }

    public static function get_with_details(int $id): ?array
    {
        global $wpdb;
        $reports   = $wpdb->prefix . 'arm_inspections';
        $templates = $wpdb->prefix . 'arm_inspection_templates';
        $items     = $wpdb->prefix . 'arm_inspection_template_items';
        $responses = $wpdb->prefix . 'arm_inspection_responses';

        $inspection = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, t.name AS template_name, t.description AS template_description
                 FROM $reports r
                 INNER JOIN $templates t ON t.id = r.template_id
                 WHERE r.id = %d",
                $id
            ),
            ARRAY_A
        );

        if (!$inspection) {
            return null;
        }

        $items_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, resp.value_text, resp.numeric_value, resp.score_value, resp.note
                 FROM $responses resp
                 INNER JOIN $items i ON i.id = resp.item_id
                 WHERE resp.inspection_id = %d
                 ORDER BY i.sort_order ASC, i.id ASC",
                $id
            ),
            ARRAY_A
        );

        $inspection['responses'] = $items_data ?: [];

        return $inspection;
    }

    public static function get_by_token(string $token): ?array
    {
        global $wpdb;
        $reports = $wpdb->prefix . 'arm_inspections';
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $reports WHERE share_token = %s", $token));
        if (!$id) {
            return null;
        }
        return self::get_with_details((int) $id);
    }

    public static function render_html(array $inspection): string
    {
        $shop_header = \ARM\PDF\Controller::shop_header_html();
        $responses   = $inspection['responses'] ?? [];

        ob_start();
        ?>
        <div class="arm-inspection-report">
            <?php echo $shop_header; ?>
            <h1 style="margin-bottom:8px;">
                <?php echo esc_html($inspection['template_name'] ?? __('Inspection Report', 'arm-repair-estimates')); ?>
            </h1>
            <p style="margin:0 0 12px; font-size:13px;">
                <?php echo esc_html__('Completed on', 'arm-repair-estimates'); ?>
                <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $inspection['created_at'] ?? '')); ?>
            </p>
            <table style="width:100%; border-collapse:collapse; margin-bottom:16px; font-size:13px;">
                <tr>
                    <td style="width:50%; vertical-align:top;">
                        <strong><?php esc_html_e('Technician', 'arm-repair-estimates'); ?>:</strong><br>
                        <?php echo esc_html($inspection['inspector_name'] ?: __('N/A', 'arm-repair-estimates')); ?><br>
                        <?php if (!empty($inspection['inspector_email'])): ?>
                            <span><?php echo esc_html($inspection['inspector_email']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="width:50%; vertical-align:top;">
                        <strong><?php esc_html_e('Customer', 'arm-repair-estimates'); ?>:</strong><br>
                        <?php echo esc_html($inspection['customer_name'] ?: __('N/A', 'arm-repair-estimates')); ?><br>
                        <?php if (!empty($inspection['customer_email'])): ?>
                            <span><?php echo esc_html($inspection['customer_email']); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($inspection['customer_phone'])): ?>
                            <span><?php echo esc_html($inspection['customer_phone']); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php if (!empty($inspection['summary'])): ?>
                <div style="margin-bottom:16px; font-size:13px;">
                    <strong><?php esc_html_e('Summary Notes', 'arm-repair-estimates'); ?>:</strong>
                    <div><?php echo wpautop(wp_kses_post($inspection['summary'])); ?></div>
                </div>
            <?php endif; ?>

            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr>
                        <th style="text-align:left; border-bottom:1px solid #ccc; padding:6px;">
                            <?php esc_html_e('Inspection Item', 'arm-repair-estimates'); ?>
                        </th>
                        <th style="text-align:left; border-bottom:1px solid #ccc; padding:6px; width:120px;">
                            <?php esc_html_e('Result', 'arm-repair-estimates'); ?>
                        </th>
                        <th style="text-align:left; border-bottom:1px solid #ccc; padding:6px;">
                            <?php esc_html_e('Notes', 'arm-repair-estimates'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($responses as $row): ?>
                    <tr>
                        <td style="border-bottom:1px solid #eee; padding:6px;">
                            <strong><?php echo esc_html($row['label']); ?></strong>
                            <?php if (!empty($row['description'])): ?>
                                <div style="color:#555; font-size:12px;">
                                    <?php echo wp_kses_post($row['description']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="border-bottom:1px solid #eee; padding:6px;">
                            <?php
                            if ($row['item_type'] === 'scale' && $row['numeric_value'] !== null) {
                                echo esc_html(number_format_i18n((float) $row['numeric_value'], 0));
                                if (!empty($row['scale_max'])) {
                                    echo ' / ' . esc_html($row['scale_max']);
                                }
                            } else {
                                echo esc_html($row['value_text'] ?? '');
                            }
                            ?>
                        </td>
                        <td style="border-bottom:1px solid #eee; padding:6px;">
                            <?php echo wpautop(wp_kses_post($row['note'] ?? '')); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ((float) $inspection['score_max'] > 0): ?>
                <p style="margin-top:16px; font-size:13px;">
                    <strong><?php esc_html_e('Score', 'arm-repair-estimates'); ?>:</strong>
                    <?php echo esc_html(number_format_i18n((float) $inspection['score_total'], 1)); ?>
                    /
                    <?php echo esc_html(number_format_i18n((float) $inspection['score_max'], 1)); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_submission(): void
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'arm_re_submit_inspection')) {
            wp_die(__('Security check failed', 'arm-repair-estimates'));
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_die(__('You must be logged in to submit an inspection form.', 'arm-repair-estimates'));
        }

        // Check if user has required role (admin or technician)
        $current_user = wp_get_current_user();
        $has_permission = false;

        // Admins always have access
        if (user_can($current_user, 'manage_options')) {
            $has_permission = true;
        } else {
            // Check for technician roles
            $roles = (array) $current_user->roles;
            if (in_array('arm_technician', $roles, true) || in_array('technician', $roles, true)) {
                $has_permission = true;
            }
        }

        if (!$has_permission) {
            wp_die(__('You do not have permission to submit inspection forms. This form is only accessible to administrators and technicians.', 'arm-repair-estimates'));
        }

        $template_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
        $template = $template_id ? Templates::find($template_id) : null;
        if (!$template) {
            wp_die(__('Invalid inspection template', 'arm-repair-estimates'));
        }

        $submission = [
            'inspector_name'  => sanitize_text_field($_POST['inspector_name'] ?? ''),
            'inspector_email' => sanitize_email($_POST['inspector_email'] ?? ''),
            'customer_name'   => sanitize_text_field($_POST['customer_name'] ?? ''),
            'customer_email'  => sanitize_email($_POST['customer_email'] ?? ''),
            'customer_phone'  => sanitize_text_field($_POST['customer_phone'] ?? ''),
            'summary'         => wp_kses_post($_POST['summary'] ?? ''),
        ];

        $responses = self::build_responses_from_request($template['items']);
        $inspection_id = self::create($template, $submission, $responses);

        self::maybe_email_customer($inspection_id);

        $inspection = self::get_with_details($inspection_id);
        $redirect = wp_get_referer() ?: home_url();
        $redirect = add_query_arg([
            'inspection_submitted' => 1,
            'inspection_id'        => $inspection_id,
            'inspection_token'     => $inspection['share_token'] ?? '',
        ], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public static function build_responses_from_request(array $items): array
    {
        $responses = [];
        foreach ($items as $item) {
            $item_id  = (int) $item['id'];
            $field    = 'item_' . $item_id;
            $notes_key= 'item_' . $item_id . '_note';
            $value    = $_POST[$field] ?? null;
            $note     = isset($_POST[$notes_key]) ? wp_kses_post($_POST[$notes_key]) : '';

            $response = [
                'item_id'       => $item_id,
                'value_text'    => '',
                'numeric_value' => null,
                'score_value'   => 0,
                'note'          => $note,
                'max_value'     => 0,
                'failed'        => false,
            ];

            if ($item['item_type'] === 'scale') {
                $min = isset($item['scale_min']) ? (int) $item['scale_min'] : 0;
                $max = isset($item['scale_max']) ? (int) $item['scale_max'] : 5;
                $numeric = is_numeric($value) ? (int) $value : null;
                if ($numeric === null) {
                    $numeric = $min;
                }
                $numeric = max($min, min($max, $numeric));
                $response['numeric_value'] = $numeric;
                $response['value_text']    = (string) $numeric;
                $response['score_value']   = $numeric;
                $response['max_value']     = $max;
                $response['failed']        = ($numeric <= $min);
            } elseif ($item['item_type'] === 'pass_fail') {
                $selected = sanitize_text_field($value ?? '');
                $pass_label = $item['pass_label'] ?: __('Pass', 'arm-repair-estimates');
                $fail_label = $item['fail_label'] ?: __('Fail', 'arm-repair-estimates');
                $pass_value = isset($item['pass_value']) ? (int) $item['pass_value'] : 1;
                $fail_value = isset($item['fail_value']) ? (int) $item['fail_value'] : 0;
                if ($selected !== $pass_label && $selected !== $fail_label) {
                    $selected = $fail_label;
                }
                $response['value_text']  = $selected;
                $response['score_value'] = ($selected === $pass_label) ? $pass_value : $fail_value;
                $response['max_value']   = max($pass_value, $fail_value);
                $response['failed']      = ($selected !== $pass_label);
            } else {
                $response['value_text'] = sanitize_text_field($value ?? '');
                $response['note']       = $response['note'] ?: $response['value_text'];
            }

            $responses[] = $response;
        }

        return $responses;
    }

    public static function maybe_email_customer(int $inspection_id): void
    {
        $inspection = self::get_with_details($inspection_id);
        if (!$inspection || empty($inspection['customer_email'])) {
            return;
        }

        $subject = sprintf(
            __('%s inspection report', 'arm-repair-estimates'),
            $inspection['template_name'] ?? __('Vehicle', 'arm-repair-estimates')
        );

        $link = add_query_arg('arm_inspection_pdf', $inspection['share_token'], home_url('/'));
        $message = sprintf(
            "%s\n\n%s\n%s",
            __('Thank you for choosing our shop. Your inspection report is ready.', 'arm-repair-estimates'),
            __('View or download your report at the link below:', 'arm-repair-estimates'),
            esc_url($link)
        );

        wp_mail($inspection['customer_email'], $subject, $message);
    }
}
