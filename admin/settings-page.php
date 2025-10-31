<?php
/**
 * MESI Cache — Admin Interface
 * ----------------------------
 * Version: 1.2.4
 * Provides an immediate-feedback admin page for cache management:
 * - Regenerate home or all caches
 * - Clear cache
 * - Manage Apache .htaccess integration
 * - Check cache status
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access when WordPress is not bootstrapped.
}

/**
 * Register the "Mesi Cache" options page under Settings menu.
 */
add_action( 'admin_menu', function() { // Register a callback that adds the settings page to the WordPress menu.
    add_options_page(
        'Mesi Cache',                // Page title
        'Mesi Cache',                // Menu title
        'manage_options',            // Capability requirement to access the page.
        'mesi-cache',                // Unique menu slug used in URLs.
        'mesi_cache_admin_page'      // Callback responsible for rendering the page.
    );
} );

/**
 * Render the Mesi Cache admin page and process form actions.
 */
function mesi_cache_admin_page() { // Render the admin interface and process submitted actions.
    if ( ! current_user_can( 'manage_options' ) ) {
        return; // Prevent unauthorized users from accessing the page.
    }

    if ( isset( $_POST['mesi_cache_action'] ) && check_admin_referer( 'mesi_cache_actions' ) ) {
        $action = sanitize_text_field( wp_unslash( $_POST['mesi_cache_action'] ) ); // Capture the requested action from the submitted form.
        $msg    = ''; // Initialize the notice message container.
        $class  = 'updated'; // Default to a success notice until proven otherwise.

        switch ( $action ) { // Decide which operation to run based on the submitted value.
            case 'regen_home':
                $ok    = function_exists( 'mesi_cache_generate_home' ) ? mesi_cache_generate_home() : false; // Attempt to regenerate the home cache when the helper exists.
                $msg   = $ok ? __( 'Home cache generated', 'mesi-cache' ) : __( 'Could not generate home cache', 'mesi-cache' ); // Build a status message for the action.
                $class = $ok ? 'updated' : 'error'; // Pick the notice class based on the result.
                break; // Exit the switch after handling the action.

            case 'regen_all':
                if ( function_exists( 'mesi_cache_generate_all' ) ) {
                    $res = mesi_cache_generate_all(); // Regenerate all caches and capture the result summary.
                    // translators: 1: number of generated items, 2: number of errors.
                    $msg = sprintf( __( 'Generated %1$d items, %2$d errors', 'mesi-cache' ), intval( $res['generated'] ), intval( $res['errors'] ) );
                    $class = 'updated'; // Treat this as a success even when errors are reported; administrators will see the counts.
                } else {
                    $msg   = __( 'Generate All function not found', 'mesi-cache' ); // Inform the user that the helper is unavailable.
                    $class = 'error'; // Display the notice as an error when the helper is missing.
                }
                break; // Exit the switch after processing the action.

            case 'clear_all':
                if ( function_exists( 'mesi_cache_clear_all' ) ) {
                    mesi_cache_clear_all(); // Invoke the helper to remove all cached files.
                    $msg = __( 'Cache cleared', 'mesi-cache' ); // Provide confirmation that the cache was cleared.
                } else {
                    $msg   = __( 'Could not clear cache (function missing)', 'mesi-cache' ); // Warn administrators that the helper is unavailable.
                    $class = 'error'; // Display the notice as an error in that scenario.
                }
                break; // Exit the switch once the action is handled.

            case 'htaccess_add':
                if ( function_exists( 'mesi_cache_insert_htaccess_block' ) ) {
                    $r     = mesi_cache_insert_htaccess_block(); // Attempt to add or update the custom .htaccess block.
                    $msg   = is_wp_error( $r ) ? __( 'Could not write .htaccess', 'mesi-cache' ) : __( 'MESI-Cache block written to .htaccess', 'mesi-cache' ); // Build a contextual message based on the result.
                    $class = is_wp_error( $r ) ? 'error' : 'updated'; // Choose the notice class that matches the outcome.
                } else {
                    $msg   = __( 'Function not available', 'mesi-cache' ); // Inform the administrator that the helper is missing.
                    $class = 'error'; // Display the notice as an error when the helper cannot be found.
                }
                break; // Exit the switch after processing the action.

            case 'htaccess_remove':
                if ( function_exists( 'mesi_cache_remove_htaccess_block' ) ) {
                    $r     = mesi_cache_remove_htaccess_block(); // Attempt to remove the custom .htaccess block.
                    $msg   = is_wp_error( $r ) ? __( 'Could not modify .htaccess', 'mesi-cache' ) : __( 'MESI-Cache block removed', 'mesi-cache' ); // Build a message describing the outcome.
                    $class = is_wp_error( $r ) ? 'error' : 'updated'; // Use the error styling when removal fails.
                } else {
                    $msg   = __( 'Function not available', 'mesi-cache' ); // Inform the administrator that the helper is missing.
                    $class = 'error'; // Display the notice as an error when the helper cannot be found.
                }
                break; // Exit the switch for this action.

            case 'check_status':
                if ( function_exists( 'mesi_cache_check_status' ) ) {
                    $res = mesi_cache_check_status(); // Run the verification helper to gather diagnostics.
                    $msg = isset( $res['msg'] ) ? $res['msg'] : __( 'Unknown result', 'mesi-cache' ); // Use the message returned by the helper or fall back to a generic string.
                    if ( isset( $res['status'] ) ) {
                        if ( 'static' === $res['status'] ) {
                            $class = 'updated'; // Treat successful verification as a standard success notice.
                        } elseif ( 'dynamic' === $res['status'] ) {
                            $class = 'notice-warning'; // Highlight dynamic status as a warning for administrators.
                        } else {
                            $class = 'error'; // Default to an error notice for unknown statuses.
                        }
                    }
                } else {
                    $msg   = __( 'Check status function not found', 'mesi-cache' ); // Warn that the helper is unavailable.
                    $class = 'error'; // Display the notice as an error in that case.
                }
                break; // Exit the switch once the action is processed.

            default:
                $msg   = __( 'Unknown action', 'mesi-cache' ); // Provide a generic error when an unsupported action is submitted.
                $class = 'error'; // Style the notice as an error for unknown actions.
        }

        echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $msg ) . '</p></div>'; // Output the admin notice immediately above the interface.
    }

    // ---------------------------------------------------------------------
    // Render main admin interface
    // ---------------------------------------------------------------------
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Mesi Cache', 'mesi-cache' ); ?></h1>

        <h2><?php esc_html_e( 'Cache Management', 'mesi-cache' ); ?></h2>

        <form method="post" style="display:inline-block;margin-right:6px;">
            <?php wp_nonce_field( 'mesi_cache_actions' ); ?>
            <input type="hidden" name="mesi_cache_action" value="regen_home">
            <button class="button button-primary">
                <?php esc_html_e( 'Regenerate Home', 'mesi-cache' ); ?>
            </button>
        </form>

        <form method="post" style="display:inline-block;margin-right:6px;">
            <?php wp_nonce_field( 'mesi_cache_actions' ); ?>
            <input type="hidden" name="mesi_cache_action" value="regen_all">
            <button class="button">
                <?php esc_html_e( 'Generate All', 'mesi-cache' ); ?>
            </button>
        </form>

        <form method="post" style="display:inline-block;">
            <?php wp_nonce_field( 'mesi_cache_actions' ); ?>
            <input type="hidden" name="mesi_cache_action" value="clear_all">
            <button class="button">
                <?php esc_html_e( 'Clear All Cache', 'mesi-cache' ); ?>
            </button>
        </form>

        <hr>

        <h2><?php esc_html_e( 'Apache Integration', 'mesi-cache' ); ?></h2>

        <form method="post" style="display:inline-block;margin-right:6px;">
            <?php wp_nonce_field( 'mesi_cache_actions' ); ?>
            <input type="hidden" name="mesi_cache_action" value="htaccess_add">
            <button class="button">
                <?php esc_html_e( 'Generate / Update MESI-Cache Block', 'mesi-cache' ); ?>
            </button>
        </form>

        <form method="post" style="display:inline-block;">
            <?php wp_nonce_field( 'mesi_cache_actions' ); ?>
            <input type="hidden" name="mesi_cache_action" value="htaccess_remove">
            <button class="button">
                <?php esc_html_e( 'Remove MESI-Cache Block', 'mesi-cache' ); ?>
            </button>
        </form>

        <hr>

        <h2><?php esc_html_e( 'Status Verifier', 'mesi-cache' ); ?></h2>

        <form method="post">
            <?php wp_nonce_field( 'mesi_cache_actions' ); ?>
            <input type="hidden" name="mesi_cache_action" value="check_status">
            <button class="button button-secondary">
                <?php esc_html_e( 'Check Cache Status', 'mesi-cache' ); ?>
            </button>
        </form>

        <p><small>
            <?php
            // translators: %s: plugin version number
            printf( esc_html__( 'Mesi Cache v%s — immediate feedback version.', 'mesi-cache' ), esc_html( MESI_CACHE_VERSION ) );
            ?>
        </small></p>
    </div>
    <?php
}