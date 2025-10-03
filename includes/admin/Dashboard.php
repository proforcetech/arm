<?php
namespace ARM\Admin;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/DashboardMetrics.php';

final class Dashboard
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'arm-repair-estimates',
            __('Dashboard', 'arm-repair-estimates'),
            __('Dashboard', 'arm-repair-estimates'),
            'manage_options',
            'arm-dashboard',
            [__CLASS__, 'render_dashboard']
        );
    }

    public static function assets(string $hook): void
    {
        if (strpos($hook, 'arm-dashboard') === false) {
            return;
        }
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    }

    public static function render_dashboard(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        $estimates = DashboardMetrics::estimate_counts($wpdb);
        $invoices  = DashboardMetrics::invoice_counts($wpdb);
        $inventory = DashboardMetrics::inventory_value($wpdb);
        $warranty  = DashboardMetrics::warranty_claim_counts($wpdb);
        $sms       = DashboardMetrics::sms_totals($wpdb);

        $invoiceSeries  = DashboardMetrics::invoice_monthly_totals($wpdb);
        $estimateSeries = DashboardMetrics::estimate_trends($wpdb);

        $smsLabels = [];
        $smsSent = [];
        $smsDelivered = [];
        $smsFailed = [];
        foreach ($sms['channels'] as $channel => $counts) {
            $smsLabels[]    = $channel;
            $smsSent[]      = (int) $counts['sent'];
            $smsDelivered[] = (int) $counts['delivered'];
            $smsFailed[]    = (int) $counts['failed'];
        }

        $currencyValue = number_format_i18n((float) $inventory['value'], 2);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Repair Shop Dashboard', 'arm-repair-estimates'); ?></h1>

            <h2><?php esc_html_e('Estimates', 'arm-repair-estimates'); ?></h2>
            <ul class="arm-stats">
                <li><?php echo esc_html((int) $estimates['pending']); ?> <?php esc_html_e('Pending', 'arm-repair-estimates'); ?></li>
                <li><?php echo esc_html((int) $estimates['approved']); ?> <?php esc_html_e('Approved', 'arm-repair-estimates'); ?></li>
                <li><?php echo esc_html((int) $estimates['rejected']); ?> <?php esc_html_e('Rejected', 'arm-repair-estimates'); ?></li>
            </ul>

            <h2><?php esc_html_e('Invoices', 'arm-repair-estimates'); ?></h2>
            <ul class="arm-stats">
                <li><?php esc_html_e('Total', 'arm-repair-estimates'); ?>: <?php echo esc_html((int) $invoices['total']); ?></li>
                <li><?php esc_html_e('Paid', 'arm-repair-estimates'); ?>: <?php echo esc_html((int) $invoices['paid']); ?></li>
                <li><?php esc_html_e('Unpaid', 'arm-repair-estimates'); ?>: <?php echo esc_html((int) $invoices['unpaid']); ?></li>
                <li><?php esc_html_e('Voided', 'arm-repair-estimates'); ?>: <?php echo esc_html((int) $invoices['void']); ?></li>
                <li><?php esc_html_e('Average Paid Invoice', 'arm-repair-estimates'); ?>: <?php echo esc_html(number_format_i18n((float) $invoices['avg_paid'], 2)); ?></li>
                <li><?php esc_html_e('Total Paid', 'arm-repair-estimates'); ?>: <?php echo esc_html(number_format_i18n((float) $invoices['sum_paid'], 2)); ?></li>
                <li><?php esc_html_e('Total Unpaid', 'arm-repair-estimates'); ?>: <?php echo esc_html(number_format_i18n((float) $invoices['sum_unpaid'], 2)); ?></li>
                <li><?php esc_html_e('Total Sales Tax', 'arm-repair-estimates'); ?>: <?php echo esc_html(number_format_i18n((float) $invoices['sum_tax'], 2)); ?></li>
            </ul>

            <h2><?php esc_html_e('Inventory', 'arm-repair-estimates'); ?></h2>
            <ul class="arm-stats">
                <li><?php esc_html_e('On-Hand Inventory Value', 'arm-repair-estimates'); ?>: <?php echo esc_html($inventory['exists'] ? $currencyValue : __('N/A', 'arm-repair-estimates')); ?></li>
            </ul>

            <h2><?php esc_html_e('Warranty Claims', 'arm-repair-estimates'); ?></h2>
            <ul class="arm-stats">
                <li><?php esc_html_e('Open', 'arm-repair-estimates'); ?>: <?php echo esc_html((int) $warranty['open']); ?></li>
                <li><?php esc_html_e('Resolved', 'arm-repair-estimates'); ?>: <?php echo esc_html((int) $warranty['resolved']); ?></li>
            </ul>

            <h2><?php esc_html_e('SMS Communications', 'arm-repair-estimates'); ?></h2>
            <ul class="arm-stats">
                <?php if ($sms['channels']) : ?>
                    <?php foreach ($sms['channels'] as $channel => $counts) : ?>
                        <li>
                            <strong><?php echo esc_html($channel); ?></strong>:
                            <?php printf(
                                /* translators: 1: sent count, 2: delivered count, 3: failed count */
                                esc_html__('Sent %1$s / Delivered %2$s / Failed %3$s', 'arm-repair-estimates'),
                                esc_html((int) $counts['sent']),
                                esc_html((int) $counts['delivered']),
                                esc_html((int) $counts['failed'])
                            ); ?>
                        </li>
                    <?php endforeach; ?>
                <?php else : ?>
                    <li><?php esc_html_e('No SMS activity recorded yet.', 'arm-repair-estimates'); ?></li>
                <?php endif; ?>
            </ul>

            <h2><?php esc_html_e('Trends', 'arm-repair-estimates'); ?></h2>
            <div style="max-width:800px;">
                <canvas id="arm_invoice_chart"></canvas>
            </div>
            <div style="max-width:800px;margin-top:2em;">
                <canvas id="arm_estimate_chart"></canvas>
            </div>
            <?php if ($sms['channels']) : ?>
                <div style="max-width:800px;margin-top:2em;">
                    <canvas id="arm_sms_chart"></canvas>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Quick Links', 'arm-repair-estimates'); ?></h2>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=arm-dashboard')); ?>"><?php esc_html_e('Dashboard', 'arm-repair-estimates'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=arm-appointments')); ?>"><?php esc_html_e('Appointments', 'arm-repair-estimates'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=arm-repair-estimates')); ?>"><?php esc_html_e('Estimates', 'arm-repair-estimates'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=arm-repair-invoices')); ?>"><?php esc_html_e('Invoices', 'arm-repair-estimates'); ?></a>
            </p>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const invoiceCtx = document.getElementById('arm_invoice_chart');
            if (invoiceCtx) {
                new Chart(invoiceCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo wp_json_encode($invoiceSeries['labels']); ?>,
                        datasets: [{
                            label: '<?php echo esc_js(__('Invoice Totals', 'arm-repair-estimates')); ?>',
                            data: <?php echo wp_json_encode($invoiceSeries['totals']); ?>,
                            backgroundColor: 'rgba(75, 192, 192, 0.6)'
                        }]
                    },
                    options: {scales: {y: {beginAtZero: true}}}
                });
            }

            const estimateCtx = document.getElementById('arm_estimate_chart');
            if (estimateCtx) {
                new Chart(estimateCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: <?php echo wp_json_encode($estimateSeries['labels']); ?>,
                        datasets: [
                            {
                                label: '<?php echo esc_js(__('Approved', 'arm-repair-estimates')); ?>',
                                data: <?php echo wp_json_encode($estimateSeries['approved']); ?>,
                                borderColor: 'rgba(54, 162, 235, 1)',
                                fill: false
                            },
                            {
                                label: '<?php echo esc_js(__('Rejected', 'arm-repair-estimates')); ?>',
                                data: <?php echo wp_json_encode($estimateSeries['rejected']); ?>,
                                borderColor: 'rgba(255, 99, 132, 1)',
                                fill: false
                            }
                        ]
                    },
                    options: {scales: {y: {beginAtZero: true}}}
                });
            }

            const smsCtx = document.getElementById('arm_sms_chart');
            if (smsCtx) {
                new Chart(smsCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo wp_json_encode($smsLabels); ?>,
                        datasets: [
                            {
                                label: '<?php echo esc_js(__('Sent', 'arm-repair-estimates')); ?>',
                                data: <?php echo wp_json_encode($smsSent); ?>,
                                backgroundColor: 'rgba(54, 162, 235, 0.5)'
                            },
                            {
                                label: '<?php echo esc_js(__('Delivered', 'arm-repair-estimates')); ?>',
                                data: <?php echo wp_json_encode($smsDelivered); ?>,
                                backgroundColor: 'rgba(75, 192, 92, 0.5)'
                            },
                            {
                                label: '<?php echo esc_js(__('Failed', 'arm-repair-estimates')); ?>',
                                data: <?php echo wp_json_encode($smsFailed); ?>,
                                backgroundColor: 'rgba(255, 99, 132, 0.5)'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            x: {stacked: true},
                            y: {beginAtZero: true, stacked: true}
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }
}
