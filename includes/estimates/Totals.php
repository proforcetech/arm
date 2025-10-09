<?php
namespace ARM\Estimates;
if (!defined('ABSPATH')) exit;

class Totals {
    /**
     * Compute totals with tax rules + callout/mileage.
     * $items: array of ['type','qty','unit','taxable','line_total'] OR raw qty/unit to compute.
     */
    public static function compute(array $items, float $tax_rate, float $callout_fee = 0.0, float $mileage_qty = 0.0, float $mileage_rate = 0.0): array {
        $subtotal = 0.0; $taxable_base = 0.0;
        $apply = get_option('arm_re_tax_apply', 'parts_labor');

        foreach ($items as $it) {
            $type = strtoupper($it['type'] ?? 'LABOR');
            $qty  = (float)($it['qty'] ?? 1);
            $unit = (float)($it['unit'] ?? ($it['unit_price'] ?? 0));
            $line = isset($it['line_total']) ? (float)$it['line_total'] : (($type==='DISCOUNT'?-1:1) * $qty * $unit);
            $taxable = !empty($it['taxable']) ? 1 : 0;

            $subtotal += $line;

            $is_part   = ($type === 'PART');
            $is_labor  = ($type === 'LABOR');
            $is_fee    = in_array($type, ['FEE','CALLOUT','MILEAGE'], true);

            $tax_ok = false;
            if ($apply === 'parts_labor') {
                $tax_ok = $taxable === 1;
            } else {
                $tax_ok = $taxable === 1 && $is_part;
            }
            if ($tax_ok) $taxable_base += max(0, $line);
        }

        if ($callout_fee > 0) $subtotal += $callout_fee;
        if ($mileage_rate > 0 && $mileage_qty > 0) $subtotal += ($mileage_rate * $mileage_qty);

        $tax_amount = round($taxable_base * ($tax_rate / 100.0), 2);
        $total = round($subtotal + $tax_amount, 2);

        return [
            'subtotal'=> round($subtotal, 2),
            'tax_amount'=> $tax_amount,
            'total'=> $total
        ];
    }
}
