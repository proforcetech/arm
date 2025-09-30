<?php
namespace ARM\Appointments;

class Frontend {
    public static function boot() {
        add_shortcode('arm_appointment_form', [__CLASS__, 'render_form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function assets() {
        wp_enqueue_style('arm-appointments-frontend', ARM_RE_URL.'assets/css/appointments-frontend.css', [], ARM_RE_VERSION);
        wp_enqueue_script('arm-appointments-frontend', ARM_RE_URL.'assets/js/appointments-frontend.js', ['jquery'], ARM_RE_VERSION, true);
        wp_localize_script('arm-appointments-frontend', 'ARM_APPT', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('arm_re_nonce'),
            'msgs'     => [
                'choose_slot' => __('Please select a time slot.','arm-repair-estimates'),
                'booked'      => __('Your appointment has been booked!','arm-repair-estimates'),
                'error'       => __('Unable to book. Please try again.','arm-repair-estimates')
            ]
        ]);
    }

    public static function render_form($atts) {
        $estimate_id = intval($_GET['estimate_id'] ?? 0); // pass via link after approval
        if (!$estimate_id) return '<p>'.__('Invalid estimate link','arm-repair-estimates').'</p>';

        ob_start();
        ?>
        <form id="arm-appointment-form" data-estimate="<?php echo esc_attr($estimate_id); ?>">
          <h3><?php _e('Choose an Appointment Slot','arm-repair-estimates'); ?></h3>
          <div id="arm-appointment-slots">
            <p><?php _e('Loading available slots…','arm-repair-estimates'); ?></p>
          </div>

          <div class="arm-appt-actions">
            <button type="submit" class="arm-btn"><?php _e('Book Appointment','arm-repair-estimates'); ?></button>
          </div>
          <div id="arm-appt-msg" class="arm-msg" role="status"></div>
        </form>
        <?php
        return ob_get_clean();
    }
}
