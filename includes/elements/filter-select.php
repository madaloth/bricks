<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Filter_Select extends Filter_Element {
	public $name        = 'filter-select';
	public $icon        = 'ti-widget-alt';
	public $filter_type = 'select';
	public $scripts     = [ 'bricksChoices' ];

	public function get_label() {
		return esc_html__( 'Filter', 'bricks' ) . ' - ' . esc_html__( 'Select', 'bricks' );
	}

	public function set_control_groups() {
		$this->control_groups['choices-search'] = [
			'title'    => esc_html__( 'Search', 'bricks' ),
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		// Not for 'sort' or 'per_page' action because multiple selection doesn't make sense in those contexts (#86c8xy1cm; @since 2.3.1)
		$this->control_groups['choices-multiple'] = [
			'title'    => esc_html__( 'Multiple options', 'bricks' ),
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
				[ 'filterAction', '=', [ '', 'filter' ] ],
			],
		];

		$this->control_groups['choices-style'] = [
			'title'    => esc_html__( 'Style', 'bricks' ),
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->control_groups['choices-item'] = [
			'title'    => esc_html__( 'Item', 'bricks' ),
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'bricks-filters' );
		$settings = $this->settings;

		/**
		 * Enqueue Choices.js assets
		 *
		 * 1. choicesJs is enabled and we are not in the main builder window
		 * OR
		 * 2. we are in the builder iframe (to prevent possible conflicts with builder panel controls, etc.)
		 */
		if ( ( bricks_is_builder_iframe() && ! bricks_is_builder_main() ) ||
			( ! empty( $settings['choicesJs'] ) && ! bricks_is_builder_main() )
		) {
			wp_enqueue_style( 'bricks-choices' );
			wp_enqueue_script( 'bricks-choices-lib' );
			wp_enqueue_script( 'bricks-choices-js' );
		}
	}

	public function set_controls() {
		$filter_controls = $this->get_filter_controls();

		if ( ! empty( $filter_controls ) ) {
			// fieldCompareOperator placeholder and default value should be Equal
			$filter_controls['fieldCompareOperator']['placeholder'] = esc_html__( 'Equal', 'bricks' );

			// Change the require condition for filterMultiLogic (@since 2.3)
			$filter_controls['filterMultiLogic']['required'] = [
				[ 'filterQueryId', '!=', '' ],
				[ 'filterAction', '=', [ '', 'filter' ] ],
				[ 'choicesJs', '=', true ],
				[ 'enableMultiple', '=', true ],
			];

			// Select filter supports multiple values if enableMultiple is on (@since 2.3)
			// Remove "IN", "NOT IN", "BETWEEN", "NOT BETWEEN" from select options
			// unset( $filter_controls['fieldCompareOperator']['options']['IN'] );
			// unset( $filter_controls['fieldCompareOperator']['options']['NOT IN'] );
			// unset( $filter_controls['fieldCompareOperator']['options']['BETWEEN'] );
			// unset( $filter_controls['fieldCompareOperator']['options']['NOT BETWEEN'] );

			$this->controls = array_merge( $this->controls, $filter_controls );
		}

		// Keep legacy native-select placeholders editable as well as Choices.js placeholders. (#86cbhtta6; @since 2.4.1)
		$this->controls['placeholder'] = [
			'label'       => esc_html__( 'Selection placeholder', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'description' => esc_html__( 'Shown when no option is selected. For regular filters, used only when Label: All is not set.', 'bricks' ),
			'required'    => [
				[ 'filterQueryId', '!=', '' ],
			],
		];

		/**
		 * CHOICES.JS
		 *
		 * Select search, multiple selection, and better styling options powered by Choices.js library (https://github.com/Choices-js/Choices)
		 *
		 * @since 2.3
		 */
		$this->controls['choicesSep'] = [
			'label'    => esc_html__( 'Enhanced select', 'bricks' ),
			'type'     => 'separator',
			'required' => [
				[ 'filterQueryId', '!=', '' ],
			],
		];

		$this->controls['choicesInfo'] = [
			'type'     => 'info',
			'content'  => esc_html__( 'An additional wrapper is added. Please use styling options below instead of the "Style" tab. ', 'bricks' ),
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesJs'] = [
			'label'    => esc_html__( 'Enhanced select', 'bricks' ),
			'desc'     => esc_html__( 'Enhance the select with search, multiple selection, and better styling options.', 'bricks' ) . ' ' . sprintf( esc_html__( 'Powered by %s', 'bricks' ), '<a href="https://github.com/Choices-js/Choices" target="_blank">Choices.js</a>' ),
			'type'     => 'checkbox',
			'required' => [
				[ 'filterQueryId', '!=', '' ],
			],
		];

		$this->controls['_addedClasses'] = [
			'label'    => esc_html__( 'Keep open while styling', 'bricks' ),
			'type'     => 'checkbox',
			'class'    => 'brx-open',
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesPosition'] = [
			'label'       => esc_html__( 'Dropdown position', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'auto'   => esc_html__( 'Auto', 'bricks' ),
				'bottom' => esc_html__( 'Bottom', 'bricks' ),
				'top'    => esc_html_x( 'Top', 'position', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Auto', 'bricks' ),
			'required'    => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		// CHOICES.JS SEARCH (@since 2.3)
		$this->controls['choicesSearch'] = [
			'group' => 'choices-search',
			'label' => esc_html__( 'Enable search', 'bricks' ),
			'type'  => 'checkbox',
		];

		$this->controls['choicesSearchBackground'] = [
			'group'    => 'choices-search',
			'label'    => esc_html__( 'Background color', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'selector' => 'input[type="search"]',
					'property' => 'background-color',
				],
			],
			'required' => [ 'choicesSearch', '=', true ],
		];

		$this->controls['choicesSearchPlaceholder'] = [
			'group'       => 'choices-search',
			'label'       => esc_html__( 'Search placeholder', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Search', 'bricks' ),
			'required'    => [ 'choicesSearch', '=', true ],
		];

		$this->controls['choicesSearchInputTypography'] = [
			'group'    => 'choices-search',
			'label'    => esc_html__( 'Input', 'bricks' ) . ': ' . esc_html__( 'Typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'selector' => '.bricks-choices__input',
					'property' => 'font',
				],
			],
			'required' => [ 'choicesSearch', '=', true ],
		];

		$this->controls['choicesSearchInputPadding'] = [
			'group'          => 'choices-search',
			'label'          => esc_html__( 'Input', 'bricks' ) . ': ' . esc_html__( 'Padding', 'bricks' ),
			'type'           => 'text',
			'css'            => [
				[
					'property' => '--choices-brx-search-input-padding',
				],
			],
			'hasDynamicData' => false,
			'inline'         => true,
			'placeholder'    => '10px',
			'required'       => [ 'choicesSearch', '=', true ],
		];

		$this->controls['choicesNoResultsText'] = [
			'group'       => 'choices-search',
			'label'       => esc_html__( 'No results', 'bricks' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'No results found', 'bricks' ),
			'description' => esc_html__( 'The text that is shown when a search has returned no results.', 'bricks' ),
			'required'    => [ 'choicesSearch', '=', true ],
		];

		$this->controls['choicesNoChoicesText'] = [
			'group'       => 'choices-search',
			'label'       => esc_html__( 'No choices', 'bricks' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'No choices to choose from', 'bricks' ),
			'description' => esc_html__( 'The text that is shown when no choices exist or all possible choices are selected.', 'bricks' ),
			'required'    => [ 'choicesSearch', '=', true ],
		];

		// CHOICES.JS MULTIPLE SELECTION (@since 2.3)
		$this->controls['enableMultiple'] = [
			'group'    => 'choices-multiple',
			'label'    => esc_html__( 'Multiple options', 'bricks' ),
			'type'     => 'checkbox',
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'filterAction', '=', [ '', 'filter' ] ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesPillGap'] = [
			'group'       => 'choices-multiple',
			'label'       => esc_html__( 'Pill', 'bricks' ) . ': ' . esc_html__( 'Gap', 'bricks' ),
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[
					'property' => '--choices-multiple-item-margin',
				],
			],
			'placeholder' => '4px',
			'required'    => [
				[ 'filterQueryId', '!=', '' ],
				[ 'filterAction', '=', [ '', 'filter' ] ],
				[ 'choicesJs', '=', true ],
				[ 'enableMultiple', '=', true ],
			],
		];

		$this->controls['choicesPillBackground'] = [
			'group'    => 'choices-multiple',
			'label'    => esc_html__( 'Pill', 'bricks' ) . ': ' . esc_html__( 'Background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-primary-color',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'filterAction', '=', [ '', 'filter' ] ],
				[ 'choicesJs', '=', true ],
				[ 'enableMultiple', '=', true ],
			],
		];

		$this->controls['choicesPillBorder'] = [
			'group'    => 'choices-multiple',
			'label'    => esc_html__( 'Pill', 'bricks' ) . ': ' . esc_html__( 'Border', 'bricks' ),
			'type'     => 'border',
			'css'      => [
				[
					'selector' => '.bricks-choices__list--multiple .bricks-choices__item',
					'property' => 'border',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'filterAction', '=', [ '', 'filter' ] ],
				[ 'choicesJs', '=', true ],
				[ 'enableMultiple', '=', true ],
			],
		];

		$this->controls['choicesPillTypography'] = [
			'group'    => 'choices-multiple',
			'label'    => esc_html__( 'Pill', 'bricks' ) . ': ' . esc_html__( 'Typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'property' => 'font',
					'selector' => '.bricks-choices__list--multiple .bricks-choices__item',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'filterAction', '=', [ '', 'filter' ] ],
				[ 'choicesJs', '=', true ],
				[ 'enableMultiple', '=', true ],
			],
		];

		$this->controls['choicesPadding'] = [
			'group'          => 'choices-style',
			'label'          => esc_html__( 'Padding', 'bricks' ),
			'type'           => 'text',
			'css'            => [
				[
					'property' => '--choices-inner-padding',
				],
			],
			'inline'         => true,
			'hasDynamicData' => false,
			'required'       => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesBackgroundColor'] = [
			'group'                   => 'choices-style',
			'label'                   => esc_html__( 'Background', 'bricks' ),
			'type'                    => 'color',
			'css'                     => [
				[
					'property' => '--choices-bg-color',
				],
			],
			'placeholder'             => '#f9f9f9',
			'previewFixedPlaceholder' => false,
			'required'                => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesBorderBase'] = [
			'group'          => 'choices-style',
			'label'          => esc_html__( 'Border', 'bricks' ) . ' (' . esc_html__( 'Base', 'bricks' ) . ')',
			'type'           => 'text',
			'css'            => [
				[
					'property' => '--choices-base-border',
				],
			],
			'inline'         => true,
			'hasDynamicData' => false,
			'placeholder'    => '1px solid',
			'required'       => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesBorderColor'] = [
			'group'    => 'choices-style',
			'label'    => esc_html__( 'Border color', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-keyline-color',
				],
				// Theme Styles border color is unlayered, so the visible Choices borders need scoped direct rules (#86c964ew0).
				[
					'property' => 'border-color',
					'selector' => '.bricks-choices__inner, .bricks-choices__list--dropdown',
					'value'    => 'var(--choices-keyline-color, var(--bricks-border-color))',
				],
				[
					'property' => 'border-color',
					'selector' => '.bricks-choices.is-focused .bricks-choices__inner, .bricks-choices.is-open .bricks-choices__inner, .bricks-choices.is-open .bricks-choices__list--dropdown',
					'value'    => 'color-mix(in srgb, var(--choices-keyline-color, var(--bricks-border-color)) 85%, var(--choices-darken, black))',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesBorderRadius'] = [
			'group'       => 'choices-style',
			'label'       => esc_html__( 'Border radius', 'bricks' ),
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[
					'property' => '--choices-border-radius',
				],
			],
			'placeholder' => '2.5px',
			'required'    => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesFontSize'] = [
			'group'    => 'choices-style',
			'label'    => esc_html__( 'Font size', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => '--choices-font-size',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesTextColor'] = [
			'group'                   => 'choices-style',
			'label'                   => esc_html__( 'Text color', 'bricks' ),
			'type'                    => 'color',
			'css'                     => [
				[
					'property' => '--choices-brx-text-color',
				],
			],
			'placeholder'             => '#333',
			'previewFixedPlaceholder' => false,
			'required'                => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		// Change from choices-search group to choices-style group as it can be styled even when search is disabled, and it's more intuitive to find all styling options in the same group (@since 2.4)
		$this->controls['choicesSearchTypography'] = [
			'group'    => 'choices-style',
			'label'    => esc_html__( 'Placeholder', 'bricks' ) . ': ' . esc_html__( 'Typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'selector' => '.bricks-choices__placeholder',
					'property' => 'font',
				],
				[
					'selector' => '.bricks-choices__input::placeholder',
					'property' => 'font',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		// Placeholder opacity control (@since 2.4)
		$this->controls['choicesPlaceholderOpacity'] = [
			'group'       => 'choices-style',
			'label'       => esc_html__( 'Placeholder', 'bricks' ) . ': ' . esc_html__( 'Opacity', 'bricks' ),
			'type'        => 'number',
			'step'        => '.01',
			'min'         => '0',
			'max'         => '1',
			'placeholder' => '0.5',
			'css'         => [
				[
					'property' => '--choices-placeholder-opacity',
				],
			],
			'required'    => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesArrowColor'] = [
			'group'    => 'choices-style',
			'label'    => esc_html__( 'Arrow color', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-text-color',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		// CHOICES.JS ITEM (@since 2.3)
		$this->controls['choicesItemPadding'] = [
			'group'          => 'choices-item',
			'label'          => esc_html__( 'Padding', 'bricks' ),
			'type'           => 'text',
			'css'            => [
				[
					'property' => '--choices-dropdown-item-padding',
				],
			],
			'hasDynamicData' => false,
			'inline'         => true,
			'placeholder'    => '10px',
			'required'       => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesDropdownBackground'] = [
			'group'    => 'choices-item',
			'label'    => esc_html__( 'Background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-bg-color-dropdown',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		// CHOICES.JS HIGHLIGHT ITEM (@since 2.3)
		$this->controls['choicesHighlightBackground'] = [
			'group'    => 'choices-item',
			'label'    => esc_html__( 'Highlight', 'bricks' ) . ': ' . esc_html__( 'Background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-highlighted-color',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesHighlightTextColor'] = [
			'group'    => 'choices-item',
			'label'    => esc_html__( 'Highlight', 'bricks' ) . ': ' . esc_html__( 'Text color', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-brx-highlighted-text-color',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		$this->controls['choicesDisabledBackground'] = [
			'group'    => 'choices-item',
			'label'    => esc_html__( 'Disabled', 'bricks' ) . ': ' . esc_html__( 'Background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-brx-bg-color-disabled',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];

		// CHOICES.JS DISABLED ITEM (@since 2.3)
		$this->controls['choicesDisabledTextColor'] = [
			'group'    => 'choices-item',
			'label'    => esc_html__( 'Disabled', 'bricks' ) . ': ' . esc_html__( 'Text color', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--choices-brx-text-color-disabled',
				],
			],
			'required' => [
				[ 'filterQueryId', '!=', '' ],
				[ 'choicesJs', '=', true ],
			],
		];
	}

	/**
	 * Setup filter
	 *
	 * If is a sort input
	 * - Set sorting options
	 *
	 * If is a per_page input
	 * - Set per_page options
	 *
	 * If is a filter input
	 * - Prepare sources
	 * - Set data_source
	 * - Set final options
	 *
	 * - Set data-brx-filter attribute
	 */
	private function set_as_filter() {
		$settings = $this->settings;

		// Check required filter settings
		if ( empty( $settings['filterQueryId'] ) ) {
			return;
		}

		// Filter or Sort
		$filter_action = $settings['filterAction'] ?? 'filter';

		if ( $filter_action === 'filter' ) {
			// A filter input must have filterSource
			if ( empty( $settings['filterSource'] ) ) {
				return;
			}

			$this->prepare_sources();
			$this->set_data_source();
			$this->set_options_with_count();
		}

		elseif ( $filter_action === 'sort' ) {
			// User wish to use what options as sort options
			$this->setup_sort_options();
		}

		else {
			// User wish to use what options as per_page options
			$this->setup_per_page_options();
		}

		// Insert filter settings as data-brx-filter attribute
		$filter_settings                 = $this->get_common_filter_settings();
		$filter_settings['filterSource'] = $settings['filterSource'] ?? false;

		$enable_choices = ! empty( $settings['choicesJs'] );
		$select_key     = $enable_choices ? '_choices' : '_root';

		if ( ! bricks_is_builder_call() && ! bricks_is_builder_main() ) {
			// To avoid double JS initialization in the builder preview
			$this->set_attribute( $select_key, 'data-brx-filter', wp_json_encode( $filter_settings ) );
		}
	}

	/**
	 * Get the Choices.js selection placeholder.
	 *
	 * Uses the configured value only when Choices.js is enabled, then falls back
	 * to the populated empty option label when no configured value is available.
	 *
	 * @return string
	 */
	private function get_choices_placeholder_value() {
		$use_placeholder  = ! empty( $this->settings['choicesJs'] ) && ! empty( $this->settings['placeholder'] );
		$user_placeholder = $use_placeholder ? $this->render_dynamic_data( $this->settings['placeholder'] ) : '';

		if ( ! empty( $user_placeholder ) ) {
			return $user_placeholder;
		}

		foreach ( $this->get_populated_options() as $option ) {
			$is_placeholder = isset( $option['is_placeholder'] ) && $option['is_placeholder'] === true;
			$is_empty_value = isset( $option['value'] ) && (string) $option['value'] === '';
			$option_text    = isset( $option['text'] ) ? trim( (string) $option['text'] ) : '';

			if ( ( $is_placeholder || $is_empty_value ) && $option_text !== '' ) {
				return $option_text;
			}
		}

		return '';
	}

	/**
	 * Get the option data displayed by Choices.js before it initializes.
	 *
	 * @since 2.4 #86cb4nn6f
	 *
	 * @param string|array $current_value              Current select value.
	 * @param bool         $is_multiple                Whether multiple values are enabled.
	 * @param bool         $show_selection_placeholder Whether the custom selection placeholder is visible.
	 * @return array
	 */
	private function get_choices_pre_init_options( $current_value, $is_multiple, $show_selection_placeholder = false ) {
		$first_option                     = [];
		$selected_options                 = [];
		$selection_placeholder_is_visible = ! $is_multiple && $show_selection_placeholder && $current_value === '';

		foreach ( $this->get_populated_options() as $option ) {
			if ( isset( $option['text'] ) && $option['text'] === '' ) {
				continue;
			}

			$option_value     = esc_attr( $option['value'] );
			$comparison_value = rawurldecode( $option_value );
			$is_placeholder   = ! empty( $option['is_placeholder'] ) || (string) $option['value'] === '';

			// Multiple selects use the placeholder as input UI, never as a selected pill.
			if ( $is_multiple && $is_placeholder ) {
				continue;
			}

			// The custom selection placeholder replaces the empty "All" option in the closed control.
			if ( $selection_placeholder_is_visible && $is_placeholder ) {
				continue;
			}

			$is_selected    = $is_multiple
				? in_array( $comparison_value, (array) $current_value, true )
				: (string) $current_value === (string) $comparison_value;
			$initial_option = [
				'text'           => $this->get_option_text_with_count( $option ),
				'is_placeholder' => $is_placeholder,
			];

			if ( empty( $first_option ) ) {
				$first_option = $initial_option;
			}

			if ( $is_selected ) {
				$selected_options[] = $initial_option;
			}
		}

		// A native single select and Choices.js both select the first option by default.
		if ( ! $is_multiple && ! $selection_placeholder_is_visible && empty( $selected_options ) && ! empty( $first_option ) ) {
			$selected_options[] = $first_option;
		}

		return $selected_options;
	}

	/**
	 * Render the visible part of the Choices.js control for the first paint.
	 *
	 * Reusing the final class structure keeps all Choices styles in one source of
	 * truth. The live library replaces this inert shell synchronously on init.
	 *
	 * @since 2.4
	 *
	 * @param array  $selected_options   Initially selected option data.
	 * @param bool   $is_multiple        Whether multiple values are enabled.
	 * @param bool   $search_enabled     Whether the search input is enabled.
	 * @param string $placeholder        Choices placeholder text.
	 * @return void
	 */
	private function render_choices_pre_init_control( $selected_options, $is_multiple, $search_enabled, $placeholder ) {
		$placeholder_text = trim( wp_strip_all_tags( $placeholder ) );

		if ( ! $is_multiple ) {
			$selected_option = $selected_options[0] ?? [];

			if ( empty( $selected_option ) && $placeholder_text !== '' ) {
				$selected_option = [
					'text'           => $placeholder_text,
					'is_placeholder' => true,
				];
			}

			$item_class     = 'bricks-choices__item bricks-choices__item--selectable';
			$item_attribute = '';

			if ( ! empty( $selected_option['is_placeholder'] ) ) {
				$item_class    .= ' bricks-choices__placeholder';
				$item_attribute = ' data-placeholder';
			}

			echo '<div class="bricks-choices__inner" aria-hidden="true">';
			echo '<div class="bricks-choices__list bricks-choices__list--single">';

			if ( ! empty( $selected_option ) ) {
				echo '<div class="' . esc_attr( $item_class ) . '"' . $item_attribute . '>';
				echo wp_kses_post( $selected_option['text'] );
				echo '<button type="button" class="bricks-choices__button" tabindex="-1"></button>';
				echo '</div>';
			}

			echo '</div>';
			echo '</div>';

			return;
		}

		echo '<div class="bricks-choices__inner" aria-hidden="true">';
		echo '<div class="bricks-choices__list bricks-choices__list--multiple">';

		foreach ( $selected_options as $selected_option ) {
			echo '<div class="bricks-choices__item bricks-choices__item--selectable">';
			echo wp_kses_post( $selected_option['text'] );
			echo '<button type="button" class="bricks-choices__button" tabindex="-1"></button>';
			echo '</div>';
		}

		echo '</div>';

		if ( $search_enabled ) {
			$placeholder_length = function_exists( 'mb_strlen' ) ? mb_strlen( $placeholder_text ) : strlen( $placeholder_text );
			$minimum_width      = max( 1, $placeholder_length + 1 );

			echo '<input type="search" class="bricks-choices__input bricks-choices__input--cloned" tabindex="-1" placeholder="' . esc_attr( $placeholder_text ) . '" style="min-width: ' . esc_attr( $minimum_width ) . 'ch; width: 1ch">';
		} elseif ( $placeholder_text !== '' ) {
			$display = ! empty( $selected_options ) ? ' style="display: none"' : '';

			echo '<div class="bricks-choices__placeholder bricks-choices__placeholder--multiple"' . $display . '>';
			echo esc_html( $placeholder_text );
			echo '</div>';
		}

		echo '</div>';
	}

	public function render() {
		$settings      = $this->settings;
		$current_value = isset( $settings['value'] ) ? $settings['value'] : '';

		// In filter AJAX call: filterValue is the current filter value
		if ( isset( $settings['filterValue'] ) ) {
			$current_value = $settings['filterValue'];
		}

		// Escape attributes - handle both single value and array (@since 2.3)
		if ( is_array( $current_value ) ) {
			$current_value = array_map( 'esc_attr', $current_value );
		} else {
			$current_value = esc_attr( $current_value );
		}

		$this->input_name = $settings['name'] ?? "form-field-{$this->id}";

		if ( $this->is_filter_input() ) {
			$this->set_as_filter();

			// Return: Indexing in progress
			if ( $this->is_indexing() ) {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'Indexing in progress.', 'bricks' ),
					]
				);
			}
		}

		// Return: No filter source selected
		$filter_action = $this->settings['filterAction'] ?? 'filter';
		if ( $filter_action === 'filter' && empty( $settings['filterSource'] ) ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'No filter source selected.', 'bricks' ),
				]
			);
		}

		// Choices.js mode (@since 2.3)
		$enable_choices          = ! empty( $settings['choicesJs'] );
		$enable_multiple         = Query_Filters::multiple_value_supported( $this->name, $settings );
		$has_choices_placeholder = $enable_choices && ! empty( $settings['placeholder'] );

		$unique_id           = Query::is_any_looping() ? Helpers::generate_random_id( false ) : $this->uid;
		$choices_placeholder = $this->get_choices_placeholder_value();
		$accessible_name     = trim( wp_strip_all_tags( $choices_placeholder ) );
		$accessible_name     = $accessible_name !== '' ? $accessible_name : esc_html__( 'Select', 'bricks' );

		$select_key      = $enable_choices ? '_choices' : '_root';
		$choices_options = [];

		$this->set_attribute( $select_key, 'aria-label', $accessible_name );

		if ( $enable_multiple ) {
			$this->set_attribute( $select_key, 'multiple' );
			$this->set_attribute( $select_key, 'name', $this->input_name . '[]' ); // Array name
		} else {
			$this->set_attribute( $select_key, 'name', $this->input_name );
		}

		if ( $enable_choices ) {
			// Set data attribute for auto-initialization
			$this->set_attribute( $select_key, 'data-brx-choices', 'true' );
			$this->set_attribute( $select_key, 'class', 'brxe-filter-select' );
			$this->set_attribute( $select_key, 'data-script-id', $unique_id );

			// Build Choices.js options
			$choices_options = [
				'searchEnabled' => ! empty( $settings['choicesSearch'] )
			];

			if ( ( $enable_multiple || $has_choices_placeholder ) && ! empty( $choices_placeholder ) ) {
				$choices_options['placeholderValue'] = $choices_placeholder;

				if ( $enable_multiple ) {
					$this->set_attribute( $select_key, 'data-placeholder', $choices_placeholder );
				}
			}

			if ( ! empty( $settings['choicesSearchPlaceholder'] ) ) {
				$choices_options['searchPlaceholderValue'] = $this->render_dynamic_data( $settings['choicesSearchPlaceholder'] );
			}

			if ( $enable_multiple && empty( $choices_options['placeholderValue'] ) && isset( $settings['filterHideEmpty'] ) ) {
				// In multiple mode with hide empty enabled, set a default search placeholder if not set by user, otherwise the choices will be empty and confusing when nothing is selected
				$choices_options['placeholderValue'] = ! empty( $choices_placeholder ) ? $choices_placeholder : esc_html__( 'Search', 'bricks' );
			}

			if ( ! empty( $settings['choicesNoResultsText'] ) ) {
				$choices_options['noResultsText'] = $this->render_dynamic_data( $settings['choicesNoResultsText'] );
			}

			if ( ! empty( $settings['choicesNoChoicesText'] ) ) {
				$choices_options['noChoicesText'] = $this->render_dynamic_data( $settings['choicesNoChoicesText'] );
			}

			if ( ! empty( $settings['choicesPosition'] ) ) {
				$choices_options['position'] = $settings['choicesPosition'];

				// For builder preview only
				if ( bricks_is_builder() || bricks_is_builder_call() ) {
					$this->set_attribute( '_root', 'data-brx-choices-position', $settings['choicesPosition'] );
				}
			}

			if ( ! empty( $settings['choicesMaxItems'] ) ) {
				$choices_options['maxItemCount'] = (int) $settings['choicesMaxItems'];
			}

			// Add options as data attribute
			$this->set_attribute( $select_key, 'data-brx-choices-options', wp_json_encode( $choices_options ) );

			echo "<div {$this->render_attributes( '_root' )}>";
			// Render the visible part of the Choices.js control for the first paint (#86cb4nn6f; @since 2.4)
			echo '<div class="bricks-choices bricks-choices--pre-init" data-type="' . ( $enable_multiple ? 'select-multiple' : 'select-one' ) . '">';

			$this->render_choices_pre_init_control(
				$this->get_choices_pre_init_options( $current_value, $enable_multiple, $has_choices_placeholder ),
				$enable_multiple,
				! empty( $choices_options['searchEnabled'] ),
				$choices_options['placeholderValue'] ?? $choices_placeholder
			);
		}

		echo "<select {$this->render_attributes($select_key)}>";

		// Generate options HTML
		foreach ( $this->get_populated_options() as $option ) {
			/**
			 * Skip empty text options
			 *
			 * Each option must have a text. 0 is allowed, otherwise it will conflict with the "All" option / Placeholder option.
			 *
			 * @since 1.12
			 */
			if ( isset( $option['text'] ) && $option['text'] === '' ) {
				continue;
			}

			$option_value   = esc_attr( $option['value'] );
			$option_text    = $this->get_option_text_with_count( $option );
			$option_class   = ! empty( $option['class'] ) ? esc_attr( trim( $option['class'] ) ) : '';
			$is_placeholder = isset( $option['is_placeholder'] ) && $option['is_placeholder'] === true;

			// In multiple mode, keep the placeholder for the empty state UI only.
			if ( $enable_multiple && $is_placeholder ) {
				continue;
			}

			// Handle both single value and array for selection (@since 2.3)
			if ( $enable_multiple ) {
				$option_selected = in_array( rawurldecode( $option_value ), (array) $current_value, true ) ? 'selected' : '';

				if ( $is_placeholder && ! bricks_is_builder() && ! bricks_is_builder_call() ) {
					// Placeholder option should not be selectable in multiple mode, only selected in the builder for styling preview
					$option_selected = '';
				}
			} else {
				// A selected empty option replaces the separate Choices.js placeholder with the "All" label.
				$show_choices_placeholder = $has_choices_placeholder && $is_placeholder && $current_value === '';
				$option_selected          = $show_choices_placeholder ? '' : selected( $current_value, rawurldecode( $option_value ), false );
			}

			$option_disabled = isset( $option['disabled'] ) ? 'disabled' : '';

			echo '<option value="' . $option_value . '" ' .
			( ! empty( $option_class ) ? "class='{$option_class}'" : '' ) . ' ' .
			$option_selected . ' ' .
			$option_disabled . '>' .
			$option_text . '</option>';

		}

		echo '</select>';

		if ( $enable_choices ) {
			echo '</div>';
			echo '</div>';
		}
	}
}
