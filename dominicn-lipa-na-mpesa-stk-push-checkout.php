<?php
/**
 * Plugin Name: DominicN Lipa na Mpesa STK Push Checkout for WooCommerce
 * Description: Accept M-Pesa STK Push payments in WooCommerce. A simple and reliable way to integrate Kenya's most popular payment method.
 * Version:     1.0.4
 * Author:      Dominic_N
 * Author URI:  https://dominicn.dev
 * Text Domain: dominicn-lipa-na-mpesa-stk-push-checkout
 * License:     GPLv2 or later
 * Requires Plugins: woocommerce
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'DOMILINA_MPESA_VERSION',     '1.0.4' );
define( 'DOMILINA_MPESA_PLUGIN_FILE', __FILE__ );
define( 'DOMILINA_MPESA_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DOMILINA_MPESA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Early includes — no WooCommerce dependency
// ---------------------------------------------------------------------------

// Must be available before the activation hook fires.
require_once DOMILINA_MPESA_PLUGIN_PATH . 'includes/domilina-mpesa-core.php'; // DOMILINA_Gateway_Installer, DOMILINA_Payment_Logger

// All other includes are loaded inside domilina_mpesa_boot() after WooCommerce is confirmed present.

// ---------------------------------------------------------------------------
// Activation / Deactivation hooks
// ---------------------------------------------------------------------------

register_activation_hook(   __FILE__, [ 'DOMILINA_Gateway_Installer', 'setup' ] );
register_deactivation_hook( __FILE__, [ 'DOMILINA_Gateway_Installer', 'teardown' ] );

// ---------------------------------------------------------------------------
// Bootstrap on plugins_loaded
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'domilina_mpesa_boot', 20 );

function domilina_mpesa_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		// Auto-deactivate if active without WooCommerce
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );

		add_action( 'admin_notices', static function () {
			printf(
				'<div class="error"><p>%s</p></div>',
				wp_kses_post( sprintf(
					/* translators: 1: opening anchor tag, 2: closing anchor tag */
					__( 'Lipa na Mpesa Checkout has been deactivated because it requires %1$sWooCommerce%2$s to be active.', 'dominicn-lipa-na-mpesa-stk-push-checkout' ),
					'<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">',
					'</a>'
				) )
			);
		} );
		return;
	}

	// Load WooCommerce-dependent classes now that WC is confirmed available.
	require_once DOMILINA_MPESA_PLUGIN_PATH . 'includes/domilina-mpesa-api.php';     // DOMILINA_Mpesa_Api_Client
	require_once DOMILINA_MPESA_PLUGIN_PATH . 'includes/domilina-mpesa-gateway.php'; // DOMILINA_Mpesa_Payment_Gateway
	require_once DOMILINA_MPESA_PLUGIN_PATH . 'includes/domilina-mpesa-webhook.php'; // DOMILINA_Mpesa_Webhook_Handler
	require_once DOMILINA_MPESA_PLUGIN_PATH . 'includes/domilina-mpesa-admin.php';   // DOMILINA_Mpesa_Admin_Panel, DOMILINA_Mpesa_Tx_Queries

	// Register the payment gateway with WooCommerce
	add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) {
		$gateways[] = 'DOMILINA_Mpesa_Payment_Gateway';
		return $gateways;
	} );

	// Boot admin panel
	if ( is_admin() ) {
		new DOMILINA_Mpesa_Admin_Panel();
	}

	// Boot callback/webhook listener
	new DOMILINA_Mpesa_Webhook_Handler();

	// ---------------------------------------------------------------------------
	// Hooks that depend on WooCommerce
	// ---------------------------------------------------------------------------

	// Block checkout integration
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'domilina_mpesa_init_blocks' );

	// Front-end asset enqueueing
	add_action( 'wp_enqueue_scripts', 'domilina_mpesa_enqueue_frontend' );

	// Template intercept: render waiting screen before theme loads
	add_action( 'template_redirect', 'domilina_mpesa_intercept_template' );

	// AJAX: poll WooCommerce order status (used by the waiting-screen JS)
	add_action( 'wp_ajax_domilina_poll_status',        'domilina_mpesa_handle_poll' );
	add_action( 'wp_ajax_nopriv_domilina_poll_status', 'domilina_mpesa_handle_poll' );
}

