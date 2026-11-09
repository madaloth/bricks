<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Toggle_Mode extends Element {
	public $category = 'general';
	public $name     = 'toggle-mode';
	public $icon     = 'fas fa-toggle-off';
	public $scripts  = [ 'bricksToggleMode' ];

	public function get_label() {
		return esc_html__( 'Toggle', 'bricks' ) . ' - ' . esc_html__( 'Mode', 'bricks' );
	}

	public function get_keywords() {
		return [ 'light', 'dark', 'theme', 'mode', 'toggle' ];
	}

	public function set_controls() {
		// Info control
		$this->controls['info'] = [
			'content' => esc_html__( 'Builder', 'bricks' ) . ': ' . esc_html__( 'Toggle between light and dark mode using the sun/moon icon in the builder toolbar. The icon only appears if dark mode is enabled for at least one color in the color manager.', 'bricks' ),
			'type'    => 'info',
		];

		// Light mode controls
		$this->controls['lightSep'] = [
			'label' => esc_html__( 'Mode', 'bricks' ) . ': ' . esc_html_x( 'Light', 'color', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['icon'] = [
			'label' => esc_html__( 'Icon', 'bricks' ),
			'type'  => 'icon',
		];

		$this->controls['iconColor'] = [
			'label' => esc_html__( 'Color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'color',
					'selector' => '.toggle.light > *',
				],
				[
					'property' => 'fill',
					'selector' => '.toggle.light > *',
				],
			],
		];

		$this->controls['iconSize'] = [
			'label' => esc_html__( 'Size', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'font-size',
					'selector' => '.toggle.light > *',
				],
			],
		];

		// Dark mode controls
		$this->controls['darkSep'] = [
			'label' => esc_html__( 'Mode', 'bricks' ) . ': ' . esc_html__( 'Dark', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['iconDark'] = [
			'label' => esc_html__( 'Icon', 'bricks' ) . ' ' . esc_html__( 'Dark', 'bricks' ),
			'type'  => 'icon',
		];

		$this->controls['iconDarkColor'] = [
			'label' => esc_html__( 'Color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'color',
					'selector' => '.toggle.dark > *',
				],
				[
					'property' => 'fill',
					'selector' => '.toggle.dark > *',
				],
			],
		];

		$this->controls['iconDarkSize'] = [
			'label' => esc_html__( 'Size', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'font-size',
					'selector' => '.toggle.dark > *',
				],
			],
		];

		// Accessibility
		$this->controls['accessibilitySep'] = [
			'label' => esc_html__( 'Accessibility', 'bricks' ),
			'type'  => 'separator',
		];

		// aria-label control for accessibility
		$this->controls['ariaLabel'] = [
			'label'       => 'aria-label',
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Toggle mode', 'bricks' ),
		];
	}

	public function render() {
		$settings = $this->settings;

		$icon_light = ! empty( $settings['icon'] ) ? self::render_icon( $settings['icon'] ) : Helpers::file_get_contents( BRICKS_PATH_ASSETS . 'svg/frontend/mode-light.svg' );
		$icon_dark  = ! empty( $settings['iconDark'] ) ? self::render_icon( $settings['iconDark'] ) : Helpers::file_get_contents( BRICKS_PATH_ASSETS . 'svg/frontend/mode-dark.svg' );

		$this->set_attribute( '_root', 'aria-label', ! empty( $settings['ariaLabel'] ) ? esc_attr( $settings['ariaLabel'] ) : esc_html__( 'Toggle mode', 'bricks' ) );

		// CSS will handle showing/hiding the correct icon based on the current mode
		echo "<button {$this->render_attributes( '_root' )}>";
		echo '<span class="toggle light">' . $icon_light . '</span>';
		echo '<span class="toggle dark">' . $icon_dark . '</span>';
		echo '</button>';
	}
}
