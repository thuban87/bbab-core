<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Modules\Projects\ProjectReportPDFService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Project Report editor metaboxes.
 *
 * Displays on project_report edit screens:
 * - Report Summary (sidebar) - status, dates, PDF actions
 *
 * Phase 7.3
 */
class ProjectReportMetabox {

    /**
     * Status badge colors.
     */
    private const STATUS_COLORS = [
        'Draft' => '#6b7280',
        'Finalized' => '#22c55e',
    ];

    /**
     * Report type badge colors.
     */
    private const TYPE_COLORS = [
        'Summary' => '#3b82f6',
        'Handoff' => '#8b5cf6',
        'Welcome Package' => '#14b8a6',
    ];

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('admin_head', [self::class, 'renderStyles']);
        add_action('admin_notices', [self::class, 'showAdminNotices']);
        add_action('wp_ajax_bbab_generate_project_report_pdf', [self::class, 'handleGeneratePDF']);

        Logger::debug('ProjectReportMetabox', 'Registered project report metabox hooks');
    }

    /**
     * Show admin notices on edit screen.
     */
    public static function showAdminNotices(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'project_report' || $screen->base !== 'post') {
            return;
        }

        if (isset($_GET['finalized']) && $_GET['finalized'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Report finalized!</strong> PDF has been generated and attached.</p></div>';
        }
    }

    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        add_meta_box(
            'bbab_project_report_summary',
            'Report Summary',
            [self::class, 'renderSummaryMetabox'],
            'project_report',
            'side',
            'high'
        );
    }

    /**
     * Render the report summary metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderSummaryMetabox(\WP_Post $post): void {
        $report_id = $post->ID;

        $number = get_post_meta($report_id, 'report_number', true);
        $status = get_post_meta($report_id, 'report_status', true) ?: 'Draft';
        $type = get_post_meta($report_id, 'report_type', true);
        $report_date = get_post_meta($report_id, 'report_date', true);

        // Get organization
        $org_id = get_post_meta($report_id, 'organization', true);
        $org_name = '';
        if ($org_id) {
            $org_name = get_post_meta($org_id, 'organization_shortcode', true);
            if (!$org_name) {
                $org_name = get_the_title($org_id);
            }
        }

        // Get project
        $project_id = get_post_meta($report_id, 'related_project', true);
        $project_name = '';
        if ($project_id) {
            $project_ref = get_post_meta($project_id, 'reference_number', true);
            $project_title = get_post_meta($project_id, 'project_name', true) ?: get_the_title($project_id);
            $project_name = $project_ref ? $project_ref . ' - ' . $project_title : $project_title;
        }

        echo '<div class="report-summary-grid">';

        // Report Number
        if ($number) {
            echo '<div class="summary-row">';
            echo '<span class="summary-label">Report #</span>';
            echo '<span class="summary-value report-number">' . esc_html($number) . '</span>';
            echo '</div>';
        }

        // Status
        echo '<div class="summary-row">';
        echo '<span class="summary-label">Status</span>';
        echo '<span class="summary-value">' . self::getStatusBadgeHtml($status) . '</span>';
        echo '</div>';

        // Type
        if ($type) {
            echo '<div class="summary-row">';
            echo '<span class="summary-label">Type</span>';
            echo '<span class="summary-value">' . self::getTypeBadgeHtml($type) . '</span>';
            echo '</div>';
        }

        // Date
        if ($report_date) {
            echo '<div class="summary-row">';
            echo '<span class="summary-label">Report Date</span>';
            echo '<span class="summary-value">' . esc_html(date('M j, Y', strtotime($report_date))) . '</span>';
            echo '</div>';
        }

        echo '</div>'; // .report-summary-grid

        // Related items section
        if ($org_name || $project_name) {
            echo '<div class="report-related" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">';

            if ($org_name && $org_id) {
                echo '<div class="related-row">';
                echo '<span class="related-label">Client:</span>';
                echo '<a href="' . esc_url(get_edit_post_link((int) $org_id)) . '">' . esc_html($org_name) . '</a>';
                echo '</div>';
            }

            if ($project_name && $project_id) {
                echo '<div class="related-row">';
                echo '<span class="related-label">Project:</span>';
                echo '<a href="' . esc_url(get_edit_post_link((int) $project_id)) . '">' . esc_html($project_name) . '</a>';
                echo '</div>';
            }

            echo '</div>';
        }

        // Finalize Button (only for Draft reports)
        if ($status !== 'Finalized') {
            $finalize_url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_finalize_report&report_id=' . $report_id . '&redirect=edit'),
                'bbab_finalize_report_' . $report_id
            );
            echo '<div class="report-finalize-action" style="margin-top: 16px; text-align: center;">';
            echo '<a href="' . esc_url($finalize_url) . '" class="button button-primary" style="width: 100%;">Finalize & Generate PDF</a>';
            echo '<p style="margin: 6px 0 0 0; font-size: 11px; color: #666;">Generates PDF and sets status to Finalized</p>';
            echo '</div>';
        }

        // PDF Link / Generate Button
        $pdf_id = get_post_meta($report_id, 'report_pdf', true);
        echo '<div class="report-pdf-actions" style="margin-top: 12px; text-align: center;">';

        if ($pdf_id) {
            $pdf_url = wp_get_attachment_url($pdf_id);
            if ($pdf_url) {
                $cache_bust = '?v=' . time();
                echo '<a href="' . esc_url($pdf_url . $cache_bust) . '" target="_blank" class="button">📄 View PDF</a> ';
            }
            echo '<button type="button" class="button bbab-regenerate-report-pdf" data-report-id="' . esc_attr($report_id) . '">🔄 Regenerate</button>';
        } else {
            echo '<button type="button" class="button bbab-generate-report-pdf" data-report-id="' . esc_attr($report_id) . '">📄 Generate PDF</button>';
        }

        echo '<div class="pdf-status" style="margin-top: 8px; font-size: 12px;"></div>';
        echo '</div>';
        echo self::getPDFScript($report_id);
    }

    /**
     * Get status badge HTML.
     *
     * @param string $status Status value.
     * @return string HTML badge.
     */
    private static function getStatusBadgeHtml(string $status): string {
        $color = self::STATUS_COLORS[$status] ?? '#6b7280';
        return sprintf(
            '<span style="display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; background:%s; color:white;">%s</span>',
            esc_attr($color),
            esc_html($status)
        );
    }

    /**
     * Get type badge HTML.
     *
     * @param string $type Type value.
     * @return string HTML badge.
     */
    private static function getTypeBadgeHtml(string $type): string {
        $color = self::TYPE_COLORS[$type] ?? '#6b7280';
        return sprintf(
            '<span style="display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; background:%s; color:white;">%s</span>',
            esc_attr($color),
            esc_html($type)
        );
    }

    /**
     * Get JavaScript for PDF generation button.
     *
     * @param int $report_id Report post ID.
     * @return string JavaScript code.
     */
    private static function getPDFScript(int $report_id): string {
        $nonce = wp_create_nonce('bbab_generate_report_pdf_' . $report_id);

        return "
        <script>
        jQuery(document).ready(function($) {
            $('.bbab-generate-report-pdf, .bbab-regenerate-report-pdf').on('click', function() {
                var btn = $(this);
                var reportId = btn.data('report-id');
                var statusDiv = btn.parent().find('.pdf-status');
                var originalText = btn.text();

                btn.prop('disabled', true).text('Generating...');
                statusDiv.text('').removeClass('success error');

                $.post(ajaxurl, {
                    action: 'bbab_generate_project_report_pdf',
                    report_id: reportId,
                    nonce: '" . esc_js($nonce) . "'
                }, function(response) {
                    if (response.success) {
                        statusDiv.text('PDF generated successfully!').addClass('success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        btn.prop('disabled', false).text(originalText);
                        statusDiv.text(response.data.message || 'Error generating PDF').addClass('error');
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text(originalText);
                    statusDiv.text('Connection error. Please try again.').addClass('error');
                });
            });
        });
        </script>";
    }

    /**
     * Handle AJAX request to generate report PDF.
     */
    public static function handleGeneratePDF(): void {
        $report_id = isset($_POST['report_id']) ? absint($_POST['report_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'bbab_generate_report_pdf_' . $report_id)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        // Verify user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        // Verify report exists
        if (!$report_id || get_post_type($report_id) !== 'project_report') {
            wp_send_json_error(['message' => 'Invalid report.']);
            return;
        }

        // Generate PDF
        $result = ProjectReportPDFService::generate($report_id);

        if (is_wp_error($result)) {
            Logger::error('ProjectReportMetabox', 'PDF generation failed', [
                'report_id' => $report_id,
                'error' => $result->get_error_message(),
            ]);
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        Logger::debug('ProjectReportMetabox', 'PDF generated via admin action', [
            'report_id' => $report_id,
            'path' => $result,
        ]);

        wp_send_json_success([
            'message' => 'PDF generated successfully.',
            'path' => $result,
        ]);
    }

    /**
     * Render metabox styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'project_report') {
            return;
        }

        echo '<style>
            /* Report Summary */
            .report-summary-grid {
                margin-bottom: 12px;
            }
            .summary-row {
                display: flex;
                justify-content: space-between;
                padding: 6px 0;
                border-bottom: 1px solid #eee;
            }
            .summary-row:last-child {
                border-bottom: none;
            }
            .summary-label {
                color: #666;
                font-size: 12px;
            }
            .summary-value {
                font-weight: 500;
            }
            .summary-value.report-number {
                font-family: monospace;
                color: #467FF7;
            }

            /* Related Items */
            .related-row {
                padding: 6px 0;
                border-bottom: 1px solid #eee;
            }
            .related-row:last-child {
                border-bottom: none;
            }
            .related-label {
                display: block;
                font-size: 11px;
                color: #666;
                margin-bottom: 2px;
            }
            .related-row a {
                text-decoration: none;
            }
            .related-row a:hover {
                text-decoration: underline;
            }

            /* PDF Status */
            .pdf-status.success {
                color: #1e8449;
            }
            .pdf-status.error {
                color: #c62828;
            }
        </style>';
    }
}
