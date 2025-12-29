<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns for KB Articles.
 *
 * Displays:
 * - Title
 * - Categories (taxonomy terms)
 * - Visibility (Public/Client-Specific)
 * - Client (org name if client-specific)
 * - Date
 *
 * Also provides a visibility filter.
 *
 * Phase 7.4 - Migrated from snippet #1492 (KB section only)
 */
class KBColumns {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_filter('manage_kb_article_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_kb_article_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'addFilters']);
        add_filter('posts_results', [self::class, 'filterByVisibility'], 10, 2);
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('KBColumns', 'Registered KB article admin column hooks');
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
        $new_columns['title'] = 'Article Title';
        $new_columns['kb_categories'] = 'Categories';
        $new_columns['visibility'] = 'Visibility';
        $new_columns['client'] = 'Client';
        $new_columns['date'] = 'Date';

        return $new_columns;
    }

    /**
     * Render custom column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function renderColumn(string $column, int $post_id): void {
        switch ($column) {
            case 'kb_categories':
                $terms = get_the_terms($post_id, 'kb_category');
                if ($terms && !is_wp_error($terms)) {
                    $term_links = [];
                    foreach ($terms as $term) {
                        $term_links[] = sprintf(
                            '<a href="%s">%s</a>',
                            esc_url(admin_url('edit.php?post_type=kb_article&kb_category=' . $term->slug)),
                            esc_html($term->name)
                        );
                    }
                    echo implode(', ', $term_links);
                } else {
                    echo '&mdash;';
                }
                break;

            case 'visibility':
                $visibility = self::getArticleVisibility($post_id);
                if ($visibility === 'Client-Specific') {
                    echo '<span class="kb-visibility kb-client-specific">Client-Specific</span>';
                } else {
                    echo '<span class="kb-visibility kb-public">Public</span>';
                }
                break;

            case 'client':
                $client = self::getArticleClient($post_id);
                if ($client) {
                    echo '<code class="org-shortcode">' . esc_html($client) . '</code>';
                } else {
                    echo '&mdash;';
                }
                break;
        }
    }

    /**
     * Determine article visibility based on category metadata.
     *
     * An article is client-specific if any of its categories has
     * the `is_client_specific` term meta set to a truthy value.
     *
     * @param int $post_id Post ID.
     * @return string 'Public' or 'Client-Specific'.
     */
    public static function getArticleVisibility(int $post_id): string {
        $terms = get_the_terms($post_id, 'kb_category');

        if (!$terms || is_wp_error($terms)) {
            return 'Public';
        }

        foreach ($terms as $term) {
            $is_client_specific = get_term_meta($term->term_id, 'is_client_specific', true);
            if ($is_client_specific === 'Yes' || $is_client_specific === '1' || $is_client_specific === true) {
                return 'Client-Specific';
            }
        }

        return 'Public';
    }

    /**
     * Get the client/org shortcode for a client-specific article.
     *
     * Matches the category slug against org shortcodes.
     *
     * @param int $post_id Post ID.
     * @return string|null Org shortcode or null if not client-specific.
     */
    public static function getArticleClient(int $post_id): ?string {
        $terms = get_the_terms($post_id, 'kb_category');

        if (!$terms || is_wp_error($terms)) {
            return null;
        }

        foreach ($terms as $term) {
            $is_client_specific = get_term_meta($term->term_id, 'is_client_specific', true);

            if ($is_client_specific === 'Yes' || $is_client_specific === '1' || $is_client_specific === true) {
                $slug = strtolower($term->slug);

                // Try to find matching organization by shortcode
                $orgs = get_posts([
                    'post_type' => 'client_organization',
                    'posts_per_page' => -1,
                    'post_status' => 'publish',
                ]);

                foreach ($orgs as $org) {
                    $shortcode = get_post_meta($org->ID, 'organization_shortcode', true);

                    if ($shortcode && stripos($slug, strtolower($shortcode)) !== false) {
                        return $shortcode; // Return shortcode, not post_title
                    }
                }

                // Fallback: return category name if no org match found
                return $term->name;
            }
        }

        return null;
    }

    /**
     * Add visibility filter dropdown.
     *
     * @param string $post_type Current post type.
     */
    public static function addFilters(string $post_type): void {
        if ($post_type !== 'kb_article') {
            return;
        }

        $selected_visibility = isset($_GET['visibility_filter']) ? sanitize_text_field($_GET['visibility_filter']) : '';

        echo '<select name="visibility_filter">';
        echo '<option value="">All Visibility</option>';
        echo '<option value="public"' . selected($selected_visibility, 'public', false) . '>Public</option>';
        echo '<option value="client-specific"' . selected($selected_visibility, 'client-specific', false) . '>Client-Specific</option>';
        echo '</select>';
    }

    /**
     * Filter results by visibility.
     *
     * Since visibility is calculated from category metadata, we filter
     * post-query rather than modifying the query itself.
     *
     * @param array     $posts The posts array.
     * @param \WP_Query $query The query object.
     * @return array Filtered posts.
     */
    public static function filterByVisibility(array $posts, \WP_Query $query): array {
        if (!is_admin() || !$query->is_main_query()) {
            return $posts;
        }

        if ($query->get('post_type') !== 'kb_article') {
            return $posts;
        }

        if (empty($_GET['visibility_filter'])) {
            return $posts;
        }

        $filter = sanitize_text_field($_GET['visibility_filter']);

        return array_filter($posts, function ($post) use ($filter) {
            $visibility = self::getArticleVisibility($post->ID);

            if ($filter === 'public') {
                return $visibility === 'Public';
            } elseif ($filter === 'client-specific') {
                return $visibility === 'Client-Specific';
            }

            return true;
        });
    }

    /**
     * Render admin styles for KB columns.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== 'kb_article') {
            return;
        }

        echo '<style>
            /* KB Visibility Colors */
            .kb-visibility {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 500;
            }
            .kb-public {
                background: #d5f5e3;
                color: #1e8449;
            }
            .kb-client-specific {
                background: #fef9e7;
                color: #b7950b;
            }

            /* Column widths */
            .column-visibility {
                width: 120px;
            }
            .column-client {
                width: 100px;
            }
            .column-kb_categories {
                width: 200px;
            }

            /* Shortcode styling */
            .org-shortcode {
                background: #f0f6fc;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }
        </style>';
    }
}
