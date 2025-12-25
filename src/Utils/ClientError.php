<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * User-friendly error display with logging.
 *
 * Generates user-friendly error messages while logging
 * technical details for debugging. Includes a unique
 * reference code so support can find the full error.
 */
class ClientError {

    /**
     * Generate a user-friendly error message and log the technical details.
     *
     * @param string $technical_message Technical error message (for logs)
     * @param array  $context           Additional context for logging
     * @return string User-friendly HTML
     */
    public static function generate(string $technical_message, array $context = []): string {
        // Generate unique error reference
        $ref = 'ERR-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

        // Log the full technical error
        Logger::error('ClientError', $technical_message, array_merge($context, [
            'error_ref' => $ref
        ]));

        // Return user-friendly HTML
        return sprintf(
            '<div class="bbab-error" style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 4px; padding: 12px; margin: 10px 0;">
                <p style="margin: 0 0 8px 0; color: #991b1b;">Something went wrong. Please try again or contact support.</p>
                <small style="color: #7f1d1d;">Reference: %s</small>
            </div>',
            esc_html($ref)
        );
    }

    /**
     * Generate a simple warning message (non-critical).
     */
    public static function warning(string $message): string {
        return sprintf(
            '<div class="bbab-warning" style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 4px; padding: 12px; margin: 10px 0;">
                <p style="margin: 0; color: #92400e;">%s</p>
            </div>',
            esc_html($message)
        );
    }

    /**
     * Generate an info message.
     */
    public static function info(string $message): string {
        return sprintf(
            '<div class="bbab-info" style="background: #eff6ff; border: 1px solid #93c5fd; border-radius: 4px; padding: 12px; margin: 10px 0;">
                <p style="margin: 0; color: #1e40af;">%s</p>
            </div>',
            esc_html($message)
        );
    }

    /**
     * Generate a success message.
     */
    public static function success(string $message): string {
        return sprintf(
            '<div class="bbab-success" style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 4px; padding: 12px; margin: 10px 0;">
                <p style="margin: 0; color: #166534;">%s</p>
            </div>',
            esc_html($message)
        );
    }
}
