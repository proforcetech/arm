<?php
namespace ARM\Appointments;

class Admin_Availability {
    public static function boot() {
        add_submenu_page(
            'arm-repair-estimates',
            __('Availability','arm-repair-estimates'),
            __('Availability','arm-repair-estimates'),
            'manage_options',
            'arm-availability',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        global $wpdb;
        $tbl = $wpdb->prefix.'arm_availability';

        // Handle save
        if (!empty($_POST['arm_avail_nonce']) && wp_verify_nonce($_POST['arm_avail_nonce'],'arm_avail_save')) {
            if (!empty($_POST['hours'])) {
                $wpdb->query("DELETE FROM $tbl WHERE type='hours'");
                foreach ($_POST['hours'] as $day=>$row) {
                    if (empty($row['start']) || empty($row['end'])) continue;
                    $wpdb->insert($tbl,[
                        'type'=>'hours',
                        'day_of_week'=>(int)$day,
                        'start_time'=>sanitize_text_field($row['start']),
                        'end_time'=>sanitize_text_field($row['end']),
                    ]);
                }
            }
            if (!empty($_POST['holiday_date'])) {
                foreach ($_POST['holiday_date'] as $i=>$date) {
                    if (!$date) continue;
                    $wpdb->insert($tbl,[
                        'type'=>'holiday',
                        'date'=>sanitize_text_field($date),
                        'label'=>sanitize_text_field($_POST['holiday_label'][$i] ?? ''),
                    ]);
                }
            }
            echo '<div class="updated"><p>Saved.</p></div>';
        }

        $hours = $wpdb->get_results("SELECT * FROM $tbl WHERE type='hours'", OBJECT_K);
        $holidays = $wpdb->get_results("SELECT * FROM $tbl WHERE type='holiday' ORDER BY date ASC");
        ?>
        <div class="wrap">
          <h1><?php _e('Availability Settings','arm-repair-estimates'); ?></h1>
          <form method="post">
            <?php wp_nonce_field('arm_avail_save','arm_avail_nonce'); ?>

            <h2><?php _e('Weekly Hours','arm-repair-estimates'); ?></h2>
            <table class="form-table">
              <?php
              $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
              foreach ($days as $i=>$d):
                $row = $hours[$i] ?? null;
              ?>
              <tr>
                <th><?php echo esc_html($d); ?></th>
                <td>
                  <input type="time" name="hours[<?php echo $i; ?>][start]" value="<?php echo esc_attr($row->start_time ?? ''); ?>">
                  —
                  <input type="time" name="hours[<?php echo $i; ?>][end]" value="<?php echo esc_attr($row->end_time ?? ''); ?>">
                </td>
              </tr>
              <?php endforeach; ?>
            </table>

            <h2><?php _e('Holidays / Closed Dates','arm-repair-estimates'); ?></h2>
            <table class="form-table" id="arm-holiday-table">
              <tr><th>Date</th><th>Label</th><th></th></tr>
              <?php foreach ($holidays as $h): ?>
              <tr>
                <td><input type="date" name="holiday_date[]" value="<?php echo esc_attr($h->date); ?>"></td>
                <td><input type="text" name="holiday_label[]" value="<?php echo esc_attr($h->label); ?>"></td>
                <td><button type="button" class="button arm-del">&times;</button></td>
              </tr>
              <?php endforeach; ?>
              <tr>
                <td><input type="date" name="holiday_date[]"></td>
                <td><input type="text" name="holiday_label[]"></td>
                <td><button type="button" class="button arm-del">&times;</button></td>
              </tr>
            </table>
            <p><button type="button" class="button" id="arm-add-holiday">+ Add Holiday</button></p>

            <p class="submit"><button type="submit" class="button-primary">Save</button></p>
          </form>
        </div>
        <script>
        jQuery(function($){
          $('#arm-add-holiday').on('click',function(){
            $('#arm-holiday-table').append('<tr><td><input type="date" name="holiday_date[]"></td><td><input type="text" name="holiday_label[]"></td><td><button type="button" class="button arm-del">&times;</button></td></tr>');
          });
          $(document).on('click','.arm-del',function(){ $(this).closest('tr').remove(); });
        });
        </script>
        <?php
    }
}
