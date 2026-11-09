<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Resolve Media Browser health findings with attachment-safe actions.
 *
 * Transforming an original is staged before attachment metadata changes. The
 * previous original remains available as a backup, while copy mode creates a
 * separate attachment and leaves the reported attachment untouched.
 *
 * @since 2.4
 */
class Media_Browser_Health_Actions {
	const META_BACKUPS          = '_bricks_media_health_backups';
	const MUTATION_LOCK_PREFIX  = 'bricks-media-health-';
	const MAX_DIMENSION         = 12000;
	const DEFAULT_QUALITY       = 82;
	const DEFAULT_MAX_EDGE      = 1920;
	const MIN_QUALITY           = 40;
	const MAX_QUALITY           = 100;
	const MAX_RELINK_LENGTH     = 1024;
	const MAX_OWNERSHIP_MATCHES = 500;

	/**
	 * Locks held by this request, including reentrant acquisition depth.
	 *
	 * @var array
	 */
	private static $mutation_locks = [];

	/**
	 * Return action context only when a remediation is valid for the attachment.
	 *
	 * Usage discovery is intentionally deferred until a dialog opens so normal
	 * inspector loads do not scan Bricks content for attachment references.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $action        Remediation action.
	 * @return array|\WP_Error
	 */
	public static function prepare( $attachment_id, $action ) {
		$validated = self::validate_request( $attachment_id, $action );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$attachment_id = $validated['attachmentId'];
		$action        = $validated['action'];
		$file          = get_attached_file( $attachment_id );
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$metadata      = is_array( $metadata ) ? $metadata : [];
		$mime          = (string) get_post_mime_type( $attachment_id );
		$usage         = in_array( $action, [ 'optimize_image', 'convert_image', 'replace_file', 'relink_file' ], true )
			? Media_Browser_Bulk::get_attachment_usage_summary( $attachment_id )
			: [
				'referenceCount' => 0,
				'usedOnSources'  => []
			];
		$formats       = [];

		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		if ( $action === 'convert_image' ) {
			$formats = self::get_supported_output_formats();

			if ( ! $formats ) {
				return new \WP_Error( 'media_health_action_format_unavailable', __( 'This server cannot create a supported web image format.', 'bricks' ) );
			}
		}

		return [
			'action'           => $action,
			'attachmentId'     => $attachment_id,
			'filename'         => wp_basename( (string) $file ),
			'mime'             => $mime,
			'width'            => absint( $metadata['width'] ?? 0 ),
			'height'           => absint( $metadata['height'] ?? 0 ),
			'filesize'         => $file && is_file( $file ) ? max( 0, (int) filesize( $file ) ) : 0,
			'usageCount'       => absint( $usage['referenceCount'] ?? 0 ),
			'usageSources'     => is_array( $usage['usedOnSources'] ?? null ) ? $usage['usedOnSources'] : [],
			'formats'          => $formats,
			'defaultFormat'    => isset( $formats['image/webp'] ) ? 'image/webp' : (string) array_key_first( $formats ),
			'defaultQuality'   => self::DEFAULT_QUALITY,
			'defaultMaxEdge'   => min( self::DEFAULT_MAX_EDGE, max( absint( $metadata['width'] ?? 0 ), absint( $metadata['height'] ?? 0 ) ) ),
			'currentBackupUrl' => self::get_latest_backup_url( $attachment_id ),
		];
	}

