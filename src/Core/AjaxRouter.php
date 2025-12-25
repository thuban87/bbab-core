<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Core;

use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Utils\UserContext;

/**
 * Centralized AJAX request router.
 *
 * All frontend JavaScript sends requests to this router.
 * The router:
 * 1. Verifies the nonce ONCE
 * 2. Checks user context ONCE
 * 3. Dispatches to the appropriate handler
 *
 * This avoids scattering wp_ajax hooks across modules and ensures
 * consistent security checks.
 *
 * JS sends: { action: 'bbab_sc_ajax', handler: 'timer_stop', data: {...} }
 */
class AjaxRouter {

    private const NONCE_ACTION = 'bbab_sc_ajax_nonce';

    /**
     * Registered handlers.
     *
     * @var array<string, array{callback: callable, require_admin: bool, require_org: bool}>
     */
    private array $handlers = [];

    /**
     * Register the AJAX hooks.
     */
    public function register(): void {
        add_action('wp_ajax_bbab_sc_ajax', [$this, 'handleRequest']);
        add_action('wp_ajax_nopriv_bbab_sc_ajax', [$this, 'handleUnauthenticated']);

        // Register the script with nonce
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    /**
     * Register a handler for a specific action.
     *
     * @param string   $name          Handler name (e.g., 'timer_stop')
     * @param callable $callback      Function to call
     * @param bool     $require_admin Whether handler requires admin privileges
     * @param bool     $require_org   Whether handler requires org context
     */
    public function addHandler(
        string $name,
        callable $callback,
        bool $require_admin = false,
        bool $require_org = true
    ): void {
        $this->handlers[$name] = [
            'callback' => $callback,
            'require_admin' => $require_admin,
            'require_org' => $require_org,
        ];
    }

    /**
     * Handle authenticated AJAX requests.
     */
    public function handleRequest(): void {
        // Verify nonce
        if (!$this->verifyNonce()) {
            $this->sendError('Invalid security token. Please refresh and try again.', 403);
            return;
        }

        // Get handler name
        $handler_name = isset($_POST['handler']) ? sanitize_text_field(wp_unslash($_POST['handler'])) : '';

        if (empty($handler_name)) {
            $this->sendError('No handler specified.', 400);
            return;
        }

        // Check if handler exists
        if (!isset($this->handlers[$handler_name])) {
            Logger::warning('AjaxRouter', 'Unknown handler requested: ' . $handler_name);
            $this->sendError('Unknown action.', 400);
            return;
        }

        $handler = $this->handlers[$handler_name];

        // Check admin requirement
        if ($handler['require_admin'] && !UserContext::isAdmin()) {
            $this->sendError('Administrator access required.', 403);
            return;
        }

        // Check org requirement
        if ($handler['require_org'] && !UserContext::getCurrentOrgId()) {
            $this->sendError('No organization context available.', 403);
            return;
        }

        // Get request data
        $data = $_POST['data'] ?? [];
        if (is_string($data)) {
            $data = json_decode(stripslashes($data), true) ?: [];
        }

        // Dispatch to handler
        try {
            $result = call_user_func($handler['callback'], $data);
            $this->sendSuccess($result);
        } catch (\Exception $e) {
            Logger::error('AjaxRouter', 'Handler exception: ' . $handler_name, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendError('An error occurred. Please try again.', 500);
        }
    }

    /**
     * Handle unauthenticated AJAX requests.
     */
    public function handleUnauthenticated(): void {
        $this->sendError('Please log in to perform this action.', 401);
    }

    /**
     * Enqueue scripts with AJAX configuration.
     */
    public function enqueueScripts(): void {
        // Only localize if a script is registered that needs it
        // Individual modules register their own scripts and can use this data
        wp_localize_script('bbab-sc-frontend', 'bbabScAjax', [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'action' => 'bbab_sc_ajax',
        ]);
    }

    /**
     * Verify the AJAX nonce.
     */
    private function verifyNonce(): bool {
        $nonce = '';
        if (isset($_POST['nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
        } elseif (isset($_POST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        }

        return wp_verify_nonce($nonce, self::NONCE_ACTION) !== false;
    }

    /**
     * Send success response.
     *
     * @param mixed $data Response data
     */
    private function sendSuccess($data = null): void {
        wp_send_json_success($data);
    }

    /**
     * Send error response.
     */
    private function sendError(string $message, int $code = 400): void {
        wp_send_json_error(['message' => $message], $code);
    }

    /**
     * Get nonce action name (for external script registration).
     */
    public static function getNonceAction(): string {
        return self::NONCE_ACTION;
    }

    /**
     * Generate a fresh nonce (for embedding in HTML).
     */
    public static function createNonce(): string {
        return wp_create_nonce(self::NONCE_ACTION);
    }
}
