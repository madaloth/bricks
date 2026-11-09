<?php
/**
 * JavaScript Handler for HTML to Bricks Converter
 *
 * Collects all JavaScript (inline and external) into a single Code element.
 *
 * PHP port of src/vue/utils/htmlToBricks/jsHandler.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JavaScript handler for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Js_Handler {
	const GENERATED_JS_COMMENT   = '// Extracted from pasted content. Enable execution only if you trust this code.';
	const GENERATED_CSS_COMMENT  = '/* Extracted from pasted content that could not be mapped to a specific element or class. */';
	const GENERATED_HTML_COMMENT = '<!-- Extracted from pasted content that could not be mapped to a specific element or class. -->';

	/**
	 * Prepend a generated comment to content.
	 *
	 * @since 2.4
	 *
	 * @param string $content The content.
	 * @param string $comment The comment to prepend.
	 * @return string
	 */
	private static function prepend_generated_comment( $content, $comment ) {
		if ( ! $content || ! is_string( $content ) ) {
			return '';
		}

		$trimmed = trim( $content );

		if ( ! $trimmed ) {
			return '';
		}

		if ( strpos( $trimmed, $comment ) === 0 ) {
			return $trimmed;
		}

		return "{$comment}\n{$trimmed}";
	}

	/**
	 * Create a Bricks Code element.
	 *
	 * @since 2.4
	 *
	 * @param array $options Code element options (jsCode, cssCode, htmlCode).
	 * @return array Bricks Code element.
	 */
	public static function create_code_element( $options = [] ) {
		$js_code   = isset( $options['jsCode'] ) ? $options['jsCode'] : '';
		$css_code  = isset( $options['cssCode'] ) ? $options['cssCode'] : '';
		$html_code = isset( $options['htmlCode'] ) ? $options['htmlCode'] : '';

		$element = [
			'id'       => Html_To_Bricks_Element_Mapper::generate_id(),
			'name'     => 'code',
			'parent'   => 0,
			'settings' => [
				'executeCode' => true,
			],
		];

		if ( $html_code ) {
			$element['settings']['code'] = self::prepend_generated_comment( $html_code, self::GENERATED_HTML_COMMENT );
		}

		if ( $css_code ) {
			$element['settings']['cssCode'] = self::prepend_generated_comment( $css_code, self::GENERATED_CSS_COMMENT );
		}

		if ( $js_code ) {
			$element['settings']['javascriptCode'] = self::prepend_generated_comment( $js_code, self::GENERATED_JS_COMMENT );
		}

		return $element;
	}

	/**
	 * Build HTML for external stylesheets.
	 *
	 * @since 2.4
	 *
	 * @param array $external_stylesheets Array of external stylesheet URLs.
	 * @return string HTML string.
	 */
	private static function build_external_stylesheets_html( $external_stylesheets = [] ) {
		if ( ! is_array( $external_stylesheets ) || empty( $external_stylesheets ) ) {
			return '';
		}

		$links = [];

		foreach ( $external_stylesheets as $href ) {
			$sanitized = self::sanitize_stylesheet_href( $href );

			if ( $sanitized ) {
				$links[] = '<link rel="stylesheet" href="' . $sanitized . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			}
		}

		return implode( "\n", $links );
	}

	/**
	 * Escape an HTML attribute value.
	 *
	 * @since 2.4
	 *
	 * @param string $value The value to escape.
	 * @return string
	 */
	private static function escape_html_attribute( $value ) {
		$str = (string) $value;
		$str = str_replace( '&', '&amp;', $str );
		$str = str_replace( '"', '&quot;', $str );
		$str = str_replace( '<', '&lt;', $str );
		$str = str_replace( '>', '&gt;', $str );
		return $str;
	}

	/**
	 * Sanitize a stylesheet href.
	 *
	 * @since 2.4
	 *
	 * @param string $href The href to sanitize.
	 * @return string Sanitized href or empty string.
	 */
	private static function sanitize_stylesheet_href( $href ) {
		if ( ! is_string( $href ) ) {
			return '';
		}

		$trimmed = trim( $href );

		if ( ! $trimmed ) {
			return '';
		}

		// Block executable/smuggled URL schemes
		if ( preg_match( '/^(javascript|data)\s*:/i', $trimmed ) ) {
			return '';
		}

		return self::escape_html_attribute( $trimmed );
	}

	/**
	 * Build HTML for external scripts.
	 *
	 * @since 2.4
	 *
	 * @param array $external_scripts Array of external script URLs.
	 * @return string HTML string.
	 */
	private static function build_external_scripts_html( $external_scripts = [] ) {
		if ( ! is_array( $external_scripts ) || empty( $external_scripts ) ) {
			return '';
		}

		$scripts = [];

		foreach ( $external_scripts as $src ) {
			$sanitized = self::sanitize_script_src( $src );

			if ( $sanitized ) {
				$scripts[] = '<script src="' . $sanitized . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
			}
		}

		return implode( "\n", $scripts );
	}

	/**
	 * Sanitize a script src.
	 *
	 * @since 2.4
	 *
	 * @param string $src The src to sanitize.
	 * @return string Sanitized src or empty string.
	 */
	private static function sanitize_script_src( $src ) {
		if ( ! is_string( $src ) ) {
			return '';
		}

		$trimmed = trim( $src );

		if ( ! $trimmed ) {
			return '';
		}

		// Block executable/smuggled URL schemes
		if ( preg_match( '/^(javascript|data)\s*:/i', $trimmed ) ) {
			return '';
		}

		return self::escape_html_attribute( $trimmed );
	}

	/**
	 * Create a Code element for global CSS and external stylesheets (no JS).
	 *
	 * Placed at the top of the elements list so styles are available immediately.
	 *
	 * @since 2.4
	 *
	 * @param string $global_css            Global CSS to include.
	 * @param array  $external_stylesheets  Array of external stylesheet URLs.
	 * @return array|null Bricks Code element or null if no content.
	 */
	public static function create_css_code_element( $global_css = '', $external_stylesheets = [] ) {
		$html_code = self::build_external_stylesheets_html( $external_stylesheets );

		if ( ! $global_css && ! $html_code ) {
			return null;
		}

		return self::create_code_element(
			[
				'cssCode'  => $global_css,
				'htmlCode' => $html_code,
			]
		);
	}

	/**
	 * Create a Code element for external script references.
	 *
	 * Placed at the bottom of the elements list.
	 *
	 * @since 2.4
	 *
	 * @param array $external_scripts Array of external script URLs.
	 * @return array|null Bricks Code element or null if no scripts.
	 */
	public static function create_external_scripts_code_element( $external_scripts = [] ) {
		if ( ! is_array( $external_scripts ) || empty( $external_scripts ) ) {
			return null;
		}

		$html_code = self::build_external_scripts_html( $external_scripts );

		if ( ! $html_code ) {
			return null;
		}

		return self::create_code_element( [ 'htmlCode' => $html_code ] );
	}

	/**
	 * Process all scripts and create a single Code element.
	 *
	 * @since 2.4
	 *
	 * @param array  $scripts              Array of script objects { content, type }.
	 * @param array  $external_scripts     Array of external script URLs.
	 * @param string $global_css           Global CSS to include.
	 * @param array  $external_stylesheets Array of external stylesheet URLs.
	 * @return array|null Bricks Code element or null if no content.
	 */
	public static function process_scripts( $scripts, $external_scripts, $global_css = '', $external_stylesheets = [] ) {
		$js_code_parts   = [];
		$html_code_parts = [];

		$stylesheet_html = self::build_external_stylesheets_html( $external_stylesheets );

		if ( $stylesheet_html ) {
			$html_code_parts[] = $stylesheet_html;
		}

		$script_html = self::build_external_scripts_html( $external_scripts );

		if ( $script_html ) {
			$html_code_parts[] = $script_html;
		}

		// Concatenate inline scripts
		if ( $scripts && ! empty( $scripts ) ) {
			$script_count = count( $scripts );

			foreach ( $scripts as $index => $script ) {
				if ( ! empty( $script['content'] ) ) {
					if ( $script_count > 1 ) {
						$js_code_parts[] = '// --- Script ' . ( $index + 1 ) . ' ---';
					}

					$js_code_parts[] = $script['content'];
					$js_code_parts[] = '';
				}
			}
		}

		$js_code   = trim( implode( "\n", $js_code_parts ) );
		$html_code = trim( implode( "\n", $html_code_parts ) );

		// Only create element if there's JS, CSS, or external resource HTML.
		if ( ! $js_code && ! $global_css && ! $html_code ) {
			return null;
		}

		return self::create_code_element(
			[
				'jsCode'   => $js_code,
				'cssCode'  => $global_css,
				'htmlCode' => $html_code,
			]
		);
	}

	/**
	 * Format JavaScript code (basic formatting).
	 *
	 * @since 2.4
	 *
	 * @param string $js_code JavaScript code.
	 * @return string Formatted code.
	 */
	public static function format_js_code( $js_code ) {
		if ( ! $js_code ) {
			return '';
		}

		$code = trim( $js_code );
		$code = str_replace( "\r\n", "\n", $code );
		$code = preg_replace( "/\n{3,}/", "\n\n", $code );

		return $code;
	}

	/**
	 * Wrap code in DOMContentLoaded if needed.
	 *
	 * @since 2.4
	 *
	 * @param string $js_code JavaScript code.
	 * @param bool   $wrap    Whether to wrap.
	 * @return string Wrapped code.
	 */
	public static function wrap_in_dom_ready( $js_code, $wrap = false ) {
		if ( ! $wrap || ! $js_code ) {
			return $js_code;
		}

		$lines    = explode( "\n", $js_code );
		$indented = array_map(
			function ( $line ) {
				return '  ' . $line;
			},
			$lines
		);

		return "document.addEventListener('DOMContentLoaded', function() {\n" .
			implode( "\n", $indented ) .
			"\n});";
	}

	/**
	 * Sanitize JavaScript code (basic security).
	 *
	 * @since 2.4
	 *
	 * @param string $js_code JavaScript code.
	 * @return string Sanitized code.
	 */
	public static function sanitize_js( $js_code ) {
		if ( ! $js_code ) {
			return '';
		}

		return $js_code;
	}
}
