<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Filter_Search extends Filter_Element {
	public $name           = 'filter-search';
	public $icon           = 'ti-search';
	public $filter_type    = 'search';
	public $css_selector   = 'input';
	private $current_value = '';

	public function get_label() {
		return esc_html__( 'Filter', 'bricks' ) . ' - ' . esc_html__( 'Search', 'bricks' );
	}

	public function set_controls() {
		// SORT / FILTER
		$filter_controls = $this->get_filter_controls();

		if ( ! empty( $filter_controls ) ) {
			$filter_controls['filterInputDebounce'] = [
				'type'        => 'number',
				'label'       => esc_html__( 'Debounce', 'bricks' ) . ' (ms)',
				'placeholder' => 500,
				'required'    => [ 'filterApplyOn', '!=', 'click' ],
			];

			$filter_controls['filterMinChars'] = [
				'type'        => 'number',
				'label'       => esc_html__( 'Min. characters', 'bricks' ),
				'placeholder' => 3,
			];

			// Enter action (@since 2.4)
			$filter_controls['filterEnterAction'] = [
				'type'        => 'select',
				'inline'      => true,
				'label'       => esc_html__( 'Enter action', 'bricks' ),
				'options'     => [
					'default'  => esc_html__( 'AJAX search', 'bricks' ),
					'redirect' => esc_html__( 'Redirect to URL', 'bricks' ),
				],
				'placeholder' => esc_html__( 'AJAX search', 'bricks' ),
				'required'    => [ 'filterQueryId', '!=', '' ],
				'description' => esc_html__( 'Choose what happens when pressing Enter in the search input.', 'bricks' ),
			];

			// Trigger Filter Submit interactions on Enter (@since 2.4)
			$filter_controls['filterEnterTriggerSubmit'] = [
				'type'        => 'checkbox',
				'inline'      => true,
				'label'       => esc_html__( 'Trigger Filter Submit interactions', 'bricks' ),
				'description' => esc_html__( 'Run Filter Submit interactions for the target query when pressing Enter.', 'bricks' ),
				'required'    => [
					[ 'filterQueryId', '!=', '' ],
					[ 'filterEnterAction', '!=', 'redirect' ],
				],
			];

			// Enter action (@since 2.4)
			$filter_controls['redirectTo'] = [
				'type'           => 'text',
				'inline'         => true,
				'label'          => esc_html__( 'Redirect to', 'bricks' ),
				'hasDynamicData' => false,
				'description'    => esc_html__( 'URL to redirect to on Enter. Active filters targeting the same query are appended as URL parameters.', 'bricks' ),
				'required'       => [
					[ 'filterQueryId', '!=', '' ],
					[ 'filterEnterAction', '=', 'redirect' ],
				],
			];

			// Enter action (@since 2.4)
			$filter_controls['newTab'] = [
				'type'     => 'checkbox',
				'inline'   => true,
				'label'    => esc_html__( 'Open in new tab', 'bricks' ),
				'required' => [
					[ 'filterQueryId', '!=', '' ],
					[ 'filterEnterAction', '=', 'redirect' ],
					[ 'redirectTo', '!=', '' ],
				],
			];

			// Search Criteria (@since 2.2)
			$filter_controls['searchCriteriaTemplateInfo'] = [
				'content'  => esc_html__( 'You cannot set search criteria on this element. You should set it on the Search Template instead, because you are using native WordPress search URL parameter.', 'bricks' ),
				'group'    => 'search-criteria',
				'type'     => 'info',
				'required' => [ 'filterNiceName', '=', 's' ],
			];

			$search_criteria_controls = Element::search_criteria_controls( 'element' );

			$filter_controls = array_merge( $filter_controls, $search_criteria_controls );

			$this->controls = array_merge( $this->controls, $filter_controls );
		}

		// INPUT
		$this->controls['inputSep'] = [
			'label' => esc_html__( 'Input', 'bricks' ),
			'type'  => 'separator',
		];

		// Placeholder
		$this->controls['placeholder'] = [
			'label'       => esc_html__( 'Placeholder', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Search', 'bricks' ),
		];

		$this->controls['placeholderTypography'] = [
			'label' => esc_html__( 'Placeholder typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => 'input::placeholder',
				],
			],
		];

		// Label (@since 2.0.2)
		$this->controls['label'] = [
			'label'  => esc_html__( 'Label', 'bricks' ),
			'type'   => 'text',
			'inline' => true,
		];

		$this->controls['labelTypography'] = [
			'label'    => esc_html__( 'Label typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'property' => 'font',
					'selector' => 'label',
				],
			],
			'required' => [ 'label', '!=', '' ],
		];

		// Icon (Clear)
		$this->controls['iconSep'] = [
			'label' => esc_html__( 'Icon', 'bricks' ) . ' (' . esc_html__( 'Clear', 'bricks' ) . ')',
			'type'  => 'separator',
		];

		$this->controls['icon'] = [
			'label' => esc_html__( 'Icon', 'bricks' ),
			'type'  => 'icon',
		];

		$this->controls['iconColor'] = [
			'label'    => esc_html__( 'Icon color', 'bricks' ),
			'type'     => 'color',
			'required' => [ 'icon', '!=', '' ],
			'css'      => [
				[
					'selector' => '.icon',
					'property' => 'color',
				],
			],
		];

		$this->controls['iconSize'] = [
			'label'    => esc_html__( 'Icon size', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'required' => [ 'icon', '!=', '' ],
			'css'      => [
				[
					'selector' => '.icon',
					'property' => 'font-size',
				],
			],
		];
	}

	private function set_as_filter() {
		$settings     = $this->settings;
		$enter_action = $settings['filterEnterAction'] ?? 'default';
		$redirect_to  = $settings['redirectTo'] ?? '';
		$new_tab      = $settings['newTab'] ?? false;

		// In filter AJAX call: filterValue is the current filter value
		$this->current_value = $settings['filterValue'] ?? '';
		$this->set_attribute( 'input', 'value', $this->current_value );

		// Insert filter settings as data-brx-filter attribute
		$filter_settings = $this->get_common_filter_settings();

		// min chars
		$filter_settings['filterMinChars']           = $settings['filterMinChars'] ?? 3;
		$filter_settings['filterEnterAction']        = $enter_action;
		$filter_settings['filterEnterTriggerSubmit'] = ! empty( $settings['filterEnterTriggerSubmit'] );

		// Set redirect URL and new tab settings if Enter action is redirect (@since 2.4)
		if ( $enter_action === 'redirect' && ! empty( $redirect_to ) ) {
			$filter_settings['redirectTo'] = esc_url( $redirect_to );
			$filter_settings['newTab']     = $new_tab;
		}

		$this->set_attribute( 'input', 'data-brx-filter', wp_json_encode( $filter_settings ) );

		// Not necessary anymore in Query Filter phase 2
		// $this->input_name = 's'; // For search query arg
	}

	public function render() {
		$settings         = $this->settings;
		$placeholder      = ! empty( $settings['placeholder'] ) ? $this->render_dynamic_data( $settings['placeholder'] ) : esc_html__( 'Search', 'bricks' );
		$icon             = $settings['icon'] ?? false;
		$label_text       = ! empty( $settings['label'] ) ? $this->render_dynamic_data( $settings['label'] ) : false;
		$this->input_name = $settings['name'] ?? "form-field-{$this->id}";

		if ( $this->is_filter_input() ) {
			$this->set_as_filter();
		}

		$this->set_attribute( 'input', 'name', $this->input_name );
		$this->set_attribute( 'input', 'placeholder', $placeholder );
		$this->set_attribute( 'input', 'type', 'search' );
		$this->set_attribute( 'input', 'autocomplete', 'off' );
		$this->set_attribute( 'input', 'spellcheck', 'false' );

		// Has label: Set input ID for accessibility and don't set aria-label (@since 2.0.2)
		if ( $label_text ) {
			$input_id = "bricks-filter-search-{$this->id}";
			$this->set_attribute( 'input', 'id', $input_id );
		} else {
			$this->set_attribute( 'input', 'aria-label', $placeholder );
		}

		echo "<div {$this->render_attributes('_root')}>";

		// Render label (@since 2.0.2)
		if ( $label_text ) {
			echo '<label for="' . esc_attr( "bricks-filter-search-{$this->id}" ) . '">' . esc_html( $label_text ) . '</label>';
		}

		// Keep the label outside the clear icon's positioning container.
		if ( $label_text && $icon ) {
			echo '<div class="brx-search-input">';
		}

		echo "<input {$this->render_attributes('input')}>";

		if ( $icon ) {
			$classes = [ 'icon' ];

			// Show the icon if the value is not empty or in the builder
			if ( $this->current_value !== '' || bricks_is_builder() || bricks_is_builder_call() ) {
				$classes[] = 'brx-show';
			}

			$icon = self::render_icon(
				$icon,
				[
					'aria-label' => esc_html__( 'Clear', 'bricks' ),
					'class'      => $classes,
					'role'       => 'button',
					'tabindex'   => 0,
				]
			);

			if ( $icon ) {
				echo $icon;
			}

			if ( $label_text ) {
				echo '</div>';
			}
		}

		echo '</div>';
	}
}
