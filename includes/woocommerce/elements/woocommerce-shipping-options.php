<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Shipping_Options extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-shipping-options';
	public $icon            = 'ti-truck';
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Shipping options', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'shipping', 'methods', 'options', 'radio' ];
	}

	public function set_control_groups() {
		$this->control_groups['package'] = [
			'title' => esc_html__( 'Package', 'bricks' ),
		];

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

		$this->control_groups['indicator'] = [
			'title' => esc_html__( 'Radio', 'bricks' ) . ': ' . esc_html__( 'Indicator', 'bricks' ),
		];
	}

	public function set_controls() {
		$checked_option_selector          = '.brx-shipping-options__option:has(> input[type="radio"]:checked), .brx-shipping-options__option.brx-shipping-options__option--checked';
		$checked_label_selector           = '.brx-shipping-options__option > input[type="radio"]:checked ~ .brx-shipping-options__option-layout .brx-shipping-options__label, .brx-shipping-options__option.brx-shipping-options__option--checked .brx-shipping-options__label';
		$checked_secondary_label_selector = '.brx-shipping-options__option > input[type="radio"]:checked ~ .brx-shipping-options__option-layout .brx-shipping-options__secondary-label, .brx-shipping-options__option.brx-shipping-options__option--checked .brx-shipping-options__secondary-label';

		$this->controls['showPackageTitle'] = [
			'group' => 'package',
			'label' => esc_html__( 'Show package title', 'bricks' ),
			'type'  => 'checkbox',
			'value' => true,
		];

		$this->controls['emptyMessage'] = [
			'group'       => 'package',
			'label'       => esc_html__( 'Empty message', 'bricks' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'No shipping options available.', 'bricks' ),
		];

		$package_controls = $this->generate_standard_controls( 'package', '.brx-shipping-options__package', [ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ] );
		$package_controls = $this->controls_grouping( $package_controls, 'package' );
		$this->controls   = array_merge( $this->controls, $package_controls );

		$this->controls['controlFlexDirection'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Direction', 'bricks' ),
			'type'  => 'direction',
			'css'   => [
				[
					'property' => 'flex-direction',
					'selector' => '.brx-shipping-options__control',
				],
			],
		];

		$this->controls['controlJustifyContent'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Justify content', 'bricks' ),
			'type'  => 'justify-content',
			'css'   => [
				[
					'property' => 'justify-content',
					'selector' => '.brx-shipping-options__control',
				],
			],
		];

		$this->controls['controlAlignItems'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Align items', 'bricks' ),
			'type'  => 'align-items',
			'css'   => [
				[
					'property' => 'align-items',
					'selector' => '.brx-shipping-options__control',
				],
			],
		];

		$this->controls['controlGap'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => '.brx-shipping-options__control',
				],
			],
		];

		$this->controls['packageTitleTypography'] = [
			'group'    => 'package',
			'label'    => esc_html__( 'Title typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'property' => 'font',
					'selector' => '.brx-shipping-options__package-title',
				],
			],
			'required' => [ 'showPackageTitle', '=', true ],
		];

		$this->controls['packageTitleColor'] = [
			'group'    => 'package',
			'label'    => esc_html__( 'Title color', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => 'color',
					'selector' => '.brx-shipping-options__package-title',
				],
			],
			'required' => [ 'showPackageTitle', '=', true ],
		];

		$option_controls = $this->generate_standard_controls( 'option', '.brx-shipping-options__option', [ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ] );
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
					'selector' => '.brx-shipping-options__label-group',
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
					'selector' => '.brx-shipping-options__option:hover',
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
					'selector' => $checked_option_selector,
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
					'selector' => $checked_option_selector,
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
					'selector' => $checked_option_selector,
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
					'selector' => '.brx-shipping-options__label',
				],
			],
		];

		$this->controls['labelColor'] = [
			'group' => 'label',
			'label' => esc_html__( 'Color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'color',
					'selector' => '.brx-shipping-options__label',
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
					'selector' => $checked_label_selector,
				],
			],
		];

		$this->controls['labelCheckedColor'] = [
			'group' => 'label',
			'label' => esc_html__( 'Color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'color',
					'selector' => $checked_label_selector,
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
					'selector' => '.brx-shipping-options__secondary-label',
				],
			],
		];

		$this->controls['secondaryColor'] = [
			'group' => 'secondary',
			'label' => esc_html__( 'Color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'color',
					'selector' => '.brx-shipping-options__secondary-label',
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
					'selector' => $checked_secondary_label_selector,
				],
			],
		];

		$this->controls['secondaryCheckedColor'] = [
			'group' => 'secondary',
			'label' => esc_html__( 'Color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'color',
					'selector' => $checked_secondary_label_selector,
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

	/**
	 * Format the shipping price and preserve customized WooCommerce full labels.
	 *
	 * @since 2.4.4
	 *
	 * @param \WC_Shipping_Rate $method        Calculated shipping rate.
	 * @param bool              $is_full_label Whether the output replaces the method name as well.
	 * @return string
	 */
	private function get_rate_secondary_label( $method, &$is_full_label = false ) {
		$is_full_label = false;
		$cost          = method_exists( $method, 'get_cost' ) ? (float) $method->get_cost() : 0;
		$label         = '';

		if ( $cost > 0 ) {
			$shipping_tax = (float) $method->get_shipping_tax();
			$tax_label    = '';

			// The cart also accounts for tax-exempt customers and display filters.
			if ( WC()->cart && WC()->cart->display_prices_including_tax() ) {
				$cost += $shipping_tax;

				if ( $shipping_tax > 0 && ! wc_prices_include_tax() ) {
					$tax_label = WC()->countries->inc_tax_or_vat();
				}
			} elseif ( $shipping_tax > 0 && wc_prices_include_tax() ) {
				$tax_label = WC()->countries->ex_tax_or_vat();
			}

			$label = wc_price( $cost );

			if ( $tax_label !== '' ) {
				$label .= ' <small class="tax_label">' . $tax_label . '</small>';
			}
		}

		// Match WooCommerce's full-label input while keeping unchanged labels split for styling.
		$full_label     = $method->get_label() . ( $label !== '' ? ': ' . $label : '' );
		$filtered_label = apply_filters( 'woocommerce_cart_shipping_method_full_label', $full_label, $method );

		if ( $filtered_label !== $full_label ) {
			// Annotations and provider summaries cannot reliably be reduced to a price.
			$filtered_label = wp_kses_post( $filtered_label );
			// Safe markup can still be empty; retain the method name unless it supplies label content.
			$is_full_label = $this->has_rate_label_content( $filtered_label );
			return $filtered_label;
		}

		if ( $label === '' ) {
			return esc_html__( 'Free', 'woocommerce' );
		}

		return wp_kses_post( $label );
	}

	/**
	 * Check sanitized label text, including image alternatives, before replacing the method name.
	 *
	 * @since 2.4.5
	 *
	 * @param string $label Sanitized shipping label HTML.
	 * @return bool
	 */
	private function has_rate_label_content( $label ) {
		$text = wp_strip_all_tags( $label );

		// Image-only provider labels can have an accessible name without any text nodes.
		foreach ( wp_html_split( $label ) as $part ) {
			if ( preg_match( '/^<img\s/i', $part ) ) {
				$attributes = wp_kses_hair( substr( $part, 4, -1 ), wp_allowed_protocols() );
				$text      .= $attributes['alt']['value'] ?? '';
			}
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return preg_match( '/[^\s\p{Z}]/u', $text ) === 1;
	}

	public function render() {
		Woocommerce_Helpers::maybe_load_cart();
		Woocommerce_Helpers::maybe_init_cart_context();
		Woocommerce_Helpers::maybe_populate_cart_contents();

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->shipping() ) {
			return;
		}

		$packages = WC()->shipping()->get_packages();

		if ( empty( $packages ) || ! is_array( $packages ) ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'No shipping packages found.', 'bricks' ),
					]
				);
			}

			return;
		}

		$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [];
		$show_titles    = isset( $this->settings['showPackageTitle'] );
		$empty_message  = ! empty( $this->settings['emptyMessage'] ) ? $this->settings['emptyMessage'] : esc_html__( 'No shipping options available.', 'bricks' );

		echo "<div {$this->render_attributes( '_root' )}>";

		// Render each package independently so controls can target package-level wrappers.
		foreach ( $packages as $index => $package ) {
			$available_methods = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : [];
			$chosen_method     = $chosen_methods[ $index ] ?? '';
			$package_key       = 'package_' . $index;
			$package_title_key = 'package_title_' . $index;
			$empty_key         = 'empty_' . $index;
			$control_key       = 'control_' . $index;
			$package_title     = apply_filters(
				'woocommerce_shipping_package_name',
				// translators: %d: Shipping package number.
				sprintf( esc_html__( 'Shipping %d', 'woocommerce' ), ( $index + 1 ) ),
				$index,
				$package
			);

			$this->set_attribute( $package_key, 'class', 'brx-shipping-options__package' );
			$this->set_attribute( $package_title_key, 'class', 'brx-shipping-options__package-title' );
			$this->set_attribute( $empty_key, 'class', 'brx-shipping-options__empty' );
			$this->set_attribute( $control_key, 'class', 'brx-shipping-options__control' );
			echo "<div {$this->render_attributes( $package_key )}>";

			if ( $show_titles ) {
				echo "<div {$this->render_attributes( $package_title_key )}>" . esc_html( wp_strip_all_tags( (string) $package_title ) ) . '</div>';
			}

			if ( empty( $available_methods ) ) {
				echo "<div {$this->render_attributes( $empty_key )}>" . esc_html( $empty_message ) . '</div>';
				echo '</div>';
				continue;
			}

			echo "<div {$this->render_attributes( $control_key )}>";

			// Keep WooCommerce input naming/index format to preserve checkout updates and validation.
			foreach ( $available_methods as $method ) {
				$method_id       = $method->id;
				$method_key      = esc_attr( sanitize_title( $method_id ) );
				$input_id        = 'brx_shipping_method_' . $index . '_' . $method_key;
				$description_id  = $input_id . '__secondary-label';
				$option_classes  = [ 'brx-shipping-options__option' ];
				$is_checked      = $method_id === $chosen_method || count( $available_methods ) === 1;
				$secondary_label = $this->get_rate_secondary_label( $method, $is_full_label );
				$method_label    = method_exists( $method, 'get_label' ) ? $method->get_label() : $method_id;
				$primary_label   = $is_full_label ? $secondary_label : esc_html( wp_strip_all_tags( (string) $method_label ) );

				/**
				 * Filter the primary label after WooCommerce's full-label customization.
				 *
				 * Returned HTML is sanitized. Empty or contentless output retains the resolved
				 * label so the shipping option keeps its accessible name. This filter does not
				 * change the secondary label or calculated shipping rate.
				 *
				 * @since 2.4.5
				 *
				 * @param string                      $label   Resolved method name or customized full-label HTML.
				 * @param \WC_Shipping_Rate            $method  Calculated shipping rate.
				 * @param Woocommerce_Shipping_Options $element Shipping options element instance.
				 */
				$filtered_primary_label = wp_kses_post( apply_filters( 'bricks/woocommerce/shipping_options/primary_label', $primary_label, $method, $this ) );

				if ( $this->has_rate_label_content( $filtered_primary_label ) ) {
					$primary_label = $filtered_primary_label;
				}

				/**
				 * Filter the secondary label independently of WooCommerce's complete shipping label.
				 *
				 * Receives the formatted price or Free label for ordinary rates, or an empty
				 * string when a customized full label supplies the primary label. Returned HTML
				 * is sanitized; return an empty string to omit this label and its accessible
				 * description link. This filter does not change the calculated shipping rate.
				 *
				 * @since 2.4.5
				 *
				 * @param string                      $label   Resolved secondary label HTML.
				 * @param \WC_Shipping_Rate            $method  Calculated shipping rate.
				 * @param Woocommerce_Shipping_Options $element Shipping options element instance.
				 */
				$secondary_label = wp_kses_post( apply_filters( 'bricks/woocommerce/shipping_options/secondary_label', $is_full_label ? '' : $secondary_label, $method, $this ) );

				$input_type      = count( $available_methods ) > 1 ? 'radio' : 'hidden';
				$option_key      = 'option_' . $index . '_' . $method_key;
				$input_key       = 'input_' . $index . '_' . $method_key;
				$layout_key      = 'layout_' . $index . '_' . $method_key;
				$label_group_key = 'label_group_' . $index . '_' . $method_key;
				$label_key       = 'label_' . $index . '_' . $method_key;
				$secondary_key   = 'secondary_' . $index . '_' . $method_key;
				$indicator_key   = 'indicator_' . $index . '_' . $method_key;

				if ( $input_type === 'hidden' && $is_checked ) {
					$option_classes[] = 'brx-shipping-options__option--checked';
				}

				$this->set_attribute( $option_key, 'class', $option_classes );
				$this->set_attribute( $option_key, 'for', $input_id );

				$this->set_attribute( $input_key, 'id', $input_id );
				$this->set_attribute( $input_key, 'class', [ 'shipping_method', 'brx-shipping-options__input', 'brx-a11y-hidden' ] );
				$this->set_attribute( $input_key, 'type', $input_type );
				$this->set_attribute( $input_key, 'name', 'shipping_method[' . absint( $index ) . ']' );
				$this->set_attribute( $input_key, 'data-index', absint( $index ) );
				$this->set_attribute( $input_key, 'value', $method_id );
				if ( $secondary_label !== '' ) {
					$this->set_attribute( $input_key, 'aria-describedby', $description_id );
				}

				if ( $input_type === 'radio' && $is_checked ) {
					$this->set_attribute( $input_key, 'checked', 'checked' );
				}

				$this->set_attribute( $layout_key, 'class', 'brx-shipping-options__option-layout' );
				$this->set_attribute( $label_group_key, 'class', 'brx-shipping-options__label-group' );
				$this->set_attribute( $label_key, 'class', 'brx-shipping-options__label' );
				$this->set_attribute( $secondary_key, 'id', $description_id );
				$this->set_attribute( $secondary_key, 'class', 'brx-shipping-options__secondary-label' );

				$this->set_attribute( $indicator_key, 'class', 'brx-input-indicator' );
				$this->set_attribute( $indicator_key, 'aria-hidden', 'true' );

				echo "<label {$this->render_attributes( $option_key )}>";
				echo "<input {$this->render_attributes( $input_key )}>";
				echo "<span {$this->render_attributes( $indicator_key )}></span>";
				echo "<div {$this->render_attributes( $layout_key )}>";
				echo "<div {$this->render_attributes( $label_group_key )}>";
				echo "<span {$this->render_attributes( $label_key )}>" . wp_kses_post( $primary_label ) . '</span>';
				if ( $secondary_label !== '' ) {
					echo "<span {$this->render_attributes( $secondary_key )}>" . wp_kses_post( $secondary_label ) . '</span>';
				}
				echo '</div>';
				echo '</div>';
				echo '</label>';

				do_action( 'woocommerce_after_shipping_rate', $method, $index );
			}

			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}
}
