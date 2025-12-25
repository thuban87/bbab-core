<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Core;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Main plugin orchestrator.
 *
 * Initializes all modules, registers hooks, and coordinates the plugin lifecycle.
 */
class Plugin {

    /**
     * The AJAX router instance.
     */
    private AjaxRouter $ajax_router;

    /**
     * Initialize the plugin.
     */
    public function __construct() {
        $this->ajax_router = new AjaxRouter();
    }

    /**
     * Run the plugin.
     *
     * This is called on plugins_loaded with priority 10 (after SimulationBootstrap).
     */
    public function run(): void {
        // Register AJAX router
        $this->ajax_router->register();

        // Load admin functionality
        if (is_admin()) {
            $this->loadAdmin();
        }

        // Load frontend functionality
        if (!is_admin() || wp_doing_ajax()) {
            $this->loadFrontend();
        }

        // Load cron handlers (always, for scheduling)
        $this->loadCron();

        // Log plugin initialization in debug mode
        Logger::debug('Plugin', 'BBAB Service Center initialized', [
            'version' => BBAB_SC_VERSION,
            'simulation_active' => defined('BBAB_SC_SIMULATED_ORG_ID') && BBAB_SC_SIMULATED_ORG_ID > 0,
        ]);
    }

    /**
     * Load admin functionality.
     */
    private function loadAdmin(): void {
        // Admin loader will be created in Phase 1
        // For now, this is a placeholder

        // TODO: Initialize AdminLoader
        // $admin_loader = new \BBAB\ServiceCenter\Admin\AdminLoader();
        // $admin_loader->register();
    }

    /**
     * Load frontend functionality.
     */
    private function loadFrontend(): void {
        // Frontend loader will be created in Phase 1
        // For now, this is a placeholder

        // TODO: Initialize FrontendLoader
        // $frontend_loader = new \BBAB\ServiceCenter\Frontend\FrontendLoader();
        // $frontend_loader->register();
    }

    /**
     * Load cron handlers.
     */
    private function loadCron(): void {
        // Cron loader will be created later
        // For now, this is a placeholder

        // TODO: Initialize CronLoader
        // $cron_loader = new \BBAB\ServiceCenter\Cron\CronLoader();
        // $cron_loader->register();
    }

    /**
     * Get the AJAX router instance.
     */
    public function getAjaxRouter(): AjaxRouter {
        return $this->ajax_router;
    }
}
