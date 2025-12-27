<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\Billing\InvoiceService;
use BBAB\ServiceCenter\Modules\Billing\LineItemService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns and filters for Invoices.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Admin list filters (Org, Status, Type)
 * - Sortable columns
 * - Column styles
 *
 * This is a foundation class for Phase 5.3.
 * Full migration from snippet 1256/1257 will happen in Phase 6.1.
 */
class InvoiceColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_invoice_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_invoice_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-invoice_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('InvoiceColumns', 'Registered invoice column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['invoice_number'] = 'Invoice #';
        $new_columns['invoice_date'] = 'Date';
        $new_columns['organization'] = 'Client';
        $new_columns['invoice_type'] = 'Type';
        $new_columns['amount'] = 'Amount';
        $new_columns['amount_paid'] = 'Paid';
        $new_columns['balance'] = 'Balance';
        $new_columns['invoice_status'] = 'Status';
        $new_columns['due_date'] = 'Due Date';
        $new_columns['pdf'] = 'PDF';

        return $new_columns;
    }

    /**
     * Render column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function renderColumn(string $column, int $post_id): void {
        switch ($column) {
            case 'invoice_number':
                $number = InvoiceService::getNumber($post_id);
                if ($number) {
                    echo '<span class="invoice-number">' . esc_html($number) . '</span>';
                } else {
                    echo '<span class="no-number">—</span>';
                }
                break;

            case 'invoice_date':
                $date = InvoiceService::getDate($post_id);
                if (!empty($date) && strtotime($date) !== false) {
                    echo esc_html(date('M j, Y', strtotime($date)));
                } else {
                    echo '—';
                }
                break;

            case 'organization':
                $org = InvoiceService::getOrganization($post_id);
                if ($org) {
                    $filter_url = admin_url('edit.php?post_type=invoice&org_filter=' . $org->ID);
                    echo '<a href="' . esc_url($filter_url) . '">' . esc_html($org->post_title) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'invoice_type':
                $type = InvoiceService::getType($post_id);
                echo esc_html($type);
                break;

            case 'amount':
                $amount = InvoiceService::getAmount($post_id);
                echo '$' . number_format($amount, 2);
                break;

            case 'amount_paid':
                $paid = InvoiceService::getPaidAmount($post_id);
                if ($paid > 0) {
                    echo '<span style="color: #1e8449;">$' . number_format($paid, 2) . '</span>';
                } else {
                    echo '<span style="color: #999;">$0.00</span>';
                }
                break;

            case 'balance':
                $balance = InvoiceService::getBalance($post_id);
                if ($balance > 0) {
                    echo '<span style="color: #c62828; font-weight: 500;">$' . number_format($balance, 2) . '</span>';
                } else {
                    echo '<span style="color: #1e8449;">$0.00</span>';
                }
                break;

            case 'invoice_status':
                $status = InvoiceService::getStatus($post_id);
                // Check if actually overdue
                if (InvoiceService::isOverdue($post_id) && $status === InvoiceService::STATUS_PENDING) {
                    $status = InvoiceService::STATUS_OVERDUE;
                }
                echo InvoiceService::getStatusBadgeHtml($status);
                break;

            case 'due_date':
                $due_date = InvoiceService::getDueDate($post_id);
                if (!empty($due_date) && strtotime($due_date) !== false) {
                    $is_overdue = InvoiceService::isOverdue($post_id);
                    $style = $is_overdue ? 'color: #c62828; font-weight: 500;' : '';
                    echo '<span style="' . $style . '">' . esc_html(date('M j, Y', strtotime($due_date))) . '</span>';
                } else {
                    echo '—';
                }
                break;

            case 'pdf':
                $pdf = InvoiceService::getPdf($post_id);
                if ($pdf && !empty($pdf['url'])) {
                    echo '<a href="' . esc_url($pdf['url']) . '" target="_blank" title="Download PDF">📄</a>';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;
        }
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['invoice_date'] = 'invoice_date';
        $columns['due_date'] = 'due_date';
        $columns['amount'] = 'amount';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'invoice') {
            return;
        }

        // Organization filter
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        $selected_org = isset($_GET['org_filter']) ? sanitize_text_field($_GET['org_filter']) : '';

        echo '<select name="org_filter">';
        echo '<option value="">All Clients</option>';
        foreach ($orgs as $org) {
            $selected = selected($selected_org, (string) $org->ID, false);
            echo '<option value="' . esc_attr($org->ID) . '"' . $selected . '>' . esc_html($org->post_title) . '</option>';
        }
        echo '</select>';

        // Status filter
        $statuses = [
            InvoiceService::STATUS_DRAFT,
            InvoiceService::STATUS_PENDING,
            InvoiceService::STATUS_PARTIAL,
            InvoiceService::STATUS_PAID,
            InvoiceService::STATUS_OVERDUE,
            InvoiceService::STATUS_VOID,
        ];
        $selected_status = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';

        echo '<select name="status_filter">';
        echo '<option value="">All Statuses</option>';
        foreach ($statuses as $status) {
            $selected = selected($selected_status, $status, false);
            echo '<option value="' . esc_attr($status) . '"' . $selected . '>' . esc_html($status) . '</option>';
        }
        echo '</select>';

        // Type filter
        $types = [
            InvoiceService::TYPE_STANDARD,
            InvoiceService::TYPE_MILESTONE,
            InvoiceService::TYPE_CLOSEOUT,
            InvoiceService::TYPE_DEPOSIT,
        ];
        $selected_type = isset($_GET['type_filter']) ? sanitize_text_field($_GET['type_filter']) : '';

        echo '<select name="type_filter">';
        echo '<option value="">All Types</option>';
        foreach ($types as $type) {
            $selected = selected($selected_type, $type, false);
            echo '<option value="' . esc_attr($type) . '"' . $selected . '>' . esc_html($type) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Apply filters to the query.
     *
     * @param \WP_Query $query The query object.
     */
    public static function applyFilters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'invoice') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Organization filter
        if (!empty($_GET['org_filter'])) {
            $meta_query[] = [
                'key' => 'organization',
                'value' => sanitize_text_field($_GET['org_filter']),
            ];
        }

        // Status filter
        if (!empty($_GET['status_filter'])) {
            $meta_query[] = [
                'key' => 'invoice_status',
                'value' => sanitize_text_field($_GET['status_filter']),
            ];
        }

        // Type filter
        if (!empty($_GET['type_filter'])) {
            $meta_query[] = [
                'key' => 'invoice_type',
                'value' => sanitize_text_field($_GET['type_filter']),
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        // Handle sorting
        $orderby = $query->get('orderby');
        if ($orderby === 'invoice_date') {
            $query->set('meta_key', 'invoice_date');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'due_date') {
            $query->set('meta_key', 'due_date');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'amount') {
            $query->set('meta_key', 'amount');
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * Render column and badge styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'invoice') {
            return;
        }

        echo '<style>
            /* Invoice Number */
            .invoice-number {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
            }
            .no-number {
                color: #999;
            }

            /* Column widths */
            .column-invoice_number { width: 130px; }
            .column-invoice_date { width: 100px; }
            .column-organization { width: 150px; }
            .column-invoice_type { width: 100px; }
            .column-amount { width: 90px; text-align: right; }
            .column-amount_paid { width: 90px; text-align: right; }
            .column-balance { width: 90px; text-align: right; }
            .column-invoice_status { width: 100px; }
            .column-due_date { width: 100px; }
            .column-pdf { width: 50px; text-align: center; }

            /* Right-align monetary columns */
            .column-amount,
            .column-amount_paid,
            .column-balance {
                text-align: right !important;
            }
        </style>';
    }
}
