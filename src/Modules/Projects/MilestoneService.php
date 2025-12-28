<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Milestone business logic service.
 *
 * Handles:
 * - Milestone queries
 * - Payment status calculation (Pending/Invoiced/Paid)
 * - Work status handling
 * - Invoice relationships
 *
 * IMPORTANT: Payment status is CALCULATED, never stored.
 *
 * Migrated from: WPCode Snippets #1492 (helper functions)
 */
class MilestoneService {

    /**
     * Work status values (stored in milestone_status).
     */
    public const WORK_PLANNED = 'Planned';
    public const WORK_IN_PROGRESS = 'In Progress';
    public const WORK_ON_HOLD = 'On Hold';
    public const WORK_WAITING = 'Waiting for Client';
    public const WORK_COMPLETED = 'Completed';

    /**
     * Payment status values (CALCULATED, never stored).
     */
    public const PAYMENT_PENDING = 'Pending';
    public const PAYMENT_INVOICED = 'Invoiced';
    public const PAYMENT_PAID = 'Paid';

    /**
     * Billing status values (stored in billing_status).
     */
    public const BILLING_PENDING = 'Pending';
    public const BILLING_INVOICED = 'Invoiced';
    public const BILLING_DEPOSIT = 'Invoiced as Deposit';
    public const BILLING_PAID = 'Paid';

    /**
     * Get milestones for a project.
     *
     * @param int $project_id Project post ID.
     * @return array Array of milestone post objects, ordered by milestone_order.
     */
    public static function getForProject(int $project_id): array {
        return get_posts([
            'post_type' => 'milestone',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_key' => 'milestone_order',
            'orderby' => ['meta_value_num' => 'ASC', 'ID' => 'ASC'],
            'meta_query' => [
                [
                    'key' => 'related_project',
                    'value' => $project_id,
                    'compare' => '=',
                ],
            ],
        ]);
    }

    /**
     * Calculate milestone payment status.
     *
     * Status is CALCULATED from invoice data, not stored:
     * - Pending: No invoices linked
     * - Invoiced: Has invoice(s), not fully paid
     * - Paid: Sum of paid amounts >= milestone amount
     *
     * Migrated from: bbab_calculate_milestone_status()
     *
     * @param int $milestone_id Milestone post ID.
     * @return string Payment status (Pending, Invoiced, or Paid).
     */
    public static function getPaymentStatus(int $milestone_id): string {
        $invoices = self::getInvoices($milestone_id);

        if (empty($invoices)) {
            return self::PAYMENT_PENDING;
        }

        $milestone_amount = (float) get_post_meta($milestone_id, 'milestone_amount', true);
        $total_paid = 0.0;

        foreach ($invoices as $invoice) {
            $paid = get_post_meta($invoice->ID, 'amount_paid', true);
            $total_paid += (float) $paid;
        }

        if ($milestone_amount > 0 && $total_paid >= $milestone_amount) {
            return self::PAYMENT_PAID;
        }

        return self::PAYMENT_INVOICED;
    }

    /**
     * Get work status for a milestone.
     *
     * @param int $milestone_id Milestone post ID.
     * @return string Work status.
     */
    public static function getWorkStatus(int $milestone_id): string {
        return get_post_meta($milestone_id, 'milestone_status', true) ?: self::WORK_PLANNED;
    }

    /**
     * Get milestone amount.
     *
     * @param int $milestone_id Milestone post ID.
     * @return float Amount.
     */
    public static function getAmount(int $milestone_id): float {
        return (float) get_post_meta($milestone_id, 'milestone_amount', true);
    }

