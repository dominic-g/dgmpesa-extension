<?php
/**
 * Thank You Page for M-Pesa Checkout.
 */

defined( 'ABSPATH' ) || exit;

$domilina_order_id = absint( get_query_var( 'order-received' ) );


$domilina_order = wc_get_order( $domilina_order_id );

if ( ! $domilina_order ) {
	return;
}

$domilina_payment_method = $domilina_order->get_payment_method();

if ( 'domilina_mpesa_checkout' === $domilina_payment_method ) : ?>
	<div class="woocommerce-order">
		<?php if ( $domilina_order->has_status( 'pending' ) ) : ?>
			<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">
				<?php esc_html_e( 'Thank you for your order.', 'dominicn-lipa-na-mpesa-stk-push-checkout' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Your payment is pending. Please complete the payment using the M-Pesa prompt sent to your phone.', 'dominicn-lipa-na-mpesa-stk-push-checkout' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Once the payment is confirmed, your order status will be updated accordingly.', 'dominicn-lipa-na-mpesa-stk-push-checkout' ); ?>
			</p>
		<?php elseif ( $domilina_order->has_status( 'processing' ) || $domilina_order->has_status( 'completed' ) ) : ?>
			<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">
				<?php esc_html_e( 'Thank you. Your payment has been received.', 'dominicn-lipa-na-mpesa-stk-push-checkout' ); ?>
			</p>
		<?php endif; ?>

		<?php do_action( 'domilina_woocommerce_thankyou_' . $domilina_payment_method, $domilina_order_id ); ?>
	</div>
<?php else : ?>
	<?php wc_get_template( 'checkout/thankyou.php', [ 'order' => $domilina_order ] ); ?>
<?php endif; ?>
