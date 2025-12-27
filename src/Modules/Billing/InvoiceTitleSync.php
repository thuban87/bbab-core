<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Syncs invoice post_title to invoice_number field.
 *
 * Ensures the WordPress post title matches the invoice number
 * for consistent display in admin lists and elsewhere.
 *
 * Migrated from: WPCode Snippet #1253
 */
class InvoiceTitleSync {

    /**
     * Flag to prevent infinite loop.
     */
    private static bool $syncing = false;

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Run after InvoiceReferenceGenerator (priority 20 vs 10)
        // Uses Pods hook so invoice_number is available
        add_action('pods_api_post_save_pod_item_invoice', [self::class, 'syncTitle'], 20, 3);

        Logger::debug('InvoiceTitleSync', 'Registered invoice title sync hooks');
    }

    /**
     * Sync post_title to invoice_number field.
     *
     * Uses Pods hook which runs AFTER meta fields and reference number are saved.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param mixed $id          Post ID (may come as string from Pods).
     */
    public static function syncTitle($pieces, $is_new_item, $id): void {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }

        // Prevent infinite loop
        if (self::$syncing) {
            return;
        }

        $post = get_post($id);
        if (!$post) {
            return;
        }

        $invoice_number = get_post_meta($id, 'invoice_number', true);

        // Only sync if invoice_number exists and differs from title
        if (!empty($invoice_number) && $post->post_title !== $invoice_number) {
            self::$syncing = true;

            wp_update_post([
                'ID' => $id,
                'post_title' => $invoice_number,
            ]);

            self::$syncing = false;

            Logger::debug('InvoiceTitleSync', 'Synced invoice title', [
                'post_id' => $id,
                'invoice_number' => $invoice_number,
            ]);
        }
    }
}
