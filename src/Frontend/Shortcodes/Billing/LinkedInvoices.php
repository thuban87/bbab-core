<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Billing;

use BBAB\ServiceCenter\Modules\Billing\InvoiceService;
use BBAB\ServiceCenter\Utils\UserContext;

/**
 * Linked Invoices shortcodes.
 *
 * Displays invoices linked to:
 * - [milestone_invoices] - invoices on single milestone pages
 * - [project_invoices] - invoices on single project pages
 *
 * Note: Does not extend BaseShortcode because it registers two shortcodes.
 *
 * Migrated from: WPCode Snippets #1631, #2750
 */
class LinkedInvoices {

    /**
     * Get the shortcode tag (primary).
     *
     * @return string
     */
    public function getTag(): string {
        return 'milestone_invoices';
    }

    /**
     * Register shortcodes.
     */
    public function register(): void {
        add_shortcode('milestone_invoices', [$this, 'renderMilestoneInvoices']);
        add_shortcode('project_invoices', [$this, 'renderProjectInvoices']);
    }

    /**
     * Render milestone invoices shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function renderMilestoneInvoices($atts = []): string {
        if (!is_array($atts)) {
            $atts = [];
        }

        // Default to current post ID if on a milestone single page
        $atts = shortcode_atts([
            'milestone_id' => get_the_ID(),
        ], $atts);

        $milestone_id = (int) $atts['milestone_id'];

        // Verify we're on a milestone page
        if (get_post_type($milestone_id) !== 'milestone') {
            return '';
        }

        // Get org context for access control
        $current_org_id = UserContext::getCurrentOrgId();
        if (!$current_org_id && !UserContext::isAdmin()) {
            return '<p class="bbab-error">Please log in to view invoice information.</p>';
        }

        // Get milestone's project to verify org
        $project_id = get_post_meta($milestone_id, 'related_project', true);
        if (!$project_id) {
            return '';
        }

        $milestone_org = get_post_meta($project_id, 'organization', true);
        if ((int) $milestone_org !== $current_org_id && !UserContext::isAdmin()) {
            return '';
        }

        // Get invoices linked to this milestone
        $invoices = InvoiceService::getForMilestone($milestone_id);

        if (empty($invoices)) {
            return '<div class="bbab-no-invoices" style="padding: 15px; background: #f9fafb; border-radius: 8px; color: #6b7280; font-style: italic;">No invoices linked to this milestone yet.</div>';
        }

        return $this->renderInvoiceTable($invoices, 'Milestone Invoices');
    }

    /**
     * Render project invoices shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function renderProjectInvoices($atts = []): string {
        if (!is_array($atts)) {
            $atts = [];
        }

        // Default to current post ID if on a project single page
        $atts = shortcode_atts([
            'project_id' => get_the_ID(),
        ], $atts);

        $project_id = (int) $atts['project_id'];

        // Verify we're on a project page
        if (get_post_type($project_id) !== 'project') {
            return '';
        }

        // Get org context for access control
        $current_org_id = UserContext::getCurrentOrgId();
        if (!$current_org_id && !UserContext::isAdmin()) {
            return '<p class="bbab-error">Please log in to view invoice information.</p>';
        }

        // Verify org access
        $project_org = get_post_meta($project_id, 'organization', true);
        if ((int) $project_org !== $current_org_id && !UserContext::isAdmin()) {
            return '';
        }

        // Get all invoices for this project (direct + milestone-linked)
        $invoices = InvoiceService::getForProject($project_id);

        if (empty($invoices)) {
            return '<div class="bbab-no-invoices" style="padding: 15px; background: #f9fafb; border-radius: 8px; color: #6b7280; font-style: italic;">No invoices linked to this project yet.</div>';
        }

        return $this->renderInvoiceTable($invoices, 'Project Invoices');
    }

    /**
     * Render the invoices table.
     *
     * @param array  $invoices Array of invoice posts.
     * @param string $title    Table title.
     * @return string HTML output.
     */
    private function renderInvoiceTable(array $invoices, string $title): string {
        $output = '<div class="bbab-linked-invoices">';
        $output .= '<style>
            .bbab-linked-invoices { margin: 20px 0; }
            .bbab-linked-invoices h3 { margin: 0 0 15px 0; padding: 0; font-size: 18px; color: #1e293b; }
            .bbab-invoice-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .bbab-invoice-table th { background: #1e40af; color: white; padding: 12px 15px; text-align: left; font-weight: 600; font-size: 13px; }
            .bbab-invoice-table td { padding: 12px 15px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
            .bbab-invoice-table tr:last-child td { border-bottom: none; }
            .bbab-invoice-table tr:hover td { background: #f8fafc; }
            .bbab-invoice-link { color: #2563eb; text-decoration: none; font-weight: 500; }
            .bbab-invoice-link:hover { text-decoration: underline; }
            .bbab-status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
            .bbab-status-draft { background: #f3f4f6; color: #4b5563; }
            .bbab-status-pending { background: #fef3c7; color: #92400e; }
            .bbab-status-paid { background: #d1fae5; color: #065f46; }
            .bbab-status-partial { background: #dbeafe; color: #1e40af; }
            .bbab-status-overdue { background: #fee2e2; color: #b91c1c; }
            .bbab-status-cancelled { background: #e5e7eb; color: #6b7280; }
            .bbab-amount { font-weight: 600; color: #1e293b; }
            .bbab-pdf-link { display: inline-flex; align-items: center; gap: 4px; color: #dc2626; font-size: 12px; }
            .bbab-pdf-link:hover { text-decoration: underline; }
            @media (max-width: 768px) {
                .bbab-invoice-table th:nth-child(3),
                .bbab-invoice-table td:nth-child(3),
                .bbab-invoice-table th:nth-child(4),
                .bbab-invoice-table td:nth-child(4) { display: none; }
            }
        </style>';

        $output .= '<h3>' . esc_html($title) . '</h3>';
        $output .= '<table class="bbab-invoice-table">';
        $output .= '<thead><tr>';
        $output .= '<th>Invoice</th>';
        $output .= '<th>Amount</th>';
        $output .= '<th>Date</th>';
        $output .= '<th>Due</th>';
        $output .= '<th>Status</th>';
        $output .= '<th>PDF</th>';
        $output .= '</tr></thead>';
        $output .= '<tbody>';

        foreach ($invoices as $invoice) {
            $invoice_id = $invoice->ID;
            $invoice_number = InvoiceService::getNumber($invoice_id) ?: 'INV-' . $invoice_id;
            $amount = InvoiceService::getAmount($invoice_id);
            $invoice_date = InvoiceService::getDate($invoice_id);
            $due_date = InvoiceService::getDueDate($invoice_id);
            $status = InvoiceService::getStatus($invoice_id);
            $pdf = InvoiceService::getPdf($invoice_id);

            // Format dates
            $formatted_date = $invoice_date ? date('M j, Y', strtotime($invoice_date)) : '-';
            $formatted_due = $due_date ? date('M j, Y', strtotime($due_date)) : '-';

            // Status badge class
            $status_class = 'bbab-status-' . strtolower(str_replace(' ', '-', $status));

            $output .= '<tr>';

            // Invoice number (linked to invoice page if one exists)
            $invoice_link = get_permalink($invoice_id);
            if ($invoice_link) {
                $output .= '<td><a href="' . esc_url($invoice_link) . '" class="bbab-invoice-link">' . esc_html($invoice_number) . '</a></td>';
            } else {
                $output .= '<td>' . esc_html($invoice_number) . '</td>';
            }

            // Amount
            $output .= '<td class="bbab-amount">$' . number_format($amount, 2) . '</td>';

            // Dates
            $output .= '<td>' . esc_html($formatted_date) . '</td>';
            $output .= '<td>' . esc_html($formatted_due) . '</td>';

            // Status badge
            $output .= '<td><span class="bbab-status-badge ' . esc_attr($status_class) . '">' . esc_html($status) . '</span></td>';

            // PDF link
            if ($pdf && !empty($pdf['url'])) {
                $output .= '<td><a href="' . esc_url($pdf['url']) . '" target="_blank" class="bbab-pdf-link">View PDF</a></td>';
            } else {
                $output .= '<td><span style="color: #9ca3af;">-</span></td>';
            }

            $output .= '</tr>';
        }

        $output .= '</tbody></table>';
        $output .= '</div>';

        return $output;
    }
}
