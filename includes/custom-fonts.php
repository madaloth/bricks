<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Custom Fonts Upload
 *
 * Font naming convention: custom_font_{font_id}
 *
 * @since 1.0
 */
class Custom_Fonts {
	public static $fonts           = false;
	public static $font_face_rules = '';

	public function __construct() {
		add_filter( 'init', [ $this, 'register_post_type' ] );
		add_action( 'post_updated', [ __CLASS__, 'refresh_font_family' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

		add_action( 'admin_notices', [ $this, 'admin_notice_use_font_manager' ] );

		add_filter( 'post_row_actions', [ $this, 'post_row_actions' ], 10, 2 );

		add_filter( 'manage_' . BRICKS_DB_CUSTOM_FONTS . '_posts_columns', [ $this, 'manage_columns' ] );
		add_action( 'manage_' . BRICKS_DB_CUSTOM_FONTS . '_posts_custom_column', [ $this, 'render_columns' ], 10, 2 );

		add_action( 'add_meta_boxes_' . BRICKS_DB_CUSTOM_FONTS, [ $this, 'add_meta_boxes' ] );
		add_filter( 'upload_mimes', [ $this, 'upload_mimes' ] );

		add_action( 'wp_ajax_bricks_save_font_faces', [ $this, 'save_font_faces' ], 10, 2 );
		add_action( 'wp_ajax_bricks_get_custom_font_data', [ $this, 'get_custom_font_data' ] );
		add_action( 'wp_ajax_bricks_get_font_attachment_metadata', [ $this, 'get_font_attachment_metadata' ] );
		add_action( 'wp_ajax_bricks_create_draft_font', [ $this, 'create_draft_font' ] );
		add_action( 'wp_ajax_bricks_delete_draft_font', [ $this, 'delete_draft_font' ] );
		add_action( 'wp_ajax_bricks_download_google_font', [ $this, 'download_google_font' ] );
		add_action( 'wp_ajax_bricks_process_font_files', [ $this, 'process_font_files' ] );
		add_action( 'wp_ajax_bricks_move_font_to_trash', [ $this, 'move_font_to_trash' ] );
		add_action( 'wp_ajax_bricks_get_trashed_fonts', [ $this, 'get_trashed_fonts' ] );
		add_action( 'wp_ajax_bricks_restore_font', [ $this, 'restore_font' ] );
		add_action( 'wp_ajax_bricks_delete_font_permanently', [ $this, 'delete_font_permanently' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'add_inline_style_font_face_rules' ], 11 );
		add_action( 'wp_enqueue_scripts', [ $this, 'add_inline_style_font_face_rules' ], 11 );

		// Add preload tags to wp_head (@since 2.0)
		if ( Database::get_setting( 'customFontsPreload', false ) ) {
			add_action( 'wp_head', [ $this, 'preload_custom_fonts' ], 1 );
		}

		// Add meta capability mapping
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 4 );
	}

	/**
	 * Map meta capabilities for custom fonts
	 *
	 * @param array  $caps    The user's actual capabilities.
	 * @param string $cap     Capability name.
	 * @param int    $user_id The user ID.
	 * @param array  $args    Adds the context to the cap. Typically the object ID.
	 * @return array The user's actual capabilities.
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		// List of all custom font capabilities
		$custom_font_caps = [
			'read_custom_font',
			'edit_custom_font',
			'edit_custom_fonts',
			'edit_others_custom_fonts',
			'publish_custom_fonts',
			'read_private_custom_fonts',
			'delete_custom_font',
			'delete_custom_fonts',
			'delete_others_custom_fonts',
			'delete_published_custom_fonts',
			'delete_private_custom_fonts',
			'edit_private_custom_fonts',
			'edit_published_custom_fonts',
			'create_custom_fonts',
		];

		// Only handle our custom font capabilities
		if ( ! in_array( $cap, $custom_font_caps ) ) {
			return $caps;
		}

		// Check if user has font manager access permission
		if ( Builder_Permissions::user_has_permission( 'access_font_manager', $user_id ) ) {
			$caps = [ 'exist' ]; // Grant access
			return $caps;
		}

		// Get all capabilities that have fonts manager access permission
		$font_manager_capabilities = Builder_Permissions::get_capabilities_by_permission( 'access_font_manager' );

		// If no capabilities have fonts manager access, restrict to full access only
		if ( empty( $font_manager_capabilities ) ) {
			$caps = [ Capabilities::FULL_ACCESS ];
		} else {
			// Check if user has any of the required capabilities
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				$caps = [ 'do_not_allow' ];
				return $caps;
			}

			$user_has_access = false;
			foreach ( $font_manager_capabilities as $required_cap ) {
				if ( $user->has_cap( $required_cap ) ) {
					$user_has_access = true;
					break;
				}
			}

			$caps = $user_has_access ? [ 'exist' ] : [ 'do_not_allow' ];
		}

		return $caps;
	}

	/**
	 * Admin notice to inform users about the new Font Manager
	 *
	 * @since 2.0
	 */
	public function admin_notice_use_font_manager() {
		$current_screen = get_current_screen();

		if ( is_object( $current_screen ) && $current_screen->post_type === BRICKS_DB_CUSTOM_FONTS ) {
			echo '<div class="notice notice-info is-dismissible bricks-notice">';
			/* translators: %s: Font Manager article link. */
			echo '<p>' . sprintf( esc_html__( 'You can now manage your custom fonts using the new %s in the builder.', 'bricks' ), Helpers::article_link( 'builder/styling/font-manager/', esc_html__( 'Font manager', 'bricks' ) ) ) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Generate custom font-face rules when viewing/editing "Custom fonts" in admin area
	 *
	 * @since 1.7.2
	 */
	public function generate_custom_font_face_rules() {
		self::refresh_font_face_rules();
	}

	/**
	 * Refresh persisted font CSS and the current request's font catalog after a mutation.
	 *
	 * @since 2.4.3
	 *
	 * @return void
	 */
	public static function refresh_font_face_rules() {
		self::$fonts           = false;
		self::$font_face_rules = '';
		self::get_custom_fonts();

		if ( self::$font_face_rules !== '' ) {
			update_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES, self::$font_face_rules );
		} else {
			delete_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES );
		}
	}

	/**
	 * Keep generated styles in sync when a custom font family is renamed.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post_after Updated font post.
	 * @param \WP_Post $post_before Previous font post.
	 * @return void
	 * @since 2.4.3
	 */
	public static function refresh_font_family( $post_id, $post_after, $post_before ) {
		if ( $post_after->post_type !== BRICKS_DB_CUSTOM_FONTS || $post_after->post_title === $post_before->post_title ) {
			return;
		}

		self::refresh_font_face_rules();

		// External CSS stores resolved family names, so refreshing @font-face alone leaves stale references.
		if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
			Assets_Files::regenerate_css_files();
		}
	}

	/**
	 * Add inline style for custom @font-face rules
	 *
	 * @since 1.7.2
	 */
	public function add_inline_style_font_face_rules() {
		$font_face_rules = get_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES, false );

