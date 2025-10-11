<?php
/**
 * Template Name: ARM Warranty Claims Test Page
 * Description: Simple page template that renders the ARM warranty claims shortcode for manual verification.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div class="arm-warranty-claims-test" style="max-width:960px;margin:2rem auto;">
  <?php echo do_shortcode('[arm_warranty_claims]'); ?>
</div>
<?php
get_footer();
