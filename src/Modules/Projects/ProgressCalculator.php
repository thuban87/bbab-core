<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

/**
 * Calculates progress metrics for projects.
 *
 * Handles:
 * - Milestone completion progress (X/Y complete)
 * - Payment progress ($ paid vs $ total)
 * - Work hours progress
 */
class ProgressCalculator {

    /**
     * Get milestone completion progress for a project.
     *
     * @param int $project_id Project post ID.
     * @return array ['completed' => int, 'total' => int, 'percent' => float]
     */
    public static function getMilestoneProgress(int $project_id): array {
        $milestones = ProjectService::getMilestones($project_id);
        $total = count($milestones);
        $completed = 0;

        foreach ($milestones as $milestone) {
            $status = get_post_meta($milestone->ID, 'milestone_status', true);
            if ($status === MilestoneService::WORK_COMPLETED) {
                $completed++;
            }
        }

        $percent = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            'completed' => $completed,
            'total' => $total,
            'percent' => $percent,
        ];
    }

    /**
     * Get payment progress for a project.
     *
     * @param int $project_id Project post ID.
     * @return array ['paid' => float, 'total' => float, 'percent' => float]
     */
    public static function getPaymentProgress(int $project_id): array {
        $total = ProjectService::getProjectTotal($project_id);
        $paid = ProjectService::getPaidTotal($project_id);

        $percent = $total > 0 ? round(($paid / $total) * 100, 1) : 0;

        return [
            'paid' => $paid,
            'total' => $total,
            'percent' => $percent,
        ];
    }

    /**
     * Get invoiced progress for a project.
     *
     * @param int $project_id Project post ID.
     * @return array ['invoiced' => float, 'total' => float, 'percent' => float]
     */
    public static function getInvoicedProgress(int $project_id): array {
        $total = ProjectService::getProjectTotal($project_id);
        $invoiced = ProjectService::getInvoicedTotal($project_id);

        $percent = $total > 0 ? round(($invoiced / $total) * 100, 1) : 0;

        return [
            'invoiced' => $invoiced,
            'total' => $total,
            'percent' => $percent,
        ];
    }

    /**
     * Get milestone payment progress for a project.
     * Counts milestones by payment status.
     *
     * @param int $project_id Project post ID.
     * @return array ['pending' => int, 'invoiced' => int, 'paid' => int, 'total' => int]
     */
    public static function getMilestonePaymentProgress(int $project_id): array {
        $milestones = ProjectService::getMilestones($project_id);
        $counts = [
            'pending' => 0,
            'invoiced' => 0,
            'paid' => 0,
            'total' => count($milestones),
        ];

        foreach ($milestones as $milestone) {
            $status = MilestoneService::getPaymentStatus($milestone->ID);

            switch ($status) {
                case MilestoneService::PAYMENT_PAID:
                    $counts['paid']++;
                    break;
                case MilestoneService::PAYMENT_INVOICED:
                    $counts['invoiced']++;
                    break;
                default:
                    $counts['pending']++;
            }
        }

        return $counts;
    }

    /**
     * Get full project progress summary.
     *
     * @param int $project_id Project post ID.
     * @return array Comprehensive progress data.
     */
    public static function getProjectSummary(int $project_id): array {
        return [
            'milestones' => self::getMilestoneProgress($project_id),
            'payment' => self::getPaymentProgress($project_id),
            'invoiced' => self::getInvoicedProgress($project_id),
            'milestone_payments' => self::getMilestonePaymentProgress($project_id),
            'hours' => ProjectService::getTotalHours($project_id),
            'budget' => ProjectService::getProjectTotal($project_id),
        ];
    }

    /**
     * Render a simple progress bar HTML.
     *
     * @param float  $percent     Percentage (0-100).
     * @param string $color       Bar color (hex or CSS color).
     * @param string $label       Optional label text.
     * @param string $height      Bar height (CSS value).
     * @return string HTML.
     */
    public static function renderProgressBar(
        float $percent,
        string $color = '#467FF7',
        string $label = '',
        string $height = '8px'
    ): string {
        $percent = max(0, min(100, $percent));

        $html = '<div style="background: #e0e0e0; border-radius: 4px; overflow: hidden; height: ' . esc_attr($height) . ';">';
        $html .= '<div style="background: ' . esc_attr($color) . '; width: ' . $percent . '%; height: 100%; transition: width 0.3s;"></div>';
        $html .= '</div>';

        if ($label) {
            $html .= '<div style="font-size: 12px; color: #666; margin-top: 4px;">' . esc_html($label) . '</div>';
        }

        return $html;
    }

    /**
     * Render milestone progress display (e.g., "3/5 complete").
     *
     * @param int  $project_id Project post ID.
     * @param bool $show_bar   Whether to include progress bar.
     * @return string HTML.
     */
    public static function renderMilestoneProgress(int $project_id, bool $show_bar = false): string {
        $progress = self::getMilestoneProgress($project_id);

        $html = '<span style="font-size: 13px;">';
        $html .= '<strong>' . $progress['completed'] . '</strong> / ' . $progress['total'] . ' complete';
        $html .= '</span>';

        if ($show_bar && $progress['total'] > 0) {
            $html .= '<div style="margin-top: 4px;">';
            $html .= self::renderProgressBar($progress['percent'], '#1e8449');
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render payment progress display.
     *
     * @param int  $project_id Project post ID.
     * @param bool $show_bar   Whether to include progress bar.
     * @return string HTML.
     */
    public static function renderPaymentProgress(int $project_id, bool $show_bar = false): string {
        $progress = self::getPaymentProgress($project_id);

        $html = '<span style="font-size: 13px;">';
        $html .= '$' . number_format($progress['paid'], 2);
        $html .= ' / $' . number_format($progress['total'], 2);
        $html .= ' (' . $progress['percent'] . '%)';
        $html .= '</span>';

        if ($show_bar && $progress['total'] > 0) {
            $html .= '<div style="margin-top: 4px;">';
            $html .= self::renderProgressBar($progress['percent'], '#1e8449');
            $html .= '</div>';
        }

        return $html;
    }
}
