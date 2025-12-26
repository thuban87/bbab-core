<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * Centralized cache management using WordPress transients.
 *
 * Benefits:
 * - Consistent key prefixing (bbab_sc_*)
 * - Easy "Clear All" functionality for admin toolbar
 * - Automatic logging of cache operations in debug mode
 * - Callback pattern for lazy loading
 */
class Cache {

    private const PREFIX = 'bbab_sc_';
    private const DEFAULT_EXPIRY = 3600; // 1 hour

    /**
     * Get a cached value, or generate it using callback if not cached.
     *
     * Usage:
     *   $data = Cache::remember('project_stats_' . $org_id, function() use ($org_id) {
     *       return expensive_query($org_id);
     *   }, 1800);
     *
     * @param string   $key      Cache key
     * @param callable $callback Function to generate value if not cached
     * @param int      $expiry   Cache expiry in seconds
     * @return mixed
     */
    public static function remember(string $key, callable $callback, int $expiry = self::DEFAULT_EXPIRY) {
        $cached = self::get($key);

        if ($cached !== null) {
            Logger::debug('Cache', 'HIT: ' . $key);
            return $cached;
        }

        Logger::debug('Cache', 'MISS: ' . $key . ' - generating...');

        $value = $callback();
        self::set($key, $value, $expiry);

        return $value;
    }

    /**
     * Get a cached value.
     * Returns null if not found (distinguishes from cached false/0).
     *
     * @param string $key Cache key
     * @return mixed|null
     */
    public static function get(string $key) {
        $full_key = self::PREFIX . $key;
        $value = get_transient($full_key);

        // Transients return false on miss, but we might cache false
        // So we wrap values in an array
        if ($value === false) {
            return null;
        }

        return $value['data'] ?? null;
    }

    /**
     * Set a cached value.
     *
     * @param string $key    Cache key
     * @param mixed  $value  Value to cache
     * @param int    $expiry Expiry in seconds
     */
    public static function set(string $key, $value, int $expiry = self::DEFAULT_EXPIRY): bool {
        $full_key = self::PREFIX . $key;

        // Wrap in array to distinguish cached false from miss
        $wrapped = ['data' => $value, 'cached_at' => time()];

        $result = set_transient($full_key, $wrapped, $expiry);

        Logger::debug('Cache', 'SET: ' . $key . ' (expiry: ' . $expiry . 's)');

        return $result;
    }

    /**
     * Delete a cached value.
     */
    public static function delete(string $key): bool {
        $full_key = self::PREFIX . $key;
        Logger::debug('Cache', 'DELETE: ' . $key);
        return delete_transient($full_key);
    }

    /**
     * Delete all plugin transients.
     * Useful for "Clear Cache" admin button.
     */
    public static function flush(): int {
        global $wpdb;

        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . self::PREFIX . '%',
                '_transient_timeout_' . self::PREFIX . '%'
            )
        );

        Logger::debug('Cache', 'FLUSH: Cleared ' . $count . ' transients');

        return (int) $count;
    }

    /**
     * Delete transients matching a pattern.
     * Example: Cache::flushPattern('project_stats_') clears all project stats
     */
    public static function flushPattern(string $pattern): int {
        global $wpdb;

        $full_pattern = self::PREFIX . $pattern;

        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . $full_pattern . '%',
                '_transient_timeout_' . $full_pattern . '%'
            )
        );

        Logger::debug('Cache', 'FLUSH PATTERN: ' . $pattern . ' - Cleared ' . $count . ' transients');

        return (int) $count;
    }

    /**
     * Get cache key for org-specific data.
     * Helper to ensure consistent key formatting.
     */
    public static function orgKey(string $type, int $org_id): string {
        return $type . '_org_' . $org_id;
    }

    /**
     * Check if a cache key exists.
     */
    public static function has(string $key): bool {
        return self::get($key) !== null;
    }

    /**
     * Register cache invalidation hooks.
     *
     * Call this once during plugin initialization to automatically clear
     * relevant caches when posts are saved or deleted.
     */
    public static function registerInvalidationHooks(): void {
        // Service Request hooks
        add_action('save_post_service_request', [self::class, 'invalidateServiceRequestCache']);

        // Project hooks
        add_action('save_post_project', [self::class, 'invalidateProjectCache']);

        // Invoice hooks
        add_action('save_post_invoice', [self::class, 'invalidateInvoiceCache']);

        // Milestone hooks
        add_action('save_post_milestone', [self::class, 'invalidateMilestoneCache']);

        // Time Entry hooks
        add_action('save_post_time_entry', [self::class, 'invalidateTimeEntryCache']);

        // Delete hook (handles all post types)
        add_action('delete_post', [self::class, 'invalidateOnDelete'], 10, 2);

        Logger::debug('Cache', 'Invalidation hooks registered');
    }

    /**
     * Invalidate service request related caches.
     */
    public static function invalidateServiceRequestCache(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        self::flushPattern('open_srs');
        self::flushPattern('sr_');
        self::flushPattern('workbench_open_srs');
        self::flushPattern('requests_summary');

        Logger::debug('Cache', 'Invalidated SR cache for post ' . $post_id);
    }

    /**
     * Invalidate project related caches.
     */
    public static function invalidateProjectCache(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        self::flushPattern('active_projects');
        self::flushPattern('project_');
        self::flushPattern('workbench_active_projects');

        Logger::debug('Cache', 'Invalidated project cache for post ' . $post_id);
    }

    /**
     * Invalidate invoice related caches.
     */
    public static function invalidateInvoiceCache(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        self::flushPattern('pending_invoices');
        self::flushPattern('invoice_');
        self::flushPattern('workbench_pending_invoices');

        Logger::debug('Cache', 'Invalidated invoice cache for post ' . $post_id);
    }

    /**
     * Invalidate milestone related caches.
     */
    public static function invalidateMilestoneCache(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Milestones affect project data too
        self::flushPattern('active_projects');
        self::flushPattern('milestone_');
        self::flushPattern('project_');
        self::flushPattern('workbench_active_projects');

        Logger::debug('Cache', 'Invalidated milestone cache for post ' . $post_id);
    }

    /**
     * Invalidate time entry related caches.
     */
    public static function invalidateTimeEntryCache(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Time entries affect SRs, projects, and milestones
        self::flushPattern('open_srs');
        self::flushPattern('active_projects');
        self::flushPattern('te_');
        self::flushPattern('sr_');
        self::flushPattern('project_');
        self::flushPattern('milestone_');
        self::flushPattern('workbench_');
        self::flushPattern('requests_summary');

        Logger::debug('Cache', 'Invalidated time entry cache for post ' . $post_id);
    }

    /**
     * Handle cache invalidation on post deletion.
     *
     * @param int           $post_id Post ID
     * @param \WP_Post|null $post    Post object (may be null in some contexts)
     */
    public static function invalidateOnDelete(int $post_id, $post = null): void {
        if (!$post) {
            $post = get_post($post_id);
        }

        if (!$post) {
            return;
        }

        switch ($post->post_type) {
            case 'service_request':
                self::invalidateServiceRequestCache($post_id);
                break;
            case 'project':
                self::invalidateProjectCache($post_id);
                break;
            case 'invoice':
                self::invalidateInvoiceCache($post_id);
                break;
            case 'milestone':
                self::invalidateMilestoneCache($post_id);
                break;
            case 'time_entry':
                self::invalidateTimeEntryCache($post_id);
                break;
        }
    }
}
