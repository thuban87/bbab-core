<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * Simple template/view renderer.
 *
 * Separates HTML from PHP logic in shortcodes and admin pages.
 *
 * Usage:
 *   return View::render('dashboard/overview', [
 *       'org_name' => $org->post_title,
 *       'projects' => $projects,
 *   ]);
 *
 * Template file: templates/dashboard/overview.php
 * Variables available as $org_name, $projects, etc.
 */
class View {

    private static ?string $base_path = null;

    /**
     * Render a template with data.
     *
     * @param string $template Template path relative to templates/ (no .php extension)
     * @param array  $data     Variables to extract into template scope
     * @return string Rendered HTML
     */
    public static function render(string $template, array $data = []): string {
        $file = self::getBasePath() . '/' . $template . '.php';

        if (!file_exists($file)) {
            Logger::error('View', 'Template not found: ' . $template);
            return '<!-- Template not found: ' . esc_html($template) . ' -->';
        }

        // Start output buffering
        ob_start();

        // Extract variables into local scope
        // IMPORTANT: Keys become variable names, so ensure they're safe
        extract(self::sanitizeKeys($data), EXTR_SKIP);

        // Include the template (it has access to extracted variables)
        include $file;

        // Return the captured output
        return ob_get_clean();
    }

    /**
     * Render and echo (for admin pages that echo directly).
     */
    public static function display(string $template, array $data = []): void {
        echo self::render($template, $data);
    }

    /**
     * Get the templates base path.
     */
    private static function getBasePath(): string {
        if (self::$base_path === null) {
            self::$base_path = BBAB_SC_PATH . 'templates';
        }
        return self::$base_path;
    }

    /**
     * Set a custom base path (for testing).
     */
    public static function setBasePath(string $path): void {
        self::$base_path = $path;
    }

    /**
     * Sanitize array keys to be valid PHP variable names.
     */
    private static function sanitizeKeys(array $data): array {
        $sanitized = [];
        foreach ($data as $key => $value) {
            // Only allow alphanumeric and underscore
            $clean_key = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $key);
            $sanitized[$clean_key] = $value;
        }
        return $sanitized;
    }

    /**
     * Escape helper for use in templates.
     * Shortcut for esc_html().
     *
     * @param mixed $value Value to escape
     */
    public static function e($value): string {
        return esc_html((string) $value);
    }

    /**
     * Escape and echo helper for use in templates.
     *
     * @param mixed $value Value to escape and echo
     */
    public static function ee($value): void {
        echo esc_html((string) $value);
    }

    /**
     * Escape URL helper.
     *
     * @param mixed $value URL to escape
     */
    public static function url($value): string {
        return esc_url((string) $value);
    }

    /**
     * Escape attribute helper.
     *
     * @param mixed $value Attribute value to escape
     */
    public static function attr($value): string {
        return esc_attr((string) $value);
    }

    /**
     * Include a partial template.
     *
     * @param string $partial Partial template path
     * @param array  $data    Variables to pass to partial
     */
    public static function partial(string $partial, array $data = []): string {
        return self::render('partials/' . $partial, $data);
    }
}
