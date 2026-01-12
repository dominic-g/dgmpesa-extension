<?php
/**
 * Plugin Name: Lipa na Mpesa Checkout for WooCommerce
 * Plugin URI:  https://dominicn.dev 
 * Description: Accept M-Pesa STK Push payments in WooCommerce. A simple and reliable way to integrate Kenya's most popular payment method.
 * Version:     1.2.0 
 * Author:      Dominic_N
 * Author URI:  https://dominicn.dev
 * Text Domain: dg-checkout-for-m-pesa
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Define constants */
define( 'DG_MPESA_VERSION', '1.2.0' );
define( 'DG_MPESA_PLUGIN_FILE', __FILE__ );
define( 'DG_MPESA_PLUGIN_PATH', plugin_dir_path( DG_MPESA_PLUGIN_FILE ) );
define( 'DG_MPESA_PLUGIN_URL', plugin_dir_url( DG_MPESA_PLUGIN_FILE ) );

/**
 * Include the installer file.
 */
require_once DG_MPESA_PLUGIN_PATH . 'includes/class-dg-mpesa-checkout-install.php';

/**
 * Activation/Deactivation Hooks.
 */
register_activation_hook( __FILE__, [ 'DG_Mpesa_Checkout_Install', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'DG_Mpesa_Checkout_Install', 'deactivate' ] );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register( function ( $class_name ) {
    $prefix   = 'DG_Mpesa_Checkout_';
    $base_dir = DG_MPESA_PLUGIN_PATH . 'includes/';
    if ( 0 !== strpos( $class_name, $prefix ) ) {
        return;
    }
    $relative_class = substr( $class_name, strlen( $prefix ) );
    $file_name      = 'class-dg-mpesa-checkout-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
    $file_path      = $base_dir . $file_name;
    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
} );

/**
 * Initialize the plugin after WooCommerce loads.
 */
add_action( 'plugins_loaded', 'dg_mpesa_checkout_plugin_init', 20 );
function dg_mpesa_checkout_plugin_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="error"><p>' . sprintf( esc_html__( 'Finachub Lipa na Mpesa Checkout requires %sWooCommerce%s to be installed and activated.', 'dg-checkout-for-m-pesa' ), '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">', '</a>' ) . '</p></div>';
        });
        return;
    }

    // Load text domain for localization
    load_plugin_textdomain( 'dg-checkout-for-m-pesa', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    // Register the Payment Gateway.
    add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
        $methods[] = 'DG_Mpesa_Checkout_Gateway';
        return $methods;
    } );

    // Initialize the Admin Dashboard.
    if ( is_admin() && class_exists( 'DG_Mpesa_Checkout_Admin_Dashboard' ) ) {
        new DG_Mpesa_Checkout_Admin_Dashboard();
    }

    if ( class_exists( 'DG_Mpesa_Checkout_Callback' ) ) {
        new DG_Mpesa_Checkout_Callback();
    }
}

/**
 * Enqueue front-end CSS & JS.
 */
add_action( 'wp_enqueue_scripts', function() {
    // Enqueue the Jost font for front-end.
    wp_enqueue_style(
        'dg_mpesa_jost_font_frontend',
        'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap',
        [],
        '1.0' // Font version doesn't need to match plugin version
    );

    // Styles for checkout page and waiting page
    if ( is_checkout() || dg_mpesa_is_waiting_page() ) {
        wp_enqueue_style(
            'dg_mpesa_frontend_styles',
            DG_MPESA_PLUGIN_URL . 'assets/css/mpesa-frontend-styles.css',
            ['dg_mpesa_jost_font_frontend'],
            DG_MPESA_VERSION
        );
    }


     if ( dg_mpesa_is_waiting_page() ) {
         $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
         wp_enqueue_script(
            'dg_mpesa_waiting_script',
            DG_MPESA_PLUGIN_URL . 'assets/js/mpesa-waiting.js',
            [ 'jquery' ],
            DG_MPESA_VERSION,
            true
        );
        // Localize script to pass order data and AJAX URL
        wp_localize_script( 'dg_mpesa_waiting_script', 'dgMpesaParams', [
             'ajax_url' => admin_url( 'admin-ajax.php' ),
             'order_id' => $order_id,
             'nonce'    => wp_create_nonce( 'dg_poll_status' )
         ] );
     }
} );

/**
 * Helper function to check if we are on the M-Pesa waiting page.
 */
function dg_mpesa_is_waiting_page() {
    // Check for waiting flag, order ID, and a valid nonce
    return ( isset( $_GET['mpesa_waiting'], $_GET['order_id'], $_GET['_wpnonce'] ) &&
             'yes' === $_GET['mpesa_waiting'] &&
             wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dg_waiting' )
           );
}


