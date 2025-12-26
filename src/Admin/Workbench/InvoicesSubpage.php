<?php
/**
 * Brad's Workbench - Invoices Sub-Page.
 *
 * Ported from admin/class-workbench-invoices.php with proper namespacing.
 *
 * @package BBAB\ServiceCenter\Admin\Workbench
 * @since   2.0.0
 */

declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Workbench;

use BBAB\ServiceCenter\Utils\Cache;

// Load WP_List_Table if not already loaded.
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class InvoicesSubpage
 *
 * Handles the Invoices sub-page with enhanced list table.
 */
class InvoicesSubpage {

    /**
     * The list table instance.
     *
     * @var InvoicesListTable
     */
    private InvoicesListTable $list_table;

    /**
     * Render the invoices page.
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'bbab-service-center'));
        }

        // Initialize the list table.
        $this->list_table = new InvoicesListTable();
        $this->list_table->prepare_items();

        // Get summary stats.
        $stats = $this->getSummaryStats();

        // Get organizations for filter.
        $organizations = $this->getOrganizations();

        // Current filters.
        $current_status = isset($_GET['invoice_status']) ? sanitize_text_field($_GET['invoice_status']) : '';
        $current_org = isset($_GET['organization']) ? absint($_GET['organization']) : 0;

        // Load template.
        include BBAB_SC_PATH . 'templates/admin/workbench-invoices.php';
    }

    /**
     * Get the list table instance (for template use).
     */
    public function getListTable(): InvoicesListTable {
        return $this->list_table;
    }

    /**
     * Get summary statistics for invoices.
     */
    private function getSummaryStats(): array {
        $cache_key = 'invoices_summary_stats_monthly';

        return Cache::remember($cache_key, function() {
            // Current month boundaries.
            $month_start = date('Y-m-01 00:00:00');
            $month_end = date('Y-m-t 23:59:59');

            // Get all invoices.
            $all_invoices = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
            ]);

            $stats = [
                'total_draft' => 0,
                'total_pending' => 0,
                'total_partial' => 0,
                'total_overdue' => 0,
                'outstanding_amount' => 0,
                'paid_this_month' => 0,
                'invoiced_this_month' => 0,
            ];

            $today = strtotime('today');

            // Count statuses and outstanding.
            foreach ($all_invoices as $invoice) {
                $status = get_post_meta($invoice->ID, 'invoice_status', true);
                $amount = (float) get_post_meta($invoice->ID, 'amount', true);
                $paid = (float) get_post_meta($invoice->ID, 'amount_paid', true);
                $due_date = get_post_meta($invoice->ID, 'due_date', true);

                switch ($status) {
                    case 'Draft':
                        $stats['total_draft']++;
                        break;
                    case 'Pending':
                        $stats['total_pending']++;
                        $stats['outstanding_amount'] += ($amount - $paid);
                        break;
                    case 'Partial':
                        $stats['total_partial']++;
                        $stats['outstanding_amount'] += ($amount - $paid);
                        break;
                    case 'Overdue':
                        $stats['total_overdue']++;
                        $stats['outstanding_amount'] += ($amount - $paid);
                        break;
                }

                // Check if pending/partial is actually overdue.
                if (in_array($status, ['Pending', 'Partial'], true) && !empty($due_date)) {
                    if (strtotime($due_date) < $today) {
                        $stats['total_overdue']++;
                        if ($status === 'Pending') {
                            $stats['total_pending']--;
                        } else {
                            $stats['total_partial']--;
                        }
                    }
                }
            }

            // Paid this month - invoices marked as Paid with payment this month.
            $paid_invoices = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'date_query' => [
                    [
                        'column' => 'post_modified',
                        'after' => $month_start,
                        'before' => $month_end,
                        'inclusive' => true,
                    ],
                ],
                'meta_query' => [
                    [
                        'key' => 'invoice_status',
                        'value' => 'Paid',
                        'compare' => '=',
                    ],
                ],
            ]);

            foreach ($paid_invoices as $invoice) {
                $stats['paid_this_month'] += (float) get_post_meta($invoice->ID, 'amount', true);
            }

            // Invoiced this month - invoices created this month.
            $month_invoices = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'date_query' => [
                    [
                        'after' => $month_start,
                        'before' => $month_end,
                        'inclusive' => true,
                    ],
                ],
            ]);

            foreach ($month_invoices as $invoice) {
                $stats['invoiced_this_month'] += (float) get_post_meta($invoice->ID, 'amount', true);
            }

            return $stats;
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get all organizations for filter dropdown.
     */
    private function getOrganizations(): array {
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $result = [];
        foreach ($orgs as $org) {
            $shortcode = get_post_meta($org->ID, 'organization_shortcode', true);
            $result[] = [
                'id' => $org->ID,
                'name' => $org->post_title,
                'shortcode' => $shortcode,
            ];
        }

        return $result;
    }
}