// ---------------------------------------------------------------------------
// Block checkout integration registration (logic moved inside boot)
// ---------------------------------------------------------------------------

function domilina_mpesa_init_blocks( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
	require_once DOMILINA_MPESA_PLUGIN_PATH . 'includes/blocks/domilina-mpesa-blocks.php';
	$registry->register( new DOMILINA_Mpesa_Block_Payment() );
}

// Front-end asset enqueueing logic
function domilina_mpesa_enqueue_frontend() {
	wp_enqueue_style(
		'domilina_mpesa_jost_font_frontend',
		'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap',
		[],
		'1.0'
	);

	if ( is_checkout() || domilina_mpesa_on_pending_screen() ) {
		wp_enqueue_style(
			'domilina_mpesa_frontend_styles',
			DOMILINA_MPESA_PLUGIN_URL . 'assets/css/mpesa-frontend-styles.css',
			[ 'domilina_mpesa_jost_font_frontend' ],
			DOMILINA_MPESA_VERSION
		);
	}

	if ( domilina_mpesa_on_pending_screen() ) {
		$get_order_id = filter_input( INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		$order_id     = $get_order_id ? absint( $get_order_id ) : 0;

		wp_enqueue_script(
			'domilina_mpesa_waiting_script',
			DOMILINA_MPESA_PLUGIN_URL . 'assets/js/mpesa-waiting.js',
			[ 'jquery' ],
			DOMILINA_MPESA_VERSION,
			true
		);

		wp_localize_script( 'domilina_mpesa_waiting_script', 'domilinaParams', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'order_id' => $order_id,
			'nonce'    => wp_create_nonce( 'domilina_poll_status' ),
		] );
	}
}

// ---------------------------------------------------------------------------
// Helper: detect the M-Pesa payment-pending screen
// ---------------------------------------------------------------------------

function domilina_mpesa_on_pending_screen() {
	// Validate nonce first before any other checks
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	
	if ( ! wp_verify_nonce( $nonce, 'domilina_waiting' ) ) {
		return false;
	}

	return (
		isset( $_GET['mpesa_waiting'], $_GET['order_id'] ) &&
		'yes' === $_GET['mpesa_waiting']
	);
}

// ---------------------------------------------------------------------------
// Template intercept: render waiting screen before theme loads
// ---------------------------------------------------------------------------

function domilina_mpesa_intercept_template() {
	if ( ! domilina_mpesa_on_pending_screen() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; 
	$order    = $order_id ? wc_get_order( $order_id ) : null;

	// Authorisation check
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$key           = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$valid_user    = is_user_logged_in() && $order &&
	                 ( $order->get_user_id() === get_current_user_id() || current_user_can( 'manage_woocommerce' ) );
	$valid_guest   = ! is_user_logged_in() && $order &&
	                 method_exists( $order, 'key_is_valid' ) && $order->key_is_valid( $key );

	if ( ! $order || ( ! $valid_user && ! $valid_guest ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	domilina_mpesa_render_pending_page( $order_id );
	exit;
}

// ---------------------------------------------------------------------------
// AJAX: poll WooCommerce order status registration (logic moved inside boot)
// ---------------------------------------------------------------------------

function domilina_mpesa_handle_poll() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'domilina_poll_status' ) ) {
		wp_send_json_error( [ 'status' => 'error', 'message' => 'Nonce verification failed.' ] );
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$order    = wc_get_order( $order_id );

	if ( ! $order ) {
		wp_send_json_error( [ 'status' => 'error', 'message' => 'Invalid order.' ] );
	}

	$status = $order->get_status();

	if ( in_array( $status, [ 'processing', 'completed' ], true ) ) {
		wp_send_json_success( [
			'status'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		] );
	} elseif ( in_array( $status, [ 'failed', 'cancelled' ], true ) ) {
		wp_send_json_success( [
			'status'   => 'failed',
			'redirect' => wc_get_page_permalink( 'checkout' ),
		] );
	} else {
		// Order still pending, return waiting
		wp_send_json_success( [
			'status' => 'pending',
		] );
	}
}

// ---------------------------------------------------------------------------
// Helper to render the pending/waiting screen HTML
// ---------------------------------------------------------------------------

function domilina_mpesa_render_pending_page( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	// Load the pending/waiting screen template
	include DOMILINA_MPESA_PLUGIN_PATH . 'domilina_thankyou-mpesa_checkout.php';
}
