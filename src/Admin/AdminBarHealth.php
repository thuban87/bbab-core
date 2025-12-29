<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Modules\Hosting\BackupService;
use BBAB\ServiceCenter\Modules\Hosting\UptimeService;
use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Admin Bar Health Indicator.
 *
 * Displays a visual indicator in the WordPress admin bar when any
 * client has critical hosting health issues (backup failures, downtime).
 *
 * This provides "at a glance" visibility without needing to visit the dashboard.
 */
class AdminBarHealth {

    /**
     * Cache key for aggregated health status.
     */
    private const CACHE_KEY = 'admin_bar_health_status';

    /**
     * Cache duration in seconds (5 minutes).
     */
    private const CACHE_SECONDS = 300;

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Hook registration - capability check happens in callbacks
        add_action('admin_bar_menu', [self::class, 'addHealthIndicator'], 100);
        add_action('admin_head', [self::class, 'renderStyles']);
        add_action('wp_head', [self::class, 'renderStyles']);

        Logger::debug('AdminBarHealth', 'Registered admin bar health indicator');
    }

    /**
     * Add health indicator to admin bar.
     *
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
     */
    public static function addHealthIndicator(\WP_Admin_Bar $wp_admin_bar): void {
        // Only for admins
        if (!current_user_can('manage_options')) {
            return;
        }

        $health = self::getHealthStatus();

        $title = self::buildTitle($health);
        $class = self::getStatusClass($health);

        $wp_admin_bar->add_node([
            'id' => 'bbab-health-indicator',
            'title' => $title,
            'href' => admin_url('tools.php?page=client-health-dashboard'),
            'meta' => [
                'class' => 'bbab-health-node ' . $class,
                'title' => self::buildTooltip($health),
            ],
        ]);

        // Add sub-items for specific issues
        if (!empty($health['backup_issues'])) {
            $wp_admin_bar->add_node([
                'id' => 'bbab-health-backups',
                'parent' => 'bbab-health-indicator',
                'title' => count($health['backup_issues']) . ' backup issue(s)',
                'href' => admin_url('tools.php?page=client-health-dashboard'),
            ]);
        }

        if (!empty($health['uptime_issues'])) {
            $wp_admin_bar->add_node([
                'id' => 'bbab-health-uptime',
                'parent' => 'bbab-health-indicator',
                'title' => count($health['uptime_issues']) . ' uptime issue(s)',
                'href' => admin_url('tools.php?page=client-health-dashboard'),
            ]);
        }
    }

    /**
     * Get aggregated health status for all orgs.
     *
     * @return array Health status data.
     */
    public static function getHealthStatus(): array {
        // Check cache first
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        $status = [
            'total_issues' => 0,
            'backup_issues' => [],
            'uptime_issues' => [],
            'has_data' => false,
            'checked_at' => time(),
        ];

        // Get all client organizations
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($orgs as $org) {
            // Check backup status
            $backup = BackupService::getData($org->ID);
            if ($backup !== null) {
                $status['has_data'] = true;

                // Flag if backup is older than 48 hours
                $age_hours = $backup['age_hours'] ?? 0;
                if ($age_hours > 48) {
                    $status['backup_issues'][] = [
                        'org_id' => $org->ID,
                        'org_name' => $org->post_title,
                        'age_hours' => $age_hours,
                    ];
                }
            }

            // Check uptime status
            $uptime = UptimeService::getData($org->ID);
            if ($uptime !== null) {
                $status['has_data'] = true;

                // Status 2 = Up, anything else is a problem
                // Also flag if uptime percentage is below 99%
                $monitor_status = $uptime['status'] ?? 0;
                $uptime_ratio = $uptime['uptime_ratio'] ?? 100;

                if ($monitor_status !== 2 || $uptime_ratio < 99.0) {
                    $status['uptime_issues'][] = [
                        'org_id' => $org->ID,
                        'org_name' => $org->post_title,
                        'status' => $monitor_status,
                        'status_label' => $uptime['status_label'] ?? 'Unknown',
                        'uptime_ratio' => $uptime_ratio,
                    ];
                }
            }
        }

        $status['total_issues'] = count($status['backup_issues']) + count($status['uptime_issues']);

        // Cache for 5 minutes
        Cache::set(self::CACHE_KEY, $status, self::CACHE_SECONDS);

        return $status;
    }

    /**
     * Build the admin bar title.
     *
     * @param array $health Health status data.
     * @return string HTML title.
     */
    private static function buildTitle(array $health): string {
        // No data yet
        if (!$health['has_data']) {
            return '<span class="ab-icon dashicons dashicons-heart"></span>';
        }

        // Has data, no issues
        if ($health['total_issues'] === 0) {
            return '<span class="ab-icon dashicons dashicons-heart"></span>';
        }

        // Has issues
        return sprintf(
            '<span class="ab-icon dashicons dashicons-warning"></span><span class="ab-label">%d</span>',
            $health['total_issues']
        );
    }

    /**
     * Build tooltip text.
     *
     * @param array $health Health status data.
     * @return string Tooltip text.
     */
    private static function buildTooltip(array $health): string {
        if (!$health['has_data']) {
            return 'Client Health: No data yet (cron pending)';
        }

        if ($health['total_issues'] === 0) {
            return 'All clients healthy';
        }

        $parts = [];

        if (!empty($health['backup_issues'])) {
            $parts[] = count($health['backup_issues']) . ' backup issue(s)';
        }

        if (!empty($health['uptime_issues'])) {
            $parts[] = count($health['uptime_issues']) . ' uptime issue(s)';
        }

        return 'Client Health: ' . implode(', ', $parts);
    }

    /**
     * Get CSS class based on health status.
     *
     * @param array $health Health status data.
     * @return string CSS class.
     */
    private static function getStatusClass(array $health): string {
        // No data yet - neutral/gray
        if (!$health['has_data']) {
            return 'health-nodata';
        }

        if ($health['total_issues'] === 0) {
            return 'health-ok';
        }

        // Critical if any uptime issues (site down)
        if (!empty($health['uptime_issues'])) {
            return 'health-critical';
        }

        // Warning for backup issues
        return 'health-warning';
    }

    /**
     * Render admin bar styles.
     */
    public static function renderStyles(): void {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        ?>
        <style>
            #wpadminbar .bbab-health-node .ab-icon:before {
                top: 3px;
            }
            #wpadminbar .bbab-health-node.health-nodata .ab-icon:before {
                color: #999;
            }
            #wpadminbar .bbab-health-node.health-ok .ab-icon:before {
                color: #46b450;
            }
            #wpadminbar .bbab-health-node.health-warning .ab-icon:before {
                color: #ffb900;
            }
            #wpadminbar .bbab-health-node.health-critical .ab-icon:before {
                color: #dc3232;
            }
            #wpadminbar .bbab-health-node.health-critical {
                background: rgba(220, 50, 50, 0.15) !important;
            }
            #wpadminbar .bbab-health-node .ab-label {
                display: inline-block;
                background: #dc3232;
                color: #fff;
                min-width: 16px;
                height: 16px;
                line-height: 16px;
                padding: 0 4px;
                border-radius: 8px;
                font-size: 9px;
                font-weight: 600;
                text-align: center;
                margin-left: 2px;
                vertical-align: middle;
            }
            #wpadminbar .bbab-health-node.health-warning .ab-label {
                background: #ffb900;
                color: #23282d;
            }
        </style>
        <?php
    }

    /**
     * Clear cached health status.
     * Call this when hosting data is updated.
     */
    public static function clearCache(): void {
        Cache::delete(self::CACHE_KEY);
    }
}
