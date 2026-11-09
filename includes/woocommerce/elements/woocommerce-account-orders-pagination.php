<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Account_Orders_Pagination extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-orders-pagination';
	public $icon            = 'ti-angle-double-right';
	public $panel_condition = [ [ 'templateType', '=', 'wc_account_orders' ], [ 'wooPage', '=', 'myaccount' ] ];

	public function get_label() {
		return esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Orders pagination', 'bricks' );
	}

	public function set_control_groups() {
		$this->control_groups['pagination'] = [
			'title' => esc_html__( 'Pagination', 'bricks' ),
		];

		$this->control_groups['button'] = [
			'title' => esc_html__( 'Button', 'bricks' ),
		];
	}

	public function set_controls() {
		$this->controls['queryId'] = [
			'tab'              => 'content',
			'label'            => esc_html__( 'Query', 'bricks' ),
			'type'             => 'query-list',
			'inline'           => true,
			'excludeMainQuery' => true,
			'placeholder'      => esc_html__( 'Select', 'bricks' ),
		];

		$this->controls['displayMode'] = [
			'tab'         => 'content',
			'group'       => 'button',
			'label'       => esc_html__( 'Display', 'bricks' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => [
				'text'     => esc_html__( 'Text only', 'bricks' ),
				'textIcon' => esc_html__( 'Text and icon', 'bricks' ),
				'icon'     => esc_html__( 'Icon only', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Text only', 'bricks' ),
		];

		$this->controls['prevIcon'] = [
			'tab'      => 'content',
			'group'    => 'button',
			'label'    => esc_html__( 'Previous icon', 'bricks' ),
			'type'     => 'icon',
			'required' => [ 'displayMode', '!=', [ 'text', '' ] ],
		];

		$this->controls['prevText'] = [
			'tab'         => 'content',
			'group'       => 'button',
			'label'       => esc_html__( 'Previous text', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Previous', 'woocommerce' ),
			'required'    => [ 'displayMode', '!=', 'icon' ],
		];

		$this->controls['nextIcon'] = [
			'tab'      => 'content',
			'group'    => 'button',
			'label'    => esc_html__( 'Next icon', 'bricks' ),
			'type'     => 'icon',
			'required' => [ 'displayMode', '!=', [ 'text', '' ] ],
		];

		$this->controls['nextText'] = [
			'tab'         => 'content',
			'group'       => 'button',
			'label'       => esc_html__( 'Next text', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Next', 'woocommerce' ),
			'required'    => [ 'displayMode', '!=', 'icon' ],
		];

		$this->controls['buttonGap'] = [
			'tab'      => 'content',
			'group'    => 'button',
			'label'    => esc_html__( 'Gap', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'gap',
					'selector' => '&.brx-wc-account-orders-pagination--text-icon .woocommerce-pagination a.woocommerce-button',
				],
			],
			'required' => [ 'displayMode', '=', 'textIcon' ],
		];

		$pagination_controls = $this->generate_standard_controls( 'pagination', '.woocommerce-pagination' );
		$pagination_controls = $this->controls_grouping( $pagination_controls, 'pagination' );

		$this->controls = array_merge( $this->controls, $pagination_controls );

		$button_controls = $this->generate_standard_controls( 'button', '.woocommerce-pagination a.woocommerce-button' );
		$button_controls = $this->controls_grouping( $button_controls, 'button' );

		$this->controls = array_merge( $this->controls, $button_controls );
	}

	public function render() {
		$settings = $this->settings;
		$query_id = $settings['queryId'] ?? false;

		if ( ! $query_id ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'No query selected', 'bricks' ),
				]
			);
		}

		$local_element = Helpers::get_element_data( $this->post_id, $query_id );

		if ( ! $local_element && ! empty( $this->element['instanceId'] ) ) {
			$local_element = Helpers::get_element_data( $this->post_id, $this->element['instanceId'] );

			if ( ! empty( $local_element['element']['id'] ) ) {
				$query_id = $query_id . '-' . $local_element['element']['id'];
			}
		}

		$query_object = Helpers::get_query_object_from_history_or_init( $query_id, $this->post_id );

		if ( ! is_a( $query_object, 'Bricks\Query' ) || $query_object->object_type !== 'wooAccountOrders' ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'This element requires an Account orders query.', 'bricks' ),
				]
			);
		}

		$current_page  = Woocommerce::get_account_orders_current_page();
		$max_num_pages = max( 1, absint( $query_object->max_num_pages ) );

		if ( $max_num_pages <= 1 ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'No pagination results.', 'bricks' ),
					]
				);
			}

			$this->set_attribute( '_root', 'style', 'display: none;' );
		}

		$wp_button_class = wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '';
		$display_mode    = $settings['displayMode'] ?? 'text';
		$display_mode    = in_array( $display_mode, [ 'text', 'textIcon', 'icon' ], true ) ? $display_mode : 'text';
		$prev_label      = ! empty( $settings['prevText'] ) ? $settings['prevText'] : __( 'Previous', 'woocommerce' );
		$next_label      = ! empty( $settings['nextText'] ) ? $settings['nextText'] : __( 'Next', 'woocommerce' );
		$prev_icon       = ! empty( $settings['prevIcon'] ) ? self::render_icon( $settings['prevIcon'] ) : '';
		$next_icon       = ! empty( $settings['nextIcon'] ) ? self::render_icon( $settings['nextIcon'] ) : '';
		$show_icon       = $display_mode !== 'text';
		$show_text       = $display_mode !== 'icon';
		$label_tag       = '<span class="brx-wc-account-orders-pagination__text">%s</span>';
		$prev_text       = $show_icon && $prev_icon ? $prev_icon : '';
		$prev_text      .= $show_text || ( $show_icon && ! $prev_icon ) ? sprintf( $label_tag, esc_html( $prev_label ) ) : '';
		$next_text       = $show_text || ( $show_icon && ! $next_icon ) ? sprintf( $label_tag, esc_html( $next_label ) ) : '';
		$next_text      .= $show_icon && $next_icon ? $next_icon : '';
		$prev_aria_label = ! $show_text && $prev_icon ? ' aria-label="' . esc_attr( wp_strip_all_tags( $prev_label ) ) . '"' : '';
		$next_aria_label = ! $show_text && $next_icon ? ' aria-label="' . esc_attr( wp_strip_all_tags( $next_label ) ) . '"' : '';

		$this->set_attribute( '_root', 'class', 'brx-wc-account-orders-pagination--' . sanitize_html_class( $display_mode === 'textIcon' ? 'text-icon' : $display_mode ) );

		echo "<div {$this->render_attributes( '_root' )}>";
		echo '<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">';

		if ( $current_page !== 1 ) {
			echo '<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button' . esc_attr( $wp_button_class ) . '" href="' . esc_url( wc_get_endpoint_url( 'orders', $current_page - 1 ) ) . '"' . $prev_aria_label . '>' . $prev_text . '</a>';
		}

		if ( $current_page !== intval( $max_num_pages ) ) {
			echo '<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button' . esc_attr( $wp_button_class ) . '" href="' . esc_url( wc_get_endpoint_url( 'orders', $current_page + 1 ) ) . '"' . $next_aria_label . '>' . $next_text . '</a>';
		}

		echo '</div>';
		echo '</div>';
	}
}
