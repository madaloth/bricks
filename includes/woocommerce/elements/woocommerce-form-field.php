<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Generic WooCommerce form field element.
 *
 * Renders a field via woocommerce_form_field() based on element settings.
 *
 * @since 2.4
 */
class Woocommerce_Form_Field extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-form-field';
	public $icon            = 'ti-pencil-alt';
	public $scripts         = [ 'bricksWooFormField' ];
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ], [ 'wooPage', '=', 'myaccount' ] ];

	public function get_label() {
		return esc_html__( 'WooCommerce form field', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woo', 'form', 'field', 'input', 'select', 'textarea' ];
	}

	/**
	 * Set style control groups that can be scoped to an individual field.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function set_control_groups() {
		$this->set_woo_checkout_form_field_common_style_control_groups();
	}

	public function set_controls() {
		$this->controls['fieldSource'] = [
			'label'       => esc_html__( 'Source', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'checkout'             => esc_html__( 'Checkout', 'bricks' ),
				'accountEditAddress'   => esc_html__( 'Account edit address', 'bricks' ),
				'accountEditAccount'   => esc_html__( 'Account edit account', 'bricks' ),
				'accountLogin'         => esc_html__( 'Account login', 'bricks' ),
				'accountRegister'      => esc_html__( 'Account register', 'bricks' ),
				'orderWithdrawal'      => esc_html__( 'Order withdrawal', 'bricks' ),
				'accountLostPassword'  => esc_html__( 'Account lost password', 'bricks' ),
				'accountResetPassword' => esc_html__( 'Account reset password', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Checkout', 'bricks' ),
			'rerender'    => true,
		];

		if ( ! Woocommerce::is_order_withdrawal_enabled() ) {
			unset( $this->controls['fieldSource']['options']['orderWithdrawal'] );
		}

		$this->controls['fieldKey'] = [
			'label'       => esc_html__( 'Field key', 'bricks' ),
			'type'        => 'select',
			'options'     => $this->get_available_fields(),
			'add'         => true,
			'placeholder' => esc_html__( 'Select a field key', 'bricks' ),
		];

		$this->controls['label'] = [
			'label' => esc_html__( 'Label', 'bricks' ),
			'type'  => 'text',
		];

		$this->controls['hideLabel'] = [
			'label' => esc_html__( 'Hide label', 'bricks' ),
			'type'  => 'checkbox',
		];

		$this->controls['placeholder'] = [
			'label' => esc_html__( 'Placeholder', 'bricks' ),
			'type'  => 'text',
		];

		$this->controls['disableRowFirstLast'] = [
			'label' => esc_html__( 'Disable first/last row classes', 'bricks' ),
			'type'  => 'checkbox',
			'value' => true,
		];

		$this->controls['fieldStylesGuide'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Recommend to style on the state elements (e.g. Checkout state) instead of individual fields for consistent design.', 'bricks' ),
		];

		$field_common_controls = $this->get_woo_checkout_form_field_common_style_controls(
			[
				'field_key'                       => 'commonFieldStyles',
				'group'                           => 'commonFieldStyles',
				'scope'                           => '&.brxe-woocommerce-form-field', // For higher specificity that can override the common field styles configured in the state element
				'wrapper_scope'                   => '', // Keep wrapper <p> controls scoped to root selector, as before.
				'label_required_control'          => 'hideLabel',
				'select2_required_control'        => 'fieldKey',
				'select2_required_values'         => [ 'billing_country', 'shipping_country', 'billing_state', 'shipping_state', 'country', 'state' ],
				'select2_dropdown_scope'          => '&.select2-container',
				'include_select2_dropdown_portal' => true,
				'group_by_separator'              => true,
			]
		);

		foreach ( $field_common_controls as $control_key => $control ) {
			$this->controls[ lcfirst( $control_key ) ] = $control;
		}
	}

	/**
	 * Retrieve all available fields from WooCommerce and plugins.
	 */
	private function get_available_fields() {
		$options = [];

		if ( ! function_exists( 'WC' ) ) {
			return $options;
		}

		$checkout_fields = bricks_is_builder() && method_exists( WC(), 'checkout' ) ? WC()->checkout()->get_checkout_fields() : [];

		foreach ( $checkout_fields as $section => $fields ) {
			if ( empty( $fields ) || ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$field_label = ! empty( $field['label'] ) ? $field['label'] : $key;
				$this->add_available_field_option( $options, $key, ucwords( (string) $section ) . ': ' . $field_label );
			}
		}

		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			foreach ( $this->get_account_edit_account_fields() as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$field_label = ! empty( $field['label'] ) ? $field['label'] : $key;
				$this->add_available_field_option( $options, $key, esc_html__( 'Account edit account', 'bricks' ) . ': ' . $field_label );
			}

			// Account edit-address options use normalized keys because the runtime endpoint decides billing vs shipping.
			$account_fields = Woocommerce::get_account_edit_address_generation_fields();

			foreach ( $account_fields as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$field_label = ! empty( $field['label'] ) ? $field['label'] : $key;
				$this->add_available_field_option( $options, $key, esc_html__( 'Account edit address', 'bricks' ) . ': ' . $field_label );
			}

			foreach ( $this->get_account_auth_fields() as $source => $fields ) {
				if ( empty( $fields ) || ! is_array( $fields ) ) {
					continue;
				}

				foreach ( $fields as $key => $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}

					$field_label = ! empty( $field['label'] ) ? $field['label'] : $key;
					$this->add_available_field_option( $options, $key, $this->get_account_auth_source_label( $source ) . ': ' . $field_label );
				}
			}
		}

		if ( Woocommerce::is_order_withdrawal_enabled() ) {
			foreach ( Woocommerce_Order_Withdrawal::get_preview_context()['fields'] as $key => $field ) {
				$this->add_available_field_option( $options, $key, esc_html__( 'Order withdrawal', 'bricks' ) . ': ' . $field['label'] );
			}
		}

		return $options;
	}

	/**
	 * Add a field option, preserving useful labels when several sources share the same key.
	 *
	 * @since 2.4
	 *
	 * @param array  $options Options keyed by field key.
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @return void
	 */
	private function add_available_field_option( &$options, $key, $label ) {
		if ( empty( $options[ $key ] ) ) {
			$options[ $key ] = $label;
			return;
		}

		if ( strpos( (string) $options[ $key ], (string) $label ) === false ) {
			$options[ $key ] .= ' / ' . $label;
		}
	}

	/**
	 * Enqueue scripts required by specific Woo account edit-account fields.
	 *
	 * @since 2.4
	 */
	public function enqueue_scripts() {
		$source    = $this->settings['fieldSource'] ?? '';
		$field_key = $this->settings['fieldKey'] ?? '';

		if ( $source === 'accountEditAccount' && in_array( $field_key, [ 'password_1', 'password_2', 'password_current' ], true ) ) {
			wp_enqueue_script( 'woocommerce' );
			wp_enqueue_script( 'wc-password-strength-meter' );
		}

		if ( $source === 'accountLogin' && $field_key === 'password' ) {
			wp_enqueue_script( 'woocommerce' );
		}

		if ( $source === 'accountRegister' && $field_key === 'password' ) {
			wp_enqueue_script( 'woocommerce' );
			wp_enqueue_script( 'wc-password-strength-meter' );
		}

		if ( $source === 'accountResetPassword' && in_array( $field_key, [ 'password_1', 'password_2' ], true ) ) {
			wp_enqueue_script( 'woocommerce' );
			wp_enqueue_script( 'wc-password-strength-meter' );
		}
	}

	/**
	 * Get checkout field config by key from all checkout sections.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key
	 * @return array
	 */
	protected function get_checkout_field_config( $field_key ) {
		if ( ! $field_key || ! function_exists( 'WC' ) || ! WC() || ! WC()->checkout() ) {
			return [];
		}

		$checkout_fields = WC()->checkout()->get_checkout_fields();

		if ( ! is_array( $checkout_fields ) ) {
			return [];
		}

		foreach ( $checkout_fields as $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}

			if ( isset( $fields[ $field_key ] ) && is_array( $fields[ $field_key ] ) ) {
				return $fields[ $field_key ];
			}
		}

		return [];
	}

	/**
	 * Sanitize and normalize supported Woo field args.
	 *
	 * @since 2.4
	 *
	 * @param mixed $field_args
	 * @return array
	 */
	protected function sanitize_field_args( $field_args ) {
		if ( ! is_array( $field_args ) ) {
			return [];
		}

		$normalized = [];

		if ( isset( $field_args['type'] ) ) {
			$normalized['type'] = sanitize_text_field( (string) $field_args['type'] );
		}

		if ( isset( $field_args['label'] ) ) {
			$normalized['label'] = sanitize_text_field( (string) $field_args['label'] );
		}

		if ( isset( $field_args['id'] ) ) {
			$normalized['id'] = sanitize_html_class( (string) $field_args['id'] );
		}

		if ( isset( $field_args['placeholder'] ) ) {
			$normalized['placeholder'] = sanitize_text_field( (string) $field_args['placeholder'] );
		}

		if ( isset( $field_args['default'] ) ) {
			$normalized['default'] = wc_clean( $field_args['default'] );
		}

		if ( isset( $field_args['required'] ) ) {
			$normalized['required'] = ! empty( $field_args['required'] );
		}

		if ( isset( $field_args['autocomplete'] ) ) {
			$normalized['autocomplete'] = sanitize_text_field( (string) $field_args['autocomplete'] );
		}

		if ( isset( $field_args['country'] ) ) {
			$normalized['country'] = sanitize_text_field( (string) $field_args['country'] );
		}

		if ( isset( $field_args['country_field'] ) ) {
			$normalized['country_field'] = sanitize_text_field( (string) $field_args['country_field'] );
		}

		foreach ( [ 'class', 'label_class', 'input_class', 'validate' ] as $array_key ) {
			if ( empty( $field_args[ $array_key ] ) || ! is_array( $field_args[ $array_key ] ) ) {
				continue;
			}

			$normalized[ $array_key ] = array_values(
				array_filter(
					array_map( 'sanitize_html_class', $field_args[ $array_key ] )
				)
			);
		}

		if ( ! empty( $field_args['custom_attributes'] ) && is_array( $field_args['custom_attributes'] ) ) {
			$normalized['custom_attributes'] = [];

			foreach ( $field_args['custom_attributes'] as $attribute_key => $attribute_value ) {
				$sanitized_key = sanitize_key( $attribute_key );
				if ( $sanitized_key === '' ) {
					continue;
				}

				$normalized['custom_attributes'][ $sanitized_key ] = sanitize_text_field( (string) $attribute_value );
			}
		}

		if ( ! empty( $field_args['options'] ) && is_array( $field_args['options'] ) ) {
			$normalized['options'] = [];

			foreach ( $field_args['options'] as $option_key => $option_label ) {
				$normalized['options'][ sanitize_text_field( (string) $option_key ) ] = sanitize_text_field( (string) $option_label );
			}
		}

		return $normalized;
	}

	/**
	 * Apply element attributes to field args classes/custom_attributes.
	 *
	 * @since 2.4
	 *
	 * @param array $field_args
	 * @return array
	 */
	protected function apply_root_attributes_to_field_args( $field_args ) {
		$root_classes      = [ 'brxe-woocommerce-form-field', 'brxe-' . $this->uid ];
		$custom_attributes = (array) ( $field_args['custom_attributes'] ?? [] );

		$root_attributes = $this->render_attributes( '_root', false, true );

		if ( is_array( $root_attributes ) && count( $root_attributes ) ) {
			foreach ( $root_attributes as $attr_name => $attr_value ) {
				if ( $attr_name === 'class' ) {
					$attr_classes = is_array( $attr_value ) ? $attr_value : preg_split( '/\s+/', trim( (string) $attr_value ) );
					$attr_classes = array_values( array_filter( $attr_classes ) );
					$root_classes = array_merge( $root_classes, $attr_classes );
					continue;
				}

				if ( is_array( $attr_value ) ) {
					$attr_value = join( ' ', $attr_value );
				}

				$custom_attributes[ $attr_name ] = $attr_value;
			}
		}

		$field_classes = array_merge( (array) ( $field_args['class'] ?? [] ), $root_classes );

		if ( ! empty( $this->settings['disableRowFirstLast'] ) ) {
			$field_classes = array_filter(
				$field_classes,
				function( $class_name ) {
					return ! in_array( $class_name, [ 'form-row-first', 'form-row-last' ], true );
				}
			);
		}

		// Woo generates a unique ID per radio option that must match its label target.
		if ( ( $field_args['type'] ?? '' ) === 'radio' ) {
			unset( $custom_attributes['id'] );
		}

		$field_args['class']             = array_values( array_unique( $field_classes ) );
		$field_args['custom_attributes'] = $custom_attributes;

		return $field_args;
	}

	/**
	 * Apply Woo notice validation state to the field wrapper and input.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @param array  $field_args Field args.
	 * @return array
	 */
	protected function apply_notice_error_state_to_field_args( $field_key, $field_args ) {
		// Builder Woo notices may be preview placeholders, so avoid turning generated fields red in the canvas.
		if ( bricks_is_builder() || bricks_is_builder_call() || bricks_is_builder_iframe() || isset( $_GET['bricks_preview'] ) ) {
			return $field_args;
		}

		$field_key    = sanitize_text_field( (string) $field_key );
		$field_errors = Woocommerce::get_woo_notice_field_errors();

		if ( $field_key === '' || empty( $field_errors[ $field_key ] ) ) {
			return $field_args;
		}

		$field_classes = (array) ( $field_args['class'] ?? [] );
		$field_classes = array_merge( $field_classes, [ 'woocommerce-invalid', 'brx-woo-field-error' ] );

		if ( ! empty( $field_args['required'] ) ) {
			$field_classes[] = 'validate-required';
			$field_classes[] = 'woocommerce-invalid-required-field';
		}

		$custom_attributes                 = (array) ( $field_args['custom_attributes'] ?? [] );
		$custom_attributes['aria-invalid'] = 'true';

		$field_args['class']             = array_values( array_unique( array_filter( $field_classes ) ) );
		$field_args['custom_attributes'] = $custom_attributes;

		return $field_args;
	}

	/**
	 * Render a WooCommerce form field with a first-paint SelectWoo shell.
	 *
	 * The filter is active only while this modular element renders, so classic and
	 * third-party WooCommerce fields keep their original markup.
	 *
	 * @since 2.4 #86cb6m9n5
	 *
	 * @param string $field_key Field key.
	 * @param array  $field_args Field arguments.
	 * @param string $value Field value.
	 * @return void
	 */
	private function render_form_field_with_selectwoo_shell( $field_key, $field_args, $value ) {
		$filter = function( $field, $rendered_key, $rendered_args, $rendered_value ) {
			$field_type = isset( $rendered_args['type'] ) ? (string) $rendered_args['type'] : '';

			if ( ! in_array( $field_type, [ 'country', 'state' ], true ) ) {
				return $field;
			}

			return $this->inject_selectwoo_pre_init_shell( $field );
		};

		add_filter( 'woocommerce_form_field', $filter, PHP_INT_MAX, 4 );

		try {
			woocommerce_form_field( $field_key, $field_args, $value );
		} finally {
			remove_filter( 'woocommerce_form_field', $filter, PHP_INT_MAX );
		}
	}

	/**
	 * Inject an inert SelectWoo-shaped shell before a standard country/state select.
	 *
	 * Reading the selected option from WooCommerce's final HTML preserves field
	 * filters and keeps the shell text in sync with the native source of truth.
	 *
	 * @since 2.4 #86cb6m9n5
	 *
	 * @param string $field Rendered WooCommerce field HTML.
	 * @return string
	 */
	private function inject_selectwoo_pre_init_shell( $field ) {
		if ( strpos( $field, '<span class="woocommerce-input-wrapper">' ) === false ) {
			return $field;
		}

		preg_match_all( '/<select\b[^>]*>.*?<\/select>/is', $field, $select_matches, PREG_OFFSET_CAPTURE );

		foreach ( $select_matches[0] as $select_match ) {
			$select_html   = $select_match[0];
			$select_offset = $select_match[1];

			if ( ! preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $select_html, $class_match ) ) {
				continue;
			}

			$select_classes = preg_split( '/\s+/', trim( $class_match[2] ) );

			if ( ! array_intersect( [ 'country_select', 'state_select' ], $select_classes ) ) {
				continue;
			}

			$before_select    = substr( $field, 0, $select_offset );
			$wrapper_position = strrpos( $before_select, '<span class="woocommerce-input-wrapper">' );

			if ( $wrapper_position === false ) {
				continue;
			}

			$option = $this->get_selectwoo_pre_init_option( $select_html );

			if ( empty( $option ) ) {
				continue;
			}

			$shell = $this->get_selectwoo_pre_init_shell( $option );

			return substr_replace( $field, $shell, $select_offset, 0 );
		}

		return $field;
	}

	/**
	 * Get the option SelectWoo will display on initialization.
	 *
	 * @since 2.4 #86cb6m9n5
	 *
	 * @param string $select_html Rendered select HTML.
	 * @return array
	 */
	private function get_selectwoo_pre_init_option( $select_html ) {
		preg_match_all( '/<option\b([^>]*)>(.*?)<\/option>/is', $select_html, $options, PREG_SET_ORDER );

		if ( empty( $options ) ) {
			return [];
		}

		$selected_option = $options[0];

		foreach ( $options as $option ) {
			if ( preg_match( '/(?:^|\s)selected(?:\s*=\s*(["\'])selected\1)?(?:\s|$)/i', trim( $option[1] ) ) ) {
				$selected_option = $option;
				break;
			}
		}

		$option_value = '';

		if ( preg_match( '/\bvalue\s*=\s*(["\'])(.*?)\1/is', $selected_option[1], $value_match ) ) {
			$option_value = html_entity_decode( $value_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$option_text = html_entity_decode( wp_strip_all_tags( $selected_option[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return [
			'text'           => trim( $option_text ),
			'is_placeholder' => $option_value === '',
		];
	}

	/**
	 * Build the inert SelectWoo-shaped first-paint shell.
	 *
	 * @since 2.4 #86cb6m9n5
	 *
	 * @param array $option Selected option data.
	 * @return string
	 */
	private function get_selectwoo_pre_init_shell( $option ) {
		$rendered_class = 'select2-selection__rendered';

		if ( ! empty( $option['is_placeholder'] ) ) {
			$rendered_class .= ' select2-selection__placeholder';
		}

		$shell  = '<span class="select2 select2-container select2-container--default bricks-selectwoo--pre-init" dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '" data-brx-selectwoo-pre-init aria-hidden="true" style="width: 100%">';
		$shell .= '<span class="selection">';
		$shell .= '<span class="select2-selection select2-selection--single">';
		$shell .= '<span class="' . esc_attr( $rendered_class ) . '">' . esc_html( $option['text'] ) . '</span>';
		$shell .= '<span class="select2-selection__arrow" aria-hidden="true"><b></b></span>';
		$shell .= '</span>';
		$shell .= '</span>';
		$shell .= '<span class="dropdown-wrapper" aria-hidden="true"></span>';
		$shell .= '</span>';

		return $shell;
	}

	public function render() {
		$settings      = $this->settings;
		$valid_sources = [ 'orderWithdrawal', 'checkout', 'accountEditAddress', 'accountEditAccount', 'accountLogin', 'accountRegister', 'accountLostPassword', 'accountResetPassword' ];
		$source        = isset( $settings['fieldSource'] ) && in_array( $settings['fieldSource'], $valid_sources, true ) ? $settings['fieldSource'] : 'checkout';
		$field_key     = trim( (string) ( $settings['fieldKey'] ?? '' ) );

		if ( $field_key === '' ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder( [ 'title' => esc_html__( 'No field key provided.', 'bricks' ) ] );
			}

			return;
		}

		if ( $source === 'orderWithdrawal' ) {
			$this->render_order_withdrawal_field( $field_key );
			return;
		}

		if ( $source === 'accountEditAddress' ) {
			$this->render_account_edit_address_field( $field_key );
			return;
		}

		if ( $source === 'accountEditAccount' ) {
			$this->render_account_edit_account_field( $field_key );
			return;
		}

		if ( in_array( $source, [ 'accountLogin', 'accountRegister', 'accountLostPassword', 'accountResetPassword' ], true ) ) {
			$this->render_account_auth_field( $source, $field_key );
			return;
		}

		// Initialize cart context to ensure checkout fields are available.
		Woocommerce_Helpers::maybe_load_cart();
		Woocommerce_Helpers::maybe_init_cart_context();
		Woocommerce_Helpers::maybe_populate_cart_contents();

		$source_field_args = $this->sanitize_field_args( $this->get_checkout_field_config( $field_key ) );

		// Woo 11.2 removes disabled order notes from the registry. Saved layouts
		// must not recreate that field using the generic custom-field defaults.
		// Keep the element editable in the Builder and leave other order fields
		// available to extensions; disabling notes does not disable their section.
		// @see https://github.com/woocommerce/woocommerce/issues/68168
		if ( $field_key === 'order_comments' && empty( $source_field_args ) && ! bricks_is_builder() && ! bricks_is_builder_call() ) {
			return;
		}

		// Legacy presets had no fieldSource; allow address fields to keep rendering after upgrading into Account v2.
		if ( empty( $source_field_args ) && ! array_key_exists( 'fieldSource', $settings ) && $this->is_account_edit_address_context() ) {
			$field_data = Woocommerce::get_account_edit_address_field_data( $field_key );

			if ( $field_data ) {
				$this->render_account_edit_address_field( $field_key );
				return;
			}
		}

		// Removal controls change Woo's registry, but leave saved elements intact.
		// Preserve the generic fallback for custom fields not explicitly removed.
		if ( empty( $source_field_args ) && ! bricks_is_builder() && ! bricks_is_builder_call() && is_checkout() ) {
			foreach ( Woocommerce::get_removed_checkout_fields() as $removed_fields ) {
				if ( in_array( $field_key, $removed_fields, true ) ) {
					return;
				}
			}
		}

		$field_args = array_merge(
			[
				'type'              => 'text',
				'label'             => '',
				'placeholder'       => '',
				'required'          => false,
				'class'             => [ 'form-row' ],
				'label_class'       => [],
				'input_class'       => [],
				'validate'          => [],
				'custom_attributes' => [],
				'return'            => false,
			],
			$source_field_args
		);

		if ( array_key_exists( 'label', $settings ) ) {
			$field_args['label'] = sanitize_text_field( (string) $settings['label'] );
		}

		if ( array_key_exists( 'placeholder', $settings ) ) {
			$field_args['placeholder'] = sanitize_text_field( (string) $settings['placeholder'] );
		}

		if ( ! empty( $settings['hideLabel'] ) ) {
			$field_args['label'] = '';
		}

		$field_args = $this->apply_root_attributes_to_field_args( $field_args );
		$field_args = $this->apply_notice_error_state_to_field_args( $field_key, $field_args );

		$checkout_value = '';

		if ( ! empty( $source_field_args ) && function_exists( 'WC' ) && method_exists( WC(), 'checkout' ) ) {
			$checkout_value = WC()->checkout()->get_value( $field_key );
		}

		if ( function_exists( 'woocommerce_form_field' ) ) {
			$this->render_form_field_with_selectwoo_shell( $field_key, $field_args, $checkout_value );
		}
	}

	/**
	 * Render a field from Woo's prepared withdrawal arguments.
	 *
	 * @since 2.4
	 *
	 * @param string $key Native field key.
	 * @return void
	 */
	private function render_order_withdrawal_field( $key ) {
		$context = Woocommerce_Order_Withdrawal::get_context();
		$args    = $context['fields'][ $key ] ?? [];
		if ( empty( $args['name'] ) || ( ! ( bricks_is_builder() || bricks_is_builder_call() ) && ( $context['screen'] ?? '' ) !== 'form' ) ) {
			return;
		}

		$name           = $args['name'];
		$value          = $context['data'][ $key ] ?? '';
		$error          = $context['errors'][ $key ] ?? '';
		$args['return'] = true;
		if ( ! empty( $this->settings['disableRowFirstLast'] ) ) {
			$args['class'] = array_values( array_diff( $args['class'] ?? [], [ 'form-row-first', 'form-row-last', 'woocommerce-form-row--first', 'woocommerce-form-row--last' ] ) );
		}

		if ( ! empty( $this->settings['hideLabel'] ) ) {
			$args['label_class'][] = 'screen-reader-text';
		}
		if ( isset( $this->settings['label'] ) ) {
			$args['label'] = sanitize_text_field( $this->settings['label'] );
		}
		if ( isset( $this->settings['placeholder'] ) ) {
			$args['placeholder'] = sanitize_text_field( $this->settings['placeholder'] );
		}

		// A blank placeholder lets the shared floating style detect empty text fields.
		if ( empty( $args['placeholder'] ) ) {
			$args['placeholder'] = ' ';
		}
		$this->set_attribute( '_root', 'class', 'brxe-woocommerce-form-field--order-withdrawal' );

		$error_html = $error !== '' ? '<span class="woocommerce-order-withdrawal-content__field-error" id="' . esc_attr( $name . '_error' ) . '">' . esc_html( $error ) . '</span>' : '';

		if ( $key === 'withdrawal_type' ) {
			$this->set_attribute( '_root', 'class', 'brx-woo-withdrawal-options' );
			$this->set_attribute( '_root', 'class', 'form-row' );
			if ( $error !== '' ) {
				$this->set_attribute( '_root', 'class', 'woocommerce-invalid brx-woo-field-error' );
			}
			echo '<fieldset ' . $this->render_attributes( '_root' ) . '><legend' . ( ! empty( $this->settings['hideLabel'] ) ? ' class="screen-reader-text"' : '' ) . '>' . esc_html( $args['label'] ?? '' );
			if ( ! empty( $args['required'] ) ) {
				echo ' <span class="required" aria-hidden="true">*</span>';
			}
			echo '</legend>';
			foreach ( $context['withdrawal_type_options'] ?? [] as $option => $label ) {
				$input_id = ( $args['id'] ?? $name ) . '_' . $option;
				echo '<label for="' . esc_attr( $input_id ) . '" class="woocommerce-order-withdrawal-content__radio-label"><input type="radio" class="input-radio" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option ) . '"' . checked( $value, $option, false );
				if ( ! empty( $args['required'] ) ) {
					echo ' required aria-required="true"';
				}
				if ( $error !== '' ) {
					echo ' aria-invalid="true" aria-describedby="' . esc_attr( $name . '_error' ) . '" aria-errormessage="' . esc_attr( $name . '_error' ) . '"';
				}
				echo '> ' . esc_html( $label ) . '</label>';
			}
			echo $error_html;
			echo '</fieldset>';
			return;
		}

		$args = $this->apply_root_attributes_to_field_args( $args );
		// The native input ID must continue matching its label and Woo's notice targets.
		unset( $args['custom_attributes']['id'] );
		$field = woocommerce_form_field( $name, $args, $value );
		if ( $error_html !== '' ) {
			// Keep validation feedback within the same field row and its configured spacing.
			$closing_row = strrpos( $field, '</p>' );
			$field       = $closing_row === false ? $field . $error_html : substr_replace( $field, $error_html, $closing_row, 0 );
		}
		echo $field;
	}

	/**
	 * Check whether the field is rendering inside the Account v2 edit-address state.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private function is_account_edit_address_context() {
		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			return Woocommerce::has_account_v2_state_preview_context( 'edit-address' );
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() && Woocommerce::get_account_v2_current_state() === 'edit-address' ) {
			return true;
		}

		global $wp;

		return isset( $wp->query_vars['edit-address'] ) && $wp->query_vars['edit-address'] !== '';
	}

	/**
	 * Render an Account v2 edit-address field.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @return void
	 */
	private function render_account_edit_address_field( $field_key ) {
		$settings   = $this->settings;
		$field_data = Woocommerce::get_account_edit_address_field_data( $field_key );

		// A normalized field can be valid for one address type but absent from the current billing/shipping endpoint.
		if ( ! $field_data ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				$this->render_element_placeholder( [ 'title' => esc_html__( 'Field not available for the current address.', 'bricks' ) ] );
			}

			return;
		}

		$field_args = array_merge(
			[
				'type'              => 'text',
				'label'             => '',
				'placeholder'       => '',
				'required'          => false,
				'class'             => [ 'form-row' ],
				'label_class'       => [],
				'input_class'       => [],
				'validate'          => [],
				'custom_attributes' => [],
				'return'            => false,
			],
			$this->sanitize_field_args( $field_data['field'] )
		);

		if ( array_key_exists( 'label', $settings ) ) {
			$field_args['label'] = sanitize_text_field( (string) $settings['label'] );
		}

		if ( array_key_exists( 'placeholder', $settings ) ) {
			$field_args['placeholder'] = sanitize_text_field( (string) $settings['placeholder'] );
		}

		if ( ! empty( $settings['hideLabel'] ) ) {
			$field_args['label'] = '';
		}

		$field_args = $this->apply_root_attributes_to_field_args( $field_args );
		$field_args = $this->apply_notice_error_state_to_field_args( $field_data['key'], $field_args );

		if ( function_exists( 'woocommerce_form_field' ) ) {
			$this->render_form_field_with_selectwoo_shell( $field_data['key'], $field_args, $field_data['value'] );
		}
	}

	/**
	 * Get Account v2 auth field definitions.
	 *
	 * @since 2.4
	 *
	 * @param string $source Optional auth source.
	 * @return array
	 */
	private function get_account_auth_fields( $source = '' ) {
		$fields = [
			'accountLogin'         => [
				'username' => [
					'type'              => 'text',
					'label'             => esc_html__( 'Username or email address', 'woocommerce' ),
					'required'          => true,
					'id'                => 'username',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'username',
						'spellcheck'   => 'false',
					],
				],
				'password' => [
					'type'              => 'password',
					'label'             => esc_html__( 'Password', 'woocommerce' ),
					'required'          => true,
					'id'                => 'password',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'current-password',
					],
				],
			],
			'accountRegister'      => [
				'username' => [
					'type'              => 'text',
					'label'             => esc_html__( 'Username', 'woocommerce' ),
					'required'          => true,
					'id'                => 'reg_username',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'username',
					],
				],
				'email'    => [
					'type'              => 'email',
					'label'             => esc_html__( 'Email address', 'woocommerce' ),
					'required'          => true,
					'id'                => 'reg_email',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'email',
					],
				],
				'password' => [
					'type'              => 'password',
					'label'             => esc_html__( 'Password', 'woocommerce' ),
					'required'          => true,
					'id'                => 'reg_password',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'new-password',
					],
				],
			],
			'accountLostPassword'  => [
				'user_login' => [
					'type'              => 'text',
					'label'             => esc_html__( 'Username or email', 'woocommerce' ),
					'required'          => true,
					'id'                => 'user_login',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--first', 'form-row', 'form-row-first' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'username',
					],
				],
			],
			'accountResetPassword' => [
				'password_1' => [
					'type'              => 'password',
					'label'             => esc_html__( 'New password', 'woocommerce' ),
					'required'          => true,
					'id'                => 'password_1',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--first', 'form-row', 'form-row-first' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'new-password',
					],
				],
				'password_2' => [
					'type'              => 'password',
					'label'             => esc_html__( 'Re-enter new password', 'woocommerce' ),
					'required'          => true,
					'id'                => 'password_2',
					'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--last', 'form-row', 'form-row-last' ],
					'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
					'custom_attributes' => [
						'autocomplete' => 'new-password',
					],
				],
			],
		];

		if ( $source ) {
			return isset( $fields[ $source ] ) ? $fields[ $source ] : [];
		}

		return $fields;
	}

	/**
	 * Get a builder label for an account auth field source.
	 *
	 * @since 2.4
	 *
	 * @param string $source Field source.
	 * @return string
	 */
	private function get_account_auth_source_label( $source ) {
		$labels = [
			'accountLogin'         => esc_html__( 'Account login', 'bricks' ),
			'accountRegister'      => esc_html__( 'Account register', 'bricks' ),
			'accountLostPassword'  => esc_html__( 'Account lost password', 'bricks' ),
			'accountResetPassword' => esc_html__( 'Account reset password', 'bricks' ),
		];

		return isset( $labels[ $source ] ) ? $labels[ $source ] : esc_html__( 'Account', 'bricks' );
	}

	/**
	 * Check whether a conditional account auth field should be rendered.
	 *
	 * @since 2.4
	 *
	 * @param string $source Field source.
	 * @param string $field_key Field key.
	 * @return bool
	 */
	private function should_render_account_auth_field( $source, $field_key ) {
		if ( $source === 'accountRegister' && $field_key === 'username' ) {
			return get_option( 'woocommerce_registration_generate_username' ) === 'no';
		}

		if ( $source === 'accountRegister' && $field_key === 'password' ) {
			return get_option( 'woocommerce_registration_generate_password' ) === 'no';
		}

		return true;
	}

	/**
	 * Render an Account v2 auth field.
	 *
	 * @since 2.4
	 *
	 * @param string $source Field source.
	 * @param string $field_key Field key.
	 * @return void
	 */
	private function render_account_auth_field( $source, $field_key ) {
		$settings = $this->settings;
		$fields   = $this->get_account_auth_fields( $source );

		if ( empty( $fields[ $field_key ] ) || ! is_array( $fields[ $field_key ] ) ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				$this->render_element_placeholder( [ 'title' => esc_html__( 'Field not available for this account form.', 'bricks' ) ] );
			}

			return;
		}

		if ( ! $this->should_render_account_auth_field( $source, $field_key ) ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				$this->render_element_placeholder( [ 'title' => esc_html__( 'Field is disabled by WooCommerce account settings.', 'bricks' ) ] );
			}

			return;
		}

		$field_args = array_merge(
			[
				'type'              => 'text',
				'label'             => '',
				'placeholder'       => '',
				'required'          => false,
				'id'                => $field_key,
				'class'             => [ 'woocommerce-form-row', 'form-row' ],
				'label_class'       => [],
				'input_class'       => [ 'woocommerce-Input', 'input-text' ],
				'validate'          => [],
				'custom_attributes' => [],
				'return'            => false,
			],
			$this->sanitize_field_args( $fields[ $field_key ] )
		);

		if ( array_key_exists( 'label', $settings ) ) {
			$field_args['label'] = sanitize_text_field( (string) $settings['label'] );
		}

		if ( array_key_exists( 'placeholder', $settings ) ) {
			$field_args['placeholder'] = sanitize_text_field( (string) $settings['placeholder'] );
		}

		if ( ! empty( $settings['hideLabel'] ) ) {
			$field_args['label'] = '';
		}

		if ( $field_args['placeholder'] === '' ) {
			$field_args['placeholder'] = ' ';
		}

		$field_args = $this->apply_root_attributes_to_field_args( $field_args );
		$field_args = $this->apply_notice_error_state_to_field_args( $field_key, $field_args );

		if ( function_exists( 'woocommerce_form_field' ) ) {
			woocommerce_form_field( $field_key, $field_args, $this->get_account_auth_field_value( $field_key, $field_args ) );
		}
	}

	/**
	 * Get posted field value for an account auth field.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @param array  $field_args Field args.
	 * @return string
	 */
	private function get_account_auth_field_value( $field_key, $field_args ) {
		if ( ! empty( $field_args['type'] ) && $field_args['type'] === 'password' ) {
			return '';
		}

		if ( function_exists( 'wc_get_post_data_by_key' ) ) {
			return (string) wc_get_post_data_by_key( $field_key, '' );
		}

		if ( isset( $_POST[ $field_key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) );
		}

		return '';
	}

	/**
	 * Get Account v2 edit-account field definitions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_account_edit_account_fields() {
		return [
			'account_first_name'   => [
				'type'              => 'text',
				'label'             => esc_html__( 'First name', 'woocommerce' ),
				'required'          => true,
				'id'                => 'account_first_name',
				'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--first', 'form-row', 'form-row-first' ],
				'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
				'custom_attributes' => [
					'autocomplete' => 'given-name',
				],
				'user_property'     => 'first_name',
			],
			'account_last_name'    => [
				'type'              => 'text',
				'label'             => esc_html__( 'Last name', 'woocommerce' ),
				'required'          => true,
				'id'                => 'account_last_name',
				'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--last', 'form-row', 'form-row-last' ],
				'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
				'custom_attributes' => [
					'autocomplete' => 'family-name',
				],
				'user_property'     => 'last_name',
			],
			'account_display_name' => [
				'type'              => 'text',
				'label'             => esc_html__( 'Display name', 'woocommerce' ),
				'required'          => true,
				'id'                => 'account_display_name',
				'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
				'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--text', 'input-text' ],
				'custom_attributes' => [
					'aria-describedby' => 'account_display_name_description',
				],
				'description'       => esc_html__( 'This will be how your name will be displayed in the account section and in reviews', 'woocommerce' ),
				'user_property'     => 'display_name',
			],
			'account_email'        => [
				'type'              => 'email',
				'label'             => esc_html__( 'Email address', 'woocommerce' ),
				'required'          => true,
				'id'                => 'account_email',
				'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
				'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--email', 'input-text' ],
				'custom_attributes' => [
					'autocomplete' => 'email',
				],
				'user_property'     => 'user_email',
			],
			'password_current'     => [
				'type'              => 'password',
				'label'             => esc_html__( 'Current password (leave blank to leave unchanged)', 'woocommerce' ),
				'id'                => 'password_current',
				'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
				'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--password', 'input-text' ],
				'custom_attributes' => [
					'autocomplete' => 'current-password',
				],
			],
			'password_1'           => [
				'type'              => 'password',
				'label'             => esc_html__( 'New password (leave blank to leave unchanged)', 'woocommerce' ),
				'id'                => 'password_1',
				'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
				'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--password', 'input-text' ],
				'custom_attributes' => [
					'autocomplete' => 'new-password',
				],
			],
			'password_2'           => [
				'type'              => 'password',
				'label'             => esc_html__( 'Confirm new password', 'woocommerce' ),
				'id'                => 'password_2',
				'class'             => [ 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row', 'form-row-wide' ],
				'input_class'       => [ 'woocommerce-Input', 'woocommerce-Input--password', 'input-text' ],
				'custom_attributes' => [
					'autocomplete' => 'new-password',
				],
			],
		];
	}

	/**
	 * Render an Account v2 edit-account field.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @return void
	 */
	private function render_account_edit_account_field( $field_key ) {
		$settings = $this->settings;
		$fields   = $this->get_account_edit_account_fields();

		if ( empty( $fields[ $field_key ] ) || ! is_array( $fields[ $field_key ] ) ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				$this->render_element_placeholder( [ 'title' => esc_html__( 'Field not available for account details.', 'bricks' ) ] );
			}

			return;
		}

		$field_args = array_merge(
			[
				'type'              => 'text',
				'label'             => '',
				'placeholder'       => '',
				'required'          => false,
				'id'                => $field_key,
				'class'             => [ 'woocommerce-form-row', 'form-row' ],
				'label_class'       => [],
				'input_class'       => [ 'woocommerce-Input', 'input-text' ],
				'custom_attributes' => [],
				'description'       => '',
				'user_property'     => '',
			],
			$fields[ $field_key ]
		);

		if ( array_key_exists( 'label', $settings ) ) {
			$field_args['label'] = sanitize_text_field( (string) $settings['label'] );
		}

		if ( array_key_exists( 'placeholder', $settings ) ) {
			$field_args['placeholder'] = sanitize_text_field( (string) $settings['placeholder'] );
		}

		if ( ! empty( $settings['hideLabel'] ) ) {
			$field_args['label'] = '';
		}

		// Floating-label styles need a placeholder state, but Woo's native edit-account fields do not expose visible placeholders.
		if ( $field_args['placeholder'] === '' ) {
			$field_args['placeholder'] = ' ';
		}

		$field_args = $this->apply_root_attributes_to_field_args( $field_args );
		$field_args = $this->apply_notice_error_state_to_field_args( $field_key, $field_args );

		$value = $this->get_account_edit_account_field_value( $field_key, $field_args );

		$this->render_account_edit_account_field_markup( $field_key, $field_args, $value );
	}

	/**
	 * Get the current value for an Account v2 edit-account field.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @param array  $field_args Field args.
	 * @return string
	 */
	private function get_account_edit_account_field_value( $field_key, $field_args ) {
		if ( ! empty( $field_args['type'] ) && $field_args['type'] === 'password' ) {
			return '';
		}

		$value         = '';
		$user_property = ! empty( $field_args['user_property'] ) ? sanitize_key( $field_args['user_property'] ) : '';

		if ( $user_property ) {
			$user = wp_get_current_user();

			if ( $user && isset( $user->{$user_property} ) ) {
				$value = (string) $user->{$user_property};
			}
		}

		if ( function_exists( 'wc_get_post_data_by_key' ) ) {
			$value = wc_get_post_data_by_key( $field_key, $value );
		}

		return (string) $value;
	}

	/**
	 * Render Account v2 edit-account field markup.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @param array  $field_args Field args.
	 * @param string $value Field value.
	 * @return void
	 */
	private function render_account_edit_account_field_markup( $field_key, $field_args, $value ) {
		$field_id          = ! empty( $field_args['id'] ) ? sanitize_html_class( $field_args['id'] ) : sanitize_html_class( $field_key );
		$field_type        = ! empty( $field_args['type'] ) ? sanitize_key( $field_args['type'] ) : 'text';
		$field_classes     = array_values( array_filter( array_map( 'sanitize_html_class', (array) ( $field_args['class'] ?? [] ) ) ) );
		$label_classes     = array_values( array_filter( array_map( 'sanitize_html_class', (array) ( $field_args['label_class'] ?? [] ) ) ) );
		$input_classes     = array_values( array_filter( array_map( 'sanitize_html_class', (array) ( $field_args['input_class'] ?? [] ) ) ) );
		$custom_attributes = (array) ( $field_args['custom_attributes'] ?? [] );

		if ( ! empty( $field_args['required'] ) ) {
			$custom_attributes['aria-required'] = 'true';
		}

		if ( ! empty( $field_args['placeholder'] ) ) {
			$custom_attributes['placeholder'] = (string) $field_args['placeholder'];
		}

		$custom_attributes_html = [];

		foreach ( $custom_attributes as $attribute_key => $attribute_value ) {
			$attribute_key = sanitize_key( $attribute_key );

			if ( $attribute_key === '' ) {
				continue;
			}

			$custom_attributes_html[] = esc_attr( $attribute_key ) . '="' . esc_attr( (string) $attribute_value ) . '"';
		}

		$required_indicator = ! empty( $field_args['required'] ) ? '&nbsp;<span class="required" aria-hidden="true">*</span>' : '';

		echo '<p class="' . esc_attr( implode( ' ', $field_classes ) ) . '" id="' . esc_attr( $field_id . '_field' ) . '">';

		if ( ! empty( $field_args['label'] ) ) {
			echo '<label for="' . esc_attr( $field_id ) . '" class="' . esc_attr( implode( ' ', $label_classes ) ) . '">' . esc_html( $field_args['label'] ) . $required_indicator . '</label>';
		}

		echo '<span class="woocommerce-input-wrapper">';
		echo '<input type="' . esc_attr( $field_type ) . '" class="' . esc_attr( implode( ' ', $input_classes ) ) . '" name="' . esc_attr( $field_key ) . '" id="' . esc_attr( $field_id ) . '"';

		if ( $field_type !== 'password' ) {
			echo ' value="' . esc_attr( $value ) . '"';
		}

		if ( ! empty( $custom_attributes_html ) ) {
			echo ' ' . implode( ' ', $custom_attributes_html );
		}

		echo ' />';
		echo '</span>';

		if ( ! empty( $field_args['description'] ) ) {
			echo ' <span id="' . esc_attr( $field_id . '_description' ) . '"><em>' . esc_html( $field_args['description'] ) . '</em></span>';
		}

		echo '</p>';
	}
}
