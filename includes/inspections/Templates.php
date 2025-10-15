<?php
namespace ARM\Inspections;

if (!defined('ABSPATH')) exit;

class Templates
{
    public static function all(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_inspection_templates';
        return (array) $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC", ARRAY_A);
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_inspection_templates';
        $template = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        if (!$template) {
            return null;
        }
        $template['items'] = self::items_for_template($id);
        return $template;
    }

    public static function find_by_slug(string $slug): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_inspection_templates';
        $template = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE slug = %s", $slug), ARRAY_A);
        if (!$template) {
            return null;
        }
        $template['items'] = self::items_for_template((int) $template['id']);
        return $template;
    }

    public static function items_for_template(int $template_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arm_inspection_template_items';
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE template_id = %d ORDER BY sort_order ASC, id ASC", $template_id),
            ARRAY_A
        );
        return $rows ? $rows : [];
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $templates = $wpdb->prefix . 'arm_inspection_templates';
        $items     = $wpdb->prefix . 'arm_inspection_template_items';
        $wpdb->delete($templates, ['id' => $id], ['%d']);
        $wpdb->delete($items, ['template_id' => $id], ['%d']);
    }

    public static function save(array $template, array $items): int
    {
        global $wpdb;
        $templates = $wpdb->prefix . 'arm_inspection_templates';
        $items_tbl = $wpdb->prefix . 'arm_inspection_template_items';
        $now       = current_time('mysql');

        $data = [
            'name'                  => sanitize_text_field($template['name'] ?? ''),
            'slug'                  => sanitize_title($template['slug'] ?? ($template['name'] ?? '')),
            'description'           => wp_kses_post($template['description'] ?? ''),
            'default_scoring'       => sanitize_key($template['default_scoring'] ?? 'scale'),
            'scale_min'             => isset($template['scale_min']) ? (int) $template['scale_min'] : null,
            'scale_max'             => isset($template['scale_max']) ? (int) $template['scale_max'] : null,
            'pass_label'            => sanitize_text_field($template['pass_label'] ?? ''),
            'fail_label'            => sanitize_text_field($template['fail_label'] ?? ''),
            'pass_value'            => isset($template['pass_value']) ? (int) $template['pass_value'] : null,
            'fail_value'            => isset($template['fail_value']) ? (int) $template['fail_value'] : null,
            'include_notes_default' => !empty($template['include_notes_default']) ? 1 : 0,
            'is_active'             => !empty($template['is_active']) ? 1 : 0,
            'updated_at'            => $now,
        ];

        if (!in_array($data['default_scoring'], ['scale', 'pass_fail', 'note'], true)) {
            $data['default_scoring'] = 'scale';
        }

        if ($data['name'] === '') {
            throw new \InvalidArgumentException(__('Template name is required', 'arm-repair-estimates'));
        }

        if ($data['slug'] === '') {
            $data['slug'] = sanitize_title($data['name']);
        }

        $existing_id = !empty($template['id']) ? (int) $template['id'] : 0;

        if ($existing_id > 0) {
            $wpdb->update(
                $templates,
                $data,
                ['id' => $existing_id],
                ['%s','%s','%s','%s','%d','%d','%s','%s','%d','%d','%d','%d','%s'],
                ['%d']
            );
            $template_id = $existing_id;
        } else {
            $data['created_at'] = $now;
            $wpdb->insert(
                $templates,
                $data,
                ['%s','%s','%s','%s','%d','%d','%s','%s','%d','%d','%d','%d','%s','%s']
            );
            $template_id = (int) $wpdb->insert_id;
        }

        $wpdb->delete($items_tbl, ['template_id' => $template_id], ['%d']);

        $order = 0;
        foreach ($items as $item) {
            $label = sanitize_text_field($item['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $order++;
            $item_type = sanitize_key($item['item_type'] ?? 'scale');
            if (!in_array($item_type, ['scale', 'pass_fail', 'note'], true)) {
                $item_type = 'scale';
            }

            $row = [
                'template_id'   => $template_id,
                'label'         => $label,
                'description'   => wp_kses_post($item['description'] ?? ''),
                'item_type'     => $item_type,
                'scale_min'     => isset($item['scale_min']) ? (int) $item['scale_min'] : null,
                'scale_max'     => isset($item['scale_max']) ? (int) $item['scale_max'] : null,
                'pass_label'    => sanitize_text_field($item['pass_label'] ?? ''),
                'fail_label'    => sanitize_text_field($item['fail_label'] ?? ''),
                'pass_value'    => isset($item['pass_value']) ? (int) $item['pass_value'] : null,
                'fail_value'    => isset($item['fail_value']) ? (int) $item['fail_value'] : null,
                'include_notes' => !empty($item['include_notes']) ? 1 : 0,
                'note_label'    => sanitize_text_field($item['note_label'] ?? ''),
                'sort_order'    => $order,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];

            $formats = ['%d','%s','%s','%s','%d','%d','%s','%s','%d','%d','%d','%s','%d','%s','%s'];
            $wpdb->insert($items_tbl, $row, $formats);
        }

        return $template_id;
    }

    public static function normalize_items_from_request(array $request, array $defaults = []): array
    {
        $labels = $request['label'] ?? [];
        if (!is_array($labels)) {
            return [];
        }
        $items = [];
        $count = count($labels);
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'label'        => $labels[$i] ?? '',
                'description'  => $request['description'][$i] ?? '',
                'item_type'    => $request['item_type'][$i] ?? ($defaults['default_scoring'] ?? 'scale'),
                'scale_min'    => $request['scale_min'][$i] ?? ($defaults['scale_min'] ?? 0),
                'scale_max'    => $request['scale_max'][$i] ?? ($defaults['scale_max'] ?? 5),
                'pass_label'   => $request['pass_label'][$i] ?? ($defaults['pass_label'] ?? ''),
                'fail_label'   => $request['fail_label'][$i] ?? ($defaults['fail_label'] ?? ''),
                'pass_value'   => $request['pass_value'][$i] ?? ($defaults['pass_value'] ?? 1),
                'fail_value'   => $request['fail_value'][$i] ?? ($defaults['fail_value'] ?? 0),
                'include_notes'=> isset($request['include_notes'][$i]) ? $request['include_notes'][$i] : 0,
                'note_label'   => $request['note_label'][$i] ?? '',
            ];
        }
        return $items;
    }
}
