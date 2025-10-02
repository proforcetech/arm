<?php
namespace ARM\Public;

if ( ! defined( 'ABSPATH' ) ) exit;

class Customer_Dashboard {

    public static function boot() {
        add_shortcode('arm_customer_dashboard', [__CLASS__, 'render_dashboard']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_arm_vehicle_crud', [__CLASS__, 'ajax_vehicle_crud']);
    }

    /** ===== Assets ===== */
    public static function enqueue_assets() {
        if (!is_user_logged_in()) return;
        if (!is_page() && !has_shortcode(get_post()->post_content ?? '', 'arm_customer_dashboard')) return;

        wp_enqueue_style('arm-customer-dashboard', ARM_RE_URL.'assets/css/arm-customer-dashboard.css', [], ARM_RE_VERSION);
        wp_enqueue_script('arm-customer-dashboard', ARM_RE_URL.'assets/js/arm-customer-dashboard.js', ['jquery'], ARM_RE_VERSION, true);
        wp_localize_script('arm-customer-dashboard', 'ARM_CUSTOMER', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('arm_customer_nonce'),
        ]);
    }

    /** ===== Dashboard Shortcode ===== */
    public static function render_dashboard() {
        if (!is_user_logged_in()) {
            return '<p>'.__('Please log in to access your dashboard.', 'arm-repair-estimates').'</p>';
        }

        $user = wp_get_current_user();
        if (!in_array('arm_customer', (array)$user->roles)) {
            return '<p>'.__('Access denied.','arm-repair-estimates').'</p>';
        }

        ob_start();
        ?>
        <div class="arm-customer-dashboard">
            <h2><?php echo esc_html__('Welcome, ','arm-repair-estimates').esc_html($user->display_name); ?></h2>

            <nav class="arm-tabs">
                <button data-tab="vehicles" class="active"><?php _e('My Vehicles','arm-repair-estimates'); ?></button>
                <button data-tab="estimates"><?php _e('Estimates','arm-repair-estimates'); ?></button>
                <button data-tab="invoices"><?php _e('Invoices','arm-repair-estimates'); ?></button>
                <button data-tab="profile"><?php _e('Profile','arm-repair-estimates'); ?></button>
            </nav>

            <section id="tab-vehicles" class="arm-tab active">
                <?php self::render_vehicles($user->ID); ?>
            </section>
            <section id="tab-estimates" class="arm-tab">
                <?php self::render_estimates($user->ID); ?>
            </section>
            <section id="tab-invoices" class="arm-tab">
                <?php self::render_invoices($user->ID); ?>
            </section>
            <section id="tab-profile" class="arm-tab">
                <?php self::render_profile($user); ?>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /** ===== Vehicles Tab ===== */
    private static function render_vehicles($user_id) {
        global $wpdb;
        $customer = self::get_or_create_customer($user_id);
        if (!$customer) {
            echo '<p>'.esc_html__('We could not load your vehicle list at this time.', 'arm-repair-estimates').'</p>';
            return;
        }

        $tbl = $wpdb->prefix.'arm_vehicles';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE customer_id=%d AND deleted_at IS NULL ORDER BY year DESC, make ASC, model ASC", $customer->id));
        ?>
        <h3><?php _e('My Vehicles','arm-repair-estimates'); ?></h3>
        <table class="widefat striped">
            <thead><tr>
                <th><?php _e('Year','arm-repair-estimates'); ?></th>
                <th><?php _e('Make','arm-repair-estimates'); ?></th>
                <th><?php _e('Model','arm-repair-estimates'); ?></th>
                <th><?php _e('Trim','arm-repair-estimates'); ?></th>
                <th><?php _e('Engine','arm-repair-estimates'); ?></th>
                <th><?php _e('Drive','arm-repair-estimates'); ?></th>
                <th><?php _e('VIN','arm-repair-estimates'); ?></th>
                <th><?php _e('License Plate','arm-repair-estimates'); ?></th>
                <th><?php _e('Current Mileage','arm-repair-estimates'); ?></th>
                <th><?php _e('Previous Service Mileage','arm-repair-estimates'); ?></th>
                <th><?php _e('Actions','arm-repair-estimates'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $v): ?>
                <tr>
                    <td><?php echo esc_html($v->year); ?></td>
                    <td><?php echo esc_html($v->make); ?></td>
                    <td><?php echo esc_html($v->model); ?></td>
                    <td><?php echo esc_html($v->trim); ?></td>
                    <td><?php echo esc_html($v->engine); ?></td>
                    <td><?php echo esc_html($v->drive); ?></td>
                    <td><?php echo esc_html($v->vin); ?></td>
                    <td><?php echo esc_html($v->license_plate); ?></td>
                    <td><?php echo esc_html($v->current_mileage); ?></td>
                    <td><?php echo esc_html($v->previous_service_mileage); ?></td>
                    <td>
                        <button class="arm-edit-vehicle"
                            data-id="<?php echo (int)$v->id; ?>"
                            data-year="<?php echo esc_attr($v->year); ?>"
                            data-make="<?php echo esc_attr($v->make); ?>"
                            data-model="<?php echo esc_attr($v->model); ?>"
                            data-trim="<?php echo esc_attr($v->trim); ?>"
                            data-engine="<?php echo esc_attr($v->engine); ?>"
                            data-drive="<?php echo esc_attr($v->drive); ?>"
                            data-vin="<?php echo esc_attr($v->vin); ?>"
                            data-license_plate="<?php echo esc_attr($v->license_plate); ?>"
                            data-current_mileage="<?php echo esc_attr($v->current_mileage); ?>"
                            data-previous_service_mileage="<?php echo esc_attr($v->previous_service_mileage); ?>"
                        ><?php _e('Edit','arm-repair-estimates'); ?></button>
                        <button class="arm-del-vehicle" data-id="<?php echo (int)$v->id; ?>"><?php _e('Delete','arm-repair-estimates'); ?></button>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11"><?php _e('No vehicles yet.','arm-repair-estimates'); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <button class="arm-add-vehicle"><?php _e('Add Vehicle','arm-repair-estimates'); ?></button>
        <div id="arm-vehicle-form" style="display:none;">
            <h4><?php _e('Vehicle Details','arm-repair-estimates'); ?></h4>
            <form>
                <input type="hidden" name="id" value="">
                <label><?php _e('Year','arm-repair-estimates'); ?> <input type="number" name="year" required></label>
                <label><?php _e('Make','arm-repair-estimates'); ?> <input type="text" name="make" required></label>
                <label><?php _e('Model','arm-repair-estimates'); ?> <input type="text" name="model" required></label>
                <label><?php _e('Trim','arm-repair-estimates'); ?> <input type="text" name="trim"></label>
                <label><?php _e('Engine','arm-repair-estimates'); ?> <input type="text" name="engine"></label>
                <label><?php _e('Drive','arm-repair-estimates'); ?> <input type="text" name="drive"></label>
                <label><?php _e('VIN','arm-repair-estimates'); ?> <input type="text" name="vin"></label>
                <label><?php _e('License Plate','arm-repair-estimates'); ?> <input type="text" name="license_plate"></label>
                <label><?php _e('Current Mileage','arm-repair-estimates'); ?> <input type="number" name="current_mileage" min="0" step="1"></label>
                <label><?php _e('Previous Service Mileage','arm-repair-estimates'); ?> <input type="number" name="previous_service_mileage" min="0" step="1"></label>
                <button type="submit"><?php _e('Save','arm-repair-estimates'); ?></button>
            </form>
        </div>
        <?php
    }

    /** ===== Estimates Tab ===== */
    private static function render_estimates($user_id) {
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_estimates';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE user_id=%d ORDER BY created_at DESC", $user_id));
        ?>
        <h3><?php _e('My Estimates','arm-repair-estimates'); ?></h3>
        <?php if ($rows): ?>
            <ul class="arm-estimates-list">
            <?php foreach ($rows as $e): ?>
                <li>
                    <a href="<?php echo esc_url($e->view_url); ?>" target="_blank">
                        <?php echo esc_html("Estimate #{$e->id} - {$e->status} - {$e->created_at}"); ?>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p><?php _e('No estimates available.','arm-repair-estimates'); ?></p>
        <?php endif;
    }

    /** ===== Invoices Tab ===== */
    private static function render_invoices($user_id) {
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_invoices';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE user_id=%d ORDER BY created_at DESC", $user_id));
        ?>
        <h3><?php _e('My Invoices','arm-repair-estimates'); ?></h3>
        <?php if ($rows): ?>
            <ul class="arm-invoices-list">
            <?php foreach ($rows as $i): ?>
                <li>
                    <a href="<?php echo esc_url($i->view_url); ?>" target="_blank">
                        <?php echo esc_html("Invoice #{$i->id} - {$i->status} - {$i->created_at}"); ?>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p><?php _e('No invoices available.','arm-repair-estimates'); ?></p>
        <?php endif;
    }

    /** ===== Profile Tab ===== */
    private static function render_profile($user) {
        ?>
        <h3><?php _e('My Profile','arm-repair-estimates'); ?></h3>
        <form method="post">
            <?php wp_nonce_field('arm_update_profile','arm_profile_nonce'); ?>
            <label><?php _e('Name','arm-repair-estimates'); ?> <input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>"></label>
            <label><?php _e('Email','arm-repair-estimates'); ?> <input type="email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>"></label>
            <button type="submit"><?php _e('Update Profile','arm-repair-estimates'); ?></button>
        </form>
        <?php
    }

    /** ===== AJAX Vehicle CRUD ===== */
    public static function ajax_vehicle_crud() {
        check_ajax_referer('arm_customer_nonce','nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Not logged in']);
        $user_id = get_current_user_id();
        $customer = self::get_or_create_customer($user_id);
        if (!$customer) {
            wp_send_json_error(['message' => __('Unable to determine customer record.', 'arm-repair-estimates')]);
        }
        global $wpdb; $tbl = $wpdb->prefix.'arm_vehicles';

        $action = sanitize_text_field($_POST['action_type'] ?? '');
        if ($action === 'add' || $action === 'edit') {
            $data = [
                'customer_id' => (int) $customer->id,
                'year' => intval($_POST['year']),
                'make' => sanitize_text_field($_POST['make']),
                'model'=> sanitize_text_field($_POST['model']),
                'trim'  => sanitize_text_field($_POST['trim']),
                'engine'=> sanitize_text_field($_POST['engine']),
                'drive' => sanitize_text_field($_POST['drive']),
                'vin'   => sanitize_text_field($_POST['vin']),
                'license_plate' => sanitize_text_field($_POST['license_plate']),
                'current_mileage' => isset($_POST['current_mileage']) && $_POST['current_mileage'] !== '' ? max(0, intval($_POST['current_mileage'])) : null,
                'previous_service_mileage' => isset($_POST['previous_service_mileage']) && $_POST['previous_service_mileage'] !== '' ? max(0, intval($_POST['previous_service_mileage'])) : null,
                'updated_at'=> current_time('mysql'),
            ];
            if ($data['year'] <= 0 || $data['make'] === '' || $data['model'] === '') {
                wp_send_json_error(['message' => __('Year, make, and model are required.', 'arm-repair-estimates')]);
            }
            foreach (['trim','engine','drive','vin','license_plate'] as $field) {
                if ($data[$field] === '') {
                    $data[$field] = null;
                }
            }
            if ($action==='add') {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($tbl,$data);
            } else {
                $id=intval($_POST['id']);
                $wpdb->update($tbl,$data,['id'=>$id,'customer_id'=>$customer->id]);
            }
            wp_send_json_success(['message'=>'Saved']);
        }
        elseif ($action==='delete') {
            $id=intval($_POST['id']);
            $wpdb->update($tbl,['deleted_at'=>current_time('mysql')],['id'=>$id,'customer_id'=>$customer->id]);
            wp_send_json_success(['message'=>'Deleted']);
        }

        wp_send_json_error(['message'=>'Invalid request']);
    }

