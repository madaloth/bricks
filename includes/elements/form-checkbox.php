<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Form_Checkbox extends Element {
	public $category        = 'general';
	public $name            = 'form-checkbox';
	public $icon            = 'ti-check-box';
	public $tag             = 'label';
	public $panel_condition = [ 'templateType', '=', '__never__' ]; // Do not show in panel at this stage. Currently only generated via WooCommerce Checkout state

	/**
	 * Return the label text for a generated WooCommerce checkout checkbox.
	 *
	 * The existing "Label text" control should act as a real override. When it is
	 * empty, fall back to WooCommerce/native defaults so existing checkouts keep the
	 * expected wording. Terms text also supports Woo placeholders such as [terms].
	 *
	 * @since 2.3
	 *
	 * @param string $woo_field      WooCommerce checkbox identifier.
	 * @param string $override_text  Optional Bricks label override.
	 *
	 * @return string
	 */
	private function get_woo_checkbox_label_text( $woo_field, $override_text = '' ) {
		if ( $woo_field === 'shipAddress' ) {
			return $override_text !== '' ? $override_text : esc_html__( 'Ship to a different address?', 'woocommerce' );
		}

		if ( $woo_field === 'createaccount' ) {
			return $override_text !== '' ? $override_text : esc_html__( 'Create an account?', 'woocommerce' );
		}

		if ( $woo_field === 'accountLoginRememberMe' ) {
			return $override_text !== '' ? $override_text : esc_html__( 'Remember me', 'woocommerce' );
		}

		if ( $woo_field === 'terms' ) {
			$terms_text = $override_text !== '' ? $override_text : wc_get_terms_and_conditions_checkbox_text();

			if ( $terms_text === '' ) {
				return '';
			}

			return wp_kses_post( wc_replace_policy_page_link_placeholders( $terms_text ) );
		}

		return $override_text;
	}

	public function get_label() {
		return esc_html__( 'Form', 'bricks' ) . ' - ' . esc_html__( 'Checkbox', 'bricks' );
	}

	public function get_keywords() {
		return [ 'input', 'form', 'field', 'checkbox' ];
	}

	public function set_control_groups() {
		$this->control_groups['label'] = [
			'title' => esc_html__( 'Label', 'bricks' ),
		];

		$this->control_groups['indicator'] = [
			'title' => esc_html__( 'Checkbox', 'bricks' ) . ': ' . esc_html__( 'Indicator', 'bricks' ),
		];
	}

	public function set_controls() {
		$this->controls['wooFields'] = [
			'label'   => esc_html__( 'WooCommerce fields', 'bricks' ),
			'type'    => 'select',
			'options' => [
				'shipAddress'            => esc_html__( 'Checkout ship to different address', 'bricks' ),
				'terms'                  => esc_html__( 'Checkout terms and conditions', 'bricks' ),
				'createaccount'          => esc_html__( 'Checkout create account', 'bricks' ),
				'accountLoginRememberMe' => esc_html__( 'Account login remember me', 'bricks' ),
			],
			'inline'  => true,
		];

		$this->controls['text'] = [
			'label'  => esc_html__( 'Label text', 'bricks' ),
			'type'   => 'text',
			'inline' => true,
		];

		$this->controls['termsInfo'] = [
			'type'     => 'info',
			'content'  => esc_html__( 'The "Label text" control supports WooCommerce placeholders such as [terms] and [privacy_policy]. Example: "I agree to the [terms] and have read the [privacy_policy]."', 'bricks' ),
			'required' => [ 'wooFields', '=', 'terms' ],
		];

		// $this->controls['inputSep'] = [
		// 'label' => esc_html__( 'Input', 'bricks' ),
		// 'type'  => 'separator',
		// ];

		// $this->controls['inputId'] = [
		// 'label'          => 'ID',
		// 'type'           => 'text',
		// 'hasDynamicData' => false,
		// ];

		// $this->controls['inputClass'] = [
		// 'label'          => esc_html__( 'Classes', 'bricks' ),
		// 'type'           => 'text',
		// 'hasDynamicData' => false,
		// ];

		// $this->controls['inputName'] = [
		// 'label'       => esc_html__( 'Name', 'bricks' ),
		// 'type'        => 'text',
		// 'placeholder' => "form-field-{$this->id}",
		// ];

		// $this->controls['inputValue'] = [
		// 'label'       => esc_html__( 'Value', 'bricks' ),
		// 'type'        => 'text',
		// 'placeholder' => '1',
		// ];

		// $this->controls['inputChecked'] = [
		// 'label'  => esc_html__( 'Checked', 'bricks' ),
		// 'type'   => 'checkbox',
		// 'inline' => true,
		// ];

		// $this->controls['inputRequired'] = [
		// 'label'  => esc_html__( 'Required', 'bricks' ),
		// 'type'   => 'checkbox',
		// 'inline' => true,
		// ];

		// $this->controls['inputDisabled'] = [
		// 'label'  => esc_html__( 'Disabled', 'bricks' ),
		// 'type'   => 'checkbox',
		// 'inline' => true,
		// ];

		// $this->controls['a11ySep'] = [
		// 'label' => esc_html__( 'Accessibility', 'bricks' ),
		// 'type'  => 'separator',
		// ];

		// $this->controls['inputAriaLabel'] = [
		// 'label'          => esc_html__( 'Aria label', 'bricks' ),
		// 'type'           => 'text',
		// 'hasDynamicData' => false,
		// ];

		// $this->controls['inputAriaDescribedby'] = [
		// 'label'          => esc_html__( 'Aria describedby', 'bricks' ),
		// 'type'           => 'text',
		// 'hasDynamicData' => false,
		// ];

		$this->controls['labelSep'] = [
			'group' => 'label',
			'label' => esc_html__( 'Label', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['labelGap'] = [
			'group' => 'label',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => '--brx-indicator-gap',
				],
			],
		];

		$this->controls['labelAlign'] = [
			'group'       => 'label',
			'label'       => esc_html__( 'Align', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'baseline'   => esc_html__( 'Baseline', 'bricks' ),
				'flex-start' => esc_html_x( 'Top', 'position', 'bricks' ),
				'center'     => esc_html__( 'Center', 'bricks' ),
				'flex-end'   => esc_html__( 'Bottom', 'bricks' ),
			],
			'inline'      => true,
			'placeholder' => esc_html__( 'Baseline', 'bricks' ),
			'css'         => [
				[
					'property' => 'align-items',
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
				],
			],
		];

		$this->controls['termsLinkSep'] = [
			'group'    => 'label',
			'label'    => esc_html__( 'Terms link', 'bricks' ),
			'type'     => 'separator',
			'required' => [ 'wooFields', '=', 'terms' ],
		];

		$this->controls['termsLinkTypography'] = [
			'group'    => 'label',
			'label'    => esc_html__( 'Typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'selector' => '.woocommerce-terms-and-conditions-link',
					'property' => 'font',
				],
			],
			'required' => [ 'wooFields', '=', 'terms' ],
		];

		$this->controls['termsAbbrSep'] = [
			'group'    => 'label',
			'label'    => esc_html__( 'Required asterisk', 'bricks' ),
			'type'     => 'separator',
			'required' => [ 'wooFields', '=', 'terms' ],
		];

		$this->controls['termsAbbrTypography'] = [
			'group'    => 'label',
			'label'    => esc_html__( 'Typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'selector' => 'abbr.required',
					'property' => 'font',
				],
			],
			'required' => [ 'wooFields', '=', 'terms' ],
		];

		$indicator_controls = $this->get_checkbox_indicator_controls(
			[
				'group' => 'indicator',
				'label' => esc_html__( 'Checkbox', 'bricks' ),
			]
		);

		$this->controls = array_merge( $this->controls, $indicator_controls );
	}

	public function render() {
		$settings     = $this->settings;
		$woo_field    = ! empty( $settings['wooFields'] ) ? $settings['wooFields'] : '';
		$text         = ! empty( $settings['text'] ) ? $this->render_dynamic_data( $settings['text'] ) : '';
		$input_name   = ! empty( $settings['inputName'] ) ? $this->render_dynamic_data( $settings['inputName'] ) : "form-field-{$this->id}";
		$input_value  = ! empty( $settings['inputValue'] ) ? $this->render_dynamic_data( $settings['inputValue'] ) : '1';
		$input_id     = ! empty( $settings['inputId'] ) ? $settings['inputId'] : '';
		$input_class  = ! empty( $settings['inputClass'] ) ? $settings['inputClass'] : '';
		$aria_label   = ! empty( $settings['inputAriaLabel'] ) ? $settings['inputAriaLabel'] : '';
		$aria_desc_by = ! empty( $settings['inputAriaDescribedby'] ) ? $settings['inputAriaDescribedby'] : '';
		$text_classes = [ 'brx-option-text' ];

		if ( empty( $woo_field ) || ! in_array( $woo_field, [ 'shipAddress', 'terms', 'createaccount', 'accountLoginRememberMe' ], true ) ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'Select a WooCommerce field to display', 'bricks' ),
				]
			);
		}

		switch ( $woo_field ) {
			case 'shipAddress':
				// Woo checkout JS requires this wrapper ID to toggle and validate the shipping fields.
				$this->attributes['_root']['id'] = 'ship-to-different-address';

				// The native ID replaces the normal Bricks ID, so retain a unique class for
				// builder and frontend styles. (#86cavrwb8; @since 2.4)
				$this->set_attribute( '_root', 'class', "brxe-{$this->uid}" );
				$this->set_attribute( '_root', 'class', [ 'woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'checkbox' ] );
				$input_id    = 'ship-to-different-address-checkbox';
				$input_name  = 'ship_to_different_address';
				$input_value = '1';
				$text        = $this->get_woo_checkbox_label_text( $woo_field, $text );
				$checked     = checked( apply_filters( 'woocommerce_ship_to_different_address_checked', 'shipping' === get_option( 'woocommerce_ship_to_destination' ) ? 1 : 0 ), 1, false );

				if ( strpos( $checked, 'checked' ) !== false ) {
					$this->set_attribute( 'input', 'checked', 'checked' );
				}
				break;

			case 'terms':
				/**
				 * Follow WooCommerce's checkout/terms.php visibility rules. The hidden
				 * terms-field input also enables server-side acceptance validation, so
				 * neither input may render when WooCommerce disables the checkbox.
				 *
				 * @since 2.4.5 #123pt1p0yz9
				 */
				if ( ! apply_filters( 'woocommerce_checkout_show_terms', true ) || ! wc_terms_and_conditions_checkbox_enabled() ) {
					if ( bricks_is_builder() ) {
						return $this->render_element_placeholder(
							[
								'title' => esc_html__( 'The terms and conditions checkbox is not enabled in WooCommerce.', 'bricks' ),
							]
						);
					}

					return;
				}

				// Match Woo terms markup classes so client-side validation applies on this field.
				$this->set_attribute( '_root', 'class', [ 'form-row', 'validate-required', 'woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'checkbox' ] );
				$input_id       = 'terms';
				$input_name     = 'terms';
				$input_value    = '1';
				$text_classes[] = 'woocommerce-terms-and-conditions-checkbox-text';
				$text           = $this->get_woo_checkbox_label_text( $woo_field, $text );

				if ( $text !== '' ) {
					$text .= '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr>';
				}
				break;

			case 'createaccount':
				$checkout              = WC()->checkout();
				$registration_disabled = ! $checkout || ! $checkout->is_registration_enabled();
				$registration_required = $checkout && $checkout->is_registration_required();

				if ( $registration_disabled ) {
					if ( bricks_is_builder() ) {
						// Inform builder users why the create account checkbox is not showing instead of just showing an empty placeholder.
						return $this->render_element_placeholder(
							[
								'title' => esc_html__( 'Create account option is not available because registration is disabled.', 'bricks' ),
							]
						);
					}

					return;
				}

				// WooCommerce omits the choice when every guest must register; the
				// account wrapper keeps the credential fields visible instead. (#86cb33dre; @since 2.4)
				if ( $registration_required ) {
					if ( bricks_is_builder() ) {
						return $this->render_element_placeholder(
							[
								'title' => esc_html__( 'Create account option is hidden because registration is required.', 'bricks' ),
							]
						);
					}

					return;
				}

				// Actual frontend but logged in, no need to show create account checkbox.
				if ( is_user_logged_in() && ! bricks_is_builder() ) {
					return;
				}

				$this->set_attribute( '_root', 'class', [ 'form-row', 'woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'checkbox' ] );
				$input_id    = 'createaccount';
				$input_name  = 'createaccount';
				$input_value = '1';
				$text        = $this->get_woo_checkbox_label_text( $woo_field, $text );

				$checked = $checkout ? checked(
					(
						true === $checkout->get_value( 'createaccount' ) ||
						true === apply_filters( 'woocommerce_create_account_default_checked', false )
					),
					true,
					false
				) : '';

				// Set checked attribute if this field is checked by default to prevent a flash of unstyled content on page load.
				if ( strpos( $checked, 'checked' ) !== false ) {
					$this->set_attribute( 'input', 'checked', 'checked' );
				}
				break;

			case 'accountLoginRememberMe':
				$this->set_attribute( '_root', 'class', [ 'woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'woocommerce-form-login__rememberme' ] );
				$input_id    = 'rememberme';
				$input_name  = 'rememberme';
				$input_value = 'forever';
				$text        = $this->get_woo_checkbox_label_text( $woo_field, $text );
				break;

			default:
				if ( bricks_is_builder() ) {
					return $this->render_element_placeholder(
						[
							'title' => esc_html__( 'Selected WooCommerce field is not supported.', 'bricks' ),
						]
					);
				}
				return;
		}

		$this->set_attribute( 'input', 'type', 'checkbox' );
		$this->set_attribute( 'input', 'name', $input_name );
		$this->set_attribute( 'input', 'value', $input_value );
		$this->set_attribute( 'input', 'class', [ 'brx-a11y-hidden' ] );
		$this->set_attribute( 'input', 'class', [ 'woocommerce-form__input', 'woocommerce-form__input-checkbox', 'input-checkbox' ] );

		if ( $input_id !== '' ) {
			$this->set_attribute( 'input', 'id', $input_id );
		}

		if ( $input_class !== '' ) {
			$this->set_attribute( 'input', 'class', $input_class );
		}

		if ( isset( $settings['inputChecked'] ) ) {
			$this->set_attribute( 'input', 'checked', 'checked' );
		}

		if ( isset( $settings['inputRequired'] ) ) {
			$this->set_attribute( 'input', 'required', 'required' );
		}

		if ( isset( $settings['inputDisabled'] ) ) {
			$this->set_attribute( 'input', 'disabled', 'disabled' );
		}

		if ( $aria_label !== '' ) {
			$this->set_attribute( 'input', 'aria-label', $aria_label );
		} elseif ( $text === '' ) {
			$this->set_attribute( 'input', 'aria-label', esc_html__( 'Checkbox', 'bricks' ) );
		}

		if ( $aria_desc_by !== '' ) {
			$this->set_attribute( 'input', 'aria-describedby', $aria_desc_by );
		}

		$this->set_attribute( 'indicator', 'class', 'brx-input-indicator' );
		$this->set_attribute( 'indicator', 'aria-hidden', 'true' );

		echo "<label {$this->render_attributes( '_root' )}>";
		echo "<input {$this->render_attributes( 'input' )}>";
		echo "<span {$this->render_attributes( 'indicator' )}></span>";

		if ( $text !== '' ) {
			echo '<span class="' . esc_attr( implode( ' ', $text_classes ) ) . '">' . wp_kses_post( $text ) . '</span>';
		}

		echo '</label>';

		if ( $woo_field === 'terms' ) {
			// WooCommerce terms checkbox relies on a hidden input.
			echo '<input type="hidden" name="terms-field" value="1" />';
		}
	}
}
