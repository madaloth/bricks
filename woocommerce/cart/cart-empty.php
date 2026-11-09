<?php
/**
 * Empty cart page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart-empty.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 7.0.1
 */

defined( 'ABSPATH' ) || exit;

$cart_state_data = Bricks\Woocommerce::get_cart_v2_state_render_data( 'empty' );

$templates   = Bricks\Templates::get_templates_by_type( 'wc_cart_empty' );
$template_id = ! empty( $templates[0] ) ? $templates[0] : false;

$is_empty_cart_ajax_response =
	isset( $_GET['removed_item'] ) ||
	( isset( $_SERVER['HTTP_REFERER'] ) && $_SERVER['HTTP_REFERER'] === wc_get_cart_url() );

$inline_css = '';

$add_empty_state_classes = function( $target_element_id ) {
	if ( ! $target_element_id ) {
		return;
	}

	add_filter(
		'bricks/element/render_attributes',
		function( $attributes, $key, $element ) use ( $target_element_id ) {
			if ( $element->id !== $target_element_id ) {
				return $attributes;
			}

			if ( isset( $attributes['_root']['class'] ) ) {
				$attributes['_root']['class'][] = 'cart-empty';
				$attributes['_root']['class'][] = 'wc-empty-cart-message';
			} else {
				$attributes['_root']['class'] = [ 'cart-empty', 'wc-empty-cart-message' ];
			}

			return $attributes;
		},
		10,
		3
	);
};

// NOTE TODO: The woocommerce_cart_is_empty hook should be outside of the conditional or placed in a way that WooCommerce has access to it to render the page after cart emptied.
// This will come with additional messages and might need to be filtered if we're planning on excluding everything and just letting the empty cart template run (@see #30zbcqm).
// @since 1.7 - Place {do_action:woocommerce_cart_is_empty} in the user custom Empty Cart template will solve the issue.

// Render Cart v2 state template
if ( $cart_state_data ) {
	$elements_by_id = [];
	$has_notice     = false;

	foreach ( $cart_state_data as $element ) {
		if ( isset( $element['id'] ) ) {
			$elements_by_id[ $element['id'] ] = $element;
		}

		if ( isset( $element['name'] ) && $element['name'] === 'woocommerce-notice' ) {
			$has_notice = true;
		}
	}

	$first_element_id = false;
	$state_root       = $cart_state_data[0] ?? null;

	if ( is_array( $state_root ) && isset( $state_root['name'] ) && $state_root['name'] === 'woocommerce-cart-v2-state-empty' ) {
		$state_children = $state_root['children'] ?? [];
		$first_child_id = is_array( $state_children ) && isset( $state_children[0] ) ? $state_children[0] : false;

		if ( $first_child_id && isset( $elements_by_id[ $first_child_id ] ) ) {
			$first_element_id = $first_child_id;
		}
	}

	if ( ! $first_element_id && is_array( $state_root ) && isset( $state_root['id'] ) ) {
		$first_element_id = $state_root['id'];
	}

	$add_empty_state_classes( $first_element_id );

	// The filled and empty states are separate trees. Preserve Woo's removal/undo notice when an
	// existing empty state has no Notice element of its own, without changing its saved structure.
	if ( ! $has_notice && function_exists( 'wc_print_notices' ) ) {
		$notices = wc_print_notices( true );

		if ( $notices ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="woocommerce-notices-wrapper">' . $notices . '</div>';
		}
	}

	echo Bricks\Frontend::render_data( $cart_state_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	if ( $is_empty_cart_ajax_response ) {
		Bricks\Assets::generate_global_classes( 'global_classes_empty_cart' );
		$inline_css .= Bricks\Assets::$inline_css['global_classes_empty_cart'] ?? '';

		$cart_page_id = wc_get_page_id( 'cart' );
		if ( $cart_page_id > 0 ) {
			$inline_css .= Bricks\Templates::generate_inline_css( $cart_page_id, $cart_state_data );
		}
	}
}

// Render Bricks template
elseif ( $template_id ) {
	$elements = get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true );

	/**
	 * Add CSS class 'cart-empty' to the first Bricks element via 'bricks/element/render_attributes'
	 *
	 * So empty cart shows when remove last item from cart.
	 *
	 * TODO: The sequence of the elements is not guaranteed and same as the order in the builder. Maybe a new section added last and dragged to the top.
	 * TODO: Maybe #862jued8a rearrange element function can be used here.
	 *
	 * @since 1.8
	 */
	if ( is_array( $elements ) && isset( $elements[0] ) ) {
		$element_id = $elements[0]['id'];

		$add_empty_state_classes( $element_id );
	}

	$template_data = Bricks\Woocommerce::get_template_data_by_type( 'wc_cart_empty' );

	// Render template
	echo $template_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	/**
	 *
	 * $_GET['removed_item'] or $_SERVER['HTTP_REFERER'] is set equal to wc_get_cart_url()
	 *
	 * HTTP_REFERER a workaround as update cart quantity to zero is not detectable this data via AJAX + redirect (#862k6erqj)
	 *
	 * Add inline CSS to the page (WooCommerce fetches this data via AJAX)
	 *
	 * @since 1.8
	 */
	if ( $is_empty_cart_ajax_response ) {
		Bricks\Assets::generate_global_classes( 'global_classes_empty_cart' );
		$inline_css .= Bricks\Assets::$inline_css['global_classes_empty_cart'] ?? '';
		$inline_css .= Bricks\Assets::$inline_css[ "template_$template_id" ];
	}
}

// Render WooCommerce template
else {
	/*
	* @hooked wc_empty_cart_message - 10
	*/
	do_action( 'woocommerce_cart_is_empty' );

	if ( wc_get_page_id( 'shop' ) > 0 ) { ?>
		<p class="return-to-shop">
			<a class="button wc-backward<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
				<?php
					/**
					 * Filter "Return To Shop" text.
					 *
					 * @since 4.6.0
					 * @param string $default_text Default text.
					 */
					echo esc_html( apply_filters( 'woocommerce_return_to_shop_text', __( 'Return to shop', 'woocommerce' ) ) );
				?>
			</a>
		</p>
		<?php
	}
}

if ( $inline_css ) {
	echo "<style id=\"bricks-cart-empty-inline-css\">$inline_css</style>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
