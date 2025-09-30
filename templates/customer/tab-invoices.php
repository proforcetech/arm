<?php
// file: templates/customer/tab-invoices.php
// Why: export actions for invoices with clean links and i18n.
?>
<div class="arm-export-actions">
  <a href="<?php echo esc_url( admin_url('admin-post.php?action=arm_customer_export&type=invoices&format=csv') ); ?>"
     class="arm-btn" target="_blank">
     <?php esc_html_e('Export Invoices (CSV)', 'arm-repair-estimates'); ?>
  </a>
  <a href="<?php echo esc_url( admin_url('admin-post.php?action=arm_customer_export&type=invoices&format=pdf') ); ?>"
     class="arm-btn" target="_blank">
     <?php esc_html_e('Export Invoices (PDF)', 'arm-repair-estimates'); ?>
  </a>
</div>
