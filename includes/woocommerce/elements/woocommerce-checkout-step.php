<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce checkout step wrapper for Checkout v2.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_Step extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-step';
	public $icon            = 'ti-layout-tab';
	public $nestable        = true;
	public $vue_component   = 'bricks-nestable';
	public $scripts         = [ 'bricksWooInitCheckoutSteps' ];
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Checkout step', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'step', 'wizard', 'multistep' ];
	}

	public function set_controls() {
		$this->controls['stepId'] = [
			'label'       => esc_html__( 'Step ID', 'bricks' ),
			'type'        => 'text',
			'placeholder' => 'billing',
			'description' => esc_html__( 'Used by navigation and interactions. Leave empty to auto-generate from the element ID.', 'bricks' ),
		];

		$this->controls['stepLabel'] = [
			'label'       => esc_html__( 'Step label', 'bricks' ),
			'type'        => 'text',
			'placeholder' => esc_html_x( 'Step', 'checkout step', 'bricks' ),
		];

		$this->controls['allowDirectEntry'] = [
			'label'       => esc_html__( 'Allow direct entry', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Allow navigation to this step even if previous steps are incomplete.', 'bricks' ),
		];

		$this->controls['validateBeforeLeave'] = [
			'label'       => esc_html__( 'Validate before leave', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Require the current step to pass validation before moving forward. Backward is always allowed.', 'bricks' ),
		];

		$this->controls['autoFocusFirstField'] = [
			'label'       => esc_html__( 'Auto focus first field', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Focus the first available field when this step becomes active.', 'bricks' ),
		];
	}

	/**
	 * Resolve this step's runtime ID.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private function get_step_id() {
		$step_id = ! empty( $this->settings['stepId'] ) ? sanitize_title( $this->settings['stepId'] ) : sanitize_title( $this->uid ?? '' );

		return $step_id ? $step_id : sanitize_title( $this->element['id'] );
	}

	/**
	 * Check whether this step is the first checkout-step child in its parent.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private function is_initial_step() {
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
		$step_id           = $this->get_step_id();
		$step_label        = ! empty( $this->settings['stepLabel'] ) ? $this->settings['stepLabel'] : esc_html_x( 'Step', 'checkout step', 'bricks' );
		$is_initial        = $this->is_initial_step();
		$allow_direct      = ! empty( $this->settings['allowDirectEntry'] );
		$validate_on_leave = ! empty( $this->settings['validateBeforeLeave'] );
		$auto_focus_first  = ! empty( $this->settings['autoFocusFirstField'] );

		$this->set_attribute( '_root', 'class', 'brx-checkout-step' );
		$this->set_attribute( '_root', 'class', $is_initial ? 'brx-checkout-step--active' : 'brx-checkout-step--inactive' );
		$this->set_attribute( '_root', 'data-brx-checkout-step', 'true' );
		$this->set_attribute( '_root', 'data-step-id', $step_id );
		$this->set_attribute( '_root', 'data-step-label', wp_strip_all_tags( $step_label ) );
		$this->set_attribute( '_root', 'data-allow-direct-entry', $allow_direct ? 'true' : 'false' );
		$this->set_attribute( '_root', 'data-validate-before-leave', $validate_on_leave ? 'true' : 'false' );
		$this->set_attribute( '_root', 'data-auto-focus-first-field', $auto_focus_first ? 'true' : 'false' );
		$this->set_attribute( '_root', 'aria-hidden', $is_initial ? 'false' : 'true' );

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Frontend::render_children( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
}
