<?php
namespace ARM\PublicSite;
if (!defined('ABSPATH')) exit;

class Shortcode_Form {
    public static function boot() {
        add_shortcode('arm_repair_estimate_form', [__CLASS__, 'render']);
        add_action('wp_ajax_arm_get_vehicle_options',    [__CLASS__, 'ajax_get_vehicle_options']);
        add_action('wp_ajax_nopriv_arm_get_vehicle_options', [__CLASS__, 'ajax_get_vehicle_options']);
    }

    public static function render($atts) {
        ob_start();
        $terms = wp_kses_post(get_option('arm_re_terms_html',''));
        ?>
        <form id="arm-repair-estimate-form" class="arm-re-form" novalidate>
          <?php wp_nonce_field('arm_re_submit','arm_re_submit_nonce'); ?>

          <h3><?php _e('Vehicle Selection','arm-repair-estimates'); ?></h3>
          <div class="arm-grid">
            <?php foreach (['year','make','model','engine','transmission','drive','trim'] as $f): ?>
            <div>
              <label for="arm_<?php echo esc_attr($f); ?>"><?php echo esc_html(ucfirst($f)); ?> *</label>
              <select id="arm_<?php echo esc_attr($f); ?>" name="vehicle_<?php echo esc_attr($f); ?>" <?php echo $f==='year'?'':'disabled'; ?> required>
                <option value=""><?php printf(esc_html__('Select %s','arm-repair-estimates'), ucfirst($f)); ?></option>
              </select>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="arm-row arm-other">
            <label><input type="checkbox" id="arm_other_toggle"> <?php _e('Other','arm-repair-estimates'); ?></label>
            <input type="text" id="arm_other_text" name="vehicle_other" placeholder="<?php esc_attr_e('Enter vehicle info (Year/Make/Model/Engine/Transmission/Drive/Trim)','arm-repair-estimates'); ?>" style="display:none;">
          </div>

          <div class="arm-row">
            <label for="arm_service_type"><?php _e('Service Type','arm-repair-estimates'); ?> *</label>
            <select id="arm_service_type" name="service_type_id" required>
              <option value=""><?php _e('Select a Service Type','arm-repair-estimates'); ?></option>
              <?php
                global $wpdb;
                $tbl = $wpdb->prefix.'arm_service_types';
                $rows = $wpdb->get_results("SELECT id,name FROM $tbl WHERE is_active=1 ORDER BY sort_order ASC, name ASC");
                foreach ($rows as $r) printf('<option value="%d">%s</option>', (int)$r->id, esc_html($r->name));
              ?>
            </select>
          </div>

          <div class="arm-row">
            <label for="arm_issue_desc"><?php _e("Describe Your Vehicle's Issues",'arm-repair-estimates'); ?></label>
            <textarea id="arm_issue_desc" name="issue_description" rows="5" placeholder="<?php esc_attr_e('What problems are you experiencing?','arm-repair-estimates'); ?>"></textarea>
          </div>

          <h3><?php _e('Contact Information','arm-repair-estimates'); ?></h3>
          <div class="arm-grid">
            <div><label for="arm_first_name"><?php _e('First Name'); ?> *</label><input type="text" id="arm_first_name" name="first_name" required></div>
            <div><label for="arm_last_name"><?php _e('Last Name'); ?> *</label><input type="text" id="arm_last_name" name="last_name" required></div>
            <div><label for="arm_email"><?php _e('Email'); ?> *</label><input type="email" id="arm_email" name="email" required></div>
            <div><label for="arm_phone"><?php _e('Phone'); ?></label><input type="tel" id="arm_phone" name="phone"></div>
            <div><label for="arm_cust_addr"><?php _e('Address'); ?> *</label><input type="text" id="arm_cust_addr" name="customer_address" required></div>
            <div><label for="arm_cust_city"><?php _e('City'); ?> *</label><input type="text" id="arm_cust_city" name="customer_city" required></div>
            <div><label for="arm_cust_zip"><?php _e('Zip'); ?> *</label><input type="text" id="arm_cust_zip" name="customer_zip" required></div>
          </div>

          <div class="arm-row">
            <label><input type="checkbox" id="arm_same_addr" name="service_same_as_customer" value="1"> <strong><?php _e('Service Location:','arm-repair-estimates'); ?></strong> <?php _e('Same as customer address','arm-repair-estimates'); ?></label>
          </div>

          <fieldset class="arm-fieldset">
            <legend><?php _e('Service Location','arm-repair-estimates'); ?></legend>
            <div class="arm-grid">
              <div><label for="arm_srv_addr"><?php _e('Address'); ?> *</label><input type="text" id="arm_srv_addr" name="service_address" required></div>
              <div><label for="arm_srv_city"><?php _e('City'); ?> *</label><input type="text" id="arm_srv_city" name="service_city" required></div>
              <div><label for="arm_srv_zip"><?php _e('Zip'); ?> *</label><input type="text" id="arm_srv_zip" name="service_zip" required></div>
            </div>
          </fieldset>

          <h3><?php _e('How do you want to receive your estimate?','arm-repair-estimates'); ?></h3>
          <div class="arm-row arm-delivery">
            <label><input type="checkbox" name="delivery_email" id="arm_del_email" value="1"> <?php _e('Email'); ?></label>
            <label><input type="checkbox" name="delivery_sms"   id="arm_del_sms" value="1"> <?php _e('Text/SMS'); ?></label>
            <label><input type="checkbox" name="delivery_both"  id="arm_del_both" value="1"> <?php _e('Both'); ?></label>
          </div>
          <p class="arm-sms-consent"><small><?php _e('By providing a telephone number and opting to receiving estimates by SMS and submitting this form you are consenting to be contacted by SMS text message. Message & data rates may apply. You can reply STOP to opt-out of further messaging.','arm-repair-estimates'); ?></small></p>

          <div class="arm-terms-wrap">
            <div class="arm-terms-content"><?php echo $terms; ?></div>
            <label class="arm-terms-accept"><input type="checkbox" id="arm_terms" name="terms_accepted" value="1" required> <?php _e('I accept the Terms and Conditions','arm-repair-estimates'); ?> *</label>
          </div>

          <div class="arm-actions"><button type="submit" class="arm-btn"><?php _e('Submit Estimate Request','arm-repair-estimates'); ?></button></div>
          <div class="arm-msg" id="arm_msg" role="status" aria-live="polite" style="display:none;"></div>
        </form>
        <?php
        return ob_get_clean();
    }

    
    public static function ajax_get_vehicle_options() {
        check_ajax_referer('arm_re_nonce','nonce');
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_vehicle_data';
        $hier = ['year','make','model','engine','transmission','drive','trim'];
        $next = sanitize_text_field($_POST['next'] ?? '');
        if (!in_array($next, $hier, true)) wp_send_json_error(['message'=>'Invalid level']);

        $filters = [];
        foreach ($hier as $h) {
            if ($h === $next) break;
            if (isset($_POST[$h]) && $_POST[$h] !== '') $filters[$h] = sanitize_text_field(wp_unslash($_POST[$h]));
        }

        $col = esc_sql($next);
        $where = []; $params = [];
        foreach ($filters as $k=>$v) { $where[]="`$k`=%s"; $params[]=$v; }
        $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
        $sql = "SELECT DISTINCT `$col` AS v FROM `$tbl` $wsql ORDER BY v ASC";
        $results = $params ? $wpdb->get_col($wpdb->prepare($sql,$params)) : $wpdb->get_col($sql);
        wp_send_json_success(['options'=>array_values(array_filter($results))]);
    }
}