/**
 * Redirect user to custom waiting page if needed.
 */
add_action( 'template_redirect', 'dg_mpesa_checkout_maybe_show_waiting_screen' );
function dg_mpesa_checkout_maybe_show_waiting_screen() {
    if ( dg_mpesa_is_waiting_page() ) { // Use helper function which includes nonce check

        $order_id = absint( $_GET['order_id'] );
        $order = wc_get_order( $order_id );

        // Validate order and user access
        $is_valid_user = is_user_logged_in() && ( $order && $order->get_user_id() === get_current_user_id() || current_user_can( 'manage_woocommerce' ) );
        $guest_order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        $is_valid_guest = ! is_user_logged_in() && $order && method_exists($order, 'key_is_valid') && $order->key_is_valid( $guest_order_key );

        if ( ! $order || ( ! $is_valid_user && ! $is_valid_guest ) ) {
             wp_safe_redirect( home_url('/') ); // Redirect silently for invalid access
             exit;
        }

        dg_mpesa_checkout_render_waiting_page( $order_id );
        exit;
    }
}

/**
 * AJAX handler for polling the order status.
 */
add_action( 'wp_ajax_dg_poll_status', 'dg_mpesa_checkout_poll_status' );
add_action( 'wp_ajax_nopriv_dg_poll_status', 'dg_mpesa_checkout_poll_status' );

function dg_mpesa_checkout_poll_status() {
    // Check nonce and required parameters
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'dg_poll_status' ) ) {
        wp_send_json_error( [ 'status' => 'error', 'message' => 'Nonce verification failed.' ] );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        wp_send_json_error( [ 'status' => 'error', 'message' => 'Invalid order.' ] );
    }

    $status = $order->get_status();

    if ( in_array( $status, [ 'processing', 'completed' ] ) ) {
        // Payment is successful, redirect to order received page
        wp_send_json_success( [
            'status'   => 'success',
            'redirect' => $order->get_checkout_order_received_url()
        ] );
    } elseif ( in_array( $status, [ 'failed', 'cancelled' ] ) ) {
        // Payment failed, redirect to view order page (to see failure note)
        wp_send_json_success( [
            'status'   => 'failure',
            'redirect' => $order->get_view_order_url()
        ] );
    } else {
        // Still pending/on-hold, keep polling
        wp_send_json_success( [ 'status' => $status ] );
    }
}

/**
 * Render the M-Pesa waiting page.
 */
function dg_mpesa_checkout_render_waiting_page( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_die( esc_html__( 'Invalid order specified.', 'dg-checkout-for-m-pesa' ) );
    }

    // Upsell notice with updated URL (REMOVED UPSELL FOR AUTOMATION)
    $upgrade_notice_html = ''; 


    $logo_url = DG_MPESA_PLUGIN_URL . 'assets/img/mpesa-logo.png';

    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
    // ... (head content remains the same)
    </head>
    <body <?php body_class( 'mpesa-waiting-body' ); ?>>
        <div id="mpesa-waiting-container-main" class="mpesa-waiting-container">
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'M-Pesa Logo', 'dg-checkout-for-m-pesa' ); ?>" class="mpesa-waiting-logo">

            <div class="mpesa-waiting-spinner">
                <svg viewBox="25 25 50 50">
                    <circle cx="50" cy="50" r="20"></circle>
                </svg>
            </div>

            <h2 id="mpesa-waiting-title"><?php esc_html_e( 'Please Confirm Payment on Your Phone', 'dg-checkout-for-m-pesa' ); ?></h2>
            <p class="mpesa-instruction" id="mpesa-waiting-instruction"><?php esc_html_e( 'An M-Pesa payment request has been sent. Please enter your M-Pesa PIN on your phone to authorize the payment.', 'dg-checkout-for-m-pesa' ); ?></p>

            <?php echo wp_kses_post( $upgrade_notice_html ); // Output the notice (now empty) ?>

            <div class="mpesa-waiting-info">
                 <p><strong><?php esc_html_e( 'Order Number:', 'dg-checkout-for-m-pesa' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></p>
                 <p><strong><?php esc_html_e( 'Amount:', 'dg-checkout-for-m-pesa' ); ?></strong> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
                 <p><small><em id="mpesa-waiting-status-text"><?php esc_html_e( 'Waiting for M-Pesa confirmation...', 'dg-checkout-for-m-pesa' ); ?></em></small></p>
            </div>

    
        </div>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}
?>