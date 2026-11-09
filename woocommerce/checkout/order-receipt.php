<?php
/**
 * Checkout Order Receipt Template
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/order-receipt.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$checkout_state_data = Bricks\Woocommerce::get_checkout_v2_state_render_data( 'receipt' );

// Only render the classic fallback when no v2 state is available. Its Notice element
// clears queued notices even when the returned markup is discarded. (#123pt1nu1c2; @since 2.4.3)
$template_data = $checkout_state_data ? false : Bricks\Woocommerce::get_template_data_by_type( 'wc_order_receipt' );

// Render Checkout v2 receipt state
if ( $checkout_state_data ) {
	echo Bricks\Frontend::render_data( $checkout_state_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

// Render Bricks template
elseif ( $template_data ) {
	echo $template_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	do_action( 'woocommerce_receipt_' . $order->get_payment_method(), $order->get_id() );
}

// Render WooCommerce template
else { ?>
	<ul class="order_details">
		<li class="order">
			<?php esc_html_e( 'Order number:', 'woocommerce' ); ?>
			<strong><?php echo esc_html( $order->get_order_number() ); ?></strong>
		</li>
		<li class="date">
			<?php esc_html_e( 'Date:', 'woocommerce' ); ?>
			<strong><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></strong>
		</li>
		<li class="total">
			<?php esc_html_e( 'Total:', 'woocommerce' ); ?>
			<strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
		</li>
		<?php if ( $order->get_payment_method_title() ) : ?>
		<li class="method">
			<?php esc_html_e( 'Payment method:', 'woocommerce' ); ?>
			<strong><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></strong>
		</li>
		<?php endif; ?>
	</ul>

	<?php do_action( 'woocommerce_receipt_' . $order->get_payment_method(), $order->get_id() ); ?>

	<div class="clear"></div>
	<?php
}
