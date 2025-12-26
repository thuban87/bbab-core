<?php
/**
 * Brad's Workbench - Projects Sub-Page.
 *
 * Ported from admin/class-workbench-projects.php with proper namespacing.
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
 * Class ProjectsSubpage
 *
 * Handles the Projects sub-page with enhanced list table.
 */
class ProjectsSubpage {

    /**
     * The list table instance.
     *
     * @var ProjectsListTable
     */
    private ProjectsListTable $list_table;

    /**
     * Render the projects page.
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'bbab-service-center'));
        }

        // Initialize the list table.
        $this->list_table = new ProjectsListTable();
        $this->list_table->prepare_items();

        // Get summary stats.
        $stats = $this->getSummaryStats();

        // Get organizations for filter.
        $organizations = $this->getOrganizations();

        // Current filters.
        $current_status = isset($_GET['project_status']) ? sanitize_text_field($_GET['project_status']) : '';
        $current_org = isset($_GET['organization']) ? absint($_GET['organization']) : 0;

        // Load template.
        include BBAB_SC_PATH . 'templates/admin/workbench-projects.php';
    }

    /**
     * Get the list table instance (for template use).
     */
    public function getListTable(): ProjectsListTable {
        return $this->list_table;
    }

    /**
     * Get summary statistics for projects.
     */
    private function getSummaryStats(): array {
        $cache_key = 'projects_summary_stats_monthly';

        return Cache::remember($cache_key, function() {
            // Current month boundaries.
            $month_start = date('Y-m-01 00:00:00');
            $month_end = date('Y-m-t 23:59:59');

            // Get all non-completed/cancelled projects for status counts.
            $active_projects = get_posts([
                'post_type' => 'project',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'project_status',
                        'value' => ['Active', 'Waiting on Client', 'On Hold'],
                        'compare' => 'IN',
                    ],
                ],
            ]);

            $stats = [
                'total_active' => 0,
                'total_waiting' => 0,
                'total_on_hold' => 0,
                'hours_this_month' => 0,
                'budget_this_month' => 0,
                'completed_this_month' => 0,
                'invoiced_this_month' => 0,
            ];

            // Count active statuses.
            foreach ($active_projects as $project) {
                $status = get_post_meta($project->ID, 'project_status', true);

                switch ($status) {
                    case 'Active':
                        $stats['total_active']++;
                        break;
                    case 'Waiting on Client':
                        $stats['total_waiting']++;
                        break;
                    case 'On Hold':
                        $stats['total_on_hold']++;
                        break;
                }
            }

            // Hours this month - get all TEs from this month linked to projects or milestones.
            $month_tes = get_posts([
                'post_type' => 'time_entry',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'entry_date',
                        'value' => [$month_start, $month_end],
                        'compare' => 'BETWEEN',
                        'type' => 'DATETIME',
                    ],
                    [
                        'relation' => 'OR',
                        [
                            'key' => 'related_project',
                            'value' => '',
                            'compare' => '!=',
                        ],
                        [
                            'key' => 'related_milestone',
                            'value' => '',
                            'compare' => '!=',
                        ],
                    ],
                ],
            ]);

            foreach ($month_tes as $te) {
                $stats['hours_this_month'] += (float) get_post_meta($te->ID, 'hours', true);
            }

            // Budget this month - projects created this month.
            $month_projects = get_posts([
                'post_type' => 'project',
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

            foreach ($month_projects as $project) {
                $stats['budget_this_month'] += (float) get_post_meta($project->ID, 'total_budget', true);
            }

            // Completed this month - projects with Completed status modified this month.
            $completed_projects = get_posts([
                'post_type' => 'project',
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
                        'key' => 'project_status',
                        'value' => 'Completed',
                        'compare' => '=',
                    ],
                ],
            ]);

            $stats['completed_this_month'] = count($completed_projects);

            // Invoiced this month - sum of invoices created this month linked to projects/milestones.
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
                $linked_project = get_post_meta($invoice->ID, 'related_project', true);
                $linked_milestone = get_post_meta($invoice->ID, 'related_milestone', true);

                if (!empty($linked_project) || !empty($linked_milestone)) {
                    $stats['invoiced_this_month'] += (float) get_post_meta($invoice->ID, 'amount', true);
                }
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
 * Class ProjectsListTable
 *
 * Custom WP_List_Table for displaying projects.
 */
