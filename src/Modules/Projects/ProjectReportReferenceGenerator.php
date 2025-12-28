<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Generates project report reference numbers in RR-YYMM-XXX format.
 *
 * Format: RR-YYMM-XXX
 * - RR: Report prefix
 * - YYMM: Year and month (e.g., 2412 for December 2024)
 * - XXX: Sequential number within that month (001, 002, etc.)
 *
 * Example: RR-2412-001, RR-2412-002
 *
 * Migrated from: WPCode Snippet #2668
 */
class ProjectReportReferenceGenerator {

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Generate reference on Pods save (runs after meta is saved)
        add_action('pods_api_post_save_pod_item_project_report', [self::class, 'maybeGenerateReference'], 10, 3);

        Logger::debug('ProjectReportReferenceGenerator', 'Registered project report reference hooks');
    }

    /**
     * Generate reference number if not already set.
     *
     * Uses Pods hook which runs AFTER meta fields are saved.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param mixed $id          Post ID (may come as string from Pods).
     */
    public static function maybeGenerateReference($pieces, $is_new_item, $id): void {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }

        // Check if already has reference number
        $existing = get_post_meta($id, 'report_number', true);
        if (!empty($existing)) {
            return;
        }

        // Get report date (for YYMM portion) - now available since Pods saved it
        $report_date = get_post_meta($id, 'report_date', true);
        if (empty($report_date)) {
            $report_date = current_time('Y-m-d');
        }

        // Generate and save reference
        $reference = self::generateNumber($report_date);
        update_post_meta($id, 'report_number', $reference);

        Logger::debug('ProjectReportReferenceGenerator', 'Generated project report reference', [
            'post_id' => $id,
            'reference' => $reference,
        ]);
    }

    /**
     * Generate next report number in RR-YYMM-XXX format.
     *
     * @param string|null $report_date Date string (any format parseable by strtotime).
     * @return string Next report number.
     */
    public static function generateNumber(?string $report_date = null): string {
        // Default to today if no date provided
        if (empty($report_date)) {
            $report_date = current_time('Y-m-d');
        }

        // Parse the date to get YYMM
        $timestamp = strtotime($report_date);
        if ($timestamp === false) {
            $timestamp = current_time('timestamp');
        }

        $yymm = date('ym', $timestamp); // e.g., "2412" for December 2024
        $prefix = 'RR-' . $yymm . '-';

        // Find highest existing number for this month
        global $wpdb;

        $highest = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = 'report_number'
            AND pm.meta_value LIKE %s
            AND p.post_type = 'project_report'
            AND p.post_status != 'trash'
            ORDER BY pm.meta_value DESC
            LIMIT 1",
            $prefix . '%'
        ));

        // Extract the sequence number and increment
        if ($highest) {
            // Get the XXX part (last 3 characters after final dash)
            $parts = explode('-', $highest);
            $last_num = (int) end($parts);
            $next_num = $last_num + 1;
        } else {
            $next_num = 1;
        }

        // Format with leading zeros (001, 002, etc.)
        return $prefix . str_pad((string) $next_num, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Parse an existing report number to get components.
     *
     * @param string $report_number Full report number.
     * @return array|null Array with 'prefix', 'yymm', 'sequence' or null if invalid.
     */
    public static function parseNumber(string $report_number): ?array {
        // Expected format: RR-YYMM-XXX
        if (!preg_match('/^(RR)-(\d{4})-(\d{3})$/', $report_number, $matches)) {
            return null;
        }

        return [
            'prefix' => $matches[1],
            'yymm' => $matches[2],
            'sequence' => (int) $matches[3],
        ];
    }

    /**
     * Get the month/year display from a report number.
     *
     * @param string $report_number Full report number.
     * @return string Month/Year display (e.g., "December 2024") or empty string.
     */
    public static function getMonthYearFromNumber(string $report_number): string {
        $parts = self::parseNumber($report_number);
        if (!$parts) {
            return '';
        }

        $yymm = $parts['yymm'];
        $year = '20' . substr($yymm, 0, 2);
        $month = substr($yymm, 2, 2);

        $timestamp = mktime(0, 0, 0, (int) $month, 1, (int) $year);
        if ($timestamp === false) {
            return '';
        }

        return date('F Y', $timestamp);
    }
}
