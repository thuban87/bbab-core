<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Handles line item linking when creating from invoice.
 *
 * Flow:
 * 1. User clicks "Add Line Item" link with ?invoice_id=123
 * 2. On load-post-new.php, we store the ID in a transient
 * 3. On save_post, we save the relationship from transient
 *
 * The dropdown won't visually show the invoice, but the relationship
 * will be saved correctly on publish.
 */
class LineItemLinker {

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('load-post-new.php', [$this, 'setInvoiceLinkTransient']);
        add_action('save_post_invoice_line_item', [$this, 'maybeSetInvoiceRelationship'], 5, 3);
        add_action('admin_notices', [$this, 'showInvoiceLinkNotice']);
    }

    /**
     * Set transient when navigating to new line item with invoice_id param.
     */
    public function setInvoiceLinkTransient(): void {
        global $typenow;

        if ('invoice_line_item' !== $typenow) {
            return;
        }

        if (!empty($_GET['invoice_id'])) {
            $invoice_id = absint($_GET['invoice_id']);
            if ($invoice_id > 0) {
                $user_id = get_current_user_id();
                set_transient('bbab_pending_invoice_link_' . $user_id, $invoice_id, HOUR_IN_SECONDS);
            }
        }
    }

    /**
     * Show notice that line item will be linked to invoice.
     */
    public function showInvoiceLinkNotice(): void {
        global $pagenow, $typenow;

        if ('post-new.php' !== $pagenow || 'invoice_line_item' !== $typenow) {
            return;
        }

        $invoice_id = isset($_GET['invoice_id']) ? absint($_GET['invoice_id']) : 0;
        if (!$invoice_id) {
            return;
        }

        $invoice = get_post($invoice_id);
        if (!$invoice) {
            return;
        }

        $invoice_number = get_post_meta($invoice_id, 'invoice_number', true) ?: $invoice->post_title;

        echo '<div class="notice notice-info"><p>';
        echo '<strong>This line item will be linked to invoice: </strong>';
        echo '<a href="' . esc_url(get_edit_post_link($invoice_id)) . '">' . esc_html($invoice_number) . '</a>';
        echo '</p></div>';
    }

    /**
     * Save invoice relationship from transient.
     *
     * Runs early (priority 5) to set meta before Pods validation.
     */
    public function maybeSetInvoiceRelationship($post_id, $post, $update): void {
        $post_id = (int) $post_id;

        // Only on new posts
        if ($update) {
            return;
        }

        $user_id = get_current_user_id();
        $invoice_id = get_transient('bbab_pending_invoice_link_' . $user_id);
        $invoice_id = $invoice_id ? absint($invoice_id) : 0;

        // Set invoice relationship if provided and not already set
        if ($invoice_id > 0) {
            $existing = get_post_meta($post_id, 'related_invoice', true);
            if (empty($existing)) {
                update_post_meta($post_id, 'related_invoice', $invoice_id);

                Logger::debug('LineItemLinker', 'Set invoice relationship', [
                    'line_item_id' => $post_id,
                    'invoice_id' => $invoice_id,
                ]);
            }
        }

        // Clean up transient after use
        delete_transient('bbab_pending_invoice_link_' . $user_id);
    }
}
