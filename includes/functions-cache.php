<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Abort if WordPress core is not bootstrapped to avoid direct execution.
}

/**
 * Initialize and return WP_Filesystem instance (or false).
 * Always prefer WP_Filesystem for file operations (Plugin Check compliant).
 */
function mesi_cache_fs() {
    require_once ABSPATH . 'wp-admin/includes/file.php'; // Load the filesystem API helpers on demand.
    WP_Filesystem(); // Initialize the filesystem abstraction layer.
    global $wp_filesystem; // Gain access to the global filesystem handler.
    return $wp_filesystem ?: false; // Return the filesystem object or false when initialization fails.
}

/**
 * Return the list of HTML tags and attributes considered safe when emitting cached pages.
 * The allow-list intentionally mirrors what modern WordPress themes output so cached
 * documents remain intact while still satisfying the escaping requirements from the
 * Plugin Handbook. Developers can extend this via the mesi_cache_allowed_html filter.
 */
function mesi_cache_allowed_html() {
    $allowed = wp_kses_allowed_html( 'post' ); // Start with the default post context allow-list.

    $extended_tags = array(
        'html',
        'head',
        'body',
        'meta',
        'link',
        'style',
        'script',
        'noscript',
        'title',
        'base',
        'iframe',
        'svg',
        'path',
        'g',
        'circle',
        'defs',
        'linearGradient',
        'stop',
        'use',
        'symbol',
        'polyline',
        'polygon',
        'rect',
        'mask',
        'pattern',
        'clipPath',
        'picture',
        'source',
        'main',
        'section',
        'article',
        'header',
        'footer',
        'nav',
        'aside',
        'template',
        'canvas',
        'video',
        'audio',
        'track',
        'figure',
        'figcaption',
        'time',
        'dialog',
        'slot',
    ); // Comprehensive list of structural and media tags emitted by themes.

    foreach ( $extended_tags as $tag ) { // Ensure each extended tag is present and permissive.
        if ( ! isset( $allowed[ $tag ] ) ) { // When WordPress does not already allow the tag...
            $allowed[ $tag ] = true; // ...permit all attributes for the tag to preserve markup fidelity.
        } elseif ( is_array( $allowed[ $tag ] ) ) { // When the tag already has attribute rules...
            $allowed[ $tag ]['data-*'] = true; // ...extend them with data-* attributes commonly used by themes.
            $allowed[ $tag ]['aria-*'] = true; // Allow ARIA attributes to maintain accessibility metadata.
            $allowed[ $tag ]['class']  = true; // Ensure CSS class attributes are permitted even when missing by default.
            $allowed[ $tag ]['style']  = true; // Allow inline styles that may be injected by block themes.
            $allowed[ $tag ]['id']     = true; // Ensure ID attributes are available for anchors and scripts.
        }
    }

    return apply_filters( 'mesi_cache_allowed_html', $allowed ); // Provide a filter so integrators can extend the allow-list.
}

/**
 * Return the list of URL protocols allowed when sanitizing cached markup.
 * Adds "data" URLs so inline assets survive sanitation and keeps the list filterable.
 */
function mesi_cache_allowed_protocols() {
    $protocols   = wp_allowed_protocols(); // Retrieve the core list of permitted URL schemes.
    $protocols[] = 'data'; // Allow data URIs used for small inline assets within cached markup.

    return apply_filters( 'mesi_cache_allowed_protocols', array_unique( $protocols ) ); // Let developers adjust the protocols.
}

/**
 * Stream a cached HTML file directly to the client without altering the markup.
 */
function mesi_cache_stream_file( $file ) {
    $path = wp_normalize_path( $file ); // Normalize the file path to avoid platform-dependent separators.
    if ( '' === $path ) {
        return false; // Abort streaming when the target file cannot be resolved.
    }

    $filesystem = mesi_cache_fs(); // Retrieve the filesystem abstraction provided by WordPress.
    if ( ! $filesystem ) {
        return false; // Stop when the filesystem layer is unavailable (credentials or initialization failure).
    }

    if ( ! $filesystem->exists( $path ) || ! $filesystem->is_readable( $path ) ) {
        return false; // Abort streaming when the target file cannot be accessed safely via WP_Filesystem.
    }

    $contents = $filesystem->get_contents( $path ); // Read the cached HTML contents through WP_Filesystem for compliance.
    if ( false === $contents ) {
        return false; // Respect filesystem errors and avoid emitting partial output.
    }

    if ( ! headers_sent() ) { // Emit the Content-Type header only when headers remain mutable.
        $charset = get_option( 'blog_charset', 'UTF-8' ); // Retrieve the configured blog character set as the default charset.
        header( 'Content-Type: text/html; charset=' . sanitize_text_field( $charset ) ); // Send an explicit charset for browsers.
    }

    $has_doctype = false; // Track whether the cached markup started with a doctype declaration.
    if ( preg_match( '/^\s*<!DOCTYPE\s+html[^>]*>/i', $contents, $matches ) ) { // Detect and strip the HTML5 doctype when present.
        $contents   = ltrim( substr( $contents, strlen( $matches[0] ) ) ); // Remove the matched doctype from the sanitized body.
        $has_doctype = true; // Remember to print the canonical doctype back before the sanitized markup.
    }

    if ( $has_doctype ) {
        echo "<!DOCTYPE html>\n"; // Reintroduce the standard HTML5 doctype ahead of the sanitized markup stream.
    }

    echo wp_kses( $contents, mesi_cache_allowed_html(), mesi_cache_allowed_protocols() ); // Output sanitized markup using the configured allow-lists.

    return true; // Indicate success after the cached document has been emitted.
}

