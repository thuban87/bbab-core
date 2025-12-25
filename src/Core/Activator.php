<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Core;

/**
 * Fired during plugin activation.
 */
class Activator {

    /**
     * Activate the plugin.
     *
     * - Check PHP version requirements
     * - Check for required plugins (Pods, WPForms)
     * - Set up default options
     * - Schedule cron jobs
     */
    public static function activate(): void {
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            deactivate_plugins(BBAB_SC_BASENAME);
            wp_die(
                'BBAB Service Center requires PHP 8.0 or higher. You are running PHP ' . PHP_VERSION,
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }

        // Check for Pods
        if (!function_exists('pods')) {
            deactivate_plugins(BBAB_SC_BASENAME);
            wp_die(
                'BBAB Service Center requires the Pods plugin to be installed and active.',
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }

        // Initialize default settings if not set
        if (!get_option('bbab_sc_settings')) {
            update_option('bbab_sc_settings', [
                'debug_mode' => false,
                'simulation_enabled' => true,
            ]);
        }

        // Schedule cron jobs
        self::scheduleCronJobs();

        // Log activation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BBAB-SC] Plugin activated successfully');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Schedule cron jobs.
     */
    private static function scheduleCronJobs(): void {
        // Analytics cron - runs twice daily
        if (!wp_next_scheduled('bbab_sc_analytics_cron')) {
            wp_schedule_event(time(), 'twicedaily', 'bbab_sc_analytics_cron');
        }

        // Hosting health cron - runs daily at 4am
        if (!wp_next_scheduled('bbab_sc_hosting_cron')) {
            // Calculate next 4am
            $next_4am = strtotime('tomorrow 4:00am');
            wp_schedule_event($next_4am, 'daily', 'bbab_sc_hosting_cron');
        }

        // Cleanup cron - runs weekly
        if (!wp_next_scheduled('bbab_sc_cleanup_cron')) {
            wp_schedule_event(time(), 'weekly', 'bbab_sc_cleanup_cron');
        }
    }
}
