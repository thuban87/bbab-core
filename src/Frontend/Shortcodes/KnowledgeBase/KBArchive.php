<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\KnowledgeBase;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Utils\UserContext;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Knowledge Base archive and single article shortcode.
 *
 * Shortcode: [knowledge_base]
 *
 * Displays:
 * - Article listing with sidebar categories, search, and pagination
 * - Single article view when ?article=ID is in URL
 *
 * Features:
 * - Org-specific categories: Only shows client's own org category + public categories
 * - Category filtering via ?kb_cat=
 * - Search via ?kb_search=
 * - Pagination via ?kb_page=
 *
 * Phase 7.4 - Migrated from snippet #1491
 */
class KBArchive extends BaseShortcode {

    protected string $tag = 'knowledge_base';
    protected bool $requires_org = true;
    protected bool $requires_login = true;

    /**
     * Number of articles per page.
     */
    private const POSTS_PER_PAGE = 20;

    /**
     * Render the shortcode output.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID.
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        // Get org shortcode for matching client-specific categories
        $org_code = strtolower(get_post_meta($org_id, 'organization_shortcode', true) ?: '');

        // Get URL parameters
        $article_id = isset($_GET['article']) ? absint($_GET['article']) : 0;
        $search = isset($_GET['kb_search']) ? sanitize_text_field($_GET['kb_search']) : '';
        $category_filter = isset($_GET['kb_cat']) ? absint($_GET['kb_cat']) : 0;
        $paged = isset($_GET['kb_page']) ? max(1, absint($_GET['kb_page'])) : 1;

        // Build category visibility data
        $category_data = $this->getCategoryData($org_code);
        $visible_categories = $category_data['visible'];
        $other_client_ids = $category_data['other_client_ids'];

        // Check if viewing single article
        $single_article = null;
        if ($article_id > 0) {
            $single_article = $this->getSingleArticle($article_id, $other_client_ids);
        }

        // Build base URL for links
        $base_url = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: home_url('/client-dashboard/knowledge-base/');
        $list_url = $category_filter ? add_query_arg('kb_cat', $category_filter, $base_url) : $base_url;

        // If single article view
        if ($single_article) {
            return $this->renderSingleArticle($single_article, $visible_categories, $category_filter, $base_url, $list_url);
        }

        // Otherwise render article list
        return $this->renderArchive(
            $visible_categories,
            $other_client_ids,
            $search,
            $category_filter,
            $paged,
            $base_url
        );
    }

    /**
     * Get category visibility data.
     *
     * Returns visible categories for this user and IDs of other client categories to exclude.
     *
     * @param string $org_code User's organization shortcode (lowercase).
     * @return array{visible: array, other_client_ids: array, user_client_id: int|null, child_categories: array}
     */
    private function getCategoryData(string $org_code): array {
        $all_categories = get_terms([
            'taxonomy' => 'kb_category',
            'hide_empty' => false,
            'parent' => 0,
        ]);

        if (is_wp_error($all_categories)) {
            return [
                'visible' => [],
                'other_client_ids' => [],
                'user_client_id' => null,
                'child_categories' => [],
            ];
        }

        $visible_categories = [];
        $other_client_ids = [];
        $user_client_id = null;

        foreach ($all_categories as $cat) {
            $is_client_specific = get_term_meta($cat->term_id, 'is_client_specific', true);

            if ($is_client_specific) {
                // Client-specific category - check if it belongs to this org
                if ($org_code && stripos($cat->slug, $org_code) !== false) {
                    $visible_categories[] = $cat;
                    $user_client_id = $cat->term_id;
                } else {
                    $other_client_ids[] = $cat->term_id;
                }
            } else {
                // Public category - always visible
                $visible_categories[] = $cat;
            }
        }

        // Get child categories for visible parents
        $child_categories = [];
        foreach ($visible_categories as $parent_cat) {
            $children = get_terms([
                'taxonomy' => 'kb_category',
                'hide_empty' => false,
                'parent' => $parent_cat->term_id,
            ]);

            if (!is_wp_error($children) && !empty($children)) {
                $child_categories[$parent_cat->term_id] = $children;
            }
        }

        return [
            'visible' => $visible_categories,
            'other_client_ids' => $other_client_ids,
            'user_client_id' => $user_client_id,
            'child_categories' => $child_categories,
        ];
    }

