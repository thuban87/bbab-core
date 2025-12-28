<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Auto-sync post titles for Projects and Milestones on save.
 *
 * Projects → Uses project_name field
 * Milestones → Uses milestone_name field
 *
 * Uses Pods' post-save hook to ensure field values are available.
 *
 * Migrated from: WPCode Snippet #1469
 */
class TitleSync {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('pods_api_post_save_pod_item_project', [self::class, 'syncProjectTitle'], 15, 3);
        add_action('pods_api_post_save_pod_item_milestone', [self::class, 'syncMilestoneTitle'], 15, 3);

        Logger::debug('TitleSync', 'Registered title sync hooks');
    }

    /**
     * Sync project post_title to project_name field.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param int   $id          Post ID.
     */
    public static function syncProjectTitle($pieces, $is_new_item, int $id): void {
        $project_name = get_post_meta($id, 'project_name', true);
        $current_title = get_the_title($id);

        if (!empty($project_name) && $current_title !== $project_name) {
            wp_update_post([
                'ID' => $id,
                'post_title' => $project_name,
            ]);

            Logger::debug('TitleSync', "Synced project {$id} title to: {$project_name}");
        }
    }

    /**
     * Sync milestone post_title to milestone_name field.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param int   $id          Post ID.
     */
    public static function syncMilestoneTitle($pieces, $is_new_item, int $id): void {
        $milestone_name = get_post_meta($id, 'milestone_name', true);

        if (empty($milestone_name)) {
            return;
        }

        $current_title = get_the_title($id);

        if ($current_title !== $milestone_name) {
            wp_update_post([
                'ID' => $id,
                'post_title' => $milestone_name,
            ]);

            Logger::debug('TitleSync', "Synced milestone {$id} title to: {$milestone_name}");
        }
    }
}
