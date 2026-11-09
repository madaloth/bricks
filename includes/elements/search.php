<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Search extends Element {
	public $block        = 'core/search';
	public $category     = 'wordpress';
	public $name         = 'search';
	public $icon         = 'ti-search';
	public $css_selector = 'form';

	public function get_label() {
		return esc_html__( 'Search', 'bricks' );
	}

	public function set_control_groups() {
		$this->control_groups['input'] = [
			'title' => esc_html__( 'Input', 'bricks' ),
		];

		$this->control_groups['button'] = [
			'title' => esc_html__( 'Button', 'bricks' ) . ' / ' . esc_html__( 'Icon', 'bricks' ),
		];

		$this->control_groups['overlay'] = [
			'title' => esc_html__( 'Overlay', 'bricks' ),
		];
	}

	public function set_controls() {
		$this->controls['searchType'] = [
			'label'       => esc_html__( 'Type', 'bricks' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => [
				'input'   => esc_html__( 'Input', 'bricks' ),
				'overlay' => esc_html__( 'Icon', 'bricks' ) . ' & ' . esc_html__( 'Overlay', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Input', 'bricks' ),
		];

		$this->controls['ariaLabel'] = [
			'label'       => 'aria-label',
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Toggle search', 'bricks' ),
			'required'    => [ 'searchType', '=', 'overlay' ],
		];

		$this->controls['actionURL'] = [
			'label'       => esc_html__( 'Action URL', 'bricks' ),
			'type'        => 'text',
			'placeholder' => home_url( '/' ),
			'description' => esc_html__( 'Leave empty to use the default WordPress home URL.', 'bricks' ),
		];

		$this->controls['additionalParams'] = [
			'label'         => esc_html__( 'Additional parameters', 'bricks' ),
			'type'          => 'repeater',
			'titleProperty' => 'paramKey',
			'fields'        => [
				'paramKey'   => [
					'label' => esc_html_x( 'Key', 'data key', 'bricks' ),
					'type'  => 'text',
				],
				'paramValue' => [
					'label' => esc_html__( 'Value', 'bricks' ),
					'type'  => 'text',
				],
			],
			'description'   => esc_html__( 'Added to the search form as hidden input fields.', 'bricks' ),
		];

		// INPUT

		$this->controls['inputHeight'] = [
			'group' => 'input',
			'label' => esc_html__( 'Height', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'height',
					'selector' => 'input[type=search]',
				],
			],
		];

		$this->controls['inputWidth'] = [
			'group' => 'input',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => 'input[type=search]', // Type: Input
				],
				[
					'property' => 'max-width',
					'selector' => '.bricks-search-overlay .bricks-search-form', // Type: Overlay
				],
			],
		];

		$this->controls['placeholder'] = [
			'group'       => 'input',
			'label'       => esc_html__( 'Placeholder', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Search ...', 'bricks' ),
		];

		$this->controls['placeholderColor'] = [
			'group' => 'input',
			'label' => esc_html__( 'Placeholder color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'color',
					'selector' => 'input[type=search]::placeholder',
				],
			],
		];

		$this->controls['inputBackgroundColor'] = [
			'group' => 'input',
			'label' => esc_html__( 'Background color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => 'input[type=search]',
				],
			],
		];

		$this->controls['inputBorder'] = [
			'group' => 'input',
			'label' => esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'property' => 'border',
					'selector' => 'input[type=search]',
				],
			],
		];

		$this->controls['inputBoxShadow'] = [
			'group' => 'input',
			'label' => esc_html__( 'Box shadow', 'bricks' ),
			'type'  => 'box-shadow',
			'css'   => [
				[
					'property' => 'box-shadow',
					'selector' => 'input[type=search]',
				],
			],
		];

		$this->controls['inputTypography'] = [
			'group' => 'input',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => 'input[type=search]',
				],
			],
		];

		$this->controls['showLabel'] = [
			'group'   => 'input',
			'label'   => esc_html__( 'Show label', 'bricks' ),
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['labelText'] = [
			'group'       => 'input',
			'label'       => esc_html__( 'Label text', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( 'Search', 'bricks' ),
			'required'    => [ 'showLabel', '=', true ],
		];

		$this->controls['labelTypography'] = [
			'group'    => 'input',
			'label'    => esc_html__( 'Label typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'property' => 'typography',
					'selector' => 'label',
				],
			],
			'required' => [ 'showLabel', '=', true ],
		];

		// BUTTON

		$this->controls['buttonAriaLabelInfo'] = [
			'group'    => 'button',
			'content'  => esc_html__( 'You have set an icon, but no text. Please provide the "aria-label" for accessibility.', 'bricks' ),
			'type'     => 'info',
			'required' => [
				[ 'buttonAriaLabel', '=', '' ],
				[ 'buttonText', '=', '' ],
				[ 'icon', '!=', '' ],
			],
		];

		$this->controls['buttonAriaLabel'] = [
			'group'  => 'button',
			'label'  => 'aria-label',
			'type'   => 'text',
			'inline' => true,
		];

		$this->controls['buttonText'] = [
			'group'  => 'button',
			'label'  => esc_html__( 'Text', 'bricks' ),
			'type'   => 'text',
			'inline' => true,
		];

		$this->controls['icon'] = [
			'group' => 'button',
			'label' => esc_html__( 'Icon', 'bricks' ),
			'type'  => 'icon',
		];

		$this->controls['buttonPadding'] = [
			'group' => 'button',
			'label' => esc_html__( 'Padding', 'bricks' ),
			'type'  => 'spacing',
			'css'   => [
				[
					'property' => 'padding',
					'selector' => 'button',
				],
			],
		];

		$this->controls['iconHeight'] = [
			'group' => 'button',
			'label' => esc_html__( 'Height', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'height',
					'selector' => 'button',
				],
			],
		];

		$this->controls['iconWidth'] = [
			'group' => 'button',
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => 'button',
				],
			],
		];

		$this->controls['iconBackgroundColor'] = [
			'group' => 'button',
			'label' => esc_html__( 'Background color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => 'button',
				],
			],
		];

		$this->controls['iconBorder'] = [
			'group' => 'button',
			'label' => esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'property' => 'border',
					'selector' => 'button',
				],
			],
		];

		$this->controls['iconBoxShadow'] = [
			'group' => 'button',
			'label' => esc_html__( 'Box shadow', 'bricks' ),
			'type'  => 'box-shadow',
			'css'   => [
				[
					'property' => 'box-shadow',
					'selector' => 'button',
				],
			],
		];

		$this->controls['iconTypography'] = [
			'group'   => 'button',
			'label'   => esc_html__( 'Typography', 'bricks' ),
			'type'    => 'typography',
			'css'     => [
				[
					'property' => 'font',
					'selector' => 'button',
				],
			],
			'exclude' => [ 'none' ],
		];

		// SEARCH OVERLAY

		$this->controls['overlayFormDirection'] = [
			'group'   => 'overlay',
			'label'   => esc_html__( 'Direction', 'bricks' ) . ' (' . esc_html__( 'Form', 'bricks' ) . ')',
			'inline'  => true,
			'tooltip' => [
				'content'  => 'flex-direction',
				'position' => 'top-left',
			],
			'type'    => 'direction',
			'css'     => [
				[
					'property' => 'flex-direction',
					'selector' => '.bricks-search-overlay form',
				],
			],
		];

		$this->controls['overlayFormGap'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'gap',
					'selector' => '.bricks-search-overlay form',
				],
			],
		];

		$this->controls['searchOverlayTitle'] = [
			'group'   => 'overlay',
			'label'   => esc_html__( 'Title', 'bricks' ),
			'type'    => 'text',
			'inline'  => true,
			'default' => esc_html__( 'Search site', 'bricks' ),
		];

		$this->controls['searchOverlayTitleTag'] = [
			'group'       => 'overlay',
			'label'       => esc_html__( 'Title tag', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => 'h4',
		];

		$this->controls['searchOverlayTitleTypography'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Title typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.title',
				],
			],
		];

		$this->controls['searchOverlayBackground'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Background', 'bricks' ),
			'type'  => 'background',
			'css'   => [
				[
					'property' => 'background',
					'selector' => '.bricks-search-overlay',
				],
			],
		];

		$this->controls['searchOverlayBackgroundOverlay'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Background', 'bricks' ) . ': ' . esc_html__( 'Overlay', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => '.bricks-search-overlay:after',
				],
			],
		];

		// OVERLAY BUTTON
		$this->controls['overlayIconWidth'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
					'selector' => '.bricks-search-overlay button[type="submit"]',
				],
			],
		];

		$this->controls['overlayButtonPadding'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Padding', 'bricks' ),
			'type'  => 'spacing',
			'css'   => [
				[
					'property' => 'padding',
					'selector' => '.bricks-search-overlay button[type="submit"]',
				],
			],
		];

		$this->controls['overlayButtonBackground'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Background color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => '.bricks-search-overlay button[type="submit"]',
				],
			],
		];

		$this->controls['overlayButtonBorder'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'property' => 'border',
					'selector' => '.bricks-search-overlay button[type="submit"]',
				],
			],
		];

		$this->controls['overlayButtonTypography'] = [
			'group' => 'overlay',
			'label' => esc_html__( 'Button', 'bricks' ) . ': ' . esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '.bricks-search-overlay button[type="submit"]',
				],
			],
		];
	}

	public function render() {
		$settings = $this->settings;

		// Keep dialog and form labels local to this rendered component or query-loop item.
		$element_id = Query::is_any_looping() ? Helpers::generate_random_id( false ) : $this->uid;

		$search_type      = isset( $settings['searchType'] ) ? $settings['searchType'] : 'input';
		$icon             = isset( $settings['icon'] ) ? self::render_icon( $settings['icon'] ) : false;
		$pre_search_value = ''; // Will be using in searchform.php @since 1.9.5

		// Parse additionalParams (@since 1.9.5)
		if ( ! empty( $settings['additionalParams'] ) && ( $search_type === 'input' || $icon ) ) {
			$additional_params = [];

			foreach ( $settings['additionalParams'] as $param ) {
				// Resolve before sanitizing and escaping the hidden input attributes (#86cb2uvah).
				$key   = $this->render_dynamic_data( $param['paramKey'] ?? '' );
				$value = $this->render_dynamic_data( $param['paramValue'] ?? '' );

				$key   = sanitize_text_field( $key );
				$value = sanitize_text_field( $value );

				if ( empty( $key ) || empty( $value ) ) {
					continue;
				}

				// If user predefined search value, store it for later use
				if ( $key === 's' ) {
					$pre_search_value = $value;
					continue;
				}

				$additional_params[ $key ] = $value;
			}

			// Overwrite additionalParams
			$settings['additionalParams'] = $additional_params;
		}

		echo "<div {$this->render_attributes( '_root' )}>";

		if ( $search_type === 'input' ) {
			// Use include to pass $settings
			include locate_template( 'searchform.php' );
		} else {
			// Return: No icon set
			if ( ! $icon ) {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'No icon selected.', 'bricks' )
					]
				);
			}

			/**
			 * Resolve overlay-only settings after the mode and icon checks. Apart from
			 * avoiding unused echo callbacks, this preserves quotes until final escaping.
			 *
			 * @since 2.3.12
			 */
			$configured_title = isset( $settings['searchOverlayTitle'] )
				? $this->render_dynamic_data( $settings['searchOverlayTitle'] )
				: '';
			$search_title     = trim( (string) $configured_title ) !== '' ? esc_html( $configured_title ) : esc_html__( 'Search site', 'bricks' );
			$search_title_tag = isset( $settings['searchOverlayTitleTag'] )
				? Helpers::sanitize_html_tag( $this->render_dynamic_data( $settings['searchOverlayTitleTag'] ), 'h4' )
				: 'h4';
			$aria_label       = isset( $settings['ariaLabel'] )
				? $this->render_dynamic_data( $settings['ariaLabel'] )
				: esc_html__( 'Toggle search', 'bricks' );

			$overlay_id       = "brxe-{$element_id}-search-overlay";
			$overlay_title_id = "brxe-{$element_id}-search-title";

			echo '<button aria-controls="' . esc_attr( $overlay_id ) . '" aria-expanded="false" aria-label="' .
				esc_attr( $aria_label ) . '" class="toggle">' . $icon . '</button>';

			unset( $settings['icon'] );
			?>
			<div
				aria-hidden="true"
				aria-labelledby="<?php echo esc_attr( $overlay_title_id ); ?>"
				aria-modal="true"
				class="bricks-search-overlay"
				id="<?php echo esc_attr( $overlay_id ); ?>"
				role="dialog"
			>
				<div class="bricks-search-inner">
					<?php
					echo "<$search_title_tag class=\"title\" id=\"" . esc_attr( $overlay_title_id ) . "\">$search_title</$search_title_tag>";

					// Use include to pass $settings
					include locate_template( 'searchform.php' );
					?>
				</div>

				<?php echo '<button aria-label="' . esc_html__( 'Close search', 'bricks' ) . '" class="close">×</button>'; ?>
			</div>
			<?php
		}

		echo '</div>';
	}

	public function convert_element_settings_to_block( $settings ) {
		$attributes = [];

		if ( isset( $settings['inputWidth'] ) ) {
			$attributes['width'] = $settings['inputWidth'];
		}

		if ( isset( $settings['placeholder'] ) ) {
			$attributes['placeholder'] = $settings['placeholder'];
		}

		if ( isset( $settings['icon'] ) ) {
			$attributes['buttonUseIcon'] = true;
		}

		if ( isset( $settings['_cssClasses'] ) ) {
			$attributes['className'] = $settings['_cssClasses'];
		}

		$block = [
			'blockName'    => $this->block,
			'attrs'        => $attributes,
			'innerContent' => [],
		];

		return $block;
	}

	public function convert_block_to_element_settings( $block, $attributes ) {
		$element_settings = [];

		if ( isset( $attributes['width'] ) ) {
			$element_settings['inputWidth'] = $attributes['width'] . 'px';
		}

		if ( isset( $attributes['placeholder'] ) ) {
			$element_settings['placeholder'] = $attributes['placeholder'];
		}

		if ( isset( $attributes['buttonUseIcon'] ) ) {
			$element_settings['icon'] = [
				'library' => 'themify',
				'icon'    => 'ti-search',
			];
		}

		if ( isset( $attributes['className'] ) ) {
			$element_settings['_cssClasses'] = $attributes['className'];
		}

		return $element_settings;
	}
}
