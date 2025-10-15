<?php
namespace ARM\Admin;

use ARM\Accounting\Transactions;

if (!defined('ABSPATH')) exit;

class Income
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

        $message = '';
        $message_type = 'success';

        if (!empty($_POST['arm_income_nonce']) && wp_verify_nonce($_POST['arm_income_nonce'], 'arm_income_save')) {
            $data = wp_unslash($_POST);
            $id = Transactions::save('income', [
                'id'               => $data['id'] ?? 0,
                'transaction_date' => $data['transaction_date'] ?? '',
                'category'         => $data['category'] ?? '',
                'amount'           => $data['amount'] ?? 0,
                'reference'        => $data['reference'] ?? '',
                'description'      => $data['description'] ?? '',
            ]);

            if ($id) {
                $message = __('Income entry saved.', 'arm-repair-estimates');
                $message_type = 'success';
            } else {
                $message = __('Unable to save income entry.', 'arm-repair-estimates');
                $message_type = 'error';
            }
        }

        if (!empty($_GET['delete']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'arm_income_delete')) {
            $deleted = Transactions::delete('income', (int) $_GET['delete']);
            if ($deleted) {
                $message = __('Income entry deleted.', 'arm-repair-estimates');
                $message_type = 'success';
            } else {
                $message = __('Unable to delete entry.', 'arm-repair-estimates');
                $message_type = 'error';
            }
        }

        $edit = null;
        if (!empty($_GET['edit'])) {
            $edit = Transactions::get('income', (int) $_GET['edit']);
        }

        $filters = [
            'from'     => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
            'to'       => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
            'category' => isset($_GET['category']) ? sanitize_text_field(wp_unslash($_GET['category'])) : '',
            'number'   => 100,
        ];

        $rows = Transactions::query('income', array_filter($filters));

        include ARM_RE_PATH . 'templates/admin/accounting-income.php';
    }

    private static function export(): void
    {
        $filters = [
            'from'     => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
            'to'       => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
            'category' => isset($_GET['category']) ? sanitize_text_field(wp_unslash($_GET['category'])) : '',
        ];

        $rows = Transactions::query('income', array_filter($filters));

        nocache_headers();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="income-export-' . gmdate('Ymd-His') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Date', 'Category', 'Amount', 'Reference', 'Description']);
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['id'],
                $row['transaction_date'],
                $row['category'],
                number_format((float) $row['amount'], 2, '.', ''),
                $row['reference'],
                wp_strip_all_tags($row['description']),
            ]);
        }
        fclose($output);
        exit;
    }
}
