<?php
namespace ARM\Vehicles;

if (!defined('ABSPATH')) exit;

/**
 * CSV Importer for Vehicle dimension (Year, Make, Model, Engine, Transmission, Drive, Trim).
 * Menu: Repair Estimates → Vehicle CSV Import
 */
class CSVImportController {

    public static function boot() {
        add_action('admin_menu', function(){
            add_submenu_page(
                'arm-repair-estimates',
                __('Vehicle CSV Import','arm-repair-estimates'),
                __('Vehicle CSV Import','arm-repair-estimates'),
                'manage_options',
                'arm-repair-vehicles-csv',
                [__CLASS__, 'render_admin']
            );
        });
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;

        $result = null;
        if (!empty($_POST['arm_csv_nonce']) && wp_verify_nonce($_POST['arm_csv_nonce'], 'arm_csv_import')) {
            $result = self::handle_upload_and_import();
        }
        ?>
        <div class="wrap">
          <h1><?php _e('Vehicle CSV Import','arm-repair-estimates'); ?></h1>
          <p><?php _e('Upload a CSV file with columns: Year, Make, Model, Engine, Transmission, Drive, Trim. Header row is required.','arm-repair-estimates'); ?></p>

          <?php if ($result && !empty($result['message'])): ?>
            <div class="<?php echo $result['ok'] ? 'updated' : 'notice notice-error'; ?>"><p><?php echo esc_html($result['message']); ?></p></div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('arm_csv_import','arm_csv_nonce'); ?>
            <input type="file" name="arm_csv" accept=".csv,text/csv" required>
            <?php submit_button(__('Import CSV','arm-repair-estimates')); ?>
          </form>

          <h2><?php _e('Sample CSV','arm-repair-estimates'); ?></h2>
<pre>
Year,Make,Model,Engine,Transmission,Drive,Trim
2019,Toyota,Corolla,1.8L,Automatic,FWD,LE
2020,Honda,Civic,2.0L,Manual,FWD,EX
</pre>
        </div>
        <?php
    }

    private static function handle_upload_and_import() {
        if (empty($_FILES['arm_csv']['name'])) return ['ok'=>false,'message'=>__('No file uploaded.','arm-repair-estimates')];

        $overrides = ['test_form' => false, 'mimes'=>['csv'=>'text/csv','txt'=>'text/plain']];
        $upl = wp_handle_upload($_FILES['arm_csv'], $overrides);
        if (!empty($upl['error'])) return ['ok'=>false, 'message'=>$upl['error']];

        $path = $upl['file'];
        $fh = fopen($path, 'r');
        if (!$fh) return ['ok'=>false,'message'=>__('Unable to read uploaded file.','arm-repair-estimates')];

        $cols  = [];
        $line  = 0;
        $ok    = 0;
        $dupes = 0;
        $fail  = 0;

        global $wpdb; $tbl = $wpdb->prefix.'arm_vehicle_data';

        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if ($line === 1) {
                $cols = array_map(function($c){ return strtolower(trim($c)); }, $row);
                $need = ['year','make','model','engine','transmission','drive','trim'];
                foreach ($need as $n) {
                    if (!in_array($n, $cols, true)) {
                        fclose($fh);
                        return ['ok'=>false,'message'=>sprintf(__('Missing column: %s','arm-repair-estimates'), $n)];
                    }
                }
                continue;
            }

            $data = self::map_row($cols, $row);
            if (!$data) { $fail++; continue; }

            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO $tbl (year, make, model, engine, transmission, drive, trim, created_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%s)",
                (int)$data['year'], $data['make'], $data['model'], $data['engine'], $data['transmission'], $data['drive'], $data['trim'], current_time('mysql')
            );
            $res = $wpdb->query($sql);
            if ($res === false) { $fail++; }
            elseif ($res === 0) { $dupes++; }
            else { $ok++; }
        }
        fclose($fh);

        $msg = sprintf(
            /* translators: 1: ok, 2: duplicates, 3: failed */
            __('Import complete. Added: %1$d, Duplicates: %2$d, Failed: %3$d', 'arm-repair-estimates'),
            $ok, $dupes, $fail
        );
        return ['ok'=>true,'message'=>$msg];
    }

    private static function map_row(array $cols, array $row) {
        $map = array_combine($cols, $row);
        if (!$map) return null;
        $year = isset($map['year']) ? (int)$map['year'] : 0;
        $make = trim((string)($map['make'] ?? ''));
        $model= trim((string)($map['model'] ?? ''));
        $engine=trim((string)($map['engine'] ?? ''));
        $transmission = trim((string)($map['transmission'] ?? ''));
        $drive =trim((string)($map['drive'] ?? ''));
        $trim  =trim((string)($map['trim'] ?? ''));
        if ($year < 1900 || !$make || !$model || !$engine || !$transmission || !$drive || !$trim) return null;
        return compact('year','make','model','engine','transmission','drive','trim');
    }
}
