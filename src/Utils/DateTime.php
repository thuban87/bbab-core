<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * All datetime handling - UTC storage, CST display.
 *
 * The database stores all times in UTC. This class handles
 * conversion to the configured timezone (default: America/Chicago)
 * for display purposes.
 */
class DateTime {

    private const STORAGE_FORMAT = 'Y-m-d H:i:s';
    private const DISPLAY_FORMAT = 'M j, Y g:i a';
    private const DATE_ONLY_FORMAT = 'M j, Y';

    /**
     * Get current time in UTC for database storage.
     */
    public static function nowUtc(): string {
        return gmdate(self::STORAGE_FORMAT);
    }

    /**
     * Get current date in UTC (no time component).
     */
    public static function todayUtc(): string {
        return gmdate('Y-m-d');
    }

    /**
     * Convert UTC datetime to local timezone for display.
     *
     * @param string      $utc_datetime UTC datetime string
     * @param string|null $format       Optional custom format
     */
    public static function toDisplay(string $utc_datetime, ?string $format = null): string {
        if (empty($utc_datetime)) {
            return '';
        }

        $format = $format ?? self::DISPLAY_FORMAT;
        $timezone = new \DateTimeZone(Settings::get('timezone', 'America/Chicago'));

        try {
            $dt = new \DateTime($utc_datetime, new \DateTimeZone('UTC'));
            $dt->setTimezone($timezone);
            return $dt->format($format);
        } catch (\Exception $e) {
            Logger::error('DateTime', 'Failed to parse datetime', [
                'input' => $utc_datetime,
                'error' => $e->getMessage()
            ]);
            return $utc_datetime;
        }
    }

    /**
     * Convert local datetime to UTC for storage.
     */
    public static function toUtc(string $local_datetime): string {
        if (empty($local_datetime)) {
            return '';
        }

        $timezone = new \DateTimeZone(Settings::get('timezone', 'America/Chicago'));

        try {
            $dt = new \DateTime($local_datetime, $timezone);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->format(self::STORAGE_FORMAT);
        } catch (\Exception $e) {
            Logger::error('DateTime', 'Failed to convert to UTC', [
                'input' => $local_datetime,
                'error' => $e->getMessage()
            ]);
            return $local_datetime;
        }
    }

    /**
     * Format date only (no time).
     */
    public static function toDateDisplay(string $date): string {
        return self::toDisplay($date, self::DATE_ONLY_FORMAT);
    }

    /**
     * Get relative time string (e.g., "2 hours ago").
     */
    public static function toRelative(string $utc_datetime): string {
        if (empty($utc_datetime)) {
            return '';
        }

        $timestamp = strtotime($utc_datetime);
        if ($timestamp === false) {
            return '';
        }

        return human_time_diff($timestamp, time()) . ' ago';
    }

    /**
     * Get the start of the current month in UTC.
     */
    public static function startOfMonth(): string {
        return gmdate('Y-m-01 00:00:00');
    }

    /**
     * Get the end of the current month in UTC.
     */
    public static function endOfMonth(): string {
        return gmdate('Y-m-t 23:59:59');
    }

    /**
     * Format a duration in hours to human-readable string.
     */
    public static function formatHours(float $hours): string {
        if ($hours < 1) {
            $minutes = (int) ($hours * 60);
            return $minutes . ' min';
        }

        $wholeHours = (int) $hours;
        $minutes = (int) (($hours - $wholeHours) * 60);

        if ($minutes === 0) {
            return $wholeHours . ' hr';
        }

        return $wholeHours . ' hr ' . $minutes . ' min';
    }
}
