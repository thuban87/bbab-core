<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\Projects\ProjectReportPDFService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns, filters, sorting, and row actions for Project Reports.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Admin list filters (Org, Type, Status)
 * - Sortable columns
 * - Default sort order (report_date DESC)
 * - Row actions (Finalize, Revert to Draft)
 * - Finalize action with PDF generation
 *
 * Migrated from: WPCode Snippet #2669
 */
class ProjectReportColumns {

    /**
     * Report type badge colors.
     */
    private const TYPE_COLORS = [
        'Summary' => '#3b82f6',         // Blue
        'Handoff' => '#8b5cf6',         // Purple
        'Welcome Package' => '#14b8a6', // Teal
    ];

    /**
     * Report status badge colors.
     */
    private const STATUS_COLORS = [
        'Draft' => '#6b7280',     // Gray
        'Finalized' => '#22c55e', // Green
    ];

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_project_report_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_project_report_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-project_report_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Default sort order
        add_action('pre_get_posts', [self::class, 'setDefaultSort'], 5);

        // Row actions
        add_filter('post_row_actions', [self::class, 'addRowActions'], 10, 2);

        // Handle row action requests
        add_action('admin_post_bbab_finalize_report', [self::class, 'handleFinalize']);
        add_action('admin_post_bbab_revert_report_draft', [self::class, 'handleRevertToDraft']);

