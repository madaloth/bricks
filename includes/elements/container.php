<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Container extends Element {
	public $category      = 'layout';
	public $name          = 'container';
	public $icon          = 'ti-layout-width-default';
	public $vue_component = 'bricks-nestable';
	public $nestable      = true;

	/**
	 * Builder-only ACF flexible content loop preview state.
	 *
	 * Tracks which layout branches to show/hide when preview mode is active.
	 *
	 * @since 2.4
	 */
	private $acf_flexible_preview = [
		'enabled'                 => false,
		'loop_id'                 => '',
		'loop_parent_id'          => '',
		'visibility_key'          => '',
		'visibility_key_fallback' => '',
		'loop_index'              => null,
		'first_loop_index'        => null,
		'visibility_collected'    => false,
		'hidden_element_ids'      => [],
	];

	public function get_label() {
		return esc_html__( 'Container', 'bricks' );
	}

	public function get_keywords() {
		return [ 'query', 'loop', 'repeater', 'nestable' ];
	}

	/**
	 * Get the display and grid controls shared with layout element Theme Styles.
	 *
	 * @param string $selector CSS selector.
	 * @return array
	 *
	 * @since 2.4
	 */
	public static function get_display_grid_controls( $selector = '' ) {
		$controls = [];

		$controls['_display'] = [
			'label'     => esc_html__( 'Display', 'bricks' ),
			'type'      => 'select',
			'options'   => [
				'flex'         => 'flex',
				'inline-flex'  => 'inline-flex', // @since 2.4
				'grid'         => 'grid',
				'block'        => 'block',
				'inline-block' => 'inline-block',
				'inline'       => 'inline',
				'none'         => 'none',
			],
			'add'       => true,
			'inline'    => true,
			'lowercase' => true,
			'css'       => [
				[
					'property' => 'display',
					'selector' => $selector,
				],
				/**
				 * Use 'required' property to add CSS rule if display is set to 'grid'
				 *
				 * @prev 1.7.2: Used .brx-grid class on nestable to set align-items to initial.
				 *
				 * @since 1.7.2
				 */
				[
					'selector' => $selector,
					'property' => 'align-items',
					'value'    => 'initial',
					'required' => 'grid',
				],
			],
		];

		/**
		 * Keep the historical `_gridGap` key for saved-data compatibility while
		 * exposing one CSS gap family for flex, inline-flex, and grid. Values are
		 * retained when Display changes so responsive and inherited contexts survive.
		 *
		 * #86c2v7zk1; @since 2.4
		 */
		$controls['_gridGap'] = [
			'label'       => esc_html__( 'Gap', 'bricks' ),
			'tooltip'     => [
				'content'  => esc_html__( 'Shorthand for row-gap and column-gap. Individual values override Gap.', 'bricks' ),
				'position' => 'top-left',
				'length'   => 'large',
			],
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[
					'property' => 'gap', // Modern output; `_gridGap` remains the storage contract.
					'selector' => $selector,
				],
			],
			'placeholder' => '',
			'required'    => [ '_display', '=', [ 'flex', 'inline-flex', 'grid' ] ],
		];

		$controls['_columnGap'] = [
			'label'    => esc_html__( 'Column gap', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'column-gap',
					'selector' => $selector,
				],
			],
			'required' => [ '_display', '=', [ 'flex', 'inline-flex', 'grid' ] ],
		];

		$controls['_rowGap'] = [
			'label'    => esc_html__( 'Row gap', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'row-gap',
					'selector' => $selector,
				],
			],
			'required' => [ '_display', '=', [ 'flex', 'inline-flex', 'grid' ] ],
		];

		$controls['_gridTemplateColumns'] = [
			'label'          => esc_html__( 'Grid template columns', 'bricks' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'css'            => [
				[
					'property' => 'grid-template-columns',
					'selector' => $selector,
				],
			],
			'placeholder'    => '',
			'required'       => [ '_display', '=', 'grid' ],
		];

		$controls['_gridTemplateRows'] = [
			'label'          => esc_html__( 'Grid template rows', 'bricks' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'css'            => [
				[
					'property' => 'grid-template-rows',
					'selector' => $selector,
				],
			],
			'placeholder'    => '',
			'required'       => [ '_display', '=', 'grid' ],
		];

		$controls['_gridAutoColumns'] = [
			'label'          => esc_html__( 'Grid auto columns', 'bricks' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'css'            => [
				[
					'property' => 'grid-auto-columns',
					'selector' => $selector,
				],
			],
			'required'       => [ '_display', '=', 'grid' ],
		];

		$controls['_gridAutoRows'] = [
			'label'          => esc_html__( 'Grid auto rows', 'bricks' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'css'            => [
				[
					'property' => 'grid-auto-rows',
					'selector' => $selector,
				],
			],
			'required'       => [ '_display', '=', 'grid' ],
		];

		$controls['_gridAutoFlow'] = [
			'label'    => esc_html__( 'Grid auto flow', 'bricks' ),
			'type'     => 'select',
			'options'  => [
				'row'    => 'row',
				'column' => 'column',
				'dense'  => 'dense',
			],
			'css'      => [
				[
					'property' => 'grid-auto-flow',
					'selector' => $selector,
				],
			],
			'required' => [ '_display', '=', 'grid' ],
		];

		$controls['_justifyItemsGrid'] = [
			'label'     => esc_html__( 'Justify items', 'bricks' ),
			'type'      => 'justify-content',
			'direction' => 'row',
			'css'       => [
				[
					'property' => 'justify-items',
					'selector' => $selector,
				],
			],
			'required'  => [ '_display', '=', 'grid' ],
		];

		$controls['_alignItemsGrid'] = [
			'label'     => esc_html__( 'Align items', 'bricks' ),
			'type'      => 'align-items',
			'direction' => 'row',
			'css'       => [
				[
					'property' => 'align-items',
					'selector' => $selector,
				],
			],
			'required'  => [ '_display', '=', 'grid' ],
		];

		$controls['_justifyContentGrid'] = [
			'label'     => esc_html__( 'Justify content', 'bricks' ),
			'type'      => 'justify-content',
			'direction' => 'row',
			'css'       => [
				[
					'property' => 'justify-content',
					'selector' => $selector,
				],
			],
			'required'  => [ '_display', '=', 'grid' ],
		];

		$controls['_alignContentGrid'] = [
			'label'     => esc_html__( 'Align content', 'bricks' ),
			'type'      => 'align-items',
			'direction' => 'row',
			'css'       => [
				[
					'property' => 'align-content',
					'selector' => $selector,
				],
			],
			'required'  => [ '_display', '=', 'grid' ],
		];

		return $controls;
	}

	public function set_controls() {
		if ( bricks_is_builder() && ! Builder_Permissions::user_has_permission( 'access_element_content' ) ) {
			$this->controls['infoNoAccess'] = [
				'type'       => 'info',
				'content'    => esc_html__( 'Your builder capability doesn\'t allow you to access these settings.', 'bricks' ),
				'fullAccess' => false,
			];
		}

		/**
		 * Grid item
		 *
		 * Show controls if parent uses display "grid"
		 *
		 * Check via control startsWith '_gridItem'
		 *
		 * @see PanelControl.vue 'settings' watcher
		 * @since 1.6.1
		 */
		$this->controls['_gridItemSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Grid item', 'bricks' ),
		];

		$this->controls['_gridItemColumnSpan'] = [
			'label'          => esc_html__( 'Grid column', 'bricks' ),
			'type'           => 'text',
			'inline'         => true,
			'hasDynamicData' => false,
			'css'            => [
				[
					'property' => 'grid-column',
				],
			],
		];

		$this->controls['_gridItemRowSpan'] = [
			'label'          => esc_html__( 'Grid row', 'bricks' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'inline'         => true,
			'css'            => [
				[
					'property' => 'grid-row',
				],
			],
		];

		$this->controls['_gridItemJustifySelf'] = [
			'label'          => esc_html__( 'Justify self', 'bricks' ),
			'type'           => 'align-items',
			'hasDynamicData' => false,
			'css'            => [
				[
					'property' => 'justify-self',
				],
			],
		];

		$this->controls['_gridItemSeparatorAfter'] = [
			'type' => 'separator',
		];

		/**
		 * Loop Builder
		 *
		 * Enable for elements: Container, Block, Div and Section (@since 1.8)
		 */
		if (
			bricks_is_builder() &&
			Builder_Permissions::user_has_permission( 'access_query_loop_builder' ) &&
			in_array( $this->name, [ 'section', 'container', 'block', 'div' ] )
		) {
			$this->controls = array_replace_recursive( $this->controls, $this->get_loop_builder_controls() );

			$this->controls['loopSeparator'] = [
				'type' => 'separator',
			];
		}

		$this->controls['link'] = [
			'label'       => esc_html__( 'Link', 'bricks' ),
			'type'        => 'link',
			'placeholder' => esc_html__( 'Select link type', 'bricks' ),
			'required'    => [ 'tag', '=', 'a' ],
		];

		$this->controls['linkInfo'] = [
			'type'     => 'info',
			'content'  => esc_html__( 'Make sure there are no elements with links inside your linked container (nested links).', 'bricks' ),
			'required' => [
				[ 'tag', '=', 'a' ],
				[ 'link', '!=', '' ],
			],
		];

		// Masonry active info (@since 1.11.1)
		$this->controls['_useMasonryInfo'] = [
			'type'     => 'info',
			// translators: %s: Masonry layout is active.
			'content'  => sprintf(
				'%s (%s > %s). %s',
				sprintf( esc_html__( '%s layout is active' ), esc_html__( 'Masonry', 'bricks' ) ),
				esc_html__( 'Style', 'bricks' ),
				esc_html__( 'Layout', 'bricks' ),
				esc_html__( 'Ensure that no conflicting CSS styles are applied to this element and that a width is defined, especially when using a Div element.', 'bricks' )
			),
			'required' => [ '_useMasonry', '=', true ],
		];

		$this->controls['tag'] = [
			'label'       => esc_html__( 'HTML tag', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'div'     => 'div',
				'section' => 'section',
				'a'       => 'a [' . esc_html__( 'Link', 'bricks' ) . ']',
				'article' => 'article',
				'nav'     => 'nav',
				'ol'      => 'ol',
				'ul'      => 'ul',
				'li'      => 'li',
				'aside'   => 'aside',
				'address' => 'address',
				'figure'  => 'figure',
				'custom'  => esc_html__( 'Custom', 'bricks' ),
			],
			'lowercase'   => true,
			'inline'      => true,
			'placeholder' => $this->tag ? $this->tag : 'div',
			'fullAccess'  => true,
		];

		$this->controls['customTag'] = [
			'label'          => esc_html__( 'Custom tag', 'bricks' ),
			'info'           => esc_html__( 'Without attributes', 'bricks' ),
			'type'           => 'text',
			'inline'         => true,
			'hasDynamicData' => false,
			'placeholder'    => 'div',
			'required'       => [ 'tag', '=', 'custom' ],
		];

		// Keep this foreach shape: the standalone schema generator matches it to expand the factory statically.
		foreach ( self::get_display_grid_controls() as $control_key => $control ) {
			$this->controls[ $control_key ] = $control;
		}

		// Display: flex

		// Flex controls
		$this->controls['_flexWrap'] = [
			'label'    => esc_html__( 'Flex wrap', 'bricks' ),
			'type'     => 'select',
			'options'  => [
				'nowrap'       => esc_html__( 'No wrap', 'bricks' ),
				'wrap'         => esc_html_x( 'Wrap', 'CSS flex property', 'bricks' ),
				'wrap-reverse' => esc_html__( 'Wrap reverse', 'bricks' ),
			],
			'inline'   => true,
			'css'      => [
				[
					'property' => 'flex-wrap',
				],
			],
			'required' => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		$this->controls['_direction'] = [
			'label'    => esc_html__( 'Direction', 'bricks' ),
			'type'     => 'direction',
			'css'      => [
				[
					'property' => 'flex-direction',
				],
			],
			'inline'   => true,
			'rerender' => true,
			'required' => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		$this->controls['_alignSelf'] = [
			'label'    => esc_html__( 'Align self', 'bricks' ),
			'type'     => 'align-items',
			'css'      => [
				[
					'property'  => 'align-self',
					'important' => true,
				],
				[
					'selector' => '',
					'property' => 'width',
					'value'    => '100%',
					'required' => 'stretch', // NOTE: Undocumented (@since 1.4)
				],
			],
			'required' => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		$this->controls['_justifyContent'] = [
			'label'    => esc_html__( 'Align main axis', 'bricks' ),
			'type'     => 'justify-content',
			'css'      => [
				[
					'property' => 'justify-content',
				],
			],
			'required' => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		$this->controls['_alignItems'] = [
			'label'    => esc_html__( 'Align cross axis', 'bricks' ),
			'type'     => 'align-items',
			'css'      => [
				[
					'property' => 'align-items',
				],
			],
			'required' => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		$this->controls['_flexGrow'] = [
			'label'       => esc_html__( 'Flex grow', 'bricks' ),
			'type'        => 'number',
			'min'         => 0,
			'css'         => [
				[
					'property' => 'flex-grow',
				],
			],
			'placeholder' => 0,
			'required'    => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		$this->controls['_flexShrink'] = [
			'label'       => esc_html__( 'Flex shrink', 'bricks' ),
			'type'        => 'number',
			'min'         => 0,
			'css'         => [
				[
					'property' => 'flex-shrink',
				],
			],
			'placeholder' => 1,
			'required'    => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		$this->controls['_flexBasis'] = [
			'label'          => esc_html__( 'Flex basis', 'bricks' ),
			'type'           => 'text',
			'css'            => [
				[
					'property' => 'flex-basis',
				],
			],
			'inline'         => true,
			'placeholder'    => 'auto',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'required'       => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
		];

		// Misc
		$this->controls['_order'] = [
			'label'       => esc_html_x( 'Order', 'CSS property', 'bricks' ),
			'type'        => 'number',
			'min'         => -999,
			'css'         => [
				[
					'property' => 'order',
				],
			],
			'placeholder' => 0,
			'required'    => [ '_display', '!=',  'none' ],
		];

		// TAB: STYLE

		// Inner container (direct children)
		$this->controls['_innerContainerSeparator'] = [
			'type'       => 'separator',
			'label'      => esc_html__( 'Inner container', 'bricks' ) . ' / div',
			'tab'        => 'style',
			'group'      => '_layout',
			'deprecated' => true, // @since 1.10
		];

		$this->controls['_innerContainerMargin'] = [
			'tab'        => 'style',
			'group'      => '_layout',
			'info'       => esc_html__( 'Inner container', 'bricks' ) . ' / div',
			'label'      => esc_html__( 'Margin', 'bricks' ),
			'type'       => 'spacing',
			'css'        => [
				[
					'property' => 'margin',
					'selector' => '> .brxe-container',
				],
				[
					'property' => 'margin',
					'selector' => '> .brxe-block',
				],
				[
					'property' => 'margin',
					'selector' => '> .brxe-div',
				],
			],
			'deprecated' => true, // @since 1.10
		];

		$this->controls['_innerContainerPadding'] = [
			'tab'        => 'style',
			'group'      => '_layout',
			'info'       => esc_html__( 'Inner container', 'bricks' ) . ' / div',
			'label'      => esc_html__( 'Padding', 'bricks' ),
			'type'       => 'spacing',
			'css'        => [
				[
					'property' => 'padding',
					'selector' => '> .brxe-container',
				],
				[
					'property' => 'padding',
					'selector' => '> .brxe-block',
				],
				[
					'property' => 'padding',
					'selector' => '> .brxe-div',
				],
			],
			'deprecated' => true, // @since 1.10
		];
	}

	/**
	 * Return shape divider HTML
	 */
	public static function get_shape_divider_html( $settings = [] ) {
		$shape_dividers = ! empty( $settings['_shapeDividers'] ) && is_array( $settings['_shapeDividers'] ) ? $settings['_shapeDividers'] : [];
		$output         = '';

		foreach ( $shape_dividers as $shape ) {
			$shape_name = ! empty( $shape['shape'] ) ? $shape['shape'] : false;

			// Skip: No shape set
			if ( ! $shape_name ) {
				continue;
			}

			$svg = '';

			// Custom shape from attachment ID (@since 1.8.6)
			if ( $shape_name === 'custom' ) {
				$svg_path = ! empty( $shape['shapeCustom']['id'] ) ? get_attached_file( $shape['shapeCustom']['id'] ) : false;
				$svg      = $svg_path ? Helpers::file_get_contents( $svg_path ) : false;
			}

			// Shape from file
			else {
				$svg = Helpers::file_get_contents( BRICKS_PATH_ASSETS . "svg/shapes/{$shape_name}.svg" );
			}

			// Skip: SVG file doesn't exist
			if ( ! $svg ) {
				continue;
			}

			$shape_classes = [ 'bricks-shape-divider' ];
			$shape_styles  = [];

			// Shape classes
			if ( isset( $shape['front'] ) ) {
				$shape_classes[] = 'front';
			}

			if ( isset( $shape['flipHorizontal'] ) ) {
				$shape_classes[] = 'flip-horizontal';
			}

			if ( isset( $shape['flipVertical'] ) ) {
				$shape_classes[] = 'flip-vertical';
			}

			if ( isset( $shape['overflow'] ) ) {
				$shape_classes[] = 'overflow';
			}

			// Shape styles
			if ( isset( $shape['horizontalAlign'] ) ) {
				$shape_styles[] = "justify-content: {$shape['horizontalAlign']}";
			}

			if ( isset( $shape['verticalAlign'] ) ) {
				$shape_styles[] = "align-items: {$shape['verticalAlign']}";
			}

			// Shape inner styles
			$shape_inner_styles   = [];
			$shape_css_properties = [
				'height',
				'width',
				'top',
				'right',
				'bottom',
				'left',
			];

			foreach ( $shape_css_properties as $property ) {
				$value = isset( $shape[ $property ] ) ? $shape[ $property ] : null;

				if ( $value !== null ) {
					// Append default unit
					if ( is_numeric( $value ) ) {
						$value .= 'px';
					}

					$shape_inner_styles[] = "{$property}: {$value}";
				}
			}

			if ( isset( $shape['rotate'] ) ) {
				$rotate               = intval( $shape['rotate'] );
				$shape_inner_styles[] = "transform: rotate({$rotate}deg)";
			}

			$output .= '<div class="' . join( ' ', $shape_classes ) . '" style="' . join( '; ', $shape_styles ) . '">';
			$output .= '<div class="bricks-shape-divider-inner" style="' . join( '; ', $shape_inner_styles ) . '">';

			$dom = new \DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadXML( $svg );
			libxml_clear_errors();

			// SVG styles
			$svg_styles = [];

			if ( isset( $shape['fill']['raw'] ) ) {
				$svg_styles[] = "fill: {$shape['fill']['raw']}";
			} elseif ( isset( $shape['fill']['rgb'] ) ) {
				$svg_styles[] = "fill: {$shape['fill']['rgb']}";
			} elseif ( isset( $shape['fill']['hex'] ) ) {
				$svg_styles[] = "fill: {$shape['fill']['hex']}";
			}

			foreach ( $dom->getElementsByTagName( 'svg' ) as $element ) {
				$element->setAttribute( 'style', join( '; ', $svg_styles ) );
			}

			$svg = $dom->saveXML();

			$output .= str_replace( '<?xml version="1.0"?>', '', $svg );

			$output .= '</div>';
			$output .= '</div>';
		}

		return $output;
	}

	/**
	 * Parses video URL or ID
	 *
	 * Input: Video ID: Return ID as is
	 * Input: Video URL: Return escaped URL
	 *
	 * @since 1.12.2
	 */
	private function parse_video_url_or_id( $input ) {
		// Remove whitespace
		$input = trim( $input );

		// Return video URL
		if ( filter_var( $input, FILTER_VALIDATE_URL ) ) {
			return esc_url( $input );
		}

		// Return video ID
		return esc_attr( $input );
	}

	/**
	 * Return background video HTML
	 */
	public function get_background_video_html( $settings ) {
		// Loop over all breakpoints
		foreach ( Breakpoints::$breakpoints as $breakpoint ) {
			$setting_key      = $breakpoint['key'] === 'desktop' ? '_background' : "_background:{$breakpoint['key']}";
			$background       = ! empty( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : false;
			$video_url        = ! empty( $background['videoUrl'] ) ? $background['videoUrl'] : false;
			$video_attributes = [];

			if ( strpos( $video_url, '{' ) !== false ) {
				$video_url = bricks_render_dynamic_data( $video_url, $this->post_id, 'link' );
			}

			if ( $video_url ) {

				$video_url = $this->parse_video_url_or_id( $video_url );

				$attributes[] = 'class="bricks-background-video-wrapper bricks-lazy-video"';
				$attributes[] = 'data-background-video-url="' . $video_url . '"';

				if ( ! empty( $background['videoScale'] ) ) {
					$attributes[] = 'data-background-video-scale="' . $background['videoScale'] . '"';
				}

				if ( ! empty( $background['videoAspectRatio'] ) ) {
					$attributes[] = 'data-background-video-ratio="' . $background['videoAspectRatio'] . '"';
				}

				if ( ! empty( $background['videoStartTime'] ) ) {
					$attributes[] = 'data-background-video-start="' . $background['videoStartTime'] . '"';
				}

				if ( ! empty( $background['videoEndTime'] ) ) {
					$attributes[] = 'data-background-video-end="' . $background['videoEndTime'] . '"';
				}

				if ( empty( $background['videoPlayOnce'] ) ) {
					$attributes[] = 'data-background-video-loop="1"';
				}

				if ( ! empty( $background['videoVimeoBackground'] ) ) {
					$attributes[] = sprintf(
						'data-background-video-vimeo-background="%s"',
						esc_attr( $background['videoVimeoBackground'] )
					);
				}

				if ( ! empty( $background['videoVimeoMuted'] ) ) {
					$attributes[] = sprintf(
						'data-background-video-vimeo-muted="%s"',
						esc_attr( $background['videoVimeoMuted'] )
					);
				}

				if ( ! empty( $background['videoShowAtBreakpoint'] ) ) {
					$breakpoint = Breakpoints::get_breakpoint_by( 'key', $background['videoShowAtBreakpoint'] );
					$width      = isset( $breakpoint['width'] ) ? $breakpoint['width'] : null;

					// Is base breakpoint
					if ( isset( $breakpoint['base'] ) ) {
						$breakpoints = Breakpoints::$breakpoints;

						foreach ( $breakpoints as $index => $bp ) {
							// Is first breakpoint
							if ( $bp['key'] === $breakpoint['key'] && $index === 0 ) {
								// Get 'width' of next breakpoint
								$next_breakpoint = isset( $breakpoints[ $index + 1 ] ) ? $breakpoints[ $index + 1 ] : null;

								if ( $next_breakpoint ) {
									$width = Breakpoints::$is_mobile_first ? 0 : $next_breakpoint['width'] + 1;
								}
							}
						}
					}

					if ( $width ) {
						$attributes[] = 'data-background-video-show-at-breakpoint="' . $width . '"';
					}
				}

				// Video poster (@since 1.11)
				if ( ! empty( $background['videoPoster'] ) ) {
					$poster_url         = $this->extract_background_video_poster_url( $background['videoPoster'] );
					$video_attributes[] = 'poster="' . $poster_url . '"';
					$attributes[]       = 'data-background-video-poster="' . $poster_url . '"';
				}
				// YouTube video poster (@since 1.11)
				if ( ! empty( $background['videoPosterYouTube'] ) ) {
					$youtube_poster_size = $background['videoPosterYouTubeSize'] ?? 'maxresdefault';
					$attributes[]        = 'data-background-video-poster-yt-size="' . $youtube_poster_size . '"';
				}

				$attributes       = join( ' ', $attributes );
				$video_attributes = join( ' ', $video_attributes );

				// @since 1.4: Chrome doesn't play the .mp4 background video if the <video> tag is injected programmatically using JavaScript
				return "<div $attributes><video autoplay loop playsinline muted $video_attributes></video></div>";
			}
		}
	}

	public function render() {
		$element  = $this->element;
		$settings = $this->settings ?? [];
		$output   = '';

		// Bricks Query Loop
		if ( isset( $settings['hasLoop'] ) ) {
			// Hold the component to first unset 'hasLoop' and then add back 'hasLoop' after the query->render (@since 1.12)
			$original_component = Helpers::get_component( $element );

			// Hold the global element to first unset 'hasLoop' and then add back 'hasLoop' after the query->render
			$global_element = Helpers::get_global_element( $element );

			// STEP: Query
			add_filter( 'bricks/posts/query_vars', [ $this, 'maybe_set_preview_query' ], 10, 3 );

			/**
			 * Is component: Generate random ID for component instance (@since 1.12)
			 *
			 * Component instances inserted as direct slot payloads are already unique local
			 * elements. Keep their local ID so their own slots can find the right slotChildren.
			 * (#86c82k2b3; @since 2.3.8)
			 */
			$is_direct_slot_component = ! empty( $element['slotInstanceId'] );

			if (
				! empty( $element['instanceId'] ) &&
				! empty( $element['parentComponent'] ) &&
				! $is_direct_slot_component
			) {
				$element['id'] .= '-' . $element['instanceId']; // Use dash instead of colon (@since 1.12.2)
			}

			$query = new \Bricks\Query( $element );

			remove_filter( 'bricks/posts/query_vars', [ $this, 'maybe_set_preview_query' ], 10, 3 );

			// Prevent endless loop (@since 2.0; #86c3qwrm6)
			$element['looped'] = true;

			// Prevent condition execution when looping (@since 1.12.2)
			unset( $element['settings']['_conditions'] );

			// Maybe enable ACF Flexible Content preview mode for the query loop (@since 2.4)
			$use_acf_flexible_preview_mode = $this->enable_acf_flexible_preview( $element );

			// Maybe add li node for children elements (@since 2.0)
			add_filter( 'bricks/frontend/render_element', [ $this, 'maybe_wrap_nav_link' ], 0, 2 );

			// STEP: Render loop
			$output = $query->render( 'Bricks\Frontend::render_element', compact( 'element' ) );

			if ( $use_acf_flexible_preview_mode ) {
				// Maybe disable ACF Flexible Content preview mode for the query loop (@since 2.4)
				$this->disable_acf_flexible_preview();
			}

			// Maybe add li node for children elements (@since 2.0)
			remove_filter( 'bricks/frontend/render_element', [ $this, 'maybe_wrap_nav_link' ], 0, 2 );

			// NOTE: Undocumented: For builder to collect first query loop node if query located inside component (@since 2.0)
			$output = apply_filters( 'bricks/frontend/render_loop', $output, $element, $this );

			echo $output;

			// STEP: Infinite scroll
			$this->render_query_loop_trail( $query );

			// Destroy Query to explicitly remove it from global store
			$query->destroy();

			unset( $query );

			return;
		}

		// Render the video wrapper first so we know it before adding the has-bg-video class
		$video_wrapper_html = $this->get_background_video_html( $settings );

		// No background video set on element ID: Loop over element global classes
		if ( ! $video_wrapper_html ) {
			/**
			 * Ensure global classes IDs are an array
			 *
			 * If "Multiple options" is not enabled, the selected class is stored as a string.
			 *
			 * @since 2.0
			 */
			if ( ! empty( $settings['_cssGlobalClasses'] ) ) {
				$elements_class_ids = $settings['_cssGlobalClasses'];

				if ( is_string( $elements_class_ids ) ) {
					$elements_class_ids = explode( ' ', $elements_class_ids );
				}

				if ( is_array( $elements_class_ids ) && count( $elements_class_ids ) ) {
					$global_classes = Database::$global_data['globalClasses'];

					foreach ( $global_classes as $global_class ) {
						$global_class_id = ! empty( $global_class['id'] ) ? $global_class['id'] : '';

						if ( ! $video_wrapper_html && in_array( $global_class_id, $elements_class_ids ) ) {
							if ( ! empty( $global_class['settings'] ) ) {
								$video_wrapper_html = $this->get_background_video_html( $global_class['settings'] );
							}
						}
					}
				}
			}
		}

		// Add .has-bg-video to set z-index: 1 (#2g9ge90)
		if ( ! empty( $video_wrapper_html ) ) {
			$this->set_attribute( '_root', 'class', 'has-bg-video' );
		}

		// Add .has-shape to set position: relative (#2t7w2bq)
		if ( ! empty( $settings['_shapeDividers'] ) ) {
			$this->set_attribute( '_root', 'class', 'has-shape' );
		}

		// Non-megamenu dropdown content: Set tag to 'ul'
		$parent_id      = ! empty( $element['parent'] ) ? $element['parent'] : false;
		$parent_element = ! empty( Frontend::$elements[ $parent_id ] ) ? Frontend::$elements[ $parent_id ] : false;

		if ( $parent_element && $parent_element['name'] === 'dropdown' && ! isset( $parent_element['settings']['megaMenu'] ) ) {
			$this->tag = 'ul';
		}

		/**
		 * Live search wrapper
		 *
		 * Add 'data-brx-ls-wrapper' to hide live search wrapper on page load.
		 *
		 * @since 1.9.6
		 */
		if ( count( Frontend::$live_search_wrapper_selectors ) ) {
			foreach ( Frontend::$live_search_wrapper_selectors as $live_search_query_id => $live_search_wrapper_selector ) {
				/**
				 * 1. Last six-characters of live search results selector match element.id
				 * 2. Live search results selector matches custom element ID
				 */
				$match_default_id = "#brxe-{$element['id']}" === $live_search_wrapper_selector;
				$match_custom_id  = ! empty( $element['settings']['_cssId'] ) && "#{$element['settings']['_cssId']}" === $live_search_wrapper_selector;

				if ( $match_default_id || $match_custom_id ) {
					unset( Frontend::$live_search_wrapper_selectors[ $live_search_query_id ] );

					$this->set_attribute( '_root', 'data-brx-ls-wrapper', $live_search_query_id );

					// Ensure setting element 'id' to target the live search wrapper with CSS. Could be omittied, if the elment doesn't has_css_settings.
					if ( empty( $this->attributes['_root']['id'] ) ) {
						$this->set_attribute( '_root', 'id', $this->get_element_attribute_id() );
					}
				}
			}
		}

		// Default: Non-query loop
		$output .= "<{$this->tag} {$this->render_attributes( '_root' )}>";

		$output .= self::get_shape_divider_html( $settings );

		$output .= $video_wrapper_html;

		// Maybe add li node for children elements (@since 2.0)
		add_filter( 'bricks/frontend/render_element', [ $this, 'maybe_wrap_nav_link' ], 0, 2 );

		if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
			foreach ( $element['children'] as $child_id ) {
				$child_element = Frontend::$elements[ $child_id ] ?? false;

				/**
				 * Skip element: Component with this 'cid' doesn't exist in database
				 *
				 * @since 1.12
				 */
				if ( ! empty( $child_element['cid'] ) && ! Helpers::get_component_by_cid( $child_element['cid'] ) ) {
					continue;
				}

				// Render element children
				$child_html = $child_element ? Frontend::render_element( $child_element ) : false; // Recursive

				$output .= $child_html;
			}
		}

		// Maybe add li node for children elements (@since 2.0)
		remove_filter( 'bricks/frontend/render_element', [ $this, 'maybe_wrap_nav_link' ], 0, 2 );

		/**
		 * STEP: Add masonry trail nodes
		 *
		 * Suppose add these nodes inside base.php but no perfect hook yet.
		 * Any custom element has to run this method manually in the render method.
		 *
		 * @since 1.11.1
		 */
		$output .= $this->maybe_masonry_trail_nodes();

		$output .= "</{$this->tag}>";

		echo $output;
	}

	/**
	 * Modify html for element instance
	 * - Wrap nav link in <li> if inside nav items or dropdown content
	 * - Priority: 0 to run before other filters that might modify the HTML before this function
	 *
	 * @since 2.0
	 */
	public function maybe_wrap_nav_link( $html, $element_instance ) {
		if ( empty( $html ) ) {
			return $html;
		}

		// Nav items is parent element: Wrap this nav link in <li> (@since 1.8)
		$element   = $element_instance->element ?? false;
		$parent_id = $element['parent'] ?? false;

		if ( ! $parent_id ) {
			return $html;
		}

		$parent_element          = ! empty( Frontend::$elements[ $parent_id ] ) ? Frontend::$elements[ $parent_id ] : false;
		$inside_nav_items        = ! empty( $parent_element['settings']['_hidden']['_cssClasses'] ) ? $parent_element['settings']['_hidden']['_cssClasses'] === 'brx-nav-nested-items' : false;
		$inside_dropdown_content = ! empty( $parent_element['settings']['_hidden']['_cssClasses'] ) ? $parent_element['settings']['_hidden']['_cssClasses'] === 'brx-dropdown-content' : false;

		// Wrap in <li> if child HTML does not start with an 'li' tag (e.g. non-megamenu dropdown)
		if (
			( $inside_nav_items || $inside_dropdown_content ) &&
			( strpos( $html, '<li' ) === false || strpos( $html, '<li' ) !== 0 )
		) {
			$dropdown_id      = $parent_element['parent'] ?? false;
			$dropdown_element = isset( Frontend::$elements[ $dropdown_id ] ) && ! empty( Frontend::$elements[ $dropdown_id ] ) ? Frontend::$elements[ $dropdown_id ] : false;

			// Megamenu: Don't wrap dropdown item in <li>
			if ( isset( $dropdown_element['settings']['megaMenu'] ) ) {
				return $html;
			}

			// Wrap menu item in <li>
			else {
				$html = '<li class="menu-item">' . $html . '</li>';
			}
		}

		return $html;
	}

	/**
	 * Extract Video poster from background settings
	 *
	 * @param array $background Background video poster settings
	 * @return string Video poster URL
	 * @since 2.2
	 */
	public function extract_background_video_poster_url( $video_poster ) {
		// If it contains 'url' key, return as is
		if ( isset( $video_poster['url'] ) ) {
			return $video_poster['url'];
		}

		$video_poster['size'] = ! empty( $video_poster['size'] ) ? $video_poster['size'] : BRICKS_DEFAULT_IMAGE_SIZE;

		// If it's "useDynamicData", then render dynamic tag
		if ( ! empty( $video_poster['useDynamicData'] ) ) {
			$dynamic_image = $this->render_dynamic_data_tag( $video_poster['useDynamicData'], 'image', [ 'size' => $video_poster['size'] ] );

			if ( ! empty( $dynamic_image[0] ) ) {
				if ( is_numeric( $dynamic_image[0] ) ) {
					// Use the image ID to populate and set $dynamic_image['url']
					return wp_get_attachment_image_url( Helpers::resolve_attachment_id( $dynamic_image[0] ), $video_poster['size'] );
				} else {
					return $dynamic_image[0];
				}
			} else {
				return '';
			}
		}

		return '';
	}

	/**
	 * Enable ACF flexible content loop preview in the builder.
	 *
	 * Attaches a render filter that hides layout branches whose condition
	 * does not match the current row layout.
	 *
	 * @since 2.4
	 */
	private function enable_acf_flexible_preview( $element ) {
		$preview     = ! empty( $element['settings']['query']['acfFlexiblePreviewMode'] );
		$object_type = $element['settings']['query']['objectType'] ?? 'post';

		// Return: Not in preview mode, or not in builder, or object type is not ACF flexible loop.
		if ( ! $preview || ( ! bricks_is_builder() && ! bricks_is_builder_call() ) || ! $this->is_acf_flexible_object_type( $object_type ) ) {
			return false;
		}

		$loop_id                 = $element['id'] ?? '';
		$loop_parent_id          = ! empty( $this->element['cid'] )
		? $this->element['cid']
		: ( $this->element['id'] ?? $loop_id );
		$visibility_key_fallback = $loop_parent_id;
		$visibility_key          = $loop_id;

		// Component child loop: Use per-instance visibility key while keeping unsuffixed key as fallback.
		if ( empty( $element['instanceId'] ) || empty( $element['parentComponent'] ) ) {
			$visibility_key = $loop_parent_id;
		}

		$has_visibility_map = $visibility_key && array_key_exists( $visibility_key, Builder::$loop_visible_elements );

		if ( ! $has_visibility_map && $visibility_key_fallback && $visibility_key_fallback !== $visibility_key ) {
			$has_visibility_map = array_key_exists( $visibility_key_fallback, Builder::$loop_visible_elements );
		}

		// Initialize an empty map so the builder can hide all first-node layouts when the first flexible loop has no rows.
		if ( $visibility_key && ! isset( Builder::$loop_visible_elements[ $visibility_key ] ) ) {
			Builder::$loop_visible_elements[ $visibility_key ] = [];
		}

		$this->acf_flexible_preview = [
			'enabled'                 => true,
			'loop_id'                 => $loop_id,
			'loop_parent_id'          => $loop_parent_id,
			'visibility_key'          => $visibility_key,
			'visibility_key_fallback' => $visibility_key_fallback,
			'loop_index'              => null,
			'first_loop_index'        => null,
			'visibility_collected'    => $has_visibility_map,
			'hidden_element_ids'      => [],
		];

		add_filter( 'bricks/element/render', [ $this, 'maybe_hide_acf_flexible_layout' ], 10, 2 );

		return true;
	}

	/**
	 * Disable ACF flexible content loop preview.
	 *
	 * Removes the render filter and resets preview state.
	 *
	 * @since 2.4
	 */
	private function disable_acf_flexible_preview() {
		remove_filter( 'bricks/element/render', [ $this, 'maybe_hide_acf_flexible_layout' ], 10, 2 );

		$this->acf_flexible_preview = [
			'enabled'                 => false,
			'loop_id'                 => '',
			'loop_parent_id'          => '',
			'visibility_key'          => '',
			'visibility_key_fallback' => '',
			'loop_index'              => null,
			'first_loop_index'        => null,
			'visibility_collected'    => false,
			'hidden_element_ids'      => [],
		];
	}

	/**
	 * Hide non-matching ACF flexible layout branches in builder preview mode.
	 *
	 * Callback for the `bricks/element/render` filter. Returns false to skip
	 * rendering when the element belongs to a layout branch that does not
	 * match the current row layout.
	 *
	 * @since 2.4
	 */
	public function maybe_hide_acf_flexible_layout( $render_element, $instance ) {
		if ( empty( $this->acf_flexible_preview['enabled'] ) ) {
			return $render_element;
		}

		$current_layout     = $this->get_acf_flexible_row_layout( $instance );
		$current_loop_index = $this->get_current_loop_index();

		// Return: Not in ACF flexible row context.
		if ( ! $current_layout ) {
			return $render_element;
		}

		// Reset per-row state so hidden elements do not leak across loop rows.
		if ( $this->acf_flexible_preview['loop_index'] !== $current_loop_index ) {
			$this->acf_flexible_preview['loop_index']         = $current_loop_index;
			$this->acf_flexible_preview['hidden_element_ids'] = [];

			// Track the first rendered row in this request for builder visibility map.
			if ( $this->acf_flexible_preview['first_loop_index'] === null ) {
				$this->acf_flexible_preview['first_loop_index'] = $current_loop_index;
			}
		}

		$element    = $instance->element ?? [];
		$element_id = $instance->id ?? ( $element['id'] ?? '' );
		$parent_id  = $element['parent'] ?? '';

		if ( ! $element_id ) {
			return $render_element;
		}

		// Skip rendering all descendants of hidden elements.
		if ( $parent_id && isset( $this->acf_flexible_preview['hidden_element_ids'][ $parent_id ] ) ) {
			$this->acf_flexible_preview['hidden_element_ids'][ $element_id ] = true;
			return false;
		}

		// Return: Only direct children of the loop container are treated as layout roots.
		if ( $parent_id !== $this->acf_flexible_preview['loop_parent_id'] ) {
			$this->collect_acf_flexible_visible_element( $element_id, $current_loop_index );
			return $render_element;
		}

		$conditions       = $instance->settings['_conditions'] ?? [];
		$layout_condition = $this->get_acf_layout_condition( $conditions, $instance );

		// Return: No row layout condition, keep legacy rendering for this child.
		if ( ! $layout_condition ) {
			$this->collect_acf_flexible_visible_element( $element_id, $current_loop_index );
			return $render_element;
		}

		$layout  = $layout_condition['layout'];
		$matches = $this->is_acf_layout_match( $current_layout, $layout, $layout_condition['compare'] );

		if ( $matches ) {
			$this->collect_acf_flexible_visible_element( $element_id, $current_loop_index );
			return $render_element;
		}

		// Hide non-matching layout root and all its descendants in preview mode.
		$this->acf_flexible_preview['hidden_element_ids'][ $element_id ] = true;

		return false;
	}

	/**
	 * Collect visible element IDs from the first rendered row for the builder.
	 *
	 * Populates `Builder::$loop_visible_elements` so the Vue builder knows
	 * which elements to show inside the first loop node.
	 *
	 * @since 2.4
	 */
	private function collect_acf_flexible_visible_element( $element_id, $current_loop_index ) {
		$visibility_key = $this->acf_flexible_preview['visibility_key'] ?? '';

		if ( ! $element_id || ! $visibility_key ) {
			return;
		}

		// Visibility map for this loop already captured in this request: Skip.
		if ( ! empty( $this->acf_flexible_preview['visibility_collected'] ) ) {
			return;
		}

		$first_loop_index = $this->acf_flexible_preview['first_loop_index'];

		// Only collect visibility map for the first rendered loop row.
		if ( $first_loop_index !== $current_loop_index ) {
			return;
		}

		if ( ! isset( Builder::$loop_visible_elements[ $visibility_key ] ) ) {
			Builder::$loop_visible_elements[ $visibility_key ] = [];
		}

		Builder::$loop_visible_elements[ $visibility_key ][ $element_id ] = true;
	}

	/**
	 * Get the current ACF flexible row layout name.
	 *
	 * @since 2.4
	 */
	private function get_acf_flexible_row_layout( $instance = null ) {
		$query = $this->get_acf_flexible_preview_query();

		if ( $query ) {
			$loop_object = $query->loop_object ?? null;

			if ( is_array( $loop_object ) && isset( $loop_object['acf_fc_layout'] ) ) {
				return (string) $loop_object['acf_fc_layout'];
			}
		}

		// Fallback: Resolve layout from dynamic data directly in current render context.
		if ( is_object( $instance ) && method_exists( $instance, 'render_dynamic_data' ) ) {
			$layout = $instance->render_dynamic_data( '{acf_get_row_layout}' );
			$layout = trim( (string) $layout );

			if ( $layout ) {
				return $layout;
			}
		}

		return '';
	}

	/**
	 * Get current loop index for active query.
	 *
	 * @since 2.4
	 */
	private function get_current_loop_index() {
		$query = $this->get_acf_flexible_preview_query();

		if ( ! $query ) {
			return null;
		}

		$loop_index = $query->loop_index ?? null;

		return $loop_index === '' ? null : $loop_index;
	}

	/**
	 * Get the active Query instance for the ACF flexible preview loop.
	 *
	 * @since 2.4
	 */
	private function get_acf_flexible_preview_query() {
		$loop_id = $this->acf_flexible_preview['loop_id'] ?? '';

		if ( ! $loop_id ) {
			return false;
		}

		$query = \Bricks\Query::get_query_for_element_id( $loop_id );

		if ( ! $query || empty( $query->is_looping ) ) {
			return false;
		}

		return $query;
	}

	/**
	 * Check if object type is an ACF Flexible Content loop type.
	 *
	 * @since 2.4
	 */
	private function is_acf_flexible_object_type( $object_type ) {
		if ( ! is_string( $object_type ) || ! $object_type ) {
			return false;
		}

		$acf_provider = \Bricks\Integrations\Dynamic_Data\Providers::get_registered_provider( 'acf' );

		if ( ! $acf_provider || ! method_exists( $acf_provider, 'get_flexible_content_query_types' ) ) {
			return false;
		}

		$types = $acf_provider->get_flexible_content_query_types();

		return is_array( $types ) && in_array( $object_type, $types, true );
	}

	/**
	 * Extract the ACF row layout condition from an element's conditions array.
	 *
	 * @since 2.4
	 */
	private function get_acf_layout_condition( $conditions, $instance ) {
		if ( empty( $conditions ) || ! is_array( $conditions ) ) {
			return false;
		}

		foreach ( $conditions as $condition_set ) {
			if ( ! is_array( $condition_set ) ) {
				continue;
			}

			foreach ( $condition_set as $condition ) {
				$key           = $condition['key'] ?? '';
				$dynamic_field = trim( (string) ( $condition['dynamic_data'] ?? '' ) );
				$value_field   = trim( (string) ( $condition['value'] ?? '' ) );

				if ( $key !== 'dynamic_data' ) {
					continue;
				}

				$layout_value = '';

				// Support both condition input styles:
				// 1) dynamic_data = {acf_get_row_layout}, value = target layout
				// 2) value = {acf_get_row_layout}, dynamic_data = target layout
				if ( $dynamic_field === '{acf_get_row_layout}' ) {
					$layout_value = $value_field;
				} elseif ( $value_field === '{acf_get_row_layout}' ) {
					$layout_value = $dynamic_field;
				} else {
					continue;
				}

				if ( is_string( $layout_value ) && is_object( $instance ) && method_exists( $instance, 'render_dynamic_data' ) ) {
					$layout_value = $instance->render_dynamic_data( $layout_value );
				}

				$layout  = trim( (string) $layout_value );
				$compare = $condition['compare'] ?? '==';

				if ( ! $layout ) {
					continue;
				}

				if (
					( substr( $layout, 0, 1 ) === '"' && substr( $layout, -1 ) === '"' ) ||
					( substr( $layout, 0, 1 ) === "'" && substr( $layout, -1 ) === "'" )
				) {
					$layout = substr( $layout, 1, -1 );
				}

				return [
					'layout'  => $layout,
					'compare' => $compare,
				];
			}
		}

		return false;
	}

	/**
	 * Check if the current layout matches a required layout using a compare operator.
	 *
	 * @since 2.4
	 */
	private function is_acf_layout_match( $current_layout, $required_layout, $compare = '==' ) {
		$current_layout  = (string) $current_layout;
		$required_layout = (string) $required_layout;

		switch ( $compare ) {
			case '!=':
				return $current_layout !== $required_layout;

			case 'contains':
				return $required_layout !== '' && strpos( $current_layout, $required_layout ) !== false;

			case 'contains_not':
				return $required_layout === '' || strpos( $current_layout, $required_layout ) === false;

			default:
				return $current_layout === $required_layout;
		}
	}
}
