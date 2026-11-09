<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Filter_Checkbox extends Filter_Element {
	public $name             = 'filter-checkbox';
	public $icon             = 'ti-check-box';
	public $filter_type      = 'checkbox';
	public $rendered_options = [];

	public function get_label() {
		return esc_html__( 'Filter', 'bricks' ) . ' - ' . esc_html__( 'Checkbox', 'bricks' );
	}

	public function set_controls() {
		$filter_controls = $this->get_filter_controls();

		if ( ! empty( $filter_controls ) ) {
			// fieldCompareOperator only support 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'
			$filter_controls['fieldCompareOperator']['options'] = [
				'IN'          => 'IN',
				'NOT IN'      => 'NOT IN',
				'BETWEEN'     => 'BETWEEN',
				'NOT BETWEEN' => 'NOT BETWEEN',
			];

			$this->controls = array_merge( $this->controls, $filter_controls );
		}
	}

	/**
	 * Populate options from user input and set to $this->populated_options
	 *
	 * Not in Beta
	 */
	private function populate_user_options() {
		$options      = [];
		$user_options = ! empty( $this->settings['options'] ) ? Helpers::parse_textarea_options( $this->settings['options'] ) : false;

		if ( ! empty( $user_options ) ) {
			foreach ( $user_options as $option ) {
				$options[] = [
					'value' => $option,
					'text'  => $option,
					'class' => '',
				];
			}
		}

		$this->populated_options = $options;
	}

	public function is_filter_input() {
		return ! empty( $this->settings['filterQueryId'] ) && ! empty( $this->settings['filterSource'] );
	}

	/**
	 * Setup filter
	 */
	private function set_as_filter() {
		$settings = $this->settings;

		// Return: Required filter settings not set
		if ( empty( $settings['filterQueryId'] ) || empty( $settings['filterSource'] ) ) {
			return;
		}

		$this->prepare_sources();
		$this->set_data_source();
		$this->set_options_with_count();

		// Insert filter settings as data-brx-filter attribute
		$filter_settings                 = $this->get_common_filter_settings();
		$filter_settings['filterSource'] = $settings['filterSource'];
		$filter_settings['hierarchy']    = $settings['filterHierarchical'] ?? false;
		$filter_settings['autoCheck']    = $settings['filterAutoCheckChildren'] ?? false;

		$this->set_attribute( '_root', 'data-brx-filter', wp_json_encode( $filter_settings ) );
	}

	public function render() {
		$settings         = $this->settings;
		$this->input_name = $settings['name'] ?? "form-field-{$this->id}";

		if ( $this->is_filter_input() ) {
			$this->set_as_filter();

			// Return: Indexing in progress (@since 1.10)
			if ( $this->is_indexing() ) {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'Indexing in progress.', 'bricks' ),
					]
				);
			}
		} else {
			// Not in Beta
			// $this->populate_user_options();
		}

		// Prepare options HTML and Show More/Less HTML, sequence matters as show more less will add attributes to root node (@since 2.3)
		$options_html        = $this->get_options_html();
		$show_more_less_html = $this->get_show_more_less_html();
		$this->set_attribute( 'options', 'class', 'brx-filter-options' );

		echo "<fieldset {$this->render_attributes('_root')}>";
		echo '<legend class="brx-filter-legend">' . $this->get_filter_group_label() . '</legend>';
		echo "<ul {$this->render_attributes('options')}>";
		echo $options_html;
		echo $show_more_less_html;
		echo '</ul>';
		echo '</fieldset>';
	}

	/**
	 * Generate options HTML
	 * $this->rendered_options will be set in this function
	 *
	 * @since 2.3
	 */
	public function get_options_html() {
		$options_html = '';

		$settings        = $this->settings;
		$hierarchy       = $settings['filterHierarchical'] ?? false;
		$display_mode    = $this->get_display_mode();
		$count_align_end = isset( $settings['countAlignEnd'] );

		// Show more/less preview in builder only
		$initial_items      = ! empty( $settings['limitOptions'] ) ? absint( $settings['limitOptions'] ) : 0;
		$is_builder_preview = bricks_is_builder() || bricks_is_builder_call();
		$rendered_index     = -1;

		$this->set_attribute( '_root', 'data-mode', $display_mode );

		if ( $count_align_end && $display_mode === 'default' ) {
			$this->set_attribute( '_root', 'class', 'brx-count-align-end' );
		}

		// Set current value
		$current_value = isset( $settings['value'] ) && is_array( $settings['value'] ) ? $settings['value'] : [];

		// In filter AJAX call, filterValue is the current filter value
		if ( isset( $settings['filterValue'] ) ) {
			$current_value = $settings['filterValue'];
		}

		// Escape attributes or it can't match with the options (@since 1.12)
		if ( is_array( $current_value ) ) {
			$current_value = array_map( 'esc_attr', $current_value );
		} else {
			$current_value = esc_attr( $current_value );
		}

		foreach ( $this->get_populated_options() as $index => $option ) {
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

			$rendered_index++; // Increment rendered index after skipping options that won't be rendered (Builder preview only)

			$option_text     = $this->get_option_text_with_count( $option );
			$option_value    = esc_attr( $option['value'] );
			$option_class    = esc_attr( $option['class'] );
			$option_selected = in_array( rawurldecode( $option_value ), $current_value );
			$option_disabled = isset( $option['disabled'] ) && ! $option_selected;

			$li_key        = 'li_' . $index;
			$label_key     = 'label_' . $index;
			$input_key     = 'input_' . $index;
			$span_key      = 'span_' . $index;
			$indicator_key = 'indicator_' . $index;

			$this->set_attribute( $input_key, 'type', 'checkbox' );
			$this->set_attribute( $input_key, 'name', $this->input_name );
			$this->set_attribute( $input_key, 'value', $option_value );

			$this->set_attribute( $span_key, 'class', 'brx-option-text' );
			$this->set_attribute( $label_key, 'class', $option_class );
			$this->set_attribute( $input_key, 'class', 'brx-a11y-hidden' );

			// Custom indicator for checkbox, easier to style (@since 2.3)
			$this->set_attribute( $indicator_key, 'class', 'brx-input-indicator' );
			$this->set_attribute( $indicator_key, 'aria-hidden', 'true' );

			// Builder only: visually hide options after initial limit (keep selected visible)
			if ( $is_builder_preview && $initial_items > 0 && $rendered_index >= $initial_items && ! $option_selected ) {
				$this->set_attribute( $li_key, 'class', 'brx-option-hidden' );
			}

			if ( $hierarchy ) {
				$depth = $option['depth'] ?? 0;
				$this->set_attribute( $li_key, 'data-depth', $depth );
			}

			if ( $option_selected ) {
				// Set checked attribute
				$this->set_attribute( $input_key, 'checked', 'checked' );

				// Set .brx-option-active classes so user could style easily (avoid using .active as it's too general)
				$this->set_attribute( $li_key, 'class', 'brx-option-active' );
				$this->set_attribute( $label_key, 'class', 'brx-option-active' );
				$this->set_attribute( $span_key, 'class', 'brx-option-active' );
			}

			if ( $option_disabled ) {
				// Set disabled attribute
				$this->set_attribute( $input_key, 'disabled', 'disabled' );
			}

			// Mode: Button
			if ( $display_mode === 'button' ) {
				$this->set_attribute( $span_key, 'class', 'bricks-button' );

				if ( isset( $settings['buttonSize'] ) ) {
					$this->set_attribute( $span_key, 'class', $settings['buttonSize'] );
				}

				if ( isset( $settings['buttonStyle'] ) ) {
					if ( isset( $settings['buttonOutline'] ) ) {
						$this->set_attribute( $span_key, 'class', 'outline bricks-color-' . $settings['buttonStyle'] );
					} else {
						$this->set_attribute( $span_key, 'class', 'bricks-background-' . $settings['buttonStyle'] );
					}
				}

				if ( isset( $settings['buttonCircle'] ) ) {
					$this->set_attribute( $span_key, 'class', 'circle' );
				}
			}

			$options_html .= "<li {$this->render_attributes( $li_key )}>";
			$options_html .= "<label {$this->render_attributes( $label_key )}>";
			$options_html .= "<input {$this->render_attributes( $input_key )}>";

			if ( $display_mode === 'default' ) {
				// Only render custom indicator in default mode (@since 2.3)
				$options_html .= "<span {$this->render_attributes( $indicator_key )}></span>";
			}

			$options_html .= "<span {$this->render_attributes( $span_key )}>{$option_text}</span>";
			$options_html .= '</label>';
			$options_html .= '</li>';

			$this->rendered_options[] = [
				'option'  => $option,
				'checked' => $option_selected,
			];
		}

		return $options_html;
	}
}
