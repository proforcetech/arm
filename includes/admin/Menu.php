<?php
namespace ARM\Admin;
if (!defined('ABSPATH')) exit;

class Menu {
    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'register']);
    }

    public static function register() {
        add_menu_page(
            __('Repair Estimates','arm-repair-estimates'),
            __('Repair Estimates','arm-repair-estimates'),
            'manage_options',
            'arm-repair-estimates',
            [__CLASS__, 'render_requests_page'],
            'dashicons-clipboard',
            27
        );

        add_submenu_page('arm-repair-estimates', __('Vehicle Data','arm-repair-estimates'), __('Vehicle Data','arm-repair-estimates'),
            'manage_options','arm-repair-vehicles',[Vehicles::class,'render']);

        add_submenu_page('arm-repair-estimates', __('Service Types','arm-repair-estimates'), __('Service Types','arm-repair-estimates'),
            'manage_options','arm-repair-services',[Services::class,'render']);

        add_submenu_page('arm-repair-estimates', __('Settings','arm-repair-estimates'), __('Settings','arm-repair-estimates'),
            'manage_options','arm-repair-settings',[Settings::class,'render']);

        // Estimates UI provided by Estimates\Controller
        add_submenu_page('arm-repair-estimates', __('Estimates','arm-repair-estimates'), __('Estimates','arm-repair-estimates'),
            'manage_options','arm-repair-estimates-builder',['ARM\\Estimates\\Controller','render_admin']);

    // Corrected the callback to point to the Dashboard class
	add_submenu_page('arm-repair-estimates', __('Dashboard','arm-repair-estimates'), __('Dashboard','arm-repair-estimates'),
	    'manage_options', 'arm-dashboard', ['ARM\\Admin\\Dashboard','render_dashboard']);

add_submenu_page(
    'arm-repair-estimates',
    __('Customer Detail','arm-repair-estimates'),
    __('Customer Detail','arm-repair-estimates'),
    'manage_options',
    'arm-customer-detail',
    function() {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        \ARM\Admin\CustomerDetail::render($id);
    }
);



        // Invoices, Bundles submenus are registered in their own Controllers
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