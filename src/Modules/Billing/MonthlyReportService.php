<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Monthly Report business logic service.
 *
 * Handles:
 * - Time entry queries for reports (via org + date range)
 * - Hours calculations (billable, all, overage)
 * - Free hours progress tracking
 * - Overage charge calculations
 *
 * Migrated from snippets: 1062, 1065, 1066
 */
class MonthlyReportService {

    /**
     * Round hours up to nearest 15-minute (quarter-hour) increment.
     *
     * @param float $hours Raw hours value.
     * @return float Rounded hours.
     */
    public static function roundToQuarterHour(float $hours): float {
        $minutes = $hours * 60;
        $rounded_minutes = ceil($minutes / 15) * 15;
        return $rounded_minutes / 60;
    }

    /**
     * Get time entries for a Monthly Report based on org + date range.
     *
     * This queries via Service Requests for the org within the report's month,
     * NOT via a direct related_report field.
     *
     * @param int $report_id Monthly Report post ID.
     * @return array Array of time entry post objects.
     */
    public static function getTimeEntries(int $report_id): array {
        if (empty($report_id)) {
            return [];
        }

        // Get report's organization and month
        $org_id = get_post_meta($report_id, 'organization', true);
        $report_month = get_post_meta($report_id, 'report_month', true); // e.g., "November 2025"

        if (empty($org_id) || empty($report_month)) {
            return [];
        }

        // Parse report month to get date range
        $month_timestamp = strtotime("1 " . $report_month);
        if ($month_timestamp === false) {
            Logger::debug('MonthlyReportService', 'Failed to parse report month', [
                'report_id' => $report_id,
                'report_month' => $report_month,
            ]);
            return [];
        }

        $month_start = date('Y-m-d', $month_timestamp);
        $month_end = date('Y-m-t', $month_timestamp); // Last day of month

        // Get all Service Requests for this organization
        $srs = get_posts([
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
            'fields' => 'ids',
        ]);

        if (empty($srs)) {
            return [];
        }

        // Get all time entries for these SRs within the date range
        $entries = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'related_service_request',
                    'value' => $srs,
                    'compare' => 'IN',
                ],
                [
                    'key' => 'entry_date',
                    'value' => [$month_start, $month_end],
                    'compare' => 'BETWEEN',
                    'type' => 'DATE',
                ],
            ],
            'orderby' => 'meta_value',
            'meta_key' => 'entry_date',
            'order' => 'ASC',
        ]);

        return $entries;
    }

    /**
     * Calculate total BILLABLE hours for a Monthly Report.
     *
     * @param int $report_id Monthly Report post ID.
     * @return float Total billable hours (quarter-hour rounded).
     */
    public static function getTotalHours(int $report_id): float {
        $entries = self::getTimeEntries($report_id);
        $total_hours = 0.0;

        foreach ($entries as $entry) {
            // Skip non-billable entries
            $billable = get_post_meta($entry->ID, 'billable', true);
            if ($billable === '0' || $billable === 0 || $billable === false) {
                continue;
            }

            $hours = get_post_meta($entry->ID, 'hours', true);
            if (is_numeric($hours)) {
                $total_hours += self::roundToQuarterHour((float) $hours);
            }
        }

        return round($total_hours, 2);
    }

    /**
     * Calculate total ALL hours (including non-billable) for a Monthly Report.
     *
     * @param int $report_id Monthly Report post ID.
     * @return float Total hours (quarter-hour rounded).
     */
    public static function getAllHours(int $report_id): float {
        $entries = self::getTimeEntries($report_id);
        $total_hours = 0.0;

        foreach ($entries as $entry) {
            $hours = get_post_meta($entry->ID, 'hours', true);
            if (is_numeric($hours)) {
                $total_hours += self::roundToQuarterHour((float) $hours);
            }
        }

        return round($total_hours, 2);
    }

    /**
     * Get the free hours limit for a report.
     *
     * Falls back to report's stored limit, then org's limit, then default setting.
     *
     * @param int $report_id Monthly Report post ID.
     * @return float Free hours limit.
     */
    public static function getFreeHoursLimit(int $report_id): float {
        // Check report's own limit first
        $limit = get_post_meta($report_id, 'free_hours_limit', true);

        if (!empty($limit) && is_numeric($limit)) {
            return (float) $limit;
        }

        // Fall back to org's free_hours setting
        $org_id = get_post_meta($report_id, 'organization', true);
        if (!empty($org_id)) {
            $org_limit = get_post_meta($org_id, 'free_hours', true);
            if (!empty($org_limit) && is_numeric($org_limit)) {
                return (float) $org_limit;
            }
        }

        // Fall back to default setting
        return (float) Settings::get('default_free_hours', 2.0);
    }

    /**
     * Get free hours progress data.
     *
     * @param int $report_id Monthly Report post ID.
     * @return array Array with 'used', 'limit', 'percent', 'remaining' keys.
     */
    public static function getFreeHoursProgress(int $report_id): array {
        $used = self::getTotalHours($report_id);
        $limit = self::getFreeHoursLimit($report_id);

        $percent = 0;
        if ($limit > 0) {
            $percent = ($used / $limit) * 100;
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'percent' => min(round($percent), 100), // Cap at 100 for display
            'percent_raw' => round($percent, 1),    // Actual percentage (can exceed 100)
            'remaining' => max(0, $limit - $used),
        ];
    }

    /**
     * Get color class based on usage percentage.
     *
     * @param int $report_id Monthly Report post ID.
     * @return string Color name: 'blue', 'yellow', 'orange', or 'red'.
     */
    public static function getProgressColor(int $report_id): string {
        $progress = self::getFreeHoursProgress($report_id);
        $percent = $progress['percent_raw'];

        if ($percent >= 100) {
            return 'red';
        } elseif ($percent >= 81) {
            return 'orange';
        } elseif ($percent >= 51) {
            return 'yellow';
        }

        return 'blue';
    }

    /**
     * Check if report has overage hours.
     *
     * @param int $report_id Monthly Report post ID.
     * @return bool True if hours exceed free limit.
     */
    public static function hasOverage(int $report_id): bool {
        $used = self::getTotalHours($report_id);
        $limit = self::getFreeHoursLimit($report_id);

        return $used > $limit;
    }

    /**
     * Get overage hours (hours beyond free limit).
     *
     * @param int $report_id Monthly Report post ID.
     * @return float Overage hours (0 if none).
     */
    public static function getOverageHours(int $report_id): float {
        $used = self::getTotalHours($report_id);
        $limit = self::getFreeHoursLimit($report_id);

        return max(0, round($used - $limit, 2));
    }

    /**
     * Get overage amount (cost of overage hours).
     *
     * @param int   $report_id Monthly Report post ID.
     * @param float $rate      Optional hourly rate override.
     * @return float Overage amount in dollars.
     */
    public static function getOverageAmount(int $report_id, ?float $rate = null): float {
        if ($rate === null) {
            $rate = (float) Settings::get('hourly_rate', 30.00);
        }

        $overage_hours = self::getOverageHours($report_id);
        return round($overage_hours * $rate, 2);
    }

    /**
     * Get the hourly rate for billing.
     *
     * @return float Hourly rate from settings.
     */
    public static function getHourlyRate(): float {
        return (float) Settings::get('hourly_rate', 30.00);
    }

    /**
     * Get report month string.
     *
     * @param int $report_id Monthly Report post ID.
     * @return string Report month (e.g., "December 2024").
     */
    public static function getReportMonth(int $report_id): string {
        return get_post_meta($report_id, 'report_month', true) ?: '';
    }

    /**
     * Get organization for a report.
     *
     * @param int $report_id Monthly Report post ID.
     * @return \WP_Post|null Organization post or null.
     */
    public static function getOrganization(int $report_id): ?\WP_Post {
        $org_id = get_post_meta($report_id, 'organization', true);

        if (empty($org_id)) {
            return null;
        }

        return get_post((int) $org_id);
    }

    /**
     * Get organization ID for a report.
     *
     * @param int $report_id Monthly Report post ID.
     * @return int Organization post ID or 0.
     */
    public static function getOrganizationId(int $report_id): int {
        $org_id = get_post_meta($report_id, 'organization', true);
        return !empty($org_id) ? (int) $org_id : 0;
    }
}
