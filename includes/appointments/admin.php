<?php
namespace ARM\Appointments;

class Admin {
    public static function boot() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_ajax_arm_admin_events', [__CLASS__, 'ajax_events']);
        add_action('wp_ajax_arm_save_event', [__CLASS__, 'ajax_save_event']);
    }

    public static function menu_page(): array {
        return [
            'page_title' => __('Appointments', 'arm-repair-estimates'),
            'menu_title' => __('Appointments', 'arm-repair-estimates'),
            'capability' => 'manage_options',
            'menu_slug'  => 'arm-appointments',
            'callback'   => [__CLASS__, 'render_calendar'],
        ];
    }

    public static function availability_menu_page(): array {
        return [
            'page_title' => __('Availability', 'arm-repair-estimates'),
            'menu_title' => __('Availability', 'arm-repair-estimates'),
            'capability' => 'manage_options',
            'menu_slug'  => 'arm-availability',
            'callback'   => [__CLASS__, 'render_availability'],
        ];
    }

    public static function assets($hook) {
        if (strpos($hook,'arm-appointments')===false) return;
        wp_enqueue_style('fullcalendar-css','https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css');
        wp_enqueue_script('fullcalendar-js','https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js',[],null,true);
        wp_enqueue_script('arm-appointments-admin', ARM_RE_URL.'assets/js/arm-appointments-admin.js',['jquery','fullcalendar-js'],ARM_RE_VERSION,true);
        wp_localize_script('arm-appointments-admin','ARM_APPT',[
            'ajax_url'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('arm_appt_nonce'),
        ]);
    }

    public static function render_calendar() {
        ?>
        <div class="wrap">
          <h1><?php _e('Appointments Calendar','arm-repair-estimates'); ?></h1>
          <div id="arm-calendar"></div>
        </div>
        <?php
    }

    public static function ajax_events() {
        check_ajax_referer('arm_appt_nonce','nonce');
        global $wpdb;
        $tbl=$wpdb->prefix.'arm_appointments';
        $rows=$wpdb->get_results("SELECT id,start_datetime,end_datetime,status FROM $tbl");
        $events=[];
        foreach($rows as $r){
            $events[]=[
              'id'=>$r->id,
              'title'=>'Appointment #'.$r->id,
              'start'=>$r->start_datetime,
              'end'=>$r->end_datetime,
              'color'=>($r->status==='confirmed'?'green':($r->status==='cancelled'?'red':'blue'))
            ];
        }
        wp_send_json($events);
    }

    public static function ajax_save_event() {
        check_ajax_referer('arm_appt_nonce','nonce');
        global $wpdb;
        $tbl=$wpdb->prefix.'arm_appointments';
        $id=intval($_POST['id']??0);
        $start=sanitize_text_field($_POST['start']);
        $end=sanitize_text_field($_POST['end']);
        if($id){
            $wpdb->update($tbl,['start_datetime'=>$start,'end_datetime'=>$end],['id'=>$id]);
        }
        wp_send_json_success();
    }


    public static function render_availability() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $tbl = $wpdb->prefix.'arm_availability';

        // Save hours
        if (!empty($_POST['arm_avail_nonce']) && wp_verify_nonce($_POST['arm_avail_nonce'],'arm_avail_save')) {
            $wpdb->query("DELETE FROM $tbl WHERE type='hours'");
            foreach ($_POST['hours'] as $dow=>$h) {
                if (empty($h['start']) || empty($h['end'])) {
                    continue;
                }
                $wpdb->insert($tbl,[
                    'type'=>'hours','day_of_week'=>$dow,
                    'start_time'=>$h['start'],'end_time'=>$h['end']
                ]);
            }
            echo '<div class="updated"><p>Hours saved.</p></div>';
        }

        // Add holiday
        if (!empty($_POST['arm_holiday_nonce']) && wp_verify_nonce($_POST['arm_holiday_nonce'],'arm_holiday_add')) {
            $wpdb->insert($tbl,[
                'type'=>'holiday',
                'date'=>sanitize_text_field($_POST['date']),
                'label'=>sanitize_text_field($_POST['label'])
            ]);
            echo '<div class="updated"><p>Holiday added.</p></div>';
        }

        // Delete holiday
        if (!empty($_GET['del_holiday']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'arm_holiday_del')) {
            $wpdb->delete($tbl,['id'=>intval($_GET['del_holiday'])]);
            echo '<div class="updated"><p>Holiday deleted.</p></div>';
        }

        // Load existing
        $hours = $wpdb->get_results("SELECT * FROM $tbl WHERE type='hours'", OBJECT_K);
        $holidays = $wpdb->get_results("SELECT * FROM $tbl WHERE type='holiday' ORDER BY date ASC");

        ?>
        <div class="wrap">
          <h1><?php _e('Availability Settings','arm-repair-estimates'); ?></h1>

          <h2><?php _e('Default Hours','arm-repair-estimates'); ?></h2>
          <form method="post">
            <?php wp_nonce_field('arm_avail_save','arm_avail_nonce'); ?>
            <table class="widefat striped">
              <thead><tr><th><?php _e('Day','arm-repair-estimates'); ?></th><th><?php _e('Start','arm-repair-estimates'); ?></th><th><?php _e('End','arm-repair-estimates'); ?></th></tr></thead>
              <tbody>
                <?php
                $days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                foreach ($days as $i=>$d):
                  $row=$hours[$i]??null;
                  ?>
                  <tr>
                    <td><?php echo esc_html($d); ?></td>
                    <td><input type="time" name="hours[<?php echo $i; ?>][start]" value="<?php echo esc_attr($row->start_time??''); ?>"></td>
                    <td><input type="time" name="hours[<?php echo $i; ?>][end]" value="<?php echo esc_attr($row->end_time??''); ?>"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php submit_button(__('Save Hours','arm-repair-estimates')); ?>
          </form>

          <h2><?php _e('Holidays','arm-repair-estimates'); ?></h2>
          <form method="post">
            <?php wp_nonce_field('arm_holiday_add','arm_holiday_nonce'); ?>
            <p>
              <label><?php _e('Date','arm-repair-estimates'); ?>:
                <input type="date" name="date" required>
              </label>
              <label><?php _e('Label','arm-repair-estimates'); ?>:
                <input type="text" name="label" required>
              </label>
              <?php submit_button(__('Add Holiday','arm-repair-estimates'),'secondary','',false); ?>
            </p>
          </form>
          <table class="widefat striped">
            <thead><tr><th><?php _e('Date'); ?></th><th><?php _e('Label'); ?></th><th><?php _e('Actions'); ?></th></tr></thead>
            <tbody>
              <?php foreach ($holidays as $h):
                $del=wp_nonce_url(add_query_arg(['del_holiday'=>$h->id]),'arm_holiday_del');
              ?>
              <tr>
                <td><?php echo esc_html($h->date); ?></td>
                <td><?php echo esc_html($h->label); ?></td>
                <td><a href="<?php echo esc_url($del); ?>" onclick="return confirm('Delete holiday?')">Delete</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

}
