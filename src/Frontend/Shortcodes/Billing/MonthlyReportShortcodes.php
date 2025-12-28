<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Billing;

use BBAB\ServiceCenter\Modules\Billing\MonthlyReportService;

/**
 * Monthly Report display shortcodes.
 *
 * Provides shortcodes for displaying monthly report data:
 * - [report_total_hours] - Total billable hours
 * - [report_hours_percentage] - Progress bar percentage
 * - [report_hours_color] - Color class based on usage
 * - [report_overage] - Overage charges message
 * - [report_has_overage] - Yes/no check for overage
 *
 * Migrated from snippets: 1062, 1065, 1066
 */
class MonthlyReportShortcodes {

    /**
     * Get the primary shortcode tag.
     *
     * @return string
     */
    public function getTag(): string {
        return 'report_total_hours';
    }

    /**
     * Register all shortcodes.
     */
    public function register(): void {
        add_shortcode('report_total_hours', [$this, 'totalHours']);
        add_shortcode('report_hours_percentage', [$this, 'hoursPercentage']);
        add_shortcode('report_hours_color', [$this, 'hoursColor']);
        add_shortcode('report_overage', [$this, 'overage']);
        add_shortcode('report_has_overage', [$this, 'hasOverage']);
    }

    /**
     * [report_total_hours] - Display total billable hours.
     *
     * Attributes:
     * - report_id: Monthly report post ID (default: current post)
     * - format: 'number' (default) or 'display' (shows "X / Y Free Hours Used")
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Hours value or formatted display.
     */
    public function totalHours($atts = []): string {
        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'report_id' => get_the_ID(),
            'format' => 'number',
        ], $atts);

        $report_id = (int) $atts['report_id'];
        if (empty($report_id)) {
            return '';
        }

        $total = MonthlyReportService::getTotalHours($report_id);

        if ($atts['format'] === 'display') {
            $limit = MonthlyReportService::getFreeHoursLimit($report_id);
            return esc_html($total . ' / ' . $limit . ' Free Hours Used');
        }

        return (string) $total;
    }

    /**
     * [report_hours_percentage] - Get progress bar percentage.
     *
     * Returns a number 0-100 suitable for progress bar width.
     *
     * Attributes:
     * - report_id: Monthly report post ID (default: current post)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Percentage value (0-100).
     */
    public function hoursPercentage($atts = []): string {
        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'report_id' => get_the_ID(),
        ], $atts);

        $report_id = (int) $atts['report_id'];
        if (empty($report_id)) {
            return '0';
        }

        $progress = MonthlyReportService::getFreeHoursProgress($report_id);
        return (string) $progress['percent'];
    }

    /**
     * [report_hours_color] - Get color class based on usage.
     *
     * Returns: 'blue' (0-50%), 'yellow' (51-80%), 'orange' (81-99%), 'red' (100%+)
     *
     * Attributes:
     * - report_id: Monthly report post ID (default: current post)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Color name.
     */
    public function hoursColor($atts = []): string {
        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'report_id' => get_the_ID(),
        ], $atts);

        $report_id = (int) $atts['report_id'];
        if (empty($report_id)) {
            return 'blue';
        }

        return MonthlyReportService::getProgressColor($report_id);
    }

    /**
     * [report_overage] - Display overage charges message.
     *
     * Attributes:
     * - report_id: Monthly report post ID (default: current post)
     * - rate: Hourly rate override (default: from settings)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Overage message or "No overage charges" message.
     */
    public function overage($atts = []): string {
        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'report_id' => get_the_ID(),
            'rate' => null,
        ], $atts);

        $report_id = (int) $atts['report_id'];
        if (empty($report_id)) {
            return '';
        }

        // Get rate (from attribute or settings)
        $rate = $atts['rate'] !== null
            ? (float) $atts['rate']
            : MonthlyReportService::getHourlyRate();

        if (!MonthlyReportService::hasOverage($report_id)) {
            return 'No overage charges this period.';
        }

        $overage_hours = MonthlyReportService::getOverageHours($report_id);
        $overage_cost = MonthlyReportService::getOverageAmount($report_id, $rate);

        return sprintf(
            'Overage: %s hours beyond free limit @ $%s/hr = <strong>$%s</strong>',
            esc_html((string) $overage_hours),
            esc_html(number_format($rate, 2)),
            esc_html(number_format($overage_cost, 2))
        );
    }

    /**
     * [report_has_overage] - Check if report has overage.
     *
     * Returns 'yes' or 'no' for use in conditional logic.
     *
     * Attributes:
     * - report_id: Monthly report post ID (default: current post)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string 'yes' or 'no'.
     */
    public function hasOverage($atts = []): string {
        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'report_id' => get_the_ID(),
        ], $atts);

        $report_id = (int) $atts['report_id'];
        if (empty($report_id)) {
            return 'no';
        }

        return MonthlyReportService::hasOverage($report_id) ? 'yes' : 'no';
    }
}
