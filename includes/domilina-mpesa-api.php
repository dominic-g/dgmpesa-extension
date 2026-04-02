<?php
defined( 'ABSPATH' ) || exit;

/**
 * DOMILINA_Mpesa_Api_Client
 *
 * Communicates with Safaricom's M-Pesa Daraja API to:
 *   - generate an OAuth access token, and
 *   - fire an STK-push (Lipa na M-Pesa Online) request.
 */
class DOMILINA_Mpesa_Api_Client {

	private $consumer_key;
	private $consumer_secret;
	private $shortcode;
	private $passkey;
	private $callback_url;
	private $env;
	private $last_error = null;

	/** @var DOMILINA_Payment_Logger */
	private $log;

	public function __construct( $key, $secret, $shortcode, $passkey, $callback, $env = 'sandbox' ) {
		$this->consumer_key    = $key;
		$this->consumer_secret = $secret;
		$this->shortcode       = $shortcode;
		$this->passkey         = $passkey;
		$this->callback_url    = $callback;
		$this->env             = $env;
		$this->log             = new DOMILINA_Payment_Logger();
	}

	/** @return string|null Last error message, or null on success. */
	public function last_error() {
		return $this->last_error;
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	private function api_base() {
		return 'live' === $this->env
			? 'https://api.safaricom.co.ke'
			: 'https://sandbox.safaricom.co.ke';
	}

	/**
	 * Fetch a fresh OAuth token from Daraja.
	 *
	 * @return string|false Access token, or false on failure.
	 */
	private function fetch_token() {
		$this->log->write( 'Requesting OAuth token.' );

		$credentials = base64_encode( $this->consumer_key . ':' . $this->consumer_secret );
		$endpoint    = $this->api_base() . '/oauth/v1/generate?grant_type=client_credentials';

		$response = wp_remote_get( $endpoint, [
			'headers' => [ 'Authorization' => 'Basic ' . $credentials ],
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			$this->log->write( 'Token error: ' . $this->last_error, 'error' );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$this->log->write( 'Token response: ' . $body );
		$data = json_decode( $body, true );

		if ( ! empty( $data['access_token'] ) ) {
			return $data['access_token'];
		}

		$this->last_error = 'Token missing: ' . wp_json_encode( $data );
		$this->log->write( $this->last_error, 'error' );
		return false;
	}

	/**
	 * Normalise a Kenyan phone number to 2547XXXXXXXX format.
	 *
	 * @param  string $raw Raw phone input.
	 * @return string
	 */
	private function normalise_phone( $raw ) {
		$digits = preg_replace( '/\D/', '', $raw );

		if ( '0' === substr( $digits, 0, 1 ) ) {
			return '254' . substr( $digits, 1 );
		}

		if ( '254' === substr( $digits, 0, 3 ) ) {
			return $digits;
		}

		if ( '7' === substr( $digits, 0, 1 ) ) {
			return '254' . $digits;
		}

		return $digits;
	}

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Initiate an STK-push for the given order.
	 *
	 * @param  string $phone    Raw phone number from the customer.
	 * @param  float  $amount   Order total.
	 * @param  int    $order_id WooCommerce order ID (used as account reference).
	 * @return array|false      Daraja response array, or false on failure.
	 */
	public function push_stk( $phone, $amount, $order_id ) {
		$this->log->write( "STK push: order #{$order_id}, phone {$phone}, amount {$amount}" );

		$phone_fmt = $this->normalise_phone( $phone );
		$timestamp = gmdate( 'YmdHis' );
		$password  = base64_encode( $this->shortcode . $this->passkey . $timestamp );

		$token = $this->fetch_token();
		if ( ! $token ) {
			$this->log->write( 'STK aborted — no token.', 'error' );
			return false;
		}

		$endpoint = $this->api_base() . '/mpesa/stkpush/v1/processrequest';
		$body     = [
			'BusinessShortCode' => $this->shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'TransactionType'   => 'CustomerPayBillOnline',
			'Amount'            => (int) $amount,
			'PartyA'            => $phone_fmt,
			'PartyB'            => $this->shortcode,
			'PhoneNumber'       => $phone_fmt,
			'CallBackURL'       => $this->callback_url,
			'AccountReference'  => (string) $order_id,
			'TransactionDesc'   => 'Payment for Order #' . $order_id,
		];

		$this->log->write( 'STK payload: ' . wp_json_encode( $body ) );

		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			$this->log->write( 'STK failed: ' . $this->last_error, 'error' );
			return false;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$this->log->write( 'STK response: ' . $raw );

		if ( isset( $data['ResponseCode'] ) && '0' === $data['ResponseCode'] ) {
			return $data;
		}

		$this->last_error = 'STK error: ' . wp_json_encode( $data );
		$this->log->write( $this->last_error, 'error' );
		return false;
	}
}
