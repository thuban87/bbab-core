<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Analytics;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Google Analytics 4 Data Service.
 *
 * Fetches analytics data from GA4 using the Data API.
 * All data is cached for 24 hours.
 *
 * Migrated from: WPCode Snippets #2025, #2026, #2076, #2077
 *
 * Required org meta:
 *   ga4_property_id - The GA4 property ID (numbers only, e.g., "123456789")
 */
class GA4Service {

    private const CACHE_SECONDS = DAY_IN_SECONDS;
    private const API_TIMEOUT = 30;
    private const GA4_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    // =========================================================================
    // Cache-Only Getters (for shortcodes - never trigger API calls)
    // =========================================================================

    /**
     * Get cached GA4 core metrics. Returns null if not cached.
     * Use this in shortcodes - never triggers API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Cached data or null
     */
    public static function getData(int $org_id): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            return null;
        }

        $data = Cache::get('ga4_data_' . $property_id);

        if ($data !== null) {
            $data['from_cache'] = true;
        }

        return $data;
    }

    /**
     * Get cached top pages. Returns null if not cached.
     *
     * @param int $org_id Organization post ID
     * @param int $limit  Number of pages (must match what was fetched)
     * @return array|null Cached data or null
     */
    public static function getTopPages(int $org_id, int $limit = 5): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            return null;
        }

        return Cache::get('ga4_pages_' . $property_id . '_' . $limit);
    }

    /**
     * Get cached traffic sources. Returns null if not cached.
     *
     * @param int $org_id Organization post ID
     * @param int $limit  Number of sources (must match what was fetched)
     * @return array|null Cached data or null
     */
    public static function getTrafficSources(int $org_id, int $limit = 6): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            return null;
        }

        return Cache::get('ga4_sources_' . $property_id . '_' . $limit);
    }

    /**
     * Get cached device data. Returns null if not cached.
     *
     * @param int $org_id Organization post ID
     * @return array|null Cached data or null
     */
    public static function getDevices(int $org_id): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            return null;
        }

        return Cache::get('ga4_devices_' . $property_id);
    }

    // =========================================================================
    // Fetch Methods (for cron - trigger API calls and cache results)
    // =========================================================================

    /**
     * Fetch and cache core GA4 metrics with period comparison.
     * USE ONLY IN CRON - triggers live API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Analytics data or null on failure
     */
    public static function fetchData(int $org_id): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            Logger::debug('GA4Service', 'No property ID for org ' . $org_id);
            return null;
        }

        $data = self::fetchCoreMetrics($property_id);

        if ($data) {
            Cache::set('ga4_data_' . $property_id, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Fetch and cache top pages from GA4.
     * USE ONLY IN CRON.
     *
     * @param int $org_id Organization post ID
     * @param int $limit  Number of pages to return (default 5)
     * @return array|null Pages data or null on failure
     */
    public static function fetchTopPages(int $org_id, int $limit = 5): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            return null;
        }

        $data = self::fetchTopPagesData($property_id, $limit);

        if ($data) {
            Cache::set('ga4_pages_' . $property_id . '_' . $limit, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Fetch and cache traffic sources/channels from GA4.
     * USE ONLY IN CRON.
     *
     * @param int $org_id Organization post ID
     * @param int $limit  Number of sources to return (default 6)
     * @return array|null Sources data or null on failure
     */
    public static function fetchTrafficSources(int $org_id, int $limit = 6): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            return null;
        }

        $data = self::fetchSourcesData($property_id, $limit);

        if ($data) {
            Cache::set('ga4_sources_' . $property_id . '_' . $limit, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Fetch and cache device category breakdown from GA4.
     * USE ONLY IN CRON.
     *
     * @param int $org_id Organization post ID
     * @return array|null Device data or null on failure
     */
    public static function fetchDevices(int $org_id): ?array {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if (empty($property_id)) {
            return null;
        }

        $data = self::fetchDevicesData($property_id);

        if ($data) {
            Cache::set('ga4_devices_' . $property_id, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Clear all GA4 cache for an organization.
     *
     * @param int $org_id Organization post ID
     */
    public static function clearCache(int $org_id): void {
        $property_id = get_post_meta($org_id, 'ga4_property_id', true);

        if ($property_id) {
            Cache::delete('ga4_data_' . $property_id);
            Cache::flushPattern('ga4_pages_' . $property_id);
            Cache::flushPattern('ga4_sources_' . $property_id);
            Cache::delete('ga4_devices_' . $property_id);
            Logger::debug('GA4Service', 'Cache cleared for property ' . $property_id);
        }
    }

    // =========================================================================
    // Private Implementation Methods
    // =========================================================================

    /**
     * Fetch core metrics with period comparison.
     */
    private static function fetchCoreMetrics(string $property_id): ?array {
        $token = GoogleAuthService::getAccessToken([self::GA4_SCOPE]);

        if (!$token) {
            Logger::error('GA4Service', 'Failed to get access token');
            return null;
        }

        // Build date ranges - current 30 days vs previous 30 days
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-29 days'));
        $prev_end_date = date('Y-m-d', strtotime('-30 days'));
        $prev_start_date = date('Y-m-d', strtotime('-59 days'));

        $request_body = [
            'dateRanges' => [
                ['startDate' => $start_date, 'endDate' => $end_date],
                ['startDate' => $prev_start_date, 'endDate' => $prev_end_date]
            ],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'newUsers'],
                ['name' => 'sessions'],
                ['name' => 'engagementRate'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'screenPageViews']
            ]
        ];

        $response = self::makeApiRequest($property_id, $request_body, $token);

        if (!$response) {
            return null;
        }

        // Handle empty data
        if (empty($response['rows'])) {
            Logger::debug('GA4Service', 'No data returned for property ' . $property_id);
            return [
                'fetched_at' => time(),
                'property_id' => $property_id,
                'current_period' => [
                    'start' => $start_date,
                    'end' => $end_date,
                    'active_users' => 0,
                    'new_users' => 0,
                    'sessions' => 0,
                    'engagement_rate' => 0,
                    'avg_session_duration' => 0,
                    'page_views' => 0
                ],
                'previous_period' => [],
                'trends' => [],
                'from_cache' => false
            ];
        }

        // Parse response
        $current_metrics = $response['rows'][0]['metricValues'] ?? [];
        $previous_metrics = isset($response['rows'][1]) ? $response['rows'][1]['metricValues'] : [];

        $data = [
            'fetched_at' => time(),
            'property_id' => $property_id,
            'current_period' => [
                'start' => $start_date,
                'end' => $end_date,
                'active_users' => intval($current_metrics[0]['value'] ?? 0),
                'new_users' => intval($current_metrics[1]['value'] ?? 0),
                'sessions' => intval($current_metrics[2]['value'] ?? 0),
                'engagement_rate' => round(floatval($current_metrics[3]['value'] ?? 0) * 100, 1),
                'avg_session_duration' => round(floatval($current_metrics[4]['value'] ?? 0)),
                'page_views' => intval($current_metrics[5]['value'] ?? 0)
            ],
            'previous_period' => [
                'start' => $prev_start_date,
                'end' => $prev_end_date,
                'active_users' => intval($previous_metrics[0]['value'] ?? 0),
                'new_users' => intval($previous_metrics[1]['value'] ?? 0),
                'sessions' => intval($previous_metrics[2]['value'] ?? 0),
                'engagement_rate' => round(floatval($previous_metrics[3]['value'] ?? 0) * 100, 1),
                'avg_session_duration' => round(floatval($previous_metrics[4]['value'] ?? 0)),
                'page_views' => intval($previous_metrics[5]['value'] ?? 0)
            ],
            'from_cache' => false
        ];

        // Calculate trends
        $data['trends'] = self::calculateTrends(
            $data['current_period'],
            $data['previous_period'],
            ['active_users', 'new_users', 'sessions', 'page_views']
        );

        return $data;
    }

    /**
     * Fetch top pages data.
     */
    private static function fetchTopPagesData(string $property_id, int $limit): ?array {
        $token = GoogleAuthService::getAccessToken([self::GA4_SCOPE]);

        if (!$token) {
            return null;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => '30daysAgo', 'endDate' => 'today']
            ],
            'dimensions' => [
                ['name' => 'pagePath']
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers']
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]
            ],
            'limit' => $limit
        ];

        $response = self::makeApiRequest($property_id, $request_body, $token);

        if (!$response) {
            return null;
        }

        $pages = [];
        if (!empty($response['rows'])) {
            foreach ($response['rows'] as $row) {
                $path = $row['dimensionValues'][0]['value'] ?? '/';
                $pages[] = [
                    'path' => $path,
                    'display_path' => $path === '/' ? 'Homepage' : $path,
                    'views' => intval($row['metricValues'][0]['value'] ?? 0),
                    'users' => intval($row['metricValues'][1]['value'] ?? 0)
                ];
            }
        }

        return [
            'fetched_at' => time(),
            'pages' => $pages
        ];
    }

    /**
     * Fetch traffic sources data.
     */
    private static function fetchSourcesData(string $property_id, int $limit): ?array {
        $token = GoogleAuthService::getAccessToken([self::GA4_SCOPE]);

        if (!$token) {
            return null;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => '30daysAgo', 'endDate' => 'today']
            ],
            'dimensions' => [
                ['name' => 'sessionDefaultChannelGroup']
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers']
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true]
            ],
            'limit' => $limit
        ];

        $response = self::makeApiRequest($property_id, $request_body, $token);

        if (!$response) {
            return null;
        }

        $sources = [];
        $total_sessions = 0;

        if (!empty($response['rows'])) {
            // First pass - get total for percentage calculation
            foreach ($response['rows'] as $row) {
                $total_sessions += intval($row['metricValues'][0]['value'] ?? 0);
            }

            // Second pass - build data with percentages
            foreach ($response['rows'] as $row) {
                $sessions = intval($row['metricValues'][0]['value'] ?? 0);
                $sources[] = [
                    'channel' => $row['dimensionValues'][0]['value'] ?? 'Unknown',
                    'sessions' => $sessions,
                    'users' => intval($row['metricValues'][1]['value'] ?? 0),
                    'percentage' => $total_sessions > 0 ? round(($sessions / $total_sessions) * 100, 1) : 0
                ];
            }
        }

        return [
            'fetched_at' => time(),
            'total_sessions' => $total_sessions,
            'sources' => $sources
        ];
    }

    /**
     * Fetch devices data.
     */
    private static function fetchDevicesData(string $property_id): ?array {
        $token = GoogleAuthService::getAccessToken([self::GA4_SCOPE]);

        if (!$token) {
            return null;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => '30daysAgo', 'endDate' => 'today']
            ],
            'dimensions' => [
                ['name' => 'deviceCategory']
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers']
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true]
            ]
        ];

        $response = self::makeApiRequest($property_id, $request_body, $token);

        if (!$response) {
            return null;
        }

        // Device icons for display
        $device_icons = [
            'desktop' => '🖥️',
            'mobile' => '📱',
            'tablet' => '📱',
            'smart tv' => '📺',
            'unknown' => '❓'
        ];

        $devices = [];
        $total_sessions = 0;

        if (!empty($response['rows'])) {
            // First pass - get total
            foreach ($response['rows'] as $row) {
                $total_sessions += intval($row['metricValues'][0]['value'] ?? 0);
            }

            // Second pass - build data
            foreach ($response['rows'] as $row) {
                $category = strtolower($row['dimensionValues'][0]['value'] ?? 'unknown');
                $sessions = intval($row['metricValues'][0]['value'] ?? 0);

                $devices[] = [
                    'category' => ucfirst($category),
                    'icon' => $device_icons[$category] ?? '❓',
                    'sessions' => $sessions,
                    'users' => intval($row['metricValues'][1]['value'] ?? 0),
                    'percentage' => $total_sessions > 0 ? round(($sessions / $total_sessions) * 100, 1) : 0
                ];
            }
        }

        return [
            'fetched_at' => time(),
            'total_sessions' => $total_sessions,
            'devices' => $devices
        ];
    }

    /**
     * Make a request to the GA4 Data API.
     */
    private static function makeApiRequest(string $property_id, array $request_body, string $token): ?array {
        // Sanitize property_id - must be numeric only
        $property_id = preg_replace('/[^0-9]/', '', $property_id);

        if (empty($property_id)) {
            Logger::error('GA4Service', 'Invalid property ID after sanitization');
            return null;
        }

        $response = wp_remote_post(
            "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($request_body),
                'timeout' => self::API_TIMEOUT
            ]
        );

        if (is_wp_error($response)) {
            Logger::error('GA4Service', 'API request failed', [
                'property' => $property_id,
                'error' => $response->get_error_message()
            ]);
            return null;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code !== 200) {
            Logger::error('GA4Service', 'API error', [
                'property' => $property_id,
                'http_code' => $http_code,
                'response' => $body
            ]);
            return null;
        }

        return $body;
    }

    /**
     * Calculate percentage trends between two periods.
     */
    private static function calculateTrends(array $current, array $previous, array $metrics): array {
        $trends = [];

        foreach ($metrics as $metric) {
            $current_val = $current[$metric] ?? 0;
            $previous_val = $previous[$metric] ?? 0;

            if ($previous_val > 0) {
                $trends[$metric] = round((($current_val - $previous_val) / $previous_val) * 100, 1);
            } else {
                $trends[$metric] = $current_val > 0 ? 100 : 0;
            }
        }

        return $trends;
    }
}
