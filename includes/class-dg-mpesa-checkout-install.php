<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DG_Mpesa_Checkout_Install
 *
 * Creates a custom table for transaction logging and forces the WooCommerce checkout page to use the classic shortcode.
 */
class DG_Mpesa_Checkout_Install {

	public static function activate() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'dg_mpesa_transactions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			transaction_id VARCHAR(100) NOT NULL,
			phone_number VARCHAR(20) NOT NULL,
			amount DECIMAL(10, 2) NOT NULL,
			status VARCHAR(50) NOT NULL,
			date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::force_classic_checkout_page();
	}

	public static function deactivate() {
		// Optional: cleanup actions on deactivation.
	}

	private static function force_classic_checkout_page() {
		$checkout_page_id  = wc_get_page_id( 'checkout' );
		$classic_shortcode = '[woocommerce_checkout]';

		if ( ! $checkout_page_id || ! get_post( $checkout_page_id ) ) {
			$page_data = array(
				'post_title'   => __( 'Checkout', 'dg-checkout-for-m-pesa' ),
				'post_content' => $classic_shortcode,
				'post_status'  => 'publish',
				'post_type'    => 'page'
			);
			$new_page_id = wp_insert_post( $page_data );
			if ( $new_page_id && ! is_wp_error( $new_page_id ) ) {
				update_option( 'dg_mpesa_checkout_page_id', $new_page_id );
			}
			return;
		}

		$checkout_page              = get_post( $checkout_page_id );
		$checkout_page->post_content = $classic_shortcode;
		wp_update_post( $checkout_page );
		update_option( 'dg_mpesa_checkout_page_id', $checkout_page_id );
	}
}
?>
