<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Invoice line item service.
 *
 * Handles:
 * - Line item CRUD operations
 * - Auto-title generation (INV-XXX - Type - $Amount)
 * - Cascade delete/trash with parent invoice
 *
 * Migrated from: WPCode Snippets #1991, #1996 (line item parts)
 */
class LineItemService {

    /**
     * Flag to prevent infinite loop during title sync.
     */
    private static bool $syncing = false;

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Auto-title line items on Pods save (runs after meta is saved)
        add_action('pods_api_post_save_pod_item_invoice_line_item', [self::class, 'autoTitle'], 10, 3);

        // Cascade delete/trash when invoice is deleted/trashed
        add_action('before_delete_post', [self::class, 'cascadeDelete']);
        add_action('wp_trash_post', [self::class, 'cascadeTrash']);

        Logger::debug('LineItemService', 'Registered line item service hooks');
    }

    /**
     * Auto-generate line item title on save.
     *
     * Format: INV-XXXXX - Line Type - $Amount
     * Uses Pods hook which runs AFTER meta fields are saved.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param mixed $id          Post ID (may come as string from Pods).
     */
    public static function autoTitle($pieces, $is_new_item, $id): void {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }

        // Prevent infinite loops
        if (self::$syncing) {
            return;
        }

        $post = get_post($id);
        if (!$post) {
            return;
        }

        // Get field values - now available since Pods saved them
        $invoice_id = get_post_meta($id, 'related_invoice', true);
        $line_type = get_post_meta($id, 'line_type', true);
        $amount = get_post_meta($id, 'amount', true);

        // Get invoice number if we have a related invoice
        $invoice_number = 'No Invoice';
        if (!empty($invoice_id)) {
            $inv_num = get_post_meta($invoice_id, 'invoice_number', true);
            if (!empty($inv_num)) {
                $invoice_number = $inv_num;
            }
        }

        // Format amount
        $amount_display = !empty($amount) ? '$' . number_format((float) $amount, 2) : '$0.00';

        // Build title
        $new_title = $invoice_number . ' - ' . ($line_type ?: 'Unknown Type') . ' - ' . $amount_display;

        // Only update if title changed
        if ($post->post_title !== $new_title) {
            self::$syncing = true;

            wp_update_post([
                'ID' => $id,
                'post_title' => $new_title,
            ]);

            self::$syncing = false;

            Logger::debug('LineItemService', 'Auto-titled line item', [
                'post_id' => $id,
                'new_title' => $new_title,
            ]);
        }
    }

    /**
     * Cascade delete line items when invoice is permanently deleted.
     *
     * @param int $post_id Post ID being deleted.
     */
    public static function cascadeDelete(int $post_id): void {
        if (get_post_type($post_id) !== 'invoice') {
            return;
        }

        $line_items = get_posts([
            'post_type' => 'invoice_line_item',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'meta_query' => [[
                'key' => 'related_invoice',
                'value' => $post_id,
                'compare' => '=',
            ]],
            'fields' => 'ids',
        ]);

        foreach ($line_items as $item_id) {
            wp_delete_post($item_id, true);
        }

        if (!empty($line_items)) {
            Logger::debug('LineItemService', 'Cascade deleted line items', [
                'invoice_id' => $post_id,
                'count' => count($line_items),
            ]);
        }
    }

    /**
     * Cascade trash line items when invoice is trashed.
     *
     * @param int $post_id Post ID being trashed.
     */
    public static function cascadeTrash(int $post_id): void {
        if (get_post_type($post_id) !== 'invoice') {
            return;
        }

        $line_items = get_posts([
            'post_type' => 'invoice_line_item',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_invoice',
                'value' => $post_id,
                'compare' => '=',
            ]],
            'fields' => 'ids',
        ]);

        foreach ($line_items as $item_id) {
            wp_trash_post($item_id);
        }

        if (!empty($line_items)) {
            Logger::debug('LineItemService', 'Cascade trashed line items', [
                'invoice_id' => $post_id,
                'count' => count($line_items),
            ]);
        }
    }

    /**
     * Get line items for an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return array Array of line item post objects, ordered by display_order.
     */
    public static function getForInvoice(int $invoice_id): array {
        return get_posts([
            'post_type' => 'invoice_line_item',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_key' => 'display_order',
            'orderby' => ['meta_value_num' => 'ASC', 'ID' => 'ASC'],
            'meta_query' => [
                [
                    'key' => 'related_invoice',
                    'value' => $invoice_id,
                    'compare' => '=',
                ],
            ],
        ]);
    }

    /**
     * Get total amount for an invoice from its line items.
     *
     * @param int $invoice_id Invoice post ID.
     * @return float Total amount.
     */
    public static function getTotalAmount(int $invoice_id): float {
        $items = self::getForInvoice($invoice_id);
        $total = 0.0;

        foreach ($items as $item) {
            $amount = get_post_meta($item->ID, 'amount', true);
            $total += (float) $amount;
        }

        return $total;
    }

    /**
     * Get total hours for an invoice from its line items.
     *
     * @param int $invoice_id Invoice post ID.
     * @return float Total hours (from quantity field for Support type items).
     */
    public static function getTotalHours(int $invoice_id): float {
        $items = self::getForInvoice($invoice_id);
        $total = 0.0;

        foreach ($items as $item) {
            $line_type = get_post_meta($item->ID, 'line_type', true);
            // Only count hours from Support-type line items
            if (in_array($line_type, ['Support', 'Support (Non-Billable)'], true)) {
                $quantity = get_post_meta($item->ID, 'quantity', true);
                $total += (float) $quantity;
            }
        }

        return $total;
    }

    /**
     * Create a line item for an invoice.
     *
     * @param int   $invoice_id Invoice post ID.
     * @param array $data       Line item data.
     * @return int|\WP_Error Line item post ID or error.
     */
    public static function create(int $invoice_id, array $data): int|\WP_Error {
        $line_item_id = wp_insert_post([
            'post_type' => 'invoice_line_item',
            'post_title' => 'Line Item',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($line_item_id)) {
            return $line_item_id;
        }

        // Use Pods API if available for proper relationship handling
        if (function_exists('pods')) {
            $pod = pods('invoice_line_item', $line_item_id);

            $fields_to_save = [
                'related_invoice' => $invoice_id,
                'line_type' => $data['line_type'] ?? '',
                'description' => $data['description'] ?? '',
                'quantity' => $data['quantity'] ?? '',
                'rate' => $data['rate'] ?? '',
                'amount' => $data['amount'] ?? 0,
                'display_order' => $data['display_order'] ?? 0,
            ];

            if (!empty($data['related_service_request'])) {
                $fields_to_save['related_service_request'] = $data['related_service_request'];
            }

            if (!empty($data['related_milestone'])) {
                $fields_to_save['related_milestone'] = $data['related_milestone'];
            }

            $pod->save($fields_to_save);
        } else {
            // Fallback to standard postmeta
            update_post_meta($line_item_id, 'related_invoice', $invoice_id);
            update_post_meta($line_item_id, 'line_type', $data['line_type'] ?? '');
            update_post_meta($line_item_id, 'description', $data['description'] ?? '');
            update_post_meta($line_item_id, 'quantity', $data['quantity'] ?? '');
            update_post_meta($line_item_id, 'rate', $data['rate'] ?? '');
            update_post_meta($line_item_id, 'amount', $data['amount'] ?? 0);
            update_post_meta($line_item_id, 'display_order', $data['display_order'] ?? 0);

            if (!empty($data['related_service_request'])) {
                update_post_meta($line_item_id, 'related_service_request', $data['related_service_request']);
            }
            if (!empty($data['related_milestone'])) {
                update_post_meta($line_item_id, 'related_milestone', $data['related_milestone']);
            }
        }

        // Trigger re-save to fire auto-title hook
        wp_update_post(['ID' => $line_item_id]);

        Logger::debug('LineItemService', 'Created line item', [
            'invoice_id' => $invoice_id,
            'line_item_id' => $line_item_id,
            'line_type' => $data['line_type'] ?? '',
        ]);

        return $line_item_id;
    }

    /**
     * Get line item count for an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return int Count.
     */
    public static function getCount(int $invoice_id): int {
        return count(self::getForInvoice($invoice_id));
    }
}
