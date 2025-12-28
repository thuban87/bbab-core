<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Line Item editor metaboxes.
 *
 * Displays on invoice_line_item edit screens:
 * - Time Entries linked to this line item (sidebar)
 *
 * Migrated from: WPCode Snippet #2039
 */
class LineItemMetabox {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('LineItemMetabox', 'Registered line item metabox hooks');
    }

    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        // Time Entries (sidebar)
        add_meta_box(
            'bbab_line_item_time_entries',
            'Source Time Entries',
            [self::class, 'renderTimeEntriesMetabox'],
            'invoice_line_item',
            'side',
            'default'
        );
    }

    /**
     * Render time entries metabox.
     *
     * Shows time entries that contributed to this line item.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderTimeEntriesMetabox(\WP_Post $post): void {
        $line_item_id = $post->ID;

        // Get the related milestone or SR
        $related_milestone = get_post_meta($line_item_id, 'related_milestone', true);
        $related_sr = get_post_meta($line_item_id, 'related_service_request', true);

        // Determine which TEs to show
        $time_entries = [];
        $source_label = '';

        if (!empty($related_milestone)) {
            // Get TEs for this milestone
            $time_entries = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_milestone',
                    'value' => $related_milestone,
                    'compare' => '=',
                ]],
                'orderby' => 'meta_value',
                'meta_key' => 'entry_date',
                'order' => 'DESC',
            ]);

            $ms_ref = get_post_meta($related_milestone, 'reference_number', true);
            $ms_name = get_post_meta($related_milestone, 'milestone_name', true) ?: get_the_title($related_milestone);
            $source_label = $ms_ref ? $ms_ref . ': ' . $ms_name : $ms_name;
        } elseif (!empty($related_sr)) {
            // Get TEs for this SR
            $time_entries = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_service_request',
                    'value' => $related_sr,
                    'compare' => '=',
                ]],
                'orderby' => 'meta_value',
                'meta_key' => 'entry_date',
                'order' => 'DESC',
            ]);

            $sr_ref = get_post_meta($related_sr, 'reference_number', true);
            $source_label = $sr_ref ?: 'SR-' . $related_sr;
        }

        // Calculate totals
        $total_hours = 0.0;
        $billable_hours = 0.0;
        $non_billable_hours = 0.0;

        foreach ($time_entries as $te) {
            $hours = (float) get_post_meta($te->ID, 'hours', true);
            $billable = get_post_meta($te->ID, 'billable', true);

            $total_hours += $hours;

            if ($billable === '0' || $billable === 0 || $billable === false) {
                $non_billable_hours += $hours;
            } else {
                $billable_hours += $hours;
            }
        }

        $count = count($time_entries);

        echo '<div class="bbab-line-item-te-metabox">';

        // Source indicator
        if (!empty($source_label)) {
            echo '<div class="bbab-source-label">';
            echo '<strong>Source:</strong> ' . esc_html($source_label);
            echo '</div>';
        }

        // Summary
        if ($count > 0) {
            echo '<div class="bbab-te-summary">';
            echo '<strong>' . $count . '</strong> time entr' . ($count === 1 ? 'y' : 'ies') . '<br>';
            echo '<strong>' . number_format($billable_hours, 2) . '</strong> billable hrs';
            if ($non_billable_hours > 0) {
                echo ' / <strong>' . number_format($non_billable_hours, 2) . '</strong> non-billable';
            }
            echo '</div>';

            // Time entries list
            echo '<div class="bbab-te-list">';
            foreach ($time_entries as $te) {
                self::renderTeRow($te);
            }
            echo '</div>';
        } else {
            echo '<div class="bbab-empty-state">';
            if (empty($related_milestone) && empty($related_sr)) {
                echo 'No milestone or SR linked to this line item.';
            } else {
                echo 'No time entries found.';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render a single time entry row.
     *
     * @param \WP_Post $te Time entry post.
     */
    private static function renderTeRow(\WP_Post $te): void {
        $te_id = $te->ID;
        $date = get_post_meta($te_id, 'entry_date', true);
        $te_ref = get_post_meta($te_id, 'reference_number', true);
        $hours = (float) get_post_meta($te_id, 'hours', true);
        $billable = get_post_meta($te_id, 'billable', true);
        $edit_link = get_edit_post_link($te_id);

        $formatted_date = $date ? date('M j', strtotime($date)) : '—';
        $is_billable = !($billable === '0' || $billable === 0 || $billable === false);

        echo '<div class="bbab-te-row">';
        echo '<div class="bbab-te-row-header">';
        echo '<span class="bbab-te-date">' . esc_html($formatted_date) . '</span>';
        echo '<span class="bbab-te-hours">' . number_format($hours, 2) . ' hrs';
        if (!$is_billable) {
            echo ' <span class="bbab-nc-badge">NC</span>';
        }
        echo '</span>';
        echo '</div>';
        echo '<div class="bbab-te-ref"><a href="' . esc_url($edit_link) . '">' . esc_html($te_ref ?: 'TE-' . $te_id) . '</a></div>';
        echo '</div>';
    }

    /**
     * Render admin styles for metaboxes.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'invoice_line_item') {
            return;
        }

        echo '<style>
            #bbab_line_item_time_entries .inside { margin: 0; padding: 0; }

            .bbab-line-item-te-metabox { font-size: 12px; }

            .bbab-source-label {
                background: #1e40af;
                color: white;
                padding: 10px 12px;
                font-size: 12px;
            }

            .bbab-te-summary {
                background: #f0f6fc;
                border-left: 4px solid #2271b1;
                padding: 10px 12px;
            }

            .bbab-te-list {
                max-height: 300px;
                overflow-y: auto;
            }

            .bbab-te-row {
                padding: 8px 12px;
                border-bottom: 1px solid #eee;
                background: white;
            }

            .bbab-te-row:hover {
                background: #f8fafc;
            }

            .bbab-te-row-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .bbab-te-date {
                color: #6b7280;
            }

            .bbab-te-hours {
                font-weight: 500;
            }

            .bbab-nc-badge {
                background: #d5f5e3;
                color: #1e8449;
                padding: 1px 5px;
                border-radius: 3px;
                font-size: 10px;
                margin-left: 4px;
            }

            .bbab-te-ref {
                margin-top: 3px;
            }

            .bbab-te-ref a {
                color: #1e40af;
                text-decoration: none;
            }

            .bbab-te-ref a:hover {
                text-decoration: underline;
            }

            .bbab-empty-state {
                padding: 15px;
                color: #6b7280;
                font-style: italic;
                text-align: center;
                background: #fafafa;
            }
        </style>';
    }
}
