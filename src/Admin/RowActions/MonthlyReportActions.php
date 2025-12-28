<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\RowActions;

use BBAB\ServiceCenter\Modules\Billing\InvoiceService;
use BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Row actions for Monthly Reports.
 *
 * Handles:
 * - Generate Invoice action
 * - View Invoice link (if invoice exists)
 *
 * Migrated from snippet: 1997
 */
class MonthlyReportActions {

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Add row actions to monthly_report list
        add_filter('post_row_actions', [self::class, 'addRowActions'], 10, 2);

        // Handle invoice generation action
        add_action('admin_post_bbab_generate_report_invoice', [self::class, 'handleGeneration']);

        // Admin notices
        add_action('admin_notices', [self::class, 'showAdminNotices']);

        Logger::debug('MonthlyReportActions', 'Registered monthly report row actions');
    }

    /**
     * Add row actions to monthly reports.
     *
     * @param array    $actions Existing actions.
     * @param \WP_Post $post    Post object.
     * @return array Modified actions.
     */
    public static function addRowActions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'monthly_report') {
            return $actions;
        }

        // Check if invoice already exists for this report
        $existing_invoice = InvoiceService::getForMonthlyReport($post->ID);

        if ($existing_invoice) {
            // Show "View Invoice" link
            $edit_link = get_edit_post_link($existing_invoice->ID);
            $invoice_number = InvoiceService::getNumber($existing_invoice->ID) ?: 'INV-' . $existing_invoice->ID;
            $status = InvoiceService::getStatus($existing_invoice->ID);

            $style = 'color: #1e8449;';
            if ($status === 'Draft') {
                $style = 'color: #856404;';
            } elseif ($status === 'Overdue') {
                $style = 'color: #c62828;';
            }

            $actions['view_invoice'] = sprintf(
                '<a href="%s" style="%s">View Invoice (%s - %s)</a>',
                esc_url($edit_link),
                $style,
                esc_html($invoice_number),
                esc_html($status)
            );
        } else {
            // Show "Generate Invoice" link
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_generate_report_invoice&report_id=' . $post->ID),
                'bbab_generate_report_invoice_' . $post->ID
            );
            $actions['generate_invoice'] = '<a href="' . esc_url($url) . '" style="color: #2271b1; font-weight: 500;">Generate Invoice</a>';
        }

        return $actions;
    }

    /**
     * Handle invoice generation from monthly report.
     */
    public static function handleGeneration(): void {
        $report_id = isset($_GET['report_id']) ? (int) $_GET['report_id'] : 0;

        // Verify nonce
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_generate_report_invoice_' . $report_id)) {
            wp_die('Security check failed');
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to perform this action');
        }

        // Validate report
        $report = get_post($report_id);
        if (!$report || $report->post_type !== 'monthly_report') {
            wp_die('Invalid Monthly Report');
        }

        // Generate the invoice
        $result = InvoiceGenerator::fromMonthlyReport($report_id);

        if (is_wp_error($result)) {
            // Check if invoice already exists
            $data = $result->get_error_data();
            if (!empty($data['invoice_id'])) {
                // Redirect to existing invoice
                wp_redirect(admin_url('post.php?post=' . $data['invoice_id'] . '&action=edit&existing_invoice=1'));
                exit;
            }

            wp_redirect(admin_url('edit.php?post_type=monthly_report&report_invoice_error=' . urlencode($result->get_error_message())));
            exit;
        }

        // Redirect to the new invoice
        wp_redirect(admin_url('post.php?post=' . $result . '&action=edit&report_invoice_created=1'));
        exit;
    }

    /**
     * Show admin notices for generation results.
     */
    public static function showAdminNotices(): void {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        if (isset($_GET['report_invoice_created']) && $_GET['report_invoice_created'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Invoice created from Monthly Report!</strong> Review the line items and click "Finalize" when ready.</p></div>';
        }

        if (isset($_GET['existing_invoice']) && $_GET['existing_invoice'] === '1') {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Invoice already exists for this report.</strong> You are viewing the existing invoice.</p></div>';
        }

        if (isset($_GET['report_invoice_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Invoice generation failed:</strong> ' . esc_html($_GET['report_invoice_error']) . '</p></div>';
        }
    }
}