		// Generate custom font-face rules if not exist while in wp-admin
		if ( ! $font_face_rules && is_admin() ) {
			$fonts = self::get_custom_fonts();

			// Generate CSS rules without modifying the original font faces structure
			$font_face_rules = self::$font_face_rules;

			if ( $font_face_rules ) {
				update_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES, $font_face_rules );
			}
		}

		// Add inline style for custom @font-face rules
		if ( $font_face_rules ) {
			wp_add_inline_style( is_admin() ? 'bricks-admin' : 'bricks-frontend', $font_face_rules );
		}
	}

	/**
	 * Get all custom fonts (in-builder, frontend, and assets generation)
	 */
	public static function get_custom_fonts() {
		// Return already generated fonts
		if ( self::$fonts ) {
			return self::$fonts;
		}

		$font_ids = get_posts(
			[
				'post_type'      => BRICKS_DB_CUSTOM_FONTS,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true, // Skip the 'found_posts' calculation
			]
		);

		$fonts = [];

		foreach ( $font_ids as $font_id ) {
			// Add 'custom_font_' prefix for correct font order in ControlTypography.vue & to build @font-face from font ID
			$fonts[ "custom_font_{$font_id}" ] = [
				'id'        => "custom_font_{$font_id}",
				'family'    => html_entity_decode( get_the_title( $font_id ), ENT_QUOTES, 'UTF-8' ),
				'fontFaces' => self::generate_font_face_rules( $font_id ),
			];
		}

		self::$fonts = $fonts;

		return $fonts;
	}

	/**
	 * Convert a 16.16 fixed-point value to float.
	 *
	 * @param int $value Raw 16.16 fixed-point value.
	 * @return float
	 */
	private static function fixed_16_16_to_float( $value ) {
		if ( $value > 0x7fffffff ) {
			$value -= 0x100000000;
		}

		return $value / 65536;
	}

	/**
	 * Format a font axis value for CSS output.
	 *
	 * @param float $value Axis value.
	 * @return string
	 */
	private static function format_font_axis_value( $value ) {
		return floor( $value ) === $value ? (string) (int) $value : rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );
	}

	/**
	 * Sanitize a CSS font-weight range descriptor.
	 *
	 * @param string $font_weight_range Raw font-weight range descriptor.
	 * @return string
	 */
	public static function sanitize_font_weight_range( $font_weight_range ) {
		$font_weight_range = preg_replace( '/\s+/', ' ', trim( sanitize_text_field( (string) $font_weight_range ) ) );

		if ( ! preg_match( '/^(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)$/', $font_weight_range, $matches ) ) {
			return '';
		}

		$min = (float) $matches[1];
		$max = (float) $matches[2];

		if ( $min < 1 || $max > 1000 || $min > $max ) {
			return '';
		}

		return self::format_font_axis_value( $min ) . ' ' . self::format_font_axis_value( $max );
	}

	/**
	 * Sanitize a CSS unicode-range descriptor.
	 *
	 * @param string $unicode_range Raw unicode-range descriptor.
	 * @return string
	 */
	public static function sanitize_unicode_range( $unicode_range ) {
		$unicode_range = preg_replace( '/\s+/', '', trim( sanitize_text_field( (string) $unicode_range ) ) );

		if ( ! $unicode_range ) {
			return '';
		}

		$ranges           = explode( ',', $unicode_range );
		$sanitized_ranges = [];

		foreach ( $ranges as $range ) {
			if ( ! preg_match( '/^U\+([0-9A-F?]{1,6})(?:-([0-9A-F]{1,6}))?$/i', $range, $matches ) ) {
				return '';
			}

			$start = strtoupper( $matches[1] );
			$end   = isset( $matches[2] ) ? strtoupper( $matches[2] ) : '';

			if ( $end && strpos( $start, '?' ) !== false ) {
				return '';
			}

			if ( strpos( $start, '?' ) !== false ) {
				$min = hexdec( str_replace( '?', '0', $start ) );
				$max = hexdec( str_replace( '?', 'F', $start ) );
			} else {
				$min = hexdec( $start );
				$max = $end ? hexdec( $end ) : $min;
			}

			if ( $min > $max || $max > 0x10FFFF ) {
				return '';
			}

			$sanitized_ranges[] = 'U+' . $start . ( $end ? '-' . $end : '' );
		}

		return implode( ', ', $sanitized_ranges );
	}

	/**
	 * Parse the OpenType fvar table.
	 *
	 * @param string $table_data Raw fvar table bytes.
	 * @return array
	 */
	private static function parse_fvar_axes( $table_data ) {
		if ( strlen( $table_data ) < 16 ) {
			return [];
		}

		$header = unpack( 'nmajor/nminor/naxes_offset/nreserved/naxis_count/naxis_size/ninstance_count/ninstance_size', substr( $table_data, 0, 16 ) );

		if ( ! $header || empty( $header['axis_count'] ) || empty( $header['axis_size'] ) ) {
			return [];
		}

		$axes   = [];
		$offset = (int) $header['axes_offset'];

		for ( $i = 0; $i < (int) $header['axis_count']; $i++ ) {
			$axis_data = substr( $table_data, $offset + ( $i * (int) $header['axis_size'] ), (int) $header['axis_size'] );

			if ( strlen( $axis_data ) < 20 ) {
				continue;
			}

			$axis_tag = substr( $axis_data, 0, 4 );
			$axis     = unpack( 'Nmin/Ndefault/Nmax', substr( $axis_data, 4, 12 ) );

			if ( ! $axis ) {
				continue;
			}

			$axes[ $axis_tag ] = [
				'min'     => self::fixed_16_16_to_float( $axis['min'] ),
				'default' => self::fixed_16_16_to_float( $axis['default'] ),
				'max'     => self::fixed_16_16_to_float( $axis['max'] ),
			];
		}

		return $axes;
	}

	/**
	 * Return a WOFF table in uncompressed form.
	 *
	 * @param string $table_data Raw WOFF table data.
	 * @param int    $orig_length Original table length.
	 * @return string
	 */
	private static function maybe_uncompress_woff_table( $table_data, $orig_length = 0 ) {
		if ( $orig_length && strlen( $table_data ) === (int) $orig_length ) {
			return $table_data;
		}

		$uncompressed = @gzuncompress( $table_data );

		return $uncompressed ? $uncompressed : $table_data;
	}

	/**
	 * Get the CSS font-weight descriptor for a subset.
	 *
	 * @param array  $subset Subset data.
	 * @param string $font_weight Fallback single weight.
	 * @return string
	 */
	private static function get_subset_font_weight_descriptor( $subset, $font_weight ) {
		if ( ! empty( $subset['font-weight-range'] ) ) {
			$font_weight_range = self::sanitize_font_weight_range( $subset['font-weight-range'] );

			if ( $font_weight_range ) {
				return $font_weight_range;
			}
		}

		return (string) $font_weight;
	}

	/**
	 * Extract a weight range from font metadata when available.
	 *
	 * @param array $font_info Parsed font metadata.
	 * @return string
	 */
	private static function get_font_weight_range_from_info( $font_info ) {
		$weight_axis = $font_info['fvar']['wght'] ?? [];

		if ( ! isset( $weight_axis['min'], $weight_axis['max'] ) || $weight_axis['min'] === $weight_axis['max'] ) {
			return '';
		}

		return self::sanitize_font_weight_range( self::format_font_axis_value( $weight_axis['min'] ) . ' ' . self::format_font_axis_value( $weight_axis['max'] ) );
	}

	/**
	 * Extract the primary weight from a CSS font-weight descriptor.
	 *
	 * @param string $descriptor CSS font-weight descriptor.
	 * @return string
	 */
	private static function get_primary_font_weight_from_descriptor( $descriptor ) {
		$descriptor = trim( (string) $descriptor );

		if ( preg_match( '/^(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)$/', $descriptor, $matches ) ) {
			$min = (float) $matches[1];
			$max = (float) $matches[2];

			if ( $min <= 400 && $max >= 400 ) {
				return '400';
			}

			return (string) $matches[1];
		}

		if ( preg_match( '/\d+(?:\.\d+)?/', $descriptor, $matches ) ) {
			return $matches[0];
		}

		return '400';
	}

	/**
	 * Extract font metadata from a local font file.
	 *
	 * @param string $font_file Path to the font file.
	 * @return array|false
	 */
	private function get_font_info_from_file( $font_file ) {
		$file_ext = strtolower( pathinfo( $font_file, PATHINFO_EXTENSION ) );

		if ( $file_ext === 'ttf' ) {
			return $this->get_ttf_info( $font_file );
		}

		if ( $file_ext === 'woff' ) {
			return $this->get_woff_info( $font_file );
		}

		if ( $file_ext === 'woff2' ) {
			return $this->get_woff2_info( $font_file );
		}

		return false;
	}

	/**
	 * Extract normalized font metadata for a file path.
	 *
	 * @param string $font_file Path to the font file.
	 * @return array|false
	 */
	private function get_normalized_font_metadata( $font_file ) {
		$font_info = $this->get_font_info_from_file( $font_file );

		if ( ! $font_info ) {
			return false;
		}

		$font_weight       = '400';
		$font_style        = '';
		$font_weight_range = self::get_font_weight_range_from_info( $font_info );

		if ( isset( $font_info['OS/2']['usWeightClass'] ) ) {
			$weight = intval( $font_info['OS/2']['usWeightClass'] );

			if ( $weight >= 100 && $weight <= 900 && $weight % 100 === 0 ) {
				$font_weight = (string) $weight;
			}
		}

		if ( isset( $font_info['OS/2']['fsSelection'] ) || isset( $font_info['head']['macStyle'] ) ) {
			$is_italic = false;

			if ( isset( $font_info['OS/2']['fsSelection'] ) ) {
				$is_italic = (bool) ( $font_info['OS/2']['fsSelection'] & 0x01 );
			} elseif ( isset( $font_info['head']['macStyle'] ) ) {
				$is_italic = (bool) ( $font_info['head']['macStyle'] & 0x02 );
			}

			if ( $is_italic ) {
				$font_style = 'italic';
			}
		}

		return [
			'weight'          => $font_weight,
			'style'           => $font_style,
			'fontWeightRange' => $font_weight_range
		];
	}

	/**
	 * Check whether a font-face rule supports the provided font weight.
	 *
	 * @param string $font_face Font-face CSS rule.
	 * @param string $font_weight Requested font weight.
	 * @return bool
	 */
	private static function font_face_supports_weight( $font_face, $font_weight ) {
		if ( ! $font_weight ) {
			return true;
		}

		if ( ! preg_match( '/font-weight:\s*([^;]+)/', $font_face, $matches ) ) {
			return false;
		}

		$descriptor = trim( $matches[1] );

		if ( preg_match( '/^(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)$/', $descriptor, $range_matches ) ) {
			$weight = (float) $font_weight;
			return $weight >= (float) $range_matches[1] && $weight <= (float) $range_matches[2];
		}

		if ( is_numeric( $descriptor ) && is_numeric( $font_weight ) ) {
			return (float) $descriptor === (float) $font_weight;
		}

		return $descriptor === (string) $font_weight;
	}

	/**
	 * Check whether a font-face rule supports the provided font style.
	 *
	 * @param string $font_face Font-face CSS rule.
	 * @param string $font_style Requested font style.
	 * @return bool
	 */
	private static function font_face_supports_style( $font_face, $font_style ) {
		$font_style = $font_style ? $font_style : 'normal';

		if ( ! preg_match( '/font-style:\s*([^;]+)/', $font_face, $matches ) ) {
			return $font_style === 'normal';
		}

		return trim( $matches[1] ) === $font_style;
	}

	/**
	 * Generate custom font-face rules
	 *
	 * Load all font-faces. Otherwise always forced to select font-family + font-weight.
	 *
	 * @param int $font_id Custom font ID.
	 * @return string Font-face rules for $font_id.
	 */
	public static function generate_font_face_rules( $font_id = 0 ) {
		$font_faces = get_post_meta( $font_id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		if ( ! $font_faces ) {
			return;
		}

		$font_family     = html_entity_decode( get_the_title( $font_id ), ENT_QUOTES, 'UTF-8' );
		$font_family     = preg_replace( '/[\x00-\x1F\x7F]/', '', $font_family );
		$font_family     = str_replace(
			[ '\\', '"', '<', '>' ],
			[ '\\\\', '\\"', '\\3C ', '\\3E ' ],
			$font_family
		);
		$font_face_rules = '';

		if ( ! is_array( $font_faces ) ) {
			return;
		}

		// $key: font-weight + variant (e.g.: 700italic)
		foreach ( $font_faces as $key => $font_face ) {
			preg_match( '/^(\d+(?:\.\d+)?)(.*)$/', (string) $key, $key_matches );
			$font_weight = $key_matches[1] ?? '400';
			$font_style  = $key_matches[2] ?? '';

			// Check if new structure (array of subsets) or legacy (single subset)
			// Legacy: ['woff2' => 123, 'woff' => 124] (associative, keys are formats)
			// New: [['woff2' => 123, 'unicode-range' => '...'], ['woff2' => 125]] (numeric keys)
			$subsets = [];
			if ( isset( $font_face[0] ) && is_array( $font_face[0] ) ) {
				$subsets = $font_face;
			} else {
				$subsets = [ $font_face ];
			}

			foreach ( $subsets as $subset ) {
				$src                    = [];
				$unicode_range          = ! empty( $subset['unicode-range'] ) ? self::sanitize_unicode_range( $subset['unicode-range'] ) : '';
				$font_weight_descriptor = self::get_subset_font_weight_descriptor( $subset, $font_weight );

				foreach ( $subset as $format => $attachment_id ) {
					if ( in_array( $format, [ 'unicode-range', 'font-weight-range' ], true ) ) {
						continue;
					}

					$font_variant_url = wp_get_attachment_url( $attachment_id );

					if ( $font_variant_url ) {
						if ( $format === 'ttf' ) {
							$format = 'truetype';
						} elseif ( $format === 'otf' ) {
							$format = 'opentype';
						} elseif ( $format === 'eot' ) {
							$format = 'embedded-opentype';
						}

						// Load woff2 first (smaller file size, almost same support as 'woff')
						if ( $format === 'woff2' ) {
							array_unshift( $src, "url($font_variant_url) format(\"$format\")" );
						} else {
							array_push( $src, "url($font_variant_url) format(\"$format\")" );
						}
					}
				}

				if ( ! count( $src ) ) {
					continue; // Skip this subset if no sources found
				}

				$src = implode( ',', $src );

				if ( $font_family && $src ) {
					$font_face_rules .= '@font-face{';
					$font_face_rules .= "font-family:\"$font_family\";";

					if ( $font_weight_descriptor ) {
						$font_face_rules .= "font-weight:$font_weight_descriptor;";
					}

					if ( $font_style ) {
						$font_face_rules .= "font-style:$font_style;";
					}

					if ( $unicode_range ) {
						$font_face_rules .= "unicode-range:$unicode_range;";
					}

					$font_face_rules .= 'font-display:swap;';
					$font_face_rules .= "src:$src;";
					$font_face_rules .= '}';
				}
			}
		}

		self::$font_face_rules .= "$font_face_rules\n";

		return $font_face_rules;
	}

	/**
	 * Preload custom fonts in wp_head
	 *
	 * Hook priority 1 to ensure it runs before other styles/scripts.
	 *
	 * @see https://web.dev/articles/codelab-preload-web-fonts
	 * @since 2.0
	 */
	public function preload_custom_fonts() {
		$fonts = self::get_custom_fonts();

		if ( ! $fonts ) {
			return;
		}

		// Collect all theme style and element settings that use custom fonts
		$all_elements = [];

		// Get active theme styles
		$theme_styles = Theme_Styles::$settings_by_id;

		if ( is_array( $theme_styles ) && ! empty( $theme_styles ) ) {
			// Loop through each theme style to check for custom fonts
			foreach ( $theme_styles as $theme_style_id => $theme_style_settings ) {
				if ( is_array( $theme_style_settings ) ) {
					foreach ( $theme_style_settings as $group => $settings ) {
						if ( is_array( $settings ) ) {
							foreach ( $settings as $setting_key => $setting_value ) {
								if ( ! empty( $setting_value['font-family'] ) && strpos( $setting_value['font-family'], 'custom_font_' ) === 0 ) {
									// If the font-family is a custom font, add it to the all_elements array
									$all_elements[] = [
										'settings' => [
											$setting_key => $setting_value,
										],
									];
								}
							}
						}
					}
				}
			}
		}

		$content_type           = Database::$active_templates['content_type'] ?? 'content';
		$header_template_id     = Database::$active_templates['header'] ?? 0;
		$content_post_id        = Database::$active_templates[ $content_type ] ?? get_the_ID();
		$footer_template_id     = Database::$active_templates['footer'] ?? 0;
		$page_settings_post_ids = array_filter(
			[
				$header_template_id,
				is_array( $content_post_id ) ? 0 : $content_post_id,
				$footer_template_id,
			]
		);

		$header_elements  = Database::get_nested_template_data( Database::get_template_data( 'header' ) );
		$content_elements = Database::get_nested_template_data( Database::get_template_data( $content_type ) );
		$footer_elements  = Database::get_nested_template_data( Database::get_template_data( 'footer' ) );

		if ( is_array( $header_elements ) ) {
			$all_elements = array_merge( $all_elements, $header_elements );
		}

		if ( is_array( $content_elements ) ) {
			$all_elements = array_merge( $all_elements, $content_elements );
		}

		if ( is_array( $footer_elements ) ) {
			$all_elements = array_merge( $all_elements, $footer_elements );
		}

		foreach ( array_unique( $page_settings_post_ids ) as $post_id ) {
			$page_settings = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );

			if ( ! empty( $page_settings ) && is_array( $page_settings ) ) {
				$all_elements[] = [
					'settings' => $page_settings,
				];
			}

			$template_settings = get_post_meta( $post_id, BRICKS_DB_TEMPLATE_SETTINGS, true );

			if ( ! empty( $template_settings ) && is_array( $template_settings ) ) {
				$all_elements[] = [
					'settings' => $template_settings,
				];
			}
		}

		$link_tags_preloaded     = [];
		$custom_font_occurrences = [];

		// Create rel="preload" tags for all custom font occurrences in the elements
		foreach ( $all_elements as $element ) {
			$element_settings  = ! empty( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			$element_selectors = isset( $element['selectors'] ) && is_array( $element['selectors'] ) ? $element['selectors'] : [];

			// Collect all class settings (@since 2.0.2)
			$element_class_ids = ! empty( $element_settings['_cssGlobalClasses'] ) && is_array( $element_settings['_cssGlobalClasses'] ) ? $element_settings['_cssGlobalClasses'] : false;
			if ( $element_class_ids ) {
				foreach ( $element_class_ids as $class_id ) {
					// Get global class settings by ID
					$class          = Helpers::get_global_class_by_id( $class_id, false );
					$class_settings = $class['settings'] ?? null;

					if ( isset( $class['selectors'] ) && is_array( $class['selectors'] ) ) {
						$element_selectors = array_merge( $element_selectors, $class['selectors'] );
					}

					// Add class settings to elements settings with class.id prefixed
					if ( is_array( $class_settings ) ) {
						foreach ( $class_settings as $class_key => $class_value ) {
							$element_settings[ "class.$class_id.$class_key" ] = $class_value;
						}
					}
				}
			}

			// Selector typography is stored separately from root element and class settings.
			foreach ( $element_selectors as $selector ) {
				if ( isset( $selector['settings'] ) && is_array( $selector['settings'] ) ) {
					foreach ( $selector['settings'] as $selector_value ) {
						$element_settings[] = $selector_value;
					}
				}
			}

			// Collect all component settings (@since 2.1)
			$component_instance = ! empty( $element['cid'] ) ? Helpers::get_component_instance( $element ) : false;
			if ( $component_instance ) {
				foreach ( $component_instance['elements'] as $component_element ) {
					if ( ! empty( $component_element['settings'] ) && is_array( $component_element['settings'] ) ) {
						foreach ( $component_element['settings'] as $comp_key => $comp_value ) {
							$element_settings[ "{$element['id']}.{$component_element['id']}.$comp_key" ] = $comp_value;
						}
					}
				}
			}

			foreach ( $element_settings as $key => $value ) {
				$font_family = $value['font-family'] ?? '';
				$font_weight = $value['font-weight'] ?? '400';
				$font_style  = $value['font-style'] ?? '';

				$custom_font_occurrence = $font_family . '|' . $font_weight . '|' . $font_style;

				// Skip duplicate occurrences
				if ( in_array( $custom_font_occurrence, $custom_font_occurrences, true ) ) {
					continue;
				}

				$custom_font_occurrences[] = $custom_font_occurrence;
				$custom_font               = $fonts[ $font_family ] ?? null;

				if ( $custom_font ) {
					$font_faces = $custom_font['fontFaces'] ?? false;

					if ( $font_faces ) {
						// Split by @font-face
						$font_faces = explode( '@font-face', $font_faces );
						$font_face  = '';

						// Get the first font-face that matches the current font-weight, and font-style
						foreach ( $font_faces as $face ) {
							if ( ! self::font_face_supports_weight( $face, $font_weight ) ) {
								continue;
							}

							if ( self::font_face_supports_style( $face, $font_style ) ) {
								$font_face = $face;
								break;
							}

							if ( ! $font_face ) {
								$font_face = $face;
							}
						}

						if ( $font_face ) {
							// Get font URL from the $font_face string
							preg_match_all( '/url\((.*?)\)/', $font_face, $matches );
							$font_urls = $matches[1] ?? [];
							$font_url  = $font_urls[0] ?? '';

							// Get file format from the URL
							$file_extension = pathinfo( $font_url, PATHINFO_EXTENSION );

							// Add preload link tag for the font URL
							if ( $font_url && in_array( $file_extension, [ 'woff2', 'woff', 'ttf' ], true ) ) {
								$link_tags_preloaded[] = sprintf(
									'<link rel="preload" href="%s" as="font" type="font/%s" crossorigin="anonymous">',
									esc_url( $font_url ),
									esc_attr( $file_extension )
								);
							}
						}
					}
				}
			}
		}

		// Return: No custom fonts to preload
		if ( empty( $link_tags_preloaded ) ) {
			return;
		}

		// Output the preload link tags
		echo implode( "\n", $link_tags_preloaded ) . "\n";
	}

	public function admin_enqueue_scripts() {
		$current_screen = get_current_screen();

		if ( is_object( $current_screen ) && $current_screen->post_type === BRICKS_DB_CUSTOM_FONTS ) {
			// Generate custom font-face rules on custom font edit page
			$this->generate_custom_font_face_rules();

			wp_enqueue_media();

			wp_enqueue_script( 'bricks-custom-fonts', BRICKS_URL_ASSETS . 'js/custom-fonts.min.js', [], filemtime( BRICKS_PATH_ASSETS . 'js/custom-fonts.min.js' ), true );
		}
	}

	public function add_meta_boxes() {
		add_meta_box(
			'bricks-font-metabox',
			esc_html__( 'Manage your custom font files', 'bricks' ),
			[ $this, 'render_meta_boxes' ],
			BRICKS_DB_CUSTOM_FONTS,
			'normal',
			'default'
		);
	}

	/**
	 * Enable font file uploads for the following mime types: .TTF, .woff, .woff2
	 *
	 * Specified in 'get_custom_fonts_mime_types' function below.
	 *
	 * .EOT only supported in IE (https://caniuse.com/?search=eot)
	 *
	 * https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Common_types
	 */
	public function upload_mimes( $mime_types ) {
		// NOTE: Check for full builder access to upload .woff2 files in font manager (@since 2.0)
		if ( Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			foreach ( self::get_custom_fonts_mime_types() as $type => $mime ) {
				if ( ! isset( $mime_types[ $type ] ) ) {
					$mime_types[ $type ] = $mime;
				}
			}
		}

		return $mime_types;
	}

	/**
	 * Get custom font MIME types for import/export integrations.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_public_custom_fonts_mime_types() {
		return self::get_custom_fonts_mime_types();
	}

	/**
	 * Get custom font MIME types.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_custom_fonts_mime_types() {
		$font_mime_types = [
			// 'eot'   => 'font/eot', // <IE9 only (if specified, it must be listed first)
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
		];

		// NOTE: Undocumented
		return apply_filters( 'bricks/custom_fonts/mime_types', $font_mime_types );
	}

	public function render_meta_boxes( $post ) {
		echo '<h2 class="title">';
		esc_html_e( 'Manage your custom font files', 'bricks' );
		echo Helpers::article_link( 'builder/styling/custom-fonts/', '<i class="dashicons dashicons-editor-help"></i>' );
		echo '</h2>';

		$font_faces = get_post_meta( $post->ID, BRICKS_DB_CUSTOM_FONT_FACES, true );

		if ( is_array( $font_faces ) && count( $font_faces ) ) {
			foreach ( $font_faces as $font_variant => $font_face ) {
				// Handle new structure (array of subsets) vs legacy (single subset)
				$subsets = [];
				if ( isset( $font_face[0] ) && is_array( $font_face[0] ) ) {
					$subsets = $font_face;
				} else {
					$subsets = [ $font_face ];
				}

				foreach ( $subsets as $subset ) {
					echo self::render_font_faces_meta_box( $subset, $font_variant );
				}
			}
		} else {
			echo self::render_font_faces_meta_box( [], 400 );
		}

		echo '<button id="bricks-custom-fonts-add-font-variant" class="button button-primary">' . esc_html__( 'Add a font variant', 'bricks' ) . '</button>';
	}

	public static function render_font_faces_meta_box( $font_face = [], $font_variant = 400 ) {
		$mime_types    = self::get_custom_fonts_mime_types();
		$font_weight   = substr( $font_variant, 0, 3 );
		$font_style    = substr( $font_variant, 3, strlen( $font_variant ) );
		$unicode_range = isset( $font_face['unicode-range'] ) ? $font_face['unicode-range'] : '';

		ob_start();
		?>
		<div class="bricks-font-variant">
			<div class="font-header">
				<div
					class="bricks-font-weight-wrapper"
					data-balloon="<?php esc_html_e( 'Font weight', 'bricks' ); ?>">
					<select name="font_weight">
						<option value="100" <?php selected( $font_weight, 100, true ); ?>><?php echo '100 (' . esc_html__( 'Thin', 'bricks' ); ?>)</option>
						<option value="200" <?php selected( $font_weight, 200, true ); ?>><?php echo '200 (' . esc_html__( 'Extra Light', 'bricks' ); ?>)</option>
						<option value="300" <?php selected( $font_weight, 300, true ); ?>><?php echo '300 (' . esc_html_x( 'Light', 'font weight', 'bricks' ); ?>)</option>
						<option value="400" <?php selected( $font_weight, 400, true ); ?>><?php echo '400 (' . esc_html_x( 'Normal', 'font weight', 'bricks' ); ?>)</option>
						<option value="500" <?php selected( $font_weight, 500, true ); ?>><?php echo '500 (' . esc_html_x( 'Medium', 'font weight', 'bricks' ); ?>)</option>
						<option value="600" <?php selected( $font_weight, 600, true ); ?>><?php echo '600 (' . esc_html__( 'Semi Bold', 'bricks' ); ?>)</option>
						<option value="700" <?php selected( $font_weight, 700, true ); ?>><?php echo '700 (' . esc_html_x( 'Bold', 'font weight', 'bricks' ); ?>)</option>
						<option value="800" <?php selected( $font_weight, 800, true ); ?>><?php echo '800 (' . esc_html__( 'Extra Bold', 'bricks' ); ?>)</option>
						<option value="900" <?php selected( $font_weight, 900, true ); ?>><?php echo '900 (' . esc_html_x( 'Black', 'font weight', 'bricks' ); ?>)</option>
					</select>
				</div>

				<div
					class="bricks-font-style-wrapper"
					data-balloon="<?php esc_html_e( 'Font style', 'bricks' ); ?>">
					<select name="font_style">
						<option value="" <?php selected( $font_style, '', true ); ?>><?php echo esc_html_x( 'Normal', 'font style', 'bricks' ); ?></option>
						<option value="italic" <?php selected( $font_style, 'italic', true ); ?>><?php esc_html_e( 'Italic', 'bricks' ); ?></option>
						<option value="oblique" <?php selected( $font_style, 'oblique', true ); ?>><?php esc_html_e( 'Oblique', 'bricks' ); ?></option>
					</select>
				</div>

				<div class="bricks-font-unicode-range-wrapper" style="margin-left: 10px; flex-grow: 1;<?php echo ! $unicode_range ? ' display: none;' : ''; ?>">
					<input type="text" name="unicode_range" value="<?php echo esc_attr( $unicode_range ); ?>" placeholder="<?php esc_attr_e( 'Unicode Range (e.g. U+0025-00A9)', 'bricks' ); ?>" style="width: 100%;" readonly>
				</div>

				<div
					class="bricks-font-preview"
					data-balloon="<?php esc_html_e( 'Font preview', 'bricks' ); ?>">
				<?php
				$font_id     = get_the_ID();
				$font_family = get_the_title();
				$style       = [
					'font-family: "' . $font_family . '"',
					'font-weight: ' . $font_weight,
				];

				if ( ! empty( $font_style ) ) {
					$style[] = "font-style: $font_style";
				}
				?>
					<div class="pangram" style='<?php echo implode( ';', $style ); ?>'><?php esc_html_e( 'The quick brown fox jumps over the lazy dog.', 'bricks ' ); ?></div>
				</div>

				<div class="actions">
					<button class="button edit" data-label="<?php esc_html_e( 'Close', 'bricks' ); ?>"><?php esc_html_e( 'Edit', 'bricks' ); ?></button>
					<button class="button delete"><?php esc_html_e( 'Delete', 'bricks' ); ?></button>
				</div>
			</div>

			<ul class="font-faces hide">
				<?php
				foreach ( $mime_types as $extension => $mime_type ) {
					$font_id     = isset( $font_face[ $extension ] ) ? $font_face[ $extension ] : '';
					$font_url    = wp_get_attachment_url( $font_id );
					$file_size   = $font_id ? ceil( filesize( get_attached_file( $font_id ) ) / 1024 ) . ' KB' : false;
					$placeholder = '';

					switch ( $extension ) {
						case 'ttf':
							$placeholder = esc_html__( 'TrueType Font: Uncompressed font data, but partial IE9+ support.', 'bricks' );
							break;

						case 'woff':
							$placeholder = esc_html__( 'Web Open Font Format: Compressed TrueType/OpenType font with information about font source and full IE9+ support (recommended).', 'bricks' );
							break;

						case 'woff2':
							$placeholder = esc_html__( 'Web Open Font Format 2.0: TrueType/OpenType font with even better compression than WOFF 1.0, but no IE browser support.', 'bricks' );
							break;
					}
					?>
				<li class="font-face">
					<label>
						<div class="font-name" data-balloon="<?php echo $file_size; ?>">
							<?php
							// translators: %s: Font file extension (e.g.: TTF, WOFF, WOFF2)
							printf( esc_html__( '%s file', 'bricks' ), strtoupper( $extension ) );
							?>
						</div>
					</label>

					<input type="url" name="font_url" value="<?php echo $font_url; ?>" placeholder="<?php echo $placeholder; ?>" readonly>
					<input type="number" name="font_id" value="<?php echo $font_id; ?>" readonly>

					<button
						id="<?php echo Helpers::generate_random_id(); ?>"
						class="button upload<?php echo $font_id ? ' hide' : ''; ?>"
						data-mime-type="<?php echo esc_attr( $mime_type ); ?>"
						data-extension="<?php echo esc_attr( $extension ); ?>"
						<?php // translators: %s: Font file extension (e.g.: TTF, WOFF, WOFF2) ?>
						data-title="<?php echo esc_attr( sprintf( esc_html__( 'Upload .%s file', 'bricks' ), $extension ) ); ?>"><?php esc_html_e( 'Upload', 'bricks' ); ?></button>
					<button class="button remove<?php echo $font_id ? '' : ' hide'; ?>"><?php esc_html_e( 'Remove', 'bricks' ); ?></button>
				</li>
				<?php } ?>
			</ul>
		</div>

			<?php
			return ob_get_clean();
	}

	public function save_font_faces() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		$post_id    = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$font_faces = isset( $_POST['font_faces'] ) ? json_decode( stripslashes( $_POST['font_faces'] ), true ) : false;

		if ( ! Capabilities::current_user_can_use_builder( $post_id ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		if ( is_array( $font_faces ) && count( $font_faces ) ) {
			$updated = update_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, $font_faces );
		} else {
			$updated = delete_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES );
		}

		// Update font face rules in options table (@since 1.7.2)
		if ( $updated ) {
			self::refresh_font_face_rules();
		}

		wp_send_json_success(
			[
				'post_id'    => $post_id,
				'font_faces' => $font_faces,
				'updated'    => $updated,
			]
		);
	}

	public function manage_columns( $columns ) {
		$columns = [
			'cb'           => '<input type="checkbox" />',
			'title'        => esc_html__( 'Font family', 'bricks' ),
			'font_preview' => esc_html__( 'Font preview', 'bricks' ),
		];

		$mime_types = self::get_custom_fonts_mime_types();

		foreach ( $mime_types as $extension => $label ) {
			// translators: %s: Font file extension (e.g.: TTF, WOFF, WOFF2)
			$columns[ $extension ] = sprintf( esc_html__( '%s file', 'bricks' ), strtoupper( $extension ) );
		}

		return $columns;
	}

	public function render_columns( $column, $post_id ) {
		if ( $column === 'font_preview' ) {
			echo '<div class="pangram" style="font-family: \'' . get_the_title( $post_id ) . '\'; font-size: 18px">';

			esc_html_e( 'The quick brown fox jumps over the lazy dog.', 'bricks ' );

			echo '</div>';
		}

		$extensions = array_keys( self::get_custom_fonts_mime_types() );
		$font_faces = get_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		if ( in_array( $column, $extensions, true ) && $font_faces ) {
			$has_font_file = false;

			foreach ( $font_faces as $font_variant => $font_face ) {
				if ( ! empty( $font_face[ $column ] ) ) {
					$has_font_file = true;
					break;
				}

				if ( isset( $font_face[0] ) && is_array( $font_face[0] ) ) {
					foreach ( $font_face as $subset ) {
						if ( ! empty( $subset[ $column ] ) ) {
							$has_font_file = true;
							break 2;
						}
					}
				}
			}

			echo $has_font_file ? '<i class="dashicons dashicons-yes-alt"></i>' : '<i class="dashicons dashicons-minus"></i>';
		}
	}

	public function post_row_actions( $actions, $post ) {
		// Remove 'Quick Edit'
		if ( $post->post_type === BRICKS_DB_CUSTOM_FONTS ) {
			// unset( $actions['inline hide-if-no-js'] );
			unset( $actions['view'] );
		}

		return $actions;
	}

	public function register_post_type() {
		$args = [
			'labels'              => [
				'name'               => esc_html__( 'Custom fonts', 'bricks' ),
				'singular_name'      => esc_html__( 'Custom font', 'bricks' ),
				'add_new'            => esc_html__( 'Add New', 'bricks' ),
				'add_new_item'       => esc_html__( 'Add New Custom Font', 'bricks' ),
				'edit_item'          => esc_html__( 'Edit Custom Font', 'bricks' ),
				'new_item'           => esc_html__( 'New Custom Font', 'bricks' ),
				'view_item'          => esc_html__( 'View Custom Font', 'bricks' ),
				'view_items'         => esc_html__( 'View Custom Fonts', 'bricks' ),
				'search_items'       => esc_html__( 'Search Custom Fonts', 'bricks' ),
				'not_found'          => esc_html__( 'No Custom Fonts found', 'bricks' ),
				'not_found_in_trash' => esc_html__( 'No Custom Font found in Trash', 'bricks' ),
				'all_items'          => esc_html__( 'All Custom Fonts', 'bricks' ),
				'menu_name'          => esc_html__( 'Custom fonts', 'bricks' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'exclude_from_search' => true,
			'hierarchical'        => false,
			'rewrite'             => false,
			'supports'            => [ 'title' ],
		];

		// Use meta capabilities for custom fonts access control
		$args['capability_type'] = 'custom_font';
		$args['capabilities']    = [
			'read_post'              => 'read_custom_font',
			'edit_post'              => 'edit_custom_font',
			'edit_posts'             => 'edit_custom_fonts',
			'edit_others_posts'      => 'edit_others_custom_fonts',
			'publish_posts'          => 'publish_custom_fonts',
			'read_private_posts'     => 'read_private_custom_fonts',
			'delete_post'            => 'delete_custom_font',
			'delete_posts'           => 'delete_custom_fonts',
			'delete_others_posts'    => 'delete_others_custom_fonts',
			'delete_published_posts' => 'delete_published_custom_fonts',
			'delete_private_posts'   => 'delete_private_custom_fonts',
			'edit_private_posts'     => 'edit_private_custom_fonts',
			'edit_published_posts'   => 'edit_published_custom_fonts',
			'create_posts'           => 'create_custom_fonts',
		];

		register_post_type( BRICKS_DB_CUSTOM_FONTS, $args );
	}

	/**
	 * Get custom font data for the font editor
	 */
	public function get_custom_font_data() {
		\Bricks\Ajax::verify_nonce( 'bricks-nonce-builder' );

		$post_id = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;

		if ( ! $post_id || ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$font_faces = get_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		$font_data = [];

		if ( $font_faces ) {
			foreach ( $font_faces as $key => $face ) {
				$subsets = [];
				if ( isset( $face[0] ) && is_array( $face[0] ) ) {
					// New structure: array of subsets
					$subsets = $face;
				} else {
					// Legacy structure: single subset
					$subsets[] = $face;
				}

				$processed_subsets = [];

				foreach ( $subsets as $subset ) {
					$url               = '';
					$type              = '';
					$filename          = '';
					$unicode_range     = ! empty( $subset['unicode-range'] ) ? self::sanitize_unicode_range( $subset['unicode-range'] ) : '';
					$font_weight_range = ! empty( $subset['font-weight-range'] ) ? $subset['font-weight-range'] : '';

					// Check each format in order of preference
					foreach ( [ 'woff2', 'woff', 'ttf' ] as $format ) {
						if ( isset( $subset[ $format ] ) && $subset[ $format ] ) {
							$attachment_id = $subset[ $format ];
							$url           = wp_get_attachment_url( $attachment_id );
							if ( $url ) {
								$type     = 'font/' . $format;
								$filename = basename( get_attached_file( $attachment_id ) );
								break;
							}
						}
					}

					$processed_subsets[] = [
						'url'               => $url,
						'type'              => $type,
						'filename'          => $filename,
						'unicode-range'     => $unicode_range,
						'font-weight-range' => $font_weight_range,
					];
				}

				// If it's legacy (single subset), return just the object to maintain BC if needed?
				// Actually, frontend should be updated to handle array. But for now, let's return array if > 1 or if it was new structure.
				// However, if we change the structure, frontend needs to know.
				// The plan says: "Handle the new array-based structure when loading font data." in frontend.
				// So I can return array of subsets. But to be safe for existing frontend code (if it's not updated yet? No I update it now),
				// I'll return array of objects for this key.
				$font_data[ $key ] = $processed_subsets;
			}
		}

		wp_send_json_success(
			[
				'fontFaces' => $font_data,
				'family'    => get_the_title( $post_id )
			]
		);
	}

	/**
	 * Get font metadata for an existing attachment.
	 *
	 * @return void
	 */
	public function get_font_attachment_metadata() {
		\Bricks\Ajax::verify_nonce( 'bricks-nonce-builder' );

		$attachment_id = isset( $_POST['attachmentId'] ) ? intval( $_POST['attachmentId'] ) : 0;

		if ( ! $attachment_id || ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$attachment = get_post( $attachment_id );

		// Match WordPress media-library AJAX endpoints, which gate attachment access with 'upload_files'.
		if (
			! $attachment ||
			$attachment->post_type !== 'attachment' ||
			! current_user_can( 'upload_files' )
		) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$font_file = get_attached_file( $attachment_id );

		if ( ! $font_file || ! is_readable( $font_file ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Font file not found.', 'bricks' ) ] );
		}

		$file_type = wp_check_filetype( $font_file, self::get_custom_fonts_mime_types() );

		if ( empty( $file_type['ext'] ) || empty( $file_type['type'] ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$font_metadata = $this->get_normalized_font_metadata( $font_file );

		wp_send_json_success( $font_metadata ? $font_metadata : [] );
	}

	/**
	 * Create a draft custom font post and return the post ID
	 *
	 * @since 2.0
	 */
	public function create_draft_font() {
		\Bricks\Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		// Create a draft custom font post
		$post_data = [
			'post_title'  => '', // Empty title initially
			'post_type'   => BRICKS_DB_CUSTOM_FONTS,
			'post_status' => 'draft',
		];

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Failed to create font draft.', 'bricks' ) ] );
		}

		// Return the real post ID with the custom_font_ prefix
		wp_send_json_success(
			[
				'fontId' => "custom_font_{$post_id}",
				'postId' => $post_id
			]
		);
	}

	/**
	 * Delete an empty draft custom font post
	 *
	 * This cleans up unused draft posts when users create but don't use fonts
	 *
	 * @since 2.0
	 */
	public function delete_draft_font() {
		\Bricks\Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		$post_id = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid font ID.', 'bricks' ) ] );
		}

		// Get the post to verify it exists and is a draft
		$post = get_post( $post_id );

		if ( ! $post || $post->post_type !== BRICKS_DB_CUSTOM_FONTS ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Font not found.', 'bricks' ) ] );
		}

		// Only allow deletion of drafts with empty titles and no font faces
		$font_faces     = get_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, true );
		$is_empty_draft = ( $post->post_status === 'draft' || empty( trim( $post->post_title ) ) ) &&
						( ! $font_faces || ( is_array( $font_faces ) && empty( $font_faces ) ) );

		if ( ! $is_empty_draft ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Only empty draft fonts can be deleted.', 'bricks' ) ] );
		}

		// Delete the post permanently (since it's unused)
		$result = wp_delete_post( $post_id, true );

		if ( ! $result ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete draft font.', 'bricks' ) ] );
		}

		wp_send_json_success( [ 'message' => esc_html__( 'Draft font deleted successfully.', 'bricks' ) ] );
	}

	/**
	 * Download Google Font and convert it to a custom font
	 *
	 * @since 1.0
	 * @throws \Exception When user doesn't have permissions or when font download/processing fails.
	 */
	public function download_google_font() {
		Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		$font_family = isset( $_POST['family'] ) ? sanitize_text_field( $_POST['family'] ) : '';
		$variants    = isset( $_POST['variants'] ) ? array_map( 'sanitize_text_field', $_POST['variants'] ) : [];

		try {
			// Create custom font post
			$font_post_data = [
				'post_title'  => $font_family,
				'post_type'   => BRICKS_DB_CUSTOM_FONTS,
				'post_status' => 'publish',
			];

			$font_post_id = wp_insert_post( $font_post_data );

			if ( is_wp_error( $font_post_id ) ) {
				throw new \Exception( $font_post_id->get_error_message() );
			}

			// Build a Google Fonts API URL that includes all the variants
			$font_family_encoded = str_replace( ' ', '+', $font_family );
			$api_url             = 'https://fonts.googleapis.com/css2?family=' . $font_family_encoded;

			// Add specific variants to the URL
			if ( ! empty( $variants ) ) {
				// Use the "ital,wght@" format which is required for Google Fonts API v2
				$variant_params = [];
				foreach ( $variants as $variant ) {
					// Extract weight (or use 400 as default)
					$weight = filter_var( $variant, FILTER_SANITIZE_NUMBER_INT );
					if ( ! $weight ) {
						$weight = '400';
					}

					// Check if it's italic
					$is_italic = strpos( $variant, 'italic' ) !== false ? '1' : '0';

					// Format: "0,400" for normal 400, "1,400" for italic 400
					$variant_params[] = $is_italic . ',' . $weight;
				}

				if ( ! empty( $variant_params ) ) {
					// IMPORTANT: Google requires tuples to be sorted by italic first, then by weight
					usort(
						$variant_params,
						function( $a, $b ) {
							// Extract italic and weight values
							list( $a_italic, $a_weight ) = explode( ',', $a );
							list( $b_italic, $b_weight ) = explode( ',', $b );

							// Sort by italic first (0 before 1)
							if ( $a_italic !== $b_italic ) {
								return $a_italic <=> $b_italic;
							}

							// Then sort by weight
							return (int) $a_weight <=> (int) $b_weight;
						}
					);

					$api_url .= ':ital,wght@' . implode( ';', $variant_params );
				}
			}

			$api_url .= '&display=swap';

			$response = wp_remote_get(
				$api_url,
				[
					'headers' => [
						'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36'
					]
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			$css = wp_remote_retrieve_body( $response );

			if ( empty( $css ) || strpos( $css, '@font-face' ) === false ) {
				throw new \Exception( esc_html__( 'Invalid CSS response from Google Fonts.', 'bricks' ) );
			}

			// Extract font-weight, font-style, unicode-range, and url for each @font-face
			preg_match_all( '/@font-face\s*{([^}]+)}/i', $css, $font_face_matches );

			// Store parsed font faces grouped by key: '400normal' => [ {url, format, unicode-range}, ... ]
			$parsed_font_faces = [];

			if ( ! empty( $font_face_matches[1] ) ) {
				foreach ( $font_face_matches[1] as $font_face_css ) {
					// Extract URL and Format
					preg_match( '/url\((.*?)\)\s*format\([\'"](\w+)[\'"]\)/', $font_face_css, $url_match );
					$url    = isset( $url_match[1] ) ? trim( $url_match[1], '"\'' ) : '';
					$format = isset( $url_match[2] ) ? $url_match[2] : '';

					if ( ! $url ) {
						continue;
					}

					// Extract font-weight descriptor
					preg_match( '/font-weight:\s*([^;]+)/i', $font_face_css, $weight_match );
					$font_weight_descriptor = isset( $weight_match[1] ) ? trim( $weight_match[1] ) : '400';
					$font_weight_range      = self::sanitize_font_weight_range( $font_weight_descriptor );
					$weight                 = self::get_primary_font_weight_from_descriptor( $font_weight_descriptor );

					// Extract font-style
					preg_match( '/font-style:\s*(\w+)/i', $font_face_css, $style_match );
					$style = isset( $style_match[1] ) && $style_match[1] === 'italic' ? 'italic' : '';

					// Extract unicode-range
					preg_match( '/unicode-range:\s*([^;]+)/i', $font_face_css, $range_match );
					$unicode_range = isset( $range_match[1] ) ? self::sanitize_unicode_range( $range_match[1] ) : '';

					// Create variant key
					$variant_key = $weight . $style;

					if ( ! isset( $parsed_font_faces[ $variant_key ] ) ) {
						$parsed_font_faces[ $variant_key ] = [];
					}

					$parsed_font_faces[ $variant_key ][] = [
						'url'               => $url,
						'format'            => $format,
						'unicode-range'     => $unicode_range,
						'font-weight-range' => $font_weight_range
					];
				}
			}

			$font_faces    = [];
			$error_message = false;

			// Cache downloaded files to avoid re-downloading same file for multiple ranges if that happens
			$downloaded_files_cache = [];

			foreach ( $parsed_font_faces as $key => $parsed_variant_faces ) {
				preg_match( '/^(\d+(?:\.\d+)?)(.*)$/', (string) $key, $key_matches );
				$weight = $key_matches[1] ?? '400';
				$style  = $key_matches[2] ?? '';

				$subsets = [];

				foreach ( $parsed_variant_faces as $font_data ) {
					$font_url      = $font_data['url'];
					$font_format   = $font_data['format'];
					$attachment_id = 0;

					// Check cache first
					if ( isset( $downloaded_files_cache[ $font_url ] ) ) {
						$attachment_id = $downloaded_files_cache[ $font_url ];
					} else {
						$font_response = wp_remote_get( $font_url );

						if ( is_wp_error( $font_response ) ) {
							continue;
						}

						$font_content = wp_remote_retrieve_body( $font_response );
						if ( empty( $font_content ) ) {
							continue;
						}

						$temp_file = wp_tempnam( 'bricks-font-' );
						if ( ! file_put_contents( $temp_file, $font_content ) ) {
							continue;
						}

						$extension = $font_format === 'truetype' ? 'ttf' : $font_format;
						$filename  = sanitize_file_name(
							sprintf(
								'%s-%s-%s-%s.%s',
								strtolower( str_replace( ' ', '-', $font_family ) ),
								$weight,
								$style ? 'italic' : 'normal',
								substr( md5( $font_url ), 0, 6 ),
								$extension
							)
						);

						$file_array = [
							'name'     => $filename,
							'tmp_name' => $temp_file,
							'type'     => 'font/' . $extension
						];

						$attachment_id = media_handle_sideload( $file_array, $font_post_id );

						// Remove temp file
						@unlink( $temp_file );

						if ( is_wp_error( $attachment_id ) ) {
							$error_message = $attachment_id->get_error_message();
							continue;
						}

						$downloaded_files_cache[ $font_url ] = $attachment_id;
					}

					if ( $attachment_id ) {
						$subset = [
							$extension => $attachment_id
						];

						if ( ! empty( $font_data['unicode-range'] ) ) {
							$unicode_range = self::sanitize_unicode_range( $font_data['unicode-range'] );

							if ( $unicode_range ) {
								$subset['unicode-range'] = $unicode_range;
							}
						}

						if ( ! empty( $font_data['font-weight-range'] ) ) {
							$subset['font-weight-range'] = $font_data['font-weight-range'];
						}

						$subsets[] = $subset;
					}
				}

				if ( ! empty( $subsets ) ) {
					$font_faces[ $key ] = $subsets;
				}
			}

			// Return: Error
			if ( $error_message ) {
				throw new \Exception( $error_message );
			}

			if ( empty( $font_faces ) ) {
				throw new \Exception( esc_html__( 'Failed to process any font files.', 'bricks' ) );
			}

			// Save font faces
			update_post_meta( $font_post_id, BRICKS_DB_CUSTOM_FONT_FACES, $font_faces );

			// Generate font face rules
			$font_face_rules = self::generate_font_face_rules( $font_post_id );

			wp_send_json_success(
				[
					'message'   => esc_html__( 'Font downloaded successfully.', 'bricks' ),
					'fontId'    => "custom_font_{$font_post_id}",
					'family'    => $font_family,
					'fontFaces' => $font_face_rules
				]
			);
		}

		// Error
		catch ( \Exception $e ) {
			if ( ! empty( $font_post_id ) ) {
				wp_delete_post( $font_post_id, true );
			}

			wp_send_json_error(
				[
					'message'    => $e->getMessage(),
					'error_code' => $e->getCode(),
					'font_urls'  => $font_urls ?? '',
				]
			);
		}
	}

	/**
	 * Process uploaded font files and extract metadata.
	 *
	 * @since 1.0
	 * @throws \Exception When user doesn't have permissions or file upload/processing fails.
	 */
	public function process_font_files() {
		Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		if ( ! isset( $_FILES['files'] ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No files were uploaded.', 'bricks' ) ] );
		}

		$processed_fonts = [];
		$files           = $_FILES['files'];
		$font_id         = isset( $_POST['font_id'] ) ? intval( $_POST['font_id'] ) : 0;

		// Ensure files is array of arrays for multiple uploads
		if ( ! is_array( $files['name'] ) ) {
			$files = [
				'name'     => [ $files['name'] ],
				'type'     => [ $files['type'] ],
				'tmp_name' => [ $files['tmp_name'] ],
				'error'    => [ $files['error'] ],
				'size'     => [ $files['size'] ]
			];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Process each uploaded file
		$files_count = count( $files['name'] );
		$i           = 0;
		while ( $i < $files_count ) {
			if ( $files['error'][ $i ] !== 0 ) {
				$i++;
				continue;
			}

			$file = [
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ]
			];

			// Check file type
			$allowed_types = self::get_custom_fonts_mime_types();
			$file_ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

			if ( ! isset( $allowed_types[ $file_ext ] ) ) {
				$i++;
				continue;
			}

			$font_metadata     = $this->get_normalized_font_metadata( $file['tmp_name'] );
			$font_weight       = $font_metadata ? $font_metadata['weight'] : '400';
			$font_style        = $font_metadata ? $font_metadata['style'] : '';
			$font_weight_range = $font_metadata ? $font_metadata['fontWeightRange'] : '';

			// Upload the file
			$upload = wp_handle_upload( $file, [ 'test_form' => false ] );

			if ( isset( $upload['error'] ) ) {
				$i++;
				continue;
			}

			// Prepare attachment data
			$attachment = [
				'post_mime_type' => $upload['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file['name'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit'
			];

			// Insert attachment
			$attach_id = wp_insert_attachment( $attachment, $upload['file'] );

			if ( is_wp_error( $attach_id ) ) {
				$i++;
				continue;
			}

			// Get existing font faces
			$font_faces = get_post_meta( $font_id, BRICKS_DB_CUSTOM_FONT_FACES, true );
			if ( ! is_array( $font_faces ) ) {
				$font_faces = [];
			}

			// Generate variant key (e.g., "400italic")
			$variant_key = $font_weight . $font_style;

			$subset_entry = [
				$file_ext => $attach_id,
			];

			if ( $font_weight_range ) {
				$subset_entry['font-weight-range'] = $font_weight_range;
			}

			if ( isset( $font_faces[ $variant_key ][0] ) && is_array( $font_faces[ $variant_key ][0] ) ) {
				$font_faces[ $variant_key ][] = $subset_entry;
			} else {
				$existing_subset = $font_faces[ $variant_key ] ?? [];

				if ( ! empty( $existing_subset['font-weight-range'] ) && empty( $subset_entry['font-weight-range'] ) ) {
					$subset_entry['font-weight-range'] = $existing_subset['font-weight-range'];
				}

				$font_faces[ $variant_key ] = array_merge( $existing_subset, $subset_entry );
			}

			// Update font faces meta
			update_post_meta( $font_id, BRICKS_DB_CUSTOM_FONT_FACES, $font_faces );

			$processed_fonts[] = [
				'weight'          => $font_weight,
				'style'           => $font_style ? $font_style : 'normal',
				'url'             => wp_get_attachment_url( $attach_id ),
				'type'            => "font/$file_ext",
				'fontWeightRange' => $font_weight_range,
			];

			$i++;
		}

		if ( empty( $processed_fonts ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No valid font files were processed.', 'bricks' ) ] );
		}

		wp_send_json_success( $processed_fonts );
	}

	/**
	 * Extract TTF font metadata.
	 *
	 * @param string $font_file Path to the TTF file to extract metadata from.
	 * @return array|false Font metadata or false on failure.
	 */
	private function get_ttf_info( $font_file ) {
		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem->exists( $font_file ) || ! $wp_filesystem->is_readable( $font_file ) ) {
			return false;
		}

		try {
			$font_data = $wp_filesystem->get_contents( $font_file );
			if ( ! $font_data ) {
				return false;
			}

			$font_info = [];

			// Read version tag (first 4 bytes)
			$version = unpack( 'N', substr( $font_data, 0, 4 ) )[1];

			// Read number of tables (2 bytes at offset 4)
			$num_tables = unpack( 'n', substr( $font_data, 4, 2 ) )[1];

			// Start reading table directory at offset 12
			$offset = 12;
			$tables = [];

			// Read the table directory
			for ( $i = 0; $i < $num_tables; $i++ ) {
				$tag          = substr( $font_data, $offset, 4 );
				$checksum     = unpack( 'N', substr( $font_data, $offset + 4, 4 ) )[1];
				$table_offset = unpack( 'N', substr( $font_data, $offset + 8, 4 ) )[1];
				$length       = unpack( 'N', substr( $font_data, $offset + 12, 4 ) )[1];

				$tables[ $tag ] = [
					'offset' => $table_offset,
					'length' => $length
				];

				$offset += 16;
			}

			// Read OS/2 table if it exists
			if ( isset( $tables['OS/2'] ) ) {
				$os2_data = substr( $font_data, $tables['OS/2']['offset'], $tables['OS/2']['length'] );

				// Extract weight class (bytes 4-5)
				$font_info['OS/2']['usWeightClass'] = unpack( 'n', substr( $os2_data, 4, 2 ) )[1];

				// Extract selection flags (bytes 62-63)
				$font_info['OS/2']['fsSelection'] = unpack( 'n', substr( $os2_data, 62, 2 ) )[1];
			}

			// Read head table if it exists
			if ( isset( $tables['head'] ) ) {
				$head_data = substr( $font_data, $tables['head']['offset'], $tables['head']['length'] );

				// Extract macStyle (bytes 44-45)
				$font_info['head']['macStyle'] = unpack( 'n', substr( $head_data, 44, 2 ) )[1];
			}

			if ( isset( $tables['fvar'] ) ) {
				$fvar_data = substr( $font_data, $tables['fvar']['offset'], $tables['fvar']['length'] );
				if ( $fvar_data ) {
					$font_info['fvar'] = self::parse_fvar_axes( $fvar_data );
				}
			}

			return $font_info;

		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Extract WOFF font metadata.
	 *
	 * @param string $font_file Path to the WOFF file to extract metadata from.
	 * @return array|false Font metadata or false on failure.
	 */
	private function get_woff_info( $font_file ) {
		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem->exists( $font_file ) || ! $wp_filesystem->is_readable( $font_file ) ) {
			return false;
		}

		try {
			$font_data = $wp_filesystem->get_contents( $font_file );
			if ( ! $font_data || strlen( $font_data ) < 44 ) {
				return false;
			}

			// Check WOFF signature
			$signature = substr( $font_data, 0, 4 );
			if ( $signature !== 'wOFF' ) {
				return false;
			}

			// Read number of tables
			$num_tables = unpack( 'n', substr( $font_data, 12, 2 ) )[1];

			// Start reading table directory at offset 44
			$offset = 44;
			$tables = [];

			// Read table directory
			for ( $i = 0; $i < $num_tables; $i++ ) {
				$entry = substr( $font_data, $offset, 20 );
				if ( strlen( $entry ) !== 20 ) {
					break;
				}

				$tag          = substr( $entry, 0, 4 );
				$table_offset = unpack( 'N', substr( $entry, 4, 4 ) )[1];
				$comp_length  = unpack( 'N', substr( $entry, 8, 4 ) )[1];
				$orig_length  = unpack( 'N', substr( $entry, 12, 4 ) )[1];

				$tables[ $tag ] = [
					'offset'      => $table_offset,
					'comp_length' => $comp_length,
					'orig_length' => $orig_length
				];

				$offset += 20;
			}

			$font_info = [];

			// Read OS/2 table if it exists
			if ( isset( $tables['OS/2'] ) ) {
				$os2_data = substr( $font_data, $tables['OS/2']['offset'], $tables['OS/2']['comp_length'] );
				$os2_data = self::maybe_uncompress_woff_table( $os2_data, $tables['OS/2']['orig_length'] );

				if ( $os2_data ) {
					// Extract weight class (bytes 4-5)
					$font_info['OS/2']['usWeightClass'] = unpack( 'n', substr( $os2_data, 4, 2 ) )[1];
					// Extract selection flags (bytes 62-63)
					$font_info['OS/2']['fsSelection'] = unpack( 'n', substr( $os2_data, 62, 2 ) )[1];
				}
			}

			// Read head table if it exists
			if ( isset( $tables['head'] ) ) {
				$head_data = substr( $font_data, $tables['head']['offset'], $tables['head']['comp_length'] );
				$head_data = self::maybe_uncompress_woff_table( $head_data, $tables['head']['orig_length'] );

				if ( $head_data ) {
					// Extract macStyle (bytes 44-45)
					$font_info['head']['macStyle'] = unpack( 'n', substr( $head_data, 44, 2 ) )[1];
				}
			}

			if ( isset( $tables['fvar'] ) ) {
				$fvar_data = substr( $font_data, $tables['fvar']['offset'], $tables['fvar']['comp_length'] );
				$fvar_data = self::maybe_uncompress_woff_table( $fvar_data, $tables['fvar']['orig_length'] );

				if ( $fvar_data ) {
					$font_info['fvar'] = self::parse_fvar_axes( $fvar_data );
				}
			}

			return $font_info;

		} catch ( \Exception $e ) {
			if ( isset( $fd ) && is_resource( $fd ) ) {
				fclose( $fd );
			}
			return false;
		}
	}

	/**
	 * Get WOFF2 known table tags by index.
	 *
	 * @return array
	 */
	private static function get_woff2_known_table_tags() {
		return [
			'cmap',
			'head',
			'hhea',
			'hmtx',
			'maxp',
			'name',
			'OS/2',
			'post',
			'cvt ',
			'fpgm',
			'glyf',
			'loca',
			'prep',
			'CFF ',
			'VORG',
			'EBDT',
			'EBLC',
			'gasp',
			'hdmx',
			'kern',
			'LTSH',
			'PCLT',
			'VDMX',
			'vhea',
			'vmtx',
			'BASE',
			'GDEF',
			'GPOS',
			'GSUB',
			'EBSC',
			'JSTF',
			'MATH',
			'CBDT',
			'CBLC',
			'COLR',
			'CPAL',
			'SVG ',
			'sbix',
			'acnt',
			'avar',
			'bdat',
			'bloc',
			'bsln',
			'cvar',
			'fdsc',
			'feat',
			'fmtx',
			'fvar',
			'gvar',
			'hsty',
			'just',
			'lcar',
			'mort',
			'morx',
			'opbd',
			'prop',
			'trak',
			'Zapf',
			'Silf',
			'Glat',
			'Gloc',
			'Feat',
			'Sill',
		];
	}

	/**
	 * Read a WOFF2 UIntBase128 value.
	 *
	 * @param string $font_data Raw WOFF2 bytes.
	 * @param int    $offset Current byte offset.
	 * @return int|false
	 */
	private static function read_woff2_uint_base128( $font_data, &$offset ) {
		$result = 0;
		$length = strlen( $font_data );

		for ( $i = 0; $i < 5; $i++ ) {
			if ( $offset >= $length ) {
				return false;
			}

			$byte = ord( $font_data[ $offset ] );
			$offset++;

			if ( $i === 0 && $byte === 0x80 ) {
				return false;
			}

			if ( $result & 0xfe000000 ) {
				return false;
			}

			$result = ( $result << 7 ) | ( $byte & 0x7f );

			if ( ( $byte & 0x80 ) === 0 ) {
				return $result;
			}
		}

		return false;
	}

	/**
	 * Decompress WOFF2 Brotli font data when the PHP runtime supports it.
	 *
	 * @param string $compressed Raw compressed WOFF2 table data.
	 * @param int    $expected_length Expected decompressed length.
	 * @return string|false
	 */
	private static function brotli_uncompress_font_data( $compressed, $expected_length = 0 ) {
		$uncompressed = false;

		if ( function_exists( 'brotli_uncompress' ) ) {
			$uncompressed = brotli_uncompress( $compressed );
		} elseif ( function_exists( 'brotli_decode' ) ) {
			$uncompressed = brotli_decode( $compressed );
		}

		if ( ! is_string( $uncompressed ) || $uncompressed === '' ) {
			return false;
		}

		if ( $expected_length && strlen( $uncompressed ) < $expected_length ) {
			return false;
		}

		return $uncompressed;
	}

	/**
	 * Extract WOFF2 font metadata.
	 *
	 * @param string $font_file Path to the WOFF2 file to extract metadata from.
	 * @return array|false Font metadata or false on failure.
	 */
	private function get_woff2_info( $font_file ) {
		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem->exists( $font_file ) || ! $wp_filesystem->is_readable( $font_file ) ) {
			return false;
		}

		try {
			$font_data = $wp_filesystem->get_contents( $font_file );
			if ( ! $font_data || strlen( $font_data ) < 48 ) {
				return false;
			}

			// Check WOFF2 signature
			$signature = substr( $font_data, 0, 4 );
			if ( $signature !== 'wOF2' ) {
				return false;
			}

			$header = unpack( 'Nsignature/Nflavor/Nlength/nnum_tables/nreserved/Ntotal_sfnt_size/Ntotal_compressed_size/nmajor_version/nminor_version/Nmeta_offset/Nmeta_length/Nmeta_orig_length/Npriv_offset/Npriv_length', substr( $font_data, 0, 48 ) );
			if ( ! $header || empty( $header['num_tables'] ) || empty( $header['total_compressed_size'] ) ) {
				return false;
			}

			if ( (int) $header['flavor'] === 0x74746366 ) {
				return false;
			}

			$known_tags = self::get_woff2_known_table_tags();
			$tables     = [];
			$offset     = 48;

			for ( $i = 0; $i < (int) $header['num_tables']; $i++ ) {
				if ( $offset >= strlen( $font_data ) ) {
					return false;
				}

				$flags = ord( $font_data[ $offset ] );
				$offset++;
				$tag_index         = $flags & 0x3f;
				$transform_version = ( $flags >> 6 ) & 0x03;

				if ( $tag_index === 0x3f ) {
					if ( $offset + 4 > strlen( $font_data ) ) {
						return false;
					}

					$tag     = substr( $font_data, $offset, 4 );
					$offset += 4;
				} else {
					$tag = $known_tags[ $tag_index ] ?? false;
				}

				if ( ! $tag ) {
					return false;
				}

				$orig_length = self::read_woff2_uint_base128( $font_data, $offset );
				if ( $orig_length === false ) {
					return false;
				}

				$transform_length = false;
				if ( ( in_array( $tag, [ 'glyf', 'loca' ], true ) && $transform_version !== 3 ) || ( $tag === 'hmtx' && $transform_version !== 0 ) ) {
					$transform_length = self::read_woff2_uint_base128( $font_data, $offset );
					if ( $transform_length === false ) {
						return false;
					}
				}

				$tables[] = [
					'tag'    => $tag,
					'length' => $transform_length === false ? $orig_length : $transform_length,
				];
			}

			$expected_length = 0;
			foreach ( $tables as $table ) {
				$expected_length += (int) $table['length'];
			}

			$compressed = substr( $font_data, $offset, (int) $header['total_compressed_size'] );
			if ( $compressed === '' ) {
				return false;
			}

			$decompressed = self::brotli_uncompress_font_data( $compressed, $expected_length );
			if ( $decompressed === false ) {
				return false;
			}

			$font_info           = [];
			$decompressed_offset = 0;

			foreach ( $tables as $table ) {
				$table_length         = (int) $table['length'];
				$table_data           = substr( $decompressed, $decompressed_offset, $table_length );
				$decompressed_offset += $table_length;

				if ( $table['tag'] === 'OS/2' ) {
					if ( strlen( $table_data ) >= 64 ) {
						$font_info['OS/2'] = [
							'usWeightClass' => unpack( 'n', substr( $table_data, 4, 2 ) )[1],
							'fsSelection'   => unpack( 'n', substr( $table_data, 62, 2 ) )[1],
						];
					}
				} elseif ( $table['tag'] === 'head' ) {
					if ( strlen( $table_data ) >= 46 ) {
						$font_info['head'] = [
							'macStyle' => unpack( 'n', substr( $table_data, 44, 2 ) )[1],
						];
					}
				} elseif ( $table['tag'] === 'fvar' ) {
					$font_info['fvar'] = self::parse_fvar_axes( $table_data );
				}
			}

			return empty( $font_info ) ? false : $font_info;

		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Move a custom font to trash
	 *
	 * @since 2.0
	 */
	public function move_font_to_trash() {
		Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		$post_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid font ID.', 'bricks' ) ] );
		}

		// Get font faces to clean up attachments
		$font_faces = get_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		// Move post to trash
		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			wp_send_json_error(
				[
					'message' => esc_html__( 'Failed to move font to trash.', 'bricks' ),
				]
			);
		}

		wp_send_json_success(
			[
				'message' => esc_html__( 'Font moved to trash successfully.', 'bricks' ),
			]
		);
	}

	/**
	 * Get trashed fonts
	 *
	 * @since 2.0
	 */
	public function get_trashed_fonts() {
		Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		$args = [
			'post_type'      => BRICKS_DB_CUSTOM_FONTS,
			'post_status'    => 'trash',
			'posts_per_page' => -1,
		];

		$trashed_fonts = get_posts( $args );
		$fonts_data    = [];

		foreach ( $trashed_fonts as $font ) {
			$font_faces = get_post_meta( $font->ID, BRICKS_DB_CUSTOM_FONT_FACES, true );

			$fonts_data[] = [
				'id'        => 'custom_font_' . $font->ID,
				'family'    => html_entity_decode( $font->post_title, ENT_QUOTES, 'UTF-8' ),
				'type'      => 'custom',
				'fontFaces' => $font_faces,
			];
		}

		wp_send_json_success( $fonts_data );
	}

	/**
	 * Restore font from trash
	 *
	 * @since 2.0
	 */
	public function restore_font() {
		Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		$post_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid font ID.', 'bricks' ) ] );
		}

		// Untrash the post first
		$result = wp_untrash_post( $post_id );

		if ( ! $result ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Failed to restore font.', 'bricks' ) ] );
		}

		// Set post status to publish
		$update_result = wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish'
			]
		);

		if ( is_wp_error( $update_result ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Failed to publish font.', 'bricks' ) ] );
		}

		// Get font data to return
		$font       = get_post( $post_id );
		$font_faces = get_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		wp_send_json_success(
			[
				'family'    => html_entity_decode( $font->post_title, ENT_QUOTES, 'UTF-8' ),
				'fontFaces' => $font_faces,
			]
		);
	}

	/**
	 * Delete font permanently
	 *
	 * @since 2.0
	 */
	public function delete_font_permanently() {
		Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You don\'t have permission to perform this action.', 'bricks' ) ] );
		}

		$post_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid font ID.', 'bricks' ) ] );
		}

		// Get font faces to clean up attachments
		$font_faces = get_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, true );

		// Delete post permanently
		$result = wp_delete_post( $post_id, true );

		if ( ! $result ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete font', 'bricks' ) ] );
		}

		// Clean up font face attachments
		if ( is_array( $font_faces ) ) {
			$attachment_ids = [];

			foreach ( $font_faces as $variant ) {
				if ( is_numeric( $variant ) ) {
					$attachment_ids[] = (int) $variant;
					continue;
				}

				if ( ! is_array( $variant ) ) {
					continue;
				}

				$subsets = isset( $variant[0] ) && is_array( $variant[0] ) ? $variant : [ $variant ];

				foreach ( $subsets as $subset ) {
					if ( is_numeric( $subset ) ) {
						$attachment_ids[] = (int) $subset;
						continue;
					}

					if ( ! is_array( $subset ) ) {
						continue;
					}

					foreach ( $subset as $font_face_key => $attachment_id ) {
						if ( in_array( $font_face_key, [ 'unicode-range', 'font-weight-range' ], true ) ) {
							continue;
						}

						if ( is_numeric( $attachment_id ) ) {
							$attachment_ids[] = (int) $attachment_id;
						}
					}
				}
			}

			foreach ( array_unique( $attachment_ids ) as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}

		wp_send_json_success( [ 'message' => esc_html__( 'Font deleted permanently', 'bricks' ) ] );
	}
}
