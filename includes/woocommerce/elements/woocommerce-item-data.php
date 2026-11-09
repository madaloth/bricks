<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Shared presentation foundation for structured WooCommerce item data.
 *
 * Cart items and order items use different WooCommerce data sources. Keeping
 * only their controls and markup here gives both elements the same styling
 * surface without treating persisted order metadata like live cart data.
 *
 * @since 2.4
 */
abstract class Woocommerce_Item_Data extends Woo_Element {
	/**
	 * Get the query loop type supported by the element.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	abstract protected function get_item_data_query_type();

	/**
	 * Get the BEM class prefix used by the element's internal markup.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	abstract protected function get_item_data_class_prefix();

	/**
	 * Get normalized item data from the active query loop object.
	 *
	 * @since 2.4
	 *
	 * @param mixed $loop_object Active query loop object.
	 * @return array
	 */
	abstract protected function get_item_data( $loop_object );

	/**
	 * Get the builder placeholder shown outside the supported query loop.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	abstract protected function get_query_loop_placeholder();

	/**
	 * Get the builder placeholder shown when the item contains no data.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	abstract protected function get_empty_item_data_placeholder();

	/**
	 * Set builder control groups.
	 *
	 * @since 2.4
	 */
	public function set_control_groups() {
		$this->control_groups['layout'] = [
			'title' => esc_html__( 'Layout', 'bricks' ),
		];

		$this->control_groups['item'] = [
			'title' => esc_html__( 'Item', 'bricks' ),
		];

		$this->control_groups['label'] = [
			'title'    => esc_html__( 'Label', 'bricks' ),
			'required' => [ 'showLabels', '!=', '' ],
		];

		$this->control_groups['value'] = [
			'title' => esc_html__( 'Value', 'bricks' ),
		];

		$this->control_groups['separator'] = [
			'title'    => esc_html__( 'Separator', 'bricks' ),
			'required' => [ 'showSeparators', '!=', '' ],
		];
	}

	/**
	 * Set builder controls.
	 *
	 * @since 2.4
	 */
	public function set_controls() {
		$class_prefix = $this->get_item_data_class_prefix();

		$this->controls['showLabels'] = [
			'label'    => esc_html__( 'Show labels', 'bricks' ),
			'type'     => 'checkbox',
			'inline'   => true,
			'rerender' => true,
			'default'  => true,
		];

		$this->controls['labelSuffix'] = [
			'label'          => esc_html__( 'Label suffix', 'bricks' ),
			'type'           => 'text',
			'inline'         => true,
			'small'          => true,
			'hasDynamicData' => false,
			'rerender'       => true,
			'default'        => ':',
			'required'       => [ 'showLabels', '!=', '' ],
		];

		$this->controls['showSeparators'] = [
			'label'    => esc_html__( 'Show separators', 'bricks' ),
			'type'     => 'checkbox',
			'inline'   => true,
			'rerender' => true,
			'default'  => true,
		];

		$this->controls['separatorText'] = [
			'label'          => esc_html__( 'Separator', 'bricks' ),
			'type'           => 'text',
			'inline'         => true,
			'small'          => true,
			'hasDynamicData' => false,
			'rerender'       => true,
			'default'        => '/',
			'required'       => [ 'showSeparators', '!=', '' ],
		];

		$this->controls['layoutDirection'] = [
			'group'  => 'layout',
			'label'  => esc_html__( 'Direction', 'bricks' ),
			'type'   => 'direction',
			'inline' => true,
			'css'    => [
				[
					'property' => 'flex-direction',
					'selector' => '',
				],
			],
		];

		$this->controls['layoutWrap'] = [
			'group'       => 'layout',
			'label'       => esc_html_x( 'Wrap', 'CSS flex property', 'bricks' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => [
				'nowrap'       => esc_html__( 'No wrap', 'bricks' ),
				'wrap'         => esc_html_x( 'Wrap', 'CSS flex property', 'bricks' ),
				'wrap-reverse' => esc_html__( 'Wrap reverse', 'bricks' ),
			],
			'placeholder' => esc_html_x( 'Wrap', 'CSS flex property', 'bricks' ),
			'css'         => [
				[
					'property' => 'flex-wrap',
					'selector' => '',
				],
			],
		];

		$this->controls['layoutJustifyContent'] = [
			'group'  => 'layout',
			'label'  => esc_html__( 'Justify content', 'bricks' ),
			'type'   => 'justify-content',
			'inline' => true,
			'css'    => [
				[
					'property' => 'justify-content',
					'selector' => '',
				],
			],
		];

		$this->controls['layoutAlignItems'] = [
			'group'  => 'layout',
			'label'  => esc_html__( 'Align items', 'bricks' ),
			'type'   => 'align-items',
			'inline' => true,
			'css'    => [
				[
					'property' => 'align-items',
					'selector' => '',
				],
			],
		];

		$this->controls['layoutRowGap'] = [
			'group' => 'layout',
			'label' => esc_html__( 'Row gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'row-gap',
					'selector' => '',
				],
			],
		];

		$this->controls['layoutColumnGap'] = [
			'group' => 'layout',
			'label' => esc_html__( 'Column gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'column-gap',
					'selector' => '',
				],
			],
		];

