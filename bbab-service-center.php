<?php
/**
 * Plugin Name: BBAB Service Center
 * Plugin URI: https://bradsbitsandbytes.com
 * Description: Complete client portal and service center for Brad's Bits and Bytes
 * Version: 2.0.0
 * Author: Brad Wales
 * Author URI: https://bradsbitsandbytes.com
 * Text Domain: bbab-service-center
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package BBAB\ServiceCenter
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BBAB_SC_VERSION', '2.0.0');
define('BBAB_SC_PATH', plugin_dir_path(__FILE__));
define('BBAB_SC_URL', plugin_dir_url(__FILE__));
define('BBAB_SC_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader
if (file_exists(BBAB_SC_PATH . 'vendor/autoload.php')) {
    require_once BBAB_SC_PATH . 'vendor/autoload.php';
} else {
    // Composer not installed - show admin notice and bail
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BBAB Service Center:</strong> Composer dependencies not installed. ';
        echo 'Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}

/**
 * Compatibility layer for snippets that haven't been migrated yet.
 *
 * These global functions wrap the namespaced service methods so old snippets
 * can continue to call them during the migration process.
 *
 * TODO: Remove these after all dependent snippets are migrated.
 */

if (!function_exists('bbab_get_sr_total_hours')) {
    /**
     * Get total hours for a service request.
     *
     * Compatibility wrapper for snippet 1716 (SR columns) until it's migrated in Session 4.3.
     *
     * @param int $sr_id Service request ID.
     * @return float Total billable hours.
     */
    function bbab_get_sr_total_hours(int $sr_id): float {
        return \BBAB\ServiceCenter\Modules\ServiceRequests\ServiceRequestService::getTotalHours($sr_id);
    }
}

if (!function_exists('bbab_generate_sr_reference')) {
    /**
     * Generate a new SR reference number.
     *
     * Compatibility wrapper in case any other code calls this function.
     *
     * @return string Reference number in SR-0001 format.
     */
    function bbab_generate_sr_reference(): string {
        return \BBAB\ServiceCenter\Modules\ServiceRequests\ReferenceGenerator::generate();
    }
}

if (!function_exists('bbab_generate_invoice_number')) {
    /**
     * Generate next invoice number in BBB-YYMM-NNN format.
     *
     * Compatibility wrapper - snippet 1995 deactivated in Phase 5.3.
     *
     * @param string|null $invoice_date Date string (any format parseable by strtotime).
     * @return string Next invoice number.
     */
    function bbab_generate_invoice_number(?string $invoice_date = null): string {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceReferenceGenerator::generateNumber($invoice_date);
    }
}

if (!function_exists('bbab_create_invoice_line_item')) {
    /**
     * Create a single invoice line item.
     *
     * Compatibility wrapper - snippet 1996 partially deactivated in Phase 5.3.
     *
     * @param int   $invoice_id Invoice post ID.
     * @param array $data       Line item data.
     * @return int|WP_Error Line item post ID or error.
     */
    function bbab_create_invoice_line_item(int $invoice_id, array $data): int|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\LineItemService::create($invoice_id, $data);
    }
}

if (!function_exists('bbab_generate_invoice_from_milestone')) {
    /**
     * Generate invoice from milestone.
     *
     * Compatibility wrapper - snippet 2398 deactivated in Phase 5.4.
     *
     * @param int $milestone_id Milestone post ID.
     * @return int|WP_Error Invoice ID or error.
     */
    function bbab_generate_invoice_from_milestone(int $milestone_id): int|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator::fromMilestone($milestone_id);
    }
}

if (!function_exists('bbab_generate_closeout_invoice')) {
    /**
     * Generate closeout invoice from project.
     *
     * Compatibility wrapper - snippet 2463 deactivated in Phase 5.4.
     *
     * @param int $project_id Project post ID.
     * @return int|WP_Error Invoice ID or error.
     */
    function bbab_generate_closeout_invoice(int $project_id): int|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator::closeoutFromProject($project_id);
    }
}

if (!function_exists('bbab_get_milestone_total_hours')) {
    /**
     * Get milestone total billable hours.
     *
     * Compatibility wrapper - snippet 2052 deactivated in Phase 5.4.
     *
     * @param int $milestone_id Milestone post ID.
     * @return float Total billable hours.
     */
    function bbab_get_milestone_total_hours(int $milestone_id): float {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator::getMilestoneTotalHours($milestone_id);
    }
}

if (!function_exists('bbab_get_report_total_hours')) {
    /**
     * Get total billable hours for a monthly report.
     *
     * Compatibility wrapper - snippet 1062 deactivated in Phase 6.1.
     * Called by [hours_progress_bar] shortcode.
     *
     * @param int $report_id Monthly report post ID.
     * @return float Total billable hours.
     */
    function bbab_get_report_total_hours(int $report_id): float {
        return \BBAB\ServiceCenter\Modules\Billing\MonthlyReportService::getTotalHours($report_id);
    }
}

if (!function_exists('bbab_get_report_free_hours_limit')) {
    /**
     * Get free hours limit for a monthly report.
     *
     * Compatibility wrapper - snippet 1062 deactivated in Phase 6.1.
     * Called by [hours_progress_bar] shortcode.
     *
     * @param int $report_id Monthly report post ID.
     * @return float Free hours limit.
     */
    function bbab_get_report_free_hours_limit(int $report_id): float {
        return \BBAB\ServiceCenter\Modules\Billing\MonthlyReportService::getFreeHoursLimit($report_id);
    }
}

