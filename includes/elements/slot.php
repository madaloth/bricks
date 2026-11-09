<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Slot extends Element {
	public $name          = 'slot';
	public $icon          = 'ti-widgetized';
	public $vue_component = 'bricks-nestable';
	public $nestable      = true;

	public function get_label() {
		return esc_html__( 'Slot', 'bricks' );
	}

	public function get_keywords() {
		return [ 'slot', 'component', 'nestable', 'placeholder' ];
	}

	public function set_controls_before() {
		// Override to prevent inheriting base controls
	}

	public function set_controls() {
		$this->controls['slotInfo'] = [
			'type'    => 'info',
			'content' => esc_html__( 'This slot element acts as a placeholder for elements added in an instance of this component.', 'bricks' ),
		];

	}

	public function set_controls_after() {
		// Override to prevent inheriting base controls
	}


	public function render() {
		$component_instance = $this->find_component_instance_with_slot();

		if ( ! $component_instance ) {
			return;
		}

		$slot_children = $component_instance['slotChildren'][ $this->id ] ?? [];

		if ( empty( $slot_children ) || ! is_array( $slot_children ) ) {
			return;
		}

		foreach ( $slot_children as $child_id ) {
			$child_element = Frontend::$elements[ $child_id ] ?? false;

			if ( $child_element ) {
				echo Frontend::render_element( $child_element );
			}
		}
	}

	/**
	 * Find the component instance that contains this slot
	 *
	 * @since 2.2
	 */
	private function find_component_instance_with_slot() {
		// Get the instanceId from the slot's element data
		$instance_id = $this->element['instanceId'] ?? null;

		foreach ( Frontend::$elements as $element ) {
			if ( ! empty( $element['cid'] ) && ! empty( $element['slotChildren'] ) ) {
				if ( isset( $element['slotChildren'][ $this->id ] ) ) {
					if ( $instance_id && $element['id'] === $instance_id ) {
						return $element;
					}
				}
			}
		}

		return null;
	}

}
