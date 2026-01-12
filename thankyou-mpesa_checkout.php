<?php
/**
 * Thank You Page for M-Pesa Checkout.
 */

defined( 'ABSPATH' ) || exit;

$order_id  = absint( get_query_var( 'order-received' ) );
$order     = wc_get_order( $order_id );

if ( ! $order ) {
	return;
}

$payment_method = $order->get_payment_method();

if ( 'dg_mpesa_checkout' === $payment_method ) : ?>
	<div class="woocommerce-order">
		<?php if ( $order->has_status( 'pending' ) ) : ?>
			<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">
				<?php esc_html_e( 'Thank you for your order.', 'dg-checkout-for-m-pesa' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Your payment is pending. Please complete the payment using the M-Pesa prompt sent to your phone.', 'dg-checkout-for-m-pesa' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Once the payment is confirmed, your order status will be updated accordingly.', 'dg-checkout-for-m-pesa' ); ?>
			</p>
		<?php elseif ( $order->has_status( 'processing' ) || $order->has_status( 'completed' ) ) : ?>
			<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">
				<?php esc_html_e( 'Thank you. Your payment has been received.', 'dg-checkout-for-m-pesa' ); ?>
			</p>
		<?php endif; ?>

		<?php do_action( 'woocommerce_thankyou_' . $payment_method, $order->get_id() ); ?>
	</div>
<?php else : ?>
	<?php wc_get_template( 'checkout/thankyou.php', [ 'order' => $order ] ); ?>
<?php endif; ?>
