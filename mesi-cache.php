<?php
/**
 * Plugin Name: Mesi Cache
 * Plugin URI: https://github.com/andresmesi/mesi-cache
 * Description: Ultra-light static HTML caching system for WordPress. Generates static files served directly by Apache for maximum performance.
 * Version: 1.2.5
 * Author: andresmesi
 * Author URI: https://mesi.com.ar
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mesi-cache
 * Domain Path: /languages
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Bail out early if WordPress core is not loaded to prevent direct access.
}

/**
 * ---------------------------------------------------------------
 * PLUGIN CONSTANTS
 * ---------------------------------------------------------------
 */

// Plugin version.
if ( ! defined( 'MESI_CACHE_VERSION' ) ) {
    define( 'MESI_CACHE_VERSION', '1.2.5' ); // Define the current plugin version constant once.
}

// Option name for settings.
if ( ! defined( 'MESI_CACHE_OPTION' ) ) {
    define( 'MESI_CACHE_OPTION', 'mesi_cache_options' ); // Define a constant for the plugin settings option name.
}

/**
 * ---------------------------------------------------------------
 * REAL CACHE DIRECTORY
 * ---------------------------------------------------------------
 * The cache folder must always be at the same level as wp-config.php,
 * never nested under wp-content or duplicated paths.
 *
 * Example expected path:
 * /home/www/site/www/wp-content/uploads/mesi-cache/
 * ---------------------------------------------------------------
 */
if ( ! defined( 'MESI_CACHE_DIR' ) ) {
    if ( ! function_exists( 'get_home_path' ) ) { // Ensure the helper to locate the install root is available.
        require_once ABSPATH . 'wp-admin/includes/file.php'; // Load the helper definition when it has not been included yet.
    }

    $mesi_cache_home_path = function_exists( 'get_home_path' ) ? get_home_path() : ''; // Resolve the WordPress installation root via helper.
    if ( '' === $mesi_cache_home_path ) { // Provide a secondary fallback derived from the wp-content directory when the helper fails.
        $mesi_cache_home_path = dirname( wp_normalize_path( WP_CONTENT_DIR ) ); // WP_CONTENT_DIR lives directly inside the installation root.
    }

    $mesi_cache_resolved = wp_normalize_path( untrailingslashit( $mesi_cache_home_path ) ); // Normalize separators and trim trailing slashes for consistency.

    define( 'MESI_CACHE_DIR', trailingslashit( $mesi_cache_resolved ) . 'wp-content/uploads/mesi-cache/' ); // Define the absolute cache directory path beside wp-config.php.
}

/**
 * ---------------------------------------------------------------
 * INCLUDES
 * ---------------------------------------------------------------
 * Load modular components. Each file must rely on MESI_CACHE_DIR
 * for file operations (not relative paths).
 * ---------------------------------------------------------------
 */
require_once __DIR__ . '/includes/functions-cache.php'; // Load helper functions that handle cache file operations.
require_once __DIR__ . '/includes/hooks.php'; // Load runtime hooks responsible for serving and generating cache.
require_once __DIR__ . '/includes/regenerate.php'; // Load utilities that regenerate cached files on demand.
require_once __DIR__ . '/includes/verifier.php'; // Load the environment verification helpers for the admin area.
require_once __DIR__ . '/includes/htaccess-manager.php'; // Load .htaccess management helpers used in the settings page.
require_once __DIR__ . '/admin/settings-page.php'; // Load the admin UI that exposes cache management actions.

/**
 * ---------------------------------------------------------------
 * ACTIVATION HOOK
 * ---------------------------------------------------------------
 * Ensure cache directory exists and is writable upon activation.
 * ---------------------------------------------------------------
 */
register_activation_hook( __FILE__, function() { // Register the activation routine to prepare the cache directory.
    require_once ABSPATH . 'wp-admin/includes/file.php'; // Load the WP_Filesystem API when the plugin activates.
    WP_Filesystem(); // Initialize the WP_Filesystem global handler.
    global $wp_filesystem; // Access the filesystem abstraction provided by WordPress.

    if ( $wp_filesystem && ! $wp_filesystem->is_dir( MESI_CACHE_DIR ) ) { // Create the cache directory when it is missing.
        $wp_filesystem->mkdir( MESI_CACHE_DIR, FS_CHMOD_DIR ); // Create the directory using the configured permissions constants.
    }

    if ( $wp_filesystem ) { // Proceed only when the filesystem API is operational.
        $index_file = MESI_CACHE_DIR . 'index.html'; // Build the path to a harmless index file for security.
        if ( ! $wp_filesystem->exists( $index_file ) ) { // Ensure the placeholder file exists to block directory listings.
            $wp_filesystem->put_contents( $index_file, '', FS_CHMOD_FILE ); // Write the placeholder file with safe permissions.
        }
    }
} );

/**
 * ---------------------------------------------------------------
 * ADMIN NOTICE FOR INVALID CACHE DIR
 * ---------------------------------------------------------------
 * Displays an admin warning if the cache directory is missing
 * or not writable, useful after manual file system changes.
 * ---------------------------------------------------------------
 */
add_action( 'admin_notices', function() { // Display an admin notice when the cache directory is not writable.
    if ( ! current_user_can( 'manage_options' ) ) { // Only show notices to administrators who can manage options.
        return; // Exit early for users without sufficient capabilities.
    }

    if ( ! is_dir( MESI_CACHE_DIR ) || ! wp_is_writable( MESI_CACHE_DIR ) ) { // Alert administrators if the cache directory is missing or unwritable.
        /* translators: %s: absolute path to the cache directory. */
        $message = sprintf( esc_html__( '%s is missing or not writable. Please fix permissions or re-activate the plugin.', 'mesi-cache' ), esc_html( MESI_CACHE_DIR ) ); // Build a translated warning that includes the cache directory path.

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__( 'Mesi Cache:', 'mesi-cache' ),
            esc_html( $message )
        ); // Output the composed notice with contextualized messaging.
    }
} );

/**
 * ---------------------------------------------------------------
 * LOAD TEXT DOMAIN
 * ---------------------------------------------------------------
 * For multilingual support of admin interface.
 * ---------------------------------------------------------------
 */
// WordPress automatically loads the text domain for plugins hosted on WordPress.org since 4.6, so no manual call is required here.
