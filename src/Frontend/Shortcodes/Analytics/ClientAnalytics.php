<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Analytics;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Analytics\GA4Service;
use BBAB\ServiceCenter\Modules\Analytics\PageSpeedService;

/**
 * Client Analytics Shortcode.
 *
 * Multi-mode shortcode for displaying analytics data.
 * All data is read from cache only - never triggers API calls.
 *
 * Shortcode: [client_analytics]
 * Migrated from: WPCode Snippet #2028
 *
 * Modes:
 *   quick_stats      - 4 key metrics in a row with trends (Quick Looks tab)
 *   top_pages        - Top 5 visited pages list
 *   cwv_card         - Core Web Vitals card (desktop)
 *   traffic_card     - Full traffic stats grid
 *   updated_timestamp - Just the "Updated X" text
 *   traffic_section  - Full traffic section with sources & devices
 *   performance_section - Desktop + mobile CWV comparison
 */
class ClientAnalytics extends BaseShortcode {

    protected string $tag = 'client_analytics';

    /**
     * Render the shortcode output.
     *
     * @param array $atts   Shortcode attributes
     * @param int   $org_id Organization ID
     * @return string HTML output
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'mode' => 'quick_stats',
            'limit' => 5
        ]);

        $mode = $atts['mode'];
        $limit = intval($atts['limit']);

        // Check if org has GA4 configured (except for CWV-only modes)
        if (!in_array($mode, ['cwv_card', 'performance_section'])) {
            $property_id = get_post_meta($org_id, 'ga4_property_id', true);
            if (empty($property_id)) {
                if ($this->isSimulating() || current_user_can('manage_options')) {
                    return $this->errorCard('No GA4 Property ID configured for this organization.');
                }
                return '';
            }
        }

        switch ($mode) {
            case 'quick_stats':
                return $this->renderQuickStats($org_id);
            case 'top_pages':
                return $this->renderTopPages($org_id, $limit);
            case 'cwv_card':
                return $this->renderCwvCard($org_id);
            case 'traffic_card':
                return $this->renderTrafficCard($org_id);
            case 'updated_timestamp':
                return $this->renderUpdatedTimestamp($org_id);
            case 'traffic_section':
                return $this->renderTrafficSection($org_id);
            case 'performance_section':
                return $this->renderPerformanceSection($org_id);
            default:
                return $this->errorCard('Invalid analytics mode.');
        }
    }

    /**
     * Quick Stats - 4 key metrics in a row with trends.
     */
    private function renderQuickStats(int $org_id): string {
        $data = GA4Service::getData($org_id);

        if (!$data) {
            return $this->errorCard('Traffic data not yet available. Check back soon!');
        }

        $metrics = [
            ['key' => 'new_users', 'label' => 'New Visitors'],
            ['key' => 'sessions', 'label' => 'Sessions'],
            ['key' => 'page_views', 'label' => 'Page Views'],
            ['key' => 'engagement_rate', 'label' => 'Engagement', 'format' => 'percent'],
        ];

        $updated_time = wp_date('n/j/y @ g:ia', $data['fetched_at']);

        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-quick-stats-bar">
            <div class="bbab-quick-stats-grid">
                <?php foreach ($metrics as $metric):
                    $value = $data['current_period'][$metric['key']] ?? 0;
                    $trend = $data['trends'][$metric['key']] ?? null;
                    $format = $metric['format'] ?? 'number';
                    $display = $format === 'percent' ? $value . '%' : number_format($value);
                ?>
                    <div class="bbab-quick-stat">
                        <div class="bbab-quick-stat-value"><?php echo esc_html($display); ?></div>
                        <div class="bbab-quick-stat-label"><?php echo esc_html($metric['label']); ?></div>
                        <?php if ($trend !== null): ?>
                            <div class="bbab-trend-badge <?php echo $trend >= 0 ? 'trend-up' : 'trend-down'; ?>">
                                <?php echo $trend >= 0 ? '↑' : '↓'; ?> <?php echo esc_html(abs($trend)); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="bbab-stats-updated">Updated <?php echo esc_html($updated_time); ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Top Pages List.
     */
    private function renderTopPages(int $org_id, int $limit): string {
        $data = GA4Service::getTopPages($org_id, $limit);

        if (!$data || empty($data['pages'])) {
            return $this->errorCard('Page data not yet available.');
        }

        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-top-pages">
            <div class="bbab-stat-label">Most Visited Pages <span class="bbab-stat-period">(Last 30 Days)</span></div>
            <ol class="bbab-pages-list">
                <?php foreach ($data['pages'] as $page): ?>
                    <li>
                        <span class="bbab-page-path"><?php echo esc_html($page['display_path']); ?></span>
                        <span class="bbab-page-stats">
                            <span class="bbab-page-views"><?php echo esc_html(number_format($page['views'])); ?> views</span>
                            <span class="bbab-page-users"><?php echo esc_html(number_format($page['users'])); ?> users</span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Core Web Vitals Card (desktop only).
     */
    private function renderCwvCard(int $org_id): string {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            if ($this->isSimulating() || current_user_can('manage_options')) {
                return $this->errorCard('No site URL configured for PageSpeed.');
            }
            return '';
        }

        $data = PageSpeedService::getData($org_id);

        if (!$data || !$data['desktop']) {
            return $this->errorCard('Performance data not yet available. Check back soon!');
        }

        $desktop = $data['desktop'];
        $perf = $desktop['performance_score'];
        $perf_rating = PageSpeedService::getScoreRating($perf);

        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-cwv-card">
            <div class="bbab-stat-label">Site Performance <span class="bbab-stat-period">(Desktop)</span></div>
            <div class="bbab-cwv-metrics">
                <div class="bbab-cwv-metric bbab-cwv-<?php echo esc_attr($perf_rating); ?>">
                    <span class="bbab-cwv-value"><?php echo $perf !== null ? esc_html($perf) : 'N/A'; ?></span>
                    <span class="bbab-cwv-label">Performance</span>
                </div>
                <div class="bbab-cwv-metric bbab-cwv-<?php echo esc_attr($desktop['lcp_rating']); ?>">
                    <span class="bbab-cwv-value"><?php echo $desktop['lcp'] !== null ? esc_html($desktop['lcp']) . 's' : 'N/A'; ?></span>
                    <span class="bbab-cwv-label">LCP</span>
                </div>
                <div class="bbab-cwv-metric bbab-cwv-<?php echo esc_attr($desktop['cls_rating']); ?>">
                    <span class="bbab-cwv-value"><?php echo $desktop['cls'] !== null ? esc_html($desktop['cls']) : 'N/A'; ?></span>
                    <span class="bbab-cwv-label">CLS</span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Full Traffic Card (for Analytics tab).
     */
    private function renderTrafficCard(int $org_id): string {
        $data = GA4Service::getData($org_id);

        if (!$data) {
            return $this->errorCard('Traffic data not yet available.');
        }

        $metrics = [
            ['key' => 'active_users', 'label' => 'Active Users', 'format' => 'number'],
            ['key' => 'new_users', 'label' => 'New Users', 'format' => 'number'],
            ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number'],
            ['key' => 'page_views', 'label' => 'Page Views', 'format' => 'number'],
            ['key' => 'engagement_rate', 'label' => 'Engagement', 'format' => 'percent'],
            ['key' => 'avg_session_duration', 'label' => 'Avg. Session', 'format' => 'time'],
        ];

        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-traffic-card">
            <div class="bbab-stat-label">Traffic Overview <span class="bbab-stat-period">(Last 30 Days)</span></div>
            <div class="bbab-traffic-grid">
                <?php foreach ($metrics as $metric):
                    $value = $data['current_period'][$metric['key']] ?? 0;
                    $trend = $data['trends'][$metric['key']] ?? null;

                    if ($metric['format'] === 'percent') {
                        $display = $value . '%';
                    } elseif ($metric['format'] === 'time') {
                        $display = gmdate('i:s', (int)$value);
                    } else {
                        $display = number_format($value);
                    }
                ?>
                    <div class="bbab-traffic-metric">
                        <div class="bbab-traffic-value"><?php echo esc_html($display); ?></div>
                        <div class="bbab-traffic-label"><?php echo esc_html($metric['label']); ?></div>
                        <?php if ($trend !== null): ?>
                            <div class="bbab-trend-badge <?php echo $trend >= 0 ? 'trend-up' : 'trend-down'; ?>">
                                <?php echo $trend >= 0 ? '↑' : '↓'; ?> <?php echo esc_html(abs($trend)); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Standalone Updated Timestamp.
     */
    private function renderUpdatedTimestamp(int $org_id): string {
        $data = GA4Service::getData($org_id);

        if (!$data || empty($data['fetched_at'])) {
            return '';
        }

        $updated_time = wp_date('n/j/y @ g:ia', $data['fetched_at']);

        return '<div class="bbab-stats-updated">Analytics updated ' . esc_html($updated_time) . '</div>';
    }

    /**
     * Traffic Section - Overview + Sources + Devices.
     */
    private function renderTrafficSection(int $org_id): string {
        $ga_data = GA4Service::getData($org_id);
        $sources_data = GA4Service::getTrafficSources($org_id, 5);
        $devices_data = GA4Service::getDevices($org_id);

        ob_start();
        ?>
        <div class="bbab-section bbab-traffic-section">
            <h3 class="bbab-section-title">Traffic Overview</h3>

            <?php if ($ga_data): ?>
                <?php echo $this->renderTrafficCard($org_id); ?>
            <?php else: ?>
                <?php echo $this->errorCard('Traffic data not yet available.'); ?>
            <?php endif; ?>

            <div class="bbab-two-column">
                <!-- Traffic Sources -->
                <div class="bbab-analytics-card">
                    <div class="bbab-card-title">Traffic Sources</div>
                    <?php if ($sources_data && !empty($sources_data['sources'])): ?>
                        <div class="bbab-sources-list">
                            <?php foreach ($sources_data['sources'] as $source): ?>
                                <div class="bbab-source-row">
                                    <div class="bbab-source-name"><?php echo esc_html($source['channel']); ?></div>
                                    <div class="bbab-source-bar-container">
                                        <div class="bbab-source-bar" style="width: <?php echo esc_attr($source['percentage']); ?>%;"></div>
                                    </div>
                                    <div class="bbab-source-stats">
                                        <span class="bbab-source-sessions"><?php echo esc_html(number_format($source['sessions'])); ?></span>
                                        <span class="bbab-source-percent"><?php echo esc_html($source['percentage']); ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="bbab-no-data">Source data not yet available</p>
                    <?php endif; ?>
                </div>

                <!-- Devices -->
                <div class="bbab-analytics-card">
                    <div class="bbab-card-title">Devices</div>
                    <?php if ($devices_data && !empty($devices_data['devices'])): ?>
                        <div class="bbab-devices-grid">
                            <?php foreach ($devices_data['devices'] as $device): ?>
                                <div class="bbab-device-item">
                                    <div class="bbab-device-icon"><?php echo esc_html($device['icon']); ?></div>
                                    <div class="bbab-device-info">
                                        <div class="bbab-device-category"><?php echo esc_html($device['category']); ?></div>
                                        <div class="bbab-device-percentage"><?php echo esc_html($device['percentage']); ?>%</div>
                                    </div>
                                    <div class="bbab-device-sessions"><?php echo esc_html(number_format($device['sessions'])); ?> sessions</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="bbab-no-data">Device data not yet available</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($ga_data): ?>
                <div class="bbab-stats-updated">Updated <?php echo esc_html(wp_date('n/j/y @ g:ia', $ga_data['fetched_at'])); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Performance Section - CWV Desktop + Mobile.
     */
    private function renderPerformanceSection(int $org_id): string {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            if ($this->isSimulating() || current_user_can('manage_options')) {
                return $this->errorCard('No site URL configured for PageSpeed.');
            }
            return '';
        }

        $data = PageSpeedService::getDataFull($org_id);

        if (!$data) {
            return '<div class="bbab-section bbab-performance-section">
                <h3 class="bbab-section-title">Site Performance</h3>' .
                $this->errorCard('Performance data not yet available. Check back soon!') .
            '</div>';
        }

        ob_start();
        ?>
        <div class="bbab-section bbab-performance-section">
            <h3 class="bbab-section-title">Site Performance</h3>

            <div class="bbab-two-column">
                <?php foreach (['desktop' => '🖥️ Desktop', 'mobile' => '📱 Mobile'] as $strategy => $label):
                    $metrics = $data[$strategy] ?? null;

                    if (!$metrics): ?>
                        <div class="bbab-analytics-card">
                            <div class="bbab-card-title"><?php echo $label; ?></div>
                            <p class="bbab-no-data">Data not yet available</p>
                        </div>
                    <?php continue; endif;

                    $perf = $metrics['performance_score'];
                    $perf_rating = PageSpeedService::getScoreRating($perf);
                ?>
                    <div class="bbab-analytics-card">
                        <div class="bbab-card-title"><?php echo $label; ?></div>
                        <div class="bbab-perf-score-row">
                            <div class="bbab-perf-score bbab-cwv-<?php echo esc_attr($perf_rating); ?>">
                                <span class="bbab-perf-score-value"><?php echo $perf !== null ? esc_html($perf) : 'N/A'; ?></span>
                                <span class="bbab-perf-score-label">Performance Score</span>
                            </div>
                        </div>
                        <div class="bbab-cwv-details">
                            <div class="bbab-cwv-detail-item bbab-cwv-<?php echo esc_attr($metrics['lcp_rating']); ?>">
                                <span class="bbab-cwv-detail-label">LCP</span>
                                <span class="bbab-cwv-detail-value"><?php echo $metrics['lcp'] !== null ? esc_html($metrics['lcp']) . 's' : 'N/A'; ?></span>
                                <span class="bbab-cwv-detail-desc">Largest Contentful Paint</span>
                            </div>
                            <div class="bbab-cwv-detail-item bbab-cwv-<?php echo esc_attr($metrics['cls_rating']); ?>">
                                <span class="bbab-cwv-detail-label">CLS</span>
                                <span class="bbab-cwv-detail-value"><?php echo $metrics['cls'] !== null ? esc_html($metrics['cls']) : 'N/A'; ?></span>
                                <span class="bbab-cwv-detail-desc">Cumulative Layout Shift</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($data): ?>
                <div class="bbab-stats-updated">Updated <?php echo esc_html(wp_date('n/j/y @ g:ia', $data['fetched_at'])); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render an error card.
     */
    private function errorCard(string $message): string {
        return '<div class="bbab-analytics-card bbab-analytics-error-card">' . esc_html($message) . '</div>';
    }
}
