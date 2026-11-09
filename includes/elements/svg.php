<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Svg extends Element {
	public $category = 'media';
	public $name     = 'svg';
	public $icon     = 'ti-vector';
	public $tag      = 'svg';

	public function get_label() {
		return 'SVG';
	}

	public function get_keywords() {
		return [ 'image' ];
	}

	public function set_controls() {
		$this->controls['source'] = [
			'label'       => esc_html__( 'Source', 'bricks' ),
			'type'        => 'select',
			'placeholder' => esc_html__( 'File', 'bricks' ),
			'inline'      => true,
			'options'     => [
				''            => esc_html__( 'File', 'bricks' ),
				'dynamicData' => esc_html__( 'Dynamic data', 'bricks' ),
				'code'        => esc_html__( 'Code', 'bricks' ),
				'iconSet'     => esc_html__( 'SVG icon', 'bricks' ),
			],
		];

		$this->controls['file'] = [
			'type'     => 'svg',
			'required' => [ 'source', '=', '' ],
		];

		$this->controls['iconSet'] = [
			'label'     => esc_html__( 'SVG icon', 'bricks' ),
			'type'      => 'icon',
			'inline'    => true,
			'libraries' => 'svg',
			'required'  => [ 'source', '=', 'iconSet' ],
		];

		$this->controls['dynamicData'] = [
			'label'    => esc_html__( 'Dynamic data', 'bricks' ),
			'type'     => 'text',
			'inline'   => true,
			'desc'     => esc_html__( 'Supported field types', 'bricks' ) . ': ' . esc_html__( 'File', 'bricks' ) . ', ' . esc_html__( 'Image', 'bricks' ) . ', ' . esc_html__( 'SVG code', 'bricks' ) . ', URL', // 'URL' (@since 2.0.2)
			'required' => [ 'source', '=', 'dynamicData' ],
		];

		// Allow adding SVG code (if current user can execute code)
		$user_can_execute_code = Capabilities::current_user_can_execute_code();
		if ( $user_can_execute_code ) {
			$this->controls['code'] = [
				'label'        => esc_html__( 'Code', 'bricks' ),
				'type'         => 'code',
				'mode'         => 'text/html', // https://codemirror.net/5/mode/xml/ (@since 1.10)
				'executeCode'  => true,
				'signCode'     => true,
				'hasVariables' => false,
				'desc'         => sprintf(
					esc_html__( 'Please ensure that the SVG code you paste in here does not contain any potentially malicious code. You can run it first through a free online SVG cleaner like %s', 'bricks' ),
					'<a href="https://svgomg.net/" target="_blank">https://svgomg.net/</a>'
				),
				'required'     => [ 'source', '=', 'code' ],
			];
		}

		// Code execution disabled
		else {
			$this->controls['codeExecutionNotAllowedInfo'] = [
				// translators: %s: 'Bricks settings path'
				'content'  => esc_html__( 'Code execution not allowed.', 'bricks' ) . ' ' . sprintf(
					esc_html__( 'You can manage code execution permissions under: %s', 'bricks' ),
					'Bricks > ' . esc_html__( 'Settings', 'bricks' ) . ' > ' . esc_html__( 'Custom code', 'bricks' ) . ' > ' . esc_html__( 'Code execution', 'bricks' )
				),
				'type'     => 'info',
				'required' => [ 'source', '=', 'code' ],
			];
		}

		$this->controls['height'] = [
			'label' => esc_html__( 'Height', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'height',
				],
			],
		];

		$this->controls['width'] = [
			'label' => esc_html__( 'Width', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[
					'property' => 'width',
				],
			],
		];

		$this->controls['strokeWidth'] = [
			'label' => esc_html__( 'Stroke width', 'bricks' ),
			'type'  => 'number',
			'min'   => 1,
			'css'   => [
				[
					'property' => 'stroke-width',
					'selector' => ' *',
					// 'important' => true, Causing issues (@since 2.2 #86c3p8f91)
				]
			],
		];

		$this->controls['stroke'] = [
			'label' => esc_html__( 'Stroke color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'stroke',
					'selector' => ' :not([stroke="none"])',
					// 'important' => true, Causing issues (@since 2.2 #86c3p8f91)
				]
			],
		];

		$this->controls['fill'] = [
			'label' => esc_html__( 'Fill', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'fill',
					'selector' => ' :not([fill="none"])',
					// 'important' => true, Causing issues (@since 2.2 #86c3p8f91)
				]
			],
		];

		$this->controls['link'] = [
			'label' => esc_html__( 'Link', 'bricks' ),
			'type'  => 'link',
		];
	}

	public function render() {
		$settings = $this->settings;
		$source   = $settings['source'] ?? 'file';
		$link     = ! empty( $settings['link'] ) ? $settings['link'] : false;
		$svg      = '';

		// A saved SVG attachment can later be replaced with another image type (#86cbk4jmg; @since 2.4.2).
		$file_id_is_non_svg = false;

		// Default: Get SVG from file ID
		if ( $source === 'file' && ! empty( $settings['file']['id'] ) ) {
			$file_id            = absint( $settings['file']['id'] );
			$translated_file_id = absint( apply_filters( 'wpml_object_id', $file_id, 'attachment', true ) );

			if ( ! $translated_file_id ) {
				$translated_file_id = $file_id;
			}

			$file_mime = get_post_mime_type( $translated_file_id );

			if ( $file_mime === 'image/svg+xml' ) {
				$svg_path = get_attached_file( $translated_file_id );
				$svg      = $svg_path ? Helpers::file_get_contents( $svg_path ) : false;
			}
			else {
				// A known non-SVG replacement must not render the older imported File path.
				$file_id_is_non_svg = (bool) $file_mime;
			}
		}

		// Get SVG from icon set
		if ( $source === 'iconSet' && ! empty( $settings['iconSet']['svg']['id'] ) ) {
			$icon_id = $settings['iconSet']['svg']['id'];

			if ( get_post_mime_type( $icon_id ) === 'image/svg+xml' ) {
				$svg_path = get_attached_file( $icon_id );
				$svg      = $svg_path ? Helpers::file_get_contents( $svg_path ) : false;
			}
		}

		// Get SVG from dynamic data
		if ( $source === 'dynamicData' && ! empty( $settings['dynamicData'] ) ) {
			$svg_data = $this->render_dynamic_data_tag( $settings['dynamicData'], 'image' );

			$file = false;

			// Check if $svg_data is already an SVG code (@since 2.0)
			if ( Helpers::is_valid_svg( $svg_data ) ) {
				$file = $svg_data;
			}
			else {
				// Get dynamic tag content (@since 2.0)
				$file = ! empty( $svg_data[0] ) ? $svg_data[0] : false;
			}

			// STEP: Check if we have a valid file ID
			if ( $file && is_numeric( $file ) ) {
				// Image tags such as {featured_image} can resolve to WebP, PNG, or JPEG IDs.
				if ( get_post_mime_type( $file ) === 'image/svg+xml' ) {
					$svg_path = get_attached_file( $file );
					$svg      = $svg_path ? Helpers::file_get_contents( $svg_path ) : false;
				}
			}

			// STEP: If not a file ID, check if the file is SVG
			// To support "icon" dynamic tags that returns SVG #86c1bpp6y (@since 2.0)
			elseif ( ! $svg && Helpers::is_valid_svg( $file ) ) {
				$svg = $file;
			}

			// STEP: Check if file is a valid SVG file path (@since 2.0.2)
			elseif ( ! $svg && self::is_valid_svg_url( $file ) ) {
				$svg = self::get_svg_from_url( $file ) ?? '';
			}
		}

		// STEP: Get SVG HTML from Code element
		$code      = $settings['code'] ?? '';
		$signature = $settings['signature'] ?? false;

		if ( $source === 'code' && $code ) {
			// Return error message if $code contains any PHP tags
			if ( strpos( $code, '<?' ) !== false ) {
				return $this->render_element_placeholder( [ 'title' => esc_html__( 'Not allowed', 'bricks' ) ], 'error' );
			}

			if ( class_exists( '\Bricks\Element_Code' ) ) {
				$code = new Element_Code(
					[
						'id'       => $this->id,
						'settings' => [
							'code'         => $code,
							'signature'    => $signature,
							'executeCode'  => true,
							'noRootForce'  => true, // Return SVG code without element wrapper
							'isStaticArea' => isset( $this->element['staticArea'] ), // To render SVG element 'code' in static header template without root wrapper (@since 1.11.1)
						],
					]
				);

				ob_start();
				$code->load();
				$code->init();
				$svg = ob_get_clean();

				// Return error: No SVG (invalid or missing signature)
				if ( ! $svg ) {
					if ( $signature ) {
						return $this->render_element_placeholder( [ 'title' => esc_html__( 'Invalid signature', 'bricks' ) ], 'error' );
					} else {
						return $this->render_element_placeholder( [ 'title' => esc_html__( 'No signature', 'bricks' ) ], 'error' );
					}
				}
			}
		}

		// Imported File sources can retain a path when their attachment is missing (@since 1.12.2).
		// Other sources must not reuse that path or show a stale SVG after an image replacement (#86cbk4jmg; @since 2.4.2).
		if ( $source === 'file' && ! $file_id_is_non_svg && ! $svg && isset( $settings['file']['path'] ) && $settings['file']['path'] !== '' ) {
			$svg = Helpers::file_get_contents( $settings['file']['path'] );
		}

		// Accept SVGs with XML declarations while keeping image bytes away from render_svg() (#86cbk4jmg; @since 2.4.2).
		if ( ! is_string( $svg ) || strpos( $svg, '<svg' ) === false ) {
			return $this->render_element_placeholder( [ 'title' => esc_html__( 'No SVG selected.', 'bricks' ) ] );
		}

		// Support dynamic data color (fill & stroke) in loop (@since 2.2)
		if ( Query::is_looping() ) {
			$has_dynamic_fill   = isset( $settings['fill']['raw'] ) && strpos( $settings['fill']['raw'], '{' ) !== false;
			$has_dynamic_stroke = isset( $settings['stroke']['raw'] ) && strpos( $settings['stroke']['raw'], '{' ) !== false;

			if ( $has_dynamic_fill || $has_dynamic_stroke ) {
				$this->attributes['_root']['data-query-loop-index'] = Query::get_looping_unique_identifier();
			}
		}

		// Run root attributes through filter (@since 1.10)
		if ( ! $link ) {
			$this->attributes = apply_filters( 'bricks/element/render_attributes', $this->attributes, '_root', $this );
		}

		$output = '';

		// Linked SVG
		if ( $link ) {
			$this->set_link_attributes( 'link', $link );

			// Add custom class to the link wrapper so we can target it in CSS (@since 2.0)
			$this->set_attribute( 'link', 'class', 'bricks-link-wrapper' );

			// Add '_attributes' to the SVG instead of the link
			$output .= "<a {$this->render_attributes( 'link', false )}>";
		}

		// Render SVG + root attributes (ID, classes, etc.)
		$output .= self::render_svg( $svg, $this->attributes['_root'] );

		if ( $link ) {
			$output .= '</a>';
		}

		echo $output;
	}

	/**
	 * Check if URL is valid and points to an SVG file
	 *
	 * @param string $url The URL to validate
	 * @return bool True if valid SVG URL, false otherwise
	 *
	 * @since 2.0.2
	 */
	private function is_valid_svg_url( $url ) {
			// Validate URL format using WordPress function
		if ( ! wp_http_validate_url( $url ) ) {
				return false;
		}

		// Check and return if URL ends with .svg
		return preg_match( '/\.svg$/i', $url );
	}

	/**
	 * Fetch SVG content from URL
	 *
	 * @param string $url The SVG URL to fetch (should be a valid SVG file URL)
	 * @param array  $args Optional arguments for the request
	 * @return string|false SVG content on success, false on failure
	 *
	 * @since 2.0.2
	 */
	private function get_svg_from_url( $url ) {
		// Make the request
		$response = \Bricks\Helpers::remote_get( $url );

		// Check for errors
		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Check HTTP status code
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return false;
		}

		$svg_content = wp_remote_retrieve_body( $response );

		// Validate SVG content
		if ( ! Helpers::is_valid_svg( $svg_content ) ) {
			return false;
		}

		return $svg_content;
	}
}
