<?php
/**
 * Brad's Workbench - Service Requests Sub-Page.
 *
 * Ported from admin/class-workbench-requests.php with proper namespacing.
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
 * Class RequestsSubpage
 *
 * Handles the Service Requests sub-page with enhanced list table.
 */
class RequestsSubpage {

    /**
     * The list table instance.
     *
     * @var RequestsListTable
     */
    private RequestsListTable $list_table;

    /**
     * Render the requests page.
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'bbab-service-center'));
        }

        // Initialize the list table.
        $this->list_table = new RequestsListTable();
        $this->list_table->prepare_items();

        // Get summary stats.
        $stats = $this->getSummaryStats();

        // Get organizations for filter.
        $organizations = $this->getOrganizations();

        // Current filters.
        $current_status = isset($_GET['request_status']) ? sanitize_text_field($_GET['request_status']) : '';
        $current_org = isset($_GET['organization']) ? absint($_GET['organization']) : 0;

        // Load template.
        include BBAB_SC_PATH . 'templates/admin/workbench-requests.php';
    }

    /**
     * Get the list table instance (for template use).
     */
    public function getListTable(): RequestsListTable {
        return $this->list_table;
    }

    /**
     * Get summary statistics for service requests.
     */
    private function getSummaryStats(): array {
        $cache_key = 'requests_summary_stats_monthly';

        return Cache::remember($cache_key, function() {
            // Current month boundaries.
            $month_start = date('Y-m-01 00:00:00');
            $month_end = date('Y-m-t 23:59:59');

            // Get all open service requests.
            $open_requests = get_posts([
                'post_type' => 'service_request',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'request_status',
                        'value' => ['Completed', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            $stats = [
                'total_new' => 0,
                'total_acknowledged' => 0,
                'total_in_progress' => 0,
                'total_waiting' => 0,
                'total_on_hold' => 0,
                'hours_this_month' => 0,
                'completed_this_month' => 0,
            ];

            // Count statuses.
            foreach ($open_requests as $sr) {
                $status = get_post_meta($sr->ID, 'request_status', true);

                switch ($status) {
                    case 'New':
                        $stats['total_new']++;
                        break;
                    case 'Acknowledged':
                        $stats['total_acknowledged']++;
                        break;
                    case 'In Progress':
                        $stats['total_in_progress']++;
                        break;
                    case 'Waiting on Client':
                        $stats['total_waiting']++;
                        break;
                    case 'On Hold':
                        $stats['total_on_hold']++;
                        break;
                }
            }

            // Hours this month - TEs linked to SRs created this month.
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
                        'key' => 'related_service_request',
                        'value' => '',
                        'compare' => '!=',
                    ],
                ],
            ]);

            foreach ($month_tes as $te) {
                $stats['hours_this_month'] += (float) get_post_meta($te->ID, 'hours', true);
            }

            // Completed this month.
            $completed_requests = get_posts([
                'post_type' => 'service_request',
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
                        'key' => 'request_status',
                        'value' => 'Completed',
                        'compare' => '=',
                    ],
                ],
            ]);

