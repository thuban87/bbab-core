<?php
/**
 * Brad's Workbench - Roadmap Items Sub-Page.
 *
 * Ported from admin/class-workbench-roadmap.php with proper namespacing.
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
 * Class RoadmapSubpage
 *
 * Handles the Roadmap Items sub-page with enhanced list table.
 */
class RoadmapSubpage {

    /**
     * The list table instance.
     *
     * @var RoadmapListTable
     */
    private RoadmapListTable $list_table;

    /**
     * Render the roadmap page.
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'bbab-service-center'));
        }

        // Initialize the list table.
        $this->list_table = new RoadmapListTable();
        $this->list_table->prepare_items();

        // Get summary stats.
        $stats = $this->getSummaryStats();

        // Get organizations for filter.
        $organizations = $this->getOrganizations();

        // Current filters.
        $current_status = isset($_GET['roadmap_status']) ? sanitize_text_field($_GET['roadmap_status']) : '';
        $current_org = isset($_GET['organization']) ? absint($_GET['organization']) : 0;

        // Load template.
        include BBAB_SC_PATH . 'templates/admin/workbench-roadmap.php';
    }

    /**
     * Get the list table instance (for template use).
     */
    public function getListTable(): RoadmapListTable {
        return $this->list_table;
    }

    /**
     * Get summary statistics for roadmap items.
     */
    private function getSummaryStats(): array {
        $cache_key = 'roadmap_summary_stats';

        return Cache::remember($cache_key, function() {
            // Get all roadmap items.
            $all_items = get_posts([
                'post_type' => 'roadmap_item',
                'post_status' => 'publish',
                'posts_per_page' => -1,
            ]);

            $stats = [
                'total_idea' => 0,
                'total_adr' => 0,
                'total_proposed' => 0,
                'total_approved' => 0,
                'total_declined' => 0,
            ];

            foreach ($all_items as $item) {
                $status = get_post_meta($item->ID, 'roadmap_status', true);

                switch ($status) {
                    case 'Idea':
                        $stats['total_idea']++;
                        break;
                    case 'ADR In Progress':
                        $stats['total_adr']++;
                        break;
                    case 'Proposed':
                        $stats['total_proposed']++;
                        break;
                    case 'Approved':
                        $stats['total_approved']++;
                        break;
                    case 'Declined':
                        $stats['total_declined']++;
                        break;
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
 * Class RoadmapListTable
 *
 * Custom WP_List_Table for displaying roadmap items.
 */
class RoadmapListTable extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'roadmap_item',
            'plural' => 'roadmap_items',
            'ajax' => false,
        ]);
    }

    /**
     * Get columns.
     */
    public function get_columns(): array {
        return [
            'title' => __('Feature', 'bbab-service-center'),
            'organization' => __('Client', 'bbab-service-center'),
            'status' => __('Status', 'bbab-service-center'),
            'priority' => __('Priority', 'bbab-service-center'),
            'category' => __('Category', 'bbab-service-center'),
            'submitted_by' => __('Submitted By', 'bbab-service-center'),
            'project' => __('Project', 'bbab-service-center'),
        ];
    }

    /**
     * Get sortable columns.
     */
    public function get_sortable_columns(): array {
        return [
            'title' => ['title', false],
            'organization' => ['organization', false],
            'status' => ['roadmap_status', false],
            'priority' => ['priority', false],
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
        $status_filter = isset($_GET['roadmap_status']) ? sanitize_text_field($_GET['roadmap_status']) : '';
        $org_filter = isset($_GET['organization']) ? absint($_GET['organization']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Build query args.
        $args = [
            'post_type' => 'roadmap_item',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ];

        // Status filter.
        if (!empty($status_filter)) {
            $args['meta_query'][] = [
                'key' => 'roadmap_status',
                'value' => $status_filter,
                'compare' => '=',
            ];
        } else {
            // Default: show active items only (not Approved/Declined).
            $args['meta_query'][] = [
                'key' => 'roadmap_status',
                'value' => ['Idea', 'ADR In Progress', 'Proposed'],
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

        $items = get_posts($args);

        // Custom search - filter results if search term provided.
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $items = array_filter($items, function($item) use ($search_lower) {
                // Search in title.
                if (strpos(strtolower($item->post_title), $search_lower) !== false) {
                    return true;
                }

                // Search in description.
                $desc = strtolower(get_post_meta($item->ID, 'description', true));
                if (strpos($desc, $search_lower) !== false) {
                    return true;
                }

                // Search in organization name/shortcode.
                $org_id = get_post_meta($item->ID, 'organization', true);
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
            $items = array_values($items);
        }

        // Check for user-requested sorting via column headers.
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
        $order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'desc' : 'asc';

        // Priority order for sorting.
        $priority_order = [
            'High' => 1,
            'Medium' => 2,
            'Low' => 3,
        ];

        // Status order for sorting.
        $status_order = [
            'Proposed' => 1,
            'ADR In Progress' => 2,
            'Idea' => 3,
            'Approved' => 4,
            'Declined' => 5,
        ];

        if (!empty($orderby)) {
            usort($items, function($a, $b) use ($orderby, $order, $priority_order, $status_order) {
                $result = 0;

                switch ($orderby) {
                    case 'title':
                        $result = strcasecmp($a->post_title, $b->post_title);
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

                    case 'roadmap_status':
                        $stat_a = get_post_meta($a->ID, 'roadmap_status', true);
                        $stat_b = get_post_meta($b->ID, 'roadmap_status', true);
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
                }

                return $order === 'desc' ? -$result : $result;
            });
        } else {
            // Default sort: by status priority, then by priority level, then by date.
            usort($items, function($a, $b) use ($status_order, $priority_order) {
                $status_a = get_post_meta($a->ID, 'roadmap_status', true);
                $status_b = get_post_meta($b->ID, 'roadmap_status', true);

                $s_order_a = $status_order[$status_a] ?? 99;
                $s_order_b = $status_order[$status_b] ?? 99;

                if ($s_order_a !== $s_order_b) {
                    return $s_order_a - $s_order_b;
                }

                // Same status, sort by priority.
                $prio_a = get_post_meta($a->ID, 'priority', true);
                $prio_b = get_post_meta($b->ID, 'priority', true);

                $p_order_a = $priority_order[$prio_a] ?? 99;
                $p_order_b = $priority_order[$prio_b] ?? 99;

                if ($p_order_a !== $p_order_b) {
                    return $p_order_a - $p_order_b;
                }

                // Same priority, sort by date (newest first).
                return strtotime($b->post_date) - strtotime($a->post_date);
            });
        }

        // Pagination.
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = count($items);

        $this->items = array_slice($items, ($current_page - 1) * $per_page, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }

    /**
     * Render the title column.
     */
    public function column_title($item): string {
        $edit_link = get_edit_post_link($item->ID, 'raw');
        $title = $item->post_title;

        // Truncate if too long.
        $truncated = mb_strlen($title) > 50 ? mb_substr($title, 0, 50) . '...' : $title;

        // Row actions.
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_link),
                __('Edit', 'bbab-service-center')
            ),
        ];

        // Add quick actions based on status.
        $status = get_post_meta($item->ID, 'roadmap_status', true);

        if ('Idea' === $status) {
            $start_adr_url = add_query_arg(
                [
                    'action' => 'bbab_start_adr',
                    'item_id' => $item->ID,
                    '_wpnonce' => wp_create_nonce('bbab_start_adr_' . $item->ID),
                ],
                admin_url('admin-post.php')
            );
            $actions['start_adr'] = sprintf(
                '<a href="%s" style="color: #dba617;">%s</a>',
                esc_url($start_adr_url),
                __('Start ADR', 'bbab-service-center')
            );
        }

        if ('Approved' === $status) {
            $project_id = get_post_meta($item->ID, 'related_project', true);
            if (empty($project_id)) {
                $create_project_url = add_query_arg(
                    [
                        'action' => 'bbab_create_project',
                        'item_id' => $item->ID,
                        '_wpnonce' => wp_create_nonce('bbab_create_project_' . $item->ID),
                    ],
                    admin_url('admin-post.php')
                );
                $actions['create_project'] = sprintf(
                    '<a href="%s" style="color: #00a32a;">%s</a>',
                    esc_url($create_project_url),
                    __('Create Project', 'bbab-service-center')
                );
            }
        }

        return sprintf(
            '<a href="%s"><strong title="%s">%s</strong></a>%s',
            esc_url($edit_link),
            esc_attr($title),
            esc_html($truncated),
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
        $status = get_post_meta($item->ID, 'roadmap_status', true);

        $css_class = 'status-' . sanitize_title($status);

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr($css_class),
            esc_html($status)
        );
    }

    /**
     * Render the priority column.
     */
    public function column_priority($item): string {
        $priority = get_post_meta($item->ID, 'priority', true);

        if (empty($priority)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        $css_class = 'priority-' . strtolower($priority);

        return sprintf(
            '<span class="bbab-priority-badge %s">%s</span>',
            esc_attr($css_class),
            esc_html($priority)
        );
    }

    /**
     * Render the category column.
     */
    public function column_category($item): string {
        $category = get_post_meta($item->ID, 'roadmap_category', true);

        if (empty($category)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        return esc_html($category);
    }

    /**
     * Render the submitted by column.
     */
    public function column_submitted_by($item): string {
        $submitted_by = get_post_meta($item->ID, 'submitted_by', true);

        if (empty($submitted_by)) {
            // Check post author.
            $author = get_user_by('id', $item->post_author);
            if ($author && user_can($author->ID, 'administrator')) {
                return '<span class="bbab-text-muted">' . esc_html__('Brad', 'bbab-service-center') . '</span>';
            }
            return '<span class="bbab-text-muted">—</span>';
        }

        if (is_array($submitted_by)) {
            $submitted_by = reset($submitted_by);
        }

        $user = get_user_by('id', $submitted_by);
        if (!$user) {
            return '<span class="bbab-text-muted">—</span>';
        }

        // Check if admin (Brad) or client.
        if (user_can($user->ID, 'administrator')) {
            return '<span class="bbab-text-muted">' . esc_html__('Brad', 'bbab-service-center') . '</span>';
        }

        return esc_html($user->display_name);
    }

    /**
     * Render the project column.
     */
    public function column_project($item): string {
        $project_id = get_post_meta($item->ID, 'related_project', true);

        if (empty($project_id)) {
            return '<span class="bbab-text-muted">—</span>';
        }

        if (is_array($project_id)) {
            $project_id = reset($project_id);
        }

        $ref = get_post_meta($project_id, 'reference_number', true);

        return sprintf(
            '<a href="%s" class="bbab-ref-link">%s</a>',
            esc_url(get_edit_post_link($project_id, 'raw')),
            esc_html($ref)
        );
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
        esc_html_e('No roadmap items found.', 'bbab-service-center');
    }
}
