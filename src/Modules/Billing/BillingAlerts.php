<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

/**
 * Billing Alerts Service.
 *
 * Provides alert counts and data for admin dashboard widgets.
 * These methods are used to display notifications about:
 * - Overdue invoices
 * - Invoices due soon
 * - Reports needing invoices
 * - New service requests
 * - Overdue tasks
 *
 * Migrated from snippet: 2207 (alert helper functions)
 */
class BillingAlerts {

    /**
     * Get count of overdue invoices.
     *
     * @return int Number of overdue invoices.
     */
    public static function getOverdueInvoiceCount(): int {
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [[
                'key' => 'invoice_status',
                'value' => 'Overdue',
                'compare' => '=',
            ]],
        ]);

        return count($invoices);
    }

    /**
     * Get overdue invoices with details for display.
     *
     * @return array Array of overdue invoice data.
     */
    public static function getOverdueInvoices(): array {
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'invoice_status',
                'value' => 'Overdue',
                'compare' => '=',
            ]],
        ]);

        $results = [];

        foreach ($invoices as $invoice) {
            $due_date = get_post_meta($invoice->ID, 'due_date', true);
            $days_overdue = (int) floor((strtotime('today') - strtotime($due_date)) / DAY_IN_SECONDS);
            $amount = floatval(get_post_meta($invoice->ID, 'amount', true));
            $amount_paid = floatval(get_post_meta($invoice->ID, 'amount_paid', true));
            $org_id = get_post_meta($invoice->ID, 'organization', true);
            $org_name = $org_id ? get_the_title((int) $org_id) : 'Unknown';

            $results[] = [
                'id' => $invoice->ID,
                'number' => get_post_meta($invoice->ID, 'invoice_number', true),
                'org_name' => $org_name,
                'balance' => $amount - $amount_paid,
                'days_overdue' => $days_overdue,
                'has_late_fee' => self::invoiceHasLateFee($invoice->ID),
            ];
        }

        // Sort by days overdue descending
        usort($results, function ($a, $b) {
            return $b['days_overdue'] - $a['days_overdue'];
        });

        return $results;
    }

    /**
     * Check if invoice already has a late fee.
     *
     * @param int $invoice_id Invoice post ID.
     * @return bool True if has late fee.
     */
    public static function invoiceHasLateFee(int $invoice_id): bool {
        $late_fee = get_posts([
            'post_type' => 'invoice_line_item',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'related_invoice',
                    'value' => $invoice_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'line_type',
                    'value' => 'Late Fee',
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($late_fee);
    }

    /**
     * Get Monthly Reports that need invoices generated.
     * Only relevant from 1st-7th of the month.
     *
     * @return array Array of reports needing invoices.
     */
    public static function getReportsNeedingInvoices(): array {
        $day_of_month = (int) date('j');

        // Only show this alert from 1st through 7th
        if ($day_of_month > 7) {
            return [];
        }

        // Get previous month's reports
        $prev_month = date('F Y', strtotime('first day of last month'));

        $reports = get_posts([
            'post_type' => 'monthly_report',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'report_month',
                'value' => $prev_month,
                'compare' => '=',
            ]],
        ]);

        $needs_invoice = [];

        foreach ($reports as $report) {
            // Check if invoice exists for this report
            $existing_invoice = get_posts([
                'post_type' => 'invoice',
                'posts_per_page' => 1,
                'post_status' => 'any',
                'fields' => 'ids',
                'meta_query' => [[
                    'key' => 'related_monthly_report',
                    'value' => $report->ID,
                    'compare' => '=',
                ]],
            ]);

            if (empty($existing_invoice)) {
                $org_id = get_post_meta($report->ID, 'organization', true);
                $needs_invoice[] = [
                    'id' => $report->ID,
                    'month' => $prev_month,
                    'org_name' => $org_id ? get_the_title((int) $org_id) : 'Unknown',
                ];
            }
        }

        return $needs_invoice;
    }

    /**
     * Get invoices due within X days.
     *
     * @param int $days Number of days to look ahead.
     * @return array Array of invoices due soon.
     */
    public static function getInvoicesDueSoon(int $days = 2): array {
        $today = current_time('Y-m-d');
        $target_date = date('Y-m-d', strtotime("+{$days} days"));

        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'invoice_status',
                    'value' => 'Pending',
                    'compare' => '=',
                ],
                [
                    'key' => 'due_date',
                    'value' => [$today, $target_date],
                    'compare' => 'BETWEEN',
                    'type' => 'DATE',
                ],
            ],
        ]);

        $results = [];

        foreach ($invoices as $invoice) {
            $due_date = get_post_meta($invoice->ID, 'due_date', true);
            $days_until = (int) floor((strtotime($due_date) - strtotime('today')) / DAY_IN_SECONDS);
            $amount = floatval(get_post_meta($invoice->ID, 'amount', true));
            $org_id = get_post_meta($invoice->ID, 'organization', true);

            $results[] = [
                'id' => $invoice->ID,
                'number' => get_post_meta($invoice->ID, 'invoice_number', true),
                'org_name' => $org_id ? get_the_title((int) $org_id) : 'Unknown',
                'amount' => $amount,
                'days_until' => $days_until,
            ];
        }

        return $results;
    }

    /**
     * Get new/unacknowledged Service Requests.
     *
     * @return array Array of new service requests.
     */
    public static function getNewServiceRequests(): array {
        $requests = get_posts([
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'request_status',
                'value' => 'New',
                'compare' => '=',
            ]],
        ]);

        $results = [];

        foreach ($requests as $sr) {
            $org_id = get_post_meta($sr->ID, 'organization', true);
            $results[] = [
                'id' => $sr->ID,
                'ref' => get_post_meta($sr->ID, 'reference_number', true),
                'subject' => get_post_meta($sr->ID, 'subject', true),
                'org_name' => $org_id ? get_the_title((int) $org_id) : 'Unknown',
            ];
        }

        return $results;
    }

    /**
     * Get in-progress Service Requests count.
     *
     * @return int Number of in-progress service requests.
     */
    public static function getInProgressSRCount(): int {
        $requests = get_posts([
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [[
                'key' => 'request_status',
                'value' => ['Acknowledged', 'In Progress', 'Waiting on Client', 'On Hold'],
                'compare' => 'IN',
            ]],
        ]);

        return count($requests);
    }

    /**
     * Get overdue Client Tasks.
     *
     * @return array Array of overdue tasks.
     */
    public static function getOverdueTasks(): array {
        $today = current_time('Y-m-d');

        $tasks = get_posts([
            'post_type' => 'client_task',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'task_status',
                    'value' => 'Pending',
                    'compare' => '=',
                ],
                [
                    'key' => 'due_date',
                    'value' => $today,
                    'compare' => '<',
                    'type' => 'DATE',
                ],
            ],
        ]);

        $results = [];

        foreach ($tasks as $task) {
            $due_date = get_post_meta($task->ID, 'due_date', true);
            $days_overdue = (int) floor((strtotime('today') - strtotime($due_date)) / DAY_IN_SECONDS);
            $org_id = get_post_meta($task->ID, 'client_organization', true);

            $results[] = [
                'id' => $task->ID,
                'description' => get_post_meta($task->ID, 'task_description', true),
                'org_name' => $org_id ? get_the_title((int) $org_id) : 'Unknown',
                'days_overdue' => $days_overdue,
            ];
        }

        // Sort by days overdue descending
        usort($results, function ($a, $b) {
            return $b['days_overdue'] - $a['days_overdue'];
        });

        return $results;
    }

    /**
     * Get tasks due soon.
     *
     * @param int $days Number of days to look ahead.
     * @return array Array of tasks due soon.
     */
    public static function getTasksDueSoon(int $days = 3): array {
        $today = current_time('Y-m-d');
        $target_date = date('Y-m-d', strtotime("+{$days} days"));

        $tasks = get_posts([
            'post_type' => 'client_task',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'task_status',
                    'value' => 'Pending',
                    'compare' => '=',
                ],
                [
                    'key' => 'due_date',
                    'value' => [$today, $target_date],
                    'compare' => 'BETWEEN',
                    'type' => 'DATE',
                ],
            ],
        ]);

        $results = [];

        foreach ($tasks as $task) {
            $due_date = get_post_meta($task->ID, 'due_date', true);
            $days_until = (int) floor((strtotime($due_date) - strtotime('today')) / DAY_IN_SECONDS);
            $org_id = get_post_meta($task->ID, 'client_organization', true);

            $results[] = [
                'id' => $task->ID,
                'description' => get_post_meta($task->ID, 'task_description', true),
                'org_name' => $org_id ? get_the_title((int) $org_id) : 'Unknown',
                'days_until' => $days_until,
            ];
        }

        return $results;
    }

    /**
     * Get total alert count for menu badge.
     *
     * @return int Total number of alerts.
     */
    public static function getTotalAlertCount(): int {
        $count = 0;

        // Overdue invoices
        $count += self::getOverdueInvoiceCount();

        // Reports needing invoices (only 1st-7th)
        $count += count(self::getReportsNeedingInvoices());

        // New SRs
        $count += count(self::getNewServiceRequests());

        // Overdue tasks
        $count += count(self::getOverdueTasks());

        return $count;
    }
}
