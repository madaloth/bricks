<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woo_Element extends Element {
	public $category = 'woocommerce';

	/**
	 * Check if this Woo element is an internal v2 state placeholder.
	 *
	 * #86c8vumef; @since 2.4
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	protected function is_v2_state_placeholder_element() {
		return ! empty( $this->name ) && strpos( $this->name, '-v2-state-' ) !== false;
	}

	/**
	 * Check if this Woo element supports per-state header/footer visibility.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	protected function supports_v2_state_template_visibility() {
		return ! empty( $this->name ) && (
			strpos( $this->name, 'woocommerce-checkout-v2-state-' ) === 0 ||
			strpos( $this->name, 'woocommerce-account-v2-state-' ) === 0
		);
	}

	/**
	 * Register common control groups unless this element has no wrapper.
	 *
	 * Woo v2 state placeholders render children only, so inherited wrapper style
	 * groups would create controls for markup that does not exist.
	 * #86c8vumef; @since 2.4
	 *
	 * @since 2.4
	 */
	public function set_common_control_groups() {
		if ( $this->is_v2_state_placeholder_element() ) {
			return;
		}

		parent::set_common_control_groups();
	}

	/**
	 * Register inherited controls before element-specific Woo controls.
	 *
	 * Skip inherited wrapper controls for v2 state placeholders while preserving
	 * state-owned Content controls added by each state class.
	 * #86c8vumef; @since 2.4
	 *
	 * @since 2.4
	 */
	public function set_controls_before() {
		if ( $this->is_v2_state_placeholder_element() ) {
			if ( $this->supports_v2_state_template_visibility() ) {
				$visibility_options = [
					'inherit' => esc_html__( 'Inherit', 'bricks' ),
					'enable'  => esc_html__( 'Enable', 'bricks' ),
					'disable' => esc_html__( 'Disable', 'bricks' ),
				];

				$this->controls['visibilityInfo'] = [
					'type'    => 'info',
					'content' => esc_html__( 'Control the visibility of the header and footer for this state, template, or endpoint. "Inherit" uses the page setting.', 'bricks' ),
				];

				$this->controls['headerVisibility'] = [
					'label'       => esc_html__( 'Header', 'bricks' ),
					'type'        => 'select',
					'options'     => $visibility_options,
					'inline'      => true,
					'placeholder' => esc_html__( 'Inherit', 'bricks' ),
				];

				$this->controls['footerVisibility'] = [
					'label'       => esc_html__( 'Footer', 'bricks' ),
					'type'        => 'select',
					'options'     => $visibility_options,
					'inline'      => true,
					'placeholder' => esc_html__( 'Inherit', 'bricks' ),
				];
			}

			return;
		}

		parent::set_controls_before();
	}

	/**
	 * Get element description.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public function get_description() {
		if ( ! $this->is_v2_state_placeholder_element() ) {
			return parent::get_description();
		}

		return esc_html__(
			'Placeholder element for the content of this state, template, or endpoint. No wrapper renders on the frontend.',
			'bricks'
		);
	}

	/**
	 * Get the shared predefined-elements generation description.
	 *
	 * State generators append new structures by design so existing user work is
	 * never overwritten by a convenience action. #86c8vumef; @since 2.4
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_v2_generate_predefined_elements_description() {
		return esc_html__(
			'Appended to the current structure, never overwrites.',
			'bricks'
		);
	}

	/**
	 * Register inherited controls after element-specific Woo controls.
	 *
	 * Woo v2 state placeholders should not receive wrapper animation, custom tag,
	 * or similar inherited controls because no wrapper node is rendered.
	 * #86c8vumef; @since 2.4
	 *
	 * @since 2.4
	 */
	public function set_controls_after() {
		if ( $this->is_v2_state_placeholder_element() ) {
			return;
		}

		parent::set_controls_after();
	}

	/**
	 * Generate standard controls for:
	 * margin, padding, background-color, border, box-shadow, typography
	 *
	 * @param string $field_key - The field key to use for the control.
	 * @param string $selector - The selector to apply the control to.
	 * @param string $types (optional) - Array of control types to generate controls for.
	 *
	 * @return array
	 */
	protected function generate_standard_controls( $field_key, $selector, $types = [] ) {
		if ( ! $field_key || ! $selector ) {
			return [];
		}

		$controls = [
			'margin'           => [
				'suffix' => 'Margin',
				'label'  => esc_html__( 'Margin', 'bricks' ),
				'type'   => 'spacing',
				'css'    => [
					[
						'property' => 'margin',
						'selector' => $selector,
					],
				],
			],

			'padding'          => [
				'suffix' => 'Padding',
				'label'  => esc_html__( 'Padding', 'bricks' ),
				'type'   => 'spacing',
				'css'    => [
					[
						'property' => 'padding',
						'selector' => $selector,
					],
				],
			],

			'background-color' => [
				'suffix' => 'BackgroundColor',
				'label'  => esc_html__( 'Background color', 'bricks' ),
				'type'   => 'color',
				'css'    => [
					[
						'property' => 'background-color',
						'selector' => $selector,
					],
				],
			],

			'border'           => [
				'suffix' => 'Border',
				'label'  => esc_html__( 'Border', 'bricks' ),
				'type'   => 'border',
				'css'    => [
					[
						'property' => 'border',
						'selector' => $selector,
					],
				],
			],

			'box-shadow'       => [
				'suffix' => 'BoxShadow',
				'label'  => esc_html__( 'Box shadow', 'bricks' ),
				'type'   => 'box-shadow',
				'css'    => [
					[
						'property' => 'box-shadow',
						'selector' => $selector,
					],
				],
			],

			'typography'       => [
				'suffix' => 'Typography',
				'label'  => esc_html__( 'Typography', 'bricks' ),
				'type'   => 'typography',
				'css'    => [
					[
						'property' => 'font',
						'selector' => $selector,
					],
				],
			],
		];

		// Get controls for specified types
		if ( ! empty( $types ) ) {
			$controls = array_intersect_key( $controls, array_flip( $types ) );
		}

		// Build final controls
		$final_controls = [];

		foreach ( $controls as $key => $control ) {
			$final_controls[ $field_key . $control['suffix'] ] = $control;
		}

		return $final_controls;
	}

	/**
	 * Insert group key to controls
	 *
	 * @param array  $controls
	 * @param string $group
	 *
	 * @return array
	 */
	protected function controls_grouping( $controls, $group ) {
		if ( empty( $group ) || empty( $controls ) || ! is_array( $controls ) ) {
			return $controls;
		}

		foreach ( $controls as $key => $control ) {
			$controls[ $key ]['group'] = $group;
		}

		return $controls;
	}

	/**
	 * Register common WooCommerce form field style control groups.
	 *
	 * @since 2.4
	 *
	 * @param string $group_prefix Control group key prefix.
	 */
	protected function set_woo_checkout_form_field_common_style_control_groups( $group_prefix = 'commonFieldStyles' ) {
		if ( ! $group_prefix ) {
			return;
		}

		$groups = [
			'Wrapper'            => esc_html__( 'Wrapper', 'bricks' ),
			'Label'              => esc_html__( 'Label', 'bricks' ),
			'InputWrapper'       => esc_html__( 'Input wrapper', 'bricks' ),
			'Input'              => esc_html__( 'Input', 'bricks' ) . ' / ' . esc_html__( 'Textarea', 'bricks' ) . ' / ' . esc_html__( 'Select', 'bricks' ),
			'Select2'            => 'Select2: ' . esc_html__( 'Country', 'bricks' ) . ' / ' . esc_html_x( 'State', 'address region', 'bricks' ),
			'Select2SearchField' => 'Select2: ' . esc_html__( 'Search', 'bricks' ),
			'Select2Results'     => 'Select2: ' . esc_html__( 'Dropdown', 'bricks' ) . ' & ' . esc_html__( 'Options', 'bricks' ),
			'InvalidState'       => esc_html__( 'Invalid state', 'bricks' ),
			'InlineErrorMessage' => esc_html__( 'Inline error message', 'bricks' ),
		];

		foreach ( $groups as $group_key => $title ) {
			$this->control_groups[ $group_prefix . $group_key ] = [
				'title' => $title,
			];
		}
	}

	/**
	 * Woo Phase 3
	 */
	protected function get_woo_form_fields_controls( $selector = '' ) {
		$controls = [];

		$controls['fieldsAlignItems'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Align items', 'bricks' ),
			'type'    => 'align-items',
			'inline'  => true,
			'css'     => [
				[
					'property' => 'align-items',
					'selector' => $selector,
				],
			],
			'exclude' => [ 'stretch' ],
		];

		$controls['fieldsWidth'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => '.password-input, .woocommerce-Input',
				],
			],
		];

		$controls['fieldsGap'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => $selector,
				],
			],
		];

		$controls['hideLabels'] = [
			'tab'   => 'content',
			'group' => 'fields',
			'label' => esc_html__( 'Hide labels', 'bricks' ),
			'type'  => 'checkbox',
		];

		$controls['hidePlaceholders'] = [
			'tab'   => 'content',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Hide placeholders', 'bricks' ),
		];

		$controls['labelTypography'] = [
			'tab'      => 'content',
			'group'    => 'fields',
			'label'    => esc_html__( 'Label typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'property' => 'font',
					'selector' => 'label[for]', // Skip rememberme label
				],
			],
			'required' => [ 'hideLabels', '=', false ],
		];

		$controls['placeholderTypography'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Placeholder typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'property' => 'font',
					'selector' => '::placeholder',
				],
				[
					'property' => 'font',
					'selector' => 'select',
				],
			],
			'required' => [ 'hidePlaceholders', '=', false ],
		];

		// FIELD

		$controls['fieldsSep'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Field', 'bricks' ),
		];

		/**
		 * Generate standard controls for .woocommerce-Input
		 * (typography, margin, padding, background-color, border, box-shadow)
		 *
		 * 'input' selector required for edit address form, which has no .woocommerce-Input.
		 */
		$field_key         = 'fieldsInput';
		$selector          = 'input, .woocommerce-Input, .select2-selection.select2-selection--single';
		$standard_controls = $this->generate_standard_controls( $field_key, $selector );

		$controls = array_merge( $controls, $standard_controls );

		return $controls;
	}

	/**
	 * Woo Phase 3
	 */
	protected function get_woo_form_submit_controls() {
		$field_key         = 'submitButton';
		$selector          = 'button[type=submit]';
		$standard_controls = $this->generate_standard_controls( $field_key, $selector );

		$controls['submitButtonWidth'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => $selector,
				],
			],
		];

		// Merge standard controls
		$controls = array_merge( $controls, $standard_controls );

		return $controls;
	}

	protected function get_woo_form_fieldset_controls() {
		$field_key = 'fieldset';
		$selector  = 'fieldset';

		$standard_controls = $this->generate_standard_controls( $field_key, $selector );

		$controls['fieldsetGap'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => $selector,
				],
			],
		];

		// merge standard controls
		$controls = array_merge( $controls, $standard_controls );

		return $controls;
	}

	/**
	 * Common style controls for Woo checkout form fields.
	 *
	 * Mirrors key controls from the `woocommerce-form-field` element and can be
	 * reused in state elements via scoped selectors.
	 *
	 * @since 2.4
	 *
	 * @param array $args Control generation arguments.
	 *   @type string $field_key Control key prefix.
	 *   @type string $group Control group key.
	 *   @type string $scope CSS scope selector.
	 *   @type string|null $wrapper_scope Wrapper selector scope. Default uses `scope`. Pass empty string to target root.
	 *   @type bool $absolute_selectors Convert all selectors to absolute selectors.
	 *   @type string $label_required_control Required-control key for label controls.
	 *   @type string $select2_required_control Required-control key for Select2 controls.
	 *   @type array $select2_required_values Required-control values for Select2 controls.
	 *   @type string $select2_dropdown_scope Scope selector for Select2 dropdown portal nodes.
	 *   @type bool $include_select2_dropdown_portal Whether to include Select2 dropdown/search/results controls.
	 *   @type bool $group_by_separator Group controls under a dedicated control group for each separator.
	 * }
	 *
	 * @return array
	 */
	protected function get_woo_checkout_form_field_common_style_controls( $args = [] ) {
		$controls = [];

		$args = wp_parse_args(
			is_array( $args ) ? $args : [],
			[
				'field_key'                       => 'commonField',
				'group'                           => 'commonFieldStyles',
				'scope'                           => 'form.woocommerce-checkout .brxe-woocommerce-form-field',
				'wrapper_scope'                   => null,
				'absolute_selectors'              => false,
				'label_required_control'          => '',
				'select2_required_control'        => '',
				'select2_required_values'         => [ 'billing_country', 'shipping_country', 'billing_state', 'shipping_state' ],
				'select2_dropdown_scope'          => '.select2-container',
				'include_select2_dropdown_portal' => true,
				'group_by_separator'              => false,
			]
		);

		$field_key                       = (string) $args['field_key'];
		$group                           = (string) $args['group'];
		$scope                           = (string) $args['scope'];
		$wrapper_scope                   = $args['wrapper_scope'];
		$absolute_selectors              = ! empty( $args['absolute_selectors'] );
		$label_required_key              = (string) $args['label_required_control'];
		$select2_required_key            = (string) $args['select2_required_control'];
		$select2_required_values         = is_array( $args['select2_required_values'] ) ? $args['select2_required_values'] : [ 'billing_country', 'shipping_country', 'billing_state', 'shipping_state' ];
		$select2_dropdown_scope          = trim( (string) $args['select2_dropdown_scope'] );
		$include_select2_dropdown_portal = ! isset( $args['include_select2_dropdown_portal'] ) || ! empty( $args['include_select2_dropdown_portal'] );
		$group_by_separator              = ! empty( $args['group_by_separator'] ) && $group !== '';
		$current_group                   = $group;

		// Wrapper defaults to the same scope as the field controls.
		if ( $wrapper_scope === null ) {
			$wrapper_scope = $scope;
		}

		$key = function( $suffix ) use ( $field_key ) {
			return $field_key ? $field_key . $suffix : $suffix;
		};

		$compose_selector = function( $root_scope, $child ) {
			$child = is_string( $child ) ? trim( $child ) : '';

			if ( $root_scope === '' ) {
				return $child;
			}

			if ( $child === '' ) {
				return $root_scope;
			}

			$parts = array_map( 'trim', explode( ',', $child ) );
			$parts = array_filter(
				$parts,
				function( $part ) {
					return $part !== '';
				}
			);

			$scoped_parts = array_map(
				function( $part ) use ( $root_scope ) {
					if ( strpos( $part, '&' ) === 0 ) {
						return str_replace( '&', $root_scope, $part );
					}

					return trim( $root_scope . ' ' . $part );
				},
				$parts
			);

			return implode( ', ', $scoped_parts );
		};

		// Use absolute selectors if specified in options, otherwise prefix with scope if provided.
		$selector = function( $child ) use ( $scope, $compose_selector ) {
			return $compose_selector( $scope, $child );
		};

		$select2_dropdown_selector = function( $child ) use ( $select2_dropdown_scope, $compose_selector ) {
			return $compose_selector( $select2_dropdown_scope, $child );
		};

		$wrapper_selector = function( $child ) use ( $wrapper_scope, $compose_selector ) {
			return $compose_selector( $wrapper_scope, $child );
		};

		// Define group for controls if specified.
		$maybe_group = function( $control ) use ( &$current_group ) {
			if ( $current_group !== '' ) {
				$control['group'] = $current_group;
			}

			return $control;
		};

		$set_group = function( $suffix ) use ( $group, $group_by_separator, &$current_group ) {
			if ( ! $group_by_separator ) {
				return;
			}

			$current_group = $group . $suffix;
		};

		$add_separator = function( $suffix, $control ) use ( $group_by_separator, &$controls, $key, $maybe_group ) {
			if ( $group_by_separator ) {
				return;
			}

			// Keep variable function calls separate to avoid a false malware detection in Wordfence. (#86caut4fq)
			$control_id              = $key( $suffix );
			$controls[ $control_id ] = $maybe_group( $control );
		};

		// WooCommerce form field element has required controls that need to be checked for conditional logic, so we pass the keys in
		$label_required   = $label_required_key ? [ $label_required_key, '=', false ] : false;
		$select2_required = $select2_required_key ? [ $select2_required_key, '=', $select2_required_values ] : false;

		$set_group( 'Wrapper' );
		$add_separator(
			'WrapperSeparator',
			[
				'type'  => 'separator',
				'label' => esc_html__( 'Wrapper', 'bricks' ) . ' (p)',
			]
		);

		$controls[ $key( 'WrapperMargin' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Margin', 'bricks' ),
				'type'  => 'spacing',
				'css'   => [
					[
						'property' => 'margin',
						'selector' => $wrapper_selector( '' ),
					],
				],
			]
		);

		$controls[ $key( 'WrapperPadding' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Padding', 'bricks' ),
				'type'  => 'spacing',
				'css'   => [
					[
						'property' => 'padding',
						'selector' => $wrapper_selector( '' ),
					],
				],
			]
		);

		$controls[ $key( 'WrapperBackgroundColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Background color', 'bricks' ),
				'type'  => 'color',
				'css'   => [
					[
						'property' => 'background-color',
						'selector' => $wrapper_selector( '' ),
					],
				],
			]
		);

		$controls[ $key( 'WrapperBorder' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Border', 'bricks' ),
				'type'  => 'border',
				'css'   => [
					[
						'property' => 'border',
						'selector' => $wrapper_selector( '' ),
					],
				],
			]
		);

		$set_group( 'Label' );
		$add_separator(
			'LabelSeparator',
			[
				'type'  => 'separator',
				'label' => esc_html__( 'Label', 'bricks' ),
			]
		);

		$controls[ $key( 'LabelTypography' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Label typography', 'bricks' ),
				'type'  => 'typography',
				'css'   => [
					[
						'property' => 'font',
						'selector' => $selector( 'label, &.brx-woo-withdrawal-options > legend' ),
					],
				],
			]
		);

		$controls[ $key( 'LabelMargin' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Label margin', 'bricks' ),
				'type'  => 'spacing',
				'css'   => [
					[
						'property' => 'margin',
						'selector' => $selector( 'label, &.brx-woo-withdrawal-options > legend' ),
					],
				],
			]
		);

		$controls[ $key( 'RequiredMarkerTypography' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Typography', 'bricks' ) . ': ' . esc_html__( 'Required marker', 'bricks' ),
				'type'  => 'typography',
				'css'   => [
					[
						'property' => 'font',
						'selector' => $selector( 'label .required, &.brx-woo-withdrawal-options > legend .required' ),
					],
				],
			]
		);

		$controls[ $key( 'OptionalMarkerTypography' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Typography', 'bricks' ) . ': ' . esc_html__( 'Optional marker', 'bricks' ),
				'type'  => 'typography',
				'css'   => [
					[
						'property' => 'font',
						'selector' => $selector( 'label .optional' ),
					],
				],
			]
		);

		$set_group( 'InputWrapper' );
		$add_separator(
			'InputWrapperSeparator',
			[
				'type'  => 'separator',
				'label' => esc_html__( 'Input wrapper', 'bricks' ) . ' (span)',
			]
		);

		$controls[ $key( 'InputWrapperMargin' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Margin', 'bricks' ),
				'type'  => 'spacing',
				'css'   => [
					[
						'property' => 'margin',
						'selector' => $selector( '.woocommerce-input-wrapper' ),
					],
				],
			]
		);

		$controls[ $key( 'InputWrapperPadding' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Padding', 'bricks' ),
				'type'  => 'spacing',
				'css'   => [
					[
						'property' => 'padding',
						'selector' => $selector( '.woocommerce-input-wrapper' ),
					],
				],
			]
		);

		$set_group( 'Input' );
		$add_separator(
			'InputSeparator',
			[
				'type'  => 'separator',
				'label' => esc_html__( 'Input', 'bricks' ) . ' / ' . esc_html__( 'Textarea', 'bricks' ) . ' / ' . esc_html__( 'Select', 'bricks' ),
			]
		);

		// Separate definitions avoid invalid scoped selector lists after Bricks prefixes each individual field rule. (#86caxkuka; @since 2.4)
		$css_definitions = function( $property, $child_selectors ) use ( $selector ) {
			$definitions = [];

			foreach ( $child_selectors as $child_selector ) {
				$definitions[] = [
					'property' => $property,
					'selector' => $selector( $child_selector ),
				];
			}

			return $definitions;
		};

		$input_selectors = [
			'input:not([type=submit])',
			'textarea',
			'select',
		];

		$controls[ $key( 'InputPadding' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Input padding', 'bricks' ),
				'type'  => 'spacing',
				'css'   => $css_definitions( 'padding', $input_selectors ),
			]
		);

		$controls[ $key( 'InputBackgroundColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Input background color', 'bricks' ),
				'type'  => 'color',
				'css'   => $css_definitions( 'background-color', $input_selectors ),
			]
		);

		$controls[ $key( 'InputBorder' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Input border', 'bricks' ),
				'type'  => 'border',
				'css'   => $css_definitions( 'border', $input_selectors ),
			]
		);

		$controls[ $key( 'InputTypography' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Input typography', 'bricks' ),
				'type'  => 'typography',
				'css'   => $css_definitions( 'font', $input_selectors ),
			]
		);

		$controls[ $key( 'InputPlaceholderColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Placeholder color', 'bricks' ),
				'type'  => 'color',
				'css'   => $css_definitions( 'color', [ 'input::placeholder', 'textarea::placeholder' ] ),
			]
		);

		// Style visible native fields before SelectWoo initializes, then target the enhanced control. (#86caxkuka; @since 2.4)
		$select2_selection_selectors = [
			'select.country_select:not(.select2-hidden-accessible)',
			'select.state_select:not(.select2-hidden-accessible)',
			'.select2-selection.select2-selection--single',
		];
		$select2_text_selectors      = [
			'select.country_select:not(.select2-hidden-accessible)',
			'select.state_select:not(.select2-hidden-accessible)',
			'.select2-selection__rendered',
		];

		$set_group( 'Select2' );
		$add_separator(
			'Select2Separator',
			[
				'type'  => 'separator',
				'label' => 'Select2: ' . esc_html__( 'Country', 'bricks' ) . ' / ' . esc_html_x( 'State', 'address region', 'bricks' ),
			]
		);

		$controls[ $key( 'Select2Padding' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Padding', 'bricks' ),
				'type'  => 'spacing',
				'css'   => $css_definitions( 'padding', $select2_selection_selectors ),
			]
		);

		$controls[ $key( 'Select2BackgroundColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Background color', 'bricks' ),
				'type'  => 'color',
				'css'   => $css_definitions( 'background-color', $select2_selection_selectors ),
			]
		);

		$controls[ $key( 'Select2Border' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Border', 'bricks' ),
				'type'  => 'border',
				'css'   => $css_definitions( 'border', $select2_selection_selectors ),
			]
		);

		$controls[ $key( 'Select2Typography' ) ] = $maybe_group(
			[
				'label' => 'Select2: ' . esc_html__( 'Text typography', 'bricks' ),
				'type'  => 'typography',
				'css'   => $css_definitions( 'font', $select2_text_selectors ),
			]
		);

		$controls[ $key( 'Select2ArrowColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Arrow color', 'bricks' ),
				'type'  => 'color',
				'css'   => [
					[
						'property' => 'border-top-color',
						'selector' => $selector( '.select2-selection__arrow b' ),
					],
					[
						'property' => 'border-bottom-color',
						'selector' => $selector( '.select2-container--open .select2-selection__arrow b' ),
					],
				],
			]
		);

		if ( $include_select2_dropdown_portal ) {
			$set_group( 'Select2SearchField' );
			$add_separator(
				'Select2SearchFieldSeparator',
				[
					'type'  => 'separator',
					'label' => esc_html__( 'Search field', 'bricks' ),
				]
			);

			$controls[ $key( 'Select2SearchFieldPadding' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Padding', 'bricks' ),
					'type'  => 'spacing',
					'css'   => [
						[
							'property' => 'padding',
							'selector' => $select2_dropdown_selector( '.select2-search--dropdown .select2-search__field' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2SearchFieldTypography' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Field typography', 'bricks' ),
					'type'  => 'typography',
					'css'   => [
						[
							'property' => 'font',
							'selector' => $select2_dropdown_selector( '.select2-search--dropdown .select2-search__field' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2SearchFieldBackgroundColor' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Background color', 'bricks' ),
					'type'  => 'color',
					'css'   => [
						[
							'property' => 'background-color',
							'selector' => $select2_dropdown_selector( '.select2-search--dropdown .select2-search__field' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2SearchFieldBorder' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Border', 'bricks' ),
					'type'  => 'border',
					'css'   => [
						[
							'property' => 'border',
							'selector' => $select2_dropdown_selector( '.select2-search--dropdown .select2-search__field' ),
						],
					],
				]
			);

			$set_group( 'Select2Results' );
			$add_separator(
				'Select2ResultsSeparator',
				[
					'type'  => 'separator',
					'label' => 'Select2: ' . esc_html__( 'Dropdown', 'bricks' ) . ' & ' . esc_html__( 'Options', 'bricks' ),
				]
			);

			$controls[ $key( 'Select2DropdownBackgroundColor' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Dropdown', 'bricks' ) . ': ' . esc_html__( 'Background color', 'bricks' ),
					'type'  => 'color',
					'css'   => [
						[
							'property' => 'background-color',
							'selector' => $select2_dropdown_selector( '.select2-dropdown' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2DropdownBorder' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Dropdown', 'bricks' ) . ': ' . esc_html__( 'Border', 'bricks' ),
					'type'  => 'border',
					'css'   => [
						[
							'property' => 'border',
							'selector' => $select2_dropdown_selector( '.select2-dropdown' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2DropdownTypography' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Dropdown', 'bricks' ) . ': ' . esc_html__( 'Typography', 'bricks' ),
					'type'  => 'typography',
					'css'   => [
						[
							'property' => 'font',
							'selector' => $select2_dropdown_selector( '.select2-dropdown' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2OptionPadding' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Option', 'bricks' ) . ': ' . esc_html__( 'Padding', 'bricks' ),
					'type'  => 'spacing',
					'css'   => [
						[
							'property' => 'padding',
							'selector' => $select2_dropdown_selector( '.select2-results__option' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2OptionSelectedColor' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Selected option color', 'bricks' ),
					'type'  => 'color',
					'css'   => [
						[
							'property' => 'color',
							'selector' => $select2_dropdown_selector( '.select2-results__option[data-selected="true"]' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2OptionSelectedBackgroundColor' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Selected option background color', 'bricks' ),
					'type'  => 'color',
					'css'   => [
						[
							'property' => 'background-color',
							'selector' => $select2_dropdown_selector( '.select2-results__option[data-selected="true"]' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2OptionHighlightColor' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Highlight option color', 'bricks' ),
					'type'  => 'color',
					'css'   => [
						[
							'property' => 'color',
							'selector' => $select2_dropdown_selector( '.select2-results__option[aria-selected="true"] ' ),
						],
						[
							'property' => 'color',
							'selector' => $select2_dropdown_selector( '.select2-results__option[aria-selected="true"][data-selected="true"]' ),
						],
					],
				]
			);

			$controls[ $key( 'Select2OptionHighlightBackgroundColor' ) ] = $maybe_group(
				[
					'label' => esc_html__( 'Highlight option background color', 'bricks' ),
					'type'  => 'color',
					'css'   => [
						[
							'property' => 'background-color',
							'selector' => $select2_dropdown_selector( '.select2-results__option[aria-selected="true"]' ),
						],
						[
							'property' => 'background-color',
							'selector' => $select2_dropdown_selector( '.select2-results__option[aria-selected="true"][data-selected="true"]' ),
						],
					],
				]
			);
		}

		$set_group( 'InvalidState' );
		$add_separator(
			'InvalidStateSeparator',
			[
				'type'  => 'separator',
				'label' => esc_html__( 'Invalid state', 'bricks' ),
			]
		);

		$invalid_label_selectors = [
			'&.woocommerce-invalid.validate-required label',
			'&.brx-woo-field-error label',
		];
		$invalid_text_selectors  = [
			'&.woocommerce-invalid.validate-required input:not([type=submit])',
			'&.woocommerce-invalid.validate-required textarea',
			'&.woocommerce-invalid.validate-required select',
			'&.woocommerce-invalid.validate-required .select2-selection__rendered',
			'&.brx-woo-field-error input:not([type=submit])',
			'&.brx-woo-field-error textarea',
			'&.brx-woo-field-error select',
			'&.brx-woo-field-error .select2-selection__rendered',
		];
		$invalid_input_selectors = [
			'&.woocommerce-invalid.validate-required input:not([type=submit])',
			'&.woocommerce-invalid.validate-required textarea',
			'&.woocommerce-invalid.validate-required select',
			'&.woocommerce-invalid.validate-required .select2-selection',
			'&.brx-woo-field-error input:not([type=submit])',
			'&.brx-woo-field-error textarea',
			'&.brx-woo-field-error select',
			'&.brx-woo-field-error .select2-selection',
		];

		// brx-woo-field-error covers Woo notices for non-required custom validation errors that do not include validate-required.
		$controls[ $key( 'InvalidLabelColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Label typography', 'bricks' ),
				'type'  => 'typography',
				'css'   => $css_definitions( 'font', $invalid_label_selectors ),
			]
		);

		$controls[ $key( 'InvalidInputTypography' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Input typography', 'bricks' ),
				'type'  => 'typography',
				'css'   => $css_definitions( 'font', $invalid_text_selectors ),
			]
		);

		$controls[ $key( 'InvalidInputBackgroundColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Input background color', 'bricks' ),
				'type'  => 'color',
				'css'   => $css_definitions( 'background-color', $invalid_input_selectors ),
			]
		);

		$controls[ $key( 'InvalidInputBorder' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Input border', 'bricks' ),
				'type'  => 'border',
				'css'   => $css_definitions( 'border', $invalid_input_selectors ),
			]
		);

		$set_group( 'InlineErrorMessage' );
		$add_separator(
			'InlineErrorMessageSeparator',
			[
				'type'  => 'separator',
				'label' => esc_html__( 'Inline error message', 'bricks' ),
			]
		);

		$controls[ $key( 'InlineErrorMessageTypography' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Typography', 'bricks' ),
				'type'  => 'typography',
				'css'   => [
					[
						'property' => 'font',
						'selector' => $selector( '.checkout-inline-error-message' ),
					],
				],
			]
		);

		$controls[ $key( 'InlineErrorMessageBackgroundColor' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Background color', 'bricks' ),
				'type'  => 'color',
				'css'   => [
					[
						'property' => 'background-color',
						'selector' => $selector( '.checkout-inline-error-message' ),
					],
				],
			]
		);

		$controls[ $key( 'InlineErrorMessageBorder' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Border', 'bricks' ),
				'type'  => 'border',
				'css'   => [
					[
						'property' => 'border',
						'selector' => $selector( '.checkout-inline-error-message' ),
					],
				],
			]
		);

		$controls[ $key( 'InlineErrorMessagePadding' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Padding', 'bricks' ),
				'type'  => 'spacing',
				'css'   => [
					[
						'property' => 'padding',
						'selector' => $selector( '.checkout-inline-error-message' ),
					],
				],
			]
		);

		$controls[ $key( 'InlineErrorMessageMargin' ) ] = $maybe_group(
			[
				'label' => esc_html__( 'Margin', 'bricks' ),
				'type'  => 'spacing',
				'css'   => [
					[
						'property' => 'margin',
						'selector' => $selector( '.checkout-inline-error-message' ),
					],
				],
			]
		);

		if ( $label_required ) {
			foreach ( [ 'LabelSeparator', 'LabelTypography', 'LabelMargin', 'MarkerSeparator', 'RequiredMarkerTypography', 'OptionalMarkerTypography' ] as $suffix ) {
				$controls[ $key( $suffix ) ]['required'] = $label_required;
			}
		}

		if ( $select2_required ) {
			$select2_prefix = $key( 'Select2' );

			foreach ( $controls as $control_id => $control ) {
				if ( strpos( $control_id, $select2_prefix ) !== 0 ) {
					continue;
				}

				$controls[ $control_id ]['required'] = $select2_required;
			}
		}

		if ( $absolute_selectors ) {
			return $this->controls_use_absolute_css_selectors( $controls );
		}

		return $controls;
	}

	/**
	 * Convert control CSS `selector` definitions into absolute selectors.
	 *
	 * Prevents Bricks from prefixing selectors with the current element root
	 * (required for state elements without a frontend wrapper node).
	 *
	 * @since 2.4
	 *
	 * @param array $controls Controls map.
	 *
	 * @return array
	 */
	protected function controls_use_absolute_css_selectors( $controls ) {
		if ( ! is_array( $controls ) ) {
			return [];
		}

		foreach ( $controls as $control_key => $control ) {
			if ( empty( $control['css'] ) || ! is_array( $control['css'] ) ) {
				continue;
			}

			foreach ( $control['css'] as $index => $css_definition ) {
				if ( ! is_array( $css_definition ) || empty( $css_definition['selector'] ) || ! is_string( $css_definition['selector'] ) ) {
					continue;
				}

					$controls[ $control_key ]['css'][ $index ]['absoluteSelector'] = $css_definition['selector'];
					unset( $controls[ $control_key ]['css'][ $index ]['selector'] );
			}
		}

		return $controls;
	}

	/**
	 * Get order
	 *
	 * Get order from 'previewOrderId' setting
	 *
	 * Default: Last order
	 *
	 * @return WC_Order|false
	 */
	protected function get_order( $template = 'view-order' ) {
		$order = false;

		if ( bricks_is_builder() || bricks_is_builder_call() || Helpers::is_bricks_template( get_the_ID() ) ) {
			$settings = $this->settings;

			$preview_order_id       = ! empty( $settings['previewOrderId'] ) ? absint( $settings['previewOrderId'] ) : false;
			$has_v2_preview_context = false;

			// Use Checkout v2 state-level preview context for order-related state descendants.
			if ( in_array( $template, [ 'thank-you', 'form-pay' ], true ) && ! $preview_order_id ) {
				$preview_state  = $template === 'form-pay' ? 'pay' : 'thankyou';
				$preview_states = in_array( $preview_state, [ 'thankyou', 'pay', 'receipt' ], true ) ? [ 'thankyou', 'pay', 'receipt' ] : [ $preview_state ];

				foreach ( $preview_states as $state ) {
					if ( Woocommerce::has_checkout_v2_state_preview_context( $state ) ) {
						$has_v2_preview_context = true;
						break;
					}
				}

				$preview_order = Woocommerce::get_checkout_v2_state_preview_order( $preview_state );

				if ( is_a( $preview_order, 'WC_Order' ) ) {
					$order = $preview_order;
				}
			}

			// Use Account v2 view-order state preview context for order-related state descendants.
			if ( $template === 'view-order' && ! $preview_order_id ) {
				$has_v2_preview_context = Woocommerce::has_account_v2_state_preview_context( 'view-order' );
				$preview_order          = Woocommerce::get_account_v2_state_preview_order( 'view-order' );

				if ( is_a( $preview_order, 'WC_Order' ) ) {
					$order = $preview_order;
				}
			}

			if ( $has_v2_preview_context && ! $order ) {
				return false;
			}

			// Preview: Get order from 'previewOrderId'
			if ( $preview_order_id && ! $order ) {
				$order = wc_get_order( $preview_order_id );
			}

			// No order found or no preview order ID, get the last order from orders
			if ( ! $order ) {
				$orders = wc_get_orders(
					[
						'limit' => 1,
					]
				);
				$order  = $orders ? $orders[0] : false;
			}

		} else {
			switch ( $template ) {
				case 'view-order':
					global $wp;
					$order_id = isset( $wp->query_vars['view-order'] ) ? absint( $wp->query_vars['view-order'] ) : 0;
					$order    = wc_get_order( $order_id );
					break;

				case 'thank-you':
					$order_id = get_query_var( 'order-received', false );

					// Get the order
					$order_id  = apply_filters( 'woocommerce_thankyou_order_id', absint( $order_id ) );
					$order_key = apply_filters( 'woocommerce_thankyou_order_key', empty( $_GET['key'] ) ? '' : wc_clean( wp_unslash( $_GET['key'] ) ) );

					if ( $order_id > 0 ) {
						$order = wc_get_order( $order_id );
						if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
							$order = false;
						}
					}
					break;

				case 'form-pay':
					$order_id  = get_query_var( 'order-pay', false );
					$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';

					if ( $order_id > 0 ) {
						$order = wc_get_order( $order_id );
						if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
							$order = false;
						}
					}
					break;

				default:
					$order = false;
					break;
			}
		}

		return $order;
	}
}
