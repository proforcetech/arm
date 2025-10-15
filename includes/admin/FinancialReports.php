<?php
namespace ARM\Admin;

use ARM\Accounting\Transactions;

if (!defined('ABSPATH')) exit;

class FinancialReports
{
    public static function boot() {}

    public static function render(): void
    {
        if (!current_user_can(Transactions::capability())) {
            wp_die(__('You do not have permission to access this screen.', 'arm-repair-estimates'));
        }

        if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
            self::export();
        }

        $filters = [
            'from' => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
            'to'   => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
        ];

        $totals = [
            'income'    => Transactions::totals('income', array_filter($filters)),
            'expenses'  => Transactions::totals('expense', array_filter($filters)),
            'purchases' => Transactions::totals('purchase', array_filter($filters)),
        ];
        $totals['net'] = $totals['income'] - $totals['expenses'] - $totals['purchases'];

        $monthly = self::build_monthly_summary($filters);

        include ARM_RE_PATH . 'templates/admin/accounting-reports.php';
    }

    private static function build_monthly_summary(array $filters): array
    {
        $monthly = [];
        $income = Transactions::monthly_summary('income', array_filter($filters));
        $expenses = Transactions::monthly_summary('expense', array_filter($filters));
        $purchases = Transactions::monthly_summary('purchase', array_filter($filters));

        foreach ($income as $row) {
            $monthly[$row['period']]['income'] = (float) $row['total'];
        }
        foreach ($expenses as $row) {
            $monthly[$row['period']]['expenses'] = (float) $row['total'];
        }
        foreach ($purchases as $row) {
            $monthly[$row['period']]['purchases'] = (float) $row['total'];
        }

        krsort($monthly);

        $normalized = [];
        foreach ($monthly as $period => $values) {
            $incomeTotal = $values['income'] ?? 0.0;
            $expenseTotal = $values['expenses'] ?? 0.0;
            $purchaseTotal = $values['purchases'] ?? 0.0;
            $normalized[] = [
                'period'    => $period,
                'income'    => $incomeTotal,
                'expenses'  => $expenseTotal,
                'purchases' => $purchaseTotal,
                'net'       => $incomeTotal - $expenseTotal - $purchaseTotal,
            ];
        }

        return $normalized;
    }

    private static function export(): void
    {
        $filters = [
            'from' => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
            'to'   => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
        ];
        $monthly = self::build_monthly_summary($filters);

        nocache_headers();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="financial-summary-' . gmdate('Ymd-His') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Period', 'Income', 'Expenses', 'Purchases', 'Net']);
        foreach ($monthly as $row) {
            fputcsv($output, [
                $row['period'],
                number_format($row['income'], 2, '.', ''),
                number_format($row['expenses'], 2, '.', ''),
                number_format($row['purchases'], 2, '.', ''),
                number_format($row['net'], 2, '.', ''),
            ]);
        }
        fclose($output);
        exit;
    }
}
