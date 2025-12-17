<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access when WordPress is not bootstrapped.
}

/**
 * ============================================================================
 * Serve cached HTML when available (front-end only)
 * ============================================================================
 */
add_action( 'init', function() {
    if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
        return; // Skip serving cached files within admin, REST, AJAX, or cron contexts.
    }

    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET'; // Determine the HTTP verb safely with a fallback.
    if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
        return; // Only serve cached output for safe idempotent requests.
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // Capture the requested URI while sanitizing user input.

    if ( false !== strpos( $request_uri, 'preview=true' ) || false !== strpos( $request_uri, '/feed/' ) || false !== strpos( $request_uri, '?' ) ) {
        return; // Bypass caching for preview links, feeds, or any request carrying query parameters.
    }

    $options = get_option( MESI_CACHE_OPTION, array() ); // Load plugin settings to honor bypass preferences.
    if ( ! empty( $options['bypass_logged_in'] ) && is_user_logged_in() ) {
        return; // Respect the option to disable caching for logged-in users.
    }

    $path_only = strtok( $request_uri, '?' ); // Strip query strings to compute the path portion only.
    $home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ); // Determine the home path for subdirectory installs.
    $relative  = ( $home_path && strpos( $path_only, $home_path . '/' ) === 0 )
        ? substr( $path_only, strlen( $home_path ) + 1 )
        : ltrim( $path_only, '/' ); // Normalize the request path to a relative representation.

    $relative = (string) preg_replace( '#^index\\.php/#', '', $relative ); // Remove index.php permalink prefixes when present.

    $file = ( $relative === '' || $relative === 'index.php' )
        ? mesi_cache_file_for_home()
        : mesi_cache_file_for_path( $relative ); // Resolve the absolute path to the cached file that matches the request.

    $needs_home_refresh = ( $relative === '' || $relative === 'index.php' ) && get_transient( 'mesi_cache_home_pending' ); // Do not serve stale home cache flagged for regeneration.

    if ( ! $needs_home_refresh && file_exists( $file ) && is_readable( $file ) ) {
        header( 'X-MESI-Cache: HIT' ); // Advertise the cache hit via a custom response header.
        mesi_cache_send_headers(); // Emit the configured cache headers for downstream caches.
        if ( mesi_cache_stream_file( $file ) ) { // Stream the cached HTML to the browser using PHP's native file streaming.
            exit; // Terminate the request after serving the cached response.
        }
    }
}, 0 );

/**
 * ============================================================================
 * Capture and store HTML on first visit (front-end)
 * ============================================================================
 */
