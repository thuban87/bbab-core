<?php
/**
 * Plugin Name: BBAB Service Center
 * Plugin URI: https://bradsbitsandbytes.com
 * Description: Complete client portal and service center for Brad's Bits and Bytes
 * Version: 2.0.0
 * Author: Brad Wales
 * Author URI: https://bradsbitsandbytes.com
 * Text Domain: bbab-service-center
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package BBAB\ServiceCenter
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BBAB_SC_VERSION', '2.0.0');
define('BBAB_SC_PATH', plugin_dir_path(__FILE__));
define('BBAB_SC_URL', plugin_dir_url(__FILE__));
define('BBAB_SC_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader
if (file_exists(BBAB_SC_PATH . 'vendor/autoload.php')) {
    require_once BBAB_SC_PATH . 'vendor/autoload.php';
} else {
    // Composer not installed - show admin notice and bail
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BBAB Service Center:</strong> Composer dependencies not installed. ';
        echo 'Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}

// CRITICAL: Bootstrap simulation EARLY (before any other plugin code)
// This runs on plugins_loaded priority 1, before anything else queries data
add_action('plugins_loaded', function() {
    \BBAB\ServiceCenter\Core\SimulationBootstrap::init();
}, 1);

// Activation hook
register_activation_hook(__FILE__, function() {
    \BBAB\ServiceCenter\Core\Activator::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    \BBAB\ServiceCenter\Core\Deactivator::deactivate();
});

// Initialize the plugin on plugins_loaded (normal priority)
add_action('plugins_loaded', function() {
    $plugin = new \BBAB\ServiceCenter\Core\Plugin();
    $plugin->run();
}, 10);
