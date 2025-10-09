<div class="arm-actions">
  <a href="<?php echo esc_url( add_query_arg(['arm_pdf'=>'estimate','id'=>(int)($estimate->id ?? 0)], home_url('/')) ); ?>"
     class="arm-btn" target="_blank" rel="noopener">
     <?php esc_html_e('Download PDF','arm-repair-estimates'); ?>
  </a>
</div>