class ProjectsListTable extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'project',
            'plural' => 'projects',
            'ajax' => false,
        ]);
    }

    /**
     * Get columns.
     */
    public function get_columns(): array {
        return [
            'ref_number' => __('Ref #', 'bbab-service-center'),
            'project_name' => __('Project', 'bbab-service-center'),
            'organization' => __('Client', 'bbab-service-center'),
            'status' => __('Status', 'bbab-service-center'),
            'milestones' => __('Milestones', 'bbab-service-center'),
            'hours' => __('Hours', 'bbab-service-center'),
            'invoices' => __('Invoices', 'bbab-service-center'),
            'budget' => __('Budget', 'bbab-service-center'),
        ];
    }

    /**
     * Get sortable columns.
     */
    public function get_sortable_columns(): array {
        return [
            'ref_number' => ['reference_number', false],
            'project_name' => ['project_name', false],
            'organization' => ['organization', false],
            'status' => ['project_status', false],
            'budget' => ['budget', false],
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
        $status_filter = isset($_GET['project_status']) ? sanitize_text_field($_GET['project_status']) : '';
        $org_filter = isset($_GET['organization']) ? absint($_GET['organization']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Build query args.
        $args = [
            'post_type' => 'project',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ];

        // Status filter.
        if (!empty($status_filter)) {
            $args['meta_query'][] = [
                'key' => 'project_status',
                'value' => $status_filter,
                'compare' => '=',
            ];
        } else {
            // Default: show active statuses only.
            $args['meta_query'][] = [
                'key' => 'project_status',
                'value' => ['Active', 'Waiting on Client', 'On Hold'],
                'compare' => 'IN',
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

        $projects = get_posts($args);

        // Custom search - filter results by meta fields if search term provided.
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $projects = array_filter($projects, function($project) use ($search_lower) {
                // Search in reference_number.
                $ref = strtolower(get_post_meta($project->ID, 'reference_number', true));
                if (strpos($ref, $search_lower) !== false) {
                    return true;
                }

                // Search in project_name.
                $name = strtolower(get_post_meta($project->ID, 'project_name', true));
                if (strpos($name, $search_lower) !== false) {
                    return true;
                }

                // Search in post title as fallback.
                if (strpos(strtolower($project->post_title), $search_lower) !== false) {
                    return true;
                }

                // Search in organization name/shortcode.
                $org_id = get_post_meta($project->ID, 'organization', true);
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

                return false;
            });
            $projects = array_values($projects);
        }

        // Check for user-requested sorting via column headers.
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
        $order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'desc' : 'asc';

        // Status order for sorting.
        $status_order = [
            'Active' => 1,
            'Waiting on Client' => 2,
            'On Hold' => 3,
            'Completed' => 4,
            'Cancelled' => 5,
        ];

        // Apply sorting based on user selection or default.
        if (!empty($orderby)) {
            usort($projects, function($a, $b) use ($orderby, $order, $status_order) {
                $result = 0;

                switch ($orderby) {
                    case 'reference_number':
                        $ref_a = get_post_meta($a->ID, 'reference_number', true);
                        $ref_b = get_post_meta($b->ID, 'reference_number', true);
                        $result = strcmp($ref_a, $ref_b);
                        break;

                    case 'project_name':
                        $name_a = get_post_meta($a->ID, 'project_name', true) ?: $a->post_title;
                        $name_b = get_post_meta($b->ID, 'project_name', true) ?: $b->post_title;
                        $result = strcasecmp($name_a, $name_b);
                        break;

                    case 'organization':
                        $org_a = get_post_meta($a->ID, 'organization', true);
                        $org_b = get_post_meta($b->ID, 'organization', true);
                        $org_a = is_array($org_a) ? reset($org_a) : $org_a;
                        $org_b = is_array($org_b) ? reset($org_b) : $org_b;
                        $short_a = $org_a ? get_post_meta($org_a, 'organization_shortcode', true) : '';
                        $short_b = $org_b ? get_post_meta($org_b, 'organization_shortcode', true) : '';
                        $result = strcasecmp($short_a, $short_b);
                        break;

                    case 'project_status':
                        $stat_a = get_post_meta($a->ID, 'project_status', true);
                        $stat_b = get_post_meta($b->ID, 'project_status', true);
                        $val_a = $status_order[$stat_a] ?? 99;
                        $val_b = $status_order[$stat_b] ?? 99;
                        $result = $val_a - $val_b;
                        break;

                    case 'budget':
                        $budget_a = (float) get_post_meta($a->ID, 'total_budget', true);
                        $budget_b = (float) get_post_meta($b->ID, 'total_budget', true);
                        $result = $budget_a - $budget_b;
                        break;

                    default:
                        $result = 0;
                }

                return $order === 'desc' ? -$result : $result;
            });
        } else {
            // Default sort: by status priority, then by date (newest first).
            usort($projects, function($a, $b) use ($status_order) {
                $status_a = get_post_meta($a->ID, 'project_status', true);
                $status_b = get_post_meta($b->ID, 'project_status', true);

                $order_a = $status_order[$status_a] ?? 99;
                $order_b = $status_order[$status_b] ?? 99;

                if ($order_a !== $order_b) {
                    return $order_a - $order_b;
                }

                return strtotime($b->post_date) - strtotime($a->post_date);
            });
        }

        // Pagination.
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = count($projects);

        $this->items = array_slice($projects, ($current_page - 1) * $per_page, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }

    /**
     * Render the ref number column.
     */
    public function column_ref_number($item): string {
        $ref = get_post_meta($item->ID, 'reference_number', true);
        $edit_link = get_edit_post_link($item->ID, 'raw');

        return sprintf(
            '<a href="%s" class="bbab-ref-link"><strong>%s</strong></a>',
            esc_url($edit_link),
            esc_html($ref)
        );
    }

    /**
     * Render the project name column.
     */
    public function column_project_name($item): string {
        $name = get_post_meta($item->ID, 'project_name', true);
        if (empty($name)) {
            $name = $item->post_title;
        }

        $edit_link = get_edit_post_link($item->ID, 'raw');

        // Row actions.
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_link),
                __('Edit', 'bbab-service-center')
            ),
            'milestones' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(add_query_arg([
                    'post_type' => 'milestone',
                    'bbab_project_id' => $item->ID,
                ], admin_url('edit.php'))),
                __('Milestones', 'bbab-service-center')
            ),
            'add_time_entry' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(add_query_arg([
                    'post_type' => 'time_entry',
                    'related_project' => $item->ID,
                ], admin_url('post-new.php'))),
                __('Add Time Entry', 'bbab-service-center')
            ),
            'time_entries' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(add_query_arg([
                    'post_type' => 'time_entry',
                    'bbab_project_id' => $item->ID,
                ], admin_url('edit.php'))),
                __('View Time Entries', 'bbab-service-center')
            ),
        ];

        return sprintf(
            '<strong>%s</strong>%s',
            esc_html($name),
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
     * Render the status column.
     */
    public function column_status($item): string {
        $status = get_post_meta($item->ID, 'project_status', true);
        $css_class = 'status-' . sanitize_title($status);

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr($css_class),
            esc_html($status)
        );
    }

    /**
     * Render the milestones column.
     */
    public function column_milestones($item): string {
        $milestones = get_posts([
            'post_type' => 'milestone',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'related_project',
                    'value' => $item->ID,
                    'compare' => '=',
                ],
            ],
        ]);

        $count = count($milestones);

        if ($count === 0) {
            return '<span class="bbab-text-muted">0</span>';
        }

        $url = add_query_arg([
            'post_type' => 'milestone',
            'bbab_project_id' => $item->ID,
        ], admin_url('edit.php'));

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%d</a>',
            esc_url($url),
            esc_attr__('View Milestones', 'bbab-service-center'),
            $count
        );
    }

    /**
     * Render the hours column.
     */
    public function column_hours($item): string {
        $cache_key = 'project_hours_' . $item->ID;

        $hours = Cache::remember($cache_key, function() use ($item) {
            $hours = 0;

            // Direct TEs.
            $direct_tes = get_posts([
                'post_type' => 'time_entry',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'related_project',
                        'value' => $item->ID,
                        'compare' => '=',
                    ],
                ],
            ]);

            foreach ($direct_tes as $te) {
                $hours += (float) get_post_meta($te->ID, 'hours', true);
            }

            // Milestone TEs.
            $milestones = get_posts([
                'post_type' => 'milestone',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'related_project',
                        'value' => $item->ID,
                        'compare' => '=',
                    ],
                ],
            ]);

            foreach ($milestones as $ms_id) {
                $ms_tes = get_posts([
                    'post_type' => 'time_entry',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'meta_query' => [
                        [
                            'key' => 'related_milestone',
                            'value' => $ms_id,
                            'compare' => '=',
                        ],
                    ],
                ]);

                foreach ($ms_tes as $te) {
                    $hours += (float) get_post_meta($te->ID, 'hours', true);
                }
            }

            return $hours;
        }, HOUR_IN_SECONDS);

        if ($hours == 0) {
            return '<span class="bbab-text-muted">0</span>';
        }

        $url = add_query_arg([
            'post_type' => 'time_entry',
            'bbab_project_id' => $item->ID,
        ], admin_url('edit.php'));

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%s</a>',
            esc_url($url),
            esc_attr__('View Time Entries', 'bbab-service-center'),
            number_format($hours, 1)
        );
    }

    /**
     * Render the invoices column.
     */
    public function column_invoices($item): string {
        // Get invoices directly linked to project.
        $direct_invoices = get_posts([
            'post_type' => 'invoice',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'related_project',
                    'value' => $item->ID,
                    'compare' => '=',
                ],
            ],
        ]);

        // Get milestones for this project.
        $milestones = get_posts([
            'post_type' => 'milestone',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'related_project',
                    'value' => $item->ID,
                    'compare' => '=',
                ],
            ],
        ]);

        // Get invoices linked to milestones.
        $milestone_invoices = [];
        if (!empty($milestones)) {
            $milestone_invoices = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'related_milestone',
                        'value' => $milestones,
                        'compare' => 'IN',
                    ],
                ],
            ]);
        }

        // Combine and dedupe.
        $all_invoices = array_unique(array_merge($direct_invoices, $milestone_invoices));
        $count = count($all_invoices);

        if ($count === 0) {
            return '<span class="bbab-text-muted">0</span>';
        }

        $url = add_query_arg([
            'post_type' => 'invoice',
            'bbab_project_id' => $item->ID,
        ], admin_url('edit.php'));

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%d</a>',
            esc_url($url),
            esc_attr__('View Invoices', 'bbab-service-center'),
            $count
        );
    }

    /**
     * Render the budget column.
     */
    public function column_budget($item): string {
        $budget = (float) get_post_meta($item->ID, 'total_budget', true);

        if ($budget == 0) {
            return '<span class="bbab-text-muted">—</span>';
        }

        return '$' . number_format($budget, 2);
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
        esc_html_e('No projects found.', 'bbab-service-center');
    }
}
