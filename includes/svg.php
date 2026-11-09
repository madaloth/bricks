<?php
namespace Bricks;

use Bricks\Integrations\Svg_Sanitizer\Allowed_Tags as Allowed_Tags;
use Bricks\Integrations\Svg_Sanitizer\Allowed_Attributes as Allowed_Attributes;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Svg {
	/**
	 * Enable SVGs uploads
	 *
	 * https://enshrined.co.uk/2018/04/29/securing-svg-uploads-in-wordpress/
	 */
	public function __construct() {
		add_filter( 'upload_mimes', [ $this, 'svg_enable_upload' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'disable_real_mime_check' ], 10, 4 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'svg_one_pixel_fix' ], 10, 4 );

		// Tries to sanitize a SVG file
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'maybe_sanitize_svg' ] );
		add_filter( 'wp_handle_sideload_prefilter', [ $this, 'maybe_sanitize_svg' ] );
		add_filter( 'bricks_form_submit_prefilter', [ $this, 'maybe_sanitize_svg' ] );
	}

	/**
	 * Enable SVG uploads
	 *
	 * @since 1.0
	 */
	public function svg_enable_upload( $mimes ) {
		if ( ! Capabilities::current_user_can_upload_svg() ) {
			return $mimes;
		}

		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';

		return $mimes;
	}

	/**
	 * Disable real MIME check (introduced in WordPress 4.7.1)
	 *
	 * https://wordpress.stackexchange.com/a/252296/44794
	 *
	 * @since 1.0
	 */
	public function disable_real_mime_check( $data, $file, $filename, $mimes ) {
		global $wp_version;

		$filetype = wp_check_filetype( $filename, $mimes );

		return [
			'ext'             => $filetype['ext'],
			'type'            => $filetype['type'],
			'proper_filename' => $data['proper_filename']
		];
	}

	/**
	 * Remove img width and height attributes for SVG files, which are set to 1px
	 *
	 * @since 1.0
	 */
	public function svg_one_pixel_fix( $image, $attachment_id, $size, $icon ) {
		if ( get_post_mime_type( $attachment_id ) == 'image/svg+xml' ) {
			$image['1'] = false;
			$image['2'] = false;
		}

		return $image;
	}

	/**
	 * Sanitize an SVG before WordPress moves an uploaded or sideloaded file.
	 *
	 * @since 2.4
	 *
	 * @param array $file Upload file data.
	 * @return array
	 */
	public function maybe_sanitize_svg( $file ) {
		$extension = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );

		// Upload MIME metadata is client supplied; the filename can still admit an SVG.
		if ( ( $file['type'] ?? '' ) !== 'image/svg+xml' && ! in_array( $extension, [ 'svg', 'svgz' ], true ) ) {
			return $file;
		}

		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		// NOTE: Undocumented. Bypass the svg sanitization process
		$bypass_sanitization = apply_filters( 'bricks/svg/bypass_sanitization', false, $file );

		if ( ! $bypass_sanitization ) {
			if ( ! $this->sanitize( $file['tmp_name'] ?? '' ) ) {
				$file['error'] = __( 'File not uploaded due to a sanitization error. Please verify the SVG file or get in touch.', 'bricks' );
			}
		}

		return $file;
	}

	/**
	 * Sanitize upload bytes and require a complete write before admitting the file.
	 *
	 * @param string $file Local temporary upload path.
	 * @return bool
	 */
	protected function sanitize( $file ) {
		try {
			if ( ! is_string( $file ) || $file === '' || strpos( $file, '://' ) !== false || ! is_file( $file ) || ! is_readable( $file ) || ! is_writable( $file ) ) {
				return false;
			}

			clearstatcache( true, $file );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished upload must fail closed without exposing its temporary path.
			$file_size = @filesize( $file );
			if ( $file_size === false || $file_size < 1 || $file_size > self::max_svg_bytes() ) {
				return false;
			}

			// PHP 8.1/8.2 can allocate the entire read length, even for a tiny file.
			// Read one extra byte to detect growth without reserving the site's upload limit.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload reads fail closed, without leaking server paths in warnings.
			$file_content = @file_get_contents( $file, false, null, 0, $file_size + 1 );
			if ( ! is_string( $file_content ) || strlen( $file_content ) !== $file_size ) {
				return false;
			}

			$file_clean = self::sanitize_contents( $file_content );

			if ( $file_clean === false ) {
				return false;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- WordPress must move exactly the sanitized temporary file; incomplete writes are rejected.
			return @file_put_contents( $file, $file_clean ) === strlen( $file_clean );
		} catch ( \Throwable $error ) {
			// No original upload may proceed when a sanitizer or filesystem operation fails.
			return false;
		}
	}

	/**
	 * Sanitize SVG or SVGZ bytes, preserving compression for upload callers.
	 *
	 * This does not honor the upload bypass filter: icon and transfer imports have
	 * always required sanitization, independently of upload-hook customizations.
	 *
	 * @since 2.4.4
	 * @param string $contents File contents.
	 * @return string|false
	 */
	public static function sanitize_contents( $contents ) {
		try {
			$max_bytes = self::max_svg_bytes();
			if ( ! is_string( $contents ) || $contents === '' || strlen( $contents ) > $max_bytes ) {
				return false;
			}

			$is_gzipped = strncmp( $contents, "\x1f\x8b\x08", 3 ) === 0;
			if ( $is_gzipped ) {
				// Limit expansion before allocating a potentially enormous SVGZ payload.
				$contents = @gzdecode( $contents, $max_bytes + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid or oversized gzip returns the normal sanitization error.
			}

			$clean = self::sanitize_markup( $contents );
			if ( $clean === false ) {
				return false;
			}

			return $is_gzipped ? gzencode( $clean ) : $clean;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Sanitize markup with the isolated library and Bricks' tag/attribute filters.
	 *
	 * @since 2.4.4
	 * @param string $markup Untrusted SVG markup.
	 * @return string|false Clean markup, or false when processing fails.
	 */
	public static function sanitize_markup( $markup ) {
		try {
			if ( ! is_string( $markup ) || strlen( $markup ) > self::max_svg_bytes() || trim( $markup ) === '' ) {
				return false;
			}

			self::load_libraries();
			$sanitizer = new \Bricks\Vendor\enshrined\svgSanitize\Sanitizer();
			$sanitizer->minify( true );
			$sanitizer->setAllowedTags( new Allowed_Tags() );
			$sanitizer->setAllowedAttrs( new Allowed_Attributes() );
			$clean = $sanitizer->sanitize( $markup );

			return is_string( $clean ) && trim( $clean ) !== '' ? $clean : false;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Verify direct WordPress byte uploads before registering an SVG attachment.
	 *
	 * wp_upload_bits() does not check fwrite()'s return value. Compare persisted
	 * bytes with the sanitized output so incomplete writes cannot report success.
	 *
	 * @since 2.4.4
	 * @param string $file Uploaded local file path.
	 * @param string $contents Expected sanitized contents.
	 * @return bool
	 */
	public static function file_matches_contents( $file, $contents ) {
		try {
			if ( ! is_string( $file ) || strpos( $file, '://' ) !== false || ! is_file( $file ) || ! is_string( $contents ) || $contents === '' ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local write verification fails closed without leaking server paths.
			return @file_get_contents( $file, false, null, 0, strlen( $contents ) + 1 ) === $contents;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Bound SVG processing to the site's existing upload limit.
	 *
	 * @since 2.4.4
	 * @return int
	 */
	private static function max_svg_bytes() {
		return max( 1, (int) wp_max_upload_size() );
	}

	/**
	 * Report availability of Bricks' own sanitizer, independently of plugin copies.
	 *
	 * @since 2.4.4
	 * @return bool
	 */
	public static function is_available() {
		try {
			self::load_libraries();
			return class_exists( '\DOMDocument' ) && class_exists( '\Bricks\Vendor\enshrined\svgSanitize\Sanitizer' );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Load the bundled sanitizer; callers must reject input if loading fails.
	 *
	 * @return void
	 */
	public static function load_libraries() {
		$autoload = BRICKS_PATH . 'includes/integrations/svg-sanitizer/library/vendor/autoload.php';
		if ( is_readable( $autoload ) ) {
			require_once $autoload;
		}
	}
}