add_action( 'template_redirect', function() {
    if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
        return; // Do not start output buffering for admin, REST, AJAX, or cron requests.
    }

    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET'; // Determine the current HTTP verb safely.
    if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
        return; // Only cache safe request methods to avoid storing dynamic submissions.
    }

    $current_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // Capture the current request URI safely.
    if ( false !== strpos( $current_uri, '?' ) ) {
        return; // Skip caching when the request carries a query string.
    }

    if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
        return; // Avoid caching personalized content for logged-in editors.
    }

    // WP 6.9 hardened internal HTTP handling; capturing live template output is required to mirror real responses reliably.
    ob_start( function( $html ) {
        if ( ! is_string( $html ) || strlen( $html ) < 128 ) {
            return $html; // Ignore responses that are not meaningful HTML documents.
        }

        if ( function_exists( 'is_404' ) && is_404() ) {
            return $html; // Do not cache 404 responses.
        }
        if ( stripos( $html, '<html' ) === false ) {
            return $html; // Skip non-HTML responses entirely.
        }
        if ( preg_match( '/404|page not found/i', $html ) ) {
            return $html; // Avoid caching content that likely represents error pages.
        }

        $status_code = function_exists( 'http_response_code' ) ? http_response_code() : 200; // Detect the HTTP status code when available.
        if ( 200 !== $status_code ) {
            return $html; // Only cache successful responses.
        }

        $uri_value = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // Capture the request URI safely for cache placement.
        $clean_uri = strtok( $uri_value, '?' ); // Strip the query string to operate on the path portion only.

        $parsed_uri = wp_parse_url( $clean_uri ); // Parse the URI to extract the path segment.
        $uri_path   = is_array( $parsed_uri ) && isset( $parsed_uri['path'] ) ? $parsed_uri['path'] : $clean_uri; // Use the parsed path when available.
        $extension  = strtolower( pathinfo( $uri_path, PATHINFO_EXTENSION ) ); // Detect the requested file extension.

        $disallowed_extensions = array( 'css', 'js', 'ico', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'txt', 'xml', 'json', 'woff', 'woff2', 'ttf', 'eot', 'pdf' ); // List of file types that should not be cached as HTML.
        if ( in_array( $extension, $disallowed_extensions, true ) ) {
            return $html; // Abort caching when the request appears to be for a static asset.
        }

        if ( ! ( is_singular( array( 'post', 'page' ) ) || is_home() || is_front_page() || is_category() || is_author() ) ) {
            return $html; // Restrict caching to templates that have explicit invalidation rules.
        }

        $relative_path = mesi_cache_relative_path_from_url( $clean_uri ); // Convert the request URI into a relative cache key.
        $relative_path = $relative_path === '' ? '/' : $relative_path; // Normalize empty paths to the root identifier.
        $cache_file    = ( '/' === $relative_path ) ? mesi_cache_file_for_home() : mesi_cache_file_for_path( $relative_path ); // Resolve the cache file based on the relative path.

        mesi_cache_write_file( $cache_file, $html ); // Persist the freshly generated HTML into the cache store.

        if ( '/' === $relative_path ) {
            delete_transient( 'mesi_cache_home_pending' ); // Clear the regeneration flag once the homepage has been refreshed.
        }

        header( 'X-MESI-Cache: MISS -> STORED' ); // Expose a debugging header indicating the cache has been populated.
        return $html; // Return the original buffer so the response continues normally.
    } );
}, 0 );

/**
 * ============================================================================
 * Invalidate relevant cache files when a post or page is published.
 * ============================================================================
 */
add_action( 'save_post', function( $post_id, $post, $update ) {
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return; // Skip autosave and revision events because they do not represent live content.
    }
    if ( get_post_status( $post_id ) !== 'publish' ) {
        return; // Only invalidate cache entries for published content.
    }

    if ( defined( 'MESI_CACHE_INVALIDATING' ) ) {
        return; // Avoid recursive invalidation loops.
    }
    define( 'MESI_CACHE_INVALIDATING', true ); // Mark the current execution path to prevent re-entrancy.

    $home_file = mesi_cache_file_for_home(); // Determine the cache file used for the home page.
    if ( file_exists( $home_file ) ) {
        wp_delete_file( $home_file ); // Remove the home cache whenever content changes.
    }
    set_transient( 'mesi_cache_home_pending', 1, HOUR_IN_SECONDS ); // Flag the homepage for regeneration on the next visit without relying on wp_remote_get().

    if ( get_post_type( $post_id ) === 'post' ) {
        $categories = get_the_category( $post_id ); // Fetch all categories assigned to the post.
        if ( ! empty( $categories ) ) {
            foreach ( $categories as $cat ) {
                $cat_path = 'category/' . $cat->slug; // Build the relative cache path for the category archive.
                $cat_file = mesi_cache_file_for_path( $cat_path ); // Resolve the absolute cache file location.
                if ( file_exists( $cat_file ) ) {
                    wp_delete_file( $cat_file ); // Remove the cached category archive when necessary.
                }
            }
        }

        $author = get_userdata( $post->post_author ); // Retrieve the author information for the post.
        if ( $author && ! empty( $author->user_nicename ) ) {
            $author_path = 'author/' . $author->user_nicename; // Build the relative cache key for the author archive.
            $author_file = mesi_cache_file_for_path( $author_path ); // Resolve the absolute cache file for the author archive.
            if ( file_exists( $author_file ) ) {
                wp_delete_file( $author_file ); // Remove the cached author archive to keep it fresh.
            }
        }
    }
}, 10, 3 );