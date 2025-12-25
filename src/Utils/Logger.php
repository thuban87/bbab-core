<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * Consistent logging across all modules.
 *
 * All log messages are prefixed with [BBAB-SC] for easy filtering in debug.log.
 * Debug messages are only logged when debug_mode is enabled in settings.
 */
class Logger {

    private const PREFIX = '[BBAB-SC]';

    /**
     * Log an error (always logged).
     */
    public static function error(string $module, string $message, array $context = []): void {
        self::log('ERROR', $module, $message, $context);
    }

    /**
     * Log a warning (always logged).
     */
    public static function warning(string $module, string $message, array $context = []): void {
        self::log('WARNING', $module, $message, $context);
    }

    /**
     * Log info (always logged).
     */
    public static function info(string $module, string $message, array $context = []): void {
        self::log('INFO', $module, $message, $context);
    }

    /**
     * Log debug info (only when debug mode enabled).
     */
    public static function debug(string $module, string $message, array $context = []): void {
        if (!Settings::isDebugMode()) {
            return;
        }
        self::log('DEBUG', $module, $message, $context);
    }

    /**
     * Log API interaction.
     */
    public static function api(string $service, string $action, string $status, array $context = []): void {
        $message = sprintf('%s API - %s: %s', $service, $action, $status);
        self::log('API', 'External', $message, $context);
    }

    /**
     * Internal log writer.
     */
    private static function log(string $level, string $module, string $message, array $context): void {
        $formatted = sprintf(
            '%s [%s] %s: %s',
            self::PREFIX,
            $level,
            $module,
            $message
        );

        if (!empty($context)) {
            $formatted .= ' | Context: ' . wp_json_encode($context);
        }

        error_log($formatted);
    }
}
