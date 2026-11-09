<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Payment_Options extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-payment-options';
	public $icon            = 'ti-credit-card';
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Payment options', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'payment', 'gateway' ];
	}

	public function set_control_groups() {
		$this->control_groups['wrapper'] = [
			'title' => esc_html__( 'Wrapper', 'bricks' ),
		];

		$this->control_groups['option'] = [
			'title' => esc_html__( 'Option', 'bricks' ),
		];

		$this->control_groups['label'] = [
			'title' => esc_html__( 'Label', 'bricks' ),
		];

		$this->control_groups['secondary'] = [
			'title' => esc_html__( 'Secondary label', 'bricks' ),
		];

		$this->control_groups['content'] = [
			'title' => esc_html__( 'Content', 'bricks' ),
		];

		$this->control_groups['notice'] = [
			'title' => esc_html__( 'Notice', 'bricks' ),
		];

		$this->control_groups['indicator'] = [
			'title' => esc_html__( 'Radio', 'bricks' ) . ': ' . esc_html__( 'Indicator', 'bricks' ),
		];
	}

	public function set_controls() {
		$this->controls['wrapperDirection'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Direction', 'bricks' ),
			'type'  => 'direction',
			'css'   => [
				[
					'property' => 'flex-direction',
					'selector' => '.brx-payment-options__control',
				],
			],
		];

		$this->controls['wrapperJustifyContent'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Justify content', 'bricks' ),
			'type'  => 'justify-content',
			'css'   => [
				[
					'property' => 'justify-content',
					'selector' => '.brx-payment-options__control',
				],
			],
		];

		$this->controls['wrapperAlignItems'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Align items', 'bricks' ),
			'type'  => 'align-items',
			'css'   => [
				[
					'property' => 'align-items',
					'selector' => '.brx-payment-options__control',
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
					'selector' => '.brx-payment-options__control',
				],
			],
		];

		$option_controls = $this->generate_standard_controls( 'option', '.brx-payment-options__option', [ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ] );
		$option_controls = $this->controls_grouping( $option_controls, 'option' );
		$this->controls  = array_merge( $this->controls, $option_controls );

		$this->controls['optionGap'] = [
			'group' => 'option',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => '.brx-payment-options__label-group',
				],
			],
		];

		$this->controls['optionHoverBackgroundColor'] = [
			'group' => 'option',
			'label' => esc_html__( 'Hover background color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => '.brx-payment-options__option:hover',
				],
			],
		];

		$this->controls['optionCheckedSep'] = [
			'group' => 'option',
			'label' => esc_html__( 'Radio', 'bricks' ) . ': ' . esc_html__( 'Checked', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['optionCheckedBackgroundColor'] = [
			'group' => 'option',
			'label' => esc_html__( 'Background color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => '.brx-payment-options__option:has(> input[type="radio"]:checked)',
				],
			],
		];

		$this->controls['optionCheckedBorder'] = [
			'group' => 'option',
			'label' => esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'property' => 'border',
					'selector' => '.brx-payment-options__option:has(> input[type="radio"]:checked)',
				],
			],
		];

		$this->controls['optionCheckedBoxShadow'] = [
			'group' => 'option',
			'label' => esc_html__( 'Box shadow', 'bricks' ),
			'type'  => 'box-shadow',
			'css'   => [
				[
					'property' => 'box-shadow',
					'selector' => '.brx-payment-options__option:has(> input[type="radio"]:checked)',
				],
			],
		];

		$this->controls['labelTypography'] = [
			'group' => 'label',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-payment-options__label',
				],
			],
		];

		$this->controls['labelCheckedSep'] = [
			'group' => 'label',
			'label' => esc_html__( 'Radio', 'bricks' ) . ': ' . esc_html__( 'Checked', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['labelCheckedTypography'] = [
			'group' => 'label',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-payment-options__option > input[type="radio"]:checked ~ .brx-payment-options__option-layout .brx-payment-options__label',
				],
			],
		];

		$this->controls['secondaryTypography'] = [
			'group' => 'secondary',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-payment-options__secondary-label',
				],
			],
		];

		$this->controls['secondaryCheckedSep'] = [
			'group' => 'secondary',
			'label' => esc_html__( 'Radio', 'bricks' ) . ': ' . esc_html__( 'Checked', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['secondaryCheckedTypography'] = [
			'group' => 'secondary',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-payment-options__option > input[type="radio"]:checked ~ .brx-payment-options__option-layout .brx-payment-options__secondary-label',
				],
			],
		];

		$content_controls = $this->generate_standard_controls( 'content', '.brx-payment-options__content', [ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ] );
		$content_controls = $this->controls_grouping( $content_controls, 'content' );
		$this->controls   = array_merge( $this->controls, $content_controls );

		$this->controls['contentTypography'] = [
			'group' => 'content',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-payment-options__content',
				],
			],
		];

		$this->controls['noticeTypography'] = [
			'group' => 'notice',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-payment-options__empty',
				],
			],
		];

		// Keep the control hints aligned with this element's radio fallbacks in SCSS.
		$indicator_controls = $this->get_checkbox_indicator_controls(
			[
				'group'               => 'indicator',
				'label'               => esc_html__( 'Radio', 'bricks' ),
				'includeBorderRadius' => false,
				'indicatorType'       => 'radio',
				'accentColor'         => '#616161',
			]
		);

		$this->controls = array_merge( $this->controls, $indicator_controls );
	}

	public function render() {
		Woocommerce_Helpers::maybe_load_cart();
		Woocommerce_Helpers::maybe_init_cart_context();
		Woocommerce_Helpers::maybe_populate_cart_contents();

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return;
		}

		$is_pay_order  = $this->is_pay_order_context();
		$order         = $is_pay_order ? Woocommerce::get_contextual_checkout_v2_order( 'pay' ) : false;
		$needs_payment = $is_pay_order
			? is_a( $order, 'WC_Order' ) && $order->needs_payment()
			: WC()->cart && WC()->cart->needs_payment();

		if ( ! $needs_payment ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder(
					[
						'title' => $is_pay_order
							? esc_html__( 'Order does not require payment.', 'bricks' )
							: esc_html__( 'Cart does not require payment.', 'bricks' ),
					]
				);
			}

			// Checkout fragments need a replacement target when shipping or coupons make payment necessary again.
			if ( ! $is_pay_order ) {
				$this->set_attribute( '_root', 'data-brx-needs-payment', 'false' );
				$this->set_attribute( '_root', 'hidden', '' );
				echo "<div {$this->render_attributes( '_root' )}></div>";
			}

			return;
		}

		$available_gateways    = WC()->payment_gateways()->get_available_payment_gateways();
		$chosen_payment_method = WC()->session ? WC()->session->get( 'chosen_payment_method', '' ) : '';
		$container_key         = 'container';
		$methods_key           = 'methods';

		$this->set_attribute( '_root', 'data-brx-needs-payment', 'true' );
		$this->set_attribute( $container_key, 'id', 'payment' );
		$this->set_attribute( $container_key, 'class', 'brx-payment-options__container' );
		$this->set_attribute( $methods_key, 'class', [ 'wc_payment_methods', 'payment_methods', 'methods', 'brx-payment-options__control' ] );
		$this->set_attribute( $methods_key, 'aria-label', esc_html__( 'Payment methods', 'woocommerce' ) );

		echo "<div {$this->render_attributes( '_root' )}>";
		echo "<div {$this->render_attributes( $container_key )}>";
		echo "<ul {$this->render_attributes( $methods_key )}>";

		if ( ! empty( $available_gateways ) ) {
			$gateway_index = 0;

			foreach ( $available_gateways as $gateway ) {
				$gateway_id      = $gateway->id;
				$gateway_key     = sanitize_title( $gateway_id );
				$input_id        = 'payment_method_' . $gateway_id;
				$content_id      = $input_id . '__content';
				$option_key      = 'option_' . $gateway_key;
				$li_key          = 'li_' . $gateway_key;
				$input_key       = 'input_' . $gateway_key;
				$indicator_key   = 'indicator_' . $gateway_key;
				$layout_key      = 'layout_' . $gateway_key;
				$label_group_key = 'label_group_' . $gateway_key;
				$label_key       = 'label_' . $gateway_key;
				$secondary_key   = 'secondary_' . $gateway_key;
				$content_key     = 'content_' . $gateway_key;
				$has_content     = $gateway->has_fields() || $gateway->get_description();
				$is_checked      = $chosen_payment_method ? $gateway_id === $chosen_payment_method : $gateway_index === 0;
				$li_classes      = [ 'wc_payment_method', 'payment_method_' . $gateway_id, 'brx-payment-options__item' ];
				$option_classes  = [ 'brx-payment-options__option' ];

				$this->set_attribute( $li_key, 'class', $li_classes );
				$this->set_attribute( $option_key, 'class', $option_classes );
				$this->set_attribute( $label_key, 'for', $input_id );

				$this->set_attribute( $input_key, 'id', $input_id );
				$this->set_attribute( $input_key, 'class', [ 'input-radio', 'brx-payment-options__input', 'brx-a11y-hidden' ] );
				$this->set_attribute( $input_key, 'type', 'radio' );
				$this->set_attribute( $input_key, 'name', 'payment_method' );
				$this->set_attribute( $input_key, 'value', $gateway_id );

				if ( ! empty( $gateway->order_button_text ) ) {
					$this->set_attribute( $input_key, 'data-order_button_text', $gateway->order_button_text );
				}

				if ( $has_content ) {
					$this->set_attribute( $input_key, 'aria-describedby', $content_id );
				}

				if ( $is_checked ) {
					$this->set_attribute( $input_key, 'checked', 'checked' );
				}

				$this->set_attribute( $indicator_key, 'class', 'brx-input-indicator' );
				$this->set_attribute( $indicator_key, 'aria-hidden', 'true' );
				$this->set_attribute( $layout_key, 'class', 'brx-payment-options__option-layout' );
				$this->set_attribute( $label_group_key, 'class', 'brx-payment-options__label-group' );
				$this->set_attribute( $label_key, 'class', 'brx-payment-options__label' );
				$this->set_attribute( $secondary_key, 'class', 'brx-payment-options__secondary-label' );

				echo "<li {$this->render_attributes( $li_key )}>";
				/**
				 * WooCommerce Stripe Gateway optimized checkout replaces the associated label's contents with its title.
				 * Keep the radio and indicator outside that label so selection survives checkout refreshes.
				 *
				 * @since 2.4.1 (#86cbj0axm)
				 */
				echo "<div {$this->render_attributes( $option_key )}>";
				echo "<input {$this->render_attributes( $input_key )}>";
				echo "<span {$this->render_attributes( $indicator_key )}></span>";
				echo "<span {$this->render_attributes( $layout_key )}>";
				echo "<span {$this->render_attributes( $label_group_key )}>";
				echo "<label {$this->render_attributes( $label_key )}>" . wp_kses_post( $gateway->get_title() ) . '</label>';

				if ( ! empty( $gateway->get_icon() ) ) {
					echo "<span {$this->render_attributes( $secondary_key )}>" . wp_kses_post( $gateway->get_icon() ) . '</span>';
				}

				echo '</span>';
				echo '</span>';
				echo '</div>';

				if ( $has_content ) {
					$this->set_attribute( $content_key, 'id', $content_id );
					$this->set_attribute( $content_key, 'class', [ 'payment_box', 'payment_method_' . $gateway_id, 'brx-payment-options__content' ] );

					if ( ! $is_checked ) {
						$this->set_attribute( $content_key, 'style', 'display:none;' );
					}

					echo "<div {$this->render_attributes( $content_key )}>";
					$gateway->payment_fields();
					echo '</div>';
				}

				echo '</li>';
				$gateway_index++;
			}
		} else {
			$empty_key = 'empty';

			$this->set_attribute( $empty_key, 'class', 'brx-payment-options__empty' );
			echo '<li>';
			echo "<div {$this->render_attributes( $empty_key )}>";

			wc_print_notice(
				apply_filters(
					'woocommerce_no_available_payment_methods_message',
					WC()->customer->get_billing_country()
						? esc_html__( 'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' )
						: esc_html__( 'Please fill in your details above to see available payment methods.', 'woocommerce' )
				),
				'notice'
			);

			echo '</div>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Check whether this element is rendering inside the pay-for-order context.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private function is_pay_order_context() {
		if ( Woocommerce::has_checkout_v2_state_preview_context( 'pay' ) ) {
			return true;
		}

		if ( get_query_var( 'order-pay', false ) ) {
			return true;
		}

		if ( Helpers::is_bricks_template( get_the_ID() ) && Templates::get_template_type( get_the_ID() ) === 'wc_form_pay' ) {
			return true;
		}

		$element = $this->element;

		while ( ! empty( $element['parent'] ) ) {
			$parent_id = $element['parent'];
			$parent    = Frontend::$elements[ $parent_id ] ?? false;

			if ( ! is_array( $parent ) ) {
				break;
			}

			if ( ( $parent['name'] ?? '' ) === 'woocommerce-checkout-v2-state-pay' ) {
				return true;
			}

			$element = $parent;
		}

		return false;
	}
}