		$this->controls['itemDirection'] = [
			'group'  => 'item',
			'label'  => esc_html__( 'Direction', 'bricks' ),
			'type'   => 'direction',
			'inline' => true,
			'css'    => [
				[
					'property' => 'flex-direction',
					'selector' => ".{$class_prefix}__item",
				],
			],
		];

		$this->controls['itemAlignItems'] = [
			'group'  => 'item',
			'label'  => esc_html__( 'Align items', 'bricks' ),
			'type'   => 'align-items',
			'inline' => true,
			'css'    => [
				[
					'property' => 'align-items',
					'selector' => ".{$class_prefix}__item",
				],
			],
		];

		$this->controls['itemGap'] = [
			'group' => 'item',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => ".{$class_prefix}__item",
				],
			],
		];

		$item_controls  = $this->generate_standard_controls(
			'item',
			".{$class_prefix}__item",
			[ 'padding', 'background-color', 'border', 'box-shadow' ]
		);
		$item_controls  = $this->controls_grouping( $item_controls, 'item' );
		$this->controls = array_merge( $this->controls, $item_controls );

		$this->controls['labelWidth'] = [
			'group' => 'label',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => ".{$class_prefix}__label",
				],
			],
		];

		$this->controls['labelFlexGrow'] = [
			'group'       => 'label',
			'label'       => esc_html__( 'Flex grow', 'bricks' ),
			'type'        => 'number',
			'min'         => 0,
			'placeholder' => 0,
			'css'         => [
				[
					'property' => 'flex-grow',
					'selector' => ".{$class_prefix}__label",
				],
			],
		];

		$this->controls['labelFlexShrink'] = [
			'group'       => 'label',
			'label'       => esc_html__( 'Flex shrink', 'bricks' ),
			'type'        => 'number',
			'min'         => 0,
			'placeholder' => 1,
			'css'         => [
				[
					'property' => 'flex-shrink',
					'selector' => ".{$class_prefix}__label",
				],
			],
		];

		$label_controls = $this->generate_standard_controls(
			'label',
			".{$class_prefix}__label",
			[ 'margin', 'padding', 'background-color', 'border', 'box-shadow', 'typography' ]
		);
		$label_controls = $this->controls_grouping( $label_controls, 'label' );
		$this->controls = array_merge( $this->controls, $label_controls );

		$this->controls['valueWidth'] = [
			'group' => 'value',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => ".{$class_prefix}__value",
				],
			],
		];

		$this->controls['valueFlexGrow'] = [
			'group'       => 'value',
			'label'       => esc_html__( 'Flex grow', 'bricks' ),
			'type'        => 'number',
			'min'         => 0,
			'placeholder' => 0,
			'css'         => [
				[
					'property' => 'flex-grow',
					'selector' => ".{$class_prefix}__value",
				],
			],
		];

		$this->controls['valueFlexShrink'] = [
			'group'       => 'value',
			'label'       => esc_html__( 'Flex shrink', 'bricks' ),
			'type'        => 'number',
			'min'         => 0,
			'placeholder' => 1,
			'css'         => [
				[
					'property' => 'flex-shrink',
					'selector' => ".{$class_prefix}__value",
				],
			],
		];

		$value_controls = $this->generate_standard_controls(
			'value',
			".{$class_prefix}__value",
			[ 'margin', 'padding', 'background-color', 'border', 'box-shadow', 'typography' ]
		);
		$value_controls = $this->controls_grouping( $value_controls, 'value' );
		$this->controls = array_merge( $this->controls, $value_controls );

		$this->controls['separatorTypography'] = [
			'group' => 'separator',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => ".{$class_prefix}__separator",
				],
			],
		];

		$this->controls['separatorMargin'] = [
			'group' => 'separator',
			'label' => esc_html__( 'Margin', 'bricks' ),
			'type'  => 'spacing',
			'css'   => [
				[
					'property' => 'margin',
					'selector' => ".{$class_prefix}__separator",
				],
			],
		];
	}

	/**
	 * Render element.
	 *
	 * @since 2.4
	 */
	public function render() {
		$loop_object_type = Query::is_looping() ? Query::get_query_object_type() : false;

		if ( $loop_object_type !== $this->get_item_data_query_type() ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder(
					[
						'title' => $this->get_query_loop_placeholder(),
					]
				);
			}

			return;
		}

		$item_data = $this->get_item_data( Query::get_loop_object() );

		if ( ! $item_data ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder(
					[
						'title' => $this->get_empty_item_data_placeholder(),
					]
				);
			}

			return;
		}

		$settings        = $this->settings;
		$class_prefix    = $this->get_item_data_class_prefix();
		$show_labels     = ! empty( $settings['showLabels'] );
		$show_separators = ! empty( $settings['showSeparators'] );
		$label_suffix    = isset( $settings['labelSuffix'] ) && is_scalar( $settings['labelSuffix'] ) ? (string) $settings['labelSuffix'] : '';
		$separator       = isset( $settings['separatorText'] ) && is_scalar( $settings['separatorText'] ) ? (string) $settings['separatorText'] : '';
		$items           = [];

		foreach ( $item_data as $data ) {
			$item_classes = [ "{$class_prefix}__item" ];
			$modifier     = sanitize_html_class( $data['slug'] );

			if ( $modifier !== '' ) {
				$item_classes[] = "{$class_prefix}__item--" . $modifier;
			}

			$item = sprintf(
				'<div class="%1$s" data-key="%2$s">',
				esc_attr( implode( ' ', $item_classes ) ),
				esc_attr( $data['slug'] )
			);

			// Text controls deliberately allow entities such as &middot; while escaping HTML tags.
			if ( $show_labels ) {
				$item .= '<span class="' . esc_attr( "{$class_prefix}__label" ) . '">' . esc_html( $data['key'] . $label_suffix ) . '</span>';
			}

			$item   .= '<div class="' . esc_attr( "{$class_prefix}__value" ) . '">' . wp_kses_post( $data['display'] ) . '</div></div>';
			$items[] = $item;
		}

		$separator_html = $show_separators && $separator !== ''
			? '<span class="' . esc_attr( "{$class_prefix}__separator" ) . '" aria-hidden="true">' . esc_html( $separator ) . '</span>'
			: '';

		$this->set_attribute( '_root', 'class', $class_prefix );

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( $separator_html, $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
}
