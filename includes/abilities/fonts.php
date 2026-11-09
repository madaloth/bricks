<?php
/**
 * Custom Font abilities
 *
 * CRUD for the Bricks "Custom Fonts" custom post type (`bricks_fonts`).
 * Each post is a font *family*; font-face definitions live in postmeta
 * under `BRICKS_DB_CUSTOM_FONT_FACES` and reference uploaded WP media
 * attachments for the actual font files.
 *
 * Upload security:
 *   - Extension allow-list: woff2, woff, ttf, otf (and eot for legacy)
 *   - MIME sniff via `wp_check_filetype_and_ext()` (not just the client-declared type)
 *   - Size bound before and after base64 decode (8 MB default - configurable via filter)
 *   - `access_font_manager` required for all reads and writes
 *   - `upload_files` also required for raw file uploads
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fonts {
	/**
	 * Maximum accepted font-file size in bytes. Filterable.
	 */
	const MAX_FILE_BYTES = 8 * 1024 * 1024;

	/**
	 * Allowed font extensions mapped to canonical CSS @font-face format keys.
	 */
	const ALLOWED_EXTENSIONS = [
		'woff2' => 'woff2',
		'woff'  => 'woff',
		'ttf'   => 'ttf',
		'otf'   => 'otf',
		'eot'   => 'eot',
	];

	/**
	 * Allowed MIME types (detected server-side, not trusted from client).
	 */
	const ALLOWED_MIMES = [
		'woff2' => [ 'font/woff2', 'application/font-woff2', 'application/octet-stream' ],
		'woff'  => [ 'font/woff', 'application/font-woff', 'application/octet-stream' ],
		'ttf'   => [ 'font/ttf', 'application/x-font-ttf', 'application/octet-stream' ],
		'otf'   => [ 'font/otf', 'application/x-font-otf', 'application/octet-stream' ],
		'eot'   => [ 'application/vnd.ms-fontobject', 'application/octet-stream' ],
	];

	// ==================================================================
	// Schemas
	// ==================================================================

	/**
	 * Input schema for list-custom-fonts.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_custom_fonts_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'search' => [
						'type'        => 'string',
						'description' => __( 'Case-insensitive substring filter on font-family name.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	public static function list_custom_fonts_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'   => [ 'type' => 'array' ],
				'total'   => [ 'type' => 'integer' ],
				'page'    => [ 'type' => 'integer' ],
				'perPage' => [ 'type' => 'integer' ],
			],
		];
	}

	public static function get_custom_font_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'fontId' ],
			'properties' => [
				'fontId' => [
					'type'        => 'integer',
					'description' => __( 'Post ID of the custom font family.', 'bricks' ),
				],
			],
		];
	}

	public static function get_custom_font_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'font' => [ 'type' => 'object' ],
			],
		];
	}

	public static function create_custom_font_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'family' ],
			'properties' => [
				'family'    => [
					'type'        => 'string',
					'description' => __( 'Font-family display name. Becomes the `font-family` CSS value.', 'bricks' ),
				],
				'fontFaces' => [
					'type'        => 'object',
					'description' => __( 'Optional initial font-face map. Keys are `{weight}{style}` strings (e.g. `400`, `400italic`, `700`). Each value may be one subset object or an array of subset objects: `{ woff2: attachmentId, woff: attachmentId, unicode-range?: "U+0000-00FF", font-weight-range?: "300 900" }`. Weight ranges require two ordered numbers from 1 to 1000. Attachment IDs must reference existing WP attachments, preferably created through `bricks/upload-custom-font-file`.', 'bricks' ),
				],
			],
		];
	}

	public static function create_custom_font_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'font' => [ 'type' => 'object' ],
			],
		];
	}

	public static function update_custom_font_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'fontId' ],
			'properties' => [
				'fontId'    => [ 'type' => 'integer' ],
				'family'    => [
					'type'        => 'string',
					'description' => __( 'New font-family name.', 'bricks' ),
				],
				'fontFaces' => [
					'type'        => 'object',
					'description' => __( 'Full replacement of the font-face map. Keys are `{weight}{style}` strings (e.g. `400`, `400italic`, `700`). Each value may be one subset object or an array of subset objects: `{ woff2: attachmentId, woff: attachmentId, unicode-range?: "U+0000-00FF", font-weight-range?: "300 900" }`. Weight ranges require two ordered numbers from 1 to 1000. Attachment IDs must reference existing WP attachments, preferably created through `bricks/upload-custom-font-file`.', 'bricks' ),
				],
			],
		];
	}

	public static function update_custom_font_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'font' => [ 'type' => 'object' ],
			],
		];
	}

	public static function delete_custom_font_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'fontId' ],
			'properties' => [
				'fontId' => [ 'type' => 'integer' ],
			],
		];
	}

	public static function delete_custom_font_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deletedId'          => [ 'type' => 'integer' ],
				'deletedAttachments' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
			],
		];
	}

	public static function upload_font_file_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'filename', 'content' ],
			'properties' => [
				'filename' => [
					'type'        => 'string',
					'description' => __( 'Original filename, including the extension. The extension must be one of `woff2`, `woff`, `ttf`, `otf`, or `eot`, and is used only to determine the canonical format; the MIME type is re-sniffed server-side.', 'bricks' ),
				],
				'content'  => [
					'type'        => 'string',
					'description' => __( 'Base64-encoded file contents. Maximum 8 MB decoded (configurable via `bricks/abilities/fonts/max_bytes` filter).', 'bricks' ),
				],
			],
		];
	}

	public static function upload_font_file_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'attachmentId' => [
					'type'        => 'integer',
					'description' => __( 'WP attachment ID of the stored font file. Reference this in `fontFaces` when calling `bricks/update-custom-font`.', 'bricks' ),
				],
				'url'          => [ 'type' => 'string' ],
				'format'       => [
					'type'        => 'string',
					'description' => __( 'Canonical format key (woff2 | woff | ttf | otf | eot).', 'bricks' ),
				],
				'bytes'        => [ 'type' => 'integer' ],
			],
		];
	}

	// ==================================================================
	// Permissions
	// ==================================================================

	/**
	 * Read permission.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input = [] ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}
		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			return Error::forbidden_builder_permission( 'access_font_manager' );
		}
		return true;
	}

	public static function write_permission( $input = [] ) {
		return self::read_permission( $input );
	}

	public static function upload_permission( $input = [] ) {
		$permission = self::write_permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return Error::forbidden_builder_permission( 'upload_files' );
		}
		return true;
	}

	// ==================================================================
	// Execute
	// ==================================================================

	/**
	 * Callback: list custom fonts.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_custom_fonts( $input ) {
		$search = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';

		$font_ids = get_posts(
			[
				'post_type'      => BRICKS_DB_CUSTOM_FONTS,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$rows = [];
		foreach ( $font_ids as $font_id ) {
			$family = html_entity_decode( (string) get_the_title( $font_id ), ENT_QUOTES, 'UTF-8' );
			if ( $search !== '' && stripos( $family, $search ) === false ) {
				continue;
			}

			$faces      = get_post_meta( $font_id, BRICKS_DB_CUSTOM_FONT_FACES, true );
			$face_count = is_array( $faces ) ? count( $faces ) : 0;

			$rows[] = [
				'id'        => (int) $font_id,
				'family'    => $family,
				'faceCount' => $face_count,
			];
		}

		return Reference::paginate( $rows, $input );
	}

	public static function get_custom_font( $input ) {
		$id = (int) ( $input['fontId'] ?? 0 );
		if ( $id <= 0 ) {
			return Error::invalid_param( 'fontId', 'positive integer', $id );
		}

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== BRICKS_DB_CUSTOM_FONTS ) {
			return Error::not_found( 'custom_font', $id );
		}

		$faces = get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		return [
			'font' => [
				'id'        => $id,
				'family'    => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'fontFaces' => is_array( $faces ) ? $faces : [],
			],
		];
	}

	public static function create_custom_font( $input ) {
		$family = trim( (string) ( $input['family'] ?? '' ) );
		if ( $family === '' ) {
			return Error::invalid_param( 'family', 'non-empty string', $family );
		}

		$validated = [];
		if ( array_key_exists( 'fontFaces', $input ) ) {
			if ( ! is_array( $input['fontFaces'] ) ) {
				return Error::invalid_param( 'fontFaces', 'object keyed by weight+style', $input['fontFaces'] );
			}

			$validated = self::validate_font_faces( $input['fontFaces'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}

		$existing = get_posts(
			[
				'post_type'      => BRICKS_DB_CUSTOM_FONTS,
				'posts_per_page' => 1,
				'title'          => $family,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		if ( ! empty( $existing ) ) {
			return Error::conflict_duplicate_name( 'custom font', $family, [ 'existingId' => (int) $existing[0] ] );
		}

		$id = wp_insert_post(
			[
				'post_title'  => $family,
				'post_status' => 'publish',
				'post_type'   => BRICKS_DB_CUSTOM_FONTS,
				'meta_input'  => $validated ? [ BRICKS_DB_CUSTOM_FONT_FACES => $validated ] : [],
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		\Bricks\Custom_Fonts::refresh_font_face_rules();

		return [
			'font' => [
				'id'        => (int) $id,
				'family'    => $family,
				'fontFaces' => $validated,
			],
		];
	}

	public static function update_custom_font( $input ) {
		$id = (int) ( $input['fontId'] ?? 0 );
		if ( $id <= 0 ) {
			return Error::invalid_param( 'fontId', 'positive integer', $id );
		}

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== BRICKS_DB_CUSTOM_FONTS ) {
			return Error::not_found( 'custom_font', $id );
		}

		// Validate every supplied field before changing the family or its faces.
		if ( array_key_exists( 'fontFaces', $input ) ) {
			if ( ! is_array( $input['fontFaces'] ) ) {
				return Error::invalid_param( 'fontFaces', 'object keyed by weight+style', $input['fontFaces'] );
			}

			$validated = self::validate_font_faces( $input['fontFaces'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}

		if ( array_key_exists( 'family', $input ) ) {
			$new_family = trim( (string) $input['family'] );
			if ( $new_family === '' ) {
				return Error::invalid_param( 'family', 'non-empty string', $new_family );
			}

			$existing = get_posts(
				[
					'post_type'      => BRICKS_DB_CUSTOM_FONTS,
					'posts_per_page' => 1,
					'title'          => $new_family,
					'fields'         => 'ids',
					'post__not_in'   => [ $id ],
					'no_found_rows'  => true,
				]
			);
			if ( ! empty( $existing ) ) {
				return Error::conflict_duplicate_name( 'custom font', $new_family, [ 'existingId' => (int) $existing[0] ] );
			}

			$updated = wp_update_post(
				[
					'ID'         => $id,
					'post_title' => $new_family,
				],
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( array_key_exists( 'fontFaces', $input ) ) {
			update_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, $validated );
		}

		\Bricks\Custom_Fonts::refresh_font_face_rules();

		$faces = get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		return [
			'font' => [
				'id'        => $id,
				'family'    => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'fontFaces' => is_array( $faces ) ? $faces : [],
			],
		];
	}

	public static function delete_custom_font( $input ) {
		$id = (int) ( $input['fontId'] ?? 0 );
		if ( $id <= 0 ) {
			return Error::invalid_param( 'fontId', 'positive integer', $id );
		}

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== BRICKS_DB_CUSTOM_FONTS ) {
			return Error::not_found( 'custom_font', $id );
		}

		// Face attachments may be shared by other families; media deletion is a separate action.
		$result = wp_delete_post( $id, true );
		if ( ! $result ) {
			return Error::internal_error( 'delete_custom_font', 'wp_delete_post returned false', [ 'fontId' => $id ] );
		}

		\Bricks\Custom_Fonts::refresh_font_face_rules();

		return [
			'deletedId'          => $id,
			'deletedAttachments' => [],
		];
	}

	/**
	 * Execute: upload-custom-font-file
	 *
	 * Accepts a base64-encoded font file, runs MIME + size + extension
	 * checks, drops it into the uploads dir via `wp_upload_bits()`, and
	 * creates a WP attachment the caller can reference when updating
	 * `fontFaces`.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function upload_font_file( $input ) {
		$filename = (string) ( $input['filename'] ?? '' );
		$content  = (string) ( $input['content'] ?? '' );

		if ( $filename === '' ) {
			return Error::invalid_param( 'filename', 'non-empty string ending in an allowed extension', $filename );
		}

		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! isset( self::ALLOWED_EXTENSIONS[ $ext ] ) ) {
			return Error::invalid_param(
				'filename',
				'filename with an allowed font extension: ' . implode( ', ', array_keys( self::ALLOWED_EXTENSIONS ) ),
				$filename
			);
		}

		$max_bytes         = self::max_font_file_bytes();
		$normalized_base64 = preg_replace( '/\s+/', '', $content );
		$normalized_base64 = is_string( $normalized_base64 ) ? $normalized_base64 : '';
		$max_encoded_bytes = self::max_base64_encoded_bytes( $max_bytes );

		if ( strlen( $normalized_base64 ) > $max_encoded_bytes ) {
			return Error::invalid_param(
				'content',
				sprintf( 'base64 payload for a font file <= %d decoded bytes', $max_bytes ),
				[ 'encodedLength' => strlen( $normalized_base64 ) ]
			);
		}

		$decoded = base64_decode( $normalized_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes caller-provided font upload content.
		if ( $decoded === false || $decoded === '' ) {
			return Error::invalid_param( 'content', 'valid base64-encoded file contents', '(length=' . strlen( $content ) . ')' );
		}

		$decoded_bytes = strlen( $decoded );
		if ( $decoded_bytes > $max_bytes ) {
			return Error::invalid_param(
				'content',
				sprintf( 'font file <= %d bytes (got %d)', $max_bytes, $decoded_bytes ),
				null
			);
		}

		if ( ! self::font_payload_matches_extension( $decoded, $ext ) ) {
			return Error::invalid_param(
				'content',
				sprintf( 'valid %s font bytes matching the filename extension', $ext ),
				[ 'declaredExt' => $ext ]
			);
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$mime_types = [];
		foreach ( self::ALLOWED_EXTENSIONS as $allowed_ext => $_fmt ) {
			$mime_types[ $allowed_ext ] = self::ALLOWED_MIMES[ $allowed_ext ][0];
		}

		$upload = wp_upload_bits( basename( $filename ), null, $decoded );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return Error::internal_error( 'upload_font_file', $upload['error'] ?? 'wp_upload_bits failed' );
		}

		$check         = wp_check_filetype_and_ext( $upload['file'], basename( $upload['file'] ), $mime_types );
		$detected_type = ! empty( $check['type'] ) ? (string) $check['type'] : '';
		$detected_ext  = ! empty( $check['ext'] ) ? (string) $check['ext'] : '';

		if ( $detected_ext === '' || ! isset( self::ALLOWED_EXTENSIONS[ $detected_ext ] ) ) {
			self::delete_uploaded_file( $upload['file'] );
			return Error::invalid_param(
				'content',
				'file payload matching an allowed font type after MIME sniff',
				[
					'declaredExt'  => $ext,
					'detectedType' => $detected_type
				]
			);
		}

		if ( ! in_array( $detected_type, self::ALLOWED_MIMES[ $detected_ext ], true ) ) {
			self::delete_uploaded_file( $upload['file'] );
			return Error::invalid_param(
				'content',
				sprintf( 'file whose MIME type matches the extension (expected one of: %s)', implode( ', ', self::ALLOWED_MIMES[ $detected_ext ] ) ),
				[ 'detectedType' => $detected_type ]
			);
		}

		$attachment = [
			'post_mime_type' => $detected_type,
			'post_title'     => sanitize_text_field( basename( $upload['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'private',
		];

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) ) {
			self::delete_uploaded_file( $upload['file'] );
			return $attach_id;
		}

		return [
			'attachmentId' => (int) $attach_id,
			'url'          => (string) wp_get_attachment_url( $attach_id ),
			'format'       => $detected_ext,
			'bytes'        => $decoded_bytes,
		];
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * Return the maximum accepted decoded font-file size.
	 *
	 * @return int
	 */
	private static function max_font_file_bytes(): int {
		return max( 1, (int) apply_filters( 'bricks/abilities/fonts/max_bytes', self::MAX_FILE_BYTES ) );
	}

	/**
	 * Calculate a conservative encoded length ceiling for a decoded byte limit.
	 *
	 * @param int $decoded_bytes Decoded byte limit.
	 * @return int
	 */
	private static function max_base64_encoded_bytes( int $decoded_bytes ): int {
		return ( (int) ceil( $decoded_bytes / 3 ) * 4 ) + 4;
	}

	/**
	 * Verify the decoded font payload starts with the expected container magic.
	 *
	 * @param string $bytes Decoded file bytes.
	 * @param string $ext   Declared lowercase extension.
	 * @return bool
	 */
	private static function font_payload_matches_extension( string $bytes, string $ext ): bool {
		if ( strlen( $bytes ) < 4 ) {
			return false;
		}

		switch ( $ext ) {
			case 'woff2':
				return strncmp( $bytes, 'wOF2', 4 ) === 0;

			case 'woff':
				return strncmp( $bytes, 'wOFF', 4 ) === 0;

			case 'otf':
				return strncmp( $bytes, 'OTTO', 4 ) === 0;

			case 'ttf':
				return strncmp( $bytes, "\x00\x01\x00\x00", 4 ) === 0
					|| strncmp( $bytes, 'true', 4 ) === 0
					|| strncmp( $bytes, 'ttcf', 4 ) === 0;

			case 'eot':
				return strlen( $bytes ) >= 36 && substr( $bytes, 34, 2 ) === 'LP';
		}

		return false;
	}

	/**
	 * Delete a failed temporary upload using WordPress' filesystem hook.
	 *
	 * @param string $path Uploaded file path.
	 * @return void
	 */
	private static function delete_uploaded_file( string $path ): void {
		if ( $path !== '' && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Validate a font-face map against the shape Bricks expects on output.
	 *
	 * Accepts `{weight+style => subsets[]}` where each subset is
	 * `{ format => attachmentId, 'unicode-range'? => string, 'font-weight-range'? => string }`.
	 *
	 * @return array|\WP_Error
	 */
	private static function validate_font_faces( array $faces ) {
		$clean = [];

		foreach ( $faces as $key => $value ) {
			$key = (string) $key;
			if ( $key === '' ) {
				return Error::invalid_param( 'fontFaces', 'keys must be non-empty weight+style strings (e.g. "400", "700italic")', $key );
			}

			if ( ! is_array( $value ) ) {
				return Error::invalid_param( "fontFaces[$key]", 'array of subsets', $value );
			}

			$subsets       = isset( $value[0] ) && is_array( $value[0] ) ? $value : [ $value ];
			$clean_subsets = [];

			foreach ( $subsets as $si => $subset ) {
				if ( ! is_array( $subset ) ) {
					return Error::invalid_param( "fontFaces[$key][$si]", 'object mapping format to attachmentId', $subset );
				}

				$clean_subset = [];
				foreach ( $subset as $format => $attach_id ) {
					$format = strtolower( (string) $format );
					if ( $format === 'unicode-range' ) {
						$clean_subset[ $format ] = (string) $attach_id;
						continue;
					}
					if ( $format === 'font-weight-range' ) {
						$range = is_string( $attach_id ) ? \Bricks\Custom_Fonts::sanitize_font_weight_range( $attach_id ) : '';
						if ( $range === '' ) {
							return Error::invalid_param( "fontFaces[$key][$si].$format", 'two ordered numbers from 1 to 1000 (e.g. "300 900")', $attach_id );
						}
						$clean_subset[ $format ] = $range;
						continue;
					}
					if ( ! isset( self::ALLOWED_EXTENSIONS[ $format ] ) ) {
						return Error::invalid_param(
							"fontFaces[$key][$si].$format",
							'format key in ' . implode( '/', array_keys( self::ALLOWED_EXTENSIONS ) ) . ' or "unicode-range"/"font-weight-range"',
							$format
						);
					}
					$attach_id = (int) $attach_id;
					if ( $attach_id <= 0 || get_post_type( $attach_id ) !== 'attachment' ) {
						return Error::invalid_param(
							"fontFaces[$key][$si].$format",
							'positive attachment ID of an uploaded font file (see `bricks/upload-custom-font-file`)',
							$attach_id
						);
					}

					$attachment_check = self::validate_font_attachment_for_format( $attach_id, $format, "fontFaces[$key][$si].$format" );

					if ( is_wp_error( $attachment_check ) ) {
						return $attachment_check;
					}

					$clean_subset[ $format ] = $attach_id;
				}

				$font_source_count = count( array_diff( array_keys( $clean_subset ), [ 'unicode-range', 'font-weight-range' ] ) );

				if ( $font_source_count === 0 ) {
					return Error::invalid_param(
						"fontFaces[$key][$si]",
						'at least one font source format plus optional "unicode-range"/"font-weight-range"',
						$subset
					);
				}

				$clean_subsets[] = $clean_subset;
			}

			if ( empty( $clean_subsets ) ) {
				continue;
			}

			$clean[ $key ] = count( $clean_subsets ) === 1 ? $clean_subsets[0] : $clean_subsets;
		}

		return $clean;
	}

	/**
	 * Verify a referenced attachment is an uploaded font file matching the format key.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Font format key.
	 * @param string $param         Error parameter path.
	 * @return true|\WP_Error
	 */
	private static function validate_font_attachment_for_format( int $attachment_id, string $format, string $param ) {
		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || $file === '' || ! file_exists( $file ) ) {
			return Error::invalid_param(
				$param,
				'attachment ID with an existing uploaded font file',
				$attachment_id
			);
		}

		$mime_types = [];
		foreach ( self::ALLOWED_EXTENSIONS as $allowed_ext => $_fmt ) {
			$mime_types[ $allowed_ext ] = self::ALLOWED_MIMES[ $allowed_ext ][0];
		}

		$file_type = wp_check_filetype_and_ext( $file, wp_basename( $file ), $mime_types );
		$ext       = isset( $file_type['ext'] ) ? strtolower( (string) $file_type['ext'] ) : '';

		if ( $ext === '' || ! isset( self::ALLOWED_EXTENSIONS[ $ext ] ) || self::ALLOWED_EXTENSIONS[ $ext ] !== $format ) {
			return Error::invalid_param(
				$param,
				sprintf( 'attachment ID for a %s font file uploaded through `bricks/upload-custom-font-file`', $format ),
				[
					'attachmentId' => $attachment_id,
					'detectedExt'  => $ext,
				]
			);
		}

		return true;
	}
}
