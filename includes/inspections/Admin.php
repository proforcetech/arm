<?php
namespace ARM\Inspections;

if (!defined('ABSPATH')) exit;

class Admin
{
    public static function boot(): void
    {
        add_action('admin_post_arm_re_save_inspection_template', [__CLASS__, 'handle_save']);
        add_action('admin_post_arm_re_delete_inspection_template', [__CLASS__, 'handle_delete']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ($hook_suffix === 'arm-repair-estimates_page_arm-repair-inspections') {
            wp_enqueue_script('jquery');
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to manage inspection templates.', 'arm-repair-estimates'));
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        if ($action === 'edit') {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            self::render_edit($id);
            return;
        }

        self::render_list();
    }

    private static function render_list(): void
    {
        $templates = Templates::all();
        ?>
        <div class="wrap arm-inspections">
            <h1 class="wp-heading-inline"><?php esc_html_e('Inspection Templates', 'arm-repair-estimates'); ?></h1>
            <a href="<?php echo esc_url(add_query_arg(['action' => 'edit'], admin_url('admin.php?page=arm-repair-inspections'))); ?>" class="page-title-action">
                <?php esc_html_e('Add New', 'arm-repair-estimates'); ?>
            </a>
            <hr class="wp-header-end">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Slug', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Active', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Created', 'arm-repair-estimates'); ?></th>
                        <th><?php esc_html_e('Actions', 'arm-repair-estimates'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$templates): ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No inspection templates found yet.', 'arm-repair-estimates'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($templates as $template): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($template['name']); ?></strong><br>
                                <span class="description"><?php echo esc_html($template['description'] ?? ''); ?></span>
                            </td>
                            <td><?php echo esc_html($template['slug']); ?></td>
                            <td><?php echo !empty($template['is_active']) ? esc_html__('Yes', 'arm-repair-estimates') : esc_html__('No', 'arm-repair-estimates'); ?></td>
                            <td><?php echo esc_html(mysql2date(get_option('date_format'), $template['created_at'] ?? '')); ?></td>
                            <td>
                                <a class="button" href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => (int) $template['id']], admin_url('admin.php?page=arm-repair-inspections'))); ?>">
                                    <?php esc_html_e('Edit', 'arm-repair-estimates'); ?>
                                </a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('arm_re_delete_inspection_template'); ?>
                                    <input type="hidden" name="action" value="arm_re_delete_inspection_template">
                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                    <button type="submit" class="button-link-delete" onclick="return confirm('<?php echo esc_js(__('Delete this template?', 'arm-repair-estimates')); ?>');">
                                        <?php esc_html_e('Delete', 'arm-repair-estimates'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_edit(int $id = 0): void
    {
        $template = $id ? Templates::find($id) : [
            'id'                    => 0,
            'name'                  => '',
            'slug'                  => '',
            'description'           => '',
            'default_scoring'       => 'scale',
            'scale_min'             => 0,
            'scale_max'             => 5,
            'pass_label'            => __('Pass', 'arm-repair-estimates'),
            'fail_label'            => __('Fail', 'arm-repair-estimates'),
            'pass_value'            => 1,
            'fail_value'            => 0,
            'include_notes_default' => 0,
            'is_active'             => 1,
            'items'                 => [],
        ];

        if (!$template) {
            wp_die(__('Inspection template not found.', 'arm-repair-estimates'));
        }

        $items = $template['items'];
        if (!$items) {
            $items = [[
                'label' => '',
                'description' => '',
                'item_type' => $template['default_scoring'],
                'scale_min' => $template['scale_min'],
                'scale_max' => $template['scale_max'],
                'pass_label'=> $template['pass_label'],
                'fail_label'=> $template['fail_label'],
                'pass_value'=> $template['pass_value'],
                'fail_value'=> $template['fail_value'],
                'include_notes' => $template['include_notes_default'],
                'note_label' => __('Notes', 'arm-repair-estimates'),
            ]];
        }

        $action_url = admin_url('admin-post.php');
        ?>
        <div class="wrap arm-inspections">
            <h1><?php echo $template['id'] ? esc_html__('Edit Inspection Template', 'arm-repair-estimates') : esc_html__('Add Inspection Template', 'arm-repair-estimates'); ?></h1>
            <?php if (!empty($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Template saved successfully.', 'arm-repair-estimates'); ?></p></div>
            <?php elseif (!empty($_GET['error'])): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['error'])); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url($action_url); ?>" class="arm-inspection-template-form">
                <?php wp_nonce_field('arm_re_save_inspection_template'); ?>
                <input type="hidden" name="action" value="arm_re_save_inspection_template">
                <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="arm-template-name"><?php esc_html_e('Template Name', 'arm-repair-estimates'); ?></label></th>
                            <td><input name="template[name]" type="text" id="arm-template-name" value="<?php echo esc_attr($template['name']); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="arm-template-slug"><?php esc_html_e('Slug', 'arm-repair-estimates'); ?></label></th>
                            <td><input name="template[slug]" type="text" id="arm-template-slug" value="<?php echo esc_attr($template['slug']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Description', 'arm-repair-estimates'); ?></th>
                            <td>
                                <textarea name="template[description]" rows="3" class="large-text"><?php echo esc_textarea($template['description']); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Default Scoring Type', 'arm-repair-estimates'); ?></th>
                            <td>
                                <select name="template[default_scoring]">
                                    <option value="scale" <?php selected($template['default_scoring'], 'scale'); ?>><?php esc_html_e('Scale', 'arm-repair-estimates'); ?></option>
                                    <option value="pass_fail" <?php selected($template['default_scoring'], 'pass_fail'); ?>><?php esc_html_e('Pass / Fail', 'arm-repair-estimates'); ?></option>
                                    <option value="note" <?php selected($template['default_scoring'], 'note'); ?>><?php esc_html_e('Notes Only', 'arm-repair-estimates'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Default Scale Range', 'arm-repair-estimates'); ?></th>
                            <td>
                                <input name="template[scale_min]" type="number" value="<?php echo esc_attr($template['scale_min']); ?>" style="width:80px;"> -
                                <input name="template[scale_max]" type="number" value="<?php echo esc_attr($template['scale_max']); ?>" style="width:80px;">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Default Pass/Fail Labels', 'arm-repair-estimates'); ?></th>
                            <td>
                                <input name="template[pass_label]" type="text" value="<?php echo esc_attr($template['pass_label']); ?>" placeholder="<?php esc_attr_e('Pass', 'arm-repair-estimates'); ?>" style="margin-right:12px;">
                                <input name="template[fail_label]" type="text" value="<?php echo esc_attr($template['fail_label']); ?>" placeholder="<?php esc_attr_e('Fail', 'arm-repair-estimates'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Default Pass/Fail Scores', 'arm-repair-estimates'); ?></th>
                            <td>
                                <input name="template[pass_value]" type="number" value="<?php echo esc_attr($template['pass_value']); ?>" style="width:80px;" step="1">
                                <input name="template[fail_value]" type="number" value="<?php echo esc_attr($template['fail_value']); ?>" style="width:80px;" step="1">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Include Notes by Default', 'arm-repair-estimates'); ?></th>
                            <td>
                                <label><input type="checkbox" name="template[include_notes_default]" value="1" <?php checked($template['include_notes_default'], 1); ?>> <?php esc_html_e('Add a notes field to new items', 'arm-repair-estimates'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Active', 'arm-repair-estimates'); ?></th>
                            <td><label><input type="checkbox" name="template[is_active]" value="1" <?php checked($template['is_active'], 1); ?>> <?php esc_html_e('Template can be used in forms', 'arm-repair-estimates'); ?></label></td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Inspection Items', 'arm-repair-estimates'); ?></h2>
                <table class="widefat arm-inspection-items" id="arm-inspection-items">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Label', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Type', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Configuration', 'arm-repair-estimates'); ?></th>
                            <th><?php esc_html_e('Notes', 'arm-repair-estimates'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $index => $item): ?>
                        <?php self::render_item_row($item, $index); ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="arm-add-inspection-item"><?php esc_html_e('Add Item', 'arm-repair-estimates'); ?></button></p>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Template', 'arm-repair-estimates'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=arm-repair-inspections')); ?>" class="button-secondary button"><?php esc_html_e('Cancel', 'arm-repair-estimates'); ?></a>
                </p>
            </form>
        </div>
        <script>
        (function($){
            const rowTemplate = function(){
                return $(<?php echo wp_json_encode(self::row_template_html($template)); ?>);
            };
            $('#arm-add-inspection-item').on('click', function(){
                $('#arm-inspection-items tbody').append(rowTemplate());
            });
            $('#arm-inspection-items').on('click', '.arm-remove-item', function(){
                if ($('#arm-inspection-items tbody tr').length === 1) {
                    $('#arm-inspection-items tbody').append(rowTemplate());
                }
                $(this).closest('tr').remove();
            });
            $('#arm-inspection-items').on('change', '.arm-item-type', function(){
                const type = $(this).val();
                const row = $(this).closest('tr');
                row.find('.arm-config-scale').toggle(type === 'scale');
                row.find('.arm-config-passfail').toggle(type === 'pass_fail');
                row.find('.arm-config-note').toggle(type === 'note');
            });
            $('#arm-inspection-items .arm-item-type').trigger('change');
        })(jQuery);
        </script>
        <?php
    }

    private static function render_item_row(array $item, int $index): void
    {
        $defaults = [
            'label' => '',
            'description' => '',
            'item_type' => 'scale',
            'scale_min' => 0,
            'scale_max' => 5,
            'pass_label' => __('Pass', 'arm-repair-estimates'),
            'fail_label' => __('Fail', 'arm-repair-estimates'),
            'pass_value' => 1,
            'fail_value' => 0,
            'include_notes' => 0,
            'note_label' => __('Notes', 'arm-repair-estimates'),
        ];
        $item = wp_parse_args($item, $defaults);
        ?>
        <tr>
            <td style="width:20%;">
                <input type="text" name="items[label][]" value="<?php echo esc_attr($item['label']); ?>" class="regular-text" required>
                <textarea name="items[description][]" rows="2" class="widefat" placeholder="<?php esc_attr_e('Optional description', 'arm-repair-estimates'); ?>"><?php echo esc_textarea($item['description']); ?></textarea>
            </td>
            <td style="width:15%;">
                <select name="items[item_type][]" class="arm-item-type">
                    <option value="scale" <?php selected($item['item_type'], 'scale'); ?>><?php esc_html_e('Scale', 'arm-repair-estimates'); ?></option>
                    <option value="pass_fail" <?php selected($item['item_type'], 'pass_fail'); ?>><?php esc_html_e('Pass / Fail', 'arm-repair-estimates'); ?></option>
                    <option value="note" <?php selected($item['item_type'], 'note'); ?>><?php esc_html_e('Note Only', 'arm-repair-estimates'); ?></option>
                </select>
            </td>
            <td style="width:35%;">
                <div class="arm-config-scale" <?php if ($item['item_type'] !== 'scale') echo 'style="display:none;"'; ?>>
                    <label><?php esc_html_e('Min', 'arm-repair-estimates'); ?> <input type="number" name="items[scale_min][]" value="<?php echo esc_attr($item['scale_min']); ?>" style="width:70px;"></label>
                    <label><?php esc_html_e('Max', 'arm-repair-estimates'); ?> <input type="number" name="items[scale_max][]" value="<?php echo esc_attr($item['scale_max']); ?>" style="width:70px;"></label>
                </div>
                <div class="arm-config-passfail" <?php if ($item['item_type'] !== 'pass_fail') echo 'style="display:none;"'; ?>>
                    <label><?php esc_html_e('Pass Label', 'arm-repair-estimates'); ?> <input type="text" name="items[pass_label][]" value="<?php echo esc_attr($item['pass_label']); ?>" style="width:90px;"></label>
                    <label><?php esc_html_e('Fail Label', 'arm-repair-estimates'); ?> <input type="text" name="items[fail_label][]" value="<?php echo esc_attr($item['fail_label']); ?>" style="width:90px;"></label>
                    <label><?php esc_html_e('Pass Score', 'arm-repair-estimates'); ?> <input type="number" name="items[pass_value][]" value="<?php echo esc_attr($item['pass_value']); ?>" style="width:70px;"></label>
                    <label><?php esc_html_e('Fail Score', 'arm-repair-estimates'); ?> <input type="number" name="items[fail_value][]" value="<?php echo esc_attr($item['fail_value']); ?>" style="width:70px;"></label>
                </div>
                <div class="arm-config-note" <?php if ($item['item_type'] !== 'note') echo 'style="display:none;"'; ?>>
                    <p><?php esc_html_e('Notes only item. Responses are captured as free-form text.', 'arm-repair-estimates'); ?></p>
                </div>
            </td>
            <td style="width:20%;">
                <label>
                    <input type="checkbox" name="items[include_notes][]" value="1" <?php checked(!empty($item['include_notes'])); ?>>
                    <?php esc_html_e('Enable notes field', 'arm-repair-estimates'); ?>
                </label>
                <input type="text" name="items[note_label][]" value="<?php echo esc_attr($item['note_label']); ?>" placeholder="<?php esc_attr_e('Note label', 'arm-repair-estimates'); ?>" class="widefat" style="margin-top:6px;">
            </td>
            <td style="width:10%; text-align:center;">
                <button type="button" class="button-link arm-remove-item">&times;</button>
            </td>
        </tr>
        <?php
    }

    private static function row_template_html(array $template): string
    {
        ob_start();
        self::render_item_row([
            'label' => '',
            'description' => '',
            'item_type' => $template['default_scoring'] ?? 'scale',
            'scale_min' => $template['scale_min'] ?? 0,
            'scale_max' => $template['scale_max'] ?? 5,
            'pass_label'=> $template['pass_label'] ?? __('Pass', 'arm-repair-estimates'),
            'fail_label'=> $template['fail_label'] ?? __('Fail', 'arm-repair-estimates'),
            'pass_value'=> $template['pass_value'] ?? 1,
            'fail_value'=> $template['fail_value'] ?? 0,
            'include_notes' => $template['include_notes_default'] ?? 0,
            'note_label' => __('Notes', 'arm-repair-estimates'),
        ], 0);
        return trim(preg_replace('/\s+/', ' ', ob_get_clean()));
    }

    public static function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'arm-repair-estimates'));
        }

        check_admin_referer('arm_re_save_inspection_template');

        $template_data = $_POST['template'] ?? [];
        $items_data    = $_POST['items'] ?? [];

        try {
            $items = Templates::normalize_items_from_request($items_data, $template_data);
            $template_data['id'] = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $template_id = Templates::save($template_data, $items);
            $redirect = add_query_arg([
                'page'   => 'arm-repair-inspections',
                'action' => 'edit',
                'id'     => $template_id,
                'updated'=> 1,
            ], admin_url('admin.php'));
        } catch (\Throwable $e) {
            $redirect = add_query_arg([
                'page' => 'arm-repair-inspections',
                'action' => 'edit',
                'id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
                'error' => rawurlencode($e->getMessage()),
            ], admin_url('admin.php'));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'arm-repair-estimates'));
        }
        check_admin_referer('arm_re_delete_inspection_template');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id) {
            Templates::delete($id);
        }
        wp_safe_redirect(admin_url('admin.php?page=arm-repair-inspections'));
        exit;
    }
}
