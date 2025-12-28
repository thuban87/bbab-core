<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Filters the Related Project dropdown on Project Reports by selected Organization.
 *
 * When the Organization field changes, dynamically updates the Related Project
 * dropdown to only show projects belonging to that organization.
 *
 * Phase 7.3
 */
class ProjectReportFieldFilter {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueueScripts']);
        add_action('wp_ajax_bbab_get_org_projects', [self::class, 'handleGetProjects']);

        Logger::debug('ProjectReportFieldFilter', 'Registered project report field filter hooks');
    }

    /**
     * Enqueue scripts on project_report edit screens.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueueScripts(string $hook): void {
        // Only on post edit screens
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'project_report') {
            return;
        }

        // Inline script to filter projects by org
        add_action('admin_footer', [self::class, 'renderScript']);
    }

    /**
     * Render the filtering JavaScript.
     */
    public static function renderScript(): void {
        $nonce = wp_create_nonce('bbab_get_org_projects');
        ?>
        <script>
        jQuery(document).ready(function($) {
            var $orgField = $('select[name="pods_meta_organization"]');
            var $projectField = $('select[name="pods_meta_related_project"]');

            if (!$orgField.length || !$projectField.length) {
                console.log('BBAB: Org or Project field not found');
                return;
            }

            // Store the original project value
            var originalProjectId = $projectField.val();

            // Function to update project dropdown
            function updateProjectDropdown(orgId, selectedProjectId) {
                if (!orgId) {
                    // No org selected - show placeholder only
                    $projectField.html('<option value="">-- Select Organization First --</option>');
                    $projectField.prop('disabled', true);
                    return;
                }

                $projectField.prop('disabled', true);
                $projectField.html('<option value="">Loading projects...</option>');

                $.post(ajaxurl, {
                    action: 'bbab_get_org_projects',
                    org_id: orgId,
                    nonce: '<?php echo esc_js($nonce); ?>'
                }, function(response) {
                    if (response.success && response.data.projects) {
                        var html = '<option value="">-- Select Project --</option>';
                        $.each(response.data.projects, function(id, name) {
                            var selected = (id == selectedProjectId) ? ' selected' : '';
                            html += '<option value="' + id + '"' + selected + '>' + name + '</option>';
                        });
                        $projectField.html(html);
                        $projectField.prop('disabled', false);
                    } else {
                        $projectField.html('<option value="">No projects found</option>');
                        $projectField.prop('disabled', true);
                    }
                }).fail(function() {
                    $projectField.html('<option value="">Error loading projects</option>');
                    $projectField.prop('disabled', false);
                });
            }

            // Listen for org changes
            $orgField.on('change', function() {
                var orgId = $(this).val();
                // Clear project selection when org changes
                updateProjectDropdown(orgId, null);
            });

            // Initial load - filter to current org if set
            var currentOrg = $orgField.val();
            if (currentOrg) {
                updateProjectDropdown(currentOrg, originalProjectId);
            } else {
                $projectField.html('<option value="">-- Select Organization First --</option>');
                $projectField.prop('disabled', true);
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX handler to get projects for an organization.
     */
    public static function handleGetProjects(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bbab_get_org_projects')) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        // Verify capability
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        $org_id = isset($_POST['org_id']) ? absint($_POST['org_id']) : 0;

        if (!$org_id) {
            wp_send_json_error(['message' => 'No organization specified']);
            return;
        }

        // Get projects for this org
        $projects = get_posts([
            'post_type' => 'project',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $project_list = [];
        foreach ($projects as $project) {
            $ref = get_post_meta($project->ID, 'reference_number', true);
            $name = get_post_meta($project->ID, 'project_name', true) ?: $project->post_title;
            $display = $ref ? $ref . ' - ' . $name : $name;
            $project_list[$project->ID] = $display;
        }

        wp_send_json_success(['projects' => $project_list]);
    }
}
