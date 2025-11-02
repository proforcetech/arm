<?php
namespace ARM\Appointments;

if (!defined('ABSPATH')) exit;

final class Frontend
{
    public static function boot(): void
    {
        add_shortcode('arm_appointment_form', [__CLASS__, 'render_form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets(): void
    {
        wp_enqueue_style('arm-appointments-frontend', ARM_RE_URL . 'assets/css/appointments-frontend.css', [], ARM_RE_VERSION);
        wp_enqueue_script(
            'arm-appointments-frontend',
            ARM_RE_URL . 'assets/js/appointments-frontend.js',
            ['jquery'],
            ARM_RE_VERSION,
            true
        );
        wp_localize_script('arm-appointments-frontend', 'ARM_APPT', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('arm_re_nonce'),
            'msgs'     => [
                'choose_slot' => __('Please select a time slot.', 'arm-repair-estimates'),
                'booked'      => __('Your appointment has been booked!', 'arm-repair-estimates'),
                'error'       => __('Unable to book. Please try again.', 'arm-repair-estimates'),
            ],
        ]);
    }

    public static function render_form($atts): string
    {
        $estimate_id = isset($_GET['estimate_id']) ? (int) $_GET['estimate_id'] : 0;

        // If no estimate_id is provided, handle based on login status
        if (!$estimate_id) {
            if (is_user_logged_in()) {
                return self::render_estimate_selector();
            } else {
                return self::render_guest_message();
            }
        }

        ob_start();
        ?>
        <form id="arm-appointment-form" data-estimate="<?php echo esc_attr($estimate_id); ?>">
          <h3><?php _e('Choose an Appointment Slot', 'arm-repair-estimates'); ?></h3>
          <div id="arm-appointment-slots">
            <p><?php _e('Loading available slots…', 'arm-repair-estimates'); ?></p>
          </div>

          <div class="arm-appt-actions">
            <button type="submit" class="arm-btn"><?php _e('Book Appointment', 'arm-repair-estimates'); ?></button>
          </div>
          <div id="arm-appt-msg" class="arm-msg" role="status"></div>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render estimate selector for logged-in users.
     */
    private static function render_estimate_selector(): string
    {
        global $wpdb;

        $user = wp_get_current_user();
        $customer_id = self::get_customer_id_from_user($user->ID);

        if (!$customer_id) {
            return '<p>' . esc_html__('Unable to find your customer profile. Please contact us.', 'arm-repair-estimates') . '</p>';
        }

        // Get approved estimates that don't have appointments yet
        $estimates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id, e.estimate_no, e.created_at, e.total,
                    CONCAT(e.vehicle_year, ' ', e.vehicle_make, ' ', e.vehicle_model) as vehicle
                FROM {$wpdb->prefix}arm_estimates e
                LEFT JOIN {$wpdb->prefix}arm_appointments a ON e.id = a.estimate_id
                WHERE e.customer_id = %d
                AND e.status = 'APPROVED'
                AND a.id IS NULL
                ORDER BY e.created_at DESC",
                $customer_id
            )
        );

        ob_start();
        ?>
        <div class="arm-estimate-selector">
            <h3><?php esc_html_e('Select an Estimate to Schedule', 'arm-repair-estimates'); ?></h3>
            <?php if (empty($estimates)) : ?>
                <p><?php esc_html_e('You don\'t have any approved estimates available for scheduling at this time.', 'arm-repair-estimates'); ?></p>
                <p><?php esc_html_e('Please request an estimate or contact us to get started.', 'arm-repair-estimates'); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('Select an approved estimate below to schedule your appointment:', 'arm-repair-estimates'); ?></p>
                <form method="get" class="arm-estimate-select-form">
                    <?php
                    // Preserve existing query parameters
                    foreach ($_GET as $key => $value) {
                        if ($key !== 'estimate_id' && !is_array($value)) {
                            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
                        }
                    }
                    ?>
                    <div class="arm-form-group">
                        <label for="estimate_id"><?php esc_html_e('Choose Estimate:', 'arm-repair-estimates'); ?></label>
                        <select name="estimate_id" id="estimate_id" class="arm-select" required>
                            <option value=""><?php esc_html_e('-- Select an Estimate --', 'arm-repair-estimates'); ?></option>
                            <?php foreach ($estimates as $est) : ?>
                                <option value="<?php echo esc_attr($est->id); ?>">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            '%s - %s - $%s (Created: %s)',
                                            $est->estimate_no,
                                            $est->vehicle,
                                            number_format($est->total, 2),
                                            date_i18n(get_option('date_format'), strtotime($est->created_at))
                                        )
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="arm-form-actions">
                        <button type="submit" class="arm-btn arm-btn-primary">
                            <?php esc_html_e('Continue to Schedule', 'arm-repair-estimates'); ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <style>
            .arm-estimate-selector {
                max-width: 600px;
                margin: 20px auto;
                padding: 20px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            .arm-estimate-selector h3 {
                margin-top: 0;
                color: #333;
            }
            .arm-form-group {
                margin: 20px 0;
            }
            .arm-form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #333;
            }
            .arm-select {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            .arm-form-actions {
                margin-top: 20px;
            }
            .arm-btn {
                padding: 10px 20px;
                background: #2271b1;
                color: #fff;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 600;
            }
            .arm-btn:hover {
                background: #135e96;
            }
            .arm-btn-primary {
                background: #2271b1;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Render message for non-logged-in users.
     */
    private static function render_guest_message(): string
    {
        $contact_email = get_option('arm_re_notify_email', get_option('admin_email'));
        $site_name = get_bloginfo('name');

        ob_start();
        ?>
        <div class="arm-guest-message">
            <h3><?php esc_html_e('Appointment Booking', 'arm-repair-estimates'); ?></h3>
            <p><?php esc_html_e('To schedule an appointment, you need an approved estimate.', 'arm-repair-estimates'); ?></p>
            <div class="arm-guest-options">
                <h4><?php esc_html_e('Next Steps:', 'arm-repair-estimates'); ?></h4>
                <ul>
                    <li>
                        <strong><?php esc_html_e('Request an Estimate:', 'arm-repair-estimates'); ?></strong>
                        <?php esc_html_e('Fill out our estimate request form to get started.', 'arm-repair-estimates'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Contact Us:', 'arm-repair-estimates'); ?></strong>
                        <?php
                        echo sprintf(
                            esc_html__('Call or email us at %s for assistance.', 'arm-repair-estimates'),
                            '<a href="mailto:' . esc_attr($contact_email) . '">' . esc_html($contact_email) . '</a>'
                        );
                        ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Already Have an Estimate?', 'arm-repair-estimates'); ?></strong>
                        <?php esc_html_e('Please log in to view and schedule your approved estimates.', 'arm-repair-estimates'); ?>
                    </li>
                </ul>
            </div>
            <div class="arm-guest-actions">
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="arm-btn arm-btn-secondary">
                    <?php esc_html_e('Log In', 'arm-repair-estimates'); ?>
                </a>
                <a href="mailto:<?php echo esc_attr($contact_email); ?>" class="arm-btn arm-btn-primary">
                    <?php esc_html_e('Contact Us', 'arm-repair-estimates'); ?>
                </a>
            </div>
        </div>
        <style>
            .arm-guest-message {
                max-width: 600px;
                margin: 20px auto;
                padding: 30px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            .arm-guest-message h3 {
                margin-top: 0;
                color: #333;
                border-bottom: 2px solid #2271b1;
                padding-bottom: 10px;
            }
            .arm-guest-message h4 {
                color: #333;
                margin-top: 20px;
            }
            .arm-guest-options {
                margin: 20px 0;
            }
            .arm-guest-options ul {
                list-style: none;
                padding: 0;
            }
            .arm-guest-options li {
                margin: 15px 0;
                padding-left: 20px;
                position: relative;
            }
            .arm-guest-options li:before {
                content: "→";
                position: absolute;
                left: 0;
                color: #2271b1;
                font-weight: bold;
            }
            .arm-guest-actions {
                margin-top: 30px;
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .arm-btn {
                display: inline-block;
                padding: 12px 24px;
                text-decoration: none;
                border-radius: 4px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
            }
            .arm-btn-primary {
                background: #2271b1;
                color: #fff;
            }
            .arm-btn-primary:hover {
                background: #135e96;
                color: #fff;
            }
            .arm-btn-secondary {
                background: #fff;
                color: #2271b1;
                border: 2px solid #2271b1;
            }
            .arm-btn-secondary:hover {
                background: #2271b1;
                color: #fff;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Get customer ID from user ID.
     */
    private static function get_customer_id_from_user($user_id): ?int
    {
        global $wpdb;
        $customer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}arm_customers WHERE email = (SELECT user_email FROM {$wpdb->users} WHERE ID = %d)",
                $user_id
            )
        );
        return $customer ? (int) $customer->id : null;
    }
}
