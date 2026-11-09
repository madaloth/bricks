<?php
namespace Bricks\Integrations\Form\Actions;

use Bricks\Database;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Registration extends Base {
	/**
	 * Capabilities a public registration form must never grant
	 *
	 * Builder users who can't manage users may still add a Registration form, so the
	 * form's role setting alone can't be trusted to pick a privileged role.
	 *
	 * #123pt1nyhyg
	 *
	 * @since 2.4.3
	 */
	const PRIVILEGED_CAPABILITIES = [
		'manage_options',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'create_sites',
		'delete_sites',
		'upgrade_network',
		'setup_network',
		'create_users',
		'add_users',
		'edit_users',
		'promote_users',
		'remove_users',
		'delete_users',
		'list_users',
		'unfiltered_html',
		'unfiltered_upload',
		'edit_others_posts',
		'edit_others_pages',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'activate_plugins',
		'edit_plugins',
		'install_themes',
		'update_themes',
		'delete_themes',
		'switch_themes',
		'edit_themes',
		'edit_theme_options',
		'edit_files',
		'update_core',
		'manage_woocommerce',
		'import',
		'export',
	];

	/**
	 * Whether a registration form may assign this role
	 *
	 * The site's default role is allowed if it exists (except administrator and super_admin). Other roles must not
	 * grant administrative, user management, unfiltered HTML or Bricks Builder capabilities (incl. custom ones).
	 *
	 * #123pt1nyhyg
	 *
	 * @since 2.4.3
	 *
	 * @param string $role_name Role slug.
	 * @return bool
	 */
	public static function is_allowed_role( $role_name ) {
		if ( in_array( $role_name, [ 'administrator', 'super_admin' ], true ) ) {
			return false;
		}

		$role = get_role( $role_name );

		if ( ! $role ) {
			return false;
		}

		if ( $role_name === get_option( 'default_role' ) ) {
			return true;
		}

		$is_privileged = false;

		// Custom Builder access capabilities can have any ID (e.g. created via abilities or import), not just a 'bricks_' prefix
		$custom_builder_capabilities = get_option( BRICKS_DB_CAPABILITIES_PERMISSIONS, [] );
		$custom_builder_capabilities = is_array( $custom_builder_capabilities ) ? array_diff( array_keys( $custom_builder_capabilities ), [ \Bricks\Capabilities::NO_ACCESS ] ) : [];

		foreach ( $role->capabilities as $capability => $granted ) {
			if ( ! $granted ) {
				continue;
			}

			// Bricks capabilities ending in '_off' or 'bricks_no_access' revoke access
			$is_bricks_capability = strpos( $capability, 'bricks_' ) === 0 && substr( $capability, -4 ) !== '_off' && $capability !== \Bricks\Capabilities::NO_ACCESS;

			// Custom post types can grant access to other users' content through their own capability names.
			$is_edit_others_capability = strpos( $capability, 'edit_others_' ) === 0;

			if ( $is_bricks_capability || $is_edit_others_capability || in_array( $capability, $custom_builder_capabilities, true ) || in_array( $capability, self::PRIVILEGED_CAPABILITIES, true ) ) {
				$is_privileged = true;
				break;
			}
		}

		/**
		 * Filter whether a role is privileged and therefore can't be assigned by a registration form
		 *
		 * @since 2.4.3
		 *
		 * @param bool   $is_privileged Whether the role grants privileged capabilities.
		 * @param string $role_name     Role slug.
		 */
		return ! apply_filters( 'bricks/form/registration/role_is_privileged', $is_privileged, $role_name );
	}

	/**
	 * User registration
	 *
	 * @since 1.0
	 */
	public function run( $form ) {
		$form_settings = $form->get_settings();
		$form_fields   = $form->get_fields();

		$user_email           = isset( $form_settings['registrationEmail'] ) && isset( $form_fields[ "form-field-{$form_settings['registrationEmail']}" ] ) ? $form_fields[ "form-field-{$form_settings['registrationEmail']}" ] : false;
		$user_login           = isset( $form_settings['registrationUserName'] ) && isset( $form_fields[ "form-field-{$form_settings['registrationUserName']}" ] ) ? $form_fields[ "form-field-{$form_settings['registrationUserName']}" ] : false;
		$first_name           = isset( $form_settings['registrationFirstName'] ) && isset( $form_fields[ "form-field-{$form_settings['registrationFirstName']}" ] ) ? $form_fields[ "form-field-{$form_settings['registrationFirstName']}" ] : false;
		$last_name            = isset( $form_settings['registrationLastName'] ) && isset( $form_fields[ "form-field-{$form_settings['registrationLastName']}" ] ) ? $form_fields[ "form-field-{$form_settings['registrationLastName']}" ] : false;
		$user_pass            = isset( $form_settings['registrationPassword'] ) && isset( $form_fields[ "form-field-{$form_settings['registrationPassword']}" ] ) ? $form_fields[ "form-field-{$form_settings['registrationPassword']}" ] : false;
		$user_pass_min_length = isset( $form_settings['registrationPasswordMinLength'] ) ? intval( $form_settings['registrationPasswordMinLength'] ) : false;
		$user_role            = isset( $form_settings['registrationRole'] ) ? $form_settings['registrationRole'] : get_option( 'default_role' );
		$wp_notification      = isset( $form_settings['registrationWPNotification'] ) && $form_settings['registrationWPNotification']; // (@since 1.12.2)

		// Fall back to the default role if the role grants privileged capabilities (for security reasons)
		if ( ! self::is_allowed_role( $user_role ) ) {
			$user_role = get_option( 'default_role' );
		}

		// Return: The default role can be administrator too. A registration form never creates administrators (#123pt1nyhyg)
		if ( ! self::is_allowed_role( $user_role ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => esc_html__( 'An error occurred, please try again later.', 'bricks' ),
				]
			);

			return;
		}

		// No user_login: Auto generate username from email address
		if ( ! $user_login && is_email( $user_email ) ) {
			// Use part before @ as user_login
			$user_login = explode( '@', $user_email )[0];

			// Sanitize the username to remove invalid characters (@since 2.0)
			$user_login = sanitize_user( $user_login, true );

			// user_login taken: Append number to user_login
			if ( username_exists( $user_login ) ) {
				$i = 1;
				while ( username_exists( $user_login . $i ) ) {
					$i++;
				}
				$user_login = $user_login . $i;
			}
		}

		// No password set: Auto-generate password (@since 1.9.2)
		if ( ! $user_pass ) {
			$user_pass = wp_generate_password( 12, true, false );
		}

		$new_user_data = [
			'user_login'           => $user_login,
			'user_email'           => $user_email,
			'first_name'           => $first_name,
			'last_name'            => $last_name,
			'user_pass'            => $user_pass,
			'user_pass_min_length' => $user_pass_min_length,
			'role'                 => $user_role,
		];

		// Form validation
		$registration_errors = self::validate_registration( $new_user_data );

		if ( is_wp_error( $registration_errors ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => $registration_errors,
				]
			);

			return;
		}

		// Remove 'user_pass_min_length' from new user data
		unset( $new_user_data['user_pass_min_length'] );

		// 'wp_insert_user' returns user ID on success and WP_Error object on failure
		$user_id = wp_insert_user( $new_user_data );

		// Return: User registration error
		if ( is_wp_error( $user_id ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => $user_id->get_error_message(),
				]
			);

			return;
		}

		/**
		 * Success, trigger 'register_new_user' action hook (so notifications can be sent)
		 *
		 * @since 1.12.2
		 */
		if ( $wp_notification ) {
			do_action( 'register_new_user', $user_id );
		}

		// Auto log in user
		// Only if user activation is disabled (@since 2.1)
		if ( isset( $form_settings['registrationAutoLogin'] ) && ! Database::get_setting( 'userActivationEnabled' ) ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, false, is_ssl() );
		}

		$form->set_result(
			[
				'action' => $this->name,
				'type'   => 'success',
			]
		);
	}

	/**
	 * Validate user registration
	 *
	 * @param array $user_data Array with user data (user_login, user_email, user_pass etc.).
	 * @return array|null Returns WP_Error object if validation errors found or null if validation passed.
	 *
	 * @since 1.0
	 */
	public static function validate_registration( $user_data ) {
		$errors = [];

		// Validate: user_login
		if ( ! $user_data['user_login'] ) {
			$errors['user_login'] = esc_html__( 'Username required.', 'bricks' );
		} elseif ( username_exists( $user_data['user_login'] ) ) {
			$errors['user_login'] = esc_html__( 'Username already exists.', 'bricks' );
		} elseif ( ! validate_username( $user_data['user_login'] ) ) {
			$errors['user_login'] = esc_html__( 'Username is not valid.', 'bricks' );
		}

		// Validate: user_email
		if ( ! $user_data['user_email'] ) {
			$errors['user_email'] = esc_html__( 'Email address required.', 'bricks' );
		} elseif ( ! is_email( $user_data['user_email'] ) ) {
			$errors['user_email'] = esc_html__( 'Email address is not valid.', 'bricks' );
		} elseif ( email_exists( $user_data['user_email'] ) ) {
			$errors['user_email'] = esc_html__( 'Email address already exists.', 'bricks' );
		}

		// Validate: user_pass (password has been auto-generated, if not provided by user)
		if ( ! $user_data['user_pass'] ) {
			$errors['user_pass'] = esc_html__( 'Password required.', 'bricks' );
		}

		// WordPress rejects backslashes in some password-setting flows, so keep Bricks auth forms consistent (@since 2.4)
		elseif ( is_string( $user_data['user_pass'] ) && strpos( $user_data['user_pass'], '\\' ) !== false ) {
			$errors['user_pass'] = sprintf(
				/* translators: %s: The '\' character. */
				esc_html__( 'Passwords may not contain the character "%s".', 'bricks' ),
				'\\'
			);
		}

		// Validate: user_pass_min_length
		elseif ( $user_data['user_pass_min_length'] && ( strlen( $user_data['user_pass'] ) < intval( $user_data['user_pass_min_length'] ) ) ) {
			// translators: %s: Minimum password length.
			$errors['user_pass'] = sprintf( esc_html__( 'Please enter a password of at least %s characters.', 'bricks' ), $user_data['user_pass_min_length'] );
		}

		if ( ! empty( $errors ) ) {
			$wp_error = new \WP_Error();

			foreach ( $errors as $code => $message ) {
				$wp_error->add( $code, $message );
			}

			return $wp_error;
		}
	}
}
