<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce cart quantity element.
 *
 * @since 2.4
 */
class Woocommerce_Cart_Quantity extends Woo_Element {
	public $category        = 'woocommerce_cart';
	public $name            = 'woocommerce-cart-quantity';
	public $icon            = 'ti-plus';
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	/**
	 * Get element label.
	 *
	 * @since 2.4
	 */
	public function get_label() {
		return esc_html__( 'Cart quantity', 'bricks' );
	}

	/**
	 * Get searchable keywords.
	 *
	 * @since 2.4
	 */
	public function get_keywords() {
		return [ 'woocommerce', 'cart', 'quantity', 'input', 'number', 'stepper' ];
	}

	/**
	 * Set builder control groups.
	 *
	 * @since 2.4
	 */
	public function set_control_groups() {
		$this->control_groups['wrapper'] = [
			'title' => esc_html__( 'Wrapper', 'bricks' ),
		];

		$this->control_groups['input'] = [
			'title' => esc_html__( 'Input', 'bricks' ),
		];

		$this->control_groups['customStepper'] = [
			'title' => esc_html__( 'Custom stepper', 'bricks' ),
		];
	}

	/**
	 * Set builder controls.
	 *
	 * @since 2.4
	 */
	public function set_controls() {
		$this->controls['stepperLayout'] = [
			'label'       => esc_html__( 'Stepper layout', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'inline'        => esc_html__( 'Inline controls', 'bricks' ),
				'stepper-left'  => esc_html__( 'Stepper left', 'bricks' ),
				'stepper-right' => esc_html__( 'Stepper right', 'bricks' ),
			],
			'inline'      => true,
			'placeholder' => esc_html__( 'Inline controls', 'bricks' ),
		];

		$this->controls['inputAriaLabel'] = [
			'label' => esc_html__( 'Aria label', 'bricks' ),
			'type'  => 'text',
		];

		// WRAPPER
		$this->controls['wrapperDirection'] = [
			'group'  => 'wrapper',
			'label'  => esc_html__( 'Direction', 'bricks' ),
			'type'   => 'direction',
			'inline' => true,
			'css'    => [
				[
					'property' => 'flex-direction',
					'selector' => '.brx-number-wrap',
				],
			],
		];

		$this->controls['wrapperAlignItems'] = [
			'group'  => 'wrapper',
			'label'  => esc_html__( 'Align items', 'bricks' ),
			'type'   => 'align-items',
			'inline' => true,
			'css'    => [
				[
					'property' => 'align-items',
					'selector' => '.brx-number-wrap',
				],
			],
		];

		$this->controls['wrapperGap'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => '.brx-number-wrap',
				],
			],
		];

		// INPUT
		$this->controls['inputWidth'] = [
			'group' => 'input',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => '.brx-number-input',
				],
			],
		];

		$this->controls['inputHeight'] = [
			'group' => 'input',
			'label' => esc_html__( 'Height', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => '--brx-cart-quantity-input-height',
					'selector' => '.brx-number-wrap',
				],
			],
		];

		$this->controls['inputPadding'] = [
			'group' => 'input',
			'label' => esc_html__( 'Padding', 'bricks' ),
			'type'  => 'spacing',
			'css'   => [
				[
					'property' => 'padding',
					'selector' => '.brx-number-input',
				],
			],
		];

		$this->controls['inputBackgroundColor'] = [
			'group' => 'input',
			'label' => esc_html__( 'Background color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => '.brx-number-input',
				],
			],
		];

		$this->controls['inputBorder'] = [
			'group' => 'input',
			'label' => esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'property' => 'border',
					'selector' => '.brx-number-input',
				],
			],
		];

		$this->controls['inputTypography'] = [
			'group' => 'input',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-number-input',
				],
			],
		];

		$this->controls['inputBoxShadow'] = [
			'group' => 'input',
			'label' => esc_html__( 'Box shadow', 'bricks' ),
			'type'  => 'box-shadow',
			'css'   => [
				[
					'property' => 'box-shadow',
					'selector' => '.brx-number-input',
				],
			],
		];

		// CUSTOM STEPPER
		$this->controls['inputCustomStepperDecreaseIcon'] = [
			'group'    => 'customStepper',
			'label'    => esc_html__( 'Decrease icon', 'bricks' ),
			'type'     => 'icon',
			'rerender' => true,
			'css'      => [
				[
					'selector' => '.brx-stepper-button.step-down svg',
				],
			],
		];

		$this->controls['inputCustomStepperIncreaseIcon'] = [
			'group'    => 'customStepper',
			'label'    => esc_html__( 'Increase icon', 'bricks' ),
			'type'     => 'icon',
			'rerender' => true,
			'css'      => [
				[
					'selector' => '.brx-stepper-button.step-up svg',
				],
			],
		];

		$this->controls['inputCustomStepperButtonGap'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Gap', 'bricks' ) . ' (' . esc_html__( 'Buttons', 'bricks' ) . ')',
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => '--brx-cart-quantity-stepper-gap',
					'selector' => '.brx-stepper',
				],
			],
		];

		$this->controls['inputCustomStepperInputGap'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Gap', 'bricks' ) . ' (' . esc_html__( 'Input', 'bricks' ) . ')',
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => '.brx-number-wrap',
				],
			],
		];

		// Keep the existing key so saved size values become widths without losing responsive settings.
		$this->controls['inputCustomStepperButtonSize'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => '.brx-stepper-button',
				],
			],
		];

		$this->controls['inputCustomStepperButtonPadding'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Padding', 'bricks' ),
			'type'  => 'spacing',
			'css'   => [
				[
					'property' => 'padding',
					'selector' => '.brx-stepper-button',
				],
			],
		];

		$this->controls['inputCustomStepperButtonBackgroundColor'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Background color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => '.brx-stepper-button',
				],
			],
		];

		$this->controls['inputCustomStepperButtonBorder'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'property' => 'border',
					'selector' => '.brx-stepper-button',
				],
			],
		];

		$this->controls['inputCustomStepperButtonTypography'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-stepper-button',
				],
			],
		];

		$this->controls['inputCustomStepperButtonBoxShadow'] = [
			'group' => 'customStepper',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Box shadow', 'bricks' ),
			'type'  => 'box-shadow',
			'css'   => [
				[
					'property' => 'box-shadow',
					'selector' => '.brx-stepper-button',
				],
			],
		];
	}

	/**
	 * Build the editable number input HTML.
	 *
	 * @since 2.4
	 *
	 * @param string      $cart_item_key Cart item key.
	 * @param array       $cart_item     Cart item data.
	 * @param \WC_Product $product       WooCommerce product.
	 * @param string      $product_name  Filtered product name.
	 *
	 * @return string
	 */
	private function render_quantity_input( $cart_item_key, $cart_item, $product, $product_name ) {
		$settings        = $this->settings;
		$stepper_layout  = ! empty( $settings['stepperLayout'] ) ? $settings['stepperLayout'] : 'inline';
		$allowed_layouts = [ 'inline', 'stepper-left', 'stepper-right' ];

		if ( ! in_array( $stepper_layout, $allowed_layouts, true ) ) {
			$stepper_layout = 'inline';
		}

		$quantity      = isset( $cart_item['quantity'] ) ? wc_stock_amount( $cart_item['quantity'] ) : 1;
		$input_id      = 'woocommerce-cart-quantity-' . $this->id . '-' . sanitize_html_class( $cart_item_key );
		$product_name  = wp_strip_all_tags( $product_name );
		$quantity_args = function_exists( 'wc_get_quantity_input_args' ) ? wc_get_quantity_input_args(
			[
				'input_id'     => $input_id,
				'input_name'   => "cart[{$cart_item_key}][qty]",
				'input_value'  => $quantity,
				'max_value'    => $product->get_max_purchase_quantity(),
				'min_value'    => 0,
				'product_name' => $product_name,
			],
			$product
		) : [
			'input_id'     => $input_id,
			'input_name'   => "cart[{$cart_item_key}][qty]",
			'input_value'  => $quantity,
			'min_value'    => 0,
			'max_value'    => $product->get_max_purchase_quantity(),
			'step'         => 1,
			'type'         => 'number',
			'classes'      => [ 'qty' ],
			'pattern'      => '',
			'inputmode'    => 'numeric',
			'placeholder'  => '',
			'autocomplete' => 'off',
			'readonly'     => false,
		];

		$quantity_args['min_value'] = max( $quantity_args['min_value'] ?? 0, 0 );
		$quantity_args['max_value'] = ! empty( $quantity_args['max_value'] ) && $quantity_args['max_value'] > 0 ? $quantity_args['max_value'] : '';

		if ( $quantity_args['max_value'] !== '' && $quantity_args['max_value'] < $quantity_args['min_value'] ) {
			$quantity_args['max_value'] = $quantity_args['min_value'];
		}

		$aria_label = ! empty( $settings['inputAriaLabel'] ) ? $this->render_dynamic_data( $settings['inputAriaLabel'] ) : sprintf(
			/* translators: %s: Product name. */
			esc_html__( 'Quantity for %s', 'bricks' ),
			$product_name
		);

		if ( ! empty( $quantity_args['type'] ) && $quantity_args['type'] === 'hidden' ) {
			return sprintf(
				'<span class="brx-number-static-value">%s</span><input type="hidden" name="%s" value="%s" />',
				esc_html( $quantity_args['input_value'] ),
				esc_attr( $quantity_args['input_name'] ),
				esc_attr( $quantity_args['input_value'] )
			);
		}

		$show_stepper = empty( $quantity_args['readonly'] );

		$input_classes = array_unique( array_merge( [ 'brx-number-input', 'qty' ], $quantity_args['classes'] ?? [] ) );
		$input_id      = $quantity_args['input_id'] ?? $input_id;

		$this->set_attribute( 'number-wrap', 'class', 'brx-number-wrap' );
		$this->set_attribute( 'number-wrap', 'class', "brx-number-layout--{$stepper_layout}" );

		$this->set_attribute( 'input', 'type', $quantity_args['type'] ?? 'number' );
		$this->set_attribute( 'input', 'id', $input_id );
		$this->set_attribute( 'input', 'class', $input_classes );
		$this->set_attribute( 'input', 'name', $quantity_args['input_name'] ?? "cart[{$cart_item_key}][qty]" );
		$this->set_attribute( 'input', 'value', $quantity_args['input_value'] ?? $quantity );
		$this->set_attribute( 'input', 'min', $quantity_args['min_value'] ?? 0 );
		$this->set_attribute( 'input', 'step', $quantity_args['step'] ?? 1 );
		$this->set_attribute( 'input', 'aria-label', $aria_label );

		if ( isset( $quantity_args['max_value'] ) && $quantity_args['max_value'] !== '' ) {
			$this->set_attribute( 'input', 'max', $quantity_args['max_value'] );
		}

		if ( ! empty( $quantity_args['pattern'] ) ) {
			$this->set_attribute( 'input', 'pattern', $quantity_args['pattern'] );
		}

		if ( ! empty( $quantity_args['inputmode'] ) ) {
			$this->set_attribute( 'input', 'inputmode', $quantity_args['inputmode'] );
		}

		if ( ! empty( $quantity_args['placeholder'] ) ) {
			$this->set_attribute( 'input', 'placeholder', $quantity_args['placeholder'] );
		}

		if ( ! empty( $quantity_args['autocomplete'] ) ) {
			$this->set_attribute( 'input', 'autocomplete', $quantity_args['autocomplete'] );
		}

		if ( ! empty( $quantity_args['readonly'] ) ) {
			$this->set_attribute( 'input', 'readonly', 'readonly' );
		}

		$input_html = '<input ' . $this->render_attributes( 'input' ) . '>';

		if ( ! $show_stepper ) {
			return '<div ' . $this->render_attributes( 'number-wrap' ) . '>' . $input_html . '</div>';
		}

		$decrease_button = $this->render_stepper_button( 'down', $input_id, $product_name );
		$increase_button = $this->render_stepper_button( 'up', $input_id, $product_name );

		if ( $stepper_layout === 'inline' ) {
			return '<div ' . $this->render_attributes( 'number-wrap' ) . '>' . $decrease_button . $input_html . $increase_button . '</div>';
		}

		$this->set_attribute( 'stepper', 'class', 'brx-stepper' );
		$this->set_attribute( 'stepper', 'role', 'group' );
		$this->set_attribute( 'stepper', 'aria-label', esc_html__( 'Adjust quantity', 'bricks' ) );

		$stepper_html = '<span ' . $this->render_attributes( 'stepper' ) . '>' . $increase_button . $decrease_button . '</span>';

		if ( $stepper_layout === 'stepper-left' ) {
			return '<div ' . $this->render_attributes( 'number-wrap' ) . '>' . $stepper_html . $input_html . '</div>';
		}

		return '<div ' . $this->render_attributes( 'number-wrap' ) . '>' . $input_html . $stepper_html . '</div>';
	}

	/**
	 * Build one custom stepper button.
	 *
	 * @since 2.4
	 *
	 * @param string $direction    Step direction.
	 * @param string $input_id     Input ID.
	 * @param string $product_name Product name.
	 *
	 * @return string
	 */
	private function render_stepper_button( $direction, $input_id, $product_name ) {
		$is_decrease = $direction === 'down';
		$handle      = $is_decrease ? 'step-down' : 'step-up';
		$text        = $is_decrease ? '-' : '+';
		$icon_key    = $is_decrease ? 'inputCustomStepperDecreaseIcon' : 'inputCustomStepperIncreaseIcon';
		$icon        = ! empty( $this->settings[ $icon_key ] )
			? self::render_icon( $this->settings[ $icon_key ], [ 'aria-hidden' => 'true' ] )
			: '';
		$label       = sprintf(
			/* translators: %s: Product name. */
			$is_decrease ? esc_html__( 'Decrease quantity for %s', 'bricks' ) : esc_html__( 'Increase quantity for %s', 'bricks' ),
			$product_name
		);

		$this->set_attribute( $handle, 'type', 'button' );
		$this->set_attribute( $handle, 'class', 'brx-stepper-button' );
		$this->set_attribute( $handle, 'class', $is_decrease ? 'step-down' : 'step-up' );
		$this->set_attribute( $handle, 'data-stepper-direction', $direction );
		$this->set_attribute( $handle, 'data-input-id', $input_id );
		$this->set_attribute( $handle, 'aria-controls', $input_id );
		$this->set_attribute( $handle, 'aria-label', $label );

		$button_content = $icon ? $icon : '<span aria-hidden="true">' . esc_html( $text ) . '</span>';

		return '<button ' . $this->render_attributes( $handle ) . '>' . $button_content . '</button>';
	}

	/**
	 * Render element.
	 *
	 * @since 2.4
	 */
	public function render() {
		$loop_object_type = Query::is_looping() ? Query::get_query_object_type() : false;

		if ( $loop_object_type !== 'wooCart' ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'Use this element inside a WooCommerce cart contents query loop.', 'bricks' ),
					]
				);
			}

			return;
		}

		$loop_object   = Query::get_loop_object();
		$cart_item_key = isset( $loop_object['key'] ) ? $loop_object['key'] : false;
		$_product      = isset( $loop_object['data'] ) ? $loop_object['data'] : false;

		if ( $cart_item_key === false || $cart_item_key === '' || ! $_product || ! is_a( $_product, 'WC_Product' ) ) {
			return;
		}

		$product_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $loop_object, $cart_item_key );

		if ( $_product->is_sold_individually() ) {
			$product_quantity = sprintf(
				'<span class="brx-number-static-value">1</span><input type="hidden" name="%s" value="1" />',
				esc_attr( "cart[{$cart_item_key}][qty]" )
			);
		} else {
			$product_quantity = $this->render_quantity_input( $cart_item_key, $loop_object, $_product, $product_name );
		}

		$product_quantity = apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $loop_object );

		$this->set_attribute( '_root', 'class', 'product-quantity' );
		$this->set_attribute( '_root', 'class', 'brx-woo-cart-quantity-update' );

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $product_quantity; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce', false, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
}
