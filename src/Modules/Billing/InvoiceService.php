<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Invoice business logic service.
 *
 * Handles:
 * - Invoice queries (by org, milestone, project, monthly report)
 * - Status management and badge generation
 * - Payment tracking
 * - Invoice type handling
 */
class InvoiceService {

    /**
     * Invoice status values.
     */
    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PAID = 'Paid';
    public const STATUS_PARTIAL = 'Partial';
    public const STATUS_OVERDUE = 'Overdue';
    public const STATUS_VOID = 'Void';
    public const STATUS_CREDITED = 'Credited';

    /**
     * Invoice type values.
     */
    public const TYPE_STANDARD = 'Standard';
    public const TYPE_MILESTONE = 'Milestone';
    public const TYPE_CLOSEOUT = 'Closeout';
    public const TYPE_DEPOSIT = 'Deposit';
    public const TYPE_CREDIT = 'Credit';

    /**
     * Get invoices for an organization.
     *
     * @param int   $org_id Organization post ID.
     * @param array $args   Optional query args (status, limit, etc.).
     * @return array Array of invoice post objects.
     */
    public static function getForOrg(int $org_id, array $args = []): array {
        $defaults = [
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => 'organization',
                    'value' => $org_id,
                    'compare' => '=',
                ],
            ],
        ];

        // Add status filter if provided
        if (!empty($args['status'])) {
            $defaults['meta_query'][] = [
                'key' => 'invoice_status',
                'value' => $args['status'],
                'compare' => is_array($args['status']) ? 'IN' : '=',
            ];
        }

        // Limit
        if (isset($args['limit'])) {
            $defaults['posts_per_page'] = (int) $args['limit'];
        }

        return get_posts($defaults);
    }

    /**
     * Get invoices linked to a milestone.
     *
     * @param int $milestone_id Milestone post ID.
     * @return array Array of invoice post objects.
     */
    public static function getForMilestone(int $milestone_id): array {
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
     * Get invoices linked to a project (directly or via milestones).
     *
     * @param int $project_id Project post ID.
     * @return array Array of invoice post objects.
     */
    public static function getForProject(int $project_id): array {
        // Get invoices directly linked to project
        $direct = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_project',
                    'value' => $project_id,
                    'compare' => '=',
                ],
            ],
        ]);

        // Get project's milestones
        $milestones = get_posts([
            'post_type' => 'milestone',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_project',
                    'value' => $project_id,
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ]);

        // Get invoices linked to milestones
        $milestone_invoices = [];
        if (!empty($milestones)) {
            $milestone_invoices = get_posts([
                'post_type' => 'invoice',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [
                    [
                        'key' => 'related_milestone',
                        'value' => $milestones,
                        'compare' => 'IN',
                    ],
                ],
            ]);
        }

        // Merge and deduplicate
        $all = array_merge($direct, $milestone_invoices);
        $unique = [];
        $seen = [];

        foreach ($all as $invoice) {
            if (!in_array($invoice->ID, $seen, true)) {
                $unique[] = $invoice;
                $seen[] = $invoice->ID;
            }
        }

        // Sort by date DESC
        usort($unique, function ($a, $b) {
            return strtotime($b->post_date) - strtotime($a->post_date);
        });

        return $unique;
    }

    /**
     * Get invoice linked to a monthly report.
     *
     * @param int $report_id Monthly Report post ID.
     * @return \WP_Post|null Invoice post or null.
     */
    public static function getForMonthlyReport(int $report_id): ?\WP_Post {
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => 'related_monthly_report',
                    'value' => $report_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($invoices) ? $invoices[0] : null;
    }

    /**
     * Get closeout invoice for a project.
     *
     * @param int $project_id Project post ID.
     * @return \WP_Post|null Closeout invoice or null.
     */
    public static function getCloseoutForProject(int $project_id): ?\WP_Post {
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'related_project',
                    'value' => $project_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'is_closeout_invoice',
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($invoices) ? $invoices[0] : null;
    }

    /**
     * Get invoice status.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string Status value.
     */
    public static function getStatus(int $invoice_id): string {
        return get_post_meta($invoice_id, 'invoice_status', true) ?: self::STATUS_DRAFT;
    }

    /**
     * Get invoice amount.
     *
     * @param int $invoice_id Invoice post ID.
     * @return float Amount.
     */
    public static function getAmount(int $invoice_id): float {
        return (float) get_post_meta($invoice_id, 'amount', true);
    }

    /**
     * Get amount paid on an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return float Paid amount.
     */
    public static function getPaidAmount(int $invoice_id): float {
        return (float) get_post_meta($invoice_id, 'amount_paid', true);
    }

    /**
     * Get balance due on an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return float Balance (amount - amount_paid).
     */
    public static function getBalance(int $invoice_id): float {
        $amount = self::getAmount($invoice_id);
        $paid = self::getPaidAmount($invoice_id);
        return max(0, $amount - $paid);
    }

    /**
     * Check if invoice is fully paid.
     *
     * @param int $invoice_id Invoice post ID.
     * @return bool True if paid.
     */
    public static function isPaid(int $invoice_id): bool {
        $status = self::getStatus($invoice_id);
        return $status === self::STATUS_PAID;
    }

    /**
     * Check if invoice is overdue.
     *
     * @param int $invoice_id Invoice post ID.
     * @return bool True if overdue.
     */
    public static function isOverdue(int $invoice_id): bool {
        $status = self::getStatus($invoice_id);
        if (in_array($status, [self::STATUS_PAID, self::STATUS_VOID, self::STATUS_CREDITED], true)) {
            return false;
        }

        $due_date = get_post_meta($invoice_id, 'due_date', true);
        if (empty($due_date)) {
            return false;
        }

        return strtotime($due_date) < strtotime(current_time('Y-m-d'));
    }

    /**
     * Get invoice type.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string Invoice type.
     */
    public static function getType(int $invoice_id): string {
        return get_post_meta($invoice_id, 'invoice_type', true) ?: self::TYPE_STANDARD;
    }

    /**
     * Get status badge HTML.
     *
     * @param string $status Status value.
     * @return string HTML badge.
     */
    public static function getStatusBadgeHtml(string $status): string {
        $colors = [
            self::STATUS_DRAFT => ['bg' => '#f5f5f5', 'text' => '#616161'],
            self::STATUS_PENDING => ['bg' => '#fef9e7', 'text' => '#b7950b'],
            self::STATUS_PAID => ['bg' => '#d5f5e3', 'text' => '#1e8449'],
            self::STATUS_PARTIAL => ['bg' => '#e3f2fd', 'text' => '#1976d2'],
            self::STATUS_OVERDUE => ['bg' => '#ffebee', 'text' => '#c62828'],
            self::STATUS_VOID => ['bg' => '#eceff1', 'text' => '#78909c'],
            self::STATUS_CREDITED => ['bg' => '#f3e5f5', 'text' => '#7b1fa2'],
        ];

        $color = $colors[$status] ?? ['bg' => '#f5f5f5', 'text' => '#616161'];

        return sprintf(
            '<span style="background:%s;color:%s;padding:4px 8px;border-radius:10px;font-size:12px;font-weight:500;display:inline-block;">%s</span>',
            esc_attr($color['bg']),
            esc_attr($color['text']),
            esc_html($status ?: 'Unknown')
        );
    }

    /**
     * Get invoice number.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string Invoice number.
     */
    public static function getNumber(int $invoice_id): string {
        return get_post_meta($invoice_id, 'invoice_number', true) ?: '';
    }

    /**
     * Get invoice date.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string Invoice date (Y-m-d format).
     */
    public static function getDate(int $invoice_id): string {
        return get_post_meta($invoice_id, 'invoice_date', true) ?: '';
    }

    /**
     * Get due date.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string Due date (Y-m-d format).
     */
    public static function getDueDate(int $invoice_id): string {
        return get_post_meta($invoice_id, 'due_date', true) ?: '';
    }

    /**
     * Get organization for an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return \WP_Post|null Organization post or null.
     */
    public static function getOrganization(int $invoice_id): ?\WP_Post {
        $org_id = get_post_meta($invoice_id, 'organization', true);

        // Handle Pods array format
        if (is_array($org_id)) {
            $org_id = reset($org_id);
        }

        if (empty($org_id)) {
            return null;
        }

        return get_post((int) $org_id);
    }

    /**
     * Get invoice PDF attachment.
     *
     * @param int $invoice_id Invoice post ID.
     * @return array|null PDF attachment data or null.
     */
    public static function getPdf(int $invoice_id): ?array {
        $pdf = get_post_meta($invoice_id, 'invoice_pdf', true);

        if (empty($pdf)) {
            return null;
        }

        // Handle Pods file field format
        if (is_array($pdf) && !empty($pdf['guid'])) {
            return [
                'url' => $pdf['guid'],
                'id' => $pdf['ID'] ?? null,
            ];
        }

        // Handle attachment ID format
        if (is_numeric($pdf)) {
            $url = wp_get_attachment_url((int) $pdf);
            if ($url) {
                return [
                    'url' => $url,
                    'id' => (int) $pdf,
                ];
            }
        }

        return null;
    }

    /**
     * Get total invoiced amount for an organization.
     *
     * @param int    $org_id   Organization post ID.
     * @param string $status   Optional status filter.
     * @return float Total amount.
     */
    public static function getTotalForOrg(int $org_id, string $status = ''): float {
        $args = [];
        if (!empty($status)) {
            $args['status'] = $status;
        }

        $invoices = self::getForOrg($org_id, $args);
        $total = 0.0;

        foreach ($invoices as $invoice) {
            $total += self::getAmount($invoice->ID);
        }

        return $total;
    }

    /**
     * Get total paid amount for an organization.
     *
     * @param int $org_id Organization post ID.
     * @return float Total paid.
     */
    public static function getTotalPaidForOrg(int $org_id): float {
        $invoices = self::getForOrg($org_id);
        $total = 0.0;

        foreach ($invoices as $invoice) {
            $total += self::getPaidAmount($invoice->ID);
        }

        return $total;
    }

    /**
     * Get total invoiced for a project.
     *
     * @param int $project_id Project post ID.
     * @return float Total invoiced amount.
     */
    public static function getTotalForProject(int $project_id): float {
        $invoices = self::getForProject($project_id);
        $total = 0.0;

        foreach ($invoices as $invoice) {
            // Exclude void/credited invoices
            $status = self::getStatus($invoice->ID);
            if (!in_array($status, [self::STATUS_VOID, self::STATUS_CREDITED], true)) {
                $total += self::getAmount($invoice->ID);
            }
        }

        return $total;
    }

    /**
     * Get total paid for a project.
     *
     * @param int $project_id Project post ID.
     * @return float Total paid amount.
     */
    public static function getTotalPaidForProject(int $project_id): float {
        $invoices = self::getForProject($project_id);
        $total = 0.0;

        foreach ($invoices as $invoice) {
            $total += self::getPaidAmount($invoice->ID);
        }

        return $total;
    }

    /**
     * Update invoice status.
     *
     * @param int    $invoice_id Invoice post ID.
     * @param string $status     New status.
     */
    public static function updateStatus(int $invoice_id, string $status): void {
        update_post_meta($invoice_id, 'invoice_status', $status);

        Logger::debug('InvoiceService', 'Updated invoice status', [
            'invoice_id' => $invoice_id,
            'status' => $status,
        ]);
    }

    /**
     * Record a payment on an invoice.
     *
     * @param int   $invoice_id Invoice post ID.
     * @param float $amount     Payment amount.
     * @param array $meta       Additional payment metadata.
     */
    public static function recordPayment(int $invoice_id, float $amount, array $meta = []): void {
        $current_paid = self::getPaidAmount($invoice_id);
        $new_paid = $current_paid + $amount;
        $invoice_amount = self::getAmount($invoice_id);

        update_post_meta($invoice_id, 'amount_paid', $new_paid);

        // Update status based on payment
        if ($new_paid >= $invoice_amount) {
            self::updateStatus($invoice_id, self::STATUS_PAID);
            update_post_meta($invoice_id, 'payment_date', current_time('Y-m-d'));
        } elseif ($new_paid > 0) {
            self::updateStatus($invoice_id, self::STATUS_PARTIAL);
        }

        // Store payment metadata if provided
        if (!empty($meta['payment_method'])) {
            update_post_meta($invoice_id, 'payment_method', $meta['payment_method']);
        }
        if (!empty($meta['transaction_id'])) {
            update_post_meta($invoice_id, 'transaction_id', $meta['transaction_id']);
        }
        if (!empty($meta['cc_fee'])) {
            update_post_meta($invoice_id, 'cc_fee', $meta['cc_fee']);
        }

        Logger::debug('InvoiceService', 'Recorded payment', [
            'invoice_id' => $invoice_id,
            'amount' => $amount,
            'new_total_paid' => $new_paid,
        ]);
    }
}
