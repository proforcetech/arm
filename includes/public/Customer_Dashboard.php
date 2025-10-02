<?php
namespace ARM\Public;

if (!defined('ABSPATH')) exit;

use WP_Post;
use WP_User;

class Customer_Dashboard {

    public static function boot(): void {
        add_shortcode('arm_customer_dashboard', [__CLASS__, 'render_dashboard']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_arm_vehicle_crud', [__CLASS__, 'ajax_vehicle_crud']);
    }

    public static function enqueue_assets(): void {
        if (!is_user_logged_in()) {
            return;
        }

        if (!is_page()) {
            return;
        }

        $post = get_post();
        if (!($post instanceof WP_Post) || !has_shortcode($post->post_content, 'arm_customer_dashboard')) {
            return;
        }

        wp_enqueue_style(
            'arm-customer-dashboard',
            ARM_RE_URL . 'assets/css/arm-customer-dashboard.css',
            [],
            ARM_RE_VERSION
        );
        wp_enqueue_script(
            'arm-customer-dashboard',
            ARM_RE_URL . 'assets/js/arm-customer-dashboard.js',
            ['jquery'],
            ARM_RE_VERSION,
            true
        );
        wp_localize_script('arm-customer-dashboard', 'ARM_CUSTOMER', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('arm_customer_nonce'),
        ]);
    }

    public static function render_dashboard(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to access your dashboard.', 'arm-repair-estimates') . '</p>';
        }

        $user = wp_get_current_user();
        if (!in_array('arm_customer', (array) $user->roles, true) && !user_can($user, 'read')) {
            return '<p>' . esc_html__('Access denied.', 'arm-repair-estimates') . '</p>';
        }

        $customer = self::resolve_customer_for_user($user, true);
        if (!$customer) {
            return '<p>' . esc_html__('We could not load your customer profile. Please contact support.', 'arm-repair-estimates') . '</p>';
        }

        ob_start();
        ?>
        <div class="arm-customer-dashboard">
            <h2><?php echo esc_html__('Welcome, ', 'arm-repair-estimates') . esc_html($user->display_name ?: $customer->first_name); ?></h2>

            <nav class="arm-tabs">
                <button data-tab="vehicles" class="active"><?php esc_html_e('My Vehicles', 'arm-repair-estimates'); ?></button>
                <button data-tab="estimates"><?php esc_html_e('Estimates', 'arm-repair-estimates'); ?></button>
                <button data-tab="invoices"><?php esc_html_e('Invoices', 'arm-repair-estimates'); ?></button>
                <button data-tab="profile"><?php esc_html_e('Profile', 'arm-repair-estimates'); ?></button>
            </nav>

