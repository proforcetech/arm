<?php
namespace ARM\Inspections;

if (!defined('ABSPATH')) exit;

class PublicView
{
    public static function boot(): void
    {
        add_shortcode('arm_re_inspection_form', [__CLASS__, 'render_shortcode']);
    }

    public static function render_shortcode($atts = []): string
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink());
            return '<div class="arm-inspection-form arm-notice arm-info" style="border:1px solid #2271b1; padding:12px; margin-bottom:16px; background:#f0f6fc;">' .
                   sprintf(
                       __('Please <a href="%s">log in</a> to access the inspection form.', 'arm-repair-estimates'),
                       esc_url($login_url)
                   ) .
                   '</div>';
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
            return '<div class="arm-inspection-form arm-notice arm-error" style="border:1px solid #d63638; padding:12px; margin-bottom:16px; background:#fcf0f1;">' .
                   esc_html__('You do not have permission to access the inspection form. This form is only accessible to administrators and technicians.', 'arm-repair-estimates') .
                   '</div>';
        }

        $atts = shortcode_atts([
            'template' => '',
        ], $atts, 'arm_re_inspection_form');

        $template = null;
        if ($atts['template'] !== '') {
            if (is_numeric($atts['template'])) {
                $template = Templates::find((int) $atts['template']);
            } else {
                $template = Templates::find_by_slug(sanitize_key($atts['template']));
            }
        }

        if (!$template) {
            $templates = Templates::all();
            foreach ($templates as $candidate) {
                if (!empty($candidate['is_active'])) {
                    $template = Templates::find((int) $candidate['id']);
                    break;
                }
            }
        }

        if (!$template) {
            return '<div class="arm-inspection-form">' . esc_html__('No inspection templates are available.', 'arm-repair-estimates') . '</div>';
        }

        $success_message = '';
        $share_token = '';
        if (!empty($_GET['inspection_submitted'])) {
            $success_message = __('Inspection submitted successfully.', 'arm-repair-estimates');
            if (!empty($_GET['inspection_token'])) {
                $share_token = sanitize_text_field(wp_unslash($_GET['inspection_token']));
            }
        }

        ob_start();
        ?>
        <div class="arm-inspection-form">
            <?php if ($success_message): ?>
                <div class="arm-notice arm-success" style="border:1px solid #46b450; padding:12px; margin-bottom:16px; background:#f0fff0;">
                    <?php echo esc_html($success_message); ?>
                    <?php if ($share_token): ?>
                        <?php $link = add_query_arg('arm_inspection_pdf', $share_token, home_url('/')); ?>
                        <br><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Download your PDF report', 'arm-repair-estimates'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="arm-inspection-fields">
                <?php wp_nonce_field('arm_re_submit_inspection'); ?>
                <input type="hidden" name="action" value="arm_re_submit_inspection">
                <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
                <div class="arm-field-group">
                    <label><?php esc_html_e('Technician name', 'arm-repair-estimates'); ?></label>
                    <input type="text" name="inspector_name" required>
                </div>
                <div class="arm-field-group">
                    <label><?php esc_html_e('Technician email', 'arm-repair-estimates'); ?></label>
                    <input type="email" name="inspector_email" required>
                </div>
                <div class="arm-field-group">
                    <label><?php esc_html_e('Customer name', 'arm-repair-estimates'); ?></label>
                    <input type="text" name="customer_name" required>
                </div>
                <div class="arm-field-group">
                    <label><?php esc_html_e('Customer email', 'arm-repair-estimates'); ?></label>
                    <input type="email" name="customer_email">
                </div>
                <div class="arm-field-group">
                    <label><?php esc_html_e('Customer phone', 'arm-repair-estimates'); ?></label>
                    <input type="text" name="customer_phone">
                </div>

                <h3><?php echo esc_html($template['name']); ?></h3>
                <?php if (!empty($template['description'])): ?>
                    <div class="description"><?php echo wpautop(wp_kses_post($template['description'])); ?></div>
                <?php endif; ?>

                <div class="arm-items">
                    <?php foreach ($template['items'] as $item): ?>
                        <div class="arm-item">
                            <label><strong><?php echo esc_html($item['label']); ?></strong></label>
                            <?php if (!empty($item['description'])): ?>
                                <div class="description"><?php echo wpautop(wp_kses_post($item['description'])); ?></div>
                            <?php endif; ?>
                            <?php echo self::render_item_input($item); ?>
                            <?php if (!empty($item['include_notes'])): ?>
                                <label class="arm-note-label"><?php echo esc_html($item['note_label'] ?: __('Notes', 'arm-repair-estimates')); ?></label>
                                <textarea name="item_<?php echo (int) $item['id']; ?>_note" rows="2"></textarea>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="arm-field-group">
                    <label><?php esc_html_e('Summary notes', 'arm-repair-estimates'); ?></label>
                    <textarea name="summary" rows="4"></textarea>
                </div>

                <button type="submit" class="arm-submit button button-primary"><?php esc_html_e('Submit inspection', 'arm-repair-estimates'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_item_input(array $item): string
    {
        $field = 'item_' . (int) $item['id'];
        if ($item['item_type'] === 'scale') {
            $min = isset($item['scale_min']) ? (int) $item['scale_min'] : 0;
            $max = isset($item['scale_max']) ? (int) $item['scale_max'] : 5;
            $options = '';
            for ($i = $min; $i <= $max; $i++) {
                $options .= sprintf('<option value="%1$d">%1$d</option>', $i);
            }
            return sprintf('<select name="%1$s" required>%2$s</select>', esc_attr($field), $options);
        }
        if ($item['item_type'] === 'pass_fail') {
            $pass_label = $item['pass_label'] ?: __('Pass', 'arm-repair-estimates');
            $fail_label = $item['fail_label'] ?: __('Fail', 'arm-repair-estimates');
            return sprintf(
                '<select name="%1$s" required><option value="%2$s">%3$s</option><option value="%4$s">%5$s</option></select>',
                esc_attr($field),
                esc_attr($pass_label),
                esc_html($pass_label),
                esc_attr($fail_label),
                esc_html($fail_label)
            );
        }
        return sprintf('<textarea name="%1$s" rows="2"></textarea>', esc_attr($field));
    }
}
