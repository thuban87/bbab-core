<?php
/**
 * Brad's Workbench - Main Dashboard Page.
 *
 * Ported from admin/class-workbench.php with proper namespacing.
 *
 * @package BBAB\ServiceCenter\Admin\Workbench
 * @since   2.0.0
 */

declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Workbench;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Core\SimulationBootstrap;

class WorkbenchPage {

    /**
     * Status sort order for Service Requests.
     */
    private array $sr_status_order = [
        'New'               => 1,
        'Acknowledged'      => 2,
        'In Progress'       => 3,
        'Waiting on Client' => 4,
        'On Hold'           => 5,
    ];

    /**
     * Status sort order for Projects.
     */
    private array $project_status_order = [
        'Active'            => 1,
        'Waiting on Client' => 2,
        'On Hold'           => 3,
    ];

    /**
     * Status sort order for Invoices.
     */
    private array $invoice_status_order = [
        'Draft'   => 1,
        'Pending' => 2,
        'Partial' => 3,
        'Overdue' => 4,
    ];

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'registerMenuPages']);
        add_action('admin_init', [$this, 'handleSimulationActions']);
        add_action('pre_get_posts', [$this, 'handleAdminListFilters']);
    }

    /**
     * Handle custom admin list filters for time entries and milestones.
     */
    public function handleAdminListFilters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $post_type = $query->get('post_type');

        // Filter time entries by SR
        if ($post_type === 'time_entry' && !empty($_GET['bbab_sr_id'])) {
            $sr_id = absint($_GET['bbab_sr_id']);
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query[] = [
                'key' => 'related_service_request',
                'value' => $sr_id,
                'compare' => '=',
            ];
            $query->set('meta_query', $meta_query);
        }

        // Filter time entries by project (includes direct project TEs AND milestone TEs)
        if ($post_type === 'time_entry' && !empty($_GET['bbab_project_id'])) {
            $project_id = absint($_GET['bbab_project_id']);

            // Get all milestone IDs for this project
            $milestone_ids = get_posts([
                'post_type' => 'milestone',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'related_project',
                        'value' => $project_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            $meta_query = $query->get('meta_query') ?: [];

            // Build OR query: related_project = X OR related_milestone IN (milestone_ids)
            $project_filter = [
                'relation' => 'OR',
                [
                    'key' => 'related_project',
                    'value' => $project_id,
                    'compare' => '=',
                ],
            ];

            if (!empty($milestone_ids)) {
                $project_filter[] = [
                    'key' => 'related_milestone',
                    'value' => $milestone_ids,
                    'compare' => 'IN',
                ];
            }

            $meta_query[] = $project_filter;
            $query->set('meta_query', $meta_query);
        }

        // Filter milestones by project
        if ($post_type === 'milestone' && !empty($_GET['bbab_project_id'])) {
            $project_id = absint($_GET['bbab_project_id']);
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query[] = [
                'key' => 'related_project',
                'value' => $project_id,
                'compare' => '=',
            ];
            $query->set('meta_query', $meta_query);
        }

        // Filter invoices by project (includes direct project invoices AND milestone invoices)
        if ($post_type === 'invoice' && !empty($_GET['bbab_project_id'])) {
            $project_id = absint($_GET['bbab_project_id']);

            // Get all milestone IDs for this project
            $milestone_ids = get_posts([
                'post_type' => 'milestone',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'related_project',
                        'value' => $project_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            $meta_query = $query->get('meta_query') ?: [];

            // Build OR query: related_project = X OR related_milestone IN (milestone_ids)
            $project_filter = [
                'relation' => 'OR',
                [
                    'key' => 'related_project',
                    'value' => $project_id,
                    'compare' => '=',
                ],
            ];

            if (!empty($milestone_ids)) {
                $project_filter[] = [
                    'key' => 'related_milestone',
                    'value' => $milestone_ids,
                    'compare' => 'IN',
                ];
            }

            $meta_query[] = $project_filter;
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Register admin menu pages.
     */
    public function registerMenuPages(): void {
        // Main menu page
        add_menu_page(
            __("Brad's Workbench", 'bbab-service-center'),
            __("Brad's Workbench", 'bbab-service-center'),
            'manage_options',
            'bbab-workbench',
            [$this, 'renderMainPage'],
            'dashicons-desktop',
            2
        );

        // Rename the auto-created submenu item
        add_submenu_page(
            'bbab-workbench',
            __("Brad's Workbench", 'bbab-service-center'),
            __('Dashboard', 'bbab-service-center'),
            'manage_options',
            'bbab-workbench',
            [$this, 'renderMainPage']
        );

        // Sub-pages - will be fully ported later
        add_submenu_page(
            'bbab-workbench',
            __('Projects', 'bbab-service-center'),
            __('Projects', 'bbab-service-center'),
            'manage_options',
            'bbab-projects',
            [$this, 'renderProjectsPage']
        );

        add_submenu_page(
            'bbab-workbench',
            __('Service Requests', 'bbab-service-center'),
            __('Service Requests', 'bbab-service-center'),
            'manage_options',
            'bbab-requests',
            [$this, 'renderRequestsPage']
        );

        add_submenu_page(
            'bbab-workbench',
            __('Invoices', 'bbab-service-center'),
            __('Invoices', 'bbab-service-center'),
            'manage_options',
            'bbab-invoices',
            [$this, 'renderInvoicesPage']
        );

        add_submenu_page(
            'bbab-workbench',
            __('Client Tasks', 'bbab-service-center'),
            __('Client Tasks', 'bbab-service-center'),
            'manage_options',
            'bbab-tasks',
            [$this, 'renderTasksPage']
        );

        add_submenu_page(
            'bbab-workbench',
            __('Roadmap Items', 'bbab-service-center'),
            __('Roadmap Items', 'bbab-service-center'),
            'manage_options',
            'bbab-roadmap',
            [$this, 'renderRoadmapPage']
        );
    }

    /**
     * Handle simulation start/stop actions.
     */
    public function handleSimulationActions(): void {
        // Only on our page
        if (!isset($_GET['page']) || $_GET['page'] !== 'bbab-workbench') {
            return;
        }

        // Exit simulation
        if (isset($_GET['bbab_sc_exit_simulation'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_sc_simulation')) {
                return;
            }
            SimulationBootstrap::clearSimulation();
            wp_safe_redirect(admin_url('admin.php?page=bbab-workbench'));
            exit;
        }

        // Start simulation
        if (isset($_GET['bbab_sc_simulate_org']) && !empty($_GET['bbab_sc_simulate_org'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_sc_simulation')) {
                return;
            }
            $org_id = absint($_GET['bbab_sc_simulate_org']);
            $org_post = get_post($org_id);
            if ($org_post && $org_post->post_type === 'client_organization') {
                SimulationBootstrap::setSimulation($org_id);
            }
            wp_safe_redirect(admin_url('admin.php?page=bbab-workbench'));
            exit;
        }
    }

    /**
     * Render the main workbench page.
     */
    public function renderMainPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'bbab-service-center'));
        }

        // Get data for boxes
        $service_requests = $this->getOpenServiceRequests(10);
        $projects = $this->getActiveProjects(10);
        $invoices = $this->getPendingInvoices(10);
        $client_tasks = $this->getPendingClientTasks(10);
        $roadmap_items = $this->getActiveRoadmapItems(10);

        // Get counts
        $sr_total_count = $this->getOpenServiceRequestCount();
        $project_total_count = $this->getActiveProjectCount();
        $invoice_total_count = $this->getPendingInvoiceCount();
        $task_total_count = $this->getPendingClientTaskCount();
        $roadmap_total_count = $this->getActiveRoadmapItemCount();

        // Get organizations for simulation
        $organizations = $this->getAllOrganizations();

        // Get simulation state
        $simulating_org_id = SimulationBootstrap::getCurrentSimulatedOrgId();
        $simulating_org_name = $simulating_org_id ? get_the_title($simulating_org_id) : '';

        // Render the page
        include BBAB_SC_PATH . 'templates/admin/workbench-main.php';
    }

    /**
     * Render the Projects sub-page.
     */
    public function renderProjectsPage(): void {
        $subpage = new ProjectsSubpage();
        $subpage->render();
    }

    /**
     * Render the Service Requests sub-page.
     */
    public function renderRequestsPage(): void {
        $subpage = new RequestsSubpage();
        $subpage->render();
    }

    /**
     * Render the Invoices sub-page.
     */
    public function renderInvoicesPage(): void {
        $subpage = new InvoicesSubpage();
        $subpage->render();
    }

    /**
     * Render the Client Tasks sub-page.
     */
    public function renderTasksPage(): void {
        $subpage = new TasksSubpage();
        $subpage->render();
    }

    /**
     * Render the Roadmap Items sub-page.
     */
    public function renderRoadmapPage(): void {
        $subpage = new RoadmapSubpage();
        $subpage->render();
    }

    /**
     * Get all organizations for simulation dropdown.
     */
    private function getAllOrganizations(): array {
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $result = [];
        foreach ($orgs as $org) {
            $shortcode = get_post_meta($org->ID, 'organization_shortcode', true)
                ?: get_post_meta($org->ID, 'shortcode', true);
            $result[] = [
                'id' => $org->ID,
                'name' => $org->post_title,
                'shortcode' => $shortcode,
            ];
        }

        return $result;
    }

    /**
     * Get open service requests.
     */
    public function getOpenServiceRequests(int $limit = 10): array {
        $cache_key = 'workbench_open_srs_' . $limit;

        return Cache::remember($cache_key, function() use ($limit) {
            $results = get_posts([
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

            usort($results, function($a, $b) {
                $status_a = get_post_meta($a->ID, 'request_status', true);
                $status_b = get_post_meta($b->ID, 'request_status', true);

                $order_a = $this->sr_status_order[$status_a] ?? 99;
                $order_b = $this->sr_status_order[$status_b] ?? 99;

                if ($order_a !== $order_b) {
                    return $order_a - $order_b;
                }

                return strtotime($b->post_date) - strtotime($a->post_date);
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    public function getOpenServiceRequestCount(): int {
        $cache_key = 'workbench_open_srs_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'service_request',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'request_status',
                        'value' => ['Completed', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get active projects.
     */
    public function getActiveProjects(int $limit = 10): array {
        $cache_key = 'workbench_active_projects_' . $limit;

        return Cache::remember($cache_key, function() use ($limit) {
            $results = get_posts([
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

            usort($results, function($a, $b) {
                $status_a = get_post_meta($a->ID, 'project_status', true);
                $status_b = get_post_meta($b->ID, 'project_status', true);

                $order_a = $this->project_status_order[$status_a] ?? 99;
                $order_b = $this->project_status_order[$status_b] ?? 99;

                if ($order_a !== $order_b) {
                    return $order_a - $order_b;
                }

                return strtotime($b->post_date) - strtotime($a->post_date);
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    public function getActiveProjectCount(): int {
        $cache_key = 'workbench_active_projects_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'project',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'project_status',
                        'value' => ['Active', 'Waiting on Client', 'On Hold'],
                        'compare' => 'IN',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get pending invoices.
     */
    public function getPendingInvoices(int $limit = 10): array {
        $cache_key = 'workbench_pending_invoices_' . $limit;

        return Cache::remember($cache_key, function() use ($limit) {
            $results = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'invoice_status',
                        'value' => ['Paid', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            usort($results, function($a, $b) {
                $status_a = get_post_meta($a->ID, 'invoice_status', true);
                $status_b = get_post_meta($b->ID, 'invoice_status', true);

                $order_a = $this->invoice_status_order[$status_a] ?? 99;
                $order_b = $this->invoice_status_order[$status_b] ?? 99;

                if ($order_a !== $order_b) {
                    return $order_a - $order_b;
                }

                $due_a = get_post_meta($a->ID, 'due_date', true);
                $due_b = get_post_meta($b->ID, 'due_date', true);

                return strtotime($due_a ?: '9999-12-31') - strtotime($due_b ?: '9999-12-31');
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    public function getPendingInvoiceCount(): int {
        $cache_key = 'workbench_pending_invoices_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'invoice_status',
                        'value' => ['Paid', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get pending client tasks.
     */
    public function getPendingClientTasks(int $limit = 10): array {
        $cache_key = 'workbench_pending_tasks_' . $limit;

        return Cache::remember($cache_key, function() use ($limit) {
            $results = get_posts([
                'post_type' => 'client_task',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'task_status',
                        'value' => 'Pending',
                        'compare' => '=',
                    ],
                ],
            ]);

            usort($results, function($a, $b) {
                $due_a = get_post_meta($a->ID, 'due_date', true);
                $due_b = get_post_meta($b->ID, 'due_date', true);

                if (empty($due_a) && empty($due_b)) {
                    return strtotime($b->post_date) - strtotime($a->post_date);
                }

                if (empty($due_a)) return 1;
                if (empty($due_b)) return -1;

                return strtotime($due_a) - strtotime($due_b);
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    public function getPendingClientTaskCount(): int {
        $cache_key = 'workbench_pending_tasks_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'client_task',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'task_status',
                        'value' => 'Pending',
                        'compare' => '=',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get active roadmap items.
     */
    public function getActiveRoadmapItems(int $limit = 10): array {
        $cache_key = 'workbench_active_roadmap_' . $limit;

        $priority_order = [
            'High' => 1,
            'Medium' => 2,
            'Low' => 3,
        ];

        $status_order = [
            'Proposed' => 1,
            'ADR In Progress' => 2,
            'Idea' => 3,
        ];

        return Cache::remember($cache_key, function() use ($limit, $priority_order, $status_order) {
            $results = get_posts([
                'post_type' => 'roadmap_item',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'roadmap_status',
                        'value' => ['Idea', 'ADR In Progress', 'Proposed'],
                        'compare' => 'IN',
                    ],
                ],
            ]);

            usort($results, function($a, $b) use ($status_order, $priority_order) {
                $status_a = get_post_meta($a->ID, 'roadmap_status', true);
                $status_b = get_post_meta($b->ID, 'roadmap_status', true);

                $s_order_a = $status_order[$status_a] ?? 99;
                $s_order_b = $status_order[$status_b] ?? 99;

                if ($s_order_a !== $s_order_b) {
                    return $s_order_a - $s_order_b;
                }

                $prio_a = get_post_meta($a->ID, 'priority', true);
                $prio_b = get_post_meta($b->ID, 'priority', true);

                $p_order_a = $priority_order[$prio_a] ?? 99;
                $p_order_b = $priority_order[$prio_b] ?? 99;

                if ($p_order_a !== $p_order_b) {
                    return $p_order_a - $p_order_b;
                }

                return strtotime($b->post_date) - strtotime($a->post_date);
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    public function getActiveRoadmapItemCount(): int {
        $cache_key = 'workbench_active_roadmap_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'roadmap_item',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'roadmap_status',
                        'value' => ['Idea', 'ADR In Progress', 'Proposed'],
                        'compare' => 'IN',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get organization shortcode for a post.
     */
    public function getOrgShortcode(int $post_id): string {
        $org_id = get_post_meta($post_id, 'organization', true);

        if (empty($org_id)) {
            return '';
        }

        if (is_array($org_id)) {
            $org_id = reset($org_id);
        }

        $shortcode = get_post_meta($org_id, 'organization_shortcode', true)
            ?: get_post_meta($org_id, 'shortcode', true);

        return $shortcode ?: '';
    }

    /**
     * Get task organization shortcode (uses wp_podsrel for Advanced Relationship).
     */
    public function getTaskOrgShortcode(int $task_id): string {
        global $wpdb;

        $org_id = $wpdb->get_var($wpdb->prepare(
            "SELECT related_item_id FROM {$wpdb->prefix}podsrel
             WHERE item_id = %d AND field_id = 1320",
            $task_id
        ));

        if (empty($org_id)) {
            return '';
        }

        $shortcode = get_post_meta($org_id, 'organization_shortcode', true)
            ?: get_post_meta($org_id, 'shortcode', true);

        return $shortcode ?: '';
    }

    /**
     * Get time entry count for a service request.
     */
    public function getSrTimeEntryCount(int $sr_id): int {
        $cache_key = 'sr_te_count_' . $sr_id;

        return Cache::remember($cache_key, function() use ($sr_id) {
            $entries = get_posts([
                'post_type' => 'time_entry',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'related_service_request',
                        'value' => $sr_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            return count($entries);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get time entry count for a project.
     */
    public function getProjectTimeEntryCount(int $project_id): int {
        $cache_key = 'project_te_count_' . $project_id;

        return Cache::remember($cache_key, function() use ($project_id) {
            $direct_entries = get_posts([
                'post_type' => 'time_entry',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'related_project',
                        'value' => $project_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            $milestones = $this->getProjectMilestones($project_id);
            $milestone_entries = [];

            foreach ($milestones as $milestone_id) {
                $ms_entries = get_posts([
                    'post_type' => 'time_entry',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'meta_query' => [
                        [
                            'key' => 'related_milestone',
                            'value' => $milestone_id,
                            'compare' => '=',
                        ],
                    ],
                ]);
                $milestone_entries = array_merge($milestone_entries, $ms_entries);
            }

            $all_entries = array_unique(array_merge($direct_entries, $milestone_entries));
            return count($all_entries);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get milestone count for a project.
     */
    public function getProjectMilestoneCount(int $project_id): int {
        return count($this->getProjectMilestones($project_id));
    }

    /**
     * Get milestone IDs for a project.
     */
    public function getProjectMilestones(int $project_id): array {
        $cache_key = 'project_milestones_' . $project_id;

        return Cache::remember($cache_key, function() use ($project_id) {
            return get_posts([
                'post_type' => 'milestone',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'related_project',
                        'value' => $project_id,
                        'compare' => '=',
                    ],
                ],
            ]);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Render a status badge.
     */
    public function renderStatusBadge(string $status, string $type = ''): string {
        if (empty($status)) {
            return '';
        }

        $css_class = 'status-' . sanitize_title($status);

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr($css_class),
            esc_html($status)
        );
    }

    /**
     * Format currency.
     */
    public function formatCurrency(float $amount): string {
        return '$' . number_format($amount, 2);
    }

    /**
     * Get edit link for a post.
     */
    public function getEditLink(int $post_id): string {
        return get_edit_post_link($post_id, 'raw') ?: '';
    }

    /**
     * Get filtered admin list URL for time entries by SR.
     */
    public function getTimeEntriesBySrUrl(int $sr_id): string {
        return add_query_arg([
            'post_type' => 'time_entry',
            'bbab_sr_id' => absint($sr_id),
        ], admin_url('edit.php'));
    }

    /**
     * Get filtered admin list URL for time entries by project.
     */
    public function getTimeEntriesByProjectUrl(int $project_id): string {
        return add_query_arg([
            'post_type' => 'time_entry',
            'bbab_project_id' => absint($project_id),
        ], admin_url('edit.php'));
    }

    /**
     * Get filtered admin list URL for milestones by project.
     */
    public function getMilestonesByProjectUrl(int $project_id): string {
        return add_query_arg([
            'post_type' => 'milestone',
            'bbab_project_id' => absint($project_id),
        ], admin_url('edit.php'));
    }
}
