<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Dashboard Knowledge Base link shortcode.
 *
 * Shortcode: [dashboard_kb_link]
 *
 * Displays a styled button linking to the Knowledge Base.
 *
 * Phase 7.4 - Migrated from snippet #1771
 */
class KBLink extends BaseShortcode {

    protected string $tag = 'dashboard_kb_link';
    protected bool $requires_org = false;
    protected bool $requires_login = true;

    /**
     * The KB page URL.
     */
    private const KB_URL = '/client-dashboard/knowledge-base/';

    /**
     * Render the shortcode output.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID (unused).
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'text' => 'Access Knowledge Base',
            'icon' => '📚',
        ]);

        $url = home_url(self::KB_URL);
        $text = esc_html($atts['text']);
        $icon = $atts['icon'];

        return sprintf(
            '<div class="dashboard-kb-link">
                <a href="%s" class="kb-hub-btn">%s %s</a>
            </div>
            <style>
                .dashboard-kb-link {
                    margin: 24px 0;
                    text-align: center;
                }
                .kb-hub-btn {
                    display: inline-block;
                    font-family: "Poppins", sans-serif;
                    font-size: 16px;
                    font-weight: 500;
                    color: white;
                    background: #467FF7;
                    padding: 12px 24px;
                    border-radius: 8px;
                    text-decoration: none;
                    transition: background 0.2s;
                }
                .kb-hub-btn:hover {
                    background: #3366cc;
                    color: white;
                }
            </style>',
            esc_url($url),
            $icon,
            $text
        );
    }
}
