<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access when WordPress is not bootstrapped.
}

function mesi_cache_insert_htaccess_block() {
    $htaccess_file = wp_normalize_path( ABSPATH . '.htaccess' ); // Resolve the absolute path to the .htaccess file.
    $fs            = mesi_cache_fs(); // Retrieve the filesystem handler for safe file operations.
    if ( ! $fs ) {
        return new WP_Error( 'mesi_cache_htaccess', __( 'Filesystem API unavailable.', 'mesi-cache' ) ); // Abort when filesystem credentials are missing.
    }

    $subpath     = trim( wp_parse_url( home_url(), PHP_URL_PATH ), '/' ); // Detect whether the site runs in a subdirectory.
    $base        = $subpath ? '/' . $subpath . '/' : '/'; // Build the RewriteBase directive dynamically.
    $cacheprefix = $subpath ? $subpath . '/' : ''; // Prefix cache lookups when WordPress lives in a subdirectory.

    $mesi_block  = "# BEGIN MESI-Cache\n"; // Open the custom MESI cache block marker.
    $mesi_block .= "<IfModule mod_rewrite.c>\n"; // Guard the rules behind mod_rewrite availability.
    $mesi_block .= "RewriteEngine On\n"; // Ensure the rewrite engine is activated.
    $mesi_block .= "RewriteBase {$base}\n\n"; // Set the rewrite base to the detected directory.
    $mesi_block .= "# Skip REST API, admin, login, feeds, previews, and query-string requests\n"; // Explain the exclusion rules.
    $mesi_block .= "RewriteCond %{REQUEST_URI} ^{$base}wp-json/ [OR]\n"; // Exclude REST API endpoints from caching.
    $mesi_block .= "RewriteCond %{REQUEST_URI} ^{$base}wp-admin/admin-ajax\\.php$ [OR]\n"; // Exclude AJAX handler requests.
    $mesi_block .= "RewriteCond %{REQUEST_URI} ^{$base}wp-admin [OR]\n"; // Exclude the WordPress admin area.
    $mesi_block .= "RewriteCond %{REQUEST_URI} ^{$base}wp-login\\.php [OR]\n"; // Exclude the login screen.
    $mesi_block .= "RewriteCond %{REQUEST_URI} /feed/ [OR]\n"; // Exclude feed endpoints.
    $mesi_block .= "RewriteCond %{REQUEST_URI} preview=true [OR]\n"; // Exclude preview requests.
    $mesi_block .= "RewriteCond %{QUERY_STRING} .+\n"; // Exclude any request that includes a query string.
    $mesi_block .= "RewriteRule .* - [S=4]\n\n"; // Skip the remaining rules when exclusions are matched.
    $mesi_block .= "# Serve cached home page when available\n"; // Describe the home cache rule.
    $mesi_block .= "RewriteCond %{DOCUMENT_ROOT}{$base}wp-content/uploads/mesi-cache/{$cacheprefix}index.html -f\n"; // Confirm the cached home file exists.
    $mesi_block .= "RewriteRule ^$ wp-content/uploads/mesi-cache/{$cacheprefix}index.html [L]\n\n"; // Serve the cached home file directly.
    $mesi_block .= "# Serve cached pretty permalinks\n"; // Describe the pretty permalink rule.
    $mesi_block .= "RewriteCond %{DOCUMENT_ROOT}{$base}wp-content/uploads/mesi-cache%{REQUEST_URI}/index.html -f\n"; // Confirm the cached permalink file exists.
    $mesi_block .= "RewriteRule ^(.*)$ wp-content/uploads/mesi-cache%{REQUEST_URI}/index.html [L]\n\n"; // Serve the cached permalink file directly.
    $mesi_block .= "# Serve cached index.php/slug permalinks\n"; // Describe the fallback permalink structure rule.
    $mesi_block .= "RewriteCond %{REQUEST_URI} ^{$base}index\\.php/(.+)\n"; // Match index.php-based permalinks.
    $mesi_block .= "RewriteCond %{DOCUMENT_ROOT}{$base}wp-content/uploads/mesi-cache/%1/index.html -f\n"; // Confirm the matching cached file exists.
    $mesi_block .= "RewriteRule ^index\\.php/(.+)$ wp-content/uploads/mesi-cache/%1/index.html [L]\n"; // Serve the cached fallback permalink directly.
    $mesi_block .= "</IfModule>\n"; // Close the mod_rewrite guard.
    $mesi_block .= "# END MESI-Cache\n"; // Close the custom MESI cache block.

    $wp_block  = "# BEGIN WordPress\n"; // Start the canonical WordPress rewrite block.
    $wp_block .= "<IfModule mod_rewrite.c>\n"; // Guard the WordPress block by mod_rewrite availability.
    $wp_block .= "RewriteEngine On\n"; // Activate the rewrite engine for WordPress fallback rules.
    $wp_block .= "RewriteBase {$base}\n"; // Apply the same base directory as above.
    $wp_block .= "RewriteRule ^index\\.php$ - [L]\n"; // Prevent rewriting index.php itself.
    $wp_block .= "RewriteCond %{REQUEST_FILENAME} !-f\n"; // Allow existing files to bypass WordPress.
    $wp_block .= "RewriteCond %{REQUEST_FILENAME} !-d\n"; // Allow existing directories to bypass WordPress.
    $wp_block .= "RewriteRule . {$base}index.php [L]\n"; // Route all other requests to index.php.
    $wp_block .= "</IfModule>\n"; // Close the mod_rewrite guard.
    $wp_block .= "# END WordPress\n"; // Close the canonical WordPress block.

    $existing = '';
    if ( $fs->exists( $htaccess_file ) ) {
        $existing = (string) $fs->get_contents( $htaccess_file ); // Retrieve the current .htaccess contents.
        $existing = preg_replace( '/# BEGIN MESI-Cache.*?# END MESI-Cache\s*/s', '', $existing ); // Remove any previous MESI block.
        $existing = preg_replace( '/# BEGIN WordPress.*?# END WordPress\s*/s', '', $existing ); // Remove any existing WordPress block for clean re-insertion.
    }

    $new_content = trim( $existing ) . "\n\n" . $mesi_block . "\n\n" . $wp_block . "\n"; // Compose the merged .htaccess file.

    if ( ! $fs->put_contents( $htaccess_file, $new_content, FS_CHMOD_FILE ) ) {
        return new WP_Error( 'mesi_cache_htaccess', __( 'Could not write .htaccess file', 'mesi-cache' ) ); // Report failure to write .htaccess.
    }

    return true; // Indicate success when the .htaccess file is updated.
}

function mesi_cache_remove_htaccess_block() {
    $htaccess_file = wp_normalize_path( ABSPATH . '.htaccess' ); // Resolve the absolute path to the .htaccess file.
    $fs            = mesi_cache_fs(); // Retrieve the filesystem handler.
    if ( ! $fs ) {
        return new WP_Error( 'mesi_cache_htaccess', __( 'Filesystem API unavailable.', 'mesi-cache' ) ); // Abort when filesystem credentials are missing.
    }

    if ( ! $fs->exists( $htaccess_file ) ) {
        return true; // Nothing to remove when .htaccess does not exist.
    }

    $contents = (string) $fs->get_contents( $htaccess_file ); // Load the current .htaccess contents.
    $stripped = preg_replace( '/# BEGIN MESI-Cache.*?# END MESI-Cache\s*/s', '', $contents ); // Remove the MESI cache block from the file.

    if ( ! $fs->put_contents( $htaccess_file, trim( $stripped ) . "\n", FS_CHMOD_FILE ) ) {
        return new WP_Error( 'mesi_cache_htaccess', __( 'Could not modify .htaccess', 'mesi-cache' ) ); // Report failure when the file cannot be updated.
    }

    return true; // Confirm the MESI block has been removed successfully.
}