	/**
	 * Run one health remediation action.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $action        Remediation action.
	 * @param array      $options       Sanitized action options.
	 * @param array|null $uploaded_file Optional replacement upload from $_FILES.
	 * @return array|\WP_Error
	 */
	public static function process( $attachment_id, $action, $options = [], $uploaded_file = null ) {
		$validated = self::validate_request( $attachment_id, $action );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$attachment_id = $validated['attachmentId'];
		$action        = $validated['action'];
		$options       = is_array( $options ) ? $options : [];
		$lock          = self::acquire_mutation_lock( $attachment_id );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$validated = self::validate_request( $attachment_id, $action );

			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			if ( $action === 'rescan' ) {
				return self::success_result(
					$attachment_id,
					Media_Browser_Health::audit_attachment( $attachment_id, true ),
					__( 'Health check complete', 'bricks' )
				);
			}

			if ( $action === 'generate_missing_sizes' ) {
				return self::generate_missing_sizes( $attachment_id );
			}

			if ( $action === 'regenerate_all_sizes' ) {
				if ( empty( $options['confirmed'] ) ) {
					return new \WP_Error( 'media_health_action_confirmation_required', __( 'Confirm regeneration before continuing.', 'bricks' ) );
				}

				return self::regenerate_all_sizes( $attachment_id );
			}

			if ( $action === 'optimize_image' ) {
				return self::transform_image( $attachment_id, $options, '' );
			}

			if ( $action === 'convert_image' ) {
				$target_mime = isset( $options['targetMime'] ) ? sanitize_mime_type( $options['targetMime'] ) : '';

				return self::transform_image( $attachment_id, $options, $target_mime );
			}

			if ( $action === 'replace_file' ) {
				return self::replace_file( $attachment_id, $options, $uploaded_file );
			}

			return self::relink_file( $attachment_id, $options );
		} finally {
			self::release_mutation_lock( $attachment_id, $lock );
		}
	}

	/**
	 * Calculate proportional dimensions for a maximum long edge.
	 *
	 * Public for deterministic unit coverage of the transform boundary.
	 *
	 * @param int $width         Current width.
	 * @param int $height        Current height.
	 * @param int $max_dimension Maximum long edge, or zero to retain dimensions.
	 * @return array
	 */
	public static function calculate_dimensions( $width, $height, $max_dimension ) {
		$width         = absint( $width );
		$height        = absint( $height );
		$max_dimension = min( self::MAX_DIMENSION, absint( $max_dimension ) );

		if ( ! $width || ! $height || ! $max_dimension || max( $width, $height ) <= $max_dimension ) {
			return [
				'width'  => $width,
				'height' => $height,
				'resize' => false
			];
		}

		$scale = $max_dimension / max( $width, $height );

		return [
			'width'  => max( 1, (int) round( $width * $scale ) ),
			'height' => max( 1, (int) round( $height * $scale ) ),
			'resize' => true,
		];
	}

	/**
	 * Validate capability, attachment state, action, and active finding.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $action        Remediation action.
	 * @return array|\WP_Error
	 */
	private static function validate_request( $attachment_id, $action ) {
		$attachment_id = absint( $attachment_id );
		$action        = sanitize_key( $action );
		$actions       = [
			'rescan',
			'generate_missing_sizes',
			'regenerate_all_sizes',
			'optimize_image',
			'convert_image',
			'replace_file',
			'relink_file',
		];

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			return new \WP_Error( 'media_health_attachment_not_found', __( 'Not found', 'bricks' ) );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) || ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'media_health_attachment_not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		if ( ! in_array( $action, $actions, true ) ) {
			return new \WP_Error( 'media_health_action_invalid', __( 'Invalid parameter', 'bricks' ) );
		}

		if ( $action === 'rescan' ) {
			return [
				'attachmentId' => $attachment_id,
				'action'       => $action
			];
		}

		$result       = Media_Browser_Health::get_result( $attachment_id, true );
		$active_codes = array_column( $result['issues'] ?? [], 'code' );
		$requirements = [
			'generate_missing_sizes' => [ 'missing_derivatives' ],
			'regenerate_all_sizes'   => [ 'missing_derivatives' ],
			'optimize_image'         => [ 'oversized' ],
			'convert_image'          => [ 'obsolete_format' ],
			'replace_file'           => [ 'broken', 'oversized', 'obsolete_format' ],
			'relink_file'            => [ 'broken' ],
		];

		if ( ! array_intersect( $requirements[ $action ], $active_codes ) ) {
			return new \WP_Error( 'media_health_action_issue_missing', __( 'This health issue is no longer active.', 'bricks' ) );
		}

		if ( in_array( $action, [ 'generate_missing_sizes', 'regenerate_all_sizes', 'optimize_image', 'convert_image' ], true ) && ! wp_attachment_is_image( $attachment_id ) ) {
			return new \WP_Error( 'media_health_action_image_required', __( 'This action is only available for images.', 'bricks' ) );
		}

		return [
			'attachmentId' => $attachment_id,
			'action'       => $action
		];
	}

	/**
	 * Acquire the attachment mutation lock atomically.
	 *
	 * A MySQL advisory lock is atomic across requests and is automatically
	 * released if its database connection closes. Locks held by this request are
	 * tracked locally so nested health services remain reentrant.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|\WP_Error
	 */
	private static function acquire_mutation_lock( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		$lock_name     = self::get_mutation_lock_name( $attachment_id );

		if ( isset( self::$mutation_locks[ $lock_name ] ) ) {
			self::$mutation_locks[ $lock_name ]['depth']++;

			return self::$mutation_locks[ $lock_name ]['token'];
		}

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return new \WP_Error( 'media_health_action_lock_failed', __( 'This media item could not be locked safely. No files were changed.', 'bricks' ) );
		}

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory locks are the atomic cross-request primitive; the lock name is prepared.

		if ( (string) $acquired !== '1' ) {
			return $acquired === null
				? new \WP_Error( 'media_health_action_lock_failed', __( 'This media item could not be locked safely. No files were changed.', 'bricks' ) )
				: new \WP_Error( 'media_health_action_locked', __( 'This media item is already being changed. Wait for the current action to finish and try again.', 'bricks' ) );
		}

		self::$mutation_locks[ $lock_name ] = [
			'token' => $lock_name,
			'depth' => 1,
		];

		return $lock_name;
	}

	/**
	 * Release one reentrant level of an attachment mutation lock.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token         Lock token returned by acquisition.
	 * @return bool
	 */
	private static function release_mutation_lock( $attachment_id, $token ) {
		global $wpdb;

		$lock_name = (string) $token;
		$held      = self::$mutation_locks[ $lock_name ] ?? null;
		unset( $attachment_id );

		if ( ! is_array( $held ) || ! hash_equals( (string) $held['token'], (string) $token ) ) {
			return false;
		}

		if ( $held['depth'] > 1 ) {
			self::$mutation_locks[ $lock_name ]['depth']--;

			return true;
		}

		unset( self::$mutation_locks[ $lock_name ] );

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $held['token'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Releases only the exact advisory lock held by this request.

		return (string) $released === '1';
	}

	/**
	 * Return a database-scoped lock name within MySQL's 64-character limit.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function get_mutation_lock_name( $attachment_id ) {
		$database = defined( 'DB_NAME' ) ? DB_NAME : '';
		$blog_id  = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$scope    = $database . ':' . absint( $blog_id ) . ':' . absint( $attachment_id );

		return self::MUTATION_LOCK_PREFIX . substr( hash( 'sha256', $scope ), 0, 40 );
	}

	/**
	 * Generate only currently missing registered image sizes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error
	 */
	private static function generate_missing_sizes( $attachment_id ) {
		self::load_image_dependencies();

		if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
			return new \WP_Error( 'media_health_action_unavailable', __( 'WordPress cannot generate missing image sizes.', 'bricks' ) );
		}

		$health        = Media_Browser_Health::audit_attachment( $attachment_id, true );
		$missing_sizes = [];

		foreach ( $health['issues'] ?? [] as $issue ) {
			if ( ( $issue['code'] ?? '' ) === 'missing_derivatives' ) {
				$missing_sizes = is_array( $issue['data']['sizes'] ?? null ) ? $issue['data']['sizes'] : [];
				break;
			}
		}

		$state             = self::get_attachment_state( $attachment_id );
		$original_metadata = $state['metadata'];
		$metadata          = $original_metadata;
		$file              = $state['source'];

		if ( $file === '' || self::get_upload_relative_path( $file ) === '' || ! is_file( $file ) || ! is_readable( $file ) ) {
			return new \WP_Error( 'media_health_action_file_unavailable', __( 'The original media file is unavailable or outside the uploads directory.', 'bricks' ) );
		}

		$transaction = self::snapshot_attachment_files( $file, $original_metadata, [], $attachment_id );

		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}

		// WordPress only regenerates sizes absent from metadata. Remove the stale
		// entries whose files the audit found missing before invoking its updater.
		$registered_sizes = function_exists( 'wp_get_registered_image_subsizes' )
			? (array) wp_get_registered_image_subsizes()
			: [];

		foreach ( $missing_sizes as $size_name ) {
			if ( is_string( $size_name ) && array_key_exists( $size_name, $registered_sizes ) ) {
				unset( $metadata['sizes'][ $size_name ] );
			}
		}

		if ( ! self::persist_attachment_metadata( $attachment_id, $metadata ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, [], $transaction ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_metadata_failed', __( 'Attachment metadata could not be prepared, so no files were changed.', 'bricks' ) );
		}

		$metadata = wp_update_image_subsizes( $attachment_id );

		if ( is_wp_error( $metadata ) || ! is_array( $metadata ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, [], $transaction ) ) {
				return self::rollback_failure_error();
			}

			return is_wp_error( $metadata )
				? $metadata
				: new \WP_Error( 'media_health_action_generation_failed', __( 'Missing image sizes could not be generated.', 'bricks' ) );
		}

		if ( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $state['file'] ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, [], $transaction, self::get_attachment_files( $file, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_url_change_blocked', __( 'WordPress attempted to change the attachment URL while generating sizes, so the original files were restored.', 'bricks' ) );
		}

		if ( ! self::persist_attachment_metadata( $attachment_id, $metadata ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, [], $transaction, self::get_attachment_files( $file, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_metadata_failed', __( 'The generated image sizes could not be saved. The original files were restored.', 'bricks' ) );
		}

		self::discard_file_snapshot( $transaction );

		return self::success_result(
			$attachment_id,
			Media_Browser_Health::audit_attachment( $attachment_id, true ),
			__( 'Missing image sizes generated', 'bricks' )
		);
	}

	/**
	 * Regenerate attachment metadata and every currently registered image size.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error
	 */
	private static function regenerate_all_sizes( $attachment_id ) {
		$metadata = self::regenerate_attachment_sizes( $attachment_id );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		return self::success_result(
			$attachment_id,
			Media_Browser_Health::audit_attachment( $attachment_id, true ),
			__( 'Image sizes regenerated', 'bricks' )
		);
	}

	/**
	 * Safely regenerate attachment metadata and registered image sizes.
	 *
	 * Existing files are snapshotted because WordPress can overwrite derivatives
	 * before the new metadata is persisted. Legacy derivatives are intentionally
	 * retained: regeneration repairs current sizes and is not a cleanup action.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error
	 */
	public static function regenerate_attachment_sizes( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			return new \WP_Error( 'media_health_attachment_not_found', __( 'The attachment could not be found.', 'bricks' ) );
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'media_health_attachment_not_allowed', __( 'You are not allowed to edit this attachment.', 'bricks' ) );
		}

		$lock = self::acquire_mutation_lock( $attachment_id );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return self::regenerate_attachment_sizes_unlocked( $attachment_id );
		} finally {
			self::release_mutation_lock( $attachment_id, $lock );
		}
	}

	/**
	 * Regenerate attachment sizes while the caller holds the mutation lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error
	 */
	private static function regenerate_attachment_sizes_unlocked( $attachment_id ) {
		$state        = self::get_attachment_state( $attachment_id );
		$file         = $state['source'];
		$old_metadata = $state['metadata'];

		if ( ! $file || self::get_upload_relative_path( $file ) === '' || ! is_file( $file ) || ! is_readable( $file ) ) {
			return new \WP_Error( 'media_health_action_file_unavailable', __( 'The original media file is unavailable or outside the uploads directory.', 'bricks' ) );
		}

		$transaction = self::snapshot_attachment_files( $file, $old_metadata, [], $attachment_id );

		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}

		self::load_image_dependencies();
		$metadata = self::generate_attachment_metadata_preserving_path( $attachment_id, $file );

		if ( ! is_array( $metadata ) || ! $metadata ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, [], $transaction ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_generation_failed', __( 'Image sizes could not be regenerated. The original files were restored.', 'bricks' ) );
		}

		if ( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $state['file'] ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, [], $transaction, self::get_attachment_files( $file, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_url_change_blocked', __( 'WordPress attempted to change the attachment URL during regeneration, so the original files were restored.', 'bricks' ) );
		}

		if ( ! empty( $old_metadata['original_image'] ) ) {
			$metadata['original_image'] = $old_metadata['original_image'];
		}

		if ( ! self::persist_attachment_metadata( $attachment_id, $metadata ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, [], $transaction, self::get_attachment_files( $file, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_metadata_failed', __( 'The regenerated image metadata could not be saved. The original files were restored.', 'bricks' ) );
		}

		self::discard_file_snapshot( $transaction );

		return $metadata;
	}

	/**
	 * Resize/recompress an image or convert it to another supported format.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $options       Transform options.
	 * @param string $target_mime   Empty to retain the current format.
	 * @return array|\WP_Error
	 */
	private static function transform_image( $attachment_id, $options, $target_mime ) {
		$source = get_attached_file( $attachment_id );

		if ( ! $source || self::get_upload_relative_path( $source ) === '' || ! is_file( $source ) || ! is_readable( $source ) ) {
			return new \WP_Error( 'media_health_action_file_unavailable', __( 'The original media file is unavailable or outside the uploads directory.', 'bricks' ) );
		}

		$mode          = isset( $options['mode'] ) && sanitize_key( $options['mode'] ) === 'replace' ? 'replace' : 'copy';
		$quality       = isset( $options['quality'] ) ? absint( $options['quality'] ) : self::DEFAULT_QUALITY;
		$quality       = min( self::MAX_QUALITY, max( self::MIN_QUALITY, $quality ) );
		$max_dimension = isset( $options['maxDimension'] ) ? min( self::MAX_DIMENSION, absint( $options['maxDimension'] ) ) : 0;
		$current_mime  = (string) get_post_mime_type( $attachment_id );
		$target_mime   = $target_mime !== '' ? $target_mime : $current_mime;
		$formats       = self::get_supported_output_formats();

		if ( $target_mime !== $current_mime && ! isset( $formats[ $target_mime ] ) ) {
			return new \WP_Error( 'media_health_action_format_unavailable', __( 'The selected image format is not supported by this server.', 'bricks' ) );
		}

		if ( $mode === 'replace' && empty( $options['confirmed'] ) ) {
			return new \WP_Error( 'media_health_action_confirmation_required', __( 'Confirm replacement before continuing.', 'bricks' ) );
		}

		self::load_image_dependencies();
		$editor = wp_get_image_editor( $source );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$size       = $editor->get_size();
		$dimensions = self::calculate_dimensions( $size['width'] ?? 0, $size['height'] ?? 0, $max_dimension );

		if ( $dimensions['resize'] ) {
			$resized = $editor->resize( $dimensions['width'], $dimensions['height'], false );

			if ( is_wp_error( $resized ) ) {
				return $resized;
			}
		}

		$editor->set_quality( $quality );
		$extension   = self::mime_extension( $target_mime );
		$destination = self::unique_destination( $source, $mode === 'replace' && $target_mime === $current_mime ? 'stage' : 'optimized', $extension );

		if ( self::get_upload_relative_path( $destination ) === '' ) {
			return new \WP_Error( 'media_health_action_path_unsafe', __( 'The optimized file could not be written safely inside the uploads directory.', 'bricks' ) );
		}

		$saved = $editor->save( $destination, $target_mime );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || self::get_upload_relative_path( $saved['path'] ) === '' || ! is_file( $saved['path'] ) || filesize( $saved['path'] ) <= 0 ) {
			return is_wp_error( $saved )
				? $saved
				: new \WP_Error( 'media_health_action_transform_failed', __( 'The optimized image could not be created.', 'bricks' ) );
		}

		if ( $mode === 'copy' ) {
			return self::create_attachment_copy( $attachment_id, $saved['path'], $target_mime );
		}

		return self::replace_with_transformed_file( $attachment_id, $source, $saved['path'], $current_mime, $target_mime );
	}

	/**
	 * Create a new attachment for a transformed image.
	 *
	 * @param int    $attachment_id Source attachment ID.
	 * @param string $file          New image file.
	 * @param string $mime          New image MIME type.
	 * @return array|\WP_Error
	 */
	private static function create_attachment_copy( $attachment_id, $file, $mime ) {
		$source = get_post( $attachment_id );

		if ( ! $source instanceof \WP_Post ) {
			self::delete_tracked_file( $file );

			return new \WP_Error( 'media_health_attachment_not_found', __( 'Not found', 'bricks' ) );
		}

		if ( self::get_upload_relative_path( $file ) === '' ) {
			return new \WP_Error( 'media_health_action_path_unsafe', __( 'The optimized file is not safely contained in the uploads directory.', 'bricks' ) );
		}

		$new_id = wp_insert_attachment(
			[
				'post_mime_type' => $mime,
				'post_title'     => sprintf(
					/* translators: %s: Original attachment title. */
					__( '%s (optimized)', 'bricks' ),
					$source->post_title
				),
				'post_content'   => $source->post_content,
				'post_excerpt'   => $source->post_excerpt,
				'post_status'    => 'inherit',
				'post_parent'    => (int) $source->post_parent,
			],
			$file,
			(int) $source->post_parent,
			true
		);

		if ( is_wp_error( $new_id ) ) {
			self::delete_tracked_file( $file );

			return $new_id;
		}

		self::load_image_dependencies();
		$metadata = wp_generate_attachment_metadata( $new_id, $file );

		if ( ! is_array( $metadata ) || ! $metadata ) {
			if ( ! self::cleanup_failed_attachment_copy( $new_id, $file, [] ) ) {
				return new \WP_Error( 'media_health_action_copy_cleanup_failed', __( 'The optimized copy failed and could not be removed completely.', 'bricks' ) );
			}

			return new \WP_Error( 'media_health_action_generation_failed', __( 'The optimized copy could not generate image sizes.', 'bricks' ) );
		}

		if ( ! self::persist_attachment_metadata( $new_id, $metadata ) ) {
			if ( ! self::cleanup_failed_attachment_copy( $new_id, $file, $metadata ) ) {
				return new \WP_Error( 'media_health_action_copy_cleanup_failed', __( 'The optimized copy failed and could not be removed completely.', 'bricks' ) );
			}

			return new \WP_Error( 'media_health_action_metadata_failed', __( 'The optimized copy metadata could not be saved, so the incomplete copy was removed.', 'bricks' ) );
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( $alt !== '' ) {
			update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		} elseif ( get_post_meta( $attachment_id, Media_Browser_Health::META_DECORATIVE, true ) ) {
			update_post_meta( $new_id, Media_Browser_Health::META_DECORATIVE, 1 );
		}

		$new_health = Media_Browser_Health::audit_attachment( $new_id, true );

		return [
			'attachmentId'     => $attachment_id,
			'newAttachmentId'  => (int) $new_id,
			'health'           => Media_Browser_Health::get_result( $attachment_id, false ),
			'newHealth'        => $new_health,
			'message'          => __( 'Optimized copy created', 'bricks' ),
			'newAttachmentUrl' => (string) wp_get_attachment_url( $new_id ),
			'backupUrl'        => '',
		];
	}

	/**
	 * Remove a newly inserted attachment and every explicitly generated file.
	 *
	 * @param int    $attachment_id New attachment ID.
	 * @param string $file          New attachment file.
	 * @param array  $metadata      Generated metadata that may not be durable.
	 * @return bool
	 */
	private static function cleanup_failed_attachment_copy( $attachment_id, $file, $metadata ) {
		$files   = self::get_attachment_files( $file, $metadata );
		$deleted = wp_delete_attachment( $attachment_id, true );
		$clean   = $deleted !== false && ! get_post( $attachment_id );

		foreach ( $files as $generated_file ) {
			if ( is_file( $generated_file ) ) {
				self::delete_tracked_file( $generated_file );
				$clean = $clean && ! is_file( $generated_file );
			}
		}

		return $clean;
	}

	/**
	 * Replace an original with a staged transform and regenerate its sizes.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        Current original file.
	 * @param string $transformed   Staged transformed file.
	 * @param string $current_mime  Current MIME type.
	 * @param string $target_mime   Target MIME type.
	 * @return array|\WP_Error
	 */
	private static function replace_with_transformed_file( $attachment_id, $source, $transformed, $current_mime, $target_mime ) {
		if ( $target_mime !== $current_mime ) {
			return self::apply_replacement_file( $attachment_id, $transformed, $target_mime );
		}

		return self::replace_staged_file_at_current_path(
			$attachment_id,
			$source,
			$transformed,
			$target_mime,
			__( 'Original image updated', 'bricks' )
		);
	}

	/**
	 * Replace bytes at the current uploads path without changing the public URL.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        Current attachment path, which may be missing.
	 * @param string $staged        Complete staged replacement.
	 * @param string $new_mime      Replacement MIME type.
	 * @param string $message       Success message.
	 * @return array|\WP_Error
	 */
	private static function replace_staged_file_at_current_path( $attachment_id, $source, $staged, $new_mime, $message ) {
		$state  = self::get_attachment_state( $attachment_id );
		$source = self::normalize_file_path( $source );

		if (
			$source === '' ||
			self::get_upload_relative_path( $source ) === '' ||
			! self::filename_matches_mime( $source, $new_mime )
		) {
			self::delete_tracked_file( $staged );

			return new \WP_Error( 'media_health_action_url_change_blocked', __( 'This replacement cannot preserve the current file URL. Use a compatible file with the same extension or create a separate attachment.', 'bricks' ) );
		}

		if ( self::file_is_owned_by_another_attachment( $attachment_id, $source ) ) {
			self::delete_tracked_file( $staged );

			return new \WP_Error( 'media_health_action_shared_file', __( 'This file is shared with another attachment and cannot be changed in place. Create a separate copy first.', 'bricks' ) );
		}

		$transaction = self::snapshot_attachment_files( $state['source'], $state['metadata'], [ $staged ], $attachment_id );

		if ( is_wp_error( $transaction ) ) {
			self::delete_tracked_file( $staged );

			return $transaction;
		}

		$backups = self::create_replacement_backups( $attachment_id, $state );

		if ( is_wp_error( $backups ) ) {
			self::discard_file_snapshot( $transaction );
			self::delete_tracked_file( $staged );

			return $backups;
		}

		$created_identity = null;
		$replaced         = $state['sourceExists']
			? self::replace_file_atomically( $staged, $source )
			: self::copy_file_exclusive( $staged, $source, $created_identity );

		if ( $replaced && ! $state['sourceExists'] && is_array( $created_identity ) ) {
			$transaction['created'][] = $created_identity;
		}

		self::delete_tracked_file( $staged );

		if ( ! $replaced ) {

			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_replace_failed', __( 'The optimized image could not replace the original.', 'bricks' ) );
		}

		$post_updated = wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $new_mime,
			],
			true
		);

		if ( is_wp_error( $post_updated ) || (string) get_post_mime_type( $attachment_id ) !== (string) $new_mime ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction ) ) {
				return self::rollback_failure_error();
			}

			return is_wp_error( $post_updated )
				? $post_updated
				: new \WP_Error( 'media_health_action_mime_failed', __( 'The replacement media type could not be saved. The original files were restored.', 'bricks' ) );
		}

		self::load_image_dependencies();
		$metadata = self::generate_attachment_metadata_preserving_path( $attachment_id, $source );
		$metadata = is_array( $metadata ) ? $metadata : [];

		if ( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $state['file'] ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction, self::get_attachment_files( $source, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_url_change_blocked', __( 'WordPress attempted to change the attachment URL while processing this file, so the original files were restored.', 'bricks' ) );
		}

		if ( self::mime_requires_generated_metadata( $new_mime ) && ! $metadata ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_generation_failed', __( 'The replacement could not generate image sizes. The original was restored.', 'bricks' ) );
		}

		if ( ! self::persist_attachment_metadata( $attachment_id, $metadata ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction, self::get_attachment_files( $source, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_metadata_failed', __( 'The replacement metadata could not be saved. The original files were restored.', 'bricks' ) );
		}

		if ( ! self::store_replacement_backups( $attachment_id, $backups ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction, self::get_attachment_files( $source, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_backup_metadata_failed', __( 'The recovery backups could not be recorded. The original files were restored.', 'bricks' ) );
		}

		self::discard_file_snapshot( $transaction );

		$backup_paths = array_column( $backups, 'path' );
		$backup_url   = $backups ? (string) end( $backups )['url'] : '';
		self::cleanup_superseded_attachment_files( $source, $state['metadata'], $source, $metadata, $backup_paths, $attachment_id );
		$health = Media_Browser_Health::audit_attachment( $attachment_id, true );

		return self::success_result( $attachment_id, $health, $message, $backup_url );
	}

	/**
	 * Replace an attachment file while preserving its ID and editorial metadata.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array      $options       Replacement options.
	 * @param array|null $uploaded_file Uploaded replacement file.
	 * @return array|\WP_Error
	 */
	private static function replace_file( $attachment_id, $options, $uploaded_file ) {
		if ( empty( $options['confirmed'] ) ) {
			return new \WP_Error( 'media_health_action_confirmation_required', __( 'Confirm replacement before continuing.', 'bricks' ) );
		}

		if ( ! is_array( $uploaded_file ) || empty( $uploaded_file['name'] ) ) {
			return new \WP_Error( 'media_health_action_upload_required', __( 'Choose a replacement file.', 'bricks' ) );
		}

		self::load_upload_dependencies();
		$upload = wp_handle_upload( $uploaded_file, [ 'test_form' => false ] );

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return new \WP_Error( 'media_health_action_upload_failed', ! empty( $upload['error'] ) ? $upload['error'] : __( 'The replacement file could not be uploaded.', 'bricks' ) );
		}

		if ( self::get_upload_relative_path( $upload['file'] ) === '' ) {
			return new \WP_Error( 'media_health_action_path_unsafe', __( 'The uploaded replacement is not safely contained in the uploads directory.', 'bricks' ) );
		}

		$current_mime  = (string) get_post_mime_type( $attachment_id );
		$bucket_before = self::mime_bucket( $current_mime );
		$bucket_after  = self::mime_bucket( (string) $upload['type'] );

		if ( $bucket_before !== $bucket_after ) {
			self::delete_tracked_file( $upload['file'] );

			return new \WP_Error( 'media_health_action_format_mismatch', __( 'Choose a replacement file in the same format as the current attachment.', 'bricks' ) );
		}

		$source = get_attached_file( $attachment_id );

		if (
			$source &&
			self::get_upload_relative_path( $source ) !== '' &&
			! self::filename_matches_mime( $source, $upload['type'] )
		) {
			self::delete_tracked_file( $upload['file'] );

			return new \WP_Error( 'media_health_action_format_mismatch', __( 'Choose a replacement file in the same format as the current attachment so its file URL can be preserved.', 'bricks' ) );
		}

		if (
			$source &&
			self::get_upload_relative_path( $source ) !== '' &&
			self::filename_matches_mime( $source, $upload['type'] )
		) {
			return self::replace_staged_file_at_current_path(
				$attachment_id,
				$source,
				$upload['file'],
				$upload['type'],
				__( 'Attachment file replaced', 'bricks' )
			);
		}

		return self::apply_replacement_file( $attachment_id, $upload['file'], $upload['type'] );
	}

	/**
	 * Relink a broken attachment to an existing file within uploads.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $options       Relink options.
	 * @return array|\WP_Error
	 */
	private static function relink_file( $attachment_id, $options ) {
		if ( empty( $options['confirmed'] ) ) {
			return new \WP_Error( 'media_health_action_confirmation_required', __( 'Confirm relinking before continuing.', 'bricks' ) );
		}

		$relative = isset( $options['relativePath'] ) ? wp_normalize_path( trim( (string) $options['relativePath'] ) ) : '';

		if ( $relative === '' || strlen( $relative ) > self::MAX_RELINK_LENGTH || strpos( $relative, '..' ) !== false || strpos( $relative, "\0" ) !== false ) {
			return new \WP_Error( 'media_health_action_path_invalid', __( 'Enter a valid path relative to the uploads directory.', 'bricks' ) );
		}

		$uploads = wp_get_upload_dir();
		$basedir = self::get_canonical_upload_root();
		$file    = $basedir ? wp_normalize_path( trailingslashit( $basedir ) . ltrim( $relative, '/' ) ) : '';
		$real    = $file ? realpath( $file ) : false;
		$real    = $real ? wp_normalize_path( $real ) : '';

		if ( ! $basedir || ! $real || self::get_upload_relative_path( $real ) === '' || ! is_file( $real ) || ! is_readable( $real ) ) {
			return new \WP_Error( 'media_health_action_file_unavailable', __( 'The selected uploads file is unavailable.', 'bricks' ) );
		}

		// Relink paths are user-entered. Restrict them to media-library-owned files
		// and require access to every exact owner so a guessed private uploads path
		// cannot be duplicated into an attachment the current user can read.
		$source_owners = self::get_file_owner_attachment_ids( $real );

		if ( is_wp_error( $source_owners ) || empty( $source_owners ) ) {
			return new \WP_Error( 'media_health_action_source_not_allowed', __( 'The selected file is not available for relinking.', 'bricks' ) );
		}

		foreach ( $source_owners as $source_owner ) {
			if ( ! current_user_can( 'read_post', $source_owner ) ) {
				return new \WP_Error( 'media_health_action_source_not_allowed', __( 'The selected file is not available for relinking.', 'bricks' ) );
			}
		}

		$checked = wp_check_filetype_and_ext( $real, wp_basename( $real ) );
		$mime    = ! empty( $checked['type'] ) ? $checked['type'] : '';

		if ( ! $mime || self::mime_bucket( (string) get_post_mime_type( $attachment_id ) ) !== self::mime_bucket( $mime ) ) {
			return new \WP_Error( 'media_health_action_format_mismatch', __( 'Choose a replacement file in the same format as the current attachment.', 'bricks' ) );
		}

		$state  = self::get_attachment_state( $attachment_id );
		$target = self::get_relink_copy_destination( $attachment_id, $state, $real, $mime );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$url_allowed = self::validate_url_change( $attachment_id, $state, $target );

		if ( is_wp_error( $url_allowed ) ) {
			return $url_allowed;
		}

		$transaction = self::snapshot_attachment_files( $state['source'], $state['metadata'], [], $attachment_id );

		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}

		// Always create a complete, exclusively owned destination so a concurrent
		// relink cannot overwrite or begin sharing the same physical upload.
		$created_identity = null;

		if ( ! self::copy_file_exclusive( $real, $target, $created_identity ) || ! is_array( $created_identity ) ) {
			self::discard_file_snapshot( $transaction );

			return new \WP_Error( 'media_health_action_copy_failed', __( 'The selected file could not be copied for this attachment.', 'bricks' ) );
		}
		$transaction['created'][] = $created_identity;

		return self::apply_replacement_file( $attachment_id, $target, $mime, true, $state, $transaction );
	}

	/**
	 * Point an existing attachment at a validated replacement and rebuild metadata.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $new_file      Replacement file.
	 * @param string     $new_mime      Replacement MIME type.
	 * @param bool       $delete_on_fail Whether a failed replacement upload should be removed.
	 * @param array|null $state          Optional state captured before a relink copy.
	 * @param array|null $transaction    Optional file snapshot captured before a relink copy.
	 * @return array|\WP_Error
	 */
	private static function apply_replacement_file( $attachment_id, $new_file, $new_mime, $delete_on_fail = true, $state = null, $transaction = null ) {
		$state         = is_array( $state ) ? $state : self::get_attachment_state( $attachment_id );
		$expected_file = self::get_upload_relative_path( $new_file );
		$url_allowed   = self::validate_url_change( $attachment_id, $state, $new_file );

		if ( is_wp_error( $url_allowed ) ) {
			if ( $delete_on_fail ) {
				self::delete_tracked_file( $new_file );
			}

			if ( is_array( $transaction ) ) {
				self::discard_file_snapshot( $transaction );
			}

			return $url_allowed;
		}

		$transaction = is_array( $transaction ) ? $transaction : self::snapshot_attachment_files( $state['source'], $state['metadata'], [], $attachment_id );

		if ( is_wp_error( $transaction ) ) {
			if ( $delete_on_fail ) {
				self::delete_tracked_file( $new_file );
			}

			return $transaction;
		}

		$transaction = self::extend_file_snapshot( $transaction, $new_file, [], [], $attachment_id );

		if ( is_wp_error( $transaction ) ) {
			if ( $delete_on_fail ) {
				self::delete_tracked_file( $new_file );
			}

			return $transaction;
		}

		$backups = self::create_replacement_backups( $attachment_id, $state );

		if ( is_wp_error( $backups ) ) {
			if ( ! self::rollback_file_snapshot( $transaction ) ) {
				return self::rollback_failure_error();
			}

			return $backups;
		}

		if ( self::file_is_owned_by_another_attachment( $attachment_id, $new_file ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_shared_file', __( 'This file became shared with another attachment before it could be committed. No attachment changes were saved.', 'bricks' ) );
		}

		update_attached_file( $attachment_id, $new_file );
		$attached_saved = $expected_file !== '' && get_post_meta( $attachment_id, '_wp_attached_file', true ) === $expected_file;
		$post_updated   = wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $new_mime
			],
			true
		);

		if ( ! $attached_saved || is_wp_error( $post_updated ) || (string) get_post_mime_type( $attachment_id ) !== (string) $new_mime ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction ) ) {
				return self::rollback_failure_error();
			}

			return is_wp_error( $post_updated )
				? $post_updated
				: new \WP_Error( 'media_health_action_replace_failed', __( 'The attachment file or media type could not be replaced.', 'bricks' ) );
		}

		self::load_image_dependencies();
		$metadata = self::generate_attachment_metadata_preserving_path( $attachment_id, $new_file );
		$metadata = is_array( $metadata ) ? $metadata : [];

		if ( get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $expected_file ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction, self::get_attachment_files( $new_file, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_url_change_blocked', __( 'WordPress attempted to change the attachment URL while processing this file, so the original files were restored.', 'bricks' ) );
		}

		if ( self::mime_requires_generated_metadata( $new_mime ) && ! $metadata ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_generation_failed', __( 'The replacement could not generate image sizes. The original was restored.', 'bricks' ) );
		}

		if ( ! self::persist_attachment_metadata( $attachment_id, $metadata ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction, self::get_attachment_files( $new_file, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_metadata_failed', __( 'The replacement metadata could not be saved. The original files were restored.', 'bricks' ) );
		}

		if ( ! self::store_replacement_backups( $attachment_id, $backups ) ) {
			if ( ! self::rollback_replacement( $attachment_id, $state, $backups, $transaction, self::get_attachment_files( $new_file, $metadata ) ) ) {
				return self::rollback_failure_error();
			}

			return new \WP_Error( 'media_health_action_backup_metadata_failed', __( 'The recovery backups could not be recorded. The original files were restored.', 'bricks' ) );
		}

		self::discard_file_snapshot( $transaction );

		$backup_paths = array_column( $backups, 'path' );
		$backup_url   = $backups ? (string) end( $backups )['url'] : '';
		self::cleanup_superseded_attachment_files( $state['source'], $state['metadata'], $new_file, $metadata, $backup_paths, $attachment_id );

		$health = Media_Browser_Health::audit_attachment( $attachment_id, true );

		return self::success_result( $attachment_id, $health, __( 'Attachment file replaced', 'bricks' ), $backup_url );
	}

	/**
	 * Restore attachment state after a failed staged replacement.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $state         Original attachment state.
	 * @param array $backups       Recovery backup records.
	 * @param array $transaction   Snapshot of files that existed before mutation.
	 * @param array $targeted_files Generator outputs proven by returned metadata.
	 * @return bool
	 */
	private static function rollback_replacement( $attachment_id, $state, $backups, $transaction, $targeted_files = [] ) {
		$restored = self::rollback_file_snapshot( $transaction, false, $targeted_files );
		$restored = self::restore_raw_post_meta( $attachment_id, '_wp_attached_file', $state['fileMetaExists'], $state['fileRaw'] ) && $restored;

		$post_updated = wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $state['mime']
			],
			true
		);
		$restored     = $restored && ! is_wp_error( $post_updated ) && (string) get_post_mime_type( $attachment_id ) === $state['mime'];
		$restored     = self::restore_raw_post_meta( $attachment_id, '_wp_attachment_metadata', $state['metadataMetaExists'], $state['metadataRaw'] ) && $restored;
		$restored     = self::restore_raw_post_meta( $attachment_id, self::META_BACKUPS, $state['backupsMetaExists'], $state['backupsRaw'] ) && $restored;

		if ( $restored ) {
			$restored = self::discard_file_snapshot( $transaction ) && self::delete_replacement_backups( $backups );
		}

		return $restored;
	}

	/**
	 * Return the fail-closed error used when automatic rollback is incomplete.
	 *
	 * @return \WP_Error
	 */
	private static function rollback_failure_error() {
		return new \WP_Error(
			'media_health_action_rollback_failed',
			__( 'The action failed and automatic rollback could not fully restore the attachment. Stop editing this media item and restore it from a verified backup.', 'bricks' )
		);
	}

	/**
	 * Capture the database and filesystem state used by a replacement rollback.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function get_attachment_state( $attachment_id ) {
		$source       = get_attached_file( $attachment_id );
		$metadata     = wp_get_attachment_metadata( $attachment_id );
		$file_raw     = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$metadata_raw = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$backups_raw  = get_post_meta( $attachment_id, self::META_BACKUPS, true );

		return [
			'source'             => self::normalize_file_path( $source ),
			'sourceExists'       => $source && is_file( $source ) && is_readable( $source ),
			'file'               => is_scalar( $file_raw ) ? (string) $file_raw : '',
			'fileRaw'            => $file_raw,
			'fileMetaExists'     => metadata_exists( 'post', $attachment_id, '_wp_attached_file' ),
			'mime'               => (string) get_post_mime_type( $attachment_id ),
			'metadata'           => is_array( $metadata ) ? $metadata : [],
			'metadataRaw'        => $metadata_raw,
			'metadataMetaExists' => metadata_exists( 'post', $attachment_id, '_wp_attachment_metadata' ),
			'backups'            => is_array( $backups_raw ) ? $backups_raw : [],
			'backupsRaw'         => $backups_raw,
			'backupsMetaExists'  => metadata_exists( 'post', $attachment_id, self::META_BACKUPS ),
		];
	}

	/**
	 * Restore one post-meta row with its exact original value and presence.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $key           Meta key.
	 * @param bool   $existed       Whether the row existed before mutation.
	 * @param mixed  $value         Exact raw value read before mutation.
	 * @return bool
	 */
	private static function restore_raw_post_meta( $attachment_id, $key, $existed, $value ) {
		if ( $existed ) {
			update_post_meta( $attachment_id, $key, $value );
		} else {
			delete_post_meta( $attachment_id, $key );
		}

		return metadata_exists( 'post', $attachment_id, $key ) === (bool) $existed && ( ! $existed || get_post_meta( $attachment_id, $key, true ) === $value );
	}

	/**
	 * Choose an attachment-owned destination for an existing relink source.
	 *
	 * A missing original path can be reused only when it remains inside uploads
	 * and its extension matches the selected file. Otherwise a unique sibling
	 * copy is created, which may require an unused attachment because its URL
	 * changes.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $state         Original attachment state.
	 * @param string $source        Validated relink source.
	 * @param string $mime          Detected source MIME type.
	 * @return string|\WP_Error
	 */
	private static function get_relink_copy_destination( $attachment_id, $state, $source, $mime ) {
		$missing_path = self::normalize_file_path( $state['source'] ?? '' );
		$candidates   = [];

		if (
			$missing_path !== '' &&
			! file_exists( $missing_path ) &&
			self::get_upload_relative_path( $missing_path ) !== '' &&
			is_dir( dirname( $missing_path ) ) &&
			is_writable( dirname( $missing_path ) ) &&
			self::filename_matches_mime( $missing_path, $mime )
		) {
			$candidates[] = $missing_path;
		}

		$extension = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );

		for ( $attempt = 1; $attempt <= 100; $attempt++ ) {
			$suffix       = 'relinked-' . absint( $attachment_id ) . ( $attempt > 1 ? '-' . $attempt : '' );
			$candidates[] = self::unique_destination( $source, $suffix, $extension );
		}

		$ownership = self::get_files_ownership_map( $candidates, [ $attachment_id ] );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		foreach ( $candidates as $candidate ) {
			$identity = self::get_canonical_upload_identity( $candidate );

			if ( $identity !== '' && empty( $ownership[ $identity ] ) ) {
				return $candidate;
			}
		}

		return new \WP_Error( 'media_health_action_owned_path', __( 'A unique attachment-owned destination could not be created for the selected file.', 'bricks' ) );
	}

	/**
	 * Check whether another attachment metadata state owns one uploads file.
	 *
	 * @param int    $attachment_id Attachment ID being repaired.
	 * @param string $file          Proposed absolute destination.
	 * @return bool
	 */
	private static function file_is_owned_by_another_attachment( $attachment_id, $file ) {
		$owners = self::get_file_owner_attachment_ids( $file, [ $attachment_id ] );

		return is_wp_error( $owners ) || ! empty( $owners );
	}

	/**
	 * Resolve attachments that explicitly own one uploads file.
	 *
	 * @param string $file     Candidate absolute uploads path.
	 * @param array  $excluded Attachment IDs to exclude from ownership results.
	 * @return array|\WP_Error
	 */
	private static function get_file_owner_attachment_ids( $file, $excluded = [] ) {
		return self::get_files_owner_attachment_ids( [ $file ], $excluded );
	}

	/**
	 * Resolve attachments that explicitly own any candidate uploads file.
	 *
	 * @param array $files    Candidate absolute uploads paths.
	 * @param array $excluded Attachment IDs to exclude from ownership results.
	 * @return array|\WP_Error
	 */
	private static function get_files_owner_attachment_ids( $files, $excluded = [] ) {
		$ownership = self::get_files_ownership_map( $files, $excluded );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		$owners = [];

		foreach ( $ownership as $attachment_ids ) {
			$owners = array_merge( $owners, $attachment_ids );
		}

		return array_values( array_unique( array_filter( $owners ) ) );
	}

	/**
	 * Resolve explicit attachment owners for each candidate uploads file.
	 *
	 * @param array $files    Candidate absolute uploads paths.
	 * @param array $excluded Attachment IDs to exclude from ownership results.
	 * @return array|\WP_Error Map of canonical file identities to owner IDs.
	 */
	private static function get_files_ownership_map( $files, $excluded = [] ) {
		global $wpdb;

		$files          = array_values( array_unique( array_map( [ __CLASS__, 'normalize_file_path' ], is_array( $files ) ? $files : [] ) ) );
		$identity_files = [];
		$basenames      = [];

		if ( ! $files || ! function_exists( 'get_posts' ) ) {
			return new \WP_Error( 'media_health_action_ownership_unknown', __( 'File ownership could not be verified safely.', 'bricks' ) );
		}

		foreach ( $files as $file ) {
			$relative = self::get_upload_relative_path( $file );
			$identity = self::get_canonical_upload_identity( $file );

			if ( $relative === '' || $identity === '' ) {
				return new \WP_Error( 'media_health_action_ownership_unknown', __( 'File ownership could not be verified safely.', 'bricks' ) );
			}

			if ( file_exists( $file ) ) {
				clearstatcache( true, $file );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent filesystem change must become a fail-closed ownership result.
				$file_stat = @stat( $file );

				// A second hard-link name can have an unrelated basename and therefore
				// cannot be discovered with a bounded metadata query.
				if ( ! is_array( $file_stat ) || ! isset( $file_stat['nlink'] ) || (int) $file_stat['nlink'] > 1 ) {
					return new \WP_Error( 'media_health_action_ownership_unknown', __( 'File ownership could not be verified safely.', 'bricks' ) );
				}
			}

			$identity_files[ $identity ] = $file;
			$basenames[]                 = wp_basename( $identity );
		}

		$excluded      = array_values( array_filter( array_map( 'absint', is_array( $excluded ) ? $excluded : [] ) ) );
		$post_statuses = function_exists( 'get_post_stati' )
			? array_values( get_post_stati( [], 'names' ) )
			: [ 'inherit', 'private', 'trash', 'publish', 'future', 'draft', 'pending' ];
		$meta_query    = [ 'relation' => 'OR' ];

		foreach ( array_values( array_unique( $basenames ) ) as $basename ) {
			foreach ( [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', self::META_BACKUPS ] as $meta_key ) {
				$meta_query[] = [
					'key'     => $meta_key,
					'value'   => $basename,
					'compare' => 'LIKE',
				];
			}
		}

		$post_ids = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => $post_statuses,
				'posts_per_page'   => self::MAX_OWNERSHIP_MATCHES + 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A hard cap plus one detects ambiguous ownership and fails closed.
				'fields'           => 'ids',
				'post__not_in'     => $excluded,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One bounded candidate query protects every path the generator may overwrite.
			]
		);

		if (
			! is_array( $post_ids ) ||
			count( $post_ids ) > self::MAX_OWNERSHIP_MATCHES ||
			( is_object( $wpdb ) && ! empty( $wpdb->last_error ) )
		) {
			return new \WP_Error( 'media_health_action_ownership_unknown', __( 'File ownership could not be verified safely.', 'bricks' ) );
		}

		$ownership = array_fill_keys( array_keys( $identity_files ), [] );

		foreach ( $post_ids as $post_id ) {
			$post_id        = absint( $post_id );
			$other_file     = get_attached_file( $post_id );
			$other_metadata = wp_get_attachment_metadata( $post_id );
			$other_metadata = is_array( $other_metadata ) ? $other_metadata : [];
			$other_files    = array_merge(
				self::get_attachment_files( $other_file, $other_metadata ),
				self::get_core_backup_files( $post_id, $other_file, $other_metadata )
			);

			$backups = get_post_meta( $post_id, self::META_BACKUPS, true );

			foreach ( is_array( $backups ) ? $backups : [] as $backup ) {
				$backup_file = is_array( $backup ) ? self::get_upload_file_path( $backup['file'] ?? '' ) : '';

				if ( $backup_file !== '' ) {
					$other_files[] = $backup_file;
				}
			}

			foreach ( $identity_files as $identity => $candidate_file ) {
				foreach ( $other_files as $other_owned_file ) {
					if ( self::upload_paths_share_identity( $candidate_file, $other_owned_file ) ) {
						$ownership[ $identity ][] = $post_id;
						break;
					}
				}
			}
		}

		foreach ( $ownership as $identity => $owner_ids ) {
			$ownership[ $identity ] = array_values( array_unique( array_filter( $owner_ids ) ) );
		}

		return $ownership;
	}

	/**
	 * Compare two uploads paths by stored and canonical identity.
	 *
	 * Canonical comparison catches different lexical paths that traverse an
	 * in-uploads symlink. Hard-link aliases are rejected before this comparison
	 * because their other names cannot be queried by basename safely at scale.
	 *
	 * @param string $first  First uploads path.
	 * @param string $second Second uploads path.
	 * @return bool
	 */
	private static function upload_paths_share_identity( $first, $second ) {
		$first_relative  = self::get_upload_relative_path( $first );
		$second_relative = self::get_upload_relative_path( $second );

		if ( $first_relative === '' || $second_relative === '' ) {
			return false;
		}

		if ( $first_relative === $second_relative ) {
			return true;
		}

		$first_identity  = self::get_canonical_upload_identity( $first );
		$second_identity = self::get_canonical_upload_identity( $second );

		return $first_identity !== '' && $first_identity === $second_identity;
	}

	/**
	 * Check whether a filename extension represents the detected MIME type.
	 *
	 * @param string $file Filename or path.
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private static function filename_matches_mime( $file, $mime ) {
		if ( ! function_exists( 'wp_check_filetype' ) ) {
			return strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) === self::mime_extension( $mime );
		}

		$filetype = wp_check_filetype( wp_basename( $file ) );

		return ! empty( $filetype['type'] ) && strtolower( (string) $filetype['type'] ) === strtolower( (string) $mime );
	}

	/**
	 * Block every URL-changing in-place action.
	 *
	 * Bricks media controls and third-party content can store URLs without a
	 * discoverable attachment ID. Until those values can be transactionally
	 * rewritten, no usage scan can prove an in-place URL change safe.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $state         Original attachment state.
	 * @param string $new_file      Proposed attachment file.
	 * @return true|\WP_Error
	 */
	private static function validate_url_change( $attachment_id, $state, $new_file ) {
		$old_relative = wp_normalize_path( ltrim( (string) ( $state['file'] ?? '' ), '/' ) );
		$new_relative = self::get_upload_relative_path( $new_file );

		if ( $old_relative === '' && ! empty( $state['source'] ) ) {
			$old_relative = self::get_upload_relative_path( $state['source'] );
		}

		if ( $old_relative !== '' && $new_relative !== '' && $old_relative === $new_relative ) {
			return true;
		}

		return new \WP_Error(
			'media_health_action_url_change_blocked',
			__( 'Changing an attachment file URL in place is not supported because stored media references could break. Use a copy action or a file that preserves the current upload path.', 'bricks' )
		);
	}

	/**
	 * Return an uploads-relative path for one validated absolute path.
	 *
	 * @param mixed $file Absolute path.
	 * @return string
	 */
	private static function get_upload_relative_path( $file ) {
		$file           = self::normalize_file_path( $file );
		$uploads        = wp_get_upload_dir();
		$basedir        = isset( $uploads['basedir'] ) ? untrailingslashit( self::normalize_file_path( $uploads['basedir'] ) ) : '';
		$canonical_root = self::get_canonical_upload_root();

		if ( $file === '' || $basedir === '' || $canonical_root === '' || is_link( $file ) ) {
			return '';
		}

		if ( file_exists( $file ) ) {
			$resolved = realpath( $file );
			$resolved = $resolved ? self::normalize_file_path( $resolved ) : '';
		} else {
			$parent   = realpath( dirname( $file ) );
			$parent   = $parent ? self::normalize_file_path( $parent ) : '';
			$resolved = $parent !== '' ? path_join( $parent, wp_basename( $file ) ) : '';
		}

		$canonical_prefix = trailingslashit( $canonical_root );

		if ( $resolved === '' || strpos( $resolved, $canonical_prefix ) !== 0 ) {
			return '';
		}

		$lexical_prefix = trailingslashit( $basedir );
		$relative       = strpos( $file, $lexical_prefix ) === 0
			? substr( $file, strlen( $lexical_prefix ) )
			: substr( $resolved, strlen( $canonical_prefix ) );
		$relative       = wp_normalize_path( ltrim( (string) $relative, '/' ) );

		return $relative !== '' && strpos( $relative, "\0" ) === false && ! preg_match( '#(^|/)\.\.?(/|$)#', $relative ) ? $relative : '';
	}

	/**
	 * Return the canonical uploads root used to confine every file mutation.
	 *
	 * @return string
	 */
	private static function get_canonical_upload_root() {
		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;

		return $basedir && is_dir( $basedir ) ? untrailingslashit( self::normalize_file_path( $basedir ) ) : '';
	}

	/**
	 * Return the canonical identity of an existing or proposed uploads path.
	 *
	 * Missing paths use their canonical parent plus basename so symlinked parent
	 * aliases cannot evade attachment ownership checks.
	 *
	 * @param mixed $file Existing or proposed uploads path.
	 * @return string
	 */
	private static function get_canonical_upload_identity( $file ) {
		$file = self::normalize_file_path( $file );

		if ( $file === '' || self::get_upload_relative_path( $file ) === '' ) {
			return '';
		}

		if ( file_exists( $file ) ) {
			$resolved = realpath( $file );

			return $resolved ? self::normalize_file_path( $resolved ) : '';
		}

		$parent = realpath( dirname( $file ) );

		return $parent ? self::normalize_file_path( path_join( $parent, wp_basename( $file ) ) ) : '';
	}

	/**
	 * Verify that attachment metadata reached durable post meta storage.
	 *
	 * WordPress returns false both for a failed update and for an unchanged value,
	 * so an unchanged return must be checked against the stored metadata.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Attachment metadata.
	 * @return bool
	 */
	private static function persist_attachment_metadata( $attachment_id, $metadata ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) === $metadata;
	}

	/**
	 * Snapshot every existing file owned by one attachment metadata state.
	 *
	 * @param string $file          Attached file path.
	 * @param array  $metadata      Attachment metadata.
	 * @param array  $excluded      Existing staging paths that are not attachment-owned files.
	 * @param int    $attachment_id Attachment ID for core backup-size protection.
	 * @return array|\WP_Error
	 */
	private static function snapshot_attachment_files( $file, $metadata, $excluded = [], $attachment_id = 0 ) {
		$snapshot = [
			'files'        => [],
			'explicit'     => [],
			'protective'   => [],
			'existing'     => [],
			'backups'      => [],
			'created'      => [],
			'attachmentId' => absint( $attachment_id ),
		];

		return self::extend_file_snapshot( $snapshot, $file, $metadata, $excluded, $attachment_id );
	}

	/**
	 * Return paths from identity-bound transaction creation records.
	 *
	 * @param mixed $created Creation records.
	 * @return array
	 */
	private static function get_created_file_paths( $created ) {
		$paths = [];

		foreach ( is_array( $created ) ? $created : [] as $record ) {
			$path = is_array( $record ) ? self::normalize_file_path( $record['path'] ?? '' ) : '';

			if ( $path !== '' ) {
				$paths[] = $path;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Add a second file stem to an existing transaction snapshot.
	 *
	 * @param array  $snapshot      File snapshot.
	 * @param string $file          Attached or proposed file path.
	 * @param array  $metadata      Attachment metadata for the path.
	 * @param array  $excluded      Existing staging paths that are not attachment-owned files.
	 * @param int    $attachment_id Attachment ID for core backup-size protection.
	 * @return array|\WP_Error
	 */
	private static function extend_file_snapshot( $snapshot, $file, $metadata, $excluded = [], $attachment_id = 0 ) {
		$excluded            = array_merge( is_array( $excluded ) ? $excluded : [], self::get_created_file_paths( $snapshot['created'] ?? [] ) );
		$excluded            = array_map( [ __CLASS__, 'normalize_file_path' ], $excluded );
		$explicit_candidates = array_merge( self::get_attachment_files( $file, $metadata ), self::get_core_backup_files( $attachment_id, $file, $metadata ) );
		$explicit_candidates = array_values( array_unique( array_diff( $explicit_candidates, $excluded ) ) );
		$candidates          = array_values( array_diff( self::get_transaction_candidate_files( $file, $metadata ), $excluded ) );
		$candidates          = array_values( array_unique( array_merge( $candidates, $explicit_candidates ) ) );
		$protective          = array_values( array_diff( $candidates, $explicit_candidates ) );

		$snapshot['files']      = array_values( array_unique( array_merge( $snapshot['files'], $candidates ) ) );
		$snapshot['explicit']   = array_values( array_unique( array_merge( is_array( $snapshot['explicit'] ?? null ) ? $snapshot['explicit'] : [], $explicit_candidates ) ) );
		$snapshot['protective'] = array_values(
			array_diff(
				array_unique( array_merge( is_array( $snapshot['protective'] ?? null ) ? $snapshot['protective'] : [], $protective ) ),
				$snapshot['explicit']
			)
		);
		$existing_candidates    = array_values( array_filter( $candidates, 'is_file' ) );

		if ( $existing_candidates ) {
			$owners = self::get_files_owner_attachment_ids( $existing_candidates, [ $attachment_id ] );

			if ( is_wp_error( $owners ) || $owners ) {
				self::discard_file_snapshot( $snapshot );

				return is_wp_error( $owners )
					? $owners
					: new \WP_Error( 'media_health_action_shared_file', __( 'A file this action may update is owned by another attachment, so no changes were made.', 'bricks' ) );
			}
		}

		foreach ( $candidates as $owned_file ) {
			if ( ! is_file( $owned_file ) ) {
				continue;
			}

			$owned_file = self::normalize_file_path( $owned_file );

			if ( self::get_upload_relative_path( $owned_file ) === '' ) {
				self::discard_file_snapshot( $snapshot );

				return new \WP_Error( 'media_health_action_snapshot_failed', __( 'The current files could not be protected safely inside uploads, so no changes were made.', 'bricks' ) );
			}

			if ( in_array( $owned_file, $snapshot['existing'], true ) ) {
				continue;
			}

			$snapshot['existing'][] = $owned_file;

			if ( ! is_readable( $owned_file ) ) {
				self::discard_file_snapshot( $snapshot );

				return new \WP_Error( 'media_health_action_snapshot_failed', __( 'The current files could not be protected, so no changes were made.', 'bricks' ) );
			}

			$extension = strtolower( pathinfo( $owned_file, PATHINFO_EXTENSION ) );
			$backup    = self::unique_destination( $owned_file, 'bricks-transaction', $extension !== '' ? $extension : 'tmp' );

			if ( ! self::copy_file_exclusive( $owned_file, $backup ) ) {
				self::discard_file_snapshot( $snapshot );

				return new \WP_Error( 'media_health_action_snapshot_failed', __( 'The current files could not be protected, so no changes were made.', 'bricks' ) );
			}

			$snapshot['backups'][ self::normalize_file_path( $owned_file ) ] = self::normalize_file_path( $backup );
		}

		return $snapshot;
	}

	/**
	 * Restore snapshotted files and remove files introduced by a failed action.
	 *
	 * @param array $snapshot       File snapshot with an explicit created-file manifest.
	 * @param bool  $discard        Whether to discard snapshots after file restoration.
	 * @param array $targeted_files Generator outputs proven by its returned metadata.
	 * @return bool
	 */
	private static function rollback_file_snapshot( $snapshot, $discard = true, $targeted_files = [] ) {
		$backups       = is_array( $snapshot['backups'] ?? null ) ? $snapshot['backups'] : [];
		$generated     = is_array( $snapshot['created'] ?? null ) ? $snapshot['created'] : [];
		$explicit      = is_array( $snapshot['explicit'] ?? null ) ? $snapshot['explicit'] : [];
		$targeted      = array_map( [ __CLASS__, 'normalize_file_path' ], is_array( $targeted_files ) ? $targeted_files : [] );
		$restore_files = array_values( array_unique( array_merge( $explicit, $targeted ) ) );
		$restored      = true;
		$attachment_id = absint( $snapshot['attachmentId'] ?? 0 );

		// Generator output is deliberately not rediscovered by filename or metadata
		// during rollback. Another process may have created or claimed those paths
		// after the snapshot; uncertain files are safer as orphans than deleted.
		foreach ( $generated as $created_file ) {
			$generated_file = is_array( $created_file ) ? self::normalize_file_path( $created_file['path'] ?? '' ) : '';

			if ( $generated_file === '' || ! self::path_matches_file_stat( $generated_file, $created_file ) ) {
				continue;
			}

			if ( $attachment_id && self::file_is_owned_by_another_attachment( $attachment_id, $generated_file ) ) {
				continue;
			}

			if ( is_file( $generated_file ) ) {
				self::delete_tracked_file( $generated_file );
				$restored = $restored && ! is_file( $generated_file );
			}
		}

		foreach ( $backups as $original => $backup ) {
			if ( ! is_file( $backup ) || ! is_readable( $backup ) ) {
				$restored = false;
				continue;
			}

			// Broad stem siblings are snapshotted defensively because an image
			// generator may target them. Restore only paths that old attachment
			// metadata owned or returned metadata proves this invocation targeted;
			// otherwise a concurrent legitimate writer must win.
			if ( ! in_array( self::normalize_file_path( $original ), $restore_files, true ) ) {
				continue;
			}

			$copied   = self::replace_file_atomically( $backup, $original );
			$restored = $copied && self::files_match( $backup, $original ) && $restored;
		}

		if ( ! $restored || ! $discard ) {
			return $restored;
		}

		return self::discard_file_snapshot( $snapshot );
	}

	/**
	 * Return attachment files plus existing sibling derivatives a generator may overwrite.
	 *
	 * Metadata can be incomplete, so limiting a transaction to listed sizes would
	 * leave unlisted dimension derivatives unprotected when WordPress reuses their
	 * filenames during regeneration. Ordinary uploads with a coincidentally shared
	 * stem (for example image-1.jpg) are not generator targets and must not make an
	 * otherwise safe replacement look like a shared-file conflict.
	 *
	 * @param string $file     Attached file path.
	 * @param array  $metadata Attachment metadata.
	 * @return array
	 */
	private static function get_transaction_candidate_files( $file, $metadata ) {
		$files   = self::get_attachment_files( $file, $metadata );
		$primary = self::normalize_file_path( $file );

		if ( $primary === '' || self::get_upload_relative_path( $primary ) === '' || ! is_dir( dirname( $primary ) ) || ! is_readable( dirname( $primary ) ) ) {
			return $files;
		}

		$directory = dirname( $primary );
		$stem      = pathinfo( $primary, PATHINFO_FILENAME );
		$pattern   = '/^' . preg_quote( $stem, '/' ) . '-(?:[0-9]+x[0-9]+|rotated)\.[^.]+$/i';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- A bounded sibling manifest is required before WordPress generates files.
		$siblings = scandir( $directory );

		foreach ( is_array( $siblings ) ? $siblings : [] as $sibling ) {
			if (
				! preg_match( $pattern, $sibling ) ||
				strpos( $sibling, '-bricks-backup-' ) !== false ||
				strpos( $sibling, '-bricks-transaction' ) !== false
			) {
				continue;
			}

			$sibling_file = self::normalize_file_path( path_join( $directory, $sibling ) );

			if ( is_file( $sibling_file ) ) {
				$files[] = $sibling_file;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Delete temporary transaction snapshots after commit or rollback.
	 *
	 * @param array $snapshot File snapshot.
	 * @return bool
	 */
	private static function discard_file_snapshot( $snapshot ) {
		$discarded = true;

		foreach ( is_array( $snapshot['backups'] ?? null ) ? $snapshot['backups'] : [] as $backup ) {
			if ( is_file( $backup ) ) {
				self::delete_tracked_file( $backup );
				$discarded = $discarded && ! is_file( $backup );
			}
		}

		return $discarded;
	}

	/**
	 * Create recovery copies for the attached file and separate full-resolution original.
	 *
	 * The attached file can be WordPress's scaled derivative, while original_image
	 * is the sole full-resolution upload. Both need durable recovery copies before
	 * a successful replacement is allowed to delete the previous file set.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $state         Original attachment state.
	 * @return array|\WP_Error
	 */
	private static function create_replacement_backups( $attachment_id, $state ) {
		$backups      = [];
		$original     = self::get_original_image_path( $state['source'], $state['metadata'] );
		$source       = self::normalize_file_path( $state['source'] );
		$backup_files = [];

		if ( $original !== '' && $original !== $source && is_file( $original ) && ! is_readable( $original ) ) {
			return new \WP_Error( 'media_health_action_backup_failed', __( 'The full-resolution original could not be protected, so no changes were made.', 'bricks' ) );
		}

		if ( $source !== '' && is_file( $source ) && ! is_readable( $source ) ) {
			return new \WP_Error( 'media_health_action_backup_failed', __( 'The current file could not be protected, so no changes were made.', 'bricks' ) );
		}

		if ( $original !== '' && $original !== $source && is_file( $original ) ) {
			$backup_files['original_image'] = $original;
		}

		if ( ! empty( $state['sourceExists'] ) ) {
			$backup_files['attached_file'] = $source;
		}

		foreach ( $backup_files as $kind => $backup_file ) {
			$backup = self::create_backup( $attachment_id, $backup_file, (string) $state['mime'] );

			if ( is_wp_error( $backup ) ) {
				self::delete_replacement_backups( $backups );

				return $backup;
			}

			$backup['kind'] = $kind;
			$backups[]      = $backup;
		}

		return $backups;
	}

	/**
	 * Resolve the separate full-resolution original owned by attachment metadata.
	 *
	 * @param string $file     Attached file path.
	 * @param array  $metadata Attachment metadata.
	 * @return string
	 */
	private static function get_original_image_path( $file, $metadata ) {
		if ( empty( $metadata['original_image'] ) ) {
			return '';
		}

		$metadata_file = self::get_upload_file_path( $metadata['file'] ?? '' );
		$metadata_file = $metadata_file !== '' ? $metadata_file : self::normalize_file_path( $file );

		return $metadata_file !== ''
			? self::normalize_file_path( path_join( dirname( $metadata_file ), wp_basename( (string) $metadata['original_image'] ) ) )
			: '';
	}

	/**
	 * Delete recovery copies created for an uncommitted replacement.
	 *
	 * @param array $backups Recovery backup records.
	 * @return bool
	 */
	private static function delete_replacement_backups( $backups ) {
		$deleted = true;

		foreach ( is_array( $backups ) ? $backups : [] as $backup ) {
			if ( is_array( $backup ) ) {
				$file = $backup['path'] ?? '';

				if ( is_file( $file ) ) {
					self::delete_tracked_file( $file );
					$deleted = $deleted && ! is_file( $file );
				}
			}
		}

		return $deleted;
	}

	/**
	 * Create a sibling backup of one local attachment original.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        Original path.
	 * @param string $mime          Original MIME type.
	 * @return array|\WP_Error
	 */
	private static function create_backup( $attachment_id, $source, $mime ) {
		$extension = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		$backup    = self::unique_destination( $source, 'bricks-backup-' . gmdate( 'Ymd-His' ), $extension );

		if ( self::get_upload_relative_path( $source ) === '' || ! self::copy_file_exclusive( $source, $backup ) ) {
			return new \WP_Error( 'media_health_action_backup_failed', __( 'A backup could not be created, so the original was not changed.', 'bricks' ) );
		}

		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		$baseurl = isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';
		$path    = wp_normalize_path( $backup );
		$file    = $basedir && strpos( $path, trailingslashit( $basedir ) ) === 0
			? ltrim( substr( $path, strlen( trailingslashit( $basedir ) ) ), '/' )
			: wp_basename( $path );

		return [
			'path'         => $path,
			'file'         => $file,
			'url'          => $baseurl && $file ? trailingslashit( $baseurl ) . str_replace( '%2F', '/', rawurlencode( $file ) ) : '',
			'mime'         => $mime,
			'createdAt'    => time(),
			'attachmentId' => $attachment_id,
		];
	}

	/**
	 * Store replacement backup metadata before superseded originals are deleted.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $new_backups   New backup records.
	 * @return bool
	 */
	private static function store_replacement_backups( $attachment_id, $new_backups ) {
		$backups = self::get_stored_backups( $attachment_id );

		foreach ( is_array( $new_backups ) ? $new_backups : [] as $backup ) {
			if ( is_array( $backup ) ) {
				$backups[] = array_diff_key( $backup, [ 'path' => true ] );
			}
		}

		$discarded = array_slice( $backups, 0, -10 );
		$backups   = array_slice( $backups, -10 );
		update_post_meta( $attachment_id, self::META_BACKUPS, $backups );

		if ( self::get_stored_backups( $attachment_id ) !== $backups ) {
			return false;
		}

		foreach ( $discarded as $record ) {
			self::delete_backup_record( $record, $backups );
		}

		return true;
	}

	/**
	 * Return normalized stored recovery backup records.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function get_stored_backups( $attachment_id ) {
		$backups = get_post_meta( $attachment_id, self::META_BACKUPS, true );

		return is_array( $backups ) ? $backups : [];
	}

	/**
	 * Delete Bricks-managed recovery files before WordPress removes attachment metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function delete_tracked_attachment_files( $attachment_id ) {
		$backups = get_post_meta( absint( $attachment_id ), self::META_BACKUPS, true );
		$backups = is_array( $backups ) ? $backups : [];

		foreach ( $backups as $record ) {
			self::delete_backup_record( $record );
		}
	}

	/**
	 * Remove files owned by previous attachment metadata after new metadata is durable.
	 *
	 * @param string $old_file     Previous attached file path.
	 * @param array  $old_metadata Previous attachment metadata.
	 * @param string $new_file     Current attached file path.
	 * @param array  $new_metadata Current attachment metadata.
	 * @param array  $preserved    Additional absolute paths that must remain available.
	 * @param int    $attachment_id Current attachment ID for shared-file protection.
	 * @return void
	 */
	public static function cleanup_superseded_attachment_files( $old_file, $old_metadata, $new_file, $new_metadata, $preserved = [], $attachment_id = 0 ) {
		$core_file     = $new_file ? $new_file : $old_file;
		$core_metadata = $new_metadata ? $new_metadata : $old_metadata;
		$old_files     = self::get_attachment_files( $old_file, $old_metadata );
		$kept          = self::get_attachment_files( $new_file, $new_metadata );
		$kept          = array_merge( $kept, self::get_core_backup_files( $attachment_id, $core_file, $core_metadata ) );

		foreach ( is_array( $preserved ) ? $preserved : [] as $file ) {
			$file = self::normalize_file_path( $file );

			if ( $file !== '' ) {
				$kept[] = $file;
			}
		}

		$kept = array_unique( $kept );

		foreach ( array_diff( $old_files, $kept ) as $file ) {
			if ( $attachment_id && self::file_is_owned_by_another_attachment( $attachment_id, $file ) ) {
				continue;
			}

			self::delete_tracked_file( $file );
		}
	}

	/**
	 * Return every file explicitly owned by one attachment metadata snapshot.
	 *
	 * @param string $file     Attached file path.
	 * @param array  $metadata Attachment metadata.
	 * @return array
	 */
	private static function get_attachment_files( $file, $metadata ) {
		$metadata = is_array( $metadata ) ? $metadata : [];
		$files    = [];
		$file     = self::normalize_file_path( $file );

		if ( $file !== '' ) {
			$files[] = $file;
		}

		$metadata_file = self::get_upload_file_path( $metadata['file'] ?? '' );

		if ( $metadata_file === '' ) {
			$metadata_file = $file;
		} else {
			$files[] = $metadata_file;
		}

		$directory = $metadata_file !== '' ? dirname( $metadata_file ) : ( $file !== '' ? dirname( $file ) : '' );

		$sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : [];

		foreach ( $sizes as $size ) {
			if ( $directory !== '' && is_array( $size ) && ! empty( $size['file'] ) ) {
				$files[] = self::normalize_file_path( path_join( $directory, wp_basename( (string) $size['file'] ) ) );
			}
		}

		foreach ( [ 'original_image', 'thumb' ] as $key ) {
			if ( $directory !== '' && ! empty( $metadata[ $key ] ) ) {
				$files[] = self::normalize_file_path( path_join( $directory, wp_basename( (string) $metadata[ $key ] ) ) );
			}
		}

		return array_values( array_unique( array_filter( $files ) ) );
	}

	/**
	 * Return files referenced by WordPress's core image-edit backup metadata.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Attached file used to resolve the directory.
	 * @param array  $metadata      Attachment metadata used to resolve the directory.
	 * @return array
	 */
	private static function get_core_backup_files( $attachment_id, $file, $metadata = [] ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return [];
		}

		$records       = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$metadata      = is_array( $metadata ) ? $metadata : [];
		$metadata_file = self::get_upload_file_path( $metadata['file'] ?? '' );
		$attached_file = self::normalize_file_path( $file );
		$directory     = $metadata_file !== '' ? dirname( $metadata_file ) : ( $attached_file !== '' ? dirname( $attached_file ) : '' );
		$files         = [];

		if ( $directory === '' || ! is_array( $records ) ) {
			return [];
		}

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['file'] ) ) {
				continue;
			}

			$backup = self::normalize_file_path( path_join( $directory, wp_basename( (string) $record['file'] ) ) );

			if ( $backup !== '' && self::get_upload_relative_path( $backup ) !== '' ) {
				$files[] = $backup;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Delete one backup record unless it is still present in the retained records.
	 *
	 * @param mixed $record   Backup record.
	 * @param array $retained Backup records that still own their files.
	 * @return void
	 */
	private static function delete_backup_record( $record, $retained = [] ) {
		if ( ! is_array( $record ) || empty( $record['file'] ) ) {
			return;
		}

		$file = (string) $record['file'];

		foreach ( $retained as $retained_record ) {
			if ( is_array( $retained_record ) && isset( $retained_record['file'] ) && (string) $retained_record['file'] === $file ) {
				return;
			}
		}

		self::delete_tracked_file( self::get_upload_file_path( $file ) );
	}

	/**
	 * Resolve a relative uploads path without allowing traversal outside uploads.
	 *
	 * @param mixed $file Relative uploads path.
	 * @return string
	 */
	private static function get_upload_file_path( $file ) {
		$file = is_scalar( $file ) ? wp_normalize_path( ltrim( (string) $file, '/' ) ) : '';

		if ( $file === '' || strpos( $file, "\0" ) !== false || preg_match( '#(^|/)\.\.(/|$)#', $file ) ) {
			return '';
		}

		$uploads   = wp_get_upload_dir();
		$basedir   = isset( $uploads['basedir'] ) ? self::normalize_file_path( $uploads['basedir'] ) : '';
		$candidate = $basedir !== '' ? self::normalize_file_path( path_join( $basedir, $file ) ) : '';

		return $candidate !== '' && self::get_upload_relative_path( $candidate ) !== '' ? $candidate : '';
	}

	/**
	 * Normalize a filesystem path for reliable ownership comparisons.
	 *
	 * @param mixed $file File path.
	 * @return string
	 */
	private static function normalize_file_path( $file ) {
		return is_scalar( $file ) && (string) $file !== '' ? wp_normalize_path( (string) $file ) : '';
	}

	/**
	 * Delete a tracked file only when its resolved location is inside uploads.
	 *
	 * @param mixed $file Absolute file path.
	 * @return bool
	 */
	private static function delete_tracked_file( $file ) {
		$file = self::normalize_file_path( $file );

		if ( $file === '' || ! is_file( $file ) ) {
			return false;
		}

		$uploads     = wp_get_upload_dir();
		$basedir     = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		$resolved    = realpath( $file );
		$basedir     = $basedir ? trailingslashit( wp_normalize_path( $basedir ) ) : '';
		$resolved    = $resolved ? wp_normalize_path( $resolved ) : '';
		$inside_path = $basedir !== '' && $resolved !== '' && strpos( $resolved, $basedir ) === 0;

		if ( ! $inside_path ) {
			return false;
		}

		return wp_delete_file_from_directory( $resolved, untrailingslashit( $basedir ) );
	}

	/**
	 * Return the latest recorded backup URL.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function get_latest_backup_url( $attachment_id ) {
		$backups = get_post_meta( $attachment_id, self::META_BACKUPS, true );
		$backups = is_array( $backups ) ? $backups : [];
		$latest  = end( $backups );

		return is_array( $latest ) && ! empty( $latest['url'] ) ? (string) $latest['url'] : '';
	}

	/**
	 * Return supported transform formats with user-facing labels.
	 *
	 * @return array
	 */
	private static function get_supported_output_formats() {
		$formats = [
			'image/webp' => 'WebP',
			'image/avif' => 'AVIF',
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
		];

		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			self::load_image_dependencies();
		}

		return array_filter(
			$formats,
			static function ( $label, $mime ) {
				return function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( [ 'mime_type' => $mime ] );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Return a safe sibling filename for staged or copied output.
	 *
	 * @param string $source    Source file.
	 * @param string $suffix    Filename suffix.
	 * @param string $extension Output extension.
	 * @return string
	 */
	private static function unique_destination( $source, $suffix, $extension ) {
		$directory = dirname( $source );
		$basename  = pathinfo( $source, PATHINFO_FILENAME );
		$filename  = sanitize_file_name( $basename . '-' . $suffix . '.' . ltrim( $extension, '.' ) );

		return trailingslashit( $directory ) . wp_unique_filename( $directory, $filename );
	}

	/**
	 * Copy a local file to an exclusively created, atomically published path.
	 *
	 * @param string     $source           Readable source file.
	 * @param string     $destination      Destination that must not already exist.
	 * @param array|null $created_identity Identity of the exclusively created destination.
	 * @return bool
	 */
	private static function copy_file_exclusive( $source, $destination, &$created_identity = null ) {
		$created_identity = null;

		if (
			! is_file( $source ) ||
			! is_readable( $source ) ||
			self::get_upload_relative_path( $source ) === '' ||
			self::get_upload_relative_path( $destination ) === ''
		) {
			return false;
		}

		$extension = strtolower( pathinfo( $destination, PATHINFO_EXTENSION ) );
		$staged    = self::unique_destination( $destination, 'bricks-copy-stage', $extension !== '' ? $extension : 'tmp' );

		$staged_identity = null;

		if ( ! self::copy_stream_exclusive( $source, $staged, $staged_identity ) ) {
			return false;
		}

		// link() publishes the complete sibling inode only when destination does
		// not exist; unlike rename(), it never overwrites a concurrent winner.
		$published          = false;
		$published_identity = null;

		if ( function_exists( 'link' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_link, WordPress.PHP.NoSilencedErrors.Discouraged -- A failed exclusive publish is an expected race and falls back safely.
			$published = @link( $staged, $destination );

			if ( $published ) {
				$published_identity         = $staged_identity;
				$published_identity['path'] = self::normalize_file_path( $destination );
			}
		}

		// Some hosts disable hard links. An exclusive destination stream remains
		// safe because callers never publish attachment metadata until this complete
		// copy has been closed and hash-verified.
		if ( ! $published ) {
			$published = self::copy_stream_exclusive( $staged, $destination, $published_identity );
		}

		if ( is_array( $staged_identity ) && self::path_matches_file_stat( $staged, $staged_identity ) ) {
			self::delete_tracked_file( $staged );
		}

		if ( ! $published || ! is_array( $published_identity ) || ! self::path_matches_file_stat( $destination, $published_identity ) || ! self::files_match( $source, $destination ) ) {
			// Do not rediscover and delete the destination here. A concurrent process
			// may have replaced the path after our exclusive publication; leaving an
			// uncertain orphan is safer than deleting another owner's file.
			return false;
		}

		$created_identity = $published_identity;

		return true;
	}

	/**
	 * Stream a local upload into a newly and exclusively created destination.
	 *
	 * @param string     $source           Complete source file.
	 * @param string     $destination      Destination that must not already exist.
	 * @param array|null $created_identity Identity of the exclusively created destination.
	 * @return bool
	 */
	private static function copy_stream_exclusive( $source, $destination, &$created_identity = null ) {
		$created_identity = null;

		if (
			! is_file( $source ) ||
			! is_readable( $source ) ||
			self::get_upload_relative_path( $source ) === '' ||
			self::get_upload_relative_path( $destination ) === ''
		) {
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Exclusive stream creation is the portable no-overwrite fallback.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A source can disappear after validation; the action fails closed below.
		$input = @fopen( $source, 'rb' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Losing an exclusive-create race is expected and must not emit a request warning.
		$output = @fopen( $destination, 'x+b' );

		if ( ! is_resource( $input ) || ! is_resource( $output ) ) {
			if ( is_resource( $input ) ) {
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				$output_stat = fstat( $output );
				fclose( $output );

				if ( self::path_matches_file_stat( $destination, $output_stat ) ) {
					self::delete_tracked_file( $destination );
				}
			}

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_copy_to_stream -- Streams one validated local upload into its exclusively owned destination.
		$bytes       = stream_copy_to_stream( $input, $output );
		$flushed     = fflush( $output );
		$output_stat = fstat( $output );
		fclose( $input );
		fclose( $output );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_read_fclose

		if ( $bytes === false || ! $flushed || ! self::path_matches_file_stat( $destination, $output_stat ) || ! self::files_match( $source, $destination ) ) {
			if ( self::path_matches_file_stat( $destination, $output_stat ) ) {
				self::delete_tracked_file( $destination );
			}

			return false;
		}

		$created_identity = [
			'path' => self::normalize_file_path( $destination ),
			'dev'  => $output_stat['dev'],
			'ino'  => $output_stat['ino'],
		];

		return true;
	}

	/**
	 * Check that a path still names the exact file opened by this request.
	 *
	 * @param string     $file          File path.
	 * @param array|bool $expected_stat fstat() result captured from the open handle.
	 * @return bool
	 */
	private static function path_matches_file_stat( $file, $expected_stat ) {
		if ( ! is_array( $expected_stat ) || empty( $expected_stat['ino'] ) || ! isset( $expected_stat['dev'] ) ) {
			return false;
		}

		clearstatcache( true, $file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent rename can make the path disappear between verification and cleanup.
		$current_stat = @stat( $file );

		return is_array( $current_stat ) && (string) $current_stat['dev'] === (string) $expected_stat['dev'] && (string) $current_stat['ino'] === (string) $expected_stat['ino'];
	}

	/**
	 * Atomically replace one attachment path from a complete staged file.
	 *
	 * @param string $source      Readable staged source.
	 * @param string $destination Existing attachment path.
	 * @return bool
	 */
	private static function replace_file_atomically( $source, $destination ) {
		if ( self::get_upload_relative_path( $source ) === '' || self::get_upload_relative_path( $destination ) === '' ) {
			return false;
		}

		$extension = strtolower( pathinfo( $destination, PATHINFO_EXTENSION ) );
		$staged    = self::unique_destination( $destination, 'bricks-replace-stage', $extension !== '' ? $extension : 'tmp' );

		if ( ! self::copy_file_exclusive( $source, $staged ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- A same-directory rename commits complete bytes atomically while the transaction snapshot protects rollback.
		$replaced = rename( $staged, $destination );

		if ( ! $replaced ) {
			self::delete_tracked_file( $staged );

			return false;
		}

		return self::files_match( $source, $destination );
	}

	/**
	 * Compare two readable files after a transactional copy.
	 *
	 * @param string $first  First file.
	 * @param string $second Second file.
	 * @return bool
	 */
	private static function files_match( $first, $second ) {
		if ( ! is_file( $first ) || ! is_readable( $first ) || ! is_file( $second ) || ! is_readable( $second ) ) {
			return false;
		}

		clearstatcache( true, $first );
		clearstatcache( true, $second );
		$first_size  = filesize( $first );
		$second_size = filesize( $second );

		if ( $first_size === false || $second_size === false || $first_size !== $second_size ) {
			return false;
		}

		$first_hash  = hash_file( 'sha256', $first );
		$second_hash = hash_file( 'sha256', $second );

		return is_string( $first_hash ) && is_string( $second_hash ) && hash_equals( $first_hash, $second_hash );
	}

	/**
	 * Return a preferred extension for an image MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private static function mime_extension( $mime ) {
		$extensions = [
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
		];

		$mime_suffix = strrchr( $mime, '/' );
		$mime_suffix = $mime_suffix !== false ? $mime_suffix : '';

		return $extensions[ $mime ] ?? sanitize_key( substr( $mime_suffix, 1 ) );
	}

	/**
	 * Group MIME types for replacement compatibility checks.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private static function mime_bucket( $mime ) {
		$mime               = strtolower( trim( $mime ) );
		$raster_image_mimes = [
			'image/avif',
			'image/bmp',
			'image/gif',
			'image/heic',
			'image/heif',
			'image/jpeg',
			'image/png',
			'image/tiff',
			'image/vnd.microsoft.icon',
			'image/webp',
			'image/x-icon',
			'image/x-ms-bmp',
			'image/x-tiff',
		];

		if ( strpos( $mime, 'font/' ) === 0 || in_array( $mime, [ 'application/font-woff', 'application/vnd.ms-fontobject', 'application/x-font-ttf', 'application/x-font-opentype' ], true ) ) {
			return 'font';
		}

		if ( in_array( $mime, $raster_image_mimes, true ) ) {
			return 'image-raster';
		}

		if ( in_array( $mime, [ 'image/svg+xml', 'image/svg' ], true ) ) {
			return 'image-svg';
		}

		if ( strpos( $mime, 'audio/' ) === 0 ) {
			return 'audio';
		}

		if ( strpos( $mime, 'video/' ) === 0 ) {
			return 'video';
		}

		return $mime !== '' ? 'mime:' . $mime : 'unknown';
	}

	/**
	 * Whether WordPress should generate non-empty image metadata for a MIME type.
	 *
	 * Vector images legitimately have no generated subsize metadata.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private static function mime_requires_generated_metadata( $mime ) {
		return self::mime_bucket( $mime ) === 'image-raster';
	}

	/**
	 * Normalize a successful service result.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $health        Current health result.
	 * @param string $message       Success message.
	 * @param string $backup_url    Optional backup URL.
	 * @return array
	 */
	private static function success_result( $attachment_id, $health, $message, $backup_url = '' ) {
		return [
			'attachmentId'     => $attachment_id,
			'newAttachmentId'  => 0,
			'health'           => $health,
			'newHealth'        => [],
			'message'          => $message,
			'newAttachmentUrl' => '',
			'backupUrl'        => $backup_url,
		];
	}

	/**
	 * Generate metadata without allowing WordPress to replace the attached path.
	 *
	 * Big-image scaling normally updates _wp_attached_file to a new -scaled URL.
	 * These in-place actions promise URL stability, so scaling is disabled only
	 * for the duration of this synchronous generation call.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Current attachment file.
	 * @return array
	 */
	private static function generate_attachment_metadata_preserving_path( $attachment_id, $file ) {
		$filter_added = function_exists( 'add_filter' ) && function_exists( 'remove_filter' );

		if ( $filter_added ) {
			add_filter( 'big_image_size_threshold', '__return_false', PHP_INT_MAX );
		}

		try {
			return wp_generate_attachment_metadata( $attachment_id, $file );
		} finally {
			if ( $filter_added ) {
				remove_filter( 'big_image_size_threshold', '__return_false', PHP_INT_MAX );
			}
		}
	}

	/**
	 * Load WordPress image processing functions.
	 *
	 * @return void
	 */
	private static function load_image_dependencies() {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Load WordPress upload handling functions.
	 *
	 * @return void
	 */
	private static function load_upload_dependencies() {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}
}
