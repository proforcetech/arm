<?php
// file: includes/admin/WarrantyClaims.php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

/**
 * Warranty claims list + detail.
 * Why: simple triage view.
 */
final class WarrantyClaims {
    public static function boot(): void {
    }

    public static function menu_page(): array {
        return [
            'page_title' => __('Warranty Claims','arm-repair-estimates'),
            'menu_title' => __('Warranty Claims','arm-repair-estimates'),
            'capability' => 'manage_options',
            'menu_slug'  => 'arm-warranty-claims',
            'callback'   => [__CLASS__, 'render_admin'],
        ];
    }

    public static function render_admin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.','arm-repair-estimates'));
        }
        $view = isset($_GET['view']) ? (int) $_GET['view'] : 0;
        if ($view > 0) {
            self::render_detail($view);
        } else {
            self::render_list();
        }
    }

    private static function render_list(): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_warranty_claims';

        $page     = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 20;
        $offset   = ($page - 1) * $per_page;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, invoice_id, first_name, last_name, email, subject, status, created_at
             FROM $tbl ORDER BY created_at DESC LIMIT %d OFFSET %d",
             $per_page, $offset
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tbl");
        $pages = max(1, (int) ceil($total / $per_page));

        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Warranty Claims','arm-repair-estimates'); ?></h1>
          <table class="widefat striped">
            <thead>
              <tr>
                <th><?php echo esc_html__('ID','arm-repair-estimates'); ?></th>
                <th><?php echo esc_html__('Invoice','arm-repair-estimates'); ?></th>
                <th><?php echo esc_html__('Customer','arm-repair-estimates'); ?></th>
                <th><?php echo esc_html__('Subject','arm-repair-estimates'); ?></th>
                <th><?php echo esc_html__('Status','arm-repair-estimates'); ?></th>
                <th><?php echo esc_html__('Created','arm-repair-estimates'); ?></th>
                <th><?php echo esc_html__('Action','arm-repair-estimates'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rows): foreach ($rows as $r): 
                  $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                  $view = admin_url('admin.php?page=arm-warranty-claims&view=' . (int) $r->id);
              ?>
                <tr>
                  <td>#<?php echo (int) $r->id; ?></td>
                  <td><?php echo (int) $r->invoice_id; ?></td>
                  <td><?php echo esc_html($name); ?><br><?php echo esc_html($r->email); ?></td>
                  <td><?php echo esc_html($r->subject); ?></td>
                  <td><?php echo esc_html($r->status); ?></td>
                  <td><?php echo esc_html($r->created_at); ?></td>
                  <td><a class="button button-small" href="<?php echo esc_url($view); ?>"><?php echo esc_html__('View','arm-repair-estimates'); ?></a></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="7"><?php echo esc_html__('No claims found.','arm-repair-estimates'); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>

          <?php if ($pages > 1): ?>
            <div class="tablenav"><div class="tablenav-pages">
              <?php echo paginate_links([
                  'base'      => add_query_arg('paged', '%#%'),
                  'format'    => '',
                  'prev_text' => __('&laquo;'),
                  'next_text' => __('&raquo;'),
                  'total'     => $pages,
                  'current'   => $page,
              ]); ?>
            </div></div>
          <?php endif; ?>
        </div>
        <?php
    }

    private static function render_detail(int $id): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_warranty_claims';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $id));
        if (!$row) {
            echo '<div class="wrap"><h1>' . esc_html__('Warranty Claims','arm-repair-estimates') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Claim not found.','arm-repair-estimates') . '</p></div></div>';
            return;
        }
        $back = admin_url('admin.php?page=arm-warranty-claims');
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Warranty Claim #','arm-repair-estimates') . (int) $row->id; ?></h1>
          <table class="widefat striped" style="max-width:900px">
            <tbody>
              <tr><th><?php echo esc_html__('Invoice','arm-repair-estimates'); ?></th><td>#<?php echo (int) $row->invoice_id; ?></td></tr>
              <tr><th><?php echo esc_html__('Customer','arm-repair-estimates'); ?></th><td><?php echo esc_html(trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''))); ?> &lt;<?php echo esc_html($row->email); ?>&gt;</td></tr>
              <tr><th><?php echo esc_html__('Subject','arm-repair-estimates'); ?></th><td><?php echo esc_html($row->subject); ?></td></tr>
              <tr><th><?php echo esc_html__('Status','arm-repair-estimates'); ?></th><td><?php echo esc_html($row->status); ?></td></tr>
              <tr><th><?php echo esc_html__('Message','arm-repair-estimates'); ?></th><td><pre style="white-space:pre-wrap"><?php echo esc_html($row->message ?? ''); ?></pre></td></tr>
              <tr><th><?php echo esc_html__('Created','arm-repair-estimates'); ?></th><td><?php echo esc_html($row->created_at); ?></td></tr>
            </tbody>
          </table>
          <p><a class="button" href="<?php echo esc_url($back); ?>">&larr; <?php echo esc_html__('Back','arm-repair-estimates'); ?></a></p>
        </div>
        <?php
    }
}
