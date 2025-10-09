<div class="arm-export-actions">
  <a href="<?php echo esc_url( admin_url('admin-post.php?action=arm_customer_export&type=estimates&format=csv') ); ?>"
     class="arm-btn" target="_blank">
     <?php esc_html_e('Export Estimates (CSV)', 'arm-repair-estimates'); ?>
  </a>
  <a href="<?php echo esc_url( admin_url('admin-post.php?action=arm_customer_export&type=estimates&format=pdf') ); ?>"
     class="arm-btn" target="_blank">
     <?php esc_html_e('Export Estimates (PDF)', 'arm-repair-estimates'); ?>
  </a>
</div>