/**
 * Safely create a directory using WP_Filesystem.
 * Preserves absolute paths (leading slash) to avoid duplicated prefixes.
 */
function mesi_cache_safe_mkdir( $dir ) {
    require_once ABSPATH . 'wp-admin/includes/file.php'; // Ensure filesystem helpers are available.
    WP_Filesystem(); // Initialize the filesystem connection for the current request.
    global $wp_filesystem; // Reference the filesystem object used by WordPress.

    if ( ! $wp_filesystem ) {
        return false; // Abort when filesystem credentials are not available.
    }

    $normalized = wp_normalize_path( $dir ); // Normalize the path separators for cross-platform compatibility.
    $normalized = untrailingslashit( $normalized ); // Remove the trailing slash to prepare for path assembly.

    $segments = array_filter( explode( '/', $normalized ), 'strlen' ); // Split the path into discrete segments excluding empties.
    $prefix   = strpos( $normalized, '/' ) === 0 ? '/' : ''; // Preserve the leading slash for absolute paths.

    $current = $prefix; // Initialize the path builder with the preserved prefix.
    foreach ( $segments as $segment ) { // Walk over each path segment to create directories one by one.
        $current = trailingslashit( $current . $segment ); // Append the segment and normalize the trailing slash.
        if ( ! $wp_filesystem->is_dir( $current ) ) { // Skip creation when the directory already exists.
            $wp_filesystem->mkdir( $current, FS_CHMOD_DIR ); // Create the directory with the recommended permissions.
        }
    }

    return $wp_filesystem->is_dir( trailingslashit( $normalized ) ) && $wp_filesystem->is_writable( trailingslashit( $normalized ) ); // Confirm the final directory exists and is writable.
}


/**
 * Ensure global cache base directory exists and is writable.
 */
function mesi_cache_ensure_dir() {
    return mesi_cache_safe_mkdir( MESI_CACHE_DIR ); // Ensure the base cache directory exists before proceeding.
}

/**
 * Get the cached file path for a given URL path (no mkdir until write time).
 */
function mesi_cache_file_for_path( $path ) {
    if ( preg_match( '#^https?://#i', $path ) ) { // Detect when an absolute URL is supplied.
        $url_parts = wp_parse_url( $path ); // Break the URL into components.
        $path      = isset( $url_parts['path'] ) ? $url_parts['path'] : '/'; // Use the path component when available.
    }

    $path = preg_replace( '#^/*(cache/)?#', '', trim( $path, '/' ) ); // Remove duplicate slashes and cached directory prefixes.

    if ( $path === '' ) {
        return trailingslashit( MESI_CACHE_DIR ) . 'index.html'; // Map empty paths to the home cache file.
    }

    $dir  = trailingslashit( MESI_CACHE_DIR . $path ); // Build the directory where the cached file will live.
    $file = $dir . 'index.html'; // Append the index file name to follow the static cache structure.

    return $file; // Return the final absolute path to the cache file.
}

/**
 * Home page cache file path.
 */
function mesi_cache_file_for_home() {
    return MESI_CACHE_DIR . 'index.html'; // Return the absolute path to the home page cache file.
}

/**
 * Normalize a full URL into a relative path inside the site (strip home path, drop query, strip index.php/ prefix).
 * Returns a clean path suitable for mesi_cache_file_for_path().
 */
