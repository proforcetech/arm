<?php
namespace ARM\Admin;

class Dashboard {
    public static function boot() {
        add_submenu_page(
            'arm-repair-estimates',
            __('Dashboard','arm-repair-estimates'),
            __('Dashboard','arm-repair-estimates'),
            'manage_options',
            'arm-dashboard',
            [__CLASS__,'render_dashboard']
        );
add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);

    }

	public static function assets($hook) {
	    if (strpos($hook,'arm-dashboard')===false) return;
	    wp_enqueue_script('chart-js','https://cdn.jsdelivr.net/npm/chart.js',[],null,true);
	}


    public static function render_dashboard() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $eT=$wpdb->prefix.'arm_estimates';
        $iT=$wpdb->prefix.'arm_invoices';

        // Estimates
        $est_pending=$wpdb->get_var("SELECT COUNT(*) FROM $eT WHERE status='PENDING'");
        $est_accepted=$wpdb->get_var("SELECT COUNT(*) FROM $eT WHERE status='APPROVED'");
        $est_rejected=$wpdb->get_var("SELECT COUNT(*) FROM $eT WHERE status='REJECTED'");

        // Invoices
        $inv_total=$wpdb->get_var("SELECT COUNT(*) FROM $iT");
        $inv_paid=$wpdb->get_var("SELECT COUNT(*) FROM $iT WHERE status='PAID'");
        $inv_unpaid=$wpdb->get_var("SELECT COUNT(*) FROM $iT WHERE status='UNPAID'");
        $inv_void=$wpdb->get_var("SELECT COUNT(*) FROM $iT WHERE status='VOID'");

        $avg_invoice=$wpdb->get_var("SELECT AVG(total) FROM $iT WHERE status='PAID'");
        $total_paid=$wpdb->get_var("SELECT SUM(total) FROM $iT WHERE status='PAID'");
        $total_unpaid=$wpdb->get_var("SELECT SUM(total) FROM $iT WHERE status='UNPAID'");
        $total_tax=$wpdb->get_var("SELECT SUM(tax_amount) FROM $iT WHERE status='PAID'");

// Monthly invoice totals (last 6 months)
$rows=$wpdb->get_results("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, SUM(total) as total
    FROM $iT WHERE status='PAID'
    GROUP BY ym ORDER BY ym DESC LIMIT 6
");
$months=[]; $totals=[];
foreach (array_reverse($rows) as $r) {
    $months[]=$r->ym;
    $totals[]=(float)$r->total;
}

// Estimate approvals vs rejections (last 6 months)
$rows=$wpdb->get_results("
    SELECT DATE_FORMAT(created_at,'%Y-%m') as ym,
    SUM(CASE WHEN status='APPROVED' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status='REJECTED' THEN 1 ELSE 0 END) as rejected
    FROM $eT GROUP BY ym ORDER BY ym DESC LIMIT 6
");
$est_months=[]; $approved=[]; $rejected=[];
foreach (array_reverse($rows) as $r) {
    $est_months[]=$r->ym;
    $approved[]=(int)$r->approved;
    $rejected[]=(int)$r->rejected;
}


        ?>
<h2><?php _e('Trends','arm-repair-estimates'); ?></h2>
<div style="max-width:800px;">
  <canvas id="arm_invoice_chart"></canvas>
</div>
<div style="max-width:800px;margin-top:2em;">
  <canvas id="arm_estimate_chart"></canvas>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const ctx1=document.getElementById('arm_invoice_chart').getContext('2d');
  new Chart(ctx1,{
    type:'bar',
    data:{
      labels: <?php echo json_encode($months); ?>,
      datasets:[{
        label:'Invoice Totals',
        data: <?php echo json_encode($totals); ?>,
        backgroundColor:'rgba(75, 192, 192, 0.6)'
      }]
    },
    options:{scales:{y:{beginAtZero:true}}}
  });

  const ctx2=document.getElementById('arm_estimate_chart').getContext('2d');
  new Chart(ctx2,{
    type:'line',
    data:{
      labels: <?php echo json_encode($est_months); ?>,
      datasets:[
        {
          label:'Approved',
          data: <?php echo json_encode($approved); ?>,
          borderColor:'rgba(54, 162, 235, 1)',
          fill:false
        },
        {
          label:'Rejected',
          data: <?php echo json_encode($rejected); ?>,
          borderColor:'rgba(255, 99, 132, 1)',
          fill:false
        }
      ]
    },
    options:{scales:{y:{beginAtZero:true}}}
  });
});
</script>

        <div class="wrap">
          <h1><?php _e('Repair Shop Dashboard','arm-repair-estimates'); ?></h1>
          
          <h2><?php _e('Estimates','arm-repair-estimates'); ?></h2>
          <ul class="arm-stats">
            <li><?php echo esc_html($est_pending); ?> Pending</li>
            <li><?php echo esc_html($est_accepted); ?> Approved</li>
            <li><?php echo esc_html($est_rejected); ?> Rejected</li>
          </ul>

          <h2><?php _e('Invoices','arm-repair-estimates'); ?></h2>
          <ul class="arm-stats">
            <li>Total: <?php echo esc_html($inv_total); ?></li>
            <li>Paid: <?php echo esc_html($inv_paid); ?></li>
            <li>Unpaid: <?php echo esc_html($inv_unpaid); ?></li>
            <li>Voided: <?php echo esc_html($inv_void); ?></li>
            <li>Average Paid Invoice: <?php echo esc_html(number_format((float)$avg_invoice,2)); ?></li>
            <li>Total Paid: <?php echo esc_html(number_format((float)$total_paid,2)); ?></li>
            <li>Total Unpaid: <?php echo esc_html(number_format((float)$total_unpaid,2)); ?></li>
            <li>Total Sales Tax: <?php echo esc_html(number_format((float)$total_tax,2)); ?></li>
          </ul>

          <h2><?php _e('Quick Links','arm-repair-estimates'); ?></h2>
          <p>
            <a class="button" href="<?php echo admin_url('admin.php?page=arm-repair-estimates'); ?>">Estimates</a>
            <a class="button" href="<?php echo admin_url('admin.php?page=arm-repair-invoices'); ?>">Invoices</a>
            <a class="button" href="<?php echo admin_url('admin.php?page=arm-appointments'); ?>">Appointments</a>
            <a class="button" href="<?php echo admin_url('admin.php?page=arm-dashboard'); ?>">Dashboard</a>
          </p>
        </div>
        <?php
    }
}
