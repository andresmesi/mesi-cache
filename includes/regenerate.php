<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access when WordPress is not bootstrapped.
}

/**
 * Generate cache for the home page.
 */
function mesi_cache_generate_home() {
    $url  = home_url( '/' ); // Build the front-page URL for regeneration requests.
    $resp = wp_safe_remote_get( $url, array( 'timeout' => 15 ) ); // Request the rendered page content from WordPress safely.
    if ( is_wp_error( $resp ) ) {
        return false; // Abort when the HTTP request fails.
    }
    $body = wp_remote_retrieve_body( $resp ); // Extract the HTML body from the HTTP response.
    if ( empty( $body ) || stripos( $body, '<html' ) === false ) {
        return false; // Ensure the response is non-empty HTML before caching.
    }
    return mesi_cache_write_file( mesi_cache_file_for_home(), $body ); // Write the HTML into the dedicated home cache file.
}

/**
 * Generate cache for a specific post/page by ID.
 */
function mesi_cache_generate_post( $post_id ) {
    $url = get_permalink( $post_id ); // Resolve the permalink for the requested post or page.
    if ( ! $url ) {
        return false; // Abort when the permalink cannot be determined.
    }
    $resp = wp_safe_remote_get( $url, array( 'timeout' => 15 ) ); // Fetch the rendered post HTML via a safe remote request.
    if ( is_wp_error( $resp ) ) {
        return false; // Abort when the HTTP request fails.
    }
    $body = wp_remote_retrieve_body( $resp ); // Extract the HTML content from the response.
    if ( empty( $body ) || stripos( $body, '<html' ) === false ) {
        return false; // Only cache when we receive a meaningful HTML response.
    }
    $path = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' ); // Convert the URL to a relative path for cache placement.
    $file = mesi_cache_file_for_path( $path ); // Resolve the cache file location for the post.
    return mesi_cache_write_file( $file, $body ); // Persist the rendered HTML into the cache store.
}

/**
 * Generate cache for all published posts, pages, and taxonomy archives.
 */
function mesi_cache_generate_all() {
    $generated = 0; // Counter for successful cache writes.
    $errors    = 0; // Counter for failed cache attempts.

    $posts = get_posts( array(
        'post_type'   => array( 'post', 'page' ), // Target standard posts and pages.
        'post_status' => 'publish', // Only regenerate published content.
        'numberposts' => -1, // Fetch all matching posts.
    ) ); // Retrieve a list of content items that should be cached.
    foreach ( $posts as $p ) {
        if ( mesi_cache_generate_post( $p->ID ) ) {
            $generated++; // Increment when cache generation succeeds.
        } else {
            $errors++; // Record failures for reporting.
        }
    }

    $taxonomies = get_taxonomies( array( 'public' => true ) ); // Retrieve all public taxonomies for archive regeneration.
    foreach ( $taxonomies as $tax ) {
        $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ); // Load all terms, including empty ones.
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            continue; // Skip taxonomies that cannot be enumerated.
        }

        foreach ( $terms as $term ) {
            $link = get_term_link( $term ); // Resolve the archive URL for the term.
            if ( is_wp_error( $link ) ) {
                $errors++; // Record the failure when the permalink is not available.
                continue; // Skip this term and move on.
            }

            $resp = wp_safe_remote_get( $link, array( 'timeout' => 15 ) ); // Fetch the archive HTML for the term.
            if ( is_wp_error( $resp ) ) {
                $errors++; // Record failed HTTP requests.
                continue; // Skip caching for this term.
            }

            $body = wp_remote_retrieve_body( $resp ); // Extract the HTML content from the HTTP response.
            if ( empty( $body ) || stripos( $body, '<html' ) === false ) {
                $errors++; // Skip non-HTML responses.
                continue; // Continue with the next term.
            }

            $path = trim( wp_parse_url( $link, PHP_URL_PATH ), '/' ); // Convert the URL into a cache-relative path.
            $file = mesi_cache_file_for_path( $path ); // Determine the cache file location for the term archive.

            if ( mesi_cache_write_file( $file, $body ) ) {
                $generated++; // Record successful cache writes.
            } else {
                $errors++; // Record failed writes for diagnostics.
            }
        }
    }

    if ( mesi_cache_generate_home() ) {
        $generated++; // Count successful home page regeneration.
    } else {
        $errors++; // Record the failure so administrators can review it.
    }

    return array(
        'generated' => $generated, // Report the total number of cached items.
        'errors'    => $errors, // Report how many attempts failed.
    );
}
