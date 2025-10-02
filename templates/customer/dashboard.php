<?php
/**
 * Customer dashboard template.
 * Variables passed in: $customer, $customer_display_name, $vehicles, $vehicle_fields,
 * $estimates, $invoices, $global_empty, $impersonating, $vehicles_table_available.
 */

if (!defined('ABSPATH')) {
    exit;
}

$vehicleFieldOrder = array_keys($vehicle_fields);
$vehicleHasNotes    = in_array('notes', $vehicleFieldOrder, true);
$vehicleColumns     = array_filter($vehicleFieldOrder, static fn($field) => $field !== 'notes');

$columnLabels = array_map(static function ($field) use ($vehicle_fields) {
    return $vehicle_fields[$field]['label'] ?? ucfirst(str_replace('_', ' ', $field));
}, $vehicleColumns);

$notesLabel = $vehicleHasNotes ? ($vehicle_fields['notes']['label'] ?? __('Notes', 'arm-repair-estimates')) : '';
?>
<div class="arm-dashboard"<?php if ($impersonating) : ?> data-arm-impersonating="1"<?php endif; ?>>
  <header class="arm-dashboard__header">
    <h2><?php echo esc_html(sprintf(__('Welcome, %s', 'arm-repair-estimates'), $customer_display_name)); ?></h2>
    <p class="arm-dashboard__meta">
      <?php if (!empty($customer->email)) : ?>
        <span><?php echo esc_html($customer->email); ?></span>
      <?php endif; ?>
      <?php if (!empty($customer->phone)) : ?>
        <span><?php echo esc_html($customer->phone); ?></span>
      <?php endif; ?>
    </p>
    <?php if ($impersonating) : ?>
      <div class="arm-dashboard__impersonation" role="alert">
        <?php esc_html_e('You are viewing this dashboard in impersonation mode.', 'arm-repair-estimates'); ?>
      </div>
    <?php endif; ?>
  </header>

  <?php if ($global_empty) : ?>
    <div class="arm-dashboard__empty" role="alert">
      <?php esc_html_e('There are no saved vehicles, estimates, or invoices yet. New activity will appear here automatically.', 'arm-repair-estimates'); ?>
    </div>
  <?php endif; ?>

  <nav class="arm-tabs" aria-label="<?php esc_attr_e('Customer dashboard sections', 'arm-repair-estimates'); ?>">
    <button type="button" class="active" data-tab="vehicles"><?php esc_html_e('Vehicles', 'arm-repair-estimates'); ?></button>
    <button type="button" data-tab="estimates"><?php esc_html_e('Estimates', 'arm-repair-estimates'); ?></button>
    <button type="button" data-tab="invoices"><?php esc_html_e('Invoices', 'arm-repair-estimates'); ?></button>
  </nav>

  <section id="arm-tab-vehicles" class="arm-tab active" aria-labelledby="arm-tab-vehicles">
    <h3><?php esc_html_e('My Vehicles', 'arm-repair-estimates'); ?></h3>

    <?php if (!$vehicles_table_available) : ?>
      <p class="arm-dashboard__notice">
        <?php esc_html_e('Vehicle records are currently unavailable. Please contact support.', 'arm-repair-estimates'); ?>
      </p>
    <?php else : ?>
      <?php if ($vehicles) : ?>
        <table class="widefat striped arm-dashboard__table">
          <thead>
            <tr>
              <?php foreach ($columnLabels as $label) : ?>
                <th scope="col"><?php echo esc_html($label); ?></th>
              <?php endforeach; ?>
              <?php if ($vehicleHasNotes) : ?>
                <th scope="col"><?php echo esc_html($notesLabel); ?></th>
              <?php endif; ?>
              <th scope="col"><?php esc_html_e('Actions', 'arm-repair-estimates'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($vehicles as $vehicle) :
                $vehicleData = [];
                foreach ($vehicle_fields as $key => $definition) {
                    $vehicleData[$key] = $vehicle[$key] ?? '';
                }
            ?>
              <tr data-vehicle="<?php echo esc_attr(wp_json_encode($vehicleData)); ?>">
                <?php foreach ($vehicleColumns as $column) :
                    $value = $vehicle[$column] ?? '';
                ?>
                  <td data-label="<?php echo esc_attr($vehicle_fields[$column]['label'] ?? $column); ?>">
                    <?php echo esc_html($value); ?>
                  </td>
                <?php endforeach; ?>
                <?php if ($vehicleHasNotes) :
                    $noteValue = $vehicle['notes'] ?? '';
                ?>
                  <td data-label="<?php echo esc_attr($notesLabel); ?>">
                    <?php echo nl2br(esc_html($noteValue)); ?>
                  </td>
                <?php endif; ?>
                <td class="arm-dashboard__actions">
                  <button type="button" class="arm-edit-vehicle" data-id="<?php echo (int) ($vehicle['id'] ?? 0); ?>">
                    <?php esc_html_e('Edit', 'arm-repair-estimates'); ?>
                  </button>
                  <button type="button" class="arm-del-vehicle" data-id="<?php echo (int) ($vehicle['id'] ?? 0); ?>">
                    <?php esc_html_e('Delete', 'arm-repair-estimates'); ?>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else : ?>
        <p class="arm-dashboard__empty-section"><?php esc_html_e('No vehicles have been saved yet.', 'arm-repair-estimates'); ?></p>
      <?php endif; ?>

      <button type="button" class="arm-add-vehicle arm-btn">
        <?php esc_html_e('Add Vehicle', 'arm-repair-estimates'); ?>
      </button>

      <div id="arm-vehicle-form" class="arm-dashboard__form" style="display:none;">
        <h4><?php esc_html_e('Vehicle Details', 'arm-repair-estimates'); ?></h4>
        <form>
          <input type="hidden" name="id" value="">
          <?php foreach ($vehicle_fields as $key => $definition) :
            $label = $definition['label'] ?? ucfirst($key);
            $type  = $definition['type'] ?? 'text';
            $required = !empty($definition['required']);
          ?>
            <label>
              <span><?php echo esc_html($label); ?><?php if ($required) : ?><span class="arm-required">*</span><?php endif; ?></span>
              <?php if ($type === 'textarea') : ?>
                <textarea name="<?php echo esc_attr($key); ?>" rows="3"<?php if ($required) : ?> required<?php endif; ?>></textarea>
              <?php elseif ($type === 'number') : ?>
                <input type="number" name="<?php echo esc_attr($key); ?>"<?php if ($required) : ?> required<?php endif; ?>>
              <?php else : ?>
                <input type="text" name="<?php echo esc_attr($key); ?>"<?php if ($required) : ?> required<?php endif; ?>>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
          <button type="submit" class="arm-btn arm-save-vehicle"><?php esc_html_e('Save Vehicle', 'arm-repair-estimates'); ?></button>
        </form>
      </div>
    <?php endif; ?>
  </section>

  <section id="arm-tab-estimates" class="arm-tab" aria-labelledby="arm-tab-estimates">
    <h3><?php esc_html_e('Estimates', 'arm-repair-estimates'); ?></h3>
    <?php $tab_estimates = locate_template(['arm/customer/tab-estimates.php', 'arm/tab-estimates.php']);
    if (!$tab_estimates) {
        $tab_estimates = ARM_RE_PATH . 'templates/customer/tab-estimates.php';
    }
    if (file_exists($tab_estimates)) {
        include $tab_estimates;
    }
    ?>
    <?php if ($estimates) : ?>
      <ul class="arm-dashboard__list">
        <?php foreach ($estimates as $estimate) :
            $number = $estimate['estimate_no'] !== '' ? $estimate['estimate_no'] : '#' . $estimate['id'];
        ?>
          <li>
            <a href="<?php echo esc_url($estimate['link']); ?>" target="_blank" rel="noopener">
              <?php echo esc_html(sprintf('%s — %s — %s', $number, $estimate['status'], $estimate['created_at'])); ?>
            </a>
            <span class="arm-dashboard__total"><?php echo esc_html(sprintf(__('Total: %s', 'arm-repair-estimates'), \ARM\Utils\Helpers::money($estimate['total']))); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else : ?>
      <p class="arm-dashboard__empty-section"><?php esc_html_e('No estimates available.', 'arm-repair-estimates'); ?></p>
    <?php endif; ?>
  </section>

  <section id="arm-tab-invoices" class="arm-tab" aria-labelledby="arm-tab-invoices">
    <h3><?php esc_html_e('Invoices', 'arm-repair-estimates'); ?></h3>
    <?php $tab_invoices = locate_template(['arm/customer/tab-invoices.php', 'arm/tab-invoices.php']);
    if (!$tab_invoices) {
        $tab_invoices = ARM_RE_PATH . 'templates/customer/tab-invoices.php';
    }
    if (file_exists($tab_invoices)) {
        include $tab_invoices;
    }
    ?>
    <?php if ($invoices) : ?>
      <ul class="arm-dashboard__list">
        <?php foreach ($invoices as $invoice) :
            $number = $invoice['invoice_no'] !== '' ? $invoice['invoice_no'] : '#' . $invoice['id'];
        ?>
          <li>
            <a href="<?php echo esc_url($invoice['link']); ?>" target="_blank" rel="noopener">
              <?php echo esc_html(sprintf('%s — %s — %s', $number, $invoice['status'], $invoice['created_at'])); ?>
            </a>
            <span class="arm-dashboard__total"><?php echo esc_html(sprintf(__('Total: %s', 'arm-repair-estimates'), \ARM\Utils\Helpers::money($invoice['total']))); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else : ?>
      <p class="arm-dashboard__empty-section"><?php esc_html_e('No invoices available.', 'arm-repair-estimates'); ?></p>
    <?php endif; ?>
  </section>
</div>
