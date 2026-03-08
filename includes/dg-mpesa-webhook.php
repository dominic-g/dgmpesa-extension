<?php
defined( 'ABSPATH' ) || exit;

/**
 * DG_Mpesa_Webhook_Handler
 *
 * Receives the M-Pesa STK-push callback from Safaricom, validates it, and
 * updates the corresponding WooCommerce order status.
 */
class DG_Mpesa_Webhook_Handler {

	/** @var DG_Payment_Logger */
	private $log;

	public function __construct() {
		$this->log = new DG_Payment_Logger();
		add_action( 'woocommerce_api_dg_callback', [ $this, 'handle_incoming' ] );
	}

	// -----------------------------------------------------------------------
	// Entry point
	// -----------------------------------------------------------------------

	/** Process an inbound M-Pesa callback POST request. */
	public function handle_incoming() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = @file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );

		$this->log->write( 'Received callback: ' . $raw );

		if ( empty( $data['Body']['stkCallback'] ) ) {
			$this->log->write( 'Invalid or empty callback payload.' );
			$this->respond( 0, 'Accepted' );
			return;
		}

		$stk         = $data['Body']['stkCallback'];
		$result_code = isset( $stk['ResultCode'] ) ? (int) $stk['ResultCode'] : 1;
		$request_id  = $stk['CheckoutRequestID'] ?? '';

		$order_id = $this->find_order( $request_id );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			$this->log->write( "No order found for CheckoutRequestID: {$request_id}", 'error' );
			$this->respond( 1, 'Order Not Found' );
			return;
		}

		if ( 0 === $result_code ) {
			$this->on_payment_success( $order, $stk );
		} else {
			$this->on_payment_failure( $order, $stk );
		}

		$this->respond( 0, 'Accepted' );
	}

	// -----------------------------------------------------------------------
	// Private handlers
	// -----------------------------------------------------------------------

	private function on_payment_success( WC_Order $order, array $stk ) {
		if ( $order->is_paid() ) {
			$this->log->write( "Order #{$order->get_id()} already paid — skipping." );
			return;
		}

		$receipt    = '';
		$amount_paid = 0.0;

		foreach ( $stk['CallbackMetadata']['Item'] ?? [] as $item ) {
			if ( 'MpesaReceiptNumber' === $item['Name'] ) {
				$receipt = $item['Value'];
			}
			if ( 'Amount' === $item['Name'] ) {
				$amount_paid = (float) $item['Value'];
			}
		}

		if ( $amount_paid < $order->get_total() ) {
			$note = sprintf(
				__( 'Partial M-Pesa payment — paid: %s, expected: %s. Tx: %s', 'dg-checkout-for-m-pesa' ),
				$amount_paid, $order->get_total(), $receipt
			);
			$order->update_status( 'on-hold', $note );
			$this->finalise_log( $order->get_id(), $receipt, 'mismatch' );
			return;
		}

		$order->payment_complete( $receipt );
		$order->add_order_note( sprintf( __( 'M-Pesa payment received. Tx ID: %s', 'dg-checkout-for-m-pesa' ), $receipt ) );
		$this->finalise_log( $order->get_id(), $receipt, 'completed' );
		$this->log->write( "Order #{$order->get_id()} complete — Tx: {$receipt}" );
	}

	private function on_payment_failure( WC_Order $order, array $stk ) {
		$reason = isset( $stk['ResultDesc'] )
			? sanitize_text_field( $stk['ResultDesc'] )
			: 'No details provided.';

		$order->update_status(
			'failed',
			sprintf( __( 'M-Pesa payment failed: %s', 'dg-checkout-for-m-pesa' ), $reason )
		);
		$order->add_order_note( sprintf( __( 'M-Pesa failed — code: %s, reason: %s', 'dg-checkout-for-m-pesa' ), $stk['ResultCode'], $reason ) );
		$this->finalise_log( $order->get_id(), '', 'failed' );
		$this->log->write( "Order #{$order->get_id()} failed — {$reason}", 'error' );
	}

	// -----------------------------------------------------------------------
	// Database helpers
	// -----------------------------------------------------------------------

	private function find_order( $checkout_request_id ) {
		global $wpdb;
		return absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dg_mpesa_checkout_request_id' AND meta_value = %s",
			$checkout_request_id
		) ) );
	}

	private function finalise_log( $order_id, $tx_id, $status ) {
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'dg_mpesa_transactions',
			[ 'transaction_id' => $tx_id, 'status' => $status ],
			[ 'order_id' => $order_id, 'status' => 'pending' ],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);
		if ( ! $updated ) {
			$this->log->write( "Could not update transaction log for order #{$order_id}", 'warning' );
		}
	}

	// -----------------------------------------------------------------------
	// Response
	// -----------------------------------------------------------------------

	private function respond( $code, $desc ) {
		header( 'Content-Type: application/json' );
		echo wp_json_encode( [ 'ResultCode' => $code, 'ResultDesc' => $desc ] );
		exit;
	}
}
