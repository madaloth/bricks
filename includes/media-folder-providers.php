<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Registry for media-folder providers.
 *
 * Integrations can register a provider on the
 * `bricks/media_folder_providers/register` action by calling
 * `Media_Folder_Providers::register( $provider )`. The
 * `bricks/media_folder_provider` filter may return a registered provider ID to
 * choose between multiple available integrations.
 *
 * @since 2.4
 */
class Media_Folder_Providers {
	/**
	 * Registered providers, keyed by provider ID.
	 *
	 * @var Media_Folder_Provider[]
	 */
	private static $providers = [];

	/**
	 * Whether default and third-party registration has run.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Register built-in and third-party media-folder providers once.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;
		self::register( new Integrations\HappyFiles\Media_Folder_Provider() );

		/**
		 * Register media-folder integrations for the Builder media browser.
		 *
		 * @param string $registry_class Registry class name.
		 * @since 2.4
		 */
		do_action( 'bricks/media_folder_providers/register', __CLASS__ );
	}

	/**
	 * Register or replace a provider by its stable ID.
	 *
	 * @param Media_Folder_Provider $provider Provider instance.
	 * @return bool
	 */
	public static function register( Media_Folder_Provider $provider ) {
		$provider_id = sanitize_key( $provider->get_id() );

		if ( ! $provider_id ) {
			return false;
		}

		self::$providers[ $provider_id ] = $provider;

		return true;
	}

	/**
	 * Return the selected provider available to the current user and post type.
	 *
	 * @param string $post_type WordPress post type.
	 * @return Media_Folder_Provider|null
	 */
	public static function get_active( $post_type = 'attachment' ) {
		self::bootstrap();
		$post_type = sanitize_key( $post_type );

		if ( ! $post_type ) {
			$post_type = 'attachment';
		}
		$available = [];

		foreach ( self::$providers as $provider_id => $provider ) {
			if ( $provider instanceof Media_Folder_Post_Type_Provider ) {
				$provider = $provider->for_post_type( $post_type );
			} elseif ( $post_type !== 'attachment' ) {
				continue;
			}

			if ( $provider instanceof Media_Folder_Provider && $provider->is_available() ) {
				$available[ $provider_id ] = $provider;
			}
		}

		if ( empty( $available ) ) {
			return null;
		}

		/**
		 * Select the active media-folder provider by its registered ID.
		 *
		 * Returning an empty or unavailable ID falls back to the first available
		 * provider in registration order.
		 *
		 * @param string                  $provider_id Requested provider ID.
		 * @param Media_Folder_Provider[] $available   Available providers.
		 * @param string                  $post_type   WordPress post type.
		 * @since 2.4
		 */
		$provider_id = sanitize_key( apply_filters( 'bricks/media_folder_provider', '', $available, $post_type ) );

		if ( $provider_id && isset( $available[ $provider_id ] ) ) {
			return $available[ $provider_id ];
		}

		return reset( $available );
	}

	/**
	 * Return the active provider context consumed by the Browser post type.
	 *
	 * @param string $post_type WordPress post type.
	 * @return array
	 */
	public static function get_context( $post_type = 'attachment' ) {
		$provider = self::get_active( $post_type );

		if ( ! $provider ) {
			return [
				'available' => false,
				'id'        => '',
				'label'     => '',
				'canManage' => false,
				'canAssign' => false,
				'folders'   => [],
			];
		}

		return [
			'available'   => true,
			'id'          => sanitize_key( $provider->get_id() ),
			'label'       => sanitize_text_field( $provider->get_label() ),
			'canManage'   => (bool) $provider->can_manage(),
			'canAssign'   => (bool) $provider->can_assign(),
			'folders'     => $provider->get_folders(),
			'scopeCounts' => is_callable( [ $provider, 'get_scope_counts' ] ) ? $provider->get_scope_counts() : null,
		];
	}
}