            $stats['completed_this_month'] = count($completed_requests);

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
 * Class RequestsListTable
 *
 * Custom WP_List_Table for displaying service requests.
 */
class RequestsListTable extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'request',
            'plural' => 'requests',
            'ajax' => false,
        ]);
    }

    /**
     * Get columns.
     */
    public function get_columns(): array {
        return [
            'ref_number' => __('Ref #', 'bbab-service-center'),
            'subject' => __('Subject', 'bbab-service-center'),
            'organization' => __('Client', 'bbab-service-center'),
            'status' => __('Status', 'bbab-service-center'),
            'request_type' => __('Type', 'bbab-service-center'),
            'priority' => __('Priority', 'bbab-service-center'),
            'hours' => __('Hours', 'bbab-service-center'),
            'created' => __('Created', 'bbab-service-center'),
        ];
    }

    /**
     * Get sortable columns.
     */
    public function get_sortable_columns(): array {
        return [
            'ref_number' => ['reference_number', false],
            'subject' => ['subject', false],
            'organization' => ['organization', false],
            'status' => ['request_status', false],
            'priority' => ['priority', false],
            'created' => ['post_date', true],
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
        $status_filter = isset($_GET['request_status']) ? sanitize_text_field($_GET['request_status']) : '';
        $org_filter = isset($_GET['organization']) ? absint($_GET['organization']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Build query args.
        $args = [
            'post_type' => 'service_request',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ];

        // Status filter.
        if (!empty($status_filter)) {
            $args['meta_query'][] = [
                'key' => 'request_status',
                'value' => $status_filter,
                'compare' => '=',
            ];
        } else {
            // Default: show open requests only.
            $args['meta_query'][] = [
                'key' => 'request_status',
                'value' => ['Completed', 'Cancelled'],
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

        $requests = get_posts($args);

        // Custom search - filter results by meta fields if search term provided.
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $requests = array_filter($requests, function($sr) use ($search_lower) {
                // Search in reference_number.
                $ref = strtolower(get_post_meta($sr->ID, 'reference_number', true));
                if (strpos($ref, $search_lower) !== false) {
                    return true;
                }

                // Search in subject.
                $subject = strtolower(get_post_meta($sr->ID, 'subject', true));
                if (strpos($subject, $search_lower) !== false) {
                    return true;
                }

                // Search in post title as fallback.
                if (strpos(strtolower($sr->post_title), $search_lower) !== false) {
                    return true;
                }

                // Search in organization name/shortcode.
                $org_id = get_post_meta($sr->ID, 'organization', true);
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

                // Search in request_type.
                $type = strtolower(get_post_meta($sr->ID, 'request_type', true));
                if (strpos($type, $search_lower) !== false) {
                    return true;
                }

                // Search in priority.
                $priority = strtolower(get_post_meta($sr->ID, 'priority', true));
                if (strpos($priority, $search_lower) !== false) {
                    return true;
                }

                return false;
            });
            $requests = array_values($requests);
        }

        // Check for user-requested sorting via column headers.
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
        $order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'desc' : 'asc';

        // Priority order mapping for sorting.
        $priority_order = [
            'Urgent' => 1,
            'High' => 2,
            'Normal' => 3,
            'Low' => 4,
        ];

        // Status order for default sorting.
        $status_order = [
            'New' => 1,
            'Acknowledged' => 2,
            'In Progress' => 3,
            'Waiting on Client' => 4,
            'On Hold' => 5,
            'Completed' => 6,
            'Cancelled' => 7,
        ];

        // Apply sorting based on user selection or default.
        if (!empty($orderby)) {
            usort($requests, function($a, $b) use ($orderby, $order, $priority_order, $status_order) {
                $result = 0;

                switch ($orderby) {
                    case 'reference_number':
                        $ref_a = get_post_meta($a->ID, 'reference_number', true);
                        $ref_b = get_post_meta($b->ID, 'reference_number', true);
                        $result = strcmp($ref_a, $ref_b);
                        break;

                    case 'subject':
                        $subj_a = get_post_meta($a->ID, 'subject', true) ?: $a->post_title;
                        $subj_b = get_post_meta($b->ID, 'subject', true) ?: $b->post_title;
                        $result = strcasecmp($subj_a, $subj_b);
                        break;

                    case 'organization':
                        $org_a = get_post_meta($a->ID, 'organization', true);
                        $org_b = get_post_meta($b->ID, 'organization', true);
                        $org_a = is_array($org_a) ? reset($org_a) : $org_a;
                        $org_b = is_array($org_b) ? reset($org_b) : $org_b;
                        $name_a = $org_a ? get_post_meta($org_a, 'organization_shortcode', true) : '';
                        $name_b = $org_b ? get_post_meta($org_b, 'organization_shortcode', true) : '';
                        $result = strcasecmp($name_a, $name_b);
                        break;

                    case 'request_status':
                        $stat_a = get_post_meta($a->ID, 'request_status', true);
                        $stat_b = get_post_meta($b->ID, 'request_status', true);
                        $val_a = $status_order[$stat_a] ?? 99;
                        $val_b = $status_order[$stat_b] ?? 99;
                        $result = $val_a - $val_b;
                        break;

                    case 'priority':
                        $prio_a = get_post_meta($a->ID, 'priority', true);
                        $prio_b = get_post_meta($b->ID, 'priority', true);
                        $val_a = $priority_order[$prio_a] ?? 99;
                        $val_b = $priority_order[$prio_b] ?? 99;
                        $result = $val_a - $val_b;
                        break;

                    case 'post_date':
                        $result = strtotime($a->post_date) - strtotime($b->post_date);
                        break;

                    default:
                        $result = 0;
                }

                return $order === 'desc' ? -$result : $result;
            });
        } else {
            // Default sort: by status priority, then by date (newest first).
            usort($requests, function($a, $b) use ($status_order) {
                $status_a = get_post_meta($a->ID, 'request_status', true);
                $status_b = get_post_meta($b->ID, 'request_status', true);

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
        $total_items = count($requests);

        $this->items = array_slice($requests, ($current_page - 1) * $per_page, $per_page);

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
     * Render the subject column.
     */
    public function column_subject($item): string {
        $subject = get_post_meta($item->ID, 'subject', true);
        if (empty($subject)) {
            $subject = $item->post_title;
        }

        $edit_link = get_edit_post_link($item->ID, 'raw');

        // Row actions.
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_link),
                __('Edit', 'bbab-service-center')
            ),
            'add_time_entry' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(add_query_arg([
                    'post_type' => 'time_entry',
                    'related_service_request' => $item->ID,
                ], admin_url('post-new.php'))),
                __('Add Time Entry', 'bbab-service-center')
            ),
            'time_entries' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(add_query_arg([
                    'post_type' => 'time_entry',
                    'bbab_sr_id' => $item->ID,
                ], admin_url('edit.php'))),
                __('View Time Entries', 'bbab-service-center')
            ),
        ];

        return sprintf(
            '<strong>%s</strong>%s',
            esc_html($subject),
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
        $status = get_post_meta($item->ID, 'request_status', true);
        $css_class = 'status-' . sanitize_title($status);

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr($css_class),
            esc_html($status)
        );
    }

    /**
     * Render the request type column.
     */
    public function column_request_type($item): string {
        $type = get_post_meta($item->ID, 'request_type', true);

        if (empty($type)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        return esc_html($type);
    }

    /**
     * Render the priority column.
     */
    public function column_priority($item): string {
        $priority = get_post_meta($item->ID, 'priority', true);

        if (empty($priority)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        $class = '';
        if ($priority === 'High' || $priority === 'Urgent') {
            $class = 'bbab-priority-high';
        }

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr($class),
            esc_html($priority)
        );
    }

    /**
     * Render the hours column.
     */
    public function column_hours($item): string {
        $cache_key = 'sr_hours_' . $item->ID;

        $hours = Cache::remember($cache_key, function() use ($item) {
            $hours = 0;

            $tes = get_posts([
                'post_type' => 'time_entry',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'related_service_request',
                        'value' => $item->ID,
                        'compare' => '=',
                    ],
                ],
            ]);

            foreach ($tes as $te) {
                $hours += (float) get_post_meta($te->ID, 'hours', true);
            }

            return $hours;
        }, HOUR_IN_SECONDS);

        if ($hours == 0) {
            return '<span class="bbab-text-muted">0</span>';
        }

        $url = add_query_arg([
            'post_type' => 'time_entry',
            'bbab_sr_id' => $item->ID,
        ], admin_url('edit.php'));

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%s</a>',
            esc_url($url),
            esc_attr__('View Time Entries', 'bbab-service-center'),
            number_format($hours, 1)
        );
    }

    /**
     * Render the created column.
     */
    public function column_created($item): string {
        $submitted_date = get_post_meta($item->ID, 'submitted_date', true);

        if (!empty($submitted_date)) {
            return date_i18n('M j, Y', strtotime($submitted_date));
        }

        return date_i18n('M j, Y', strtotime($item->post_date));
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
        esc_html_e('No service requests found.', 'bbab-service-center');
    }
}
