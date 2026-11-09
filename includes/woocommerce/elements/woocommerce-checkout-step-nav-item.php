<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce checkout step navigation item for Checkout v2.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_Step_Nav_Item extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-step-nav-item';
	public $icon            = 'ti-layout-accordion-list';
	public $nestable        = true;
	public $vue_component   = 'bricks-nestable';
	public $scripts         = [ 'bricksWooInitCheckoutSteps' ];
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Checkout step nav item', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'step', 'navigation', 'item', 'multistep' ];
	}

	public function set_controls() {
		$this->controls['styleInfo'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Style navigation items on the parent Checkout steps navigation element.', 'bricks' ),
		];
	}

	/**
	 * Get default child structure for a nav item.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_default_child_elements() {
		return [
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
					'text' => esc_html_x( 'Step', 'checkout step', 'bricks' ),
				],
			],
		];
	}

	public function get_nestable_children() {
		return $this->get_default_child_elements();
	}

	/**
	 * Check whether this nav item is the first sibling nav item.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private function is_initial_item() {
		$parent_id      = $this->element['parent'] ?? false;
		$parent_element = $parent_id ? ( Frontend::$elements[ $parent_id ] ?? false ) : false;
		$children       = ! empty( $parent_element['children'] ) && is_array( $parent_element['children'] ) ? $parent_element['children'] : [];

		foreach ( $children as $child_id ) {
			$child = Frontend::$elements[ $child_id ] ?? false;

			if ( ! $child || ( $child['name'] ?? '' ) !== $this->name ) {
				continue;
			}

			return ( $child['id'] ?? '' ) === ( $this->element['id'] ?? '' );
		}

		return true;
	}

	public function render() {
		$is_initial = $this->is_initial_item();

		$this->set_attribute( '_root', 'class', 'brx-checkout-step-nav-item' );
		if ( $is_initial ) {
			$this->set_attribute( '_root', 'class', 'is-active' );
		}
		$this->set_attribute( '_root', 'data-brx-checkout-step-nav-item', 'true' );
		$this->set_attribute( '_root', 'role', 'button' );
		$this->set_attribute( '_root', 'tabindex', '0' );
		$this->set_attribute( '_root', 'aria-disabled', 'false' );
		$this->set_attribute( '_root', 'aria-current', $is_initial ? 'step' : 'false' );

		echo '<li ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Frontend::render_children( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</li>';
	}
}