    /**
     * Get a single article, checking access permissions.
     *
     * @param int   $article_id       Article post ID.
     * @param array $other_client_ids Category IDs belonging to other clients.
     * @return \WP_Post|null Article post or null if not accessible.
     */
    private function getSingleArticle(int $article_id, array $other_client_ids): ?\WP_Post {
        $article = get_post($article_id);

        if (!$article || $article->post_type !== 'kb_article' || $article->post_status !== 'publish') {
            return null;
        }

        // Admins can see everything
        if (current_user_can('manage_options')) {
            return $article;
        }

        // Check if article is in a client-specific category the user shouldn't see
        $article_cats = get_the_terms($article_id, 'kb_category');

        if ($article_cats && !is_wp_error($article_cats)) {
            foreach ($article_cats as $cat) {
                // Direct match to other client category
                if (in_array($cat->term_id, $other_client_ids, true)) {
                    Logger::debug('KnowledgeBase', 'Access denied to article', [
                        'article_id' => $article_id,
                        'blocked_cat' => $cat->term_id,
                    ]);
                    return null;
                }

                // Check parent category too
                if ($cat->parent && in_array($cat->parent, $other_client_ids, true)) {
                    return null;
                }
            }
        }

        return $article;
    }

    /**
     * Render single article view.
     *
     * @param \WP_Post $article            The article post.
     * @param array    $visible_categories Visible categories for sidebar.
     * @param int      $category_filter    Current category filter.
     * @param string   $base_url           Base URL for links.
     * @param string   $list_url           URL to return to list.
     * @return string HTML output.
     */
    private function renderSingleArticle(
        \WP_Post $article,
        array $visible_categories,
        int $category_filter,
        string $base_url,
        string $list_url
    ): string {
        $article_cats = get_the_terms($article->ID, 'kb_category');
        $cat_names = ($article_cats && !is_wp_error($article_cats))
            ? wp_list_pluck($article_cats, 'name')
            : [];
        $last_updated = get_the_modified_date('F j, Y', $article->ID);

        ob_start();
        ?>
        <div class="kb-archive">
            <?php echo $this->renderHeader($base_url, '', $category_filter, true, $list_url); ?>

            <div class="kb-layout">
                <?php echo $this->renderSidebar($visible_categories, [], $category_filter, '', false, $base_url); ?>

                <main class="kb-articles">
                    <article class="kb-single-article">
                        <div class="article-breadcrumb">
                            <a href="<?php echo esc_url($list_url); ?>">&larr; Back to articles</a>
                        </div>

                        <h1 class="single-article-title"><?php echo esc_html($article->post_title); ?></h1>

                        <div class="single-article-meta">
                            <?php if ($cat_names): ?>
                                <span class="meta-categories"><?php echo esc_html(implode(' &rsaquo; ', $cat_names)); ?></span>
                                <span class="meta-separator">&bull;</span>
                            <?php endif; ?>
                            <span class="meta-updated">Updated <?php echo esc_html($last_updated); ?></span>
                        </div>

                        <div class="single-article-content">
                            <?php echo apply_filters('the_content', $article->post_content); ?>
                        </div>
                    </article>
                </main>
            </div>
        </div>

        <?php echo $this->renderStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render article archive/listing view.
     *
     * @param array  $visible_categories Visible categories for sidebar.
     * @param array  $other_client_ids   Category IDs to exclude.
     * @param string $search             Search query.
     * @param int    $category_filter    Current category filter.
     * @param int    $paged              Current page number.
     * @param string $base_url           Base URL for links.
     * @return string HTML output.
     */
    private function renderArchive(
        array $visible_categories,
        array $other_client_ids,
        string $search,
        int $category_filter,
        int $paged,
        string $base_url
    ): string {
        // Build query args
        $args = [
            'post_type' => 'kb_article',
            'post_status' => 'publish',
            'posts_per_page' => self::POSTS_PER_PAGE,
            'paged' => $paged,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if ($search) {
            $args['s'] = $search;
        }

        // Category filter
        $tax_query = [];

        if ($category_filter > 0) {
            $tax_query[] = [
                'taxonomy' => 'kb_category',
                'field' => 'term_id',
                'terms' => $category_filter,
                'include_children' => true,
            ];
        }

        // Exclude other client categories
        if (!empty($other_client_ids)) {
            $tax_query[] = [
                'taxonomy' => 'kb_category',
                'field' => 'term_id',
                'terms' => $other_client_ids,
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($tax_query)) {
            if (count($tax_query) > 1) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        $query = new \WP_Query($args);
        $total_pages = $query->max_num_pages;

        // Get child categories data
        $child_categories = [];
        foreach ($visible_categories as $parent_cat) {
            $children = get_terms([
                'taxonomy' => 'kb_category',
                'hide_empty' => false,
                'parent' => $parent_cat->term_id,
            ]);
            if (!is_wp_error($children) && !empty($children)) {
                $child_categories[$parent_cat->term_id] = $children;
            }
        }

        ob_start();
        ?>
        <div class="kb-archive">
            <?php echo $this->renderHeader($base_url, $search, $category_filter, false, ''); ?>

            <div class="kb-layout">
                <?php echo $this->renderSidebar($visible_categories, $child_categories, $category_filter, $search, false, $base_url); ?>

                <main class="kb-articles">
                    <?php if ($search): ?>
                        <p class="kb-search-results">
                            <?php echo esc_html($query->found_posts); ?> result<?php echo $query->found_posts !== 1 ? 's' : ''; ?>
                            for "<?php echo esc_html($search); ?>"
                            <a href="<?php echo esc_url($category_filter ? add_query_arg('kb_cat', $category_filter, $base_url) : $base_url); ?>" class="clear-search">Clear</a>
                        </p>
                    <?php endif; ?>

                    <?php if ($query->have_posts()): ?>
                        <?php while ($query->have_posts()): $query->the_post(); ?>
                            <?php
                            $article_id = get_the_ID();
                            $article_cats = get_the_terms($article_id, 'kb_category');
                            $cat_names = ($article_cats && !is_wp_error($article_cats))
                                ? wp_list_pluck($article_cats, 'name')
                                : [];
                            $excerpt = get_the_excerpt() ?: wp_trim_words(get_the_content(), 25);
                            $article_url = add_query_arg('article', $article_id, $base_url);
                            if ($category_filter) {
                                $article_url = add_query_arg('kb_cat', $category_filter, $article_url);
                            }
                            ?>
                            <article class="kb-article-card">
                                <h2 class="article-title">
                                    <a href="<?php echo esc_url($article_url); ?>">
                                        <?php the_title(); ?>
                                    </a>
                                </h2>
                                <div class="article-meta">
                                    <?php echo esc_html(implode(' &rsaquo; ', $cat_names)); ?>
                                </div>
                                <div class="article-excerpt">
                                    <?php echo esc_html($excerpt); ?>
                                </div>
                            </article>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>

                        <?php echo $this->renderPagination($paged, $total_pages, $base_url, $category_filter, $search); ?>

                    <?php else: ?>
                        <p class="no-articles">No articles found.</p>
                    <?php endif; ?>
                </main>
            </div>
        </div>

        <?php echo $this->renderStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the header with title and search form.
     *
     * @param string $base_url        Base URL for form action.
     * @param string $search          Current search query.
     * @param int    $category_filter Current category filter.
     * @param bool   $is_single       Whether viewing single article.
     * @param string $list_url        URL to list view.
     * @return string HTML output.
     */
    private function renderHeader(
        string $base_url,
        string $search,
        int $category_filter,
        bool $is_single,
        string $list_url
    ): string {
        ob_start();
        ?>
        <div class="kb-header">
            <h1 class="kb-title">
                <?php if ($is_single): ?>
                    <a href="<?php echo esc_url($list_url); ?>" class="kb-back-link">Knowledge Base</a>
                <?php else: ?>
                    Knowledge Base
                <?php endif; ?>
            </h1>
            <form class="kb-search-form" method="get" action="<?php echo esc_url($base_url); ?>">
                <input type="text"
                       name="kb_search"
                       placeholder="Search articles..."
                       value="<?php echo esc_attr($search); ?>"
                       class="kb-search-input">
                <?php if ($category_filter): ?>
                    <input type="hidden" name="kb_cat" value="<?php echo esc_attr($category_filter); ?>">
                <?php endif; ?>
                <button type="submit" class="kb-search-btn">Search</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the sidebar with categories.
     *
     * @param array  $visible_categories Visible top-level categories.
     * @param array  $child_categories   Child categories keyed by parent ID.
     * @param int    $category_filter    Current category filter.
     * @param string $search             Current search query.
     * @param bool   $is_single          Whether viewing single article.
     * @param string $base_url           Base URL for links.
     * @return string HTML output.
     */
    private function renderSidebar(
        array $visible_categories,
        array $child_categories,
        int $category_filter,
        string $search,
        bool $is_single,
        string $base_url
    ): string {
        ob_start();
        ?>
        <aside class="kb-sidebar">
            <h3>Categories</h3>
            <ul class="kb-categories">
                <li>
                    <a href="<?php echo esc_url($base_url); ?>"
                       class="<?php echo (!$category_filter && !$search && !$is_single) ? 'active' : ''; ?>">
                        All Articles
                    </a>
                </li>
                <?php foreach ($visible_categories as $cat): ?>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg('kb_cat', $cat->term_id, $base_url)); ?>"
                           class="<?php echo ($category_filter === (int)$cat->term_id) ? 'active' : ''; ?>">
                            <?php echo esc_html($cat->name); ?>
                        </a>
                        <?php if (isset($child_categories[$cat->term_id])): ?>
                            <ul class="kb-subcategories">
                                <?php foreach ($child_categories[$cat->term_id] as $child): ?>
                                    <li>
                                        <a href="<?php echo esc_url(add_query_arg('kb_cat', $child->term_id, $base_url)); ?>"
                                           class="<?php echo ($category_filter === (int)$child->term_id) ? 'active' : ''; ?>">
                                            <?php echo esc_html($child->name); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>
        <?php
        return ob_get_clean();
    }

    /**
     * Render pagination.
     *
     * @param int    $paged           Current page.
     * @param int    $total_pages     Total pages.
     * @param string $base_url        Base URL.
     * @param int    $category_filter Current category filter.
     * @param string $search          Current search query.
     * @return string HTML output.
     */
    private function renderPagination(
        int $paged,
        int $total_pages,
        string $base_url,
        int $category_filter,
        string $search
    ): string {
        if ($total_pages <= 1) {
            return '';
        }

        ob_start();
        ?>
        <div class="kb-pagination">
            <?php if ($paged > 1): ?>
                <?php
                $prev_url = add_query_arg('kb_page', $paged - 1, $base_url);
                if ($category_filter) $prev_url = add_query_arg('kb_cat', $category_filter, $prev_url);
                if ($search) $prev_url = add_query_arg('kb_search', $search, $prev_url);
                ?>
                <a href="<?php echo esc_url($prev_url); ?>" class="pagination-prev">&larr; Previous</a>
            <?php endif; ?>

            <span class="pagination-info">Page <?php echo esc_html($paged); ?> of <?php echo esc_html($total_pages); ?></span>

            <?php if ($paged < $total_pages): ?>
                <?php
                $next_url = add_query_arg('kb_page', $paged + 1, $base_url);
                if ($category_filter) $next_url = add_query_arg('kb_cat', $category_filter, $next_url);
                if ($search) $next_url = add_query_arg('kb_search', $search, $next_url);
                ?>
                <a href="<?php echo esc_url($next_url); ?>" class="pagination-next">Next &rarr;</a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the CSS styles.
     *
     * @return string CSS styles.
     */
    private function renderStyles(): string {
        return '
        <style>
            .kb-archive {
                max-width: 1200px;
                margin: 0 auto;
            }
            .kb-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 32px;
                flex-wrap: wrap;
                gap: 16px;
            }
            .kb-title {
                font-family: "Poppins", sans-serif;
                font-size: 36px;
                font-weight: 600;
                color: #1C244B;
                margin: 0;
            }
            .kb-title a.kb-back-link {
                color: #1C244B;
                text-decoration: none;
            }
            .kb-title a.kb-back-link:hover {
                color: #467FF7;
            }
            .kb-search-form {
                display: flex;
                gap: 8px;
            }
            .kb-search-input {
                padding: 10px 16px;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                width: 300px;
            }
            .kb-search-btn {
                background: #467FF7;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 6px;
                font-family: "Poppins", sans-serif;
                cursor: pointer;
            }
            .kb-search-btn:hover {
                background: #3366cc;
            }
            .kb-layout {
                display: flex;
                gap: 40px;
            }
            .kb-sidebar {
                width: 220px;
                flex-shrink: 0;
            }
            .kb-sidebar h3 {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 16px 0;
            }
            .kb-categories {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .kb-categories > li {
                margin-bottom: 8px;
            }
            .kb-categories a {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #324A6D;
                text-decoration: none;
                display: block;
                padding: 4px 0;
            }
            .kb-categories a:hover,
            .kb-categories a.active {
                color: #467FF7;
            }
            .kb-categories a.active {
                font-weight: 500;
            }
            .kb-subcategories {
                list-style: none;
                padding-left: 16px;
                margin: 4px 0 0 0;
            }
            .kb-subcategories li {
                margin-bottom: 4px;
            }
            .kb-subcategories a {
                font-size: 13px;
            }
            .kb-articles {
                flex: 1;
                min-width: 0;
            }
            .kb-search-results {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #324A6D;
                margin: 0 0 24px 0;
            }
            .clear-search {
                color: #467FF7;
                margin-left: 8px;
            }
            .kb-article-card {
                background: #F3F5F8;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 16px;
            }
            .kb-article-card .article-title {
                font-family: "Poppins", sans-serif;
                font-size: 20px;
                font-weight: 600;
                margin: 0 0 8px 0;
            }
            .kb-article-card .article-title a {
                color: #1C244B;
                text-decoration: none;
            }
            .kb-article-card .article-title a:hover {
                color: #467FF7;
            }
            .article-meta {
                font-family: "Poppins", sans-serif;
                font-size: 12px;
                color: #467FF7;
                margin-bottom: 8px;
            }
            .article-excerpt {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #324A6D;
                line-height: 1.5;
            }
            .no-articles {
                font-family: "Poppins", sans-serif;
                text-align: center;
                color: #324A6D;
                padding: 48px;
                background: #F3F5F8;
                border-radius: 12px;
            }

            /* Single Article Styles */
            .kb-single-article {
                background: #fff;
            }
            .article-breadcrumb {
                margin-bottom: 24px;
            }
            .article-breadcrumb a {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #467FF7;
                text-decoration: none;
            }
            .article-breadcrumb a:hover {
                text-decoration: underline;
            }
            .single-article-title {
                font-family: "Poppins", sans-serif;
                font-size: 32px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 12px 0;
            }
            .single-article-meta {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #7f8c8d;
                margin-bottom: 32px;
            }
            .single-article-meta .meta-categories {
                color: #467FF7;
            }
            .single-article-meta .meta-separator {
                margin: 0 8px;
            }
            .single-article-content {
                font-family: "Poppins", sans-serif;
                font-size: 16px;
                color: #324A6D;
                line-height: 1.7;
            }
            .single-article-content h2 {
                font-size: 24px;
                font-weight: 600;
                color: #1C244B;
                margin: 32px 0 16px 0;
            }
            .single-article-content h3 {
                font-size: 20px;
                font-weight: 600;
                color: #1C244B;
                margin: 24px 0 12px 0;
            }
            .single-article-content p {
                margin: 0 0 16px 0;
            }
            .single-article-content ul,
            .single-article-content ol {
                margin: 0 0 16px 24px;
            }
            .single-article-content li {
                margin-bottom: 8px;
            }
            .single-article-content img {
                max-width: 100%;
                height: auto;
                border-radius: 8px;
                margin: 16px 0;
            }
            .single-article-content code {
                background: #f5f5f5;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 14px;
            }
            .single-article-content pre {
                background: #f5f5f5;
                padding: 16px;
                border-radius: 8px;
                overflow-x: auto;
                margin: 16px 0;
            }
            .single-article-content a {
                color: #467FF7;
            }

            /* Pagination Styles */
            .kb-pagination {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 24px;
                margin-top: 32px;
                padding-top: 24px;
                border-top: 1px solid #e0e0e0;
            }
            .kb-pagination a {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #467FF7;
                text-decoration: none;
                padding: 8px 16px;
                border: 1px solid #467FF7;
                border-radius: 6px;
            }
            .kb-pagination a:hover {
                background: #467FF7;
                color: white;
            }
            .pagination-info {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #7f8c8d;
            }

            /* Mobile responsive */
            @media (max-width: 768px) {
                .kb-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .kb-search-input {
                    width: 100%;
                    max-width: 250px;
                }
                .kb-layout {
                    flex-direction: column;
                }
                .kb-sidebar {
                    width: 100%;
                    margin-bottom: 24px;
                }
                .kb-categories {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                .kb-categories > li {
                    margin-bottom: 0;
                }
                .kb-subcategories {
                    display: none;
                }
                .single-article-title {
                    font-size: 26px;
                }
                .kb-pagination {
                    flex-wrap: wrap;
                    gap: 12px;
                }
            }
        </style>';
    }
}
