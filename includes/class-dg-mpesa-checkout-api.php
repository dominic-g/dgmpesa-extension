<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DG_Mpesa_Checkout_API
 *
 * Handles the STK push call to Safaricom's M-Pesa API.
 * Changes:
 * - Uses gmdate() for UTC timestamps.
 * - Uses wp_json_encode() for safe JSON encoding.
 */
class DG_Mpesa_Checkout_API {

	private $logger;
	private $consumer_key;
	private $consumer_secret;
	private $shortcode;
	private $passkey;
	private $callback_url;
	private $env;
	private $last_error = null;

	public function __construct( $consumer_key, $consumer_secret, $shortcode, $passkey, $callback_url, $environment = 'sandbox' ) {
		$this->consumer_key    = $consumer_key;
		$this->consumer_secret = $consumer_secret;
		$this->shortcode       = $shortcode;
		$this->passkey         = $passkey;
		$this->callback_url    = $callback_url;
		$this->env             = $environment;
		$this->logger          = new DG_Mpesa_Checkout_Logger();
	}

	public function get_last_error() {
		return $this->last_error;
	}

	private function get_base_url() {
		return $this->env === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
	}

	private function generate_token() {
		$this->logger->log( "Generating OAuth token..." );
		$credentials = base64_encode( $this->consumer_key . ':' . $this->consumer_secret );
		$url         = $this->get_base_url() . '/oauth/v1/generate?grant_type=client_credentials';

		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Basic ' . $credentials
			],
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			$this->logger->log( "Error generating token: " . $this->last_error );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$this->logger->log( "Token generation response: " . $body );
		$data = json_decode( $body, true );

		if ( isset( $data['access_token'] ) ) {
			return $data['access_token'];
		}

		$this->last_error = 'Could not retrieve access token: ' . wp_json_encode( $data );
		$this->logger->log( $this->last_error );
		return false;
	}

	public function initiate_stk_push( $phone_number, $amount, $order_id ) {
		$this->logger->log( "Initiating STK push. Order #{$order_id}, Phone: {$phone_number}, Amount: {$amount}" );

		$formatted_phone = $this->format_phone_number( $phone_number );
		$timestamp       = gmdate( 'YmdHis' ); // UTC timestamp
		$password        = base64_encode( $this->shortcode . $this->passkey . $timestamp );

		$token = $this->generate_token();
		if ( ! $token ) {
			$this->logger->log( "STK push aborted - token generation failed." );
			return false;
		}

		$url = $this->get_base_url() . '/mpesa/stkpush/v1/processrequest';
		$payload = [
			'BusinessShortCode' => $this->shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'TransactionType'   => 'CustomerPayBillOnline',
			'Amount'            => (int) $amount,
			'PartyA'            => $formatted_phone,
			'PartyB'            => $this->shortcode,
			'PhoneNumber'       => $formatted_phone,
			'CallBackURL'       => $this->callback_url,
			'AccountReference'  => $order_id,
			'TransactionDesc'   => 'Payment for Order #' . $order_id
		];

		$this->logger->log( "STK push payload: " . wp_json_encode( $payload ) );

		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json'
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			$this->logger->log( "STK push failed: " . $this->last_error );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$this->logger->log( "STK push API response: " . $body );
		$data = json_decode( $body, true );

		if ( isset( $data['ResponseCode'] ) && $data['ResponseCode'] === '0' ) {
			return $data;
		}

		$this->last_error = 'STK push error: ' . wp_json_encode( $data );
		$this->logger->log( $this->last_error );
		return false;
	}

	private function format_phone_number( $phone ) {
		$phone = preg_replace( '/\D/', '', $phone );
		if ( substr( $phone, 0, 1 ) === '0' ) {
			return '254' . substr( $phone, 1 );
		} elseif ( substr( $phone, 0, 3 ) === '254' ) {
			return $phone;
		} elseif ( substr( $phone, 0, 1 ) === '7' ) {
			return '254' . $phone;
		}
		return $phone;
	}
}
?>