            <section id="tab-vehicles" class="arm-tab active">
                <?php self::render_vehicles($customer); ?>
            </section>
            <section id="tab-estimates" class="arm-tab">
                <?php self::render_estimates($customer); ?>
            </section>
            <section id="tab-invoices" class="arm-tab">
                <?php self::render_invoices($customer); ?>
            </section>
            <section id="tab-profile" class="arm-tab">
                <?php self::render_profile($user, $customer); ?>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_vehicles(object $customer): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_vehicles';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tbl WHERE customer_id=%d AND (deleted_at IS NULL OR deleted_at='0000-00-00 00:00:00') ORDER BY year DESC, make ASC, model ASC",
            (int) $customer->id
        ));
        ?>
        <h3><?php esc_html_e('My Vehicles', 'arm-repair-estimates'); ?></h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Year', 'arm-repair-estimates'); ?></th>
                    <th><?php esc_html_e('Make', 'arm-repair-estimates'); ?></th>
                    <th><?php esc_html_e('Model', 'arm-repair-estimates'); ?></th>
                    <th><?php esc_html_e('Actions', 'arm-repair-estimates'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows) : foreach ($rows as $v) : ?>
                <tr>
                    <td><?php echo esc_html($v->year); ?></td>
                    <td><?php echo esc_html($v->make); ?></td>
                    <td><?php echo esc_html($v->model); ?></td>
                    <td>
                        <button class="arm-edit-vehicle" data-id="<?php echo (int) $v->id; ?>"><?php esc_html_e('Edit', 'arm-repair-estimates'); ?></button>
                        <button class="arm-del-vehicle" data-id="<?php echo (int) $v->id; ?>"><?php esc_html_e('Delete', 'arm-repair-estimates'); ?></button>
                    </td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="4"><?php esc_html_e('No vehicles yet.', 'arm-repair-estimates'); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <button class="arm-add-vehicle"><?php esc_html_e('Add Vehicle', 'arm-repair-estimates'); ?></button>
        <div id="arm-vehicle-form" style="display:none;">
            <h4><?php esc_html_e('Vehicle Details', 'arm-repair-estimates'); ?></h4>
            <form>
                <input type="hidden" name="id" value="">
                <label><?php esc_html_e('Year', 'arm-repair-estimates'); ?> <input type="number" name="year" min="1900" max="2100"></label>
                <label><?php esc_html_e('Make', 'arm-repair-estimates'); ?> <input type="text" name="make" required></label>
                <label><?php esc_html_e('Model', 'arm-repair-estimates'); ?> <input type="text" name="model" required></label>
                <label><?php esc_html_e('Engine', 'arm-repair-estimates'); ?> <input type="text" name="engine"></label>
                <label><?php esc_html_e('Trim', 'arm-repair-estimates'); ?> <input type="text" name="trim"></label>
                <button type="submit"><?php esc_html_e('Save', 'arm-repair-estimates'); ?></button>
            </form>
        </div>
        <?php
    }

    private static function render_estimates(object $customer): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_estimates';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, estimate_no, status, total, created_at, token FROM $tbl WHERE customer_id=%d ORDER BY created_at DESC",
            (int) $customer->id
        ));
        ?>
        <h3><?php esc_html_e('My Estimates', 'arm-repair-estimates'); ?></h3>
        <?php if ($rows) : ?>
            <ul class="arm-estimates-list">
                <?php foreach ($rows as $e) :
                    $view_url = add_query_arg(['arm_estimate' => $e->token], home_url('/'));
                    ?>
                    <li>
                        <a href="<?php echo esc_url($view_url); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html(sprintf(__('Estimate %1$s — %2$s — %3$s', 'arm-repair-estimates'), $e->estimate_no, $e->status, mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $e->created_at))); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p><?php esc_html_e('No estimates available.', 'arm-repair-estimates'); ?></p>
        <?php endif;
    }

    private static function render_invoices(object $customer): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_invoices';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, invoice_no, status, total, created_at, token FROM $tbl WHERE customer_id=%d ORDER BY created_at DESC",
            (int) $customer->id
        ));
        ?>
        <h3><?php esc_html_e('My Invoices', 'arm-repair-estimates'); ?></h3>
        <?php if ($rows) : ?>
            <ul class="arm-invoices-list">
                <?php foreach ($rows as $i) :
                    $view_url = add_query_arg(['arm_invoice' => $i->token], home_url('/'));
                    ?>
                    <li>
                        <a href="<?php echo esc_url($view_url); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html(sprintf(__('Invoice %1$s — %2$s — %3$s', 'arm-repair-estimates'), $i->invoice_no, $i->status, mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $i->created_at))); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p><?php esc_html_e('No invoices available.', 'arm-repair-estimates'); ?></p>
        <?php endif;
    }

    private static function render_profile(WP_User $user, object $customer): void {
        ?>
        <h3><?php esc_html_e('My Profile', 'arm-repair-estimates'); ?></h3>
        <p><?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?><br>
        <?php echo esc_html($customer->email); ?><br>
        <?php if (!empty($customer->phone)) : echo esc_html($customer->phone) . '<br>'; endif; ?></p>

        <form method="post">
            <?php wp_nonce_field('arm_update_profile', 'arm_profile_nonce'); ?>
            <label><?php esc_html_e('Display Name', 'arm-repair-estimates'); ?>
                <input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>">
            </label>
            <label><?php esc_html_e('Email', 'arm-repair-estimates'); ?>
                <input type="email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>">
            </label>
            <button type="submit"><?php esc_html_e('Update Profile', 'arm-repair-estimates'); ?></button>
        </form>
        <?php
    }

    public static function resolve_customer_for_user(WP_User $user, bool $create_if_missing = true): ?object {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_customers';
        $user_id = (int) $user->ID;
        $email   = sanitize_email($user->user_email);

        $customer_id = (int) get_user_meta($user_id, 'arm_customer_id', true);
        if ($customer_id > 0) {
            $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $customer_id));
            if ($customer) {
                return $customer;
            }
        }

        if ($email) {
            $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE email=%s", $email));
            if ($customer) {
                update_user_meta($user_id, 'arm_customer_id', (int) $customer->id);
                return $customer;
            }
        }

        if (!$create_if_missing || !$email) {
            return null;
        }

        $first = trim((string) $user->first_name);
        $last  = trim((string) $user->last_name);
        if ($first === '' && $user->display_name) {
            $parts = preg_split('/\s+/', $user->display_name);
            $first = sanitize_text_field($parts[0] ?? 'Customer');
            $last  = sanitize_text_field($parts[1] ?? ($last !== '' ? $last : 'Account'));
        }
        if ($first === '') {
            $first = 'Customer';
        }
        if ($last === '') {
            $last = 'Account';
        }

        $phone = get_user_meta($user_id, 'billing_phone', true);
        if (!$phone) {
            $phone = get_user_meta($user_id, 'phone', true);
        }

        $data = [
            'first_name' => sanitize_text_field($first),
            'last_name'  => sanitize_text_field($last),
            'email'      => $email,
            'phone'      => sanitize_text_field((string) $phone),
            'created_at' => current_time('mysql'),
        ];

        $wpdb->insert($tbl, $data);
        $new_id = (int) $wpdb->insert_id;
        if ($new_id > 0) {
            update_user_meta($user_id, 'arm_customer_id', $new_id);
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $new_id));
        }

        return null;
    }

    public static function ajax_vehicle_crud(): void {
        check_ajax_referer('arm_customer_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not logged in', 'arm-repair-estimates')]);
        }

        $user = wp_get_current_user();
        $customer = self::resolve_customer_for_user($user, true);
        if (!$customer) {
            wp_send_json_error(['message' => __('Unable to find your customer record.', 'arm-repair-estimates')]);
        }

        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_vehicles';
        $action = sanitize_text_field(wp_unslash($_POST['action_type'] ?? ''));

        if ($action === 'add' || $action === 'edit') {
            $data = [
                'year'        => isset($_POST['year']) ? (int) $_POST['year'] : null,
                'make'        => sanitize_text_field(wp_unslash($_POST['make'] ?? '')),
                'model'       => sanitize_text_field(wp_unslash($_POST['model'] ?? '')),
                'engine'      => sanitize_text_field(wp_unslash($_POST['engine'] ?? '')),
                'trim'        => sanitize_text_field(wp_unslash($_POST['trim'] ?? '')),
                'customer_id' => (int) $customer->id,
                'user_id'     => (int) $user->ID,
                'updated_at'  => current_time('mysql'),
                'deleted_at'  => null,
            ];

            if ($data['make'] === '' || $data['model'] === '') {
                wp_send_json_error(['message' => __('Make and model are required.', 'arm-repair-estimates')]);
            }

            if ($action === 'add') {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($tbl, $data);
            } else {
                $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
                $wpdb->update($tbl, $data, ['id' => $id, 'customer_id' => (int) $customer->id]);
            }

            wp_send_json_success(['message' => __('Saved', 'arm-repair-estimates')]);
        }

        if ($action === 'delete') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $wpdb->update($tbl, [
                'deleted_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => $id, 'customer_id' => (int) $customer->id]);

            wp_send_json_success(['message' => __('Deleted', 'arm-repair-estimates')]);
        }

        wp_send_json_error(['message' => __('Invalid request', 'arm-repair-estimates')]);
    }
}
