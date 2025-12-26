<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

/**
 * Handles time entry pre-population when creating from SR/Project/Milestone links.
 *
 * Migrated from admin/class-admin.php.
 *
 * Flow:
 * 1. User clicks "Add Time Entry" link with ?related_service_request=123
 * 2. On load-post-new.php, we store the ID in a transient
 * 3. On admin_footer, we output JS to pre-populate the Pods Select2 field
 * 4. On save_post_time_entry, we save the relationship from hidden field/transient
 */
class TimeEntryLinker {

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('load-post-new.php', [$this, 'setTimeEntryLinkTransient']);
        add_action('admin_footer', [$this, 'prepopulatePodsFields']);
        add_action('save_post_time_entry', [$this, 'maybeSetTimeEntryRelationship'], 5, 3);
    }

    /**
     * Set transient when navigating to new time entry with URL params.
     */
    public function setTimeEntryLinkTransient(): void {
        global $typenow;

        if ('time_entry' !== $typenow) {
            return;
        }

        $user_id = get_current_user_id();

        // Check for SR link.
        if (!empty($_GET['related_service_request'])) {
            $sr_id = absint($_GET['related_service_request']);
            if ($sr_id > 0) {
                set_transient('bbab_pending_sr_link_' . $user_id, $sr_id, HOUR_IN_SECONDS);
            }
        }

        // Check for Project link.
        if (!empty($_GET['related_project'])) {
            $project_id = absint($_GET['related_project']);
            if ($project_id > 0) {
                set_transient('bbab_pending_project_link_' . $user_id, $project_id, HOUR_IN_SECONDS);
            }
        }

        // Check for Milestone link.
        if (!empty($_GET['related_milestone'])) {
            $milestone_id = absint($_GET['related_milestone']);
            if ($milestone_id > 0) {
                set_transient('bbab_pending_milestone_link_' . $user_id, $milestone_id, HOUR_IN_SECONDS);
            }
        }
    }

    /**
     * Save time entry relationship from hidden field or transient.
     *
     * Runs early (priority 5) to set meta before Pods validation.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public function maybeSetTimeEntryRelationship(int $post_id, \WP_Post $post, bool $update): void {
        // Only on new posts.
        if ($update) {
            return;
        }

        $user_id = get_current_user_id();

        $sr_id = 0;
        $project_id = 0;
        $milestone_id = 0;

        // Check hidden fields (with nonce verification).
        if (isset($_POST['bbab_prepopulate_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bbab_prepopulate_nonce'])), 'bbab_prepopulate_te')) {
            $sr_id = isset($_POST['bbab_prepopulate_sr']) ? absint($_POST['bbab_prepopulate_sr']) : 0;
            $project_id = isset($_POST['bbab_prepopulate_project']) ? absint($_POST['bbab_prepopulate_project']) : 0;
        }

        // Fall back to transients if hidden fields weren't set.
        if ($sr_id === 0) {
            $sr_id = get_transient('bbab_pending_sr_link_' . $user_id);
            $sr_id = $sr_id ? absint($sr_id) : 0;
        }
        if ($project_id === 0) {
            $project_id = get_transient('bbab_pending_project_link_' . $user_id);
            $project_id = $project_id ? absint($project_id) : 0;
        }
        if ($milestone_id === 0) {
            $milestone_id = get_transient('bbab_pending_milestone_link_' . $user_id);
            $milestone_id = $milestone_id ? absint($milestone_id) : 0;
        }

        // Set SR relationship if provided and not already set.
        if ($sr_id > 0) {
            $existing = get_post_meta($post_id, 'related_service_request', true);
            if (empty($existing)) {
                update_post_meta($post_id, 'related_service_request', $sr_id);
            }
        }

        // Set Project relationship if provided and not already set.
        if ($project_id > 0) {
            $existing = get_post_meta($post_id, 'related_project', true);
            if (empty($existing)) {
                update_post_meta($post_id, 'related_project', $project_id);
            }
        }

        // Set Milestone relationship if provided and not already set.
        if ($milestone_id > 0) {
            $existing = get_post_meta($post_id, 'related_milestone', true);
            if (empty($existing)) {
                update_post_meta($post_id, 'related_milestone', $milestone_id);
            }
        }

        // Clean up transients after use.
        delete_transient('bbab_pending_sr_link_' . $user_id);
        delete_transient('bbab_pending_project_link_' . $user_id);
        delete_transient('bbab_pending_milestone_link_' . $user_id);
    }

    /**
     * Pre-populate Pods relationship fields from URL parameters.
     *
     * Outputs JavaScript to set Select2 field values on the new time entry page.
     */
    public function prepopulatePodsFields(): void {
        global $pagenow, $typenow;

        if ('post-new.php' !== $pagenow || 'time_entry' !== $typenow) {
            return;
        }

        $sr_id = isset($_GET['related_service_request']) ? absint($_GET['related_service_request']) : 0;
        $project_id = isset($_GET['related_project']) ? absint($_GET['related_project']) : 0;

        if (!$sr_id && !$project_id) {
            return;
        }

        // Get post info for the related item.
        if ($sr_id) {
            $post = get_post($sr_id);
            if (!$post || 'service_request' !== $post->post_type) {
                return;
            }
            $field_name = 'related_service_request';
            $post_id = $sr_id;
            $ref_number = get_post_meta($sr_id, 'reference_number', true);
            $subject = get_post_meta($sr_id, 'subject', true) ?: $post->post_title;
            $label = $ref_number . ' - ' . $subject;
        } else {
            $post = get_post($project_id);
            if (!$post || 'project' !== $post->post_type) {
                return;
            }
            $field_name = 'related_project';
            $post_id = $project_id;
            $ref_number = get_post_meta($project_id, 'reference_number', true);
            $project_name = get_post_meta($project_id, 'project_name', true) ?: $post->post_title;
            $label = $ref_number . ' - ' . $project_name;
        }

        $nonce = wp_create_nonce('bbab_prepopulate_te');
        $is_sr = $sr_id > 0;
        ?>
        <script type="text/javascript">
        (function($) {
            var fieldName = <?php echo wp_json_encode($field_name); ?>;
            var postId = <?php echo wp_json_encode((string) $post_id); ?>;
            var label = <?php echo wp_json_encode($label); ?>;
            var isSR = <?php echo wp_json_encode($is_sr); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var populated = false;
            var hiddenFieldsAdded = false;

            function addHiddenFields() {
                if (hiddenFieldsAdded) return;
                var $form = $('form#post');
                if (!$form.length) return;
                if (isSR) {
                    $form.append('<input type="hidden" name="bbab_prepopulate_sr" value="' + postId + '" />');
                } else {
                    $form.append('<input type="hidden" name="bbab_prepopulate_project" value="' + postId + '" />');
                }
                $form.append('<input type="hidden" name="bbab_prepopulate_nonce" value="' + nonce + '" />');
                hiddenFieldsAdded = true;
            }

            function setSelect2Value($select) {
                if (populated) return true;
                if (!$select.hasClass('select2-hidden-accessible')) return false;
                var currentVal = $select.val();
                if (currentVal === postId || (Array.isArray(currentVal) && currentVal.indexOf(postId) !== -1)) {
                    populated = true;
                    return true;
                }
                var newOption = new Option(label, postId, true, true);
                $select.append(newOption);
                $select.val(postId).trigger('change');
                populated = true;
                return true;
            }

            function findSelectField() {
                var selectors = [
                    'select[name="pods_meta_' + fieldName + '"]',
                    'select[name="' + fieldName + '"]',
                    'select[data-name="' + fieldName + '"]',
                    'select#pods-form-ui-' + fieldName.replace(/_/g, '-'),
                    '.pods-field-' + fieldName.replace(/_/g, '-') + ' select'
                ];
                for (var i = 0; i < selectors.length; i++) {
                    var $field = $(selectors[i]);
                    if ($field.length) return $field;
                }
                return null;
            }

            function attemptPrepopulation() {
                if (populated) return true;
                var $select = findSelectField();
                if ($select && $select.length) return setSelect2Value($select);
                return false;
            }

            function setupObserver() {
                var observer = new MutationObserver(function(mutations) {
                    if (populated) { observer.disconnect(); return; }
                    if (attemptPrepopulation()) observer.disconnect();
                });
                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['class']
                });
                setTimeout(function() { observer.disconnect(); }, 10000);
            }

            $(document).ready(function() {
                addHiddenFields();
                if (!attemptPrepopulation()) {
                    setupObserver();
                    var attempts = 0;
                    var interval = setInterval(function() {
                        attempts++;
                        if (attemptPrepopulation() || attempts > 20) clearInterval(interval);
                    }, 250);
                }
            });

            $(window).on('load', function() {
                if (!populated) attemptPrepopulation();
            });
        })(jQuery);
        </script>
        <?php
    }
}