    private static function get_or_create_customer($user_id) {
        static $cache = [];
        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE wp_user_id = %d", $user_id));
        if ($customer) {
            return $cache[$user_id] = $customer;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return $cache[$user_id] = null;
        }

        if (!empty($user->user_email)) {
            $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE email = %s", $user->user_email));
            if ($customer) {
                $wpdb->update($tbl, ['wp_user_id' => $user_id, 'updated_at' => current_time('mysql')], ['id' => $customer->id]);
                $customer->wp_user_id = $user_id;
                return $cache[$user_id] = $customer;
            }
        }

        $first = sanitize_text_field(get_user_meta($user_id, 'first_name', true));
        $last  = sanitize_text_field(get_user_meta($user_id, 'last_name', true));
        $display = trim((string) $user->display_name);
        if ($first === '') {
            $parts = preg_split('/\s+/', $display, 2);
            $first = sanitize_text_field($parts[0] ?? $user->user_login ?? 'Customer');
        }
        if ($last === '') {
            $parts = isset($parts) ? $parts : preg_split('/\s+/', $display, 2);
            $last  = sanitize_text_field($parts[1] ?? 'Account');
        }

        $now = current_time('mysql');
        $wpdb->insert($tbl, [
            'first_name' => $first !== '' ? $first : 'Customer',
            'last_name'  => $last !== '' ? $last : 'Account',
            'email'      => $user->user_email,
            'wp_user_id' => $user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $wpdb->insert_id;
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %d", $id));
        return $cache[$user_id] = $customer;
    }
}
