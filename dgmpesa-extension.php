<?php
/**
 * Plugin Name: Lipa na Mpesa Checkout for WooCommerce
 * Plugin URI:  https://dominicn.dev
 * Description: Accept M-Pesa STK Push payments in WooCommerce. A simple and reliable way to integrate Kenya's most popular payment method.
 * Version:     1.3.2
 * Author:      Dominic_N
 * Author URI:  https://dominicn.dev
 * Text Domain: dg-checkout-for-m-pesa
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'DG_MPESA_VERSION',     '1.3.2' );
define( 'DG_MPESA_PLUGIN_FILE', __FILE__ );
define( 'DG_MPESA_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DG_MPESA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Early includes — no WooCommerce dependency
// ---------------------------------------------------------------------------

// Must be available before the activation hook fires.
require_once DG_MPESA_PLUGIN_PATH . 'includes/dg-mpesa-core.php'; // DG_Gateway_Installer, DG_Payment_Logger

// All other includes are loaded inside dg_mpesa_boot() after WooCommerce is confirmed present.

// ---------------------------------------------------------------------------
// Activation / Deactivation hooks
// ---------------------------------------------------------------------------

register_activation_hook(   __FILE__, [ 'DG_Gateway_Installer', 'setup' ] );
register_deactivation_hook( __FILE__, [ 'DG_Gateway_Installer', 'teardown' ] );

// ---------------------------------------------------------------------------
// Bootstrap on plugins_loaded
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'dg_mpesa_boot', 20 );

function dg_mpesa_boot() {
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
					__( 'DG Lipa na Mpesa Checkout has been deactivated because it requires %sWooCommerce%s to be active.', 'dg-checkout-for-m-pesa' ),
					'<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">',
					'</a>'
				) )
			);
		} );
		return;
	}

	// Load WooCommerce-dependent classes now that WC is confirmed available.
	require_once DG_MPESA_PLUGIN_PATH . 'includes/dg-mpesa-api.php';     // DG_Mpesa_Api_Client
	require_once DG_MPESA_PLUGIN_PATH . 'includes/dg-mpesa-gateway.php'; // DG_Mpesa_Payment_Gateway
	require_once DG_MPESA_PLUGIN_PATH . 'includes/dg-mpesa-webhook.php'; // DG_Mpesa_Webhook_Handler
	require_once DG_MPESA_PLUGIN_PATH . 'includes/dg-mpesa-admin.php';   // DG_Mpesa_Admin_Panel, DG_Mpesa_Tx_Queries

	load_plugin_textdomain( 'dg-checkout-for-m-pesa', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	// Register the payment gateway with WooCommerce
	add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) {
		$gateways[] = 'DG_Mpesa_Payment_Gateway';
		return $gateways;
	} );

	// Boot admin panel
	if ( is_admin() ) {
		new DG_Mpesa_Admin_Panel();
	}

	// Boot callback/webhook listener
	new DG_Mpesa_Webhook_Handler();

	// ---------------------------------------------------------------------------
	// Hooks that depend on WooCommerce
	// ---------------------------------------------------------------------------

	// Block checkout integration
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'dg_mpesa_init_blocks' );

	// Front-end asset enqueueing
	add_action( 'wp_enqueue_scripts', 'dg_mpesa_enqueue_frontend' );

	// Template intercept: render waiting screen before theme loads
	add_action( 'template_redirect', 'dg_mpesa_intercept_template' );

	// AJAX: poll WooCommerce order status (used by the waiting-screen JS)
	add_action( 'wp_ajax_dg_poll_status',        'dg_mpesa_handle_poll' );
	add_action( 'wp_ajax_nopriv_dg_poll_status', 'dg_mpesa_handle_poll' );
}

// ---------------------------------------------------------------------------
// Block checkout integration registration (logic moved inside boot)
// ---------------------------------------------------------------------------

function dg_mpesa_init_blocks( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
	require_once DG_MPESA_PLUGIN_PATH . 'includes/blocks/dg-mpesa-blocks.php';
	$registry->register( new DG_Mpesa_Block_Payment() );
}

// Front-end asset enqueueing logic
function dg_mpesa_enqueue_frontend() {
	wp_enqueue_style(
		'dg_mpesa_jost_font_frontend',
		'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap',
		[],
		'1.0'
	);

	if ( is_checkout() || dg_mpesa_on_pending_screen() ) {
		wp_enqueue_style(
			'dg_mpesa_frontend_styles',
			DG_MPESA_PLUGIN_URL . 'assets/css/mpesa-frontend-styles.css',
			[ 'dg_mpesa_jost_font_frontend' ],
			DG_MPESA_VERSION
		);
	}

	if ( dg_mpesa_on_pending_screen() ) {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		wp_enqueue_script(
			'dg_mpesa_waiting_script',
			DG_MPESA_PLUGIN_URL . 'assets/js/mpesa-waiting.js',
			[ 'jquery' ],
			DG_MPESA_VERSION,
			true
		);

		wp_localize_script( 'dg_mpesa_waiting_script', 'dgMpesaParams', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'order_id' => $order_id,
			'nonce'    => wp_create_nonce( 'dg_poll_status' ),
		] );
	}
}

// ---------------------------------------------------------------------------
// Helper: detect the M-Pesa payment-pending screen
// ---------------------------------------------------------------------------

function dg_mpesa_on_pending_screen() {
	return (
		isset( $_GET['mpesa_waiting'], $_GET['order_id'], $_GET['_wpnonce'] ) &&
		'yes' === $_GET['mpesa_waiting'] &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dg_waiting' )
	);
}

// ---------------------------------------------------------------------------
// Template intercept: render waiting screen before theme loads
// ---------------------------------------------------------------------------

function dg_mpesa_intercept_template() {
	if ( ! dg_mpesa_on_pending_screen() ) {
		return;
	}

	$order_id = absint( $_GET['order_id'] );
	$order    = wc_get_order( $order_id );

	// Authorisation check
	$key           = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$valid_user    = is_user_logged_in() && $order &&
	                 ( $order->get_user_id() === get_current_user_id() || current_user_can( 'manage_woocommerce' ) );
	$valid_guest   = ! is_user_logged_in() && $order &&
	                 method_exists( $order, 'key_is_valid' ) && $order->key_is_valid( $key );

	if ( ! $order || ( ! $valid_user && ! $valid_guest ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	dg_mpesa_render_pending_page( $order_id );
	exit;
}

// ---------------------------------------------------------------------------
// AJAX: poll WooCommerce order status registration (logic moved inside boot)
// ---------------------------------------------------------------------------

function dg_mpesa_handle_poll() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'dg_poll_status' ) ) {
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
			'status'   => 'failure',
			'redirect' => $order->get_checkout_order_received_url(),
		] );
	} else {
		wp_send_json_success( [ 'status' => $status ] );
	}
}

// ---------------------------------------------------------------------------
// Render: the full-page payment-pending screen
// ---------------------------------------------------------------------------

function dg_mpesa_render_pending_page( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		wp_die( esc_html__( 'Invalid order specified.', 'dg-checkout-for-m-pesa' ) );
	}

	$logo_url  = DG_MPESA_PLUGIN_URL . 'assets/img/mpesa-logo.png';
	$font_url  = 'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap';
	$style_url = DG_MPESA_PLUGIN_URL . 'assets/css/mpesa-frontend-styles.css';
	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<link rel="stylesheet" href="<?php echo esc_url( $font_url ); ?>" type="text/css">
		<link rel="stylesheet" href="<?php echo esc_url( $style_url ); ?>" type="text/css" media="all">
		<?php wp_head(); ?>
		<title><?php esc_html_e( 'Awaiting M-Pesa Payment', 'dg-checkout-for-m-pesa' ); ?></title>
	</head>
	<body <?php body_class( 'mpesa-waiting-body' ); ?>>
		<div id="mpesa-waiting-container-main" class="mpesa-waiting-container">
			<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'M-Pesa Logo', 'dg-checkout-for-m-pesa' ); ?>" class="mpesa-waiting-logo">

			<div class="mpesa-waiting-spinner">
				<svg viewBox="25 25 50 50"><circle cx="50" cy="50" r="20"></circle></svg>
			</div>

			<h2 id="mpesa-waiting-title"><?php esc_html_e( 'Please Confirm Payment on Your Phone', 'dg-checkout-for-m-pesa' ); ?></h2>
			<p class="mpesa-instruction" id="mpesa-waiting-instruction">
				<?php esc_html_e( 'An M-Pesa payment request has been sent. Enter your M-Pesa PIN to authorise.', 'dg-checkout-for-m-pesa' ); ?>
			</p>

			<div class="mpesa-waiting-info">
				<p><strong><?php esc_html_e( 'Order Number:', 'dg-checkout-for-m-pesa' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></p>
				<p><strong><?php esc_html_e( 'Amount:', 'dg-checkout-for-m-pesa' ); ?></strong> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
				<p><small><em id="mpesa-waiting-status-text"><?php esc_html_e( 'Waiting for M-Pesa confirmation…', 'dg-checkout-for-m-pesa' ); ?></em></small></p>
			</div>
		</div>
		<?php wp_footer(); ?>
	</body>
	</html>
	<?php
}