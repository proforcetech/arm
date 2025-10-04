<?php
namespace ARM\Appointments;

if (!defined('ABSPATH')) exit;

/**
 * Admin screen for defining business hours and holidays.
 */
final class Admin_Availability
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_page']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'arm-repair-estimates',
            __('Availability', 'arm-repair-estimates'),
            __('Availability', 'arm-repair-estimates'),
            'manage_options',
            'arm-availability',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'arm_availability';

        if (!empty($_POST['arm_avail_nonce']) && wp_verify_nonce($_POST['arm_avail_nonce'], 'arm_avail_save')) {
            $wpdb->query("DELETE FROM $table WHERE type='hours'");
            if (!empty($_POST['hours']) && is_array($_POST['hours'])) {
                foreach ($_POST['hours'] as $day => $row) {
                    $start = sanitize_text_field($row['start'] ?? '');
                    $end   = sanitize_text_field($row['end'] ?? '');
                    if (!$start || !$end) continue;
                    $wpdb->insert($table, [
                        'type'        => 'hours',
                        'day_of_week' => (int) $day,
                        'start_time'  => $start,
                        'end_time'    => $end,
                    ]);
                }
            }

            $wpdb->query("DELETE FROM $table WHERE type='holiday'");
            if (!empty($_POST['holiday_date']) && is_array($_POST['holiday_date'])) {
                foreach ($_POST['holiday_date'] as $i => $date) {
                    $date = sanitize_text_field($date);
                    if (!$date) continue;
                    $label = sanitize_text_field($_POST['holiday_label'][$i] ?? '');
                    $wpdb->insert($table, [
                        'type'  => 'holiday',
                        'date'  => $date,
                        'label' => $label,
                    ]);
                }
            }

            echo '<div class="updated"><p>' . esc_html__('Availability saved.', 'arm-repair-estimates') . '</p></div>';
        }

        $hours = $wpdb->get_results("SELECT * FROM $table WHERE type='hours'", OBJECT_K);
        $holidays = $wpdb->get_results("SELECT * FROM $table WHERE type='holiday' ORDER BY date ASC");

        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        ?>
        <div class="wrap">
          <h1><?php _e('Availability Settings', 'arm-repair-estimates'); ?></h1>
          <form method="post">
            <?php wp_nonce_field('arm_avail_save', 'arm_avail_nonce'); ?>
            <h2><?php _e('Weekly Hours', 'arm-repair-estimates'); ?></h2>
            <table class="form-table">
              <?php foreach ($days as $i => $day):
                $row = $hours[$i] ?? null;
              ?>
              <tr>
                <th><?php echo esc_html($day); ?></th>
                <td>
                  <input type="time" name="hours[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr($row->start_time ?? ''); ?>">
                  —
                  <input type="time" name="hours[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr($row->end_time ?? ''); ?>">
                </td>
              </tr>
              <?php endforeach; ?>
            </table>

            <h2><?php _e('Holidays / Closed Dates', 'arm-repair-estimates'); ?></h2>
            <table class="form-table" id="arm-holiday-table">
              <tr><th><?php _e('Date', 'arm-repair-estimates'); ?></th><th><?php _e('Label', 'arm-repair-estimates'); ?></th><th></th></tr>
              <?php if ($holidays): foreach ($holidays as $holiday): ?>
              <tr>
                <td><input type="date" name="holiday_date[]" value="<?php echo esc_attr($holiday->date); ?>"></td>
                <td><input type="text" name="holiday_label[]" value="<?php echo esc_attr($holiday->label); ?>"></td>
                <td><button type="button" class="button arm-del">&times;</button></td>
              </tr>
              <?php endforeach; endif; ?>
              <tr>
                <td><input type="date" name="holiday_date[]"></td>
                <td><input type="text" name="holiday_label[]"></td>
                <td><button type="button" class="button arm-del">&times;</button></td>
              </tr>
            </table>
            <p><button type="button" class="button" id="arm-add-holiday"><?php _e('+ Add Holiday', 'arm-repair-estimates'); ?></button></p>

            <?php submit_button(__('Save Availability', 'arm-repair-estimates')); ?>
          </form>
        </div>
        <script>
        jQuery(function($){
          $('#arm-add-holiday').on('click', function(){
            $('#arm-holiday-table').append('<tr><td><input type="date" name="holiday_date[]"></td><td><input type="text" name="holiday_label[]"></td><td><button type="button" class="button arm-del">&times;</button></td></tr>');
          });
          $(document).on('click', '.arm-del', function(){
            $(this).closest('tr').remove();
          });
        });
        </script>
        <?php
    }
}
