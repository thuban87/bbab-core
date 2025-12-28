<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Generates unique reference numbers for Projects.
 * Format: PR-0001
 *
 * Migrated from: WPCode Snippet #2320
 */
class ProjectReferenceGenerator {

    private const PREFIX = 'PR-';
    private const META_KEY = 'reference_number';

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('pods_api_post_save_pod_item_project', [self::class, 'maybeGenerate'], 10, 3);

        Logger::debug('ProjectReferenceGenerator', 'Registered project reference hooks');
    }

    /**
     * Generate reference number if not already set.
     *
     * @param mixed $pieces     Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param int   $id         Post ID.
     */
    public static function maybeGenerate($pieces, $is_new_item, int $id): void {
        $current_ref = get_post_meta($id, self::META_KEY, true);

        if (!empty($current_ref)) {
            return;
        }

        $new_ref = self::generate();
        update_post_meta($id, self::META_KEY, $new_ref);

        Logger::debug('ProjectReferenceGenerator', "Generated {$new_ref} for project {$id}");
    }

    /**
     * Generate the next available reference number.
     *
     * @return string Reference number (e.g., PR-0001).
     */
    public static function generate(): string {
        global $wpdb;

        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s
             AND meta_value LIKE %s
             ORDER BY CAST(SUBSTRING(meta_value, 4) AS UNSIGNED) DESC
             LIMIT 1",
            self::META_KEY,
            self::PREFIX . '%'
        ));

        $num = $last ? (int) substr($last, strlen(self::PREFIX)) + 1 : 1;

        return self::PREFIX . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the next reference number without saving it.
     * Useful for previewing what the next number would be.
     *
     * @return string Next reference number.
     */
    public static function getNextReference(): string {
        return self::generate();
    }
}
