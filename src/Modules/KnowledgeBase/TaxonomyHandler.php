<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\KnowledgeBase;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Handles KB taxonomy redirects.
 *
 * Redirects kb_category taxonomy archives to the main Knowledge Base page
 * with the appropriate filter applied.
 *
 * Phase 7.4 - Migrated from snippet #1485
 */
class TaxonomyHandler {

    /**
     * The KB page URL.
     */
    private const KB_PAGE_URL = '/client-dashboard/knowledge-base/';

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('template_redirect', [self::class, 'redirectTaxonomyArchives']);

        Logger::debug('KnowledgeBase', 'TaxonomyHandler registered');
    }

    /**
     * Redirect KB category taxonomy archives to the main KB page.
     *
     * When a user visits a kb_category archive (e.g., /kb_category/general/),
     * redirect them to the KB page with the category filter applied.
     *
     * Also handles cases where taxonomy archives are disabled (404) by checking
     * the URL pattern directly.
     */
    public static function redirectTaxonomyArchives(): void {
        // First try: standard taxonomy check
        if (is_tax('kb_category')) {
            $term = get_queried_object();

            if ($term && !is_wp_error($term)) {
                $redirect_url = home_url(self::KB_PAGE_URL . '?kb_cat=' . $term->term_id);
                wp_redirect($redirect_url, 301);
                exit;
            }
        }

        // Fallback: check if we're on a 404 with kb_category in the URL
        if (is_404()) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';

            // Check for /kb_category/slug/ pattern
            if (preg_match('#/kb_category/([^/]+)/?#', $request_uri, $matches)) {
                $slug = sanitize_title($matches[1]);
                $term = get_term_by('slug', $slug, 'kb_category');

                if ($term && !is_wp_error($term)) {
                    $redirect_url = home_url(self::KB_PAGE_URL . '?kb_cat=' . $term->term_id);
                    wp_redirect($redirect_url, 301);
                    exit;
                }

                // If term not found, just redirect to main KB page
                wp_redirect(home_url(self::KB_PAGE_URL), 301);
                exit;
            }
        }
    }
}