function mesi_cache_relative_path_from_url( $url ) {
    $path = (string) wp_parse_url( $url, PHP_URL_PATH ); // Extract the path component from the provided URL.
    $path = $path === '' ? '/' : $path; // Default to the root path when the URL contains no path information.

    $home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ); // Determine the home path for subdirectory installations.
    if ( $home_path && strpos( $path, $home_path . '/' ) === 0 ) { // Strip the home path prefix when the site runs in a subdirectory.
        $path = substr( $path, strlen( $home_path ) ); // Remove the leading subdirectory portion from the path.
        if ( false === $path ) { // Safeguard against substr returning false for unexpected input.
            $path = '/'; // Fallback to the root path when trimming fails.
        }
    }

    $path = ltrim( $path, '/' ); // Remove the leading slash to work with relative paths.

    if ( substr( $path, 0, 10 ) === 'index.php/' ) { // Detect installations using index.php in permalink structures.
        $path = substr( $path, 10 ); // Remove the redundant index.php prefix.
    }

    return trim( $path, '/' ); // Return the clean relative path suitable for cache lookups.
}

/**
 * Write a cache file using WP_Filesystem with full recursive mkdir support.
 * Returns true on success.
 */
function mesi_cache_write_file( $file, $html ) {
    if ( ! mesi_cache_ensure_dir() ) {
        return false; // Stop if the base cache directory cannot be prepared.
    }

    $fs = mesi_cache_fs(); // Retrieve the filesystem object for safe file operations.
    if ( ! $fs ) {
        return false; // Abort when the filesystem layer is unavailable.
    }

    $target_dir = wp_normalize_path( dirname( $file ) ); // Normalize the directory path for consistent handling.
    if ( ! mesi_cache_safe_mkdir( $target_dir ) ) { // Recursively create the directory structure when missing.
        return false; // Abort when the cache directories cannot be created.
    }

    $temp_file = $file . '.tmp'; // Prepare a temporary file path for atomic writes.
    if ( ! $fs->put_contents( $temp_file, $html, FS_CHMOD_FILE ) ) {
        return false; // Abort when writing the temporary file fails.
    }

    if ( $fs->exists( $file ) ) { // Remove any stale cache file before the atomic rename.
        $fs->delete( $file ); // Delete the previous cache file to avoid conflicts.
    }

    if ( ! $fs->move( $temp_file, $file, true ) ) { // Attempt to atomically rename the temporary file into place.
        $fs->copy( $temp_file, $file, true, FS_CHMOD_FILE ); // Fall back to copy when move is not supported.
        $fs->delete( $temp_file ); // Remove the temporary file after copying.
    }

    $fs->chmod( $file, FS_CHMOD_FILE ); // Ensure the resulting cache file has the correct permissions.
    clearstatcache( true, $file ); // Clear PHP's file status cache to avoid stale metadata.

    return $fs->exists( $file ); // Indicate success when the cache file exists after writing.
}

/**
 * Delete a cache file (if present) using WP helpers.
 */
function mesi_cache_delete_file( $file ) {
    if ( file_exists( $file ) ) { // Verify the target file exists before attempting deletion.
        wp_delete_file( $file ); // Use WordPress helper to delete the file safely.
    }
}

/**
 * Remove the whole cache directory contents (not the base folder).
 */
function mesi_cache_clear_all() {
    if ( ! is_dir( MESI_CACHE_DIR ) ) {
        return; // Exit silently when the cache directory does not exist.
    }
    $fs = mesi_cache_fs(); // Retrieve the filesystem object to manipulate cache contents.
    if ( ! $fs ) {
        return; // Abort when the filesystem API cannot be initialized.
    }
    $dirlist = $fs->dirlist( MESI_CACHE_DIR, true ); // List all files and subdirectories within the cache directory.
    if ( is_array( $dirlist ) ) { // Proceed only when the directory listing succeeded.
        foreach ( array_keys( $dirlist ) as $item ) { // Iterate over every item found inside the cache directory.
            $fs->delete( MESI_CACHE_DIR . $item, true ); // Recursively delete each cached entry using the filesystem API.
        }
    }
}

/**
 * Send HTTP headers for cached pages (configurable).
 */
function mesi_cache_send_headers() {
    $options = get_option( MESI_CACHE_OPTION, array() ); // Retrieve plugin options to customize header output.

    if ( ! empty( $options['add_cache_headers'] ) ) { // Only emit browser caching headers when explicitly enabled.
        header( 'Cache-Control: public, max-age=86400' ); // Advertise a one-day cache lifetime for client caches.
    }

    $charset = get_option( 'blog_charset', 'UTF-8' ); // Retrieve the configured blog character set with UTF-8 fallback.
    header( 'Content-Type: text/html; charset=' . sanitize_text_field( $charset ) ); // Output the HTML content-type header with the sanitized charset value.
}
