<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce checkout steps navigation for Checkout v2.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_Steps_Nav extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-steps-nav';
	public $icon            = 'ti-menu-alt';
	public $nestable        = true;
	public $scripts         = [ 'bricksWooInitCheckoutSteps' ];
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Checkout steps navigation', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'steps', 'navigation', 'multistep' ];
	}

	public function set_control_groups() {
		$this->control_groups['wrapper'] = [
			'title' => esc_html__( 'Wrapper', 'bricks' ),
		];

		$this->control_groups['item'] = [
			'title' => esc_html__( 'Items', 'bricks' ),
		];

		$this->control_groups['stepNumber'] = [
			'title'    => esc_html__( 'Step number', 'bricks' ),
			'required' => [ 'showStepNumber', '!=', '' ],
		];

		$this->control_groups['itemActive'] = [
			'title' => esc_html__( 'Items', 'bricks' ) . ' - ' . esc_html__( 'Active', 'bricks' ),
		];

		$this->control_groups['itemCompleted'] = [
			'title' => esc_html__( 'Items', 'bricks' ) . ' - ' . esc_html__( 'Completed', 'bricks' ),
		];
	}

	public function set_controls() {
		$this->controls['showStepNumber'] = [
			'label' => esc_html__( 'Show step number', 'bricks' ),
			'type'  => 'checkbox',
		];

		$this->controls['showStepNumberInfo'] = [
			'type'    => 'info',
			/* translators: %s: {woo_checkout_current_step_number} dynamic tag. */
			'content' => sprintf(
				esc_html__( 'This option uses CSS counters and pseudo-elements to render step numbers instantly. In contrast, the %s dynamic tag relies on JavaScript and may briefly flash or update after page load.', 'bricks' ),
				'{woo_checkout_current_step_number}'
			),
		];

		$this->controls['wrapperDisplay'] = [
			'group'   => 'wrapper',
			'label'   => esc_html__( 'Display', 'bricks' ),
			'type'    => 'select',
			'inline'  => true,
			'options' => [
				'flex'        => 'flex',
				'inline-flex' => 'inline-flex',
				'grid'        => 'grid',
				'inline-grid' => 'inline-grid',
				'block'       => 'block',
			],
			'css'     => [
				[
					'property' => 'display',
					'selector' => '.brx-checkout-steps-nav-list',
				],
			],
		];

		$this->controls['wrapperDirection'] = [
			'group'  => 'wrapper',
			'label'  => esc_html__( 'Direction', 'bricks' ),
			'type'   => 'direction',
			'inline' => true,
			'css'    => [
				[
					'property' => 'flex-direction',
					'selector' => '.brx-checkout-steps-nav-list',
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
					'selector' => '.brx-checkout-steps-nav-list',
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
					'selector' => '.brx-checkout-steps-nav-list',
				],
			],
		];

		$this->controls['wrapperWrap'] = [
			'group'   => 'wrapper',
			'label'   => esc_html_x( 'Wrap', 'CSS flex property', 'bricks' ),
			'type'    => 'select',
			'inline'  => true,
			'options' => [
				'nowrap'       => esc_html__( 'No wrap', 'bricks' ),
				'wrap'         => esc_html_x( 'Wrap', 'CSS flex property', 'bricks' ),
				'wrap-reverse' => esc_html__( 'Wrap reverse', 'bricks' ),
			],
			'css'     => [
				[
					'property' => 'flex-wrap',
					'selector' => '.brx-checkout-steps-nav-list',
				],
			],
		];

		$this->controls['wrapperWidth'] = [
			'group' => 'wrapper',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => '.brx-checkout-steps-nav-list',
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
					'selector' => '.brx-checkout-steps-nav-list',
				],
			],
		];

		$wrapper_controls = $this->generate_standard_controls( 'wrapper', '.brx-checkout-steps-nav-list' );
		$wrapper_controls = $this->controls_grouping( $wrapper_controls, 'wrapper' );
		$this->controls   = array_merge( $this->controls, $wrapper_controls );

		$item_controls  = $this->generate_standard_controls( 'item', '.brxe-woocommerce-checkout-step-nav-item', [ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ] );
		$item_controls  = $this->controls_grouping( $item_controls, 'item' );
		$this->controls = array_merge( $this->controls, $item_controls );

		$this->controls['itemTypography'] = [
			'group' => 'item',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brxe-woocommerce-checkout-step-nav-item',
				],
			],
		];

		$this->controls['stepNumberPlacement'] = [
			'group'    => 'stepNumber',
			'label'    => esc_html__( 'Placement', 'bricks' ),
			'type'     => 'select',
			'inline'   => true,
			'options'  => [
				'-1' => esc_html__( 'Inline start', 'bricks' ),
				'99' => esc_html__( 'Inline end', 'bricks' ),
			],
			'css'      => [
				[
					'property' => 'order',
					'selector' => '.brx-checkout-steps-nav-list[data-brx-show-steps] .brxe-woocommerce-checkout-step-nav-item::before',
				],
			],
			'required' => [ 'showStepNumber', '!=', '' ],
		];

		$this->controls['stepNumberInlineOffsetStart'] = [
			'group'    => 'stepNumber',
			'label'    => esc_html__( 'Inline start offset', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'margin-inline-start',
					'selector' => '.brx-checkout-steps-nav-list[data-brx-show-steps] .brxe-woocommerce-checkout-step-nav-item::before',
				],
			],
			'required' => [ 'showStepNumber', '!=', '' ],
		];

		$this->controls['stepNumberInlineOffsetEnd'] = [
			'group'    => 'stepNumber',
			'label'    => esc_html__( 'Inline end offset', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'margin-inline-end',
					'selector' => '.brx-checkout-steps-nav-list[data-brx-show-steps] .brxe-woocommerce-checkout-step-nav-item::before',
				],
			],
			'required' => [ 'showStepNumber', '!=', '' ],
		];

		$this->controls['stepNumberBlockOffset'] = [
			'group'    => 'stepNumber',
			'label'    => esc_html__( 'Block offset', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'margin-block-start',
					'selector' => '.brx-checkout-steps-nav-list[data-brx-show-steps] .brxe-woocommerce-checkout-step-nav-item::before',
				],
			],
			'required' => [ 'showStepNumber', '!=', '' ],
		];

		$step_number_controls = $this->generate_standard_controls(
			'stepNumber',
			'.brx-checkout-steps-nav-list[data-brx-show-steps] .brxe-woocommerce-checkout-step-nav-item::before',
			[ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ]
		);
		$step_number_controls = $this->controls_grouping( $step_number_controls, 'stepNumber' );

		$this->controls = array_merge( $this->controls, $step_number_controls );

		$this->controls['stepNumberTypography'] = [
			'group' => 'stepNumber',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brx-checkout-steps-nav-list[data-brx-show-steps] .brxe-woocommerce-checkout-step-nav-item::before',
				],
			],
		];

		$item_active_controls = $this->generate_standard_controls(
			'itemActive',
			'.brxe-woocommerce-checkout-step-nav-item.is-active, .brxe-woocommerce-checkout-step-nav-item.brx-builder-active-nav-item',
			[ 'background-color', 'border', 'box-shadow' ]
		);
		$item_active_controls = $this->controls_grouping( $item_active_controls, 'itemActive' );
		$this->controls       = array_merge( $this->controls, $item_active_controls );

		$this->controls['itemActiveTypography'] = [
			'group' => 'itemActive',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brxe-woocommerce-checkout-step-nav-item.is-active, .brxe-woocommerce-checkout-step-nav-item.brx-builder-active-nav-item',
				],
			],
		];

		$item_completed_controls                   = $this->generate_standard_controls(
			'itemCompleted',
			'.brxe-woocommerce-checkout-step-nav-item.is-completed',
			[ 'background-color', 'border', 'box-shadow' ]
		);
		$item_completed_controls                   = $this->controls_grouping( $item_completed_controls, 'itemCompleted' );
		$this->controls                            = array_merge( $this->controls, $item_completed_controls );
		$this->controls['itemCompletedTypography'] = [
			'group' => 'itemCompleted',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.brxe-woocommerce-checkout-step-nav-item.is-completed',
				],
			],
		];
	}

	/**
	 * Get blueprint for one checkout step navigation item.
	 *
	 * @since 2.4
	 *
	 * @param int $index Item index.
	 *
	 * @return array
	 */
	public function get_nestable_item( $index = 0 ) {
		return [
			'name'     => 'woocommerce-checkout-step-nav-item',
			'label'    => sprintf( esc_html__( 'Step item %d', 'bricks' ), $index + 1 ),
			'children' => [
				[
					'name'     => 'icon',
					'label'    => esc_html__( 'Step icon', 'bricks' ),
					'settings' => [
						'icon'     => [
							'library' => 'themify',
							'icon'    => 'ti-check',
						],
						'iconSize' => '0.85em',
					],
				],
				[
					'name'     => 'text-basic',
					'label'    => esc_html__( 'Step label', 'bricks' ),
					'settings' => [
						'text' => sprintf( esc_html__( 'Step %d', 'bricks' ), $index + 1 ),
					],
				],
			],
		];
	}

	/**
	 * Get default navigation items.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public function get_nestable_children() {
		$children      = [];
		$default_count = 3;

		for ( $i = 0; $i < $default_count; $i++ ) {
			$children[] = $this->get_nestable_item( $i );
		}

		return $children;
	}

	public function render() {
		$has_children = ! empty( $this->element['children'] ) && is_array( $this->element['children'] );

		if ( ! $has_children && ! bricks_is_builder() && ! bricks_is_builder_call() ) {
			return $this->render_element_placeholder( esc_html__( ' No navigation items found.', 'bricks' ) );
		}

		$this->set_attribute( '_root', 'class', 'brx-checkout-steps-nav' );
		$this->set_attribute( '_root', 'data-brx-checkout-steps-nav', 'true' );
		$this->set_attribute( 'nav_list', 'class', 'brx-checkout-steps-nav-list' );

		$show_step = ! empty( $this->settings['showStepNumber'] );
		if ( $show_step ) {
			$this->set_attribute( 'nav_list', 'data-brx-show-steps', 'true' );
		}

		echo '<nav ' . $this->render_attributes( '_root' ) . '>';
		echo '<ol ' . $this->render_attributes( 'nav_list' ) . '>';
		echo Frontend::render_children( $this );
		echo '</ol>';
		echo '</nav>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-checkout-steps-nav">
			<nav class="brxe-woocommerce-checkout-steps-nav brx-checkout-steps-nav" data-brx-checkout-steps-nav="true">
				<ol
					class="brx-checkout-steps-nav-list"
					:data-brx-show-steps="element.settings.showStepNumber ? 'true' : null"
				>
					<bricks-element-children
						:element="element"
						:parentComponent="component || parentComponent"
						:instanceId="instanceId"
						:loopId="loopId" />
				</ol>
			</nav>
		</script>
		<?php
	}
}
