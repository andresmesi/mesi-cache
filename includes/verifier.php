<?php
/**
 * MESI Cache — Environment Verifier
 * ---------------------------------
 * Checks the server environment to ensure the cache system
 * can write, read, and serve cached files properly.
 *
 * Version: 1.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access when WordPress is not bootstrapped.
}

/**
 * Display a verification table with environment diagnostics.
 * Called from the admin settings page (mesi_cache_display_verification_results()).
 */
function mesi_cache_display_verification_results() {
    require_once ABSPATH . 'wp-admin/includes/file.php'; // Load filesystem helpers needed for diagnostics.
    WP_Filesystem(); // Initialize the filesystem API to gather directory details.
    global $wp_filesystem; // Access the global filesystem instance for the current request.

    $results = array(); // Prepare a container for all verification checks.

    $results[] = array(
        'label' => __( 'Cache directory exists', 'mesi-cache' ), // Describe the check performed.
        'ok'    => is_dir( MESI_CACHE_DIR ), // Confirm the cache directory is present on disk.
        'info'  => MESI_CACHE_DIR, // Display the absolute cache directory path to the administrator.
    );

    $results[] = array(
        'label' => __( 'Cache directory writable', 'mesi-cache' ), // Describe the writability check.
        'ok'    => ( $wp_filesystem && $wp_filesystem->is_writable( MESI_CACHE_DIR ) ), // Determine whether the directory is writable.
        'info'  => ( $wp_filesystem && $wp_filesystem->is_writable( MESI_CACHE_DIR ) ) ? __( 'Writable', 'mesi-cache' ) : __( 'Not writable', 'mesi-cache' ), // Provide human-readable context for the result.
    );

    $results[] = array(
        'label' => __( 'WordPress Filesystem API initialized', 'mesi-cache' ), // Describe the filesystem initialization check.
        'ok'    => (bool) $wp_filesystem, // Test if the filesystem global is ready.
        'info'  => $wp_filesystem ? __( 'Active', 'mesi-cache' ) : __( 'Inactive', 'mesi-cache' ), // Provide context for the status.
    );

    $htaccess_file = trailingslashit( wp_normalize_path( realpath( ABSPATH ) ) ) . '.htaccess'; // Resolve the absolute .htaccess location.

    $results[] = array(
        'label' => __( '.htaccess file present', 'mesi-cache' ), // Describe the .htaccess presence check.
        'ok'    => file_exists( $htaccess_file ), // Confirm that the .htaccess file exists on disk.
        'info'  => $htaccess_file, // Display the resolved path for transparency.
    );

    $has_block = false; // Default to assuming the MESI block is not yet installed.
    if ( file_exists( $htaccess_file ) ) { // Only inspect the file when it is present.
        $contents = file_get_contents( $htaccess_file ); // Read the contents of .htaccess for pattern matching.
        if ( false !== $contents ) {
            $normalized = preg_replace( '/^\xEF\xBB\xBF/', '', str_replace( "\r", '', $contents ) ); // Strip BOM and normalize line endings.
            $has_block  = ( stripos( $normalized, '# BEGIN MESI-Cache' ) !== false ); // Detect the custom rewrite block ignoring case.
        }
    }

    $results[] = array(
        'label' => __( 'MESI-Cache block in .htaccess', 'mesi-cache' ), // Describe the block detection result.
        'ok'    => $has_block, // Indicate whether the block exists.
        'info'  => $has_block ? __( 'Block detected', 'mesi-cache' ) : __( 'Not found', 'mesi-cache' ), // Provide readable status information.
    );

    $cache_files = 0; // Track how many cached HTML files are currently stored.
    if ( is_dir( MESI_CACHE_DIR ) ) { // Ensure the directory exists before globbing.
        $found = glob( trailingslashit( MESI_CACHE_DIR ) . '*.html' ); // Locate cached HTML files at the root of the cache directory.
        if ( is_array( $found ) ) {
            $cache_files = count( $found ); // Count the number of cache files discovered.
        }
    }

    $results[] = array(
        'label' => __( 'Cached HTML files', 'mesi-cache' ), // Describe the cache file count.
        'ok'    => ( $cache_files > 0 ), // Consider the check successful when at least one cache file exists.
        // translators: %d: number of cached HTML files detected during verification.
        'info'  => sprintf( _n( '%d file', '%d files', $cache_files, 'mesi-cache' ), $cache_files ), // Present the total with pluralization support.
    );

    echo '<table class="widefat striped" style="max-width:700px;margin-top:10px">'; // Render the container table for results.
    echo '<thead><tr><th>' . esc_html__( 'Check', 'mesi-cache' ) . '</th><th>' . esc_html__( 'Result', 'mesi-cache' ) . '</th><th>' . esc_html__( 'Info', 'mesi-cache' ) . '</th></tr></thead><tbody>'; // Output the table header.

    foreach ( $results as $row ) { // Iterate over each diagnostic row collected above.
        echo '<tr>'; // Begin a new table row.
        echo '<td>' . esc_html( $row['label'] ) . '</td>'; // Display the label for the current check.
        echo '<td>' . ( $row['ok']
            ? '<span style="color:green;font-weight:bold;">✔ ' . esc_html__( 'OK', 'mesi-cache' ) . '</span>' // Show a green success indicator when the check passes.
            : '<span style="color:red;font-weight:bold;">✖ ' . esc_html__( 'FAIL', 'mesi-cache' ) . '</span>' // Show a red failure indicator when the check fails.
        ) . '</td>'; // Close the status cell after inserting the indicator.
        echo '<td>' . esc_html( $row['info'] ) . '</td>'; // Display additional context for the current check.
        echo '</tr>'; // Close the table row markup.
    }

    echo '</tbody></table>'; // Close the table and tbody tags to complete the markup.
}

/**
 * Compact wrapper for verifier — used by admin "Check Cache Status".
 * ---------------------------------------------------------------
 * Returns a summary array { status, msg } so that settings-page.php
 * can show a single-line message but still leverage the full verifier.
 */
function mesi_cache_check_status() {
    ob_start(); // Begin buffering so the table markup can follow the notice neatly.

    if ( function_exists( 'mesi_cache_display_verification_results' ) ) { // Ensure the diagnostic renderer exists.
        mesi_cache_display_verification_results(); // Output the diagnostic table immediately.
        ob_end_flush(); // Flush the buffer so the table appears beneath the notice.
        return array(
            'status' => 'static', // Flag the result as static to indicate success.
            'msg'    => __( 'Verification completed — see detailed table below.', 'mesi-cache' ), // Provide a descriptive message for administrators.
        );
    }

    ob_end_clean(); // Discard any captured output when diagnostics are unavailable.
    return array(
        'status' => 'error', // Flag the result as an error.
        'msg'    => __( 'Verifier not available.', 'mesi-cache' ), // Inform the administrator that diagnostics could not run.
    );
}
