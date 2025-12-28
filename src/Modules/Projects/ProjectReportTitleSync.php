<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Syncs project report post_title to report_number field.
 *
 * Ensures the WordPress post title matches the report number
 * for consistent display in admin lists and elsewhere.
 *
 * Migrated from: WPCode Snippet #2668 (title sync portion)
 */
class ProjectReportTitleSync {

    /**
     * Flag to prevent infinite loop.
     */
    private static bool $syncing = false;

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Run after ProjectReportReferenceGenerator (priority 20 vs 10)
        // Uses Pods hook so report_number is available
        add_action('pods_api_post_save_pod_item_project_report', [self::class, 'syncTitle'], 20, 3);

        Logger::debug('ProjectReportTitleSync', 'Registered project report title sync hooks');
    }

    /**
     * Sync post_title to report_number field.
     *
     * Uses Pods hook which runs AFTER meta fields and reference number are saved.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param mixed $id          Post ID (may come as string from Pods).
     */
    public static function syncTitle($pieces, $is_new_item, $id): void {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }

        // Prevent infinite loop
        if (self::$syncing) {
            return;
        }

        $post = get_post($id);
        if (!$post) {
            return;
        }

        $report_number = get_post_meta($id, 'report_number', true);

        // Only sync if report_number exists and differs from title
        if (!empty($report_number) && $post->post_title !== $report_number) {
            self::$syncing = true;

            wp_update_post([
                'ID' => $id,
                'post_title' => $report_number,
            ]);

            self::$syncing = false;

            Logger::debug('ProjectReportTitleSync', 'Synced project report title', [
                'post_id' => $id,
                'report_number' => $report_number,
            ]);
        }
    }
}
