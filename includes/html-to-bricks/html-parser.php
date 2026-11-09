<?php
/**
 * HTML Parser for HTML to Bricks Converter
 *
 * Parses HTML string and extracts styles, scripts, and body content.
 * Uses PHP DOMDocument instead of browser DOMParser.
 *
 * PHP port of src/vue/utils/htmlToBricks/htmlParser.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML parser for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Html_Parser {

	/**
	 * Void (self-closing) elements.
	 *
	 * @var array
	 */
	private static $void_elements = [
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	];

	/**
	 * Block-level elements.
	 *
	 * @var array
	 */
	private static $block_elements = [
		'address',
		'article',
		'aside',
		'blockquote',
		'canvas',
		'dd',
		'div',
		'dl',
		'dt',
		'fieldset',
		'figcaption',
		'figure',
		'footer',
		'form',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'header',
		'hr',
		'li',
		'main',
		'nav',
		'noscript',
		'ol',
		'p',
		'pre',
		'section',
		'table',
		'tfoot',
		'ul',
		'video',
	];

	/**
	 * Event handler attribute names.
	 *
	 * @var array
	 */
	private static $event_attributes = [
		'onclick',
		'onchange',
		'onsubmit',
		'onmouseover',
		'onmouseout',
		'onmouseenter',
		'onmouseleave',
		'onfocus',
		'onblur',
		'onkeydown',
		'onkeyup',
		'onkeypress',
		'onload',
		'onerror',
		'onscroll',
		'onresize',
	];

	/**
	 * Attributes handled by dedicated extractors or element-specific mappers.
	 *
	 * @var array
	 */
	private static $handled_attributes = [
		'id',
		'class',
		'style',
		// Element-specific (handled in map*Element functions).
		'src',
		'alt',
		'width',
		'height',
		'loading',
		'href',
		'target',
		'rel',
		'autoplay',
		'loop',
		'muted',
		'controls',
		'poster',
		'type',
		'name',
		'placeholder',
		'required',
		'pattern',
		'rows',
		'accept',
		'multiple',
		'checked',
		'min',
		'max',
		'step',
		'value',
		'for',
		'action',
		'method',
	];

	/**
	 * Content tags that are considered "content" even when empty.
	 *
	 * @var array
	 */
	private static $content_tags = [ 'img', 'video', 'audio', 'iframe', 'canvas', 'svg' ];

	/**
	 * Parse HTML string and extract components.
	 *
	 * @since 2.4
	 *
	 * @param string $html_string Raw HTML string to parse.
	 * @return array {
	 *     @type bool                              $success              Whether parsing was successful.
	 *     @type \DOMElement|null                   $body_content         Parsed body element (or fragment wrapper).
	 *     @type \DOMDocument|null                  $doc                  The DOMDocument instance (needed for saveHTML).
	 *     @type array                              $styles               Array of extracted CSS strings.
	 *     @type array                              $scripts              Array of extracted script objects.
	 *     @type array                              $external_stylesheets Array of external stylesheet URLs.
	 *     @type array                              $external_scripts     Array of external script URLs.
	 *     @type Html_To_Bricks_Error_Collector     $errors               Error collector.
	 * }
	 */
	public static function parse_html( $html_string ) {
		$errors = new Html_To_Bricks_Error_Collector();
		$result = [
			'success'              => false,
			'body_content'         => null,
			'doc'                  => null,
			'styles'               => [],
			'scripts'              => [],
			'external_stylesheets' => [],
			'external_scripts'     => [],
			'errors'               => $errors,
		];

		// Validate input.
		if ( ! $html_string || ! is_string( $html_string ) ) {
			$errors->add_error(
				Html_To_Bricks_Error_Codes::EMPTY_INPUT,
				'htmlImportParseError',
				[ 'reason' => 'Input is empty or not a string' ]
			);
			return $result;
		}

		$trimmed = trim( $html_string );

		if ( strlen( $trimmed ) === 0 ) {
			$errors->add_error(
				Html_To_Bricks_Error_Codes::EMPTY_INPUT,
				'htmlImportParseError',
				[ 'reason' => 'Input is empty' ]
			);
			return $result;
		}

		$previous_libxml_errors = libxml_use_internal_errors( true );

		try {
			$doc = new \DOMDocument();

			// Wrap in DOCTYPE + html + body to ensure proper parsing.
			$wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $trimmed . '</body></html>';
			$doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			libxml_clear_errors();

			$xpath = new \DOMXPath( $doc );

			// Extract <style> tags.
			$style_nodes      = $xpath->query( '//style' );
			$styles_to_remove = [];

			foreach ( $style_nodes as $style_tag ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$css_content = trim( $style_tag->textContent );

				if ( $css_content ) {
					$result['styles'][] = $css_content;
				}

				$styles_to_remove[] = $style_tag;
			}

			foreach ( $styles_to_remove as $node ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $node->parentNode ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$node->parentNode->removeChild( $node );
				}
			}

			// Extract <link rel="stylesheet"> tags.
			$link_nodes      = $xpath->query( '//link[@rel="stylesheet"]' );
			$links_to_remove = [];

			foreach ( $link_nodes as $link_tag ) {
				$href = $link_tag->getAttribute( 'href' );

				if ( $href ) {
					$result['external_stylesheets'][] = $href;
				}

				$links_to_remove[] = $link_tag;
			}

			foreach ( $links_to_remove as $node ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $node->parentNode ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$node->parentNode->removeChild( $node );
				}
			}

			// Extract <script> tags.
			$script_nodes       = $xpath->query( '//script' );
			$scripts_to_process = [];

			// Collect first, then process (to avoid modifying DOM while iterating).
			foreach ( $script_nodes as $script_tag ) {
				$scripts_to_process[] = $script_tag;
			}

			foreach ( $scripts_to_process as $script_tag ) {
				$src  = $script_tag->getAttribute( 'src' );
				$type = $script_tag->getAttribute( 'type' ) ? $script_tag->getAttribute( 'type' ) : 'text/javascript';

				// Skip non-JavaScript scripts (like JSON-LD).
				if ( $type && strpos( $type, 'javascript' ) === false && $type !== 'module' ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $script_tag->parentNode ) {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$script_tag->parentNode->removeChild( $script_tag );
					}
					continue;
				}

				if ( $src ) {
					// External script.
					$result['external_scripts'][] = $src;

					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $script_tag->parentNode ) {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$script_tag->parentNode->removeChild( $script_tag );
					}
				} else {
					// Inline script: replace with placeholder to preserve DOM position.
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$script_content = trim( $script_tag->textContent );

					if ( $script_content ) {
						$result['scripts'][] = [
							'content' => $script_content,
							'type'    => $type,
						];

						$placeholder = $doc->createElement( 'bricks-script-placeholder' );
						$placeholder->setAttribute( 'data-script-content', $script_content );

						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( $script_tag->parentNode ) {
							// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							$script_tag->parentNode->replaceChild( $placeholder, $script_tag );
						}
					} else {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( $script_tag->parentNode ) {
							// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							$script_tag->parentNode->removeChild( $script_tag );
						}
					}
				}
			}

			// Remove non-content head tags.
			$head = $doc->getElementsByTagName( 'head' )->item( 0 );

			if ( $head ) {
				$head_removals = $xpath->query( './/title|.//meta|.//base', $head );
				$to_remove     = [];

				foreach ( $head_removals as $tag ) {
					$to_remove[] = $tag;
				}

				// Also remove non-stylesheet link tags in head.
				$head_links = $xpath->query( './/link[not(@rel="stylesheet")]', $head );

				foreach ( $head_links as $tag ) {
					$to_remove[] = $tag;
				}

				foreach ( $to_remove as $node ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $node->parentNode ) {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$node->parentNode->removeChild( $node );
					}
				}
			}

			// Get body content.
			$body = $doc->getElementsByTagName( 'body' )->item( 0 );

			if ( ! $body || ! self::has_child_elements( $body ) ) {
				// Try fragment parsing if body is empty.
				$fragment_result = self::parse_html_fragment( $trimmed );

				if ( $fragment_result && $fragment_result['body_content'] ) {
					$result['body_content'] = $fragment_result['body_content'];
					$result['doc']          = $fragment_result['doc'];
					$result['success']      = true;
					return $result;
				}
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $body && $body->childNodes->length > 0 ) {
				$result['body_content'] = $body;
				$result['doc']          = $doc;
				$result['success']      = true;
			} else {
				$errors->add_error(
					Html_To_Bricks_Error_Codes::INVALID_HTML,
					'htmlImportParseError',
					[ 'reason' => 'No content found in HTML' ]
				);
			}
		} catch ( \Exception $e ) {
			$errors->add_error(
				Html_To_Bricks_Error_Codes::PARSE_ERROR,
				'htmlImportParseError',
				[ 'reason' => $e->getMessage() ]
			);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml_errors );
		}

		return $result;
	}

	/**
	 * Parse HTML fragment (without html/body wrapper).
	 *
	 * @since 2.4
	 *
	 * @param string $html_string HTML fragment string.
	 * @return array|null Array with body_content and doc, or null on failure.
	 */
	public static function parse_html_fragment( $html_string ) {
		$previous_libxml_errors = libxml_use_internal_errors( true );

		try {
			$doc = new \DOMDocument();

			$wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . trim( $html_string ) . '</body></html>';
			$doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			libxml_clear_errors();

			$body = $doc->getElementsByTagName( 'body' )->item( 0 );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $body && $body->childNodes->length > 0 ) {
				return [
					'body_content' => $body,
					'doc'          => $doc,
				];
			}

			return null;
		} catch ( \Exception $e ) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml_errors );
		}
	}

	/**
	 * Extract inline styles from an element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return string|null Inline style string or null.
	 */
	public static function extract_inline_style( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return null;
		}

		$style = $element->getAttribute( 'style' );

		return $style ? trim( $style ) : null;
	}

	/**
	 * Extract classes from an element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return array Array of class names.
	 */
	public static function extract_classes( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return [];
		}

		$class_name = $element->getAttribute( 'class' );

		if ( ! $class_name ) {
			return [];
		}

		$classes = preg_split( '/\s+/', trim( $class_name ) );

		return array_values(
			array_filter(
				$classes,
				function ( $c ) {
					return strlen( $c ) > 0;
				}
			)
		);
	}

	/**
	 * Extract ID from an element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return string|null ID or null.
	 */
	public static function extract_id( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return null;
		}

		$id = $element->getAttribute( 'id' );

		return $id ? trim( $id ) : null;
	}

	/**
	 * Extract data attributes from an element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return array Object with data attribute key-value pairs.
	 */
	public static function extract_data_attributes( $element ) {
		if ( ! ( $element instanceof \DOMElement ) || ! $element->attributes ) {
			return [];
		}

		$data_attrs = [];

		foreach ( $element->attributes as $attr ) {
			if ( strpos( $attr->name, 'data-' ) === 0 ) {
				$data_attrs[ $attr->name ] = $attr->value;
			}
		}

		return $data_attrs;
	}

	/**
	 * Extract event handlers from an element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return array Object with event handler attributes.
	 */
	public static function extract_event_handlers( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return [];
		}

		$event_handlers = [];

		foreach ( self::$event_attributes as $attr ) {
			if ( $element->hasAttribute( $attr ) ) {
				$event_handlers[ $attr ] = $element->getAttribute( $attr );
			}
		}

		return $event_handlers;
	}

	/**
	 * Extract custom HTML attributes not already handled by dedicated mappers.
	 *
	 * Captures aria-*, role, tabindex, title, and any other attributes
	 * that are not already extracted by other helpers (id, class, style,
	 * data-*, on* event handlers, and element-specific attributes).
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return array Object with attribute key-value pairs.
	 */
	public static function extract_custom_attributes( $element ) {
		if ( ! ( $element instanceof \DOMElement ) || ! $element->attributes ) {
			return [];
		}

		$custom_attrs = [];

		foreach ( $element->attributes as $attr ) {
			// Skip data-* attributes (handled by extractDataAttributes).
			if ( strpos( $attr->name, 'data-' ) === 0 ) {
				continue;
			}

			// Skip on* event handlers (handled by extractEventHandlers).
			if ( strpos( $attr->name, 'on' ) === 0 ) {
				continue;
			}

			// Skip attributes handled by dedicated mappers.
			if ( in_array( $attr->name, self::$handled_attributes, true ) ) {
				continue;
			}

			$custom_attrs[ $attr->name ] = $attr->value;
		}

		return $custom_attrs;
	}

	/**
	 * Check if element has meaningful content.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return bool True if element has content.
	 */
	public static function has_content( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return false;
		}

		// Check for text content.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$text_content = $element->textContent;

		if ( $text_content !== null && trim( $text_content ) !== '' ) {
			return true;
		}

		// Check for child elements.
		if ( self::has_child_elements( $element ) ) {
			return true;
		}

		// Check for specific content elements.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( in_array( strtolower( $element->tagName ), self::$content_tags, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get text content from element (direct text only, not from children).
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return string Text content.
	 */
	public static function get_direct_text_content( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return '';
		}

		$text = '';

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $element->childNodes as $node ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $node->nodeType === XML_TEXT_NODE ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$text .= $node->textContent;
			}
		}

		return trim( $text );
	}

	/**
	 * Get inner HTML preserving formatting for rich text elements.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement       $element DOM element.
	 * @param \DOMDocument|null $doc Optional DOMDocument (uses ownerDocument if null).
	 * @return string Inner HTML.
	 */
	public static function get_inner_html( $element, $doc = null ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$doc   = $doc ? $doc : $element->ownerDocument;
		$inner = '';

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $element->childNodes as $child ) {
			$inner .= $doc->saveHTML( $child );
		}

		return trim( $inner );
	}

	/**
	 * Get outer HTML of an element.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement       $element DOM element.
	 * @param \DOMDocument|null $doc Optional DOMDocument (uses ownerDocument if null).
	 * @return string Outer HTML.
	 */
	public static function get_outer_html( $element, $doc = null ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$doc = $doc ? $doc : $element->ownerDocument;

		return $doc->saveHTML( $element );
	}

	/**
	 * Check if element is a void element (self-closing).
	 *
	 * @since 2.4
	 *
	 * @param string $tag_name Tag name.
	 * @return bool True if void element.
	 */
	public static function is_void_element( $tag_name ) {
		return in_array( strtolower( $tag_name ), self::$void_elements, true );
	}

	/**
	 * Check if element is a block-level element.
	 *
	 * @since 2.4
	 *
	 * @param string $tag_name Tag name.
	 * @return bool True if block element.
	 */
	public static function is_block_element( $tag_name ) {
		return in_array( strtolower( $tag_name ), self::$block_elements, true );
	}

	/**
	 * Get child elements (element nodes only, not text nodes).
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return array Array of DOMElement children.
	 */
	public static function get_child_elements( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return [];
		}

		$children = [];

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $element->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType === XML_ELEMENT_NODE ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Check if element has any child element nodes.
	 *
	 * @since 2.4
	 *
	 * @param \DOMElement $element DOM element.
	 * @return bool True if element has child elements.
	 */
	private static function has_child_elements( $element ) {
		if ( ! ( $element instanceof \DOMElement ) ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $element->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType === XML_ELEMENT_NODE ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the closest ancestor element with a given tag name.
	 *
	 * PHP equivalent of element.closest('tagname').
	 *
	 * @since 2.4
	 *
	 * @param \DOMNode $node     Starting node.
	 * @param string   $tag_name Tag name to search for.
	 * @return \DOMElement|null The closest matching ancestor or null.
	 */
	public static function closest( $node, $tag_name ) {
		$tag_name = strtolower( $tag_name );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$current = $node->parentNode;

		while ( $current && $current instanceof \DOMElement ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( strtolower( $current->tagName ) === $tag_name ) {
				return $current;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$current = $current->parentNode;
		}

		return null;
	}
}
