<?php
/**
 * HTML to Bricks Element Mappings
 *
 * Static tag-to-element mappings for HTML to Bricks conversion.
 *
 * PHP port of src/vue/utils/htmlToBricks/elementMappings.json
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Element mappings for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Element_Mappings {
	/**
	 * Get the full mappings data.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_mappings() {
		return [
			'section'    => [
				'bricksElement' => 'section',
				'nestable'      => true,
				'defaultTag'    => 'section',
				'supportedTags' => [ 'section', 'header', 'footer', 'article', 'aside', 'div', 'custom' ],
			],
			'container'  => [
				'bricksElement' => 'container',
				'nestable'      => true,
				'defaultTag'    => 'div',
				'supportedTags' => [ 'div', 'custom' ],
			],
			'block'      => [
				'bricksElement' => 'block',
				'nestable'      => true,
				'defaultTag'    => 'div',
				'supportedTags' => [ 'div', 'article', 'custom' ],
			],
			'div'        => [
				'bricksElement' => 'div',
				'nestable'      => true,
				'defaultTag'    => 'div',
				'supportedTags' => [ 'div', 'nav', 'article', 'a', 'ul', 'ol', 'li', 'figure', 'address', 'custom' ],
			],
			'heading'    => [
				'bricksElement' => 'heading',
				'nestable'      => false,
				'defaultTag'    => 'h3',
				'supportedTags' => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'custom' ],
			],
			'text-basic' => [
				'bricksElement' => 'text-basic',
				'nestable'      => false,
				'defaultTag'    => 'div',
				'supportedTags' => [ 'div', 'p', 'span', 'figcaption', 'address', 'figure', 'custom' ],
			],
			'text-link'  => [
				'bricksElement' => 'text-link',
				'nestable'      => false,
				'defaultTag'    => 'a',
				'supportedTags' => [ 'a', 'span' ],
			],
			'icon'       => [
				'bricksElement' => 'icon',
				'nestable'      => false,
			],
			'button'     => [
				'bricksElement' => 'button',
				'nestable'      => false,
				'defaultTag'    => 'button',
				'supportedTags' => [ 'button', 'a', 'span', 'custom' ],
			],
			'image'      => [
				'bricksElement' => 'image',
				'nestable'      => false,
			],
			'svg'        => [
				'bricksElement' => 'svg',
				'nestable'      => false,
			],
			'video'      => [
				'bricksElement' => 'video',
				'nestable'      => false,
			],
			'audio'      => [
				'bricksElement' => 'audio',
				'nestable'      => false,
			],
			'code'       => [
				'bricksElement' => 'code',
				'nestable'      => false,
			],
			'divider'    => [
				'bricksElement' => 'divider',
				'nestable'      => false,
			],
			'form'       => [
				'bricksElement' => 'form',
				'nestable'      => false,
			],
		];
	}

	/**
	 * Get HTML tag to Bricks element mapping.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_html_to_element() {
		return [
			'section'    => [ 'element' => 'section' ],
			'header'     => [
				'element'  => 'section',
				'settings' => [ 'tag' => 'header' ]
			],
			'footer'     => [
				'element'  => 'section',
				'settings' => [ 'tag' => 'footer' ]
			],
			'article'    => [
				'element'  => 'div',
				'settings' => [ 'tag' => 'article' ]
			],
			'aside'      => [
				'element'  => 'section',
				'settings' => [ 'tag' => 'aside' ]
			],

			'div'        => [ 'element' => 'div' ],
			'nav'        => [
				'element'  => 'div',
				'settings' => [ 'tag' => 'nav' ]
			],
			'main'       => [
				'element'  => 'div',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => 'main'
				]
			],
			'span'       => [
				'element'  => 'div',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => 'span'
				]
			],
			'ul'         => [
				'element'  => 'div',
				'settings' => [ 'tag' => 'ul' ]
			],
			'ol'         => [
				'element'  => 'div',
				'settings' => [ 'tag' => 'ol' ]
			],
			'li'         => [
				'element'  => 'div',
				'settings' => [ 'tag' => 'li' ]
			],
			'figure'     => [
				'element'  => 'div',
				'settings' => [ 'tag' => 'figure' ]
			],
			'figcaption' => [
				'element'  => 'div',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => 'figcaption'
				]
			],
			'address'    => [
				'element'  => 'div',
				'settings' => [ 'tag' => 'address' ]
			],
			'blockquote' => [
				'element'  => 'div',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => 'blockquote'
				]
			],

			'h1'         => [
				'element'  => 'heading',
				'settings' => [ 'tag' => 'h1' ]
			],
			'h2'         => [
				'element'  => 'heading',
				'settings' => [ 'tag' => 'h2' ]
			],
			'h3'         => [
				'element'  => 'heading',
				'settings' => [ 'tag' => 'h3' ]
			],
			'h4'         => [
				'element'  => 'heading',
				'settings' => [ 'tag' => 'h4' ]
			],
			'h5'         => [
				'element'  => 'heading',
				'settings' => [ 'tag' => 'h5' ]
			],
			'h6'         => [
				'element'  => 'heading',
				'settings' => [ 'tag' => 'h6' ]
			],

			'p'          => [
				'element'  => 'text-basic',
				'settings' => [ 'tag' => 'p' ]
			],
			'label'      => [
				'element'  => 'text-basic',
				'settings' => [
					'tag'       => 'custom',
					'customTag' => 'label'
				]
			],

			'a'          => [ 'element' => 'text-link' ],
			'button'     => [ 'element' => 'button' ],

			'img'        => [ 'element' => 'image' ],
			'svg'        => [
				'element'  => 'svg',
				'settings' => [ 'source' => 'code' ]
			],

			'video'      => [ 'element' => 'video' ],
			'audio'      => [ 'element' => 'audio' ],

			'hr'         => [ 'element' => 'divider' ],

			'form'       => [ 'element' => 'form' ],

			'pre'        => [
				'element'  => 'code',
				'fallback' => true
			],
			'code'       => [
				'element'  => 'code',
				'fallback' => true
			],
			'iframe'     => [
				'element'  => 'code',
				'fallback' => true
			],
			'canvas'     => [
				'element'  => 'code',
				'fallback' => true
			],
			'table'      => [
				'element'  => 'code',
				'fallback' => true
			],
			'thead'      => [
				'element'  => 'code',
				'fallback' => true
			],
			'tbody'      => [
				'element'  => 'code',
				'fallback' => true
			],
			'tfoot'      => [
				'element'  => 'code',
				'fallback' => true
			],
			'tr'         => [
				'element'  => 'code',
				'fallback' => true
			],
			'td'         => [
				'element'  => 'code',
				'fallback' => true
			],
			'th'         => [
				'element'  => 'code',
				'fallback' => true
			],
		];
	}

	/**
	 * Get inline elements that should be preserved as HTML within text content.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_inline_elements() {
		return [
			'strong',
			'b',
			'em',
			'i',
			'u',
			's',
			'strike',
			'del',
			'ins',
			'mark',
			'small',
			'sub',
			'sup',
			'abbr',
			'cite',
			'dfn',
			'kbd',
			'samp',
			'var',
			'time',
			'q',
		];
	}

	/**
	 * Get elements that should be skipped during parsing.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_skip_elements() {
		return [ 'br', 'wbr', 'script', 'style', 'link', 'meta', 'title', 'noscript' ];
	}
}