/**
 * Class InvoicesListTable
 *
 * Custom WP_List_Table for displaying invoices.
 */
class InvoicesListTable extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'invoice',
            'plural' => 'invoices',
            'ajax' => false,
        ]);
    }

    /**
     * Get columns.
     */
    public function get_columns(): array {
        return [
            'invoice_number' => __('Invoice #', 'bbab-service-center'),
            'organization' => __('Client', 'bbab-service-center'),
            'amount' => __('Amount', 'bbab-service-center'),
            'amount_paid' => __('Paid', 'bbab-service-center'),
            'status' => __('Status', 'bbab-service-center'),
            'due_date' => __('Due Date', 'bbab-service-center'),
            'related_to' => __('Related To', 'bbab-service-center'),
        ];
    }

    /**
     * Get sortable columns.
     */
    public function get_sortable_columns(): array {
        return [
            'invoice_number' => ['invoice_number', false],
            'organization' => ['organization', false],
            'amount' => ['amount', false],
            'status' => ['invoice_status', false],
            'due_date' => ['due_date', true],
        ];
    }

    /**
     * Prepare items for display.
     */
    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [$columns, $hidden, $sortable];

        // Get filter values.
        $status_filter = isset($_GET['invoice_status']) ? sanitize_text_field($_GET['invoice_status']) : '';
        $org_filter = isset($_GET['organization']) ? absint($_GET['organization']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Build query args.
        $args = [
            'post_type' => 'invoice',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ];

        // Status filter.
        if (!empty($status_filter)) {
            $args['meta_query'][] = [
                'key' => 'invoice_status',
                'value' => $status_filter,
                'compare' => '=',
            ];
        } else {
            // Default: show unpaid invoices only.
            $args['meta_query'][] = [
                'key' => 'invoice_status',
                'value' => ['Paid', 'Cancelled'],
                'compare' => 'NOT IN',
            ];
        }

        // Org filter.
        if (!empty($org_filter)) {
            $args['meta_query'][] = [
                'key' => 'organization',
                'value' => $org_filter,
                'compare' => '=',
            ];
        }

        // Ensure meta_query has relation if multiple conditions.
        if (isset($args['meta_query']) && count($args['meta_query']) > 1) {
            $args['meta_query']['relation'] = 'AND';
        }

        $invoices = get_posts($args);

        // Custom search - filter results by meta fields if search term provided.
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $invoices = array_filter($invoices, function($invoice) use ($search_lower) {
                // Search in invoice_number.
                $inv_num = strtolower(get_post_meta($invoice->ID, 'invoice_number', true));
                if (strpos($inv_num, $search_lower) !== false) {
                    return true;
                }

                // Search in organization name/shortcode.
                $org_id = get_post_meta($invoice->ID, 'organization', true);
                if (!empty($org_id)) {
                    if (is_array($org_id)) {
                        $org_id = reset($org_id);
                    }
                    $org_name = strtolower(get_the_title($org_id));
                    $org_shortcode = strtolower(get_post_meta($org_id, 'organization_shortcode', true));
                    if (strpos($org_name, $search_lower) !== false || strpos($org_shortcode, $search_lower) !== false) {
                        return true;
                    }
                }

                // Search in related project reference number.
                $project_id = get_post_meta($invoice->ID, 'related_project', true);
                if (!empty($project_id)) {
                    if (is_array($project_id)) {
                        $project_id = reset($project_id);
                    }
                    $ref = strtolower(get_post_meta($project_id, 'reference_number', true));
                    if (strpos($ref, $search_lower) !== false) {
                        return true;
                    }
                }

                // Search in related milestone reference number.
                $milestone_id = get_post_meta($invoice->ID, 'related_milestone', true);
                if (!empty($milestone_id)) {
                    if (is_array($milestone_id)) {
                        $milestone_id = reset($milestone_id);
                    }
                    $ref = strtolower(get_post_meta($milestone_id, 'reference_number', true));
                    if (strpos($ref, $search_lower) !== false) {
                        return true;
                    }
                }

                // Search in related service request reference number.
                $sr_id = get_post_meta($invoice->ID, 'related_service_request', true);
                if (!empty($sr_id)) {
                    if (is_array($sr_id)) {
                        $sr_id = reset($sr_id);
                    }
                    $ref = strtolower(get_post_meta($sr_id, 'reference_number', true));
                    if (strpos($ref, $search_lower) !== false) {
                        return true;
                    }
                }

                return false;
            });
            $invoices = array_values($invoices);
        }

        // Sort by status priority (overdue first), then by due date.
        $status_order = [
            'Overdue' => 1,
            'Pending' => 2,
            'Partial' => 3,
            'Draft' => 4,
            'Paid' => 5,
            'Cancelled' => 6,
        ];

        $today = strtotime('today');

        usort($invoices, function($a, $b) use ($status_order, $today) {
            $status_a = get_post_meta($a->ID, 'invoice_status', true);
            $status_b = get_post_meta($b->ID, 'invoice_status', true);
            $due_a = get_post_meta($a->ID, 'due_date', true);
            $due_b = get_post_meta($b->ID, 'due_date', true);

            // Check if actually overdue.
            if (in_array($status_a, ['Pending', 'Partial'], true) && !empty($due_a) && strtotime($due_a) < $today) {
                $status_a = 'Overdue';
            }
            if (in_array($status_b, ['Pending', 'Partial'], true) && !empty($due_b) && strtotime($due_b) < $today) {
                $status_b = 'Overdue';
            }

            $order_a = $status_order[$status_a] ?? 99;
            $order_b = $status_order[$status_b] ?? 99;

            if ($order_a !== $order_b) {
                return $order_a - $order_b;
            }

            // Same status, sort by due date (earliest first).
            $due_time_a = !empty($due_a) ? strtotime($due_a) : PHP_INT_MAX;
            $due_time_b = !empty($due_b) ? strtotime($due_b) : PHP_INT_MAX;

            return $due_time_a - $due_time_b;
        });

        // Pagination.
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = count($invoices);

        $this->items = array_slice($invoices, ($current_page - 1) * $per_page, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }

    /**
     * Render the invoice number column.
     */
    public function column_invoice_number($item): string {
        $inv_number = get_post_meta($item->ID, 'invoice_number', true);
        $edit_link = get_edit_post_link($item->ID, 'raw');

        // Row actions.
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_link),
                __('Edit', 'bbab-service-center')
            ),
        ];

        return sprintf(
            '<a href="%s" class="bbab-ref-link"><strong>%s</strong></a>%s',
            esc_url($edit_link),
            esc_html($inv_number),
            $this->row_actions($actions)
        );
    }

    /**
     * Render the organization column.
     */
    public function column_organization($item): string {
        $org_id = get_post_meta($item->ID, 'organization', true);

        if (empty($org_id)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        if (is_array($org_id)) {
            $org_id = reset($org_id);
        }

        $shortcode = get_post_meta($org_id, 'organization_shortcode', true);
        $name = get_the_title($org_id);

        return sprintf(
            '<span class="bbab-org-badge" title="%s">%s</span>',
            esc_attr($name),
            esc_html($shortcode ?: $name)
        );
    }

    /**
     * Render the amount column.
     */
    public function column_amount($item): string {
        $amount = (float) get_post_meta($item->ID, 'amount', true);

        return '<strong>$' . number_format($amount, 2) . '</strong>';
    }

    /**
     * Render the amount paid column.
     */
    public function column_amount_paid($item): string {
        $paid = (float) get_post_meta($item->ID, 'amount_paid', true);

        if ($paid == 0) {
            return '<span class="bbab-text-muted">$0.00</span>';
        }

        return '$' . number_format($paid, 2);
    }

    /**
     * Render the status column.
     */
    public function column_status($item): string {
        $status = get_post_meta($item->ID, 'invoice_status', true);
        $due_date = get_post_meta($item->ID, 'due_date', true);
        $today = strtotime('today');

        // Check if actually overdue.
        if (in_array($status, ['Pending', 'Partial'], true) && !empty($due_date) && strtotime($due_date) < $today) {
            $status = 'Overdue';
        }

        $css_class = 'status-' . sanitize_title($status);

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr($css_class),
            esc_html($status)
        );
    }

    /**
     * Render the due date column.
     */
    public function column_due_date($item): string {
        $due_date = get_post_meta($item->ID, 'due_date', true);
        $status = get_post_meta($item->ID, 'invoice_status', true);

        if (empty($due_date)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        $due_timestamp = strtotime($due_date);
        $display = date_i18n('M j, Y', $due_timestamp);
        $today = strtotime('today');

        // Add overdue indicator.
        if ($due_timestamp < $today && !in_array($status, ['Paid', 'Cancelled'], true)) {
            $days_overdue = floor(($today - $due_timestamp) / DAY_IN_SECONDS);
            return sprintf(
                '<span class="bbab-overdue">%s <small>(%dd overdue)</small></span>',
                esc_html($display),
                $days_overdue
            );
        }

        return esc_html($display);
    }

    /**
     * Render the related to column.
     */
    public function column_related_to($item): string {
        $related = [];

        // Check for project.
        $project_id = get_post_meta($item->ID, 'related_project', true);
        if (!empty($project_id)) {
            if (is_array($project_id)) {
                $project_id = reset($project_id);
            }
            $ref = get_post_meta($project_id, 'reference_number', true);
            $related[] = sprintf(
                '<a href="%s" class="bbab-ref-link" title="%s">%s</a>',
                esc_url(get_edit_post_link($project_id, 'raw')),
                esc_attr__('Project', 'bbab-service-center'),
                esc_html($ref)
            );
        }

        // Check for milestone.
        $milestone_id = get_post_meta($item->ID, 'related_milestone', true);
        if (!empty($milestone_id)) {
            if (is_array($milestone_id)) {
                $milestone_id = reset($milestone_id);
            }
            $ref = get_post_meta($milestone_id, 'reference_number', true);
            $related[] = sprintf(
                '<a href="%s" class="bbab-ref-link" title="%s">%s</a>',
                esc_url(get_edit_post_link($milestone_id, 'raw')),
                esc_attr__('Milestone', 'bbab-service-center'),
                esc_html($ref)
            );
        }

        // Check for service request.
        $sr_id = get_post_meta($item->ID, 'related_service_request', true);
        if (!empty($sr_id)) {
            if (is_array($sr_id)) {
                $sr_id = reset($sr_id);
            }
            $ref = get_post_meta($sr_id, 'reference_number', true);
            $related[] = sprintf(
                '<a href="%s" class="bbab-ref-link" title="%s">%s</a>',
                esc_url(get_edit_post_link($sr_id, 'raw')),
                esc_attr__('Service Request', 'bbab-service-center'),
                esc_html($ref)
            );
        }

        if (empty($related)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        return implode(', ', $related);
    }

    /**
     * Default column renderer.
     */
    public function column_default($item, $column_name): string {
        return '';
    }

    /**
     * Message when no items found.
     */
    public function no_items(): void {
        esc_html_e('No invoices found.', 'bbab-service-center');
    }
}
