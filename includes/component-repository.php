<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Canonical component storage for local and remote component workflows.
 *
 * Keeps component definitions and their remote provenance on the same site in
 * multisite installations that share components from the main site.
 *
 * @since 2.4
 */
class Component_Repository {
	const LOCK_OPTION = 'bricks_remote_component_import_lock';

	/**
	 * Get all component definitions from the canonical store.
	 *
	 * @return array
	 */
	public static function get_all() {
		$components = self::get_option( BRICKS_DB_COMPONENTS, [] );

		return is_array( $components ) ? array_values( $components ) : [];
	}

	/**
	 * Replace component definitions in the canonical store.
	 *
	 * @param array $components Component definitions.
	 * @return bool
	 */
	public static function update_all( $components ) {
		$components = is_array( $components ) ? array_values( $components ) : [];
		$updated    = self::update_option( BRICKS_DB_COMPONENTS, $components );

		Database::$global_data['components'] = $components;

		return $updated;
	}

	/**
	 * Get remote component provenance records.
	 *
	 * @return array
	 */
	public static function get_provenance() {
		$provenance = self::get_option( BRICKS_DB_REMOTE_COMPONENT_PROVENANCE, [] );

		return is_array( $provenance ) ? $provenance : [];
	}

	/**
	 * Replace remote component provenance records.
	 *
	 * @param array $provenance Provenance records.
	 * @return bool
	 */
	public static function update_provenance( $provenance ) {
		return self::update_option(
			BRICKS_DB_REMOTE_COMPONENT_PROVENANCE,
			is_array( $provenance ) ? $provenance : [],
			false
		);
	}

	/**
	 * Get the current design-system version.
	 *
	 * @return int
	 */
	public static function get_design_system_version() {
		return (int) self::get_option( Abilities\Design::DESIGN_SYSTEM_VERSION_OPTION, 0 );
	}

	/**
	 * Bump the design-system version in the canonical component store.
	 *
	 * @return void
	 */
	public static function bump_design_system_version() {
		self::update_option(
			Abilities\Design::DESIGN_SYSTEM_VERSION_OPTION,
			self::get_design_system_version() + 1,
			false
		);
	}

	/**
	 * Acquire the short-lived remote component import lock.
	 *
	 * @return string|\WP_Error Lock token or error.
	 */
	public static function acquire_lock() {
		$token = wp_generate_uuid4();
		$now   = time();
		$lock  = self::get_option( self::LOCK_OPTION, [] );

		if ( is_array( $lock ) && ! empty( $lock['token'] ) && (int) ( $lock['expires'] ?? 0 ) > $now ) {
			return new \WP_Error(
				'remote_component_import_locked',
				esc_html__( 'Another remote component import is already running.', 'bricks' ),
				[ 'status' => 409 ]
			);
		}

		self::update_option(
			self::LOCK_OPTION,
			[
				'token'   => $token,
				'expires' => $now + MINUTE_IN_SECONDS,
			],
			false
		);

		$stored = self::get_option( self::LOCK_OPTION, [] );

		if ( ! is_array( $stored ) || ( $stored['token'] ?? '' ) !== $token ) {
			return new \WP_Error(
				'remote_component_import_locked',
				esc_html__( 'Unable to acquire the remote component import lock.', 'bricks' ),
				[ 'status' => 409 ]
			);
		}

		return $token;
	}

	/**
	 * Release a previously acquired import lock.
	 *
	 * @param string $token Lock token.
	 * @return void
	 */
	public static function release_lock( $token ) {
		$lock = self::get_option( self::LOCK_OPTION, [] );

		if ( is_array( $lock ) && ( $lock['token'] ?? '' ) === $token ) {
			self::delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Read an option from the site that owns components.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private static function get_option( $option, $default = false ) {
		if ( is_multisite() && BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS ) {
			return get_blog_option( get_main_site_id(), $option, $default );
		}

		return get_option( $option, $default );
	}

	/**
	 * Update an option on the site that owns components.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Option value.
	 * @param bool   $autoload Whether to autoload a newly added option.
	 * @return bool
	 */
	private static function update_option( $option, $value, $autoload = null ) {
		if ( is_multisite() && BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS ) {
			return update_blog_option( get_main_site_id(), $option, $value );
		}

		if ( $autoload === null ) {
			return update_option( $option, $value );
		}

		return update_option( $option, $value, $autoload );
	}

	/**
	 * Delete an option on the site that owns components.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function delete_option( $option ) {
		if ( is_multisite() && BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS ) {
			return delete_blog_option( get_main_site_id(), $option );
		}

		return delete_option( $option );
	}
}