if (!function_exists('bbab_generate_invoice_pdf')) {
    /**
     * Generate PDF for an invoice.
     *
     * Compatibility wrapper - snippet 2113 deactivated in Phase 6.2.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string|WP_Error Path to generated PDF or error.
     */
    function bbab_generate_invoice_pdf(int $invoice_id): string|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\PDFService::generateInvoicePDF($invoice_id);
    }
}

if (!function_exists('bbab_record_invoice_payment')) {
    /**
     * Record a payment on an invoice.
     *
     * Compatibility wrapper - snippet 2136 deactivated in Phase 6.3.
     *
     * @param int    $invoice_id     Invoice post ID.
     * @param float  $amount         Payment amount.
     * @param string $method         Payment method: 'stripe', 'ach', 'zelle'.
     * @param string $transaction_id Optional transaction/payment intent ID.
     * @param float  $cc_fee         Optional CC fee amount.
     * @return bool True on success.
     */
    function bbab_record_invoice_payment(int $invoice_id, float $amount, string $method, string $transaction_id = '', float $cc_fee = 0): bool {
        static $stripe_service = null;
        if ($stripe_service === null) {
            $stripe_service = new \BBAB\ServiceCenter\Modules\Billing\StripeService();
        }
        return $stripe_service->recordPayment($invoice_id, $amount, $method, $transaction_id, $cc_fee);
    }
}

if (!function_exists('bbab_get_overdue_invoice_count')) {
    /**
     * Get count of overdue invoices.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @return int Number of overdue invoices.
     */
    function bbab_get_overdue_invoice_count(): int {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getOverdueInvoiceCount();
    }
}

if (!function_exists('bbab_get_overdue_invoices')) {
    /**
     * Get overdue invoices with details.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @return array Array of overdue invoice data.
     */
    function bbab_get_overdue_invoices(): array {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getOverdueInvoices();
    }
}

if (!function_exists('bbab_invoice_has_late_fee')) {
    /**
     * Check if invoice has a late fee.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @param int $invoice_id Invoice post ID.
     * @return bool True if has late fee.
     */
    function bbab_invoice_has_late_fee(int $invoice_id): bool {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::invoiceHasLateFee($invoice_id);
    }
}

if (!function_exists('bbab_get_reports_needing_invoices')) {
    /**
     * Get monthly reports needing invoices.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @return array Array of reports needing invoices.
     */
    function bbab_get_reports_needing_invoices(): array {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getReportsNeedingInvoices();
    }
}

if (!function_exists('bbab_get_invoices_due_soon')) {
    /**
     * Get invoices due within X days.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @param int $days Number of days to look ahead.
     * @return array Array of invoices due soon.
     */
    function bbab_get_invoices_due_soon(int $days = 2): array {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getInvoicesDueSoon($days);
    }
}

if (!function_exists('bbab_get_new_service_requests')) {
    /**
     * Get new/unacknowledged service requests.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @return array Array of new service requests.
     */
    function bbab_get_new_service_requests(): array {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getNewServiceRequests();
    }
}

if (!function_exists('bbab_get_in_progress_sr_count')) {
    /**
     * Get count of in-progress service requests.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @return int Number of in-progress SRs.
     */
    function bbab_get_in_progress_sr_count(): int {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getInProgressSRCount();
    }
}

if (!function_exists('bbab_get_overdue_tasks')) {
    /**
     * Get overdue client tasks.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @return array Array of overdue tasks.
     */
    function bbab_get_overdue_tasks(): array {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getOverdueTasks();
    }
}

if (!function_exists('bbab_get_tasks_due_soon')) {
    /**
     * Get tasks due soon.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @param int $days Number of days to look ahead.
     * @return array Array of tasks due soon.
     */
    function bbab_get_tasks_due_soon(int $days = 3): array {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getTasksDueSoon($days);
    }
}

if (!function_exists('bbab_get_total_alert_count')) {
    /**
     * Get total alert count for menu badge.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @return int Total number of alerts.
     */
    function bbab_get_total_alert_count(): int {
        return \BBAB\ServiceCenter\Modules\Billing\BillingAlerts::getTotalAlertCount();
    }
}

if (!function_exists('bbab_add_late_fee_to_invoice')) {
    /**
     * Add a late fee line item to an invoice.
     *
     * Compatibility wrapper - snippet 2207 deactivated in Phase 6.3.
     *
     * @param int $invoice_id Invoice post ID.
     * @return int|WP_Error Line item ID or error.
     */
    function bbab_add_late_fee_to_invoice(int $invoice_id): int|\WP_Error {
        static $billing_cron = null;
        if ($billing_cron === null) {
            $billing_cron = new \BBAB\ServiceCenter\Cron\BillingCronHandler();
        }
        return $billing_cron->addLateFee($invoice_id);
    }
}

// CRITICAL: Bootstrap simulation EARLY (before any other plugin code)
// This runs on plugins_loaded priority 1, before anything else queries data
add_action('plugins_loaded', function() {
    \BBAB\ServiceCenter\Core\SimulationBootstrap::init();
}, 1);

// Activation hook
register_activation_hook(__FILE__, function() {
    \BBAB\ServiceCenter\Core\Activator::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    \BBAB\ServiceCenter\Core\Deactivator::deactivate();
});

// Initialize the plugin on plugins_loaded (normal priority)
add_action('plugins_loaded', function() {
    $plugin = new \BBAB\ServiceCenter\Core\Plugin();
    $plugin->run();
}, 10);
