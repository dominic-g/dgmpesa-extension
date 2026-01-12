<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DG_Mpesa_Checkout_Gateway extends WC_Payment_Gateway {

    protected $consumer_key;
    protected $consumer_secret;
    protected $shortcode;
    protected $passkey;
    protected $environment;
    protected $callback_url;

    private $logger;
    private $api;

    public function __construct() {
        $this->id                 = 'dg_mpesa_checkout';
        $this->method_title       = 'M-Pesa';
        $this->method_description = 'Accept M-Pesa payments in WooCommerce.';
        $this->has_fields         = true;
        // Remove 'block' support as free version enforces classic checkout
        $this->supports           = [ 'products', 'refunds' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title   = $this->get_option( 'title' );
        $this->enabled = $this->get_option( 'enabled' );

        $this->consumer_key    = $this->get_option( 'consumer_key' );
        $this->consumer_secret = $this->get_option( 'consumer_secret' );
        $this->shortcode       = $this->get_option( 'shortcode' );
        $this->passkey         = $this->get_option( 'passkey' );
        $this->environment     = $this->get_option( 'environment' );

        $generated_callback_url = add_query_arg( 'wc-api', 'dg_callback', home_url( '/' ) );
        $this->callback_url     = ! empty( $this->get_option( 'callback_url' ) )
            ? untrailingslashit( $this->get_option( 'callback_url' ) )
            : $generated_callback_url;

        $this->logger = new DG_Mpesa_Checkout_Logger();

        if ( class_exists( 'DG_Mpesa_Checkout_API' ) ) {
            $this->api = new DG_Mpesa_Checkout_API(
                $this->consumer_key,
                $this->consumer_secret,
                $this->shortcode,
                $this->passkey,
                $this->callback_url,
                $this->environment
            );
        }

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'maybe_redirect_to_waiting_page' ] );

        // Change the main radio button label to "Lipa na Mpesa" followed by logo (increased logo size).
        add_filter( 'woocommerce_gateway_title', [ $this, 'add_logo_to_gateway_title' ], 10, 2 );
    }

    public function init_form_fields() {
        $default_callback = add_query_arg( 'wc-api', 'dg_callback', home_url( '/' ) );
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
                'description' => 'For Sandbox testing, use bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919. Replace with your live passkey when ready.',
                'default'     => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
            ],
            'callback_url' => [
                'title'       => 'Callback URL',
                'type'        => 'text',
                'description' => 'HTTPS callback URL.',
                'default'     => $default_callback,
            ],
            'environment' => [
                'title'       => 'Environment',
                'type'        => 'select',
                'description' => 'Choose Sandbox or Live.',
                'default'     => 'sandbox',
                'options'     => [
                    'sandbox' => 'Sandbox',
                    'live'    => 'Live'
                ],
            ],
        ];
    }

    public function is_available() {
        return ( 'yes' === $this->enabled );
    }

    public function process_payment( $order_id ) {
        // Verify nonce: unslash input before sanitizing.
        if ( ! isset( $_POST['mpesa_checkout_nonce_field'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mpesa_checkout_nonce_field'] ) ), 'mpesa_checkout_action' ) ) {
            wc_add_notice( esc_html__( 'Security check failed, please try again.', 'dg-checkout-for-m-pesa' ), 'error' );
            return [
                'result'   => 'failure',
                'redirect' => ''
            ];
        }

        $order = wc_get_order( $order_id );
        $phone_number = isset( $_POST['mpesa_phone_number'] )
            ? sanitize_text_field( wp_unslash( $_POST['mpesa_phone_number'] ) )
            : '';
        $amount = $order->get_total();

        $this->logger->log( "Processing payment for order #{$order_id}, phone: {$phone_number}, amount: {$amount}" );

        $this->record_pending_transaction( $order_id, $phone_number, $amount );

        $response = $this->api ? $this->api->initiate_stk_push( $phone_number, $amount, $order_id ) : false;
        if ( false === $response ) {
            $error_message = $this->api ? $this->api->get_last_error() : esc_html__( 'M-Pesa STK push failed.', 'dg-checkout-for-m-pesa' );
            if ( empty( $error_message ) ) {
                $error_message = esc_html__( 'M-Pesa STK push failed. Please try again.', 'dg-checkout-for-m-pesa' );
            }
            wc_add_notice( esc_html__( 'M-Pesa STK push error: ', 'dg-checkout-for-m-pesa' ) . esc_html( $error_message ), 'error' );
            return [
                'result'   => 'failure',
                'redirect' => ''
            ];
        }

        if ( isset( $response['CheckoutRequestID'] ) ) {
            update_post_meta( $order_id, '_dg_mpesa_checkout_request_id', sanitize_text_field( $response['CheckoutRequestID'] ) );
        }

        $order->update_status( 'on-hold', esc_html__( 'Awaiting M-Pesa payment confirmation', 'dg-checkout-for-m-pesa' ) );
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => add_query_arg( [
                'mpesa_waiting' => 'yes',
                'order_id'      => $order_id,
                '_wpnonce'      => wp_create_nonce( 'dg_waiting' )
            ], home_url( '/' ) )
        ];
    }

    private function record_pending_transaction( $order_id, $phone_number, $amount ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dg_mpesa_transactions';
        $wpdb->insert( $table_name, [
            'order_id'       => $order_id,
            'transaction_id' => '',
            'phone_number'   => $phone_number,
            'amount'         => $amount,
            'status'         => 'pending',
            'date_created'   => current_time( 'mysql' ),
        ] );
    }

    public function payment_fields() {
        ?>
        <div class="dg-mpesa-checkout-wrapper" style="font-family: 'Jost', sans-serif; font-size: 16px;">
            <div class="dg-mpesa-checkout-description" style="margin-bottom:1rem;">
                <?php esc_html_e( 'Enter your M-Pesa phone number to receive an STK push prompt.', 'dg-checkout-for-m-pesa' ); ?>
            </div>

            <?php wp_nonce_field( 'mpesa_checkout_action', 'mpesa_checkout_nonce_field' ); ?>

            <div class="dg-mpesa-checkout-field" style="margin-bottom:1rem;">
                <label for="mpesa_phone_number">
                    <?php esc_html_e( 'Phone Number', 'dg-checkout-for-m-pesa' ); ?> <span class="required">*</span>
                </label>
                <input
                    type="text"
                    name="mpesa_phone_number"
                    id="mpesa_phone_number"
                    placeholder="<?php esc_attr_e( '07XXXXXXXX', 'dg-checkout-for-m-pesa' ); ?>"
                    required
                    style="padding:0.6rem; width:100%;"
                >
            </div>
        </div>
        <?php
    }

    public function maybe_redirect_to_waiting_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && $order->has_status( 'on-hold' ) ) {
            wp_safe_redirect( add_query_arg( [ 'mpesa_waiting' => 'yes', 'order_id' => $order_id ], home_url( '/' ) ) );
            exit;
        }
    }

    public function get_block_handler() {
        // Block handler is not used in the free version as block support is removed
        return false;
    }

    /**
     * Adds the main title in the checkout radio button as "Lipa na Mpesa"
     * followed by a larger M-Pesa logo (height: 35px).
     */
    public function add_logo_to_gateway_title( $title, $id ) {
        if ( $id === $this->id ) {
            $logo_url  = plugins_url( 'assets/img/mpesa-logo.png', dirname( __FILE__ ) );
            $logo_html = '<img src="' . esc_url( $logo_url ) . '" alt="M-Pesa" style="height:35px; vertical-align:middle; margin-left:8px;">';
            // Force the label to "Lipa na Mpesa" + bigger logo
            $title_text = 'Lipa na Mpesa';
            return $title_text . $logo_html;
        }
        return $title;
    }
}
?>