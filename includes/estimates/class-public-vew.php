<?php
namespace ARM\Estimates;

if (!defined('ABSPATH')) exit;

final class PublicView {

    public static function boot(): void {
        
        add_filter('query_vars', function ($vars) { $vars[] = 'arm_estimate'; return $vars; });

        
        add_action('template_redirect', [__CLASS__, 'maybe_render']);

        
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_assets']);
    }

    /** Enqueue CSS/JS only when viewing an estimate */
    public static function maybe_enqueue_assets(): void {
        $token = get_query_var('arm_estimate');
        if (!$token) return;

        
        wp_enqueue_style(
            'arm-re-frontend',
            defined('ARM_RE_URL') ? ARM_RE_URL.'assets/css/arm-frontend.css' : plugins_url('/assets/css/arm-frontend.css', dirname(__FILE__,2)),
            [],
            defined('ARM_RE_VERSION') ? ARM_RE_VERSION : '1.0.0'
        );

        
        wp_enqueue_script(
            'arm-re-estimate-public',
            defined('ARM_RE_URL') ? ARM_RE_URL.'assets/js/estimate-public.js' : plugins_url('/assets/js/estimate-public.js', dirname(__FILE__,2)),
            ['jquery'],
            defined('ARM_RE_VERSION') ? ARM_RE_VERSION : '1.0.0',
            true
        );

        wp_localize_script('arm-re-estimate-public', 'ARM_RE_EST_PUBLIC', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('arm_re_est_action'),
            'token'    => (string)$token,
            'i18n'     => [
                'sig_required' => __('Please sign and type your name to approve the estimate.', 'arm-repair-estimates'),
                'approved'     => __('Thanks! Your approval has been recorded.', 'arm-repair-estimates'),
                'declined'     => __('Your response has been recorded.', 'arm-repair-estimates'),
                'error'        => __('Something went wrong. Please try again.', 'arm-repair-estimates'),
                'confirmDecl'  => __('Are you sure you want to decline this estimate?', 'arm-repair-estimates'),
                'clear'        => __('Clear', 'arm-repair-estimates'),
            ],
        ]);
    }

    /** Render public page with theme header/footer */
    public static function maybe_render(): void {
        $token = get_query_var('arm_estimate');
        if (!$token) return;

        global $wpdb;
        $tblE = $wpdb->prefix.'arm_estimates';
        $tblI = $wpdb->prefix.'arm_estimate_items';
        $tblC = $wpdb->prefix.'arm_customers';
        $tblJ = $wpdb->prefix.'arm_estimate_jobs';

        $est = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tblE WHERE token=%s", $token));
        if (!$est) { status_header(404); wp_die(__('Estimate not found.', 'arm-repair-estimates')); }

        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tblI WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC",
            (int)$est->id
        ));
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tblJ WHERE estimate_id=%d ORDER BY sort_order ASC, id ASC",
            (int) $est->id
        ));
        $cust = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tblC WHERE id=%d",
            (int)$est->customer_id
        ));
        $terms = wp_kses_post(get_option('arm_re_terms_html', ''));


        $technicians = Controller::get_technician_directory();
        $assigned_technician = null;
        if (!empty($est->technician_id) && isset($technicians[(int) $est->technician_id])) {
            $assigned_technician = $technicians[(int) $est->technician_id];
        }
        if ($jobs) {
            foreach ($jobs as $job) {
                $job->assigned_technician = (!empty($job->technician_id) && isset($technicians[(int) $job->technician_id]))
                    ? $technicians[(int) $job->technician_id]
                    : null;
            }
        }


        $shop = (object)[
            'name'    => get_option('arm_re_shop_name',''),
            'address' => get_option('arm_re_shop_address',''),
            'phone'   => get_option('arm_re_shop_phone',''),
            'email'   => get_option('arm_re_shop_email',''),
            'logo'    => get_option('arm_re_logo_url',''),
        ];

        
        status_header(200);
        get_header();

        
        $ARM_RE_ESTIMATE_CONTEXT = compact('est','items','cust','terms','shop','jobs','assigned_technician');

        
        $tpl = defined('ARM_RE_PATH')
            ? ARM_RE_PATH.'templates/estimate-view.php'
            : dirname(__FILE__,2).'/templates/estimate-view.php';

        if (file_exists($tpl)) {
            include $tpl;
        } else {
            echo '<div class="wrap"><p>'.esc_html__('Template not found.', 'arm-repair-estimates').'</p></div>';
        }

        get_footer();
        exit;
    }
}
