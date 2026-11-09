<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Handling Bricks' password protection logic
 *
 * @since 1.11.1
 */
class Password_Protection {
	/**
	 * Post ID whose protected title should be unprefixed during scoped Bricks rendering.
	 *
	 * @var int
	 */
	private static $protected_title_scope_post_id = 0;

	/**
	 * Scoped protected title filter callback.
	 *
	 * @var callable|null
	 */
	private static $protected_title_scope_filter = null;

	/**
	 * Nested Bricks render_data depth while the protected title scope is active.
	 *
	 * @var int
	 */
	private static $protected_title_scope_depth = 0;

	/**
	 * Whether the scoped Bricks render hooks have been registered.
	 *
	 * @var bool
	 */
	private static $protected_title_scope_hooks_added = false;

	/**
	 * Populate an empty password protection template with default content
	 *
	 * @param int $post_id Template post ID.
	 * @return bool Whether the template was populated.
	 */
	public static function populate_template( $post_id ) {
		// Generate random IDs for each element
		$section_id        = Helpers::generate_random_id( false );
		$container_id      = Helpers::generate_random_id( false );
		$heading_id        = Helpers::generate_random_id( false );
		$text_id           = Helpers::generate_random_id( false );
		$form_id           = Helpers::generate_random_id( false );
		$password_field_id = Helpers::generate_random_id( false );

		$default_content = [
			[
				'id'       => $section_id,
				'name'     => 'section',
				'parent'   => 0,
				'children' => [
					$container_id,
				],
				'settings' => [],
			],
			[
				'id'       => $container_id,
				'name'     => 'container',
				'parent'   => $section_id,
				'children' => [
					$heading_id,
					$text_id,
					$form_id,
				],
				'settings' => [],
			],
			[
				'id'       => $heading_id,
				'name'     => 'heading',
				'parent'   => $container_id,
				'settings' => [
					'tag'  => 'h1',
					'text' => esc_html__( 'Password protected', 'bricks' ),
				],
			],
			[
				'id'       => $text_id,
				'name'     => 'text-basic',
				'parent'   => $container_id,
				'settings' => [
					'text' => esc_html__( 'This content is password protected. To view it please enter your password below:', 'bricks' ),
				],
			],
			[
				'id'       => $form_id,
				'name'     => 'form',
				'parent'   => $container_id,
				'children' => [],
				'settings' => [
					'showLabels'        => true,
					'submitButtonStyle' => 'primary',
					'fields'            => [
						[
							'type'        => 'password',
							'label'       => esc_html__( 'Password', 'bricks' ),
							'placeholder' => esc_html__( 'Your password', 'bricks' ),
							'id'          => $password_field_id,
						],
					],
					'actions'           => [
						'unlock-password-protection',
					],
					'submitButtonText'  => esc_html__( 'Unlock', 'bricks' ),
					'successMessage'    => esc_html__( 'Password accepted. You can now access the protected content.', 'bricks' ),
					'_margin'           => [
						'top' => '30',
					],
				],
			],
		];

		return update_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, $default_content );
	}

	/**
	 * Determine if the password protection template should be rendered
	 *
	 * @param int $template_id The ID of the password protection template.
	 * @param int $post_id The content post ID used to evaluate WordPress password protection.
	 * @return bool
	 */
	public static function is_active( $template_id, $post_id = 0 ) {
		$post                     = $post_id ? get_post( $post_id ) : get_post();
		$is_wp_password_protected = $post instanceof \WP_Post && ! empty( $post->post_password );

		$settings                  = Helpers::get_template_settings( $template_id );
		$location                  = $settings['passwordProtectionSource'] ?? 'both';
		$password                  = $settings['passwordProtectionPassword'] ?? '';
		$is_within_date_range      = self::is_within_date_range( $settings );
		$bypass_for_logged_in_user = self::bypass_for_logged_in_user( $settings );
		$has_valid_password_cookie = self::has_valid_password_cookie( $template_id );

		// Return: No password set (non-WP password protection)
		if ( ! $password && ! $is_wp_password_protected ) {
			return false;
		}

		// Return: By-pass for logged-in user
		if ( $bypass_for_logged_in_user ) {
			// Skip WordPress password-protection if logged-in user is allowed to bypass
			if ( $is_wp_password_protected ) {
				add_filter( 'post_password_required', '__return_false' );
			}

			return false;
		}

		// Return: Render for WordPress only, but post is not password-protected
		if ( $location === 'wordpress' && ! $is_wp_password_protected ) {
			return false;
		}

		// Check: Password protection template is enabled for 'wordpress' or both ('bricks' & 'wordpress')
		if ( $location === 'wordpress' || $location === 'both' ) {
			// Skip WordPress password-protection if not within date range
			if ( $is_wp_password_protected && ! $is_within_date_range ) {
				add_filter( 'post_password_required', '__return_false' );
				return;
			}

			self::handle_wp_password_form( $template_id );

			// Return: WordPress password-protected and password has been entered successfully
			if ( $is_wp_password_protected && ! post_password_required( $post ) ) {
				return false;
			}

			if ( $is_wp_password_protected ) {
				self::enable_protected_title_scope( $post->ID );
			}
		}

		if ( $has_valid_password_cookie ) {
			return false;
		}

		if ( ! $is_within_date_range ) {
			return false;
		}

		// Allow modification of the render decision
		// See: https://academy.bricksbuilder.io/filter-bricks-password_protection-is_active
		return apply_filters( 'bricks/password_protection/is_active', true, $template_id, $settings );
	}

	/**
	 * Handle the WordPress password form by rendering the password protection template.
	 *
	 * @param int $template_id The ID of the password protection template.
	 */
	private static function handle_wp_password_form( $template_id ) {
		add_filter(
			'the_password_form',
			function( $output, $post ) use ( $template_id ) {
				// Get template elements data and render
				$elements = Database::get_data( $template_id, 'content' );
				if ( empty( $elements ) ) {
					return $output;
				}

				// Generate CSS for the password protection template
				\Bricks\Assets::generate_css_from_elements( $elements, 'content' );

				// Render the password protection template
				$output = Frontend::render_data( $elements );

				// Show error message if the password is incorrect
				if ( wp_get_raw_referer() === get_permalink() ) {
					$error_message = esc_html__( 'Incorrect password.', 'bricks' );
					foreach ( $elements as $element ) {
						if ( ! empty( $element['settings']['passwordProtectionErrorMessage'] ) ) {
							$error_message = esc_html( $element['settings']['passwordProtectionErrorMessage'] );
						}
					}

					$error_message = '<div class="message error"><div class="text">' . $error_message . '</div></div>';

					// Insert $error_message before </form> of $output string
					$output = str_replace( '</form>', $error_message . '</form>', $output );
				}

				return $output;
			},
			10,
			2
		);
	}

	/**
	 * Enable a request-scoped protected title override for Bricks render_data.
	 *
	 * @param int $post_id The protected post ID.
	 * @return void
	 */
	private static function enable_protected_title_scope( $post_id ) {
		self::$protected_title_scope_post_id = (int) $post_id;

		if ( self::$protected_title_scope_hooks_added ) {
			return;
		}

		add_action( 'bricks/frontend/before_render_data', [ __CLASS__, 'start_protected_title_scope' ], 10, 2 );
		add_action( 'bricks/frontend/after_render_data', [ __CLASS__, 'stop_protected_title_scope' ], 10, 2 );

		self::$protected_title_scope_hooks_added = true;
	}

	/**
	 * Start the scoped protected title override before Bricks renders data.
	 *
	 * @return void
	 */
	public static function start_protected_title_scope() {
		if ( ! self::$protected_title_scope_post_id ) {
			return;
		}

		if ( self::$protected_title_scope_depth === 0 ) {
			self::$protected_title_scope_filter = function( $title_format, $post ) {
				$filtered_post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;

				return $filtered_post_id && $filtered_post_id === self::$protected_title_scope_post_id ? '%s' : $title_format;
			};

			add_filter( 'protected_title_format', self::$protected_title_scope_filter, 10, 2 );
		}

		self::$protected_title_scope_depth++;
	}

	/**
	 * Stop the scoped protected title override after Bricks renders data.
	 *
	 * @return void
	 */
	public static function stop_protected_title_scope() {
		if ( self::$protected_title_scope_depth === 0 ) {
			return;
		}

		self::$protected_title_scope_depth--;

		if ( self::$protected_title_scope_depth === 0 && self::$protected_title_scope_filter ) {
			remove_filter( 'protected_title_format', self::$protected_title_scope_filter, 10 );
			self::$protected_title_scope_filter = null;
		}
	}

	/**
	 * Return the protected title format without the default "Protected:" prefix.
	 *
	 * WordPress passes this to sprintf() with the raw post title; '%s' keeps the title.
	 * An empty string would make sprintf() return empty and break dynamic data (e.g. {post_title}).
	 *
	 * @since 2.3.2
	 *
	 * @param string         $prepend Default translatable "Protected: %s" prefix.
	 * @param \WP_Post|false $post    Optional post object.
	 *
	 * @return string
	 */
	public static function get_wp_protected_title_format( $prepend, $post = false ) {
		return '%s';
	}

	/**
	 * Check if logged-in users can bypass password protection.
	 *
	 * @param array $settings The template settings.
	 * @return bool
	 */
	private static function bypass_for_logged_in_user( $settings ) {
		return ! empty( $settings['passwordProtectionBypassLoggedIn'] ) && is_user_logged_in();
	}

	/**
	 * Check if the user has a valid password cookie.
	 *
	 * @param int $template_id The ID of the password protection template.
	 * @return bool
	 */
	public static function get_template_password( $template_id ) {
		$settings = Helpers::get_template_settings( $template_id );

		return $settings['passwordProtectionPassword'] ?? '';
	}

	private static function has_valid_password_cookie( $template_id ) {
		if ( ! isset( $_COOKIE[ 'brx_pp_' . COOKIEHASH ] ) ) {
			return false;
		}

		$cookie   = $_COOKIE[ 'brx_pp_' . COOKIEHASH ];
		$password = self::get_template_password( $template_id );

		return wp_check_password( $password, $cookie );
	}

	/**
	 * Check if the current date is within the specified date range.
	 *
	 * @param array $settings The template settings.
	 * @return bool
	 */
	private static function is_within_date_range( $settings ) {
		if ( empty( $settings['passwordProtectionSchedule'] ) ) {
			return true;
		}

		$current_time = time();
		$wp_timezone  = wp_timezone();

		$start_date = ! empty( $settings['passwordProtectionStartDate'] )
			? wp_date( 'U', strtotime( $settings['passwordProtectionStartDate'] ), $wp_timezone )
			: null;

		$end_date = ! empty( $settings['passwordProtectionEndDate'] )
			? wp_date( 'U', strtotime( $settings['passwordProtectionEndDate'] ), $wp_timezone )
			: null;

		if ( $start_date && $current_time < $start_date ) {
			return false;
		}

		if ( $end_date && $current_time > $end_date ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate the submitted password against the template's password.
	 *
	 * @param int    $template_id
	 * @param string $submitted_password
	 * @return bool
	 */
	public static function validate_password( $template_id, $submitted_password ) {
		$template_password = self::get_template_password( $template_id );

		return $submitted_password === $template_password;
	}

	/**
	 * Set the password cookie for the given template.
	 *
	 * @param int    $template_id
	 * @param string $password
	 */
	public static function set_password_cookie( $template_id, $password ) {
		// See: https:// academy.bricksbuilder.io/filter-bricks-password_protection-cookie_expires
		$expire = apply_filters( 'bricks/password_protection/cookie_expires', time() + 10 * DAY_IN_SECONDS );
		$secure = is_ssl();

		setcookie(
			'brx_pp_' . COOKIEHASH,
			wp_hash_password( $password ),
			$expire,
			COOKIEPATH,
			COOKIE_DOMAIN,
			$secure,
			true // HttpOnly
		);
	}

	/**
	 * Verify that the form exists in the template and is set up for password protection.
	 *
	 * @param string $form_id
	 * @param int    $template_id
	 * @return bool
	 */
	public static function verify_form_in_template( $form_id, $template_id ) {
		$element_data = Helpers::get_element_data( $template_id, $form_id );

		if ( $element_data === false ) {
			return false;
		}

		$element = $element_data['element'];

		// Check if the element is a form and it's the unlock password protection form
		if ( $element['name'] === 'form' && isset( $element['settings']['actions'] ) ) {
			return in_array( 'unlock-password-protection', $element['settings']['actions'], true );
		}

		return false;
	}

	/**
	 * Check if a specific template part should be excluded.
	 *
	 * @param string $template_part The template part to check ('header', 'footer', or 'popup').
	 * @param int    $template_id The ID of the password protection template.
	 * @return bool Whether the template part should be excluded.
	 */
	public static function should_exclude_template_part( $template_part, $template_id ) {
		$settings = Helpers::get_template_settings( $template_id );

		switch ( $template_part ) {
			case 'header':
				return ! empty( $settings['passwordProtectionExcludeHeader'] );
			case 'footer':
				return ! empty( $settings['passwordProtectionExcludeFooter'] );
			case 'popup':
				return ! empty( $settings['passwordProtectionExcludePopups'] );
			default:
				return false;
		}
	}
}
