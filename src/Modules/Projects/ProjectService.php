<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Project business logic service.
 *
 * Handles:
 * - Project queries with org filtering
 * - Budget and hours calculations
 * - Invoice totals (invoiced/paid amounts)
 * - Status badge rendering
 *
 * Migrated from: WPCode Snippets #1492 (helper functions)
 */
class ProjectService {

    /**
     * Project status values.
     */
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_WAITING = 'Waiting on Client';
    public const STATUS_HOLD = 'On Hold';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';

    /**
     * Billing status values.
     */
    public const BILLING_NOT_STARTED = 'Not Started';
    public const BILLING_IN_PROGRESS = 'In Progress';
    public const BILLING_CLOSED_OUT = 'Closed Out';

    /**
     * Get all projects for an organization.
     *
     * @param int   $org_id Organization post ID.
     * @param array $args   Additional query args.
     * @return array Array of project post objects.
     */
    public static function getForOrg(int $org_id, array $args = []): array {
        $defaults = [
            'post_type' => 'project',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'organization',
                    'value' => $org_id,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        return get_posts(array_merge($defaults, $args));
    }

    /**
     * Get active projects for an organization.
     * Excludes Completed and Cancelled.
     *
     * @param int $org_id Organization post ID.
     * @return array Array of project post objects.
     */
    public static function getActiveProjects(int $org_id): array {
        return get_posts([
            'post_type' => 'project',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'organization',
                    'value' => $org_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'project_status',
                    'value' => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
                    'compare' => 'NOT IN',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Get milestones for a project.
     *
     * @param int $project_id Project post ID.
     * @return array Array of milestone post objects, ordered by milestone_order.
     */
    public static function getMilestones(int $project_id): array {
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
     * Get project total budget.
     *
     * If project has milestones: sum of milestone_amount values
     * If no milestones: use total_budget field
     *
     * @param int $project_id Project post ID.
     * @return float Total budget amount.
     */
    public static function getProjectTotal(int $project_id): float {
        $milestones = self::getMilestones($project_id);

        if (!empty($milestones)) {
            $total = 0.0;
            foreach ($milestones as $milestone) {
                $total += (float) get_post_meta($milestone->ID, 'milestone_amount', true);
            }
            return $total;
        }

        return (float) get_post_meta($project_id, 'total_budget', true);
    }

    /**
     * Get total billable hours for a project (including all milestones).
     *
     * OPTIMIZED: Uses two separate queries instead of nested subquery.
     *
     * @param int $project_id Project post ID.
     * @return float Total hours.
     */
    public static function getTotalHours(int $project_id): float {
        global $wpdb;

        // Step 1: Get all milestone IDs for this project
        $milestone_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'related_project' AND meta_value = %d",
            $project_id
        ));

        // Step 2: Build query for time entries linked to project OR any milestone
        $where_clauses = [];
        $params = [];

        // Direct project link
        $where_clauses[] = "(pm_project.meta_key = 'related_project' AND pm_project.meta_value = %d)";
        $params[] = $project_id;

        // Milestone links (if any exist)
        if (!empty($milestone_ids)) {
            $placeholders = implode(',', array_fill(0, count($milestone_ids), '%d'));
            $where_clauses[] = "(pm_milestone.meta_key = 'related_milestone' AND pm_milestone.meta_value IN ({$placeholders}))";
            $params = array_merge($params, array_map('intval', $milestone_ids));
        }

        $where = implode(' OR ', $where_clauses);

        $sql = "SELECT SUM(CAST(pm_hours.meta_value AS DECIMAL(10,2)))
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_hours ON p.ID = pm_hours.post_id AND pm_hours.meta_key = 'hours'
                LEFT JOIN {$wpdb->postmeta} pm_project ON p.ID = pm_project.post_id AND pm_project.meta_key = 'related_project'
                LEFT JOIN {$wpdb->postmeta} pm_milestone ON p.ID = pm_milestone.post_id AND pm_milestone.meta_key = 'related_milestone'
                WHERE p.post_type = 'time_entry'
                AND p.post_status = 'publish'
                AND ({$where})";

        $total = $wpdb->get_var($wpdb->prepare($sql, ...$params));

        return (float) $total;
    }

    /**
     * Get sum of all invoice amounts linked to this project.
     *
     * Migrated from: bbab_get_project_invoiced_total()
     *
     * @param int $project_id Project post ID.
     * @return float Total invoiced amount.
     */
    public static function getInvoicedTotal(int $project_id): float {
        $invoices = get_posts([
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

        $total = 0.0;
        foreach ($invoices as $invoice) {
            $amount = get_post_meta($invoice->ID, 'amount', true);
            $total += (float) $amount;
        }

        return $total;
    }

    /**
     * Get sum of all paid amounts from invoices linked to this project.
     *
     * Migrated from: bbab_get_project_paid_total()
     *
     * @param int $project_id Project post ID.
     * @return float Total paid amount.
     */
    public static function getPaidTotal(int $project_id): float {
        $invoices = get_posts([
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

        $total = 0.0;
        foreach ($invoices as $invoice) {
            $paid = get_post_meta($invoice->ID, 'amount_paid', true);
            $total += (float) $paid;
        }

        return $total;
    }

    /**
     * Get the invoices linked to a project.
     *
     * @param int $project_id Project post ID.
     * @return array Array of invoice post objects.
     */
    public static function getInvoices(int $project_id): array {
        return get_posts([
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
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Get project status badge HTML.
     *
     * @param string $status Status value.
     * @return string HTML badge.
     */
    public static function getStatusBadgeHtml(string $status): string {
        $colors = [
            self::STATUS_ACTIVE => ['bg' => '#d5f5e3', 'text' => '#1e8449'],
            self::STATUS_WAITING => ['bg' => '#fef9e7', 'text' => '#b7950b'],
            self::STATUS_HOLD => ['bg' => '#e8eaf6', 'text' => '#5c6bc0'],
            self::STATUS_COMPLETED => ['bg' => '#f5f5f5', 'text' => '#616161'],
            self::STATUS_CANCELLED => ['bg' => '#ffebee', 'text' => '#c62828'],
        ];

        $color = $colors[$status] ?? ['bg' => '#f5f5f5', 'text' => '#616161'];

        return sprintf(
            '<span style="background:%s;color:%s;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">%s</span>',
            esc_attr($color['bg']),
            esc_attr($color['text']),
            esc_html($status ?: 'Unknown')
        );
    }

    /**
     * Get billing status badge HTML.
     *
     * @param string $status Billing status value.
     * @return string HTML badge.
     */
    public static function getBillingStatusBadgeHtml(string $status): string {
        $colors = [
            self::BILLING_NOT_STARTED => ['bg' => '#f5f5f5', 'text' => '#616161'],
            self::BILLING_IN_PROGRESS => ['bg' => '#e3f2fd', 'text' => '#1976d2'],
            self::BILLING_CLOSED_OUT => ['bg' => '#d5f5e3', 'text' => '#1e8449'],
        ];

        $color = $colors[$status] ?? ['bg' => '#f5f5f5', 'text' => '#616161'];

        return sprintf(
            '<span style="background:%s;color:%s;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">%s</span>',
            esc_attr($color['bg']),
            esc_attr($color['text']),
            esc_html($status ?: 'Unknown')
        );
    }
}