    /**
     * Get invoices linked to a milestone.
     *
     * @param int $milestone_id Milestone post ID.
     * @return array Array of invoice post objects.
     */
    public static function getInvoices(int $milestone_id): array {
        return get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_milestone',
                    'value' => $milestone_id,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Get count of invoices linked to a milestone.
     *
     * Migrated from: bbab_get_milestone_invoice_count()
     *
     * @param int $milestone_id Milestone post ID.
     * @return int Invoice count.
     */
    public static function getInvoiceCount(int $milestone_id): int {
        return count(self::getInvoices($milestone_id));
    }

    /**
     * Get total hours logged for a milestone.
     *
     * @param int $milestone_id Milestone post ID.
     * @return float Total hours.
     */
    public static function getTotalHours(int $milestone_id): float {
        $entries = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_milestone',
                    'value' => $milestone_id,
                    'compare' => '=',
                ],
            ],
        ]);

        $total = 0.0;
        foreach ($entries as $entry) {
            $total += (float) get_post_meta($entry->ID, 'hours', true);
        }

        return $total;
    }

    /**
     * Get total paid amount for a milestone.
     *
     * @param int $milestone_id Milestone post ID.
     * @return float Total paid amount.
     */
    public static function getPaidTotal(int $milestone_id): float {
        $invoices = self::getInvoices($milestone_id);
        $total = 0.0;

        foreach ($invoices as $invoice) {
            $paid = get_post_meta($invoice->ID, 'amount_paid', true);
            $total += (float) $paid;
        }

        return $total;
    }

    /**
     * Check if milestone is fully paid.
     *
     * @param int $milestone_id Milestone post ID.
     * @return bool True if paid.
     */
    public static function isPaid(int $milestone_id): bool {
        return self::getPaymentStatus($milestone_id) === self::PAYMENT_PAID;
    }

    /**
     * Check if milestone is a deposit.
     *
     * @param int $milestone_id Milestone post ID.
     * @return bool True if deposit milestone.
     */
    public static function isDeposit(int $milestone_id): bool {
        return get_post_meta($milestone_id, 'is_deposit', true) === '1';
    }

    /**
     * Get work status badge HTML.
     *
     * @param string $status Work status value.
     * @return string HTML badge.
     */
    public static function getWorkStatusBadgeHtml(string $status): string {
        $colors = [
            self::WORK_PLANNED => ['bg' => '#e8eaf6', 'text' => '#5c6bc0'],
            self::WORK_IN_PROGRESS => ['bg' => '#e3f2fd', 'text' => '#1976d2'],
            self::WORK_ON_HOLD => ['bg' => '#fef9e7', 'text' => '#b7950b'],
            self::WORK_WAITING => ['bg' => '#fff3e0', 'text' => '#f57c00'],
            self::WORK_COMPLETED => ['bg' => '#d5f5e3', 'text' => '#1e8449'],
        ];

        $color = $colors[$status] ?? ['bg' => '#f5f5f5', 'text' => '#616161'];

        return sprintf(
            '<span style="background:%s;color:%s;padding:4px 8px;border-radius:10px;font-size:12px;font-weight:500;">%s</span>',
            esc_attr($color['bg']),
            esc_attr($color['text']),
            esc_html($status ?: 'Unknown')
        );
    }

    /**
     * Get payment status badge HTML.
     *
     * @param string $status Payment status value.
     * @param int|null $milestone_id Optional milestone ID for invoice linking.
     * @return string HTML badge (may include link to invoice).
     */
    public static function getPaymentStatusBadgeHtml(string $status, ?int $milestone_id = null): string {
        $icons = [
            self::PAYMENT_PENDING => '⏳',
            self::PAYMENT_INVOICED => '📄',
            self::PAYMENT_PAID => '✓',
        ];

        $colors = [
            self::PAYMENT_PENDING => 'color: #7f8c8d;',
            self::PAYMENT_INVOICED => 'color: #1976d2; font-weight: 500;',
            self::PAYMENT_PAID => 'color: #1e8449; font-weight: 500;',
        ];

        $icon = $icons[$status] ?? '';
        $style = $colors[$status] ?? '';
        $text = $icon . ' ' . esc_html($status);

        // Try to link to invoice if Invoiced or Paid
        if ($milestone_id && in_array($status, [self::PAYMENT_INVOICED, self::PAYMENT_PAID], true)) {
            $invoices = self::getInvoices($milestone_id);
            if (!empty($invoices)) {
                $invoice = $invoices[0]; // Most recent
                $invoice_url = get_edit_post_link($invoice->ID);
                $invoice_number = get_post_meta($invoice->ID, 'invoice_number', true) ?: 'Invoice';

                return sprintf(
                    '<a href="%s" title="%s" style="%stext-decoration: none;">%s</a>',
                    esc_url($invoice_url),
                    esc_attr($invoice_number),
                    $style,
                    $text
                );
            }
        }

        return sprintf('<span style="%s">%s</span>', $style, $text);
    }

    /**
     * Get the parent project for a milestone.
     *
     * @param int $milestone_id Milestone post ID.
     * @return \WP_Post|null Project post or null.
     */
    public static function getProject(int $milestone_id): ?\WP_Post {
        $project_id = get_post_meta($milestone_id, 'related_project', true);

        // Handle Pods array format
        if (is_array($project_id)) {
            $project_id = reset($project_id);
        }

        if (empty($project_id)) {
            return null;
        }

        return get_post((int) $project_id);
    }

    /**
     * Get milestone order display (e.g., "2 / 5").
     *
     * @param int $milestone_id Milestone post ID.
     * @return string Order display or empty string.
     */
    public static function getOrderDisplay(int $milestone_id): string {
        $order = get_post_meta($milestone_id, 'milestone_order', true);
        $project = self::getProject($milestone_id);

        if (!$order || !$project) {
            return $order ?: '';
        }

        $total = count(self::getForProject($project->ID));

        return $order . ' / ' . $total;
    }
}
