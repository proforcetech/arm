<?php
namespace ARM\Admin;

use ARM\Accounting\Transactions;

if (!defined('ABSPATH')) exit;

class Menu
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'register']);
    }

    public static function register(): void
    {
        $accounting_cap = Transactions::capability();

        add_menu_page(
            __('Repair Estimates', 'arm-repair-estimates'),
            __('Repair Estimates', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-estimates',
            [__CLASS__, 'render_requests_page'],
            'dashicons-clipboard',
            27
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Vehicle Data', 'arm-repair-estimates'),
            __('Vehicle Data', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-vehicles',
            [Vehicles::class, 'render']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Service Types', 'arm-repair-estimates'),
            __('Service Types', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-services',
            [Services::class, 'render']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Income', 'arm-repair-estimates'),
            __('Income', 'arm-repair-estimates'),
            $accounting_cap,
            'arm-accounting-income',
            [Income::class, 'render']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Expenses', 'arm-repair-estimates'),
            __('Expenses', 'arm-repair-estimates'),
            $accounting_cap,
            'arm-accounting-expenses',
            [Expenses::class, 'render']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Purchases', 'arm-repair-estimates'),
            __('Purchases', 'arm-repair-estimates'),
            $accounting_cap,
            'arm-accounting-purchases',
            [Purchases::class, 'render']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Financial Reports', 'arm-repair-estimates'),
            __('Financial Reports', 'arm-repair-estimates'),
            $accounting_cap,
            'arm-accounting-reports',
            [FinancialReports::class, 'render']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Settings', 'arm-repair-estimates'),
            __('Settings', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-settings',
            [Settings::class, 'render']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Estimates', 'arm-repair-estimates'),
            __('Estimates', 'arm-repair-estimates'),
            'manage_options',
            'arm-repair-estimates-builder',
            ['ARM\\Estimates\\Controller', 'render_admin']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Dashboard', 'arm-repair-estimates'),
            __('Dashboard', 'arm-repair-estimates'),
            'manage_options',
            'arm-dashboard',
            [Dashboard::class, 'render_dashboard']
        );

        add_submenu_page(
            'arm-repair-estimates',
            __('Customer Detail', 'arm-repair-estimates'),
            __('Customer Detail', 'arm-repair-estimates'),
            'manage_options',
            'arm-customer-detail',
            function () {
                $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                CustomerDetail::render($id);
            }
        );
        
    }

    public static function render_requests_page(): void
    {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'arm_estimate_requests';
        $rows  = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 50");
        ?>
        <div class="wrap">
          <h1><?php _e('Estimate Requests', 'arm-repair-estimates'); ?></h1>
          <table class="widefat striped">
            <thead><tr>
              <th><?php _e('Date'); ?></th>
              <th><?php _e('Customer'); ?></th>
              <th><?php _e('Service Type'); ?></th>
              <th><?php _e('Actions'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $row):
                $builder_url = admin_url('admin.php?page=arm-repair-estimates-builder&action=new&from_request=' . (int) $row->id);
            ?>
              <tr>
                <td><?php echo esc_html($row->created_at); ?></td>
                <td><?php echo esc_html("{$row->first_name} {$row->last_name}"); ?><br><?php echo esc_html($row->email); ?></td>
                <td><?php echo esc_html($row->service_type_id); ?></td>
                <td><a class="button button-primary" href="<?php echo esc_url($builder_url); ?>"><?php _e('Create Estimate', 'arm-repair-estimates'); ?></a></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4"><?php _e('No submissions yet.', 'arm-repair-estimates'); ?></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}
