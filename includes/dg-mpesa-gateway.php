<?php
defined( 'ABSPATH' ) || exit;

/**
 * DG_Mpesa_Payment_Gateway
 *
 * WooCommerce payment gateway that fires an M-Pesa STK-push on checkout
 * and redirects customers to a real-time payment-confirmation screen.
 *
 * @extends WC_Payment_Gateway
 */
class DG_Mpesa_Payment_Gateway extends WC_Payment_Gateway {

	/** @var string Consumer key from Daraja. */
	protected $consumer_key;
	protected $consumer_secret;
	protected $shortcode;
	protected $passkey;
	protected $environment;
	protected $callback_url;

	/** @var DG_Payment_Logger */
	private $log;

	/** @var DG_Mpesa_Api_Client */
	private $api_client;

	public function __construct() {
		$this->id                 = 'dg_mpesa_checkout';
		$this->method_title       = 'M-Pesa';
		$this->method_description = 'Accept M-Pesa payments in WooCommerce.';
		$this->has_fields         = true;
		$this->supports           = [ 'products', 'refunds', 'block_features' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title' );
		$this->enabled = $this->get_option( 'enabled' );

		$this->consumer_key    = $this->get_option( 'consumer_key' );
		$this->consumer_secret = $this->get_option( 'consumer_secret' );
		$this->shortcode       = $this->get_option( 'shortcode' );
		$this->passkey         = $this->get_option( 'passkey' );
		$this->environment     = $this->get_option( 'environment' );

		$auto_callback       = add_query_arg( 'wc-api', 'dg_callback', home_url( '/' ) );
		$stored_callback     = $this->get_option( 'callback_url' );
		$this->callback_url  = ! empty( $stored_callback )
			? untrailingslashit( $stored_callback )
			: $auto_callback;

		$this->log = new DG_Payment_Logger();

		$this->api_client = new DG_Mpesa_Api_Client(
			$this->consumer_key,
			$this->consumer_secret,
			$this->shortcode,
			$this->passkey,
			$this->callback_url,
			$this->environment
		);

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'redirect_if_pending' ] );
		add_filter( 'woocommerce_gateway_title', [ $this, 'inject_logo_into_title' ], 10, 2 );
	}

	// -----------------------------------------------------------------------
	// Settings fields
	// -----------------------------------------------------------------------

	public function init_form_fields() {
		$default_cb = add_query_arg( 'wc-api', 'dg_callback', home_url( '/' ) );

		$this->form_fields = [
			'enabled' => [
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable M-Pesa Payment',
				'default' => 'yes',
			],
			'title' => [
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'Displayed during checkout.',
				'default'     => 'M-Pesa',
			],
			'consumer_key' => [
				'title'       => 'Consumer Key',
				'type'        => 'text',
				'description' => 'Your M-Pesa Consumer Key from the Safaricom Developer Portal.',
				'default'     => '',
			],
			'consumer_secret' => [
				'title'       => 'Consumer Secret',
				'type'        => 'password',
				'description' => 'Your M-Pesa Consumer Secret.',
				'default'     => '',
			],
			'shortcode' => [
				'title'       => 'Short Code',
				'type'        => 'text',
				'description' => 'For Sandbox testing, use 174379. Replace with your live Short Code when ready.',
				'default'     => '174379',
			],
			'passkey' => [
				'title'       => 'Passkey',
				'type'        => 'password',
				'description' => 'Lipa na M-Pesa Online passkey for your shortcode.',
				'default'     => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
			],
			'callback_url' => [
				'title'       => 'Callback URL',
				'type'        => 'text',
				'description' => 'HTTPS URL where M-Pesa posts the payment result.',
				'default'     => $default_cb,
			],
			'environment' => [
				'title'       => 'Environment',
				'type'        => 'select',
				'description' => 'Choose Sandbox for testing or Live for real payments.',
				'default'     => 'sandbox',
				'options'     => [
					'sandbox' => 'Sandbox',
					'live'    => 'Live',
				],
			],
		];
	}

	// -----------------------------------------------------------------------
	// Availability
	// -----------------------------------------------------------------------

	public function is_available() {
		return 'yes' === $this->enabled;
	}

	// -----------------------------------------------------------------------
	// Checkout form
	// -----------------------------------------------------------------------

	public function payment_fields() {
		?>
		<div class="dg-mpesa-checkout-wrapper" style="font-family:'Jost',sans-serif;font-size:16px;">
			<div class="dg-mpesa-checkout-description" style="margin-bottom:1rem;">
				<?php esc_html_e( 'Enter your M-Pesa phone number to receive an STK push prompt.', 'dgmpesa-extension' ); ?>
			</div>

			<?php wp_nonce_field( 'mpesa_checkout_action', 'mpesa_checkout_nonce_field' ); ?>

			<div class="dg-mpesa-checkout-field" style="margin-bottom:1rem;">
				<label for="mpesa_phone_number">
					<?php esc_html_e( 'Phone Number', 'dgmpesa-extension' ); ?> <span class="required">*</span>
				</label>
				<input
					type="text"
					name="mpesa_phone_number"
					id="mpesa_phone_number"
					/* translators: placeholder Example phone number */
					placeholder="<?php esc_attr_e( '07XXXXXXXX', 'dgmpesa-extension' ); ?>"
					required
					style="padding:0.6rem;width:100%;"
				>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Payment processing
	// -----------------------------------------------------------------------

	public function process_payment( $order_id ) {
		// Nonce check
		$nonce = isset( $_POST['mpesa_checkout_nonce_field'] )
			? sanitize_text_field( wp_unslash( $_POST['mpesa_checkout_nonce_field'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'mpesa_checkout_action' ) ) {
			wc_add_notice( esc_html__( 'Security check failed, please try again.', 'dgmpesa-extension' ), 'error' );
			return [ 'result' => 'failure', 'redirect' => '' ];
		}

		$order        = wc_get_order( $order_id );
		$phone_number = isset( $_POST['mpesa_phone_number'] )
			? sanitize_text_field( wp_unslash( $_POST['mpesa_phone_number'] ) )
			: '';
		$amount       = $order->get_total();

		$this->log->write( "Processing order #{$order_id} — phone: {$phone_number}, amount: {$amount}" );

		$this->log_pending_transaction( $order_id, $phone_number, $amount );

		$result = $this->api_client->push_stk( $phone_number, $amount, $order_id );

		if ( false === $result ) {
			/* translators: %s: error message from API */
			$err = $this->api_client->last_error() ?: esc_html__( 'M-Pesa STK push failed. Please try again.', 'dgmpesa-extension' );
			wc_add_notice( esc_html__( 'M-Pesa error: ', 'dgmpesa-extension' ) . esc_html( $err ), 'error' );
			return [ 'result' => 'failure', 'redirect' => '' ];
		}

		if ( isset( $result['CheckoutRequestID'] ) ) {
			update_post_meta( $order_id, '_dg_mpesa_checkout_request_id', sanitize_text_field( $result['CheckoutRequestID'] ) );
		}

		$order->update_status( 'on-hold', esc_html__( 'Awaiting M-Pesa payment confirmation', 'dgmpesa-extension' ) );
		WC()->cart->empty_cart();

		return [
			'result'   => 'success',
			'redirect' => add_query_arg( [
				'mpesa_waiting' => 'yes',
				'order_id'      => $order_id,
				'_wpnonce'      => wp_create_nonce( 'dg_waiting' ),
			], home_url( '/' ) ),
		];
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function log_pending_transaction( $order_id, $phone, $amount ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'dg_mpesa_transactions',
			[
				'order_id'       => $order_id,
				'transaction_id' => '',
				'phone_number'   => $phone,
				'amount'         => $amount,
				'status'         => 'pending',
				'date_created'   => current_time( 'mysql' ),
			]
		);
	}

	public function redirect_if_pending( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && $order->has_status( 'on-hold' ) ) {
			wp_safe_redirect( add_query_arg( [ 'mpesa_waiting' => 'yes', 'order_id' => $order_id ], home_url( '/' ) ) );
			exit;
		}
	}

	public function inject_logo_into_title( $title, $id ) {
		if ( $id !== $this->id ) {
			return $title;
		}
		$logo = plugins_url( 'assets/img/mpesa-logo.png', dirname( __FILE__ ) );
		return 'Lipa na Mpesa <img src="' . esc_url( $logo ) . '" alt="M-Pesa" style="height:35px;vertical-align:middle;margin-left:8px;">';
	}
}
