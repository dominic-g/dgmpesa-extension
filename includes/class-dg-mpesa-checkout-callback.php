<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DG_Mpesa_Checkout_Callback
 *
 * Handles the M-Pesa Confirmation/Validation callbacks to automatically update order status.
 */
class DG_Mpesa_Checkout_Callback {

    private $logger;

    public function __construct() {
        $this->logger = new DG_Mpesa_Checkout_Logger();
        // Hook the WooCommerce API endpoint
        add_action( 'woocommerce_api_dg_callback', [ $this, 'process_callback' ] );
    }

    /**
     * Main handler for the M-Pesa API callback.
     */
    public function process_callback() {
        // M-Pesa typically sends a POST request with a JSON body
        $body = @file_get_contents( 'php://input' );
        $data = json_decode( $body, true );
        $this->logger->log( "Received M-Pesa Callback: " . $body );

        if ( empty( $data ) || ! isset( $data['Body']['stkCallback'] ) ) {
            $this->logger->log( "Empty, invalid, or non-STK-push callback data received." );
            // Respond with acknowledgement even if data is unexpected
            $this->send_api_response( 0, 'Accepted' );
            return;
        }

        $callback_data = $data['Body']['stkCallback'];
        $result_code   = isset($callback_data['ResultCode']) ? $callback_data['ResultCode'] : 1; // Default to fail
        $checkout_request_id = $callback_data['CheckoutRequestID'];

        // Find the order associated with the CheckoutRequestID
        $order_id = $this->find_order_by_checkout_id( $checkout_request_id );
        $order    = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order ) {
            $this->logger->log( "Order not found for CheckoutRequestID: " . $checkout_request_id );
            $this->send_api_response( 1, 'Order Not Found' );
            return;
        }

        // Process the result
        if ( 0 === (int) $result_code ) {
            $this->handle_successful_payment( $order, $callback_data );
        } else {
            $this->handle_failed_payment( $order, $callback_data );
        }
        
        // Respond to M-Pesa to acknowledge receipt
        $this->send_api_response( 0, 'Accepted' );
    }
    
    /**
     * Sends the standard M-Pesa API response and exits.
     */
    private function send_api_response( $code, $desc ) {
        header( 'Content-Type: application/json' );
        echo wp_json_encode( [ 
            'ResultCode' => $code, 
            'ResultDesc' => $desc 
        ] );
        exit;
    }

    /**
     * Finds the WooCommerce Order ID using the stored CheckoutRequestID.
     */
    private function find_order_by_checkout_id( $checkout_request_id ) {
        global $wpdb;
        $order_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dg_mpesa_checkout_request_id' AND meta_value = %s",
            $checkout_request_id
        ) );
        return absint( $order_id );
    }

    /**
     * Handle successful M-Pesa payment.
     */
    private function handle_successful_payment( $order, $callback_data ) {
        if ( $order->is_paid() ) {
            $this->logger->log( "Order #{$order->get_id()} already paid. Skipping update." );
            return;
        }

        $mpesa_data = $callback_data['CallbackMetadata']['Item'];
        $mpesa_receipt = '';
        $amount_paid = 0;
        
        foreach ( $mpesa_data as $item ) {
            if ( 'MpesaReceiptNumber' === $item['Name'] ) {
                $mpesa_receipt = $item['Value'];
            }
            if ( 'Amount' === $item['Name'] ) {
                $amount_paid = (float) $item['Value'];
            }
        }

        if ( $amount_paid < $order->get_total() ) {
            $order->update_status( 'on-hold', sprintf( __( 'M-Pesa payment received but amount mismatch. Paid: %s, Expected: %s. Transaction ID: %s', 'dg-checkout-for-m-pesa' ), $amount_paid, $order->get_total(), $mpesa_receipt ) );
            $this->finalize_transaction_log( $order->get_id(), $mpesa_receipt, 'mismatch' );
            return;
        }

        // Finalize payment
        $order->payment_complete( $mpesa_receipt );
        $order->add_order_note( sprintf( __( 'M-Pesa payment successful. Transaction ID: %s', 'dg-checkout-for-m-pesa' ), $mpesa_receipt ) );
        $this->finalize_transaction_log( $order->get_id(), $mpesa_receipt, 'completed' );
        $this->logger->log( "Order #{$order->get_id()} successfully completed. M-Pesa ID: {$mpesa_receipt}" );
    }

    /**
     * Handle failed M-Pesa payment.
     */
    private function handle_failed_payment( $order, $callback_data ) {
        $result_desc = isset( $callback_data['ResultDesc'] ) ? sanitize_text_field( $callback_data['ResultDesc'] ) : 'Payment failed. No further details provided.';

        $order->update_status( 'failed', sprintf( __( 'M-Pesa payment failed. Reason: %s', 'dg-checkout-for-m-pesa' ), $result_desc ) );
        $order->add_order_note( sprintf( __( 'M-Pesa payment failed. Result Code: %s. Description: %s', 'dg-checkout-for-m-pesa' ), $callback_data['ResultCode'], $result_desc ) );
        $this->finalize_transaction_log( $order->get_id(), '', 'failed' );
        
        $this->logger->log( "Order #{$order->get_id()} payment failed. Reason: {$result_desc}" );
    }

    /**
     * Update the custom transaction log with final status.
     */
    private function finalize_transaction_log( $order_id, $transaction_id, $status ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dg_mpesa_transactions';
        
        // Update the initial pending transaction record
        $updated = $wpdb->update( 
            $table_name, 
            [ 
                'transaction_id' => $transaction_id, 
                'status'         => $status,
            ], 
            [ 
                'order_id' => $order_id,
                'status'   => 'pending' // Only update the initial pending record
            ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        if ( ! $updated ) {
            $this->logger->log( "Could not update initial pending transaction log for Order #{$order_id}. Fallback required." );
        }
    }
}