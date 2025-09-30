<?php
namespace ARM\Public;

if (!defined('ABSPATH')) exit;

/**
 * Renders the front-end shortcode form.
 */
final class Shortcode_Form {

    public static function boot(): void {
        \add_shortcode('arm_repair_estimate_form', [__CLASS__, 'render']);
    }

    public static function render(array $atts = []): string {
        ob_start();

        $terms = \wp_kses_post(\get_option('arm_re_terms_html', ''));
        ?>
        <form id="arm-repair-estimate-form" class="arm-re-form" novalidate>
            <?php \wp_nonce_field('arm_re_submit', 'arm_re_submit_nonce'); ?>

            <h3><?php \_e('Vehicle Selection', 'arm-repair-estimates'); ?></h3>
            <div class="arm-grid">
                <div>
                    <label for="arm_year"><?php \_e('Year', 'arm-repair-estimates'); ?> *</label>
                    <select id="arm_year" name="vehicle_year" required>
                        <option value=""><?php \_e('Select Year', 'arm-repair-estimates'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="arm_make"><?php \_e('Make', 'arm-repair-estimates'); ?> *</label>
                    <select id="arm_make" name="vehicle_make" required disabled>
                        <option value=""><?php \_e('Select Make', 'arm-repair-estimates'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="arm_model"><?php \_e('Model', 'arm-repair-estimates'); ?> *</label>
                    <select id="arm_model" name="vehicle_model" required disabled>
                        <option value=""><?php \_e('Select Model', 'arm-repair-estimates'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="arm_engine"><?php \_e('Engine', 'arm-repair-estimates'); ?> *</label>
                    <select id="arm_engine" name="vehicle_engine" required disabled>
                        <option value=""><?php \_e('Select Engine', 'arm-repair-estimates'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="arm_drive"><?php \_e('Drive', 'arm-repair-estimates'); ?> *</label>
                    <select id="arm_drive" name="vehicle_drive" required disabled>
                        <option value=""><?php \_e('Select Drive', 'arm-repair-estimates'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="arm_trim"><?php \_e('Trim', 'arm-repair-estimates'); ?> *</label>
                    <select id="arm_trim" name="vehicle_trim" required disabled>
                        <option value=""><?php \_e('Select Trim', 'arm-repair-estimates'); ?></option>
                    </select>
                </div>
            </div>

            <div class="arm-row arm-other">
                <label><input type="checkbox" id="arm_other_toggle"> <?php \_e('Other', 'arm-repair-estimates'); ?></label>
                <input type="text" id="arm_other_text" name="vehicle_other" placeholder="<?php echo \esc_attr__('Enter vehicle info (Year/Make/Model/Engine/Drive/Trim)', 'arm-repair-estimates'); ?>" style="display:none;">
            </div>

            <div class="arm-row">
                <label for="arm_service_type"><?php \_e('Service Type', 'arm-repair-estimates'); ?> *</label>
                <select id="arm_service_type" name="service_type_id" required>
                    <option value=""><?php \_e('Select a Service Type', 'arm-repair-estimates'); ?></option>
                    <?php self::render_service_type_options(); ?>
                </select>
            </div>

            <div class="arm-row">
                <label for="arm_issue_desc"><?php \_e("Describe Your Vehicle's Issues", 'arm-repair-estimates'); ?></label>
                <textarea id="arm_issue_desc" name="issue_description" rows="5" placeholder="<?php echo \esc_attr__('What problems are you experiencing?', 'arm-repair-estimates'); ?>"></textarea>
            </div>

            <h3><?php \_e('Contact Information', 'arm-repair-estimates'); ?></h3>
            <div class="arm-grid">
                <div>
                    <label for="arm_first_name"><?php \_e('First Name', 'arm-repair-estimates'); ?> *</label>
                    <input type="text" id="arm_first_name" name="first_name" required>
                </div>
                <div>
                    <label for="arm_last_name"><?php \_e('Last Name', 'arm-repair-estimates'); ?> *</label>
                    <input type="text" id="arm_last_name" name="last_name" required>
                </div>
                <div>
                    <label for="arm_email"><?php \_e('Email', 'arm-repair-estimates'); ?> *</label>
                    <input type="email" id="arm_email" name="email" required>
                </div>
                <div>
                    <label for="arm_phone"><?php \_e('Phone', 'arm-repair-estimates'); ?></label>
                    <input type="tel" id="arm_phone" name="phone" placeholder="(###) ###-####">
                </div>
                <div>
                    <label for="arm_cust_addr"><?php \_e('Address', 'arm-repair-estimates'); ?> *</label>
                    <input type="text" id="arm_cust_addr" name="customer_address" required>
                </div>
                <div>
                    <label for="arm_cust_city"><?php \_e('City', 'arm-repair-estimates'); ?> *</label>
                    <input type="text" id="arm_cust_city" name="customer_city" required>
                </div>
                <div>
                    <label for="arm_cust_zip"><?php \_e('Zip Code', 'arm-repair-estimates'); ?> *</label>
                    <input type="text" id="arm_cust_zip" name="customer_zip" required>
                </div>
            </div>

            <div class="arm-row">
                <label><input type="checkbox" id="arm_same_addr" name="service_same_as_customer" value="1"> <strong><?php \_e('Service Location', 'arm-repair-estimates'); ?>:</strong> <?php \_e('Same as customer address', 'arm-repair-estimates'); ?></label>
            </div>

            <fieldset class="arm-fieldset">
                <legend><?php \_e('Service Location', 'arm-repair-estimates'); ?></legend>
                <div class="arm-grid">
                    <div>
                        <label for="arm_srv_addr"><?php \_e('Address', 'arm-repair-estimates'); ?> *</label>
                        <input type="text" id="arm_srv_addr" name="service_address" required>
                    </div>
                    <div>
                        <label for="arm_srv_city"><?php \_e('City', 'arm-repair-estimates'); ?> *</label>
                        <input type="text" id="arm_srv_city" name="service_city" required>
                    </div>
                    <div>
                        <label for="arm_srv_zip"><?php \_e('Zip Code', 'arm-repair-estimates'); ?> *</label>
                        <input type="text" id="arm_srv_zip" name="service_zip" required>
                    </div>
                </div>
            </fieldset>

            <h3><?php \_e('How do you want to receive your estimate?', 'arm-repair-estimates'); ?></h3>
            <div class="arm-row arm-delivery">
                <label><input type="checkbox" name="delivery_email" id="arm_del_email" value="1"> <?php \_e('Email', 'arm-repair-estimates'); ?></label>
                <label><input type="checkbox" name="delivery_sms"   id="arm_del_sms" value="1">   <?php \_e('Text/SMS', 'arm-repair-estimates'); ?></label>
                <label><input type="checkbox" name="delivery_both"  id="arm_del_both" value="1">  <?php \_e('Both', 'arm-repair-estimates'); ?></label>
            </div>
            <p class="arm-sms-consent"><small><?php \_e('By providing a telephone number and opting to receiving estimates by SMS and submitting this form you are consenting to be contacted by SMS text message. Message & data rates may apply. You can reply STOP to opt-out of further messaging.', 'arm-repair-estimates'); ?></small></p>

            <div class="arm-terms-wrap">
                <div class="arm-terms-content"><?php echo $terms; ?></div>
                <label class="arm-terms-accept"><input type="checkbox" id="arm_terms" name="terms_accepted" value="1" required> <?php \_e('I accept the Terms and Conditions', 'arm-repair-estimates'); ?> *</label>
            </div>

            <div class="arm-actions">
                <button type="submit" class="arm-btn"><?php \_e('Submit Estimate Request', 'arm-repair-estimates'); ?></button>
            </div>

            <div class="arm-msg" id="arm_msg" role="status" aria-live="polite" style="display:none;"></div>
        </form>
        <?php

        return \ob_get_clean();
    }

    /** Render <option> tags for active service types */
    private static function render_service_type_options(): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_service_types';
        $rows = $wpdb->get_results("SELECT id, name FROM $tbl WHERE is_active=1 ORDER BY sort_order ASC, name ASC");
        if ($rows) {
            foreach ($rows as $r) {
                printf('<option value="%d">%s</option>', (int)$r->id, \esc_html($r->name));
            }
        }
    }
}