        // Admin notices
        add_action('admin_notices', [self::class, 'showAdminNotices']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('ProjectReportColumns', 'Registered project report column hooks');
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
        $new_columns['report_number'] = 'Report #';
        $new_columns['organization'] = 'Client';
        $new_columns['related_project'] = 'Project';
        $new_columns['report_type'] = 'Type';
        $new_columns['report_status'] = 'Status';
        $new_columns['report_date'] = 'Date';
        $new_columns['report_pdf'] = 'PDF';

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
            case 'report_number':
                $number = get_post_meta($post_id, 'report_number', true);
                if ($number) {
                    echo '<strong class="report-number">' . esc_html($number) . '</strong>';
                } else {
                    echo '<span class="no-number">—</span>';
                }
                break;

            case 'organization':
                self::renderOrgColumn($post_id);
                break;

            case 'related_project':
                self::renderProjectColumn($post_id);
                break;

            case 'report_type':
                $type = get_post_meta($post_id, 'report_type', true);
                if ($type) {
                    echo self::getBadgeHtml($type, self::TYPE_COLORS[$type] ?? '#6b7280');
                } else {
                    echo '—';
                }
                break;

            case 'report_status':
                $status = get_post_meta($post_id, 'report_status', true);
                if ($status) {
                    echo self::getBadgeHtml($status, self::STATUS_COLORS[$status] ?? '#6b7280');
                } else {
                    echo '—';
                }
                break;

            case 'report_date':
                $date = get_post_meta($post_id, 'report_date', true);
                if ($date && strtotime($date) !== false) {
                    echo esc_html(date('M j, Y', strtotime($date)));
                } else {
                    echo '—';
                }
                break;

            case 'report_pdf':
                self::renderPdfColumn($post_id);
                break;
        }
    }

    /**
     * Render organization column.
     *
     * @param int $post_id Post ID.
     */
    private static function renderOrgColumn(int $post_id): void {
        $org_id = get_post_meta($post_id, 'organization', true);

        if (!$org_id) {
            echo '—';
            return;
        }

        // Try shortcode first, then name, then title
        $org_name = get_post_meta($org_id, 'organization_shortcode', true);
        if (!$org_name) {
            $org_name = get_post_meta($org_id, 'organization_name', true);
        }
        if (!$org_name) {
            $org_name = get_the_title($org_id);
        }

        $edit_link = get_edit_post_link((int) $org_id);
        if ($edit_link) {
            echo '<a href="' . esc_url($edit_link) . '">' . esc_html($org_name) . '</a>';
        } else {
            echo esc_html($org_name);
        }
    }

    /**
     * Render project column.
     *
     * @param int $post_id Post ID.
     */
    private static function renderProjectColumn(int $post_id): void {
        $project_id = get_post_meta($post_id, 'related_project', true);

        if (!$project_id) {
            echo '—';
            return;
        }

        // Show reference number if available, otherwise project name
        $project_ref = get_post_meta($project_id, 'reference_number', true);
        $project_name = get_post_meta($project_id, 'project_name', true);
        $display = $project_ref ?: $project_name ?: get_the_title($project_id);

        $edit_link = get_edit_post_link((int) $project_id);
        if ($edit_link) {
            echo '<a href="' . esc_url($edit_link) . '">' . esc_html($display) . '</a>';
        } else {
            echo esc_html($display);
        }
    }

    /**
     * Render PDF column.
     *
     * @param int $post_id Post ID.
     */
    private static function renderPdfColumn(int $post_id): void {
        $pdf_id = get_post_meta($post_id, 'report_pdf', true);

        if (!$pdf_id) {
            echo '<span class="no-pdf">—</span>';
            return;
        }

        $pdf_url = wp_get_attachment_url($pdf_id);
        if ($pdf_url) {
            // Add cache bust to ensure fresh PDF
            $cache_bust = '?v=' . time();
            echo '<a href="' . esc_url($pdf_url . $cache_bust) . '" target="_blank" title="View PDF" class="pdf-link">📄</a>';
        } else {
            echo '<span class="no-pdf">—</span>';
        }
    }

    /**
     * Generate a colored badge HTML.
     *
     * @param string $label Badge label.
     * @param string $color Background color.
     * @return string HTML for badge.
     */
    private static function getBadgeHtml(string $label, string $color): string {
        return sprintf(
            '<span class="report-badge" style="background:%s;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['report_number'] = 'report_number';
        $columns['report_date'] = 'report_date';
        return $columns;
    }

    /**
     * Set default sort order to newest first by report date.
     *
     * @param \WP_Query $query The query object.
     */
    public static function setDefaultSort(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'project_report') {
            return;
        }

        // Only set default if no orderby specified
        if (!$query->get('orderby')) {
            $query->set('meta_key', 'report_date');
            $query->set('orderby', 'meta_value');
            $query->set('order', 'DESC');
        }
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'project_report') {
            return;
        }

        // Organization filter
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        $selected_org = isset($_GET['filter_org']) ? sanitize_text_field($_GET['filter_org']) : '';

        echo '<select name="filter_org">';
        echo '<option value="">All Clients</option>';
        foreach ($orgs as $org) {
            $org_name = get_post_meta($org->ID, 'organization_name', true);
            if (!$org_name) {
                $org_name = $org->post_title;
            }
            $selected = selected($selected_org, (string) $org->ID, false);
            echo '<option value="' . esc_attr($org->ID) . '"' . $selected . '>' . esc_html($org_name) . '</option>';
        }
        echo '</select>';

        // Report Type filter
        $types = ['Summary', 'Handoff', 'Welcome Package'];
        $selected_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';

        echo '<select name="filter_type">';
        echo '<option value="">All Types</option>';
        foreach ($types as $type) {
            $selected = selected($selected_type, $type, false);
            echo '<option value="' . esc_attr($type) . '"' . $selected . '>' . esc_html($type) . '</option>';
        }
        echo '</select>';

        // Status filter
        $statuses = ['Draft', 'Finalized'];
        $selected_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';

        echo '<select name="filter_status">';
        echo '<option value="">All Statuses</option>';
        foreach ($statuses as $status) {
            $selected = selected($selected_status, $status, false);
            echo '<option value="' . esc_attr($status) . '"' . $selected . '>' . esc_html($status) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Apply filters to the query.
     *
     * @param \WP_Query $query The query object.
     */
    public static function applyFilters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'project_report') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Organization filter
        if (!empty($_GET['filter_org'])) {
            $meta_query[] = [
                'key' => 'organization',
                'value' => sanitize_text_field($_GET['filter_org']),
                'compare' => '=',
            ];
        }

        // Type filter
        if (!empty($_GET['filter_type'])) {
            $meta_query[] = [
                'key' => 'report_type',
                'value' => sanitize_text_field($_GET['filter_type']),
                'compare' => '=',
            ];
        }

        // Status filter
        if (!empty($_GET['filter_status'])) {
            $meta_query[] = [
                'key' => 'report_status',
                'value' => sanitize_text_field($_GET['filter_status']),
                'compare' => '=',
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        // Handle sorting by custom meta fields
        $orderby = $query->get('orderby');

        if ($orderby === 'report_number') {
            $query->set('meta_key', 'report_number');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'report_date') {
            $query->set('meta_key', 'report_date');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Render column and badge styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'project_report') {
            return;
        }

        echo '<style>
            /* Report Number */
            .report-number {
                font-family: monospace;
                color: #467FF7;
            }
            .no-number, .no-pdf {
                color: #999;
            }

            /* Badge styling */
            .report-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
                color: white;
                font-weight: 500;
            }

            /* PDF link */
            .pdf-link {
                text-decoration: none;
                font-size: 16px;
            }

            /* Column widths */
            .column-report_number { width: 110px; }
            .column-organization { width: 120px; }
            .column-related_project { width: 140px; }
            .column-report_type { width: 110px; }
            .column-report_status { width: 90px; }
            .column-report_date { width: 90px; }
            .column-report_pdf { width: 50px; text-align: center; }
        </style>';
    }

    /**
     * Add row actions to project reports.
     *
     * @param array    $actions Existing actions.
     * @param \WP_Post $post    Post object.
     * @return array Modified actions.
     */
    public static function addRowActions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'project_report') {
            return $actions;
        }

        $status = get_post_meta($post->ID, 'report_status', true);

        // Finalize action (Draft -> Finalized with PDF generation)
        if ($status !== 'Finalized') {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_finalize_report&report_id=' . $post->ID),
                'bbab_finalize_report_' . $post->ID
            );
            $actions['finalize'] = '<a href="' . esc_url($url) . '" style="color: #22c55e; font-weight: 500;">Finalize & Generate PDF</a>';
        }

        // Revert to Draft (Finalized -> Draft)
        if ($status === 'Finalized') {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_revert_report_draft&report_id=' . $post->ID),
                'bbab_revert_report_draft_' . $post->ID
            );
            $actions['revert_draft'] = '<a href="' . esc_url($url) . '" style="color: #666;">Revert to Draft</a>';
        }

        return $actions;
    }

    /**
     * Handle Finalize action - generates PDF and sets status to Finalized.
     */
    public static function handleFinalize(): void {
        $report_id = isset($_GET['report_id']) ? (int) $_GET['report_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_finalize_report_' . $report_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $report_id)) {
            wp_die('Permission denied');
        }

        $report = get_post($report_id);
        if (!$report || $report->post_type !== 'project_report') {
            wp_die('Invalid Project Report');
        }

        // Generate PDF
        $pdf_result = ProjectReportPDFService::generate($report_id);

        if (is_wp_error($pdf_result)) {
            Logger::error('ProjectReportColumns', 'PDF generation failed during finalize', [
                'report_id' => $report_id,
                'error' => $pdf_result->get_error_message(),
            ]);
            wp_redirect(admin_url('edit.php?post_type=project_report&finalized=0&pdf_error=' . urlencode($pdf_result->get_error_message())));
            exit;
        }

        // Update status to Finalized
        update_post_meta($report_id, 'report_status', 'Finalized');

        Logger::debug('ProjectReportColumns', 'Report finalized', ['report_id' => $report_id]);

        // Redirect back to edit screen if requested, otherwise to list
        $redirect = isset($_GET['redirect']) ? sanitize_text_field($_GET['redirect']) : 'list';
        if ($redirect === 'edit') {
            wp_redirect(admin_url('post.php?post=' . $report_id . '&action=edit&finalized=1'));
        } else {
            wp_redirect(admin_url('edit.php?post_type=project_report&finalized=1'));
        }
        exit;
    }

    /**
     * Handle Revert to Draft action.
     */
    public static function handleRevertToDraft(): void {
        $report_id = isset($_GET['report_id']) ? (int) $_GET['report_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_revert_report_draft_' . $report_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $report_id)) {
            wp_die('Permission denied');
        }

        update_post_meta($report_id, 'report_status', 'Draft');

        Logger::debug('ProjectReportColumns', 'Report reverted to draft', ['report_id' => $report_id]);

        wp_redirect(admin_url('edit.php?post_type=project_report&reverted=1'));
        exit;
    }

    /**
     * Show admin notices for row actions.
     */
    public static function showAdminNotices(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'project_report') {
            return;
        }

        if (isset($_GET['finalized']) && $_GET['finalized'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Report finalized!</strong> PDF has been generated and attached.</p></div>';
        }

        if (isset($_GET['finalized']) && $_GET['finalized'] === '0' && isset($_GET['pdf_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Finalization failed:</strong> ' . esc_html($_GET['pdf_error']) . '</p></div>';
        }

        if (isset($_GET['reverted']) && $_GET['reverted'] === '1') {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Report reverted to draft.</strong></p></div>';
        }
    }
}
