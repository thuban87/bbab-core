<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Generates unique reference numbers for Milestones.
 * Format: PR-0001-01 (parent project ref + milestone order)
 *
 * Supports decimal orders (e.g., milestone_order=1.5 → PR-0001-01.5)
 *
 * Migrated from: WPCode Snippet #2321
 */
class MilestoneReferenceGenerator {

    private const META_KEY = 'reference_number';

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('pods_api_post_save_pod_item_milestone', [self::class, 'maybeGenerate'], 10, 3);

        Logger::debug('MilestoneReferenceGenerator', 'Registered milestone reference hooks');
    }

    /**
     * Generate reference number if not already set.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param int   $id          Post ID.
     */
    public static function maybeGenerate($pieces, $is_new_item, int $id): void {
        $current_ref = get_post_meta($id, self::META_KEY, true);

        if (!empty($current_ref)) {
            return;
        }

        // Get parent project
        $project_id = get_post_meta($id, 'related_project', true);

        // Handle Pods relationship field returning array
        if (is_array($project_id)) {
            $project_id = reset($project_id);
        }

        if (empty($project_id)) {
            Logger::warning('MilestoneReferenceGenerator', "No parent project for milestone {$id}, skipping ref generation");
            return;
        }

        // Get parent project's reference number
        $project_ref = get_post_meta($project_id, self::META_KEY, true);

        if (empty($project_ref)) {
            Logger::warning('MilestoneReferenceGenerator', "Parent project {$project_id} has no reference number, skipping");
            return;
        }

        // Get milestone order
        $milestone_order = get_post_meta($id, 'milestone_order', true);

        if (empty($milestone_order)) {
            Logger::warning('MilestoneReferenceGenerator', "No milestone_order for milestone {$id}, skipping ref generation");
            return;
        }

        $new_ref = self::generate($project_ref, $milestone_order);
        update_post_meta($id, self::META_KEY, $new_ref);

        Logger::debug('MilestoneReferenceGenerator', "Generated {$new_ref} for milestone {$id}");
    }

    /**
     * Generate a milestone reference number from project ref and order.
     *
     * @param string     $project_ref     Parent project reference (e.g., PR-0001).
     * @param string|int $milestone_order Milestone order (can be decimal like 1.5).
     * @return string Reference number (e.g., PR-0001-01 or PR-0001-01.5).
     */
    public static function generate(string $project_ref, $milestone_order): string {
        $order_float = floatval($milestone_order);
        $whole_part = floor($order_float);
        $decimal_part = $order_float - $whole_part;

        if ($decimal_part > 0) {
            // Has decimal: pad whole number, append decimal portion
            // e.g., 1.5 → "01.5"
            $decimal_str = ltrim((string) $decimal_part, '0'); // ".5" or ".75"
            $order_formatted = str_pad((string) (int) $whole_part, 2, '0', STR_PAD_LEFT) . $decimal_str;
        } else {
            // Whole number only: just pad
            // e.g., 1 → "01"
            $order_formatted = str_pad((string) (int) $whole_part, 2, '0', STR_PAD_LEFT);
        }

        return $project_ref . '-' . $order_formatted;
    }

    /**
     * Generate reference for a milestone by its project ID and order.
     *
     * @param int        $project_id      Project post ID.
     * @param string|int $milestone_order Milestone order.
     * @return string|null Reference number or null if project has no ref.
     */
    public static function generateForProject(int $project_id, $milestone_order): ?string {
        $project_ref = get_post_meta($project_id, self::META_KEY, true);

        if (empty($project_ref)) {
            return null;
        }

        return self::generate($project_ref, $milestone_order);
    }
}
