<?php
/**
 * Element Mapper for HTML to Bricks Converter
 *
 * Maps HTML elements to Bricks element types and settings.
 * Uses the mappings defined in Html_To_Bricks_Element_Mappings.
 *
 * PHP port of src/vue/utils/htmlToBricks/elementMapper.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Element mapper for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Element_Mapper {

	/**
	 * Container tags that should stay as containers even with text-only content.
	 *
	 * @var array
	 */
	private static $preserve_container_tags = [ 'nav', 'main', 'article', 'aside', 'ul', 'ol', 'li', 'figure' ];

	/**
	 * Reserved native Bricks classes that act as conversion hints.
	 *
	 * @var array
	 */
	private static $native_class_element_map = [
		'brxe-section'   => 'section',
		'brxe-container' => 'container',
		'brxe-block'     => 'block',
		'brxe-button'    => 'button',
	];

	/**
	 * Font Awesome style token mapping.
	 *
	 * @var array
	 */
	private static $fa_style_tokens = [
		'fontawesomeSolid'   => [ 'fas', 'fa-solid' ],
		'fontawesomeRegular' => [ 'fa', 'far', 'fa-regular' ],
		'fontawesomeBrands'  => [ 'fab', 'fa-brands' ],
	];

	/**
	 * Font Awesome icon prefix per library.
	 *
	 * @var array
	 */
	private static $fa_icon_prefix = [
		'fontawesomeSolid'   => 'fas',
		'fontawesomeRegular' => 'fa',
		'fontawesomeBrands'  => 'fab',
	];

	/**
	 * HTML input type to Bricks field type mapping.
	 *
	 * @var array
	 */
	private static $input_type_map = [
		'email'          => 'email',
		'text'           => 'text',
		'password'       => 'password',
		'textarea'       => 'textarea',
		'tel'            => 'tel',
		'number'         => 'number',
		'url'            => 'url',
		'checkbox'       => 'checkbox',
		'radio'          => 'radio',
		'file'           => 'file',
		'date'           => 'datepicker',
		'datetime'       => 'datepicker',
		'datetime-local' => 'datepicker',
		'hidden'         => 'hidden',
		'search'         => 'text',
		'color'          => 'text',
		'range'          => 'number',
		'time'           => 'text',
		'week'           => 'text',
		'month'          => 'text',
	];

	/**
	 * Cached icon sets (flipped arrays for fast lookup).
	 *
	 * @var array|null
	 */
	private static $themify_set = null;

	/**
	 * Ionicons icon set.
	 *
	 * @var array|null
	 */
	private static $ionicons_set = null;

	/**
	 * Font Awesome combined icon set.
	 *
	 * @var array|null
	 */
	private static $fa_set = null;

	/**
	 * Generate a unique 6-character ID.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public static function generate_id() {
		$chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$length = strlen( $chars );
		$id     = '';

		for ( $i = 0; $i < 6; $i++ ) {
			$id .= $chars[ wp_rand( 0, $length - 1 ) ];
		}

		return $id;
	}

	/**
	 * Check if tag name is an inline element.
	 *
	 * @since 2.4
	 *
	 * @param string $tag_name Tag name to check.
	 * @return bool True if inline element.
	 */
	public static function is_inline_element( $tag_name ) {
		return in_array( strtolower( $tag_name ), Html_To_Bricks_Element_Mappings::get_inline_elements(), true );
	}

	/**
	 * Check whether an inline wrapper contains only editable phrasing markup.
	 *
	 * Links, native icons, embedded media, and block descendants stay structural
	 * so their dedicated Bricks behavior is not folded into a text payload.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element Element to inspect.
	 * @return bool
	 */
	private static function has_only_phrasing_content( $element ) {
		$phrasing_tags = array_flip(
			array_merge(
				Html_To_Bricks_Element_Mappings::get_inline_elements(),
				[ 'span', 'br', 'wbr' ]
			)
		);

		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType !== XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$tag_name = strtolower( $child->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( ! isset( $phrasing_tags[ $tag_name ] ) ) {
				return false;
			}

			if ( $tag_name === 'a' ) {
				return false;
			}

			if (
				$tag_name === 'i' &&
				self::detect_supported_icon_from_class_tokens( Html_To_Bricks_Html_Parser::extract_classes( $child ) )
			) {
				return false;
			}

			if ( ! self::has_only_phrasing_content( $child ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if element should be skipped.
	 *
	 * @since 2.4
	 *
	 * @param string $tag_name Tag name to check.
	 * @return bool True if element should be skipped.
	 */
	public static function should_skip_element( $tag_name ) {
		return in_array( strtolower( $tag_name ), Html_To_Bricks_Element_Mappings::get_skip_elements(), true );
	}

	/**
	 * Get mapping for HTML tag.
	 *
	 * @since 2.4
	 *
	 * @param string $tag_name HTML tag name.
	 * @return array|null Mapping object or null if not mapped.
	 */
	public static function get_tag_mapping( $tag_name ) {
		$html_to_element = Html_To_Bricks_Element_Mappings::get_html_to_element();
		$tag             = strtolower( $tag_name );

		return $html_to_element[ $tag ] ?? null;
	}

	/**
	 * Check if element should be treated as a container with children.
	 *
	 * @since 2.4
	 *
	 * @param string $bricks_element_name Bricks element name.
	 * @return bool True if should have children.
	 */
	public static function is_nestable_element( $bricks_element_name ) {
		$mappings = Html_To_Bricks_Element_Mappings::get_mappings();

		if ( ! isset( $mappings[ $bricks_element_name ] ) ) {
			return false;
		}

		return ! empty( $mappings[ $bricks_element_name ]['nestable'] );
	}

	/**
	 * Check whether a class name is reserved for native Bricks output.
	 *
	 * @since 2.4
	 *
	 * @param string $class_name CSS class without leading dot.
	 * @return bool
	 */
	public static function is_reserved_bricks_class( $class_name ) {
		return (bool) preg_match( '/^brxe?-/', (string) $class_name );
	}

	/**
	 * Resolve a native Bricks element from reserved class tokens.
	 *
	 * @since 2.4
	 *
	 * @param array $class_tokens CSS class tokens.
	 * @return string
	 */
	private static function get_native_element_from_class_tokens( $class_tokens ) {
		$class_set = array_flip( $class_tokens );

		foreach ( self::$native_class_element_map as $class_name => $element_name ) {
			if ( isset( $class_set[ $class_name ] ) ) {
				return $element_name;
			}
		}

		return '';
	}

	/**
	 * Initialize icon font lookup sets.
	 *
	 * Uses array_flip for O(1) lookups.
	 *
	 * @since 2.4
	 */
	private static function init_icon_sets() {
		if ( self::$themify_set === null ) {
			self::$themify_set  = array_flip( Html_To_Bricks_Icon_Fonts::get_themify() );
			self::$ionicons_set = array_flip( Html_To_Bricks_Icon_Fonts::get_ionicons() );
			self::$fa_set       = array_flip( Html_To_Bricks_Icon_Fonts::get_font_awesome_all() );
		}
	}

	/**
	 * Detect a supported icon from CSS class tokens.
	 *
	 * Checks Themify, Ionicons, and Font Awesome icon libraries.
	 *
	 * @since 2.4
	 *
	 * @param array $class_tokens Array of class names.
	 * @return array|null Icon match with 'icon' and 'consumed_classes' keys, or null.
	 */
	public static function detect_supported_icon_from_class_tokens( $class_tokens = [] ) {
		if ( ! is_array( $class_tokens ) || empty( $class_tokens ) ) {
			return null;
		}

		self::init_icon_sets();

		// Check Themify.
		foreach ( $class_tokens as $class_name ) {
			if ( isset( self::$themify_set[ $class_name ] ) ) {
				return [
					'icon'             => [
						'library' => 'themify',
						'icon'    => $class_name,
					],
					'consumed_classes' => [ $class_name ],
				];
			}
		}

		// Check Ionicons.
		foreach ( $class_tokens as $class_name ) {
			if ( isset( self::$ionicons_set[ $class_name ] ) ) {
				return [
					'icon'             => [
						'library' => 'ionicons',
						'icon'    => $class_name,
					],
					'consumed_classes' => [ $class_name ],
				];
			}
		}

		// Check Font Awesome.
		$fa_icon = null;

		foreach ( $class_tokens as $class_name ) {
			if ( isset( self::$fa_set[ $class_name ] ) ) {
				$fa_icon = $class_name;
				break;
			}
		}

		if ( ! $fa_icon ) {
			return null;
		}

		$style_library = self::detect_font_awesome_library( $class_tokens );

		if ( ! $style_library ) {
			return null;
		}

		$consumed = [ $fa_icon ];

		foreach ( self::$fa_style_tokens[ $style_library ] as $token ) {
			if ( in_array( $token, $class_tokens, true ) ) {
				$consumed[] = $token;
			}
		}

		return [
			'icon'             => [
				'library' => $style_library,
				'icon'    => self::$fa_icon_prefix[ $style_library ] . ' ' . $fa_icon,
			],
			'consumed_classes' => $consumed,
		];
	}

	/**
	 * Detect Font Awesome library from class tokens.
	 *
	 * @since 2.4
	 *
	 * @param array $class_tokens Array of class names.
	 * @return string|null Library name or null.
	 */
	private static function detect_font_awesome_library( $class_tokens = [] ) {
		$token_set = array_flip( $class_tokens );

		// Check Brands first (most specific).
		foreach ( self::$fa_style_tokens['fontawesomeBrands'] as $token ) {
			if ( isset( $token_set[ $token ] ) ) {
				return 'fontawesomeBrands';
			}
		}

		// Then Solid.
		foreach ( self::$fa_style_tokens['fontawesomeSolid'] as $token ) {
			if ( isset( $token_set[ $token ] ) ) {
				return 'fontawesomeSolid';
			}
		}

		// Then Regular.
		foreach ( self::$fa_style_tokens['fontawesomeRegular'] as $token ) {
			if ( isset( $token_set[ $token ] ) ) {
				return 'fontawesomeRegular';
			}
		}

		return null;
	}

	/**
	 * Map a single DOM element to a Bricks element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement                    $element         DOM element to map.
	 * @param string|null                    $parent_id       Parent element ID.
	 * @param Html_To_Bricks_Error_Collector $errors          Error collector.
	 * @param bool                           $preserve_inline Convert inline elements to child elements instead of folding them into parent text.
	 * @return array|null Bricks element array or null if skipped.
	 */
	public static function map_element( $element, $parent_id = null, $errors = null, $preserve_inline = false ) {
		if ( ! $errors ) {
			$errors = new Html_To_Bricks_Error_Collector();
		}

		// Skip text nodes handled separately.
		if ( $element->nodeType === XML_TEXT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return null;
		}

		// Skip comment nodes.
		if ( $element->nodeType === XML_COMMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return null;
		}

		if ( ! ( $element instanceof \DOMElement ) ) {
			return null;
		}

		$tag_name = strtolower( $element->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Skip certain elements.
		if ( self::should_skip_element( $tag_name ) ) {
			return null;
		}

		// Handle inline script placeholders.
		if ( $tag_name === 'bricks-script-placeholder' ) {
			$script_content = $element->getAttribute( 'data-script-content' );

			if ( $script_content ) {
				return [
					'id'       => self::generate_id(),
					'name'     => 'code',
					'parent'   => $parent_id ? $parent_id : 0,
					'settings' => [
						'executeCode'    => true,
						'javascriptCode' => $script_content,
					],
				];
			}

			return null;
		}

		$class_tokens        = Html_To_Bricks_Html_Parser::extract_classes( $element );
		$native_element_name = self::get_native_element_from_class_tokens( $class_tokens );
		$icon_match          = self::detect_supported_icon_from_class_tokens( $class_tokens );

		// Convert supported icon libraries to native Bricks icon element.
		if ( $tag_name === 'i' && $icon_match ) {
			$id             = self::generate_id();
			$bricks_element = [
				'id'       => $id,
				'name'     => 'icon',
				'parent'   => $parent_id ? $parent_id : 0,
				'settings' => [
					'icon' => $icon_match['icon'],
				],
			];

			$consumed_set = array_flip( $icon_match['consumed_classes'] );
			$remaining    = array_filter(
				$class_tokens,
				function ( $class_name ) use ( $consumed_set ) {
					return ! isset( $consumed_set[ $class_name ] ) && ! self::is_reserved_bricks_class( $class_name );
				}
			);

			if ( ! empty( $remaining ) ) {
				$bricks_element['settings']['_cssClasses'] = implode( ' ', $remaining );
			}

			$css_id = Html_To_Bricks_Html_Parser::extract_id( $element );

			if ( $css_id ) {
				$bricks_element['settings']['_cssId'] = $css_id;
			}

			return $bricks_element;
		}

		// Handle inline elements - they stay as HTML within parent unless a
		// linked container needs editable child elements.
		if ( self::is_inline_element( $tag_name ) && ! $preserve_inline ) {
			return null;
		}

		// Get mapping for this tag.
		$mapping     = self::get_tag_mapping( $tag_name );
		$is_fallback = false;

		// Handle unmapped elements - use div with custom tag.
		if ( ! $mapping ) {
			$mapping     = [
				'element'  => 'div',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => $tag_name
				],
				'fallback' => true,
			];
			$is_fallback = true;
		}

		// Check if this is a fallback element.
		if ( ! empty( $mapping['fallback'] ) ) {
			$is_fallback = true;
		}

		if ( $native_element_name ) {
			$semantic_settings = array_intersect_key(
				isset( $mapping['settings'] ) ? $mapping['settings'] : [],
				array_flip( [ 'tag', 'customTag' ] )
			);
			$mapping           = [ 'element' => $native_element_name ];

			if ( ! empty( $semantic_settings ) ) {
				$mapping['settings'] = $semantic_settings;
			}

			$is_fallback = false;
		}

		// A phrasing-only inline wrapper inside a structural parent should remain
		// one inline formatting context. Render the text element as the source tag
		// so descendant selectors still match without adding a block wrapper.
		if ( $preserve_inline && ! $native_element_name && self::is_inline_element( $tag_name ) && self::has_only_phrasing_content( $element ) ) {
			$mapping = [
				'element'  => 'text-basic',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => $tag_name,
				],
			];
		}

		// Check for child elements.
		$has_child_elements            = false;
		$has_non_inline_child_elements = false;

		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType === XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$has_child_elements = true;

				if ( ! self::is_inline_element( $child->tagName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$has_non_inline_child_elements = true;
					break;
				}
			}
		}

		// Linked card/container markup needs a nestable wrapper so child elements stay editable.
		if ( $tag_name === 'a' && $has_non_inline_child_elements ) {
			$mapping = [
				'element'  => 'div',
				'settings' => [ 'tag' => 'a' ],
				'nestable' => true,
			];
		}

		// Handle non-nestable button with child elements.
		if ( isset( $mapping['element'] ) && $mapping['element'] === 'button' ) {
			if ( $has_child_elements ) {
				$mapping = [
					'element'  => 'div',
					'settings' => [
						'tag'       => 'custom',
						'customTag' => 'button'
					],
					'nestable' => true,
				];
			}
		}

		// Keep nested structure for label wrappers used in complex toggles/components.
		if ( $tag_name === 'label' && $has_child_elements ) {
			$mapping = [
				'element'  => 'div',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => 'label'
				],
				'nestable' => true,
			];
		}

		// Check if element has only text content (no block-level child elements).
		$has_only_text_content = true;

		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType === XML_ELEMENT_NODE && ! self::is_inline_element( $child->tagName ) ) {
				$has_only_text_content = false;
				break;
			}
		}

		$text_content                = Html_To_Bricks_Html_Parser::get_direct_text_content( $element );
		$inner_html                  = Html_To_Bricks_Html_Parser::get_inner_html( $element );
		$can_collapse_text_container = (
			! $native_element_name &&
			(
				! $preserve_inline ||
				! self::is_inline_element( $tag_name ) ||
				self::has_only_phrasing_content( $element )
			)
		);

		// Convert text-only containers to text-basic for proper text display.
		if (
			$can_collapse_text_container &&
			$has_only_text_content &&
			( $text_content || $inner_html ) &&
			isset( $mapping['element'] ) &&
			$mapping['element'] === 'div' &&
			! in_array( $tag_name, self::$preserve_container_tags, true )
		) {
			$text_tag_candidates = [ 'span', 'figcaption', 'address', 'blockquote', 'label', 'p' ];
			$text_tag            = $preserve_inline || in_array( $tag_name, $text_tag_candidates, true ) ? $tag_name : 'div';

			$mapping = [
				'element'  => 'text-basic',
				'settings' => $text_tag === 'div'
					? [ 'tag' => 'div' ]
					: [
						'tag'       => 'custom',
						'customTag' => $text_tag
					],
			];
		}

		// Keep list item semantics while still collapsing text-only items.
		if ( $has_only_text_content && ( $text_content || $inner_html ) && $tag_name === 'li' ) {
			$mapping = [
				'element'  => 'text-basic',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => 'li'
				],
			];
		}

		// Generate element ID.
		$id = self::generate_id();

		$bricks_element_name = $mapping['element'];
		$nestable            = self::is_nestable_element( $bricks_element_name );

		// Build base element.
		$bricks_element = [
			'id'       => $id,
			'name'     => $bricks_element_name,
			'parent'   => $parent_id ? $parent_id : 0,
			'settings' => [],
		];

		// Add children array for nestable elements.
		if ( $nestable ) {
			$bricks_element['children'] = [];
		}

		// Apply settings from mapping.
		if ( ! empty( $mapping['settings'] ) ) {
			$bricks_element['settings'] = array_merge( $bricks_element['settings'], $mapping['settings'] );
		}

		// Extract CSS ID.
		$css_id = Html_To_Bricks_Html_Parser::extract_id( $element );

		if ( $css_id ) {
			$bricks_element['settings']['_cssId'] = $css_id;
		}

		// Extract CSS classes.
		$css_class_tokens = array_values(
			array_filter(
				$class_tokens,
				function ( $class_name ) {
					return ! self::is_reserved_bricks_class( $class_name );
				}
			)
		);

		if ( ! empty( $css_class_tokens ) && $bricks_element_name !== 'svg' ) {
			$bricks_element['settings']['_cssClasses'] = implode( ' ', $css_class_tokens );
		}

		// Extract inline styles.
		$inline_style = Html_To_Bricks_Html_Parser::extract_inline_style( $element );

		if ( $inline_style && $bricks_element_name !== 'svg' ) {
			$bricks_element['settings']['_inlineStyle'] = $inline_style;
		}

		// Extract all custom attributes.
		$data_attrs     = Html_To_Bricks_Html_Parser::extract_data_attributes( $element );
		$event_handlers = Html_To_Bricks_Html_Parser::extract_event_handlers( $element );
		$custom_attrs   = Html_To_Bricks_Html_Parser::extract_custom_attributes( $element );

		$all_attrs = 'svg' === $bricks_element_name ? [] : array_merge( $data_attrs, $event_handlers, $custom_attrs );

		if ( ! empty( $all_attrs ) ) {
			$bricks_element['settings']['_attributes'] = [];

			foreach ( $all_attrs as $name => $value ) {
				$bricks_element['settings']['_attributes'][] = [
					'id'    => self::generate_id(),
					'name'  => $name,
					'value' => $value,
				];
			}
		}

		if ( $tag_name === 'a' && $bricks_element_name === 'div' ) {
			self::map_anchor_attributes( $element, $bricks_element );
		}

		// Element-specific handling.
		switch ( $bricks_element_name ) {
			case 'heading':
				$bricks_element['settings']['text'] = Html_To_Bricks_Html_Parser::get_inner_html( $element );
				break;

			case 'text-basic':
				$bricks_element['settings']['text'] = Html_To_Bricks_Html_Parser::get_inner_html( $element );
				break;

			case 'text-link':
				self::map_link_element( $element, $bricks_element );
				break;

			case 'button':
				self::map_button_element( $element, $bricks_element );
				break;

			case 'image':
				self::map_image_element( $element, $bricks_element );
				break;

			case 'svg':
				self::map_svg_element( $element, $bricks_element );
				break;

			case 'video':
				self::map_video_element( $element, $bricks_element );
				break;

			case 'audio':
				self::map_audio_element( $element, $bricks_element );
				break;

			case 'code':
				if ( $is_fallback ) {
					$bricks_element['settings']['code']        = Html_To_Bricks_Html_Parser::get_outer_html( $element );
					$bricks_element['settings']['executeCode'] = true;
				}
				break;

			case 'form':
				$form_data = self::extract_form_fields( $element );

				if ( ! empty( $form_data['fields'] ) ) {
					$bricks_element['settings']['fields'] = $form_data['fields'];
				}

				if ( isset( $form_data['submit_button']['text'] ) && $form_data['submit_button']['text'] !== '' ) {
					$bricks_element['settings']['submitButtonText'] = $form_data['submit_button']['text'];
				}
				break;
		}

		// Avoid emitting empty text elements.
		if (
			( $bricks_element_name === 'heading' || $bricks_element_name === 'text-basic' ) &&
			( ! isset( $bricks_element['settings']['text'] ) || trim( $bricks_element['settings']['text'] ) === '' )
		) {
			return null;
		}

		return $bricks_element;
	}

	/**
	 * Extract form fields from a form element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $form_element DOM form element.
	 * @return array Object with 'fields' array and optional 'submit_button'.
	 */
	private static function extract_form_fields( $form_element ) {
		$fields        = [];
		$submit_button = null;
		$doc           = $form_element->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$xpath         = new \DOMXPath( $doc );

		$form_inputs = $xpath->query( './/input|.//select|.//textarea', $form_element );

		foreach ( $form_inputs as $input ) {
			$tag_name = strtolower( $input->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$type     = $input->getAttribute( 'type' ) ? $input->getAttribute( 'type' ) : ( $tag_name === 'textarea' ? 'textarea' : 'text' );

			// Handle submit buttons separately.
			if ( in_array( $type, [ 'submit', 'button' ], true ) ) {
				$submit_button = [
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'text' => $input->getAttribute( 'value' ) !== '' ? $input->getAttribute( 'value' ) : trim( $input->textContent ),
				];
				continue;
			}

			// Skip reset and image inputs.
			if ( in_array( $type, [ 'reset', 'image' ], true ) ) {
				continue;
			}

			// Map HTML input type to Bricks field type.
			$bricks_type = self::map_input_type_to_bricks_type( $type, $tag_name );

			// Find associated label.
			$label = self::find_label_for_input( $input, $form_element );

			$field = [
				'id'    => self::generate_id(),
				'type'  => $bricks_type,
				'label' => $label ? $label : ( $input->getAttribute( 'placeholder' ) ? $input->getAttribute( 'placeholder' ) : '' ),
			];

			// Add name if present.
			$name = $input->getAttribute( 'name' );

			if ( $name ) {
				$field['name'] = $name;
			}

			// Add placeholder.
			$placeholder = $input->getAttribute( 'placeholder' );

			if ( $placeholder ) {
				$field['placeholder'] = $placeholder;
			}

			// Add required attribute.
			if ( $input->hasAttribute( 'required' ) ) {
				$field['required'] = true;
			}

			// Add pattern if present.
			$pattern = $input->getAttribute( 'pattern' );

			if ( $pattern ) {
				$field['pattern'] = $pattern;
			}

			// Handle select options.
			if ( $tag_name === 'select' ) {
				$options = self::extract_select_options( $input );

				if ( ! empty( $options ) ) {
					$field['options'] = implode( "\n", $options );
				}
			}

			// Handle textarea rows.
			if ( $tag_name === 'textarea' ) {
				$rows = $input->getAttribute( 'rows' );

				if ( $rows ) {
					$field['rows'] = (int) $rows;
				}
			}

			// Handle file input accept.
			if ( $type === 'file' ) {
				$accept = $input->getAttribute( 'accept' );

				if ( $accept ) {
					$field['fileAccept'] = $accept;
				}

				if ( $input->hasAttribute( 'multiple' ) ) {
					$field['fileMultiple'] = true;
				}
			}

			// Handle checkbox/radio default checked state.
			if ( in_array( $type, [ 'checkbox', 'radio' ], true ) ) {
				if ( $input->hasAttribute( 'checked' ) ) {
					$field['checked'] = true;
				}
			}

			// Handle number input constraints.
			if ( $type === 'number' ) {
				$min  = $input->getAttribute( 'min' );
				$max  = $input->getAttribute( 'max' );
				$step = $input->getAttribute( 'step' );

				if ( $min !== '' ) {
					$field['min'] = $min;
				}

				if ( $max !== '' ) {
					$field['max'] = $max;
				}

				if ( $step !== '' ) {
					$field['step'] = $step;
				}
			}

			$fields[] = $field;
		}

		// Look for <button> elements.
		$buttons = $xpath->query( './/button', $form_element );

		foreach ( $buttons as $button ) {
			$button_type = $button->getAttribute( 'type' ) ? $button->getAttribute( 'type' ) : 'submit';

			if ( $button_type === 'submit' ) {
				$submit_button = [
					'text' => trim( $button->textContent ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				];
				break;
			}
		}

		// Look for <a> elements that act as submit buttons.
		if ( ! $submit_button ) {
			$links = $xpath->query( './/a', $form_element );

			foreach ( $links as $link ) {
				$link_text  = trim( $link->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$link_class = $link->getAttribute( 'class' ) ? $link->getAttribute( 'class' ) : '';

				if (
					$link_text &&
					(
						strpos( $link_class, 'btn' ) !== false ||
						strpos( $link_class, 'submit' ) !== false ||
						stripos( $link_text, 'submit' ) !== false ||
						stripos( $link_text, 'send' ) !== false ||
						stripos( $link_text, 'inquiry' ) !== false
					)
				) {
					$submit_button = [ 'text' => $link_text ];
					break;
				}
			}
		}

		return [
			'fields'        => $fields,
			'submit_button' => $submit_button,
		];
	}

	/**
	 * Map HTML input type to Bricks form field type.
	 *
	 * @since 2.4
	 *
	 * @param string $type     HTML input type.
	 * @param string $tag_name HTML tag name.
	 * @return string Bricks field type.
	 */
	private static function map_input_type_to_bricks_type( $type, $tag_name ) {
		if ( $tag_name === 'select' ) {
			return 'select';
		}

		if ( $tag_name === 'textarea' ) {
			return 'textarea';
		}

		return self::$input_type_map[ $type ] ?? 'text';
	}

	/**
	 * Find label associated with an input element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $input Input element.
	 * @param \DOMElement $form  Parent form element.
	 * @return string|null Label text or null.
	 */
	private static function find_label_for_input( $input, $form ) {
		$doc   = $form->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$xpath = new \DOMXPath( $doc );

		// Check for id and matching label[for].
		$id = $input->getAttribute( 'id' );

		if ( $id ) {
			$labels = $xpath->query( './/label[@for="' . $id . '"]', $form );

			if ( $labels->length > 0 ) {
				return trim( $labels->item( 0 )->textContent );
			}
		}

		// Check for wrapping label.
		$parent_label = Html_To_Bricks_Html_Parser::closest( $input, 'label' );

		if ( $parent_label ) {
			$clone  = $parent_label->cloneNode( true );
			$inputs = $xpath->query( './/input|.//select|.//textarea', $clone );

			foreach ( $inputs as $el ) {
				if ( $el->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$el->parentNode->removeChild( $el ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
			}

			$text = trim( $clone->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			return $text ? $text : null;
		}

		// Check for adjacent label (previous sibling).
		$prev = $input->previousSibling; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		while ( $prev && $prev->nodeType !== XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$prev = $prev->previousSibling; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		if ( $prev && $prev instanceof \DOMElement && strtolower( $prev->tagName ) === 'label' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return trim( $prev->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return null;
	}

	/**
	 * Extract options from a select element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $select Select element.
	 * @return array Array of option strings in "value : label" format.
	 */
	private static function extract_select_options( $select ) {
		$options = [];
		$doc     = $select->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$xpath   = new \DOMXPath( $doc );
		$opts    = $xpath->query( './/option', $select );

		foreach ( $opts as $option ) {
			// phpcs:ignore WordPress.PHP.DisallowShortTernary.Found, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$value = $option->getAttribute( 'value' ) ? $option->getAttribute( 'value' ) : trim( $option->textContent );
			$label = trim( $option->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( ! $label ) {
				continue;
			}

			if ( $value !== $label ) {
				$options[] = $value . ' : ' . $label;
			} else {
				$options[] = $label;
			}
		}

		return $options;
	}

	/**
	 * Map link-specific attributes.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element       DOM anchor element.
	 * @param array       $bricks_element Bricks element to populate (passed by reference).
	 */
	private static function map_link_element( $element, &$bricks_element ) {
		$leading_icon = self::extract_leading_supported_icon( $element );
		$text         = Html_To_Bricks_Html_Parser::get_inner_html( $element );

		if ( $leading_icon ) {
			$clone       = $element->cloneNode( true );
			$first_child = self::get_first_meaningful_child_node( $clone );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $first_child && $first_child->nodeType === XML_ELEMENT_NODE && $first_child->parentNode ) {
				$first_child->parentNode->removeChild( $first_child ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}

			$bricks_element['settings']['icon'] = $leading_icon['icon'];
			$text                               = ltrim( Html_To_Bricks_Html_Parser::get_inner_html( $clone ) );
		}

		$bricks_element['settings']['text'] = $text;

		self::map_anchor_attributes( $element, $bricks_element );
	}

	/**
	 * Map anchor attributes to Bricks link settings.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element        DOM anchor element.
	 * @param array       $bricks_element Bricks element to populate (passed by reference).
	 */
	private static function map_anchor_attributes( $element, &$bricks_element ) {
		$href = $element->getAttribute( 'href' );

		if ( $href ) {
			$bricks_element['settings']['link'] = [
				'type' => 'external',
				'url'  => $href,
			];

			$target = $element->getAttribute( 'target' );

			if ( $target === '_blank' ) {
				$bricks_element['settings']['link']['newTab'] = true;
			}

			$rel = $element->getAttribute( 'rel' );

			if ( $rel ) {
				$allowed_rel_tokens = [ 'nofollow', 'noopener', 'noreferrer', 'ugc', 'sponsored', 'external' ];
				$rel_tokens         = preg_split( '/\s+/', strtolower( trim( $rel ) ) );
				$safe_rel_tokens    = array_values( array_unique( array_intersect( $rel_tokens, $allowed_rel_tokens ) ) );

				if ( ! empty( $safe_rel_tokens ) ) {
					$bricks_element['settings']['link']['rel'] = implode( ' ', $safe_rel_tokens );
				}
			}
		}
	}

	/**
	 * Get first meaningful child node (non-empty text or element).
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return \DOMNode|null First meaningful child or null.
	 */
	private static function get_first_meaningful_child_node( $element ) {
		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType === XML_TEXT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( trim( $child->textContent ) !== '' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return $child;
				}
			} elseif ( $child->nodeType === XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return $child;
			}
		}

		return null;
	}

	/**
	 * Extract leading supported icon from element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return array|null Icon match or null.
	 */
	private static function extract_leading_supported_icon( $element ) {
		$first_child = self::get_first_meaningful_child_node( $element );

		if ( ! $first_child || $first_child->nodeType !== XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return null;
		}

		if ( strtolower( $first_child->tagName ) !== 'i' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return null;
		}

		return self::detect_supported_icon_from_class_tokens( Html_To_Bricks_Html_Parser::extract_classes( $first_child ) );
	}

	/**
	 * Map button-specific attributes.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element       DOM button element.
	 * @param array       $bricks_element Bricks element to populate (passed by reference).
	 */
	private static function map_button_element( $element, &$bricks_element ) {
		$text                               = Html_To_Bricks_Html_Parser::get_direct_text_content( $element );
		$bricks_element['settings']['text'] = $text ? $text : Html_To_Bricks_Html_Parser::get_inner_html( $element );

		if ( strtolower( $element->tagName ) === 'a' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			self::map_anchor_attributes( $element, $bricks_element );
			return;
		}

		// Check if it's actually a button nested in a link.
		$parent_link = Html_To_Bricks_Html_Parser::closest( $element, 'a' );

		if ( $parent_link ) {
			self::map_anchor_attributes( $parent_link, $bricks_element );
		}
	}

	/**
	 * Map image-specific attributes.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element       DOM image element.
	 * @param array       $bricks_element Bricks element to populate (passed by reference).
	 */
	private static function map_image_element( $element, &$bricks_element ) {
		$src     = $element->getAttribute( 'src' );
		$alt     = $element->getAttribute( 'alt' );
		$width   = $element->getAttribute( 'width' );
		$height  = $element->getAttribute( 'height' );
		$loading = $element->getAttribute( 'loading' );

		if ( $src ) {
			$bricks_element['settings']['_importImage'] = [
				'url' => $src,
				'alt' => $alt ? $alt : '',
			];

			$bricks_element['settings']['image'] = [
				'url'           => $src,
				'isPlaceholder' => true,
			];
		}

		if ( $alt ) {
			$bricks_element['settings']['altText'] = $alt;
		}

		if ( $width ) {
			$bricks_element['settings']['_width'] = $width . ( strpos( $width, '%' ) !== false ? '' : 'px' );
		}

		if ( $height ) {
			$bricks_element['settings']['_height'] = $height . ( strpos( $height, '%' ) !== false ? '' : 'px' );
		}

		if ( $loading ) {
			$bricks_element['settings']['loading'] = $loading;
		}
	}

	/**
	 * Map SVG-specific attributes.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element       DOM SVG element.
	 * @param array       $bricks_element Bricks element to populate (passed by reference).
	 */
	private static function map_svg_element( $element, &$bricks_element ) {
		$svg = $element->cloneNode( true );

		if ( ! $svg->hasAttribute( 'xmlns' ) ) {
			$svg->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		}

		$bricks_element['settings']['source'] = 'code';
		$bricks_element['settings']['code']   = self::normalize_svg_markup_case(
			Html_To_Bricks_Html_Parser::get_outer_html( $svg )
		);
	}

	/**
	 * Restore case-sensitive SVG names lowercased by DOMDocument's HTML parser.
	 *
	 * @since 2.4
	 *
	 * @param string $markup Serialized SVG markup.
	 * @return string
	 */
	private static function normalize_svg_markup_case( $markup ) {
		$element_names   = [
			'altglyph'            => 'altGlyph',
			'altglyphdef'         => 'altGlyphDef',
			'altglyphitem'        => 'altGlyphItem',
			'animatecolor'        => 'animateColor',
			'animatemotion'       => 'animateMotion',
			'animatetransform'    => 'animateTransform',
			'clippath'            => 'clipPath',
			'feblend'             => 'feBlend',
			'fecolormatrix'       => 'feColorMatrix',
			'fecomponenttransfer' => 'feComponentTransfer',
			'fecomposite'         => 'feComposite',
			'feconvolvematrix'    => 'feConvolveMatrix',
			'fediffuselighting'   => 'feDiffuseLighting',
			'fedisplacementmap'   => 'feDisplacementMap',
			'fedistantlight'      => 'feDistantLight',
			'fedropshadow'        => 'feDropShadow',
			'feflood'             => 'feFlood',
			'fefunca'             => 'feFuncA',
			'fefuncb'             => 'feFuncB',
			'fefuncg'             => 'feFuncG',
			'fefuncr'             => 'feFuncR',
			'fegaussianblur'      => 'feGaussianBlur',
			'feimage'             => 'feImage',
			'femerge'             => 'feMerge',
			'femergenode'         => 'feMergeNode',
			'femorphology'        => 'feMorphology',
			'feoffset'            => 'feOffset',
			'fepointlight'        => 'fePointLight',
			'fespecularlighting'  => 'feSpecularLighting',
			'fespotlight'         => 'feSpotLight',
			'fetile'              => 'feTile',
			'feturbulence'        => 'feTurbulence',
			'foreignobject'       => 'foreignObject',
			'glyphref'            => 'glyphRef',
			'lineargradient'      => 'linearGradient',
			'radialgradient'      => 'radialGradient',
			'textpath'            => 'textPath',
		];
		$attribute_names = [
			'attributename'       => 'attributeName',
			'attributetype'       => 'attributeType',
			'basefrequency'       => 'baseFrequency',
			'baseprofile'         => 'baseProfile',
			'calcmode'            => 'calcMode',
			'filterres'           => 'filterRes',
			'filterunits'         => 'filterUnits',
			'gradienttransform'   => 'gradientTransform',
			'gradientunits'       => 'gradientUnits',
			'kernelmatrix'        => 'kernelMatrix',
			'kernelunitlength'    => 'kernelUnitLength',
			'keypoints'           => 'keyPoints',
			'keysplines'          => 'keySplines',
			'keytimes'            => 'keyTimes',
			'lengthadjust'        => 'lengthAdjust',
			'markerheight'        => 'markerHeight',
			'markerunits'         => 'markerUnits',
			'markerwidth'         => 'markerWidth',
			'maskcontentunits'    => 'maskContentUnits',
			'maskunits'           => 'maskUnits',
			'numoctaves'          => 'numOctaves',
			'pathlength'          => 'pathLength',
			'patterncontentunits' => 'patternContentUnits',
			'patterntransform'    => 'patternTransform',
			'patternunits'        => 'patternUnits',
			'preservealpha'       => 'preserveAlpha',
			'preserveaspectratio' => 'preserveAspectRatio',
			'primitiveunits'      => 'primitiveUnits',
			'refx'                => 'refX',
			'refy'                => 'refY',
			'repeatcount'         => 'repeatCount',
			'repeatdur'           => 'repeatDur',
			'spreadmethod'        => 'spreadMethod',
			'startoffset'         => 'startOffset',
			'stddeviation'        => 'stdDeviation',
			'surfacescale'        => 'surfaceScale',
			'tablevalues'         => 'tableValues',
			'targetx'             => 'targetX',
			'targety'             => 'targetY',
			'textlength'          => 'textLength',
			'viewbox'             => 'viewBox',
			'xchannelselector'    => 'xChannelSelector',
			'ychannelselector'    => 'yChannelSelector',
		];

		$markup = preg_replace_callback(
			'/<(\s*\/?\s*)([a-z][a-z0-9]*)(?=[\s>\/])/i',
			function( $matches ) use ( $element_names ) {
				$name = strtolower( $matches[2] );
				return '<' . $matches[1] . ( $element_names[ $name ] ?? $matches[2] );
			},
			$markup
		);

		return preg_replace_callback(
			'/(\s)([a-z][a-z0-9:-]*)(\s*=)/i',
			function( $matches ) use ( $attribute_names ) {
				$name = strtolower( $matches[2] );
				return $matches[1] . ( $attribute_names[ $name ] ?? $matches[2] ) . $matches[3];
			},
			$markup
		);
	}

	/**
	 * Map video-specific attributes.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element       DOM video element.
	 * @param array       $bricks_element Bricks element to populate (passed by reference).
	 */
	private static function map_video_element( $element, &$bricks_element ) {
		$doc   = $element->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$xpath = new \DOMXPath( $doc );

		$src = $element->getAttribute( 'src' );

		if ( ! $src ) {
			$source_els = $xpath->query( './/source', $element );

			if ( $source_els->length > 0 ) {
				$src = $source_els->item( 0 )->getAttribute( 'src' );
			}
		}

		if ( $src ) {
			$bricks_element['settings']['videoType'] = 'file';
			$bricks_element['settings']['fileUrl']   = $src;
		}

		if ( $element->hasAttribute( 'autoplay' ) ) {
			$bricks_element['settings']['fileAutoplay'] = true;
		}

		if ( $element->hasAttribute( 'loop' ) ) {
			$bricks_element['settings']['fileLoop'] = true;
		}

		if ( $element->hasAttribute( 'muted' ) ) {
			$bricks_element['settings']['fileMute'] = true;
		}

		if ( $element->hasAttribute( 'controls' ) ) {
			$bricks_element['settings']['fileControls'] = true;
		}

		$poster = $element->getAttribute( 'poster' );

		if ( $poster ) {
			$bricks_element['settings']['videoPoster'] = [
				'url'      => $poster,
				'external' => true
			];
		}
	}

	/**
	 * Map audio-specific attributes.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element       DOM audio element.
	 * @param array       $bricks_element Bricks element to populate (passed by reference).
	 */
	private static function map_audio_element( $element, &$bricks_element ) {
		$doc   = $element->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$xpath = new \DOMXPath( $doc );

		$src = $element->getAttribute( 'src' );

		if ( ! $src ) {
			$source_els = $xpath->query( './/source', $element );

			if ( $source_els->length > 0 ) {
				$src = $source_els->item( 0 )->getAttribute( 'src' );
			}
		}

		if ( $src ) {
			$bricks_element['settings']['audio'] = [ 'url' => $src ];
		}

		if ( $element->hasAttribute( 'autoplay' ) ) {
			$bricks_element['settings']['autoplay'] = true;
		}

		if ( $element->hasAttribute( 'loop' ) ) {
			$bricks_element['settings']['loop'] = true;
		}

		if ( $element->hasAttribute( 'controls' ) ) {
			$bricks_element['settings']['controls'] = true;
		}
	}

	/**
	 * Recursively map DOM tree to Bricks elements.
	 *
	 * @since 2.4
	 *
	 * @param \DOMNode                       $node            DOM node to process.
	 * @param string|null                    $parent_id       Parent Bricks element ID.
	 * @param array                          $elements        Array to accumulate elements (passed by reference).
	 * @param Html_To_Bricks_Error_Collector $errors          Error collector.
	 * @param int                            $depth           Current nesting depth.
	 * @param bool                           $preserve_inline Convert inline elements as child elements.
	 * @return string|null ID of created element or null.
	 */
	public static function map_dom_tree( $node, $parent_id = null, &$elements = [], $errors = null, $depth = 0, $preserve_inline = false ) {
		if ( ! $errors ) {
			$errors = new Html_To_Bricks_Error_Collector();
		}

		// Warn about deep nesting.
		if ( $depth > 20 ) {
			$errors->add_warning(
				Html_To_Bricks_Warning_Codes::DEEP_NESTING,
				'htmlImportDeepNesting',
				[ 'depth' => $depth ]
			);
		}

		// Handle text nodes.
		if ( $node->nodeType === XML_TEXT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$text = trim( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( $text !== '' && $parent_id ) {
				$text_element = [
					'id'       => self::generate_id(),
					'name'     => 'text-basic',
					'parent'   => $parent_id,
					'settings' => [
						'tag'  => 'div',
						'text' => $text,
					],
				];

				$elements[] = $text_element;

				return $text_element['id'];
			}

			return null;
		}

		// Skip non-element nodes.
		if ( $node->nodeType !== XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return null;
		}

		// Map this element.
		$bricks_element = self::map_element( $node, $parent_id, $errors, $preserve_inline );

		// If element was skipped (inline, etc.), process children under current parent.
		if ( ! $bricks_element ) {
			foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				self::map_dom_tree( $child, $parent_id, $elements, $errors, $depth + 1 );
			}

			return null;
		}

		// Add element to list.
		$elements[] = $bricks_element;

		// If nestable, process children.
		if ( isset( $bricks_element['children'] ) ) {
			// We need to track the element index so we can update children array.
			$element_index            = count( $elements ) - 1;
			$preserve_inline_children = self::is_linked_container_element( $bricks_element );

			foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// Inline markup belongs to a text parent when the whole parent collapses
				// to text-basic. In a mixed-content nestable parent, preserve a direct
				// inline sibling as its own editable text element instead of dropping it.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$child_node_type         = $child->nodeType;
				$child_tag_name          = $child_node_type === XML_ELEMENT_NODE ? strtolower( $child->tagName ) : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$is_inline_child         = $child_node_type === XML_ELEMENT_NODE && self::is_inline_element( $child_tag_name );
				$is_supported_icon_child = (
					$child_node_type === XML_ELEMENT_NODE &&
					$child_tag_name === 'i' &&
					self::detect_supported_icon_from_class_tokens( Html_To_Bricks_Html_Parser::extract_classes( $child ) )
				);
				if ( $is_inline_child && ! $preserve_inline_children && trim( $child->textContent ) === '' && ! $is_supported_icon_child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				// Skip text nodes that are just whitespace.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $child->nodeType === XML_TEXT_NODE && trim( $child->textContent ) === '' ) {
					continue;
				}

				$child_id = self::map_dom_tree( $child, $bricks_element['id'], $elements, $errors, $depth + 1, $preserve_inline_children || $is_inline_child );

				if ( $child_id ) {
					$elements[ $element_index ]['children'][] = $child_id;
				}
			}
		}

		return $bricks_element['id'];
	}

	/**
	 * Check whether an element is a linked layout container.
	 *
	 * @since 2.4
	 *
	 * @param array $bricks_element Bricks element.
	 * @return bool
	 */
	private static function is_linked_container_element( $bricks_element ) {
		return isset( $bricks_element['name'], $bricks_element['settings']['tag'] ) &&
			$bricks_element['name'] === 'div' &&
			$bricks_element['settings']['tag'] === 'a';
	}

	/**
	 * Convert DOM fragment/element to Bricks elements array.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement                    $body_content Body element or fragment wrapper.
	 * @param Html_To_Bricks_Error_Collector $errors       Error collector.
	 * @return array Array of Bricks elements.
	 */
	public static function convert_to_elements( $body_content, $errors = null ) {
		if ( ! $errors ) {
			$errors = new Html_To_Bricks_Error_Collector();
		}

		$elements = [];

		foreach ( $body_content->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			self::map_dom_tree( $node, null, $elements, $errors, 0 );
		}

		return $elements;
	}
}
