<?php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

class Menu {
    /**
     * Hook into WordPress.
     */
    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'register']);
    }

    /**
     * Register the top level menu + all submenus in one place.
     */
    public static function register() {
        add_menu_page(
            __('Repair Estimates', 'arm-repair-estimates'),
            __('Repair Estimates', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-estimates',
            [__CLASS__, 'render_requests_page'],
            'dashicons-clipboard',
            27
        );

        $parent = 'arm-repair-estimates';

        $visible = self::ordered_visible_submenus();
        foreach ($visible as $page) {
            self::add_submenu($parent, $page);
        }

        $hidden = self::hidden_submenus();
        foreach ($hidden as $page) {
            self::add_submenu($parent, $page);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function ordered_visible_submenus(): array {
        $dashboard = Dashboard::menu_page();

        $primary = array_filter([
            \ARM\Appointments\Admin::menu_page(),
            Customers::menu_page(),
            \ARM\Estimates\Controller::menu_page(),
            \ARM\Invoices\Controller::menu_page(),
            Inventory::menu_page(),
            \ARM\Bundles\Controller::menu_page(),
            Services::menu_page(),
            Settings::menu_page(),
        ]);

        $primary = array_map(function ($page) {
            return array_merge([
                'parent_slug' => 'arm-repair-estimates',
            ], $page);
        }, $primary);

        usort($primary, static function ($a, $b) {
            return strcasecmp($a['menu_title'] ?? '', $b['menu_title'] ?? '');
        });

        $others = array_filter([
            \ARM\Appointments\Admin::availability_menu_page(),
            WarrantyClaims::menu_page(),
            [
                'page_title' => __('Vehicle Data', 'arm-repair-estimates'),
                'menu_title' => __('Vehicle Data', 'arm-repair-estimates'),
                'capability' => 'manage_options',
                'menu_slug'  => 'arm-repair-vehicles',
                'callback'   => [Vehicles::class, 'render'],
            ],
        ]);

        usort($others, static function ($a, $b) {
            return strcasecmp($a['menu_title'] ?? '', $b['menu_title'] ?? '');
        });

        $visible = array_merge([
            array_merge(['parent_slug' => 'arm-repair-estimates'], $dashboard),
        ], $primary, $others);

        foreach ($visible as $index => &$page) {
            if (!isset($page['position'])) {
                $page['position'] = $index + 1;
            }
        }
        unset($page);

        return $visible;
    }

    /**
     * Hidden submenu pages (blank menu title but routable).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function hidden_submenus(): array {
        return array_filter([
            Customers::customer_detail_menu(),
        ]);
    }

    /**
     * Helper wrapper for add_submenu_page to enforce consistent keys.
     */
    private static function add_submenu(string $parent, array $args): void {
        $defaults = [
            'parent_slug' => $parent,
            'page_title'  => '',
            'menu_title'  => '',
            'capability'  => 'manage_options',
            'menu_slug'   => '',
            'callback'    => null,
        ];
        $page = array_merge($defaults, $args);

        add_submenu_page(
            $page['parent_slug'],
            $page['page_title'],
            $page['menu_title'],
            $page['capability'],
            $page['menu_slug'],
            $page['callback'],
            $page['position'] ?? null
        );
    }

    // Simple list of requests (migrated later if desired)
    public static function render_requests_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_estimate_requests';
        $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC LIMIT 50");
        ?>
        <div class="wrap">
          <h1><?php _e('Estimate Requests','arm-repair-estimates'); ?></h1>
          <table class="widefat striped">
            <thead><tr>
              <th><?php _e('Date'); ?></th>
              <th><?php _e('Customer'); ?></th>
              <th><?php _e('Service Type'); ?></th>
              <th><?php _e('Actions'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r):
                $builder_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&from_request='.(int)$r->id);
            ?>
              <tr>
                <td><?php echo esc_html($r->created_at); ?></td>
                <td><?php echo esc_html("{$r->first_name} {$r->last_name}"); ?><br><?php echo esc_html($r->email); ?></td>
                <td><?php echo esc_html($r->service_type_id); ?></td>
                <td><a class="button button-primary" href="<?php echo esc_url($builder_url); ?>"><?php _e('Create Estimate','arm-repair-estimates'); ?></a></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4"><?php _e('No submissions yet.','arm-repair-estimates'); ?></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}