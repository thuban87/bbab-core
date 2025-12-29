<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns and filters for Client Tasks.
 *
 * Displays:
 * - Task (linked title using task_name field)
 * - Client (organization)
 * - Assigned To (user)
 * - Due Date
 * - Status
 * - Created Date
 *
 * Filters:
 * - Organization dropdown
 * - Status dropdown
 *
 * Migrated from: WPCode Snippet #1109 (CLIENT TASKS section)
 */
class ClientTaskColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definitions
        add_filter('manage_client_task_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_client_task_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-client_task_sortable_columns', [self::class, 'sortableColumns']);

        // Handle sorting
        add_action('pre_get_posts', [self::class, 'handleSorting']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('ClientTaskColumns', 'Registered client task column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];

        // Keep checkbox
        if (isset($columns['cb'])) {
            $new_columns['cb'] = $columns['cb'];
        }

        // Our custom columns
        $new_columns['task'] = 'Task';
        $new_columns['organization'] = 'Client';
        $new_columns['assigned_user'] = 'Assigned To';
        $new_columns['due_date'] = 'Due Date';
        $new_columns['task_status'] = 'Status';
        $new_columns['created'] = 'Created';

        return $new_columns;
    }

    /**
     * Render column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function renderColumn(string $column, int $post_id): void {
        switch ($column) {
            case 'task':
                $task_desc = get_post_meta($post_id, 'task_description', true);
                $display = !empty($task_desc) ? $task_desc : get_the_title($post_id);
                $edit_link = get_edit_post_link($post_id);
                echo '<strong><a class="row-title" href="' . esc_url($edit_link) . '">' . esc_html($display) . '</a></strong>';
                break;

            case 'organization':
                // Client Tasks use Advanced Relationship stored in wp_podsrel
                $shortcode = self::getTaskOrgShortcode($post_id);
                if (!empty($shortcode)) {
                    echo '<code class="org-shortcode">' . esc_html($shortcode) . '</code>';
                } else {
                    echo '<span class="no-value">—</span>';
                }
                break;

            case 'assigned_user':
                $user_id = get_post_meta($post_id, 'assigned_user', true);
                if (!empty($user_id)) {
                    $user = get_user_by('ID', $user_id);
                    if ($user) {
                        echo esc_html($user->display_name);
                    } else {
                        echo '<span class="no-value">—</span>';
                    }
                } else {
                    echo '<span class="no-value">—</span>';
                }
                break;

            case 'due_date':
                $due_date = get_post_meta($post_id, 'due_date', true);
                // Treat empty, '0000-00-00', and '0000-00-00 00:00:00' as no date
                if (!empty($due_date) && $due_date !== '0000-00-00' && $due_date !== '0000-00-00 00:00:00') {
                    $timestamp = strtotime($due_date);
                    $today = strtotime('today');
                    $tomorrow = strtotime('tomorrow');

                    $class = '';
                    if ($timestamp < $today) {
                        $class = 'overdue';
                    } elseif ($timestamp < $tomorrow) {
                        $class = 'due-today';
                    }

                    echo '<span class="due-date ' . esc_attr($class) . '">' . esc_html($due_date) . '</span>';
                } else {
                    echo '<span class="no-value">—</span>';
                }
                break;

            case 'task_status':
                $status = get_post_meta($post_id, 'task_status', true);
                if (!empty($status)) {
                    $class = 'status-' . sanitize_title($status);
                    echo '<span class="task-status ' . esc_attr($class) . '">' . esc_html($status) . '</span>';
                } else {
                    echo '<span class="no-value">—</span>';
                }
                break;

            case 'created':
                $created = get_post_meta($post_id, 'created_date', true);
                echo !empty($created) ? esc_html($created) : esc_html(get_the_date('m/d/Y', $post_id));
                break;
        }
    }

    /**
     * Get organization shortcode for a client task.
     *
     * Client Tasks use Advanced Relationship stored in wp_podsrel table.
     *
     * @param int $task_id Task post ID.
     * @return string Organization shortcode or empty string.
     */
    private static function getTaskOrgShortcode(int $task_id): string {
        global $wpdb;

        // Field ID 1320 is the organization field for client_task pod
        $org_id = $wpdb->get_var($wpdb->prepare(
            "SELECT related_item_id FROM {$wpdb->prefix}podsrel
             WHERE item_id = %d AND field_id = 1320",
            $task_id
        ));

        if (empty($org_id)) {
            return '';
        }

        $shortcode = get_post_meta($org_id, 'organization_shortcode', true);
        return $shortcode ?: '';
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['due_date'] = 'due_date';
        $columns['task_status'] = 'task_status';
        $columns['created'] = 'created';
        return $columns;
    }

    /**
     * Handle custom column sorting.
     *
     * @param \WP_Query $query The query object.
     */
    public static function handleSorting(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'client_task') {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'due_date':
                $query->set('meta_key', 'due_date');
                $query->set('orderby', 'meta_value');
                break;

            case 'task_status':
                $query->set('meta_key', 'task_status');
                $query->set('orderby', 'meta_value');
                break;

            case 'created':
                $query->set('meta_key', 'created_date');
                $query->set('orderby', 'meta_value');
                break;
        }
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'client_task') {
            return;
        }

        // Organization filter
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $current_org = isset($_GET['bbab_org']) ? absint($_GET['bbab_org']) : 0;

        echo '<select name="bbab_org">';
        echo '<option value="">All Clients</option>';
        foreach ($orgs as $org) {
            $shortcode = get_post_meta($org->ID, 'organization_shortcode', true);
            $label = $shortcode ? $shortcode . ' - ' . $org->post_title : $org->post_title;
            printf(
                '<option value="%d" %s>%s</option>',
                $org->ID,
                selected($current_org, $org->ID, false),
                esc_html($label)
            );
        }
        echo '</select>';

        // Status filter
        $statuses = ['Pending', 'Completed'];
        $current_status = isset($_GET['bbab_status']) ? sanitize_text_field($_GET['bbab_status']) : '';

        echo '<select name="bbab_status">';
        echo '<option value="">All Statuses</option>';
        foreach ($statuses as $status) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($status),
                selected($current_status, $status, false),
                esc_html($status)
            );
        }
        echo '</select>';
    }

    /**
     * Apply filter queries.
     *
     * @param \WP_Query $query The query object.
     */
    public static function applyFilters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'client_task') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Organization filter - uses wp_podsrel table (field_id 1320)
        if (!empty($_GET['bbab_org'])) {
            // Store the org ID for use in the posts_where filter
            $query->set('bbab_filter_org_id', absint($_GET['bbab_org']));
            add_filter('posts_where', [self::class, 'filterByOrgWhere'], 10, 2);
        }

        // Status filter
        if (!empty($_GET['bbab_status'])) {
            $meta_query[] = [
                'key' => 'task_status',
                'value' => sanitize_text_field($_GET['bbab_status']),
                'compare' => '=',
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Filter posts by organization using wp_podsrel join.
     *
     * Client Tasks store organization relationship in wp_podsrel table,
     * not in postmeta, so we need a custom WHERE clause.
     *
     * @param string    $where The WHERE clause.
     * @param \WP_Query $query The query object.
     * @return string Modified WHERE clause.
     */
    public static function filterByOrgWhere(string $where, \WP_Query $query): string {
        global $wpdb;

        $org_id = $query->get('bbab_filter_org_id');
        if (!$org_id) {
            return $where;
        }

        // Field ID 1320 is the organization field for client_task pod
        $where .= $wpdb->prepare(
            " AND {$wpdb->posts}.ID IN (
                SELECT item_id FROM {$wpdb->prefix}podsrel
                WHERE field_id = 1320 AND related_item_id = %d
            )",
            $org_id
        );

        // Remove the filter to prevent it from affecting other queries
        remove_filter('posts_where', [self::class, 'filterByOrgWhere'], 10);

        return $where;
    }

    /**
     * Render column styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'client_task') {
            return;
        }

        echo '<style>
            /* Client Task columns */
            .column-task { width: 30%; }
            .column-organization { width: 100px; }
            .column-assigned_user { width: 120px; }
            .column-due_date { width: 100px; }
            .column-task_status { width: 80px; }
            .column-created { width: 90px; }

            /* Shortcode styling */
            .org-shortcode {
                background: #f0f6fc;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }

            /* Due date states */
            .due-date.overdue {
                color: #dc3232;
                font-weight: 500;
            }
            .due-date.due-today {
                color: #dba617;
                font-weight: 500;
            }

            /* Status badges */
            .task-status {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .task-status.status-pending {
                background: #fff3cd;
                color: #856404;
            }
            .task-status.status-completed {
                background: #d4edda;
                color: #155724;
            }

            .no-value {
                color: #999;
            }
        </style>';
    }
}
