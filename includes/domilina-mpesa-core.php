<?php
defined( 'ABSPATH' ) || exit;

/**
 * DOMILINA_Payment_Logger
 *
 * Wraps WooCommerce's WC_Logger for plugin-specific debug messages.
 * Logs are written only when WP_DEBUG is active.
 */
class DOMILINA_Payment_Logger {

	private $handle = 'domilina-mpesa';
	private $wc_log;

	public function __construct() {
		$this->wc_log = class_exists( 'WC_Logger' ) ? wc_get_logger() : null;
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * @param string $msg   The message to log.
	 * @param string $level PSR-3 log level (debug, info, error …).
	 */
	public function write( $msg, $level = 'debug' ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $this->wc_log ) {
			$this->wc_log->log( $level, $msg, [ 'source' => $this->handle ] );
		}
	}
}

/**
 * DOMILINA_Gateway_Installer
 *
 * Handles plugin activation: creates the custom transactions table.
 */
class DOMILINA_Gateway_Installer {

	/**
	 * Run on plugin activation.
	 */
	public static function setup() {
		if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( DOMILINA_MPESA_PLUGIN_FILE ) );
		wp_die(
			wp_kses_post( sprintf(
				/* translators: 1: opening anchor tag, 2: closing anchor tag */
				__( 'DG Lipa na Mpesa Checkout requires %1$sWooCommerce%2$s to be active before it can be activated.', 'dominicn-lipa-na-mpesa-stk-push-checkout' ),
					'<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">',
					'</a>'
				) ),
				esc_html__( 'Plugin Activation Error', 'dominicn-lipa-na-mpesa-stk-push-checkout' ),
				[ 'back_link' => true ]
			);
		}

		global $wpdb;
		$table      = $wpdb->prefix . 'domilina_mpesa_transactions';
		$collation  = $wpdb->get_charset_collate();

		$ddl = "CREATE TABLE IF NOT EXISTS {$table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id      BIGINT(20) UNSIGNED NOT NULL,
			transaction_id VARCHAR(100) NOT NULL,
			phone_number  VARCHAR(20) NOT NULL,
			amount        DECIMAL(10,2) NOT NULL,
			status        VARCHAR(50) NOT NULL,
			date_created  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$collation};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $ddl );
	}

	/** Run on plugin deactivation (placeholder). */
	public static function teardown() {}
}
