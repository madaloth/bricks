<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Bulk management services for Builder media attachments.
 *
 * @since 2.4
 */
class Media_Browser_Bulk {
	const TOKEN_TTL             = 900;
	const ARCHIVE_RECORD_TTL    = 86400;
	const MAX_ITEMS             = 10000;
	const MAX_ARCHIVE_ITEMS     = 100;
	const MAX_ARCHIVE_BYTES     = 1073741824;
	const MAX_PREFLIGHT_DETAILS = 25;
	const MAX_USAGE_SOURCES     = 50;
	const USAGE_SCAN_BATCH_SIZE = 250;
	const MAX_USAGE_SCAN_ROWS   = 10000;
	const MAX_USAGE_VALUE_BYTES = 8388608;
	const MAX_USAGE_TOTAL_BYTES = 33554432;
	const MAX_USAGE_REFERENCES  = 10000;
	const USAGE_LOOKUP_LIMIT    = 25;
	const USAGE_VERSION_OPTION  = 'bricks_media_usage_version';

	/**
	 * Whether persisting the usage revision failed during this request.
	 *
	 * @var bool
	 */
	private static $usage_version_write_failed = false;

	/**
	 * Last usage revision persisted by this request.
	 *
	 * @var string
	 */
	private static $usage_version_current = '';

	/**
	 * Whether a destructive check observed the request's latest revision.
	 *
	 * @var bool
	 */
	private static $usage_version_observed = false;

	/**
	 * Register media-folder providers and the archive cleanup hook.
	 *
	 * @since 2.4
	 */
	public function __construct() {
		Media_Folder_Providers::bootstrap();
		add_action( 'bricks_media_browser_cleanup_archive', [ __CLASS__, 'cleanup_archive' ] );
		add_action( 'added_post_meta', [ __CLASS__, 'handle_usage_meta_change' ], 10, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'handle_usage_meta_change' ], 10, 4 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'handle_usage_meta_change' ], 10, 4 );
		add_filter( 'update_post_metadata_by_mid', [ __CLASS__, 'handle_usage_meta_update_by_mid' ], 10, 4 );
		add_action( 'added_option', [ __CLASS__, 'handle_usage_option_change' ], 10, 2 );
		add_action( 'updated_option', [ __CLASS__, 'handle_usage_option_change' ], 10, 3 );
		add_action( 'deleted_option', [ __CLASS__, 'handle_usage_option_change' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'handle_usage_status_change' ], 10, 3 );
		add_action( 'post_updated', [ __CLASS__, 'handle_usage_post_update' ], 10, 3 );
	}

	/**
	 * Invalidate destructive-action confirmations when reference-bearing meta changes.
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $object_id  Source post ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value.
	 * @return void
	 */
	public static function handle_usage_meta_change( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id, $meta_value );

		$post_type = get_post_type( $object_id );

		if (
			! in_array( $post_type, [ 'attachment', 'revision' ], true ) &&
			in_array( $meta_key, self::get_usage_meta_keys(), true )
		) {
			self::bump_usage_version();
		}
	}

	/**
	 * Invalidate confirmations before update-by-ID can rename a relevant meta key.
	 *
	 * @param mixed       $check      Metadata-operation short-circuit value.
	 * @param int         $meta_id    Metadata row ID.
	 * @param mixed       $meta_value New metadata value.
	 * @param string|bool $meta_key  New metadata key, or false to retain it.
	 * @return mixed
	 */
	public static function handle_usage_meta_update_by_mid( $check, $meta_id, $meta_value, $meta_key = false ) {
		unset( $meta_value );

		$metadata = get_metadata_by_mid( 'post', $meta_id );

		if ( ! $metadata || ! isset( $metadata->post_id, $metadata->meta_key ) ) {
			return $check;
		}

		$post_type = get_post_type( $metadata->post_id );
		$new_key   = $meta_key === false ? (string) $metadata->meta_key : (string) $meta_key;

		if (
			! in_array( $post_type, [ 'attachment', 'revision' ], true ) &&
			(
				in_array( (string) $metadata->meta_key, self::get_usage_meta_keys(), true ) ||
				in_array( $new_key, self::get_usage_meta_keys(), true )
			)
		) {
			self::bump_usage_version();
		}

		return $check;
	}

	/**
	 * Invalidate destructive-action confirmations when global Bricks data changes.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Previous value.
	 * @param mixed  $value  Current value.
	 * @return void
	 */
	public static function handle_usage_option_change( $option, $old = null, $value = null ) {
		unset( $old, $value );

		if ( in_array( $option, self::get_usage_option_keys(), true ) ) {
			self::bump_usage_version();
		}
	}

	/**
	 * Invalidate confirmations when a source enters or leaves a scanned status.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Updated post.
	 * @return void
	 */
	public static function handle_usage_status_change( $new_status, $old_status, $post ) {
		if (
			$new_status !== $old_status &&
			$post instanceof \WP_Post &&
			! in_array( $post->post_type, [ 'attachment', 'revision' ], true )
		) {
			self::bump_usage_version();
		}
	}

	/**
	 * Invalidate confirmations when a post enters or leaves the scanned post types.
	 *
	 * @param int      $post_id     Updated post ID.
	 * @param \WP_Post $post_after  Updated post.
	 * @param \WP_Post $post_before Previous post.
	 * @return void
	 */
	public static function handle_usage_post_update( $post_id, $post_after, $post_before ) {
		unset( $post_id );

		if (
			$post_after instanceof \WP_Post &&
			$post_before instanceof \WP_Post &&
			$post_after->post_type !== $post_before->post_type
		) {
			self::bump_usage_version();
		}
	}

	/**
	 * Advance the media-usage revision after reference-bearing data changes.
	 *
	 * Public so storage integrations that bypass standard WordPress metadata or
	 * option APIs can explicitly invalidate pending destructive confirmations.
	 *
	 * @return string|\WP_Error New usage revision, or a persistence error.
	 */
	public static function bump_usage_version() {
		if ( self::$usage_version_current !== '' && ! self::$usage_version_observed ) {
			return self::$usage_version_current;
		}

		$next = wp_generate_uuid4();

		if ( ! update_option( self::USAGE_VERSION_OPTION, $next, false ) ) {
			self::$usage_version_write_failed = true;

			return self::usage_version_error();
		}

		$stored = self::get_usage_version( false );

		if ( is_wp_error( $stored ) || $stored !== $next ) {
			self::$usage_version_write_failed = true;

			return is_wp_error( $stored ) ? $stored : self::usage_version_error();
		}

		self::$usage_version_current  = $next;
		self::$usage_version_observed = false;

		return $next;
	}

	/**
	 * Return the current media-usage revision.
	 *
	 * @param bool $track_observation Whether a destructive check is reading this revision.
	 * @return string|\WP_Error
	 */
	private static function get_usage_version( $track_observation = true ) {
		if ( self::$usage_version_write_failed ) {
			return self::usage_version_error();
		}

		$version = self::read_uncached_option( self::USAGE_VERSION_OPTION );

		if ( is_wp_error( $version ) ) {
			return $version;
		}

		if ( $version === null ) {
			$initial = wp_generate_uuid4();
			$added   = add_option( self::USAGE_VERSION_OPTION, $initial, '', false );
			$version = self::read_uncached_option( self::USAGE_VERSION_OPTION );

			if ( is_wp_error( $version ) || $version === null || ( $added && $version !== $initial ) ) {
				return is_wp_error( $version ) ? $version : self::usage_version_error();
			}
		}

		if ( ! is_string( $version ) || $version === '' ) {
			return self::usage_version_error();
		}

		if ( $track_observation ) {
			self::$usage_version_current  = $version;
			self::$usage_version_observed = true;
		}

		return $version;
	}

	/**
	 * Read an option directly so destructive checks cannot observe stale cache data.
	 *
	 * @param string $option_name Option name.
	 * @return string|null|\WP_Error Raw option value, null when missing, or a read error.
	 */
	private static function read_uncached_option( $option_name ) {
		$descriptor = self::get_uncached_option_descriptor( $option_name );

		if ( is_wp_error( $descriptor ) || $descriptor === null ) {
			return $descriptor;
		}

		if ( $descriptor['valueBytes'] > self::MAX_USAGE_VALUE_BYTES ) {
			return self::usage_version_error();
		}

		return self::fetch_uncached_option_value( $descriptor );
	}

	/**
	 * Read only an option's identity and stored byte length.
	 *
	 * @param string $option_name Option name.
	 * @return array|null|\WP_Error Length descriptor, null when missing, or a read error.
	 */
	private static function get_uncached_option_descriptor( $option_name ) {
		global $wpdb;

		if (
			! isset( $wpdb->options ) ||
			! method_exists( $wpdb, 'prepare' ) ||
			! method_exists( $wpdb, 'get_row' )
		) {
			return self::usage_version_error();
		}

		$sql = "SELECT option_id, OCTET_LENGTH(option_value) AS value_bytes
			FROM {$wpdb->options}
			WHERE option_name = %s
			LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safety reads must bypass the object cache; the option name is prepared and only the bounded descriptor is returned.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $option_name ) );

		if ( ! empty( $wpdb->last_error ) ) {
			return self::usage_version_error();
		}

		if ( $row === null ) {
			return null;
		}

		$option_id   = absint( $row->option_id ?? 0 );
		$value_bytes = self::get_usage_byte_length( $row->value_bytes ?? null );

		if ( ! $option_id || $value_bytes === null ) {
			return self::usage_version_error();
		}

		return [
			'optionId'   => $option_id,
			'valueBytes' => $value_bytes,
		];
	}

	/**
	 * Fetch an option only while its stored length still matches a safe descriptor.
	 *
	 * @param array $descriptor Option identity and expected byte length.
	 * @return string|\WP_Error Raw option value or a read error.
	 */
	private static function fetch_uncached_option_value( $descriptor ) {
		global $wpdb;

		$option_id   = absint( $descriptor['optionId'] ?? 0 );
		$value_bytes = self::get_usage_byte_length( $descriptor['valueBytes'] ?? null );

		if ( ! $option_id || $value_bytes === null ) {
			return self::usage_version_error();
		}

		$sql = "SELECT option_id, OCTET_LENGTH(option_value) AS value_bytes,
			CASE WHEN OCTET_LENGTH(option_value) = %d THEN option_value ELSE NULL END AS option_value
			FROM {$wpdb->options}
			WHERE option_id = %d
			LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The expected safe length and row ID are prepared; CASE prevents PHP from receiving a value that grew after the descriptor read.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, [ $value_bytes, $option_id ] ) );

		if (
			! empty( $wpdb->last_error ) ||
			! is_object( $row ) ||
			absint( $row->option_id ?? 0 ) !== $option_id ||
			self::get_usage_byte_length( $row->value_bytes ?? null ) !== $value_bytes ||
			! is_string( $row->option_value ?? null )
		) {
			return self::usage_version_error();
		}

		return $row->option_value;
	}

	/**
	 * Return postmeta keys that can contain attachment references.
	 *
	 * @return array
	 */
	private static function get_usage_meta_keys() {
		$keys = [ '_thumbnail_id' ];

		foreach ( [ 'BRICKS_DB_PAGE_HEADER', 'BRICKS_DB_PAGE_CONTENT', 'BRICKS_DB_PAGE_FOOTER', 'BRICKS_DB_PAGE_SETTINGS', 'BRICKS_DB_TEMPLATE_SETTINGS', 'BRICKS_DB_CUSTOM_FONT_FACES' ] as $constant_name ) {
			if ( defined( $constant_name ) ) {
				$keys[] = constant( $constant_name );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Return global option keys that can contain attachment references.
	 *
	 * @return array
	 */
	private static function get_usage_option_keys() {
		$keys = [];

		foreach ( [ 'BRICKS_DB_GLOBAL_CLASSES', 'BRICKS_DB_THEME_STYLES', 'BRICKS_DB_COMPONENTS', 'BRICKS_DB_CUSTOM_ICONS', 'BRICKS_DB_GLOBAL_ELEMENTS' ] as $constant_name ) {
			if ( defined( $constant_name ) ) {
				$keys[] = constant( $constant_name );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Return the current folder provider context for a Browser post type.
	 *
	 * @since 2.4
	 *
	 * @param string $post_type WordPress post type.
	 * @return array
	 */
	public static function get_folder_provider( $post_type = 'attachment' ) {
		return Media_Folder_Providers::get_context( $post_type );
	}

	/**
	 * Whether recoverable media trash is available.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	public static function trash_enabled() {
		return defined( 'MEDIA_TRASH' ) && MEDIA_TRASH &&
			defined( 'EMPTY_TRASH_DAYS' ) && (int) EMPTY_TRASH_DAYS > 0;
	}

	/**
	 * Return folders from the active provider for a Browser post type.
	 *
	 * @since 2.4
	 *
	 * @param string $post_type WordPress post type.
	 * @return array
	 */
	public static function get_folders( $post_type = 'attachment' ) {
		$provider = Media_Folder_Providers::get_active( $post_type );

		return $provider ? $provider->get_folders() : [];
	}

	/**
	 * Return usage details for one attachment remediation preflight.
	 *
	 * This public wrapper keeps the expensive site scan on demand and strips the
	 * internal per-attachment lookup maps used by bulk destructive actions.
	 *
	 * @since 2.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error
	 */
	public static function get_attachment_usage_summary( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return [
				'referenceCount' => 0,
				'usedOnSources'  => []
			];
		}

		$usage   = self::get_usage_summary( [ $attachment_id ] );
		$sources = [];

		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		foreach ( $usage['usedOnSources'] ?? [] as $source ) {
			$reference_count = absint( $source['attachmentReferenceCounts'][ $attachment_id ] ?? 0 );

			if ( ! $reference_count ) {
				continue;
			}

			unset( $source['attachmentReferenceCounts'] );
			$source['referenceCount'] = $reference_count;
			$sources[]                = $source;
		}

		return [
			'referenceCount' => absint( $usage['byAttachment'][ $attachment_id ] ?? 0 ),
			'usedOnSources'  => $sources,
		];
	}

	/**
	 * Create, rename, move, reorder, or delete a media folder.
	 *
	 * @since 2.4
	 *
	 * @param array $input Request input.
	 * @return array|\WP_Error
	 */
	public static function manage_folder( $input ) {
		$post_type = is_array( $input ) && isset( $input['postType'] ) && is_scalar( $input['postType'] )
			? sanitize_key( wp_unslash( $input['postType'] ) )
			: 'attachment';
		$provider  = Media_Folder_Providers::get_active( $post_type );

		if ( ! $provider ) {
			return new \WP_Error( 'media_folder_provider_unavailable', __( 'No media folder provider is available.', 'bricks' ) );
		}

		return $provider->manage_folder( is_array( $input ) ? wp_unslash( $input ) : [] );
	}

	/**
	 * Resolve and snapshot a bulk selection.
	 *
	 * @since 2.4
	 *
	 * @param array  $selection Selection descriptor.
	 * @param string $operation Bulk operation being prepared.
	 * @return array|\WP_Error
	 */
	public static function prepare_selection( $selection, $operation = '' ) {
		$mode        = isset( $selection['mode'] ) ? sanitize_key( $selection['mode'] ) : 'explicit';
		$operation   = sanitize_key( $operation );
		$media_view  = isset( $selection['mediaView'] ) && sanitize_key( $selection['mediaView'] ) === 'trash' ? 'trash' : 'library';
		$query_total = null;

		if ( $mode === 'query' ) {
			$resolved = self::resolve_query_selection( $selection );

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$ids         = $resolved['ids'];
			$query_total = $resolved['total'];
		} else {
			$ids = isset( $selection['ids'] ) && is_array( $selection['ids'] ) ? $selection['ids'] : [];
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ( $query_total !== null && $query_total > self::MAX_ITEMS ) || count( $ids ) > self::MAX_ITEMS ) {
			return new \WP_Error(
				'media_bulk_too_many_items',
				// translators: %s: Maximum number of media items.
				sprintf( __( 'Select no more than %s media items at once.', 'bricks' ), number_format_i18n( self::MAX_ITEMS ) )
			);
		}

		$excluded_ids = isset( $selection['excludedIds'] ) && is_array( $selection['excludedIds'] )
			? array_map( 'absint', $selection['excludedIds'] )
			: [];
		$ids          = array_values( array_diff( $ids, $excluded_ids ) );

		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		$ids = array_values(
			array_filter(
				$ids,
				static function( $attachment_id ) {
					return get_post_type( $attachment_id ) === 'attachment' && current_user_can( 'edit_post', $attachment_id );
				}
			)
		);

		if ( empty( $ids ) ) {
			return new \WP_Error( 'media_bulk_empty_selection', __( 'Select at least one media item.', 'bricks' ) );
		}

		$usage_version = $operation === 'delete' ? self::get_usage_version() : '';

		if ( is_wp_error( $usage_version ) ) {
			return $usage_version;
		}

		$preflight = self::get_preflight_summary( $ids, $operation );

		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		if ( $operation === 'delete' ) {
			$current_usage_version = self::get_usage_version();

			if ( is_wp_error( $current_usage_version ) ) {
				return $current_usage_version;
			}

			if ( $usage_version !== $current_usage_version ) {
				return self::usage_changed_error();
			}
		}

		$token = str_replace( '-', '', wp_generate_uuid4() );
		$key   = self::selection_key( $token );

		set_transient(
			$key,
			[
				'userId'              => get_current_user_id(),
				'ids'                 => $ids,
				'operation'           => $operation,
				'mediaView'           => $media_view,
				'usageReferenceCount' => (int) ( $preflight['usage']['referenceCount'] ?? 0 ),
				'usageVersion'        => $usage_version,
			],
			self::TOKEN_TTL
		);

		return [
			'token'     => $token,
			'count'     => count( $ids ),
			'folder'    => self::get_folder_summary( $ids ),
			'metadata'  => self::get_metadata_summary( $ids ),
			'preflight' => $preflight,
		];
	}

	/**
	 * Read another bounded page of confirmation details from the frozen selection.
	 *
	 * @param string $token Prepared selection token.
	 * @param int    $cursor Offset into the selection.
	 * @return array|\WP_Error
	 */
	public static function get_selection_details( $token, $cursor = 0 ) {
		$selection = self::get_selection( $token );
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}

		$ids = array_slice( $selection['ids'], max( 0, (int) $cursor ), self::MAX_PREFLIGHT_DETAILS );
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new \WP_Error( 'media_bulk_not_allowed', __( 'Not allowed', 'bricks' ) );
			}
		}
		$summary = self::get_preflight_summary( $ids, $selection['operation'] );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		return [
			'details'    => $summary['details'] ?? [],
			'nextCursor' => max( 0, (int) $cursor ) + count( $ids ),
			'total'      => count( $selection['ids'] ),
		];
	}

	/**
	 * Process one chunk of a prepared selection.
	 *
	 * @since 2.4
	 *
	 * @param string $token     Selection token.
	 * @param string $operation Bulk operation.
	 * @param int    $cursor    Zero-based cursor.
	 * @param array  $options   Operation options.
	 * @return array|\WP_Error
	 */
	public static function process( $token, $operation, $cursor = 0, $options = [] ) {
		$selection = self::get_selection( $token );

		if ( is_wp_error( $selection ) ) {
			return $selection;
		}

		$operation  = sanitize_key( $operation );
		$operations = [ 'metadata', 'move', 'regenerate', 'trash', 'restore', 'delete', 'download' ];

		if ( ! in_array( $operation, $operations, true ) ) {
			return new \WP_Error( 'media_bulk_operation_invalid', __( 'Invalid media bulk operation.', 'bricks' ) );
		}

		if ( in_array( $operation, [ 'trash', 'restore', 'delete' ], true ) && ( $selection['operation'] ?? '' ) !== $operation ) {
			return new \WP_Error(
				'media_bulk_preflight_required',
				__( 'Media safety checks have changed. Reload the Builder, select the media again, and retry.', 'bricks' )
			);
		}

		$media_view = $selection['mediaView'] ?? 'library';

		if (
			( $operation === 'trash' && $media_view !== 'library' ) ||
			( $operation === 'restore' && $media_view !== 'trash' ) ||
			( $operation === 'delete' && self::trash_enabled() && $media_view !== 'trash' )
		) {
			return new \WP_Error( 'media_bulk_operation_view_mismatch', __( 'The selected media location does not allow this action.', 'bricks' ) );
		}

		if ( $operation === 'delete' && empty( $options['confirmed'] ) ) {
			return new \WP_Error( 'media_bulk_delete_confirmation_required', __( 'Confirm permanent deletion before continuing.', 'bricks' ) );
		}

		if (
			in_array( $operation, [ 'trash', 'delete' ], true ) &&
			! empty( $selection['usageReferenceCount'] ) &&
			empty( $options['usageConfirmed'] )
		) {
			return new \WP_Error( 'media_bulk_usage_confirmation_required', __( 'Confirm that you reviewed the media usage warning before continuing.', 'bricks' ) );
		}

		$batch_size = $operation === 'regenerate' ? 5 : ( $operation === 'download' ? 10 : 25 );
		$cursor     = max( 0, absint( $cursor ) );
		$ids        = $selection['ids'];
		$total      = count( $ids );
		$chunk      = array_slice( $ids, $cursor, $batch_size );
		$archive    = null;

		if ( $operation === 'delete' ) {
			$usage_validation = self::validate_usage_version( $selection );

			if ( is_wp_error( $usage_validation ) ) {
				return $usage_validation;
			}
		}

		if ( $operation === 'download' ) {
			$archive = self::get_or_create_archive( $token, $ids, $options );

			if ( is_wp_error( $archive ) ) {
				return $archive;
			}
		}

		$items         = [];
		$usage_changed = false;

		foreach ( $chunk as $attachment_id ) {
			if ( $operation === 'metadata' ) {
				$result = self::update_metadata( $attachment_id, $options );
			} elseif ( $operation === 'move' ) {
				$result = self::move_attachment( $attachment_id, $options );
			} elseif ( $operation === 'regenerate' ) {
				$result = self::regenerate_attachment( $attachment_id );
			} elseif ( $operation === 'trash' ) {
				$result = self::trash_attachment( $attachment_id );
			} elseif ( $operation === 'restore' ) {
				$result = self::restore_attachment( $attachment_id );
			} elseif ( $operation === 'delete' ) {
				$result = self::delete_attachment( $attachment_id, $selection );
			} else {
				$result = self::add_attachment_to_archive( $attachment_id, $archive );
			}

			if ( is_wp_error( $result ) ) {
				if ( empty( $items ) ) {
					return $result;
				}

				$usage_changed = true;
				break;
			}

			if ( ! empty( $result['_usageChanged'] ) ) {
				$usage_changed = true;
				unset( $result['_usageChanged'] );
			}

			$items[] = array_merge( [ 'id' => $attachment_id ], $result );

			if ( $usage_changed ) {
				break;
			}
		}

		$next_cursor = $cursor + count( $items );
		$done        = ! $usage_changed && $next_cursor >= $total;
		$response    = [
			'processed'  => min( $next_cursor, $total ),
			'total'      => $total,
			'succeeded'  => count(
				array_filter(
					$items,
					static function( $item ) {
						return $item['status'] === 'success'; }
				)
			),
			'skipped'    => count(
				array_filter(
					$items,
					static function( $item ) {
						return $item['status'] === 'skipped'; }
				)
			),
			'failed'     => count(
				array_filter(
					$items,
					static function( $item ) {
						return $item['status'] === 'error'; }
				)
			),
			'nextCursor' => $done ? null : $next_cursor,
			'done'       => $done,
			'items'      => $items,
		];

		if ( $usage_changed ) {
			$response['usageChanged'] = true;
		}

		if ( $operation === 'download' ) {
			$archive                   = self::save_archive( $archive );
			$response['downloadToken'] = $archive['token'];

			if ( $done ) {
				$response['downloadUrl'] = add_query_arg(
					[
						'action'   => 'bricks_download_media_archive',
						'token'    => $archive['token'],
						'_wpnonce' => wp_create_nonce( 'bricks-media-archive-' . $archive['token'] ),
					],
					admin_url( 'admin-ajax.php' )
				);
			}
		}

		if ( $usage_changed ) {
			$selection['usageVersion'] = '';
		}

		set_transient( self::selection_key( $token ), $selection, self::TOKEN_TTL );

		return $response;
	}

	/**
	 * Stream a prepared archive and remove it after use.
	 *
	 * @since 2.4
	 *
	 * @param string $token Download token.
	 * @return \WP_Error|void
	 */
	public static function stream_archive( $token ) {
		$token   = sanitize_key( $token );
		$archive = get_transient( self::archive_key( $token ) );

		if ( ! is_array( $archive ) || (int) ( $archive['userId'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'media_archive_expired', __( 'This media download has expired.', 'bricks' ) );
		}

		if ( empty( $archive['expiresAt'] ) || time() > (int) $archive['expiresAt'] ) {
			self::cleanup_archive( $token );

			return new \WP_Error( 'media_archive_expired', __( 'This media download has expired.', 'bricks' ) );
		}

		$path = self::get_valid_archive_path( $archive['path'] ?? '' );

		if ( ! $path || ! is_readable( $path ) ) {
			return new \WP_Error( 'media_archive_missing', __( 'The media archive is no longer available.', 'bricks' ) );
		}

		delete_transient( self::archive_key( $token ) );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="bricks-media-' . gmdate( 'Y-m-d-His' ) . '.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- Streams a local, validated, one-time archive without loading it into memory.
		wp_delete_file( $path );
		exit;
	}

	/**
	 * Remove an expired temporary archive.
	 *
	 * @since 2.4
	 *
	 * @param string $token Download token.
	 * @return void
	 */
	public static function cleanup_archive( $token ) {
		$archive = get_transient( self::archive_key( sanitize_key( $token ) ) );

		$path = is_array( $archive ) ? self::get_valid_archive_path( $archive['path'] ?? '' ) : '';

		if ( $path ) {
			wp_delete_file( $path );
		}

		delete_transient( self::archive_key( sanitize_key( $token ) ) );
	}

	/**
	 * Resolve an archive path only when it is a regular file inside WordPress' temporary directory.
	 *
	 * @param mixed $path Stored archive path.
	 * @return string
	 */
	private static function get_valid_archive_path( $path ) {
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}

		$resolved_path = realpath( $path );
		$temp_dir      = realpath( get_temp_dir() );

		if ( ! $resolved_path || ! $temp_dir || ! is_file( $resolved_path ) ) {
			return '';
		}

		$resolved_path = wp_normalize_path( $resolved_path );
		$temp_dir      = trailingslashit( wp_normalize_path( $temp_dir ) );

		return strpos( $resolved_path, $temp_dir ) === 0 ? $resolved_path : '';
	}

	/**
	 * Resolve a query-mode selection.
	 *
	 * @param array $selection Selection descriptor.
	 * @return array|\WP_Error
	 */
	private static function resolve_query_selection( $selection ) {
		$filters             = isset( $selection['filters'] ) && is_array( $selection['filters'] ) ? $selection['filters'] : [];
		$search              = isset( $selection['search'] ) ? sanitize_text_field( $selection['search'] ) : '';
		$media_view          = isset( $selection['mediaView'] ) && sanitize_key( $selection['mediaView'] ) === 'trash' ? 'trash' : 'library';
		$folder_id           = $selection['folderId'] ?? '';
		$filters['folderId'] = $folder_id;

		$result = Media_Browser_Query::run(
			[
				'posts_per_page' => self::MAX_ITEMS + 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Resolve only enough IDs to enforce the explicit bulk-action safety limit.
				'paged'          => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => $media_view === 'trash' ? 'trash' : [ 'inherit', 'private' ],
				'post_type'      => 'attachment',
				'fields'         => 'ids',
				'no_found_rows'  => false,
			],
			$search,
			$filters
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || ! isset( $result['ids'] ) || ! is_array( $result['ids'] ) ) {
			return new \WP_Error( 'media_bulk_query_failed', __( 'Media items could not be selected. Try again.', 'bricks' ) );
		}

		return [
			'ids'   => $result['ids'],
			'total' => max( count( $result['ids'] ), absint( $result['total'] ?? 0 ) ),
		];
	}

	/**
	 * Return common/mixed metadata for the selection.
	 *
	 * @param array $ids Attachment IDs.
	 * @return array
	 */
	private static function get_metadata_summary( $ids ) {
		$values      = [];
		$image_count = 0;

		foreach ( $ids as $attachment_id ) {
			$post = get_post( $attachment_id );

			if ( ! $post ) {
				continue;
			}

			if ( wp_attachment_is_image( $attachment_id ) ) {
				$image_count++;
			}

			$current = [
				'title'       => $post->post_title,
				'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'caption'     => $post->post_excerpt,
				'description' => $post->post_content,
			];

			if ( wp_attachment_is_image( $attachment_id ) ) {
				$current['decorative'] = (bool) get_post_meta( $attachment_id, Media_Browser_Health::META_DECORATIVE, true );
			}

			foreach ( $current as $key => $value ) {
				if ( ! isset( $values[ $key ] ) ) {
					$values[ $key ] = [
						'value' => $value,
						'mixed' => false
					];
				} elseif ( $values[ $key ]['value'] !== $value ) {
					$values[ $key ]['mixed'] = true;
				}
			}
		}

		$values['imageCount'] = $image_count;

		return $values;
	}

	/**
	 * Return the current folder when exactly one item has one assignment.
	 *
	 * Multiple selections and items assigned to multiple folders remain mixed so
	 * the Move dialog does not imply one current destination.
	 *
	 * @param array $ids Attachment IDs.
	 * @return array
	 */
	private static function get_folder_summary( $ids ) {
		$summary = [
			'value' => 0,
			'mixed' => true,
		];

		if ( count( $ids ) !== 1 ) {
			return $summary;
		}

		$provider = Media_Folder_Providers::get_active();

		if ( ! $provider instanceof Media_Folder_Item_Provider ) {
			return $summary;
		}

		$folder_ids = $provider->get_item_folder_ids( reset( $ids ) );

		if ( is_wp_error( $folder_ids ) ) {
			return $summary;
		}

		$folder_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $folder_ids ) ) ) );

		if ( count( $folder_ids ) > 1 ) {
			return $summary;
		}

		return [
			'value' => $folder_ids ? reset( $folder_ids ) : 0,
			'mixed' => false,
		];
	}

	/**
	 * Return immutable action details, capability eligibility, and media usage.
	 *
	 * @param array  $ids       Attachment IDs.
	 * @param string $operation Bulk operation.
	 * @return array|\WP_Error
	 */
	private static function get_preflight_summary( $ids, $operation ) {
		if ( ! in_array( $operation, [ 'trash', 'restore', 'delete' ], true ) ) {
			return [];
		}

		$usage          = in_array( $operation, [ 'trash', 'delete' ], true ) ? self::get_usage_summary( $ids ) : self::empty_usage_summary();
		$details        = [];
		$eligible_count = 0;
		$ready_count    = 0;
		$in_use_count   = 0;

		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		foreach ( $ids as $attachment_id ) {
			$status   = get_post_status( $attachment_id );
			$eligible = current_user_can( 'delete_post', $attachment_id );

			if ( $operation === 'trash' ) {
				$eligible = $eligible && self::trash_enabled() && $status !== 'trash';
			} elseif ( $operation === 'restore' ) {
				$eligible = $eligible && $status === 'trash';
			} elseif ( self::trash_enabled() ) {
				$eligible = $eligible && $status === 'trash';
			}

			if ( $eligible ) {
				$eligible_count++;
			}

			$usage_count = (int) ( $usage['byAttachment'][ $attachment_id ] ?? 0 );

			if ( $usage_count > 0 ) {
				$in_use_count++;
			} elseif ( $eligible ) {
				$ready_count++;
			}

			if ( count( $details ) < self::MAX_PREFLIGHT_DETAILS ) {
				$attachment_usage_sources = [];
				$visible_reference_count  = 0;

				foreach ( $usage['usedOnSources'] as $source ) {
					$source_reference_count = (int) ( $source['attachmentReferenceCounts'][ $attachment_id ] ?? 0 );

					if ( $source_reference_count < 1 ) {
						continue;
					}

					$source['referencePaths'] = array_values( $source['attachmentReferencePaths'][ $attachment_id ] ?? [] );
					unset( $source['attachmentReferenceCounts'], $source['attachmentReferencePaths'] );
					$source['attachmentIds']    = [ $attachment_id ];
					$source['referenceCount']   = $source_reference_count;
					$attachment_usage_sources[] = $source;
					$visible_reference_count   += $source_reference_count;
				}

				$details[] = array_merge(
					self::get_attachment_snapshot( $attachment_id ),
					[
						'eligible'              => $eligible,
						'usageCount'            => $usage_count,
						'usageSources'          => $attachment_usage_sources,
						'usageSourcesTruncated' => $visible_reference_count < $usage_count,
					]
				);
			}
		}

		unset( $usage['byAttachment'] );

		foreach ( $usage['usedOnSources'] as &$source ) {
			unset( $source['attachmentReferenceCounts'], $source['attachmentReferencePaths'] );
		}
		unset( $source );

		return [
			'operation'        => $operation,
			'eligibleCount'    => $eligible_count,
			'readyCount'       => $ready_count,
			'inUseCount'       => $in_use_count,
			'skippedCount'     => count( $ids ) - $eligible_count,
			'details'          => $details,
			'detailsTruncated' => count( $ids ) > count( $details ),
			'usage'            => $usage,
		];
	}

	/**
	 * Scan current Bricks data and featured-image assignments for media usage.
	 *
	 * @param array $ids Attachment IDs.
	 * @return array|\WP_Error
	 */
	private static function get_usage_summary( $ids ) {
		$ids_lookup = array_fill_keys( array_map( 'absint', $ids ), true );
		$summary    = self::empty_usage_summary();
		$meta_keys  = array_values( array_diff( self::get_usage_meta_keys(), [ '_thumbnail_id' ] ) );
		$budget     = self::create_usage_budget();

		$post_usage = self::collect_post_usage( $ids_lookup, $meta_keys, $budget );

		if ( is_wp_error( $post_usage ) ) {
			return $post_usage;
		}

		foreach ( $post_usage as $post_id => $post_references ) {
			$source_counts          = $post_references['counts'];
			$source_reference_paths = $post_references['referencePaths'] ?? [];
			$bricks_reference_count = $post_references['bricksReferenceCount'];

			$post_type        = get_post_type( $post_id );
			$post_type_object = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post_type ) : null;
			$can_read_post    = current_user_can( 'read_post', $post_id );
			$permalink        = function_exists( 'get_permalink' ) && $can_read_post ? get_permalink( $post_id ) : '';
			$template_slug    = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
			$builder_url      = '';

			if (
				$can_read_post &&
				$bricks_reference_count > 0 &&
				class_exists( Capabilities::class ) &&
				class_exists( Helpers::class ) &&
				Capabilities::current_user_can_use_builder( $post_id )
			) {
				$builder_url = Helpers::get_builder_edit_link( $post_id );
			}

			self::add_usage_source(
				$summary,
				$source_counts,
				[
					'id'                       => (int) $post_id,
					'title'                    => $can_read_post ? get_the_title( $post_id ) : '',
					'type'                     => $post_type,
					'typeLabel'                => $post_type_object->labels->singular_name ?? $post_type,
					'kind'                     => 'post',
					'builderUrl'               => $builder_url,
					'frontendUrl'              => $post_type === $template_slug ? '' : ( $permalink ? $permalink : '' ),
					'attachmentReferencePaths' => $can_read_post ? $source_reference_paths : [],
				]
			);
		}

		$global_sources = [
			'BRICKS_DB_GLOBAL_CLASSES'  => [
				'type'  => 'global-class',
				'label' => __( 'Global class', 'bricks' ),
			],
			'BRICKS_DB_THEME_STYLES'    => [
				'type'  => 'theme-style',
				'label' => __( 'Theme style', 'bricks' ),
			],
			'BRICKS_DB_COMPONENTS'      => [
				'type'  => 'component',
				'label' => __( 'Component', 'bricks' ),
			],
			'BRICKS_DB_CUSTOM_ICONS'    => [
				'type'  => 'custom-icon',
				'label' => __( 'Custom icon', 'bricks' ),
			],
			'BRICKS_DB_GLOBAL_ELEMENTS' => [
				'type'  => 'global-element',
				'label' => __( 'Global element', 'bricks' ),
			],
		];

		foreach ( $global_sources as $constant_name => $source_config ) {
			if ( ! defined( $constant_name ) ) {
				continue;
			}

			$items = self::get_usage_option_value( constant( $constant_name ), $budget );

			if ( is_wp_error( $items ) ) {
				return $items;
			}

			foreach ( is_array( $items ) ? $items : [] as $item_key => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$source_counts          = [];
				$source_reference_paths = [];
				self::collect_attachment_references( $item, $ids_lookup, $source_counts, $constant_name, $source_reference_paths );
				$item_id  = (string) ( $item['id'] ?? $item_key );
				$retained = self::consume_usage_references( $budget, count( $source_counts ) );

				if ( is_wp_error( $retained ) ) {
					return $retained;
				}

				self::add_usage_source(
					$summary,
					$source_counts,
					[
						'id'                       => $item_id,
						'itemId'                   => $item_id,
						'title'                    => self::get_global_usage_title( $source_config['type'], $item, $item_key ),
						'type'                     => $source_config['type'],
						'typeLabel'                => $source_config['label'],
						'kind'                     => 'global',
						'attachmentReferencePaths' => $source_reference_paths,
					]
				);
			}
		}

		$summary['attachmentCount'] = count( array_filter( $summary['byAttachment'] ) );

		return $summary;
	}

	/**
	 * Collect sparse reference counts for posts containing Bricks data.
	 *
	 * Production requests read only the relevant postmeta rows in bounded chunks.
	 * This avoids loading every matching post and its complete metadata cache into
	 * memory before a destructive-action preflight can return.
	 *
	 * @param array $ids_lookup Selected attachment lookup.
	 * @param array $meta_keys  Bricks postmeta keys.
	 * @param array $budget     Request budget passed by reference.
	 * @return array|\WP_Error
	 */
	private static function collect_post_usage( $ids_lookup, $meta_keys, &$budget ) {
		global $wpdb;

		if (
			isset( $wpdb->posts, $wpdb->postmeta ) &&
			method_exists( $wpdb, 'prepare' ) &&
			method_exists( $wpdb, 'get_results' )
		) {
			return self::collect_post_usage_from_database( $ids_lookup, $meta_keys, $budget );
		}

		return self::collect_post_usage_with_query( $ids_lookup, $meta_keys, $budget );
	}

	/**
	 * Collect post usage directly from relevant postmeta rows in bounded chunks.
	 *
	 * @param array $ids_lookup Selected attachment lookup.
	 * @param array $meta_keys  Bricks postmeta keys.
	 * @param array $budget     Request budget passed by reference.
	 * @return array|\WP_Error
	 */
	private static function collect_post_usage_from_database( $ids_lookup, $meta_keys, &$budget ) {
		global $wpdb;

		$post_types = function_exists( 'get_post_types' ) ? get_post_types( [], 'names' ) : [ 'post', 'page' ];
		$post_types = array_values( array_diff( (array) $post_types, [ 'attachment', 'revision' ] ) );
		$meta_keys  = array_values( array_unique( array_merge( $meta_keys, [ '_thumbnail_id' ] ) ) );

		if ( empty( $post_types ) || empty( $meta_keys ) ) {
			return [];
		}

		$meta_placeholders      = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
		$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$post_usage             = [];
		$cursor                 = 0;
		$row_count              = 0;
		$scanned_rows           = 0;
		$candidate_sql          = '';
		$candidate_values       = [];

		if ( count( $ids_lookup ) <= self::USAGE_LOOKUP_LIMIT ) {
			$attachment_ids        = array_keys( $ids_lookup );
			$id_placeholders       = implode( ', ', array_fill( 0, count( $attachment_ids ), '%s' ) );
			$serialized_conditions = [];
			$candidate_values[]    = '_thumbnail_id';

			foreach ( $attachment_ids as $attachment_id ) {
				$candidate_values[] = (string) absint( $attachment_id );
			}

			$candidate_values[] = '_thumbnail_id';

			foreach ( $attachment_ids as $attachment_id ) {
				$attachment_id           = absint( $attachment_id );
				$serialized_conditions[] = 'pm.meta_value LIKE %s';
				$candidate_values[]      = '%i:' . $attachment_id . ';%';
				$serialized_conditions[] = 'pm.meta_value LIKE %s';
				$candidate_values[]      = '%:"' . $attachment_id . '";%';
			}

			$candidate_sql = "
				AND (
					(pm.meta_key = %s AND pm.meta_value IN ({$id_placeholders}))
					OR (pm.meta_key <> %s AND (" . implode( ' OR ', $serialized_conditions ) . '))
				)';
		}

		do {
			$query_limit = min( self::USAGE_SCAN_BATCH_SIZE, self::MAX_USAGE_SCAN_ROWS - $scanned_rows + 1 );
			$sql         = "SELECT pm.meta_id, pm.post_id, pm.meta_key, OCTET_LENGTH(pm.meta_value) AS value_bytes
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_id > %d
				AND pm.meta_key IN ({$meta_placeholders})
				{$candidate_sql}
				AND p.post_type IN ({$post_type_placeholders})
				AND p.post_status NOT IN (%s, %s)
				ORDER BY pm.meta_id ASC
				LIMIT %d";
			$values      = array_merge(
				[ $cursor ],
				$meta_keys,
				$candidate_values,
				$post_types,
				[ 'inherit', 'auto-draft', $query_limit ]
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- A bounded, read-only scan of fixed tables replaces an unbounded post/meta-cache load; every dynamic value is prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

			if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
				return self::usage_scan_error();
			}

			$row_count     = count( $rows );
			$scanned_rows += $row_count;

			if ( $scanned_rows > self::MAX_USAGE_SCAN_ROWS ) {
				return self::usage_scan_limit_error();
			}

			foreach ( $rows as $row ) {
				$cursor     = max( $cursor, absint( $row->meta_id ?? 0 ) );
				$value_size = self::get_usage_byte_length( $row->value_bytes ?? null );

				if ( $value_size === null ) {
					return self::usage_scan_error();
				}

				$value_budget = self::consume_usage_bytes( $budget, $value_size );

				if ( is_wp_error( $value_budget ) ) {
					return $value_budget;
				}
			}

			$value_rows = self::fetch_post_usage_values( $rows );

			if ( is_wp_error( $value_rows ) ) {
				return $value_rows;
			}

			foreach ( $value_rows as $row ) {
				$post_id    = absint( $row->post_id ?? 0 );
				$meta_key   = (string) ( $row->meta_key ?? '' );
				$raw_value  = $row->meta_value;
				$meta_value = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw_value ) : $raw_value;
				$merged     = self::merge_post_reference_value( $post_usage, $post_id, $meta_key, $meta_value, $ids_lookup, $budget, true );

				if ( is_wp_error( $merged ) ) {
					return $merged;
				}
			}

		} while ( $row_count === $query_limit );

		ksort( $post_usage, SORT_NUMERIC );

		return $post_usage;
	}

	/**
	 * Fetch one length-checked postmeta batch without returning oversized values.
	 *
	 * @param array $descriptors Length-only rows from the candidate query.
	 * @return array|\WP_Error
	 */
	private static function fetch_post_usage_values( $descriptors ) {
		global $wpdb;

		if ( empty( $descriptors ) ) {
			return [];
		}

		$conditions = [];
		$ids        = [];
		$expected   = [];
		$values     = [];

		foreach ( $descriptors as $descriptor ) {
			$meta_id     = absint( $descriptor->meta_id ?? 0 );
			$value_bytes = self::get_usage_byte_length( $descriptor->value_bytes ?? null );

			if ( ! $meta_id || $value_bytes === null || isset( $expected[ $meta_id ] ) ) {
				return self::usage_scan_error();
			}

			$conditions[]         = '(pm.meta_id = %d AND OCTET_LENGTH(pm.meta_value) = %d)';
			$values[]             = $meta_id;
			$values[]             = $value_bytes;
			$ids[]                = $meta_id;
			$expected[ $meta_id ] = [
				'postId'     => absint( $descriptor->post_id ?? 0 ),
				'metaKey'    => (string) ( $descriptor->meta_key ?? '' ),
				'valueBytes' => $value_bytes,
			];
		}

		$id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql             = 'SELECT pm.meta_id, pm.post_id, pm.meta_key, OCTET_LENGTH(pm.meta_value) AS value_bytes,
			CASE WHEN ' . implode( ' OR ', $conditions ) . " THEN pm.meta_value ELSE NULL END AS meta_value
			FROM {$wpdb->postmeta} pm
			WHERE pm.meta_id IN ({$id_placeholders})
			ORDER BY pm.meta_id ASC";
		$values          = array_merge( $values, $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IDs and expected byte lengths are prepared; CASE returns no value whose length changed after the descriptor query.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) || count( $rows ) !== count( $descriptors ) ) {
			return self::usage_scan_error();
		}

		$ordered = [];

		foreach ( $rows as $row ) {
			$meta_id     = absint( $row->meta_id ?? 0 );
			$value_bytes = self::get_usage_byte_length( $row->value_bytes ?? null );

			if (
				! isset( $expected[ $meta_id ] ) ||
				isset( $ordered[ $meta_id ] ) ||
				$expected[ $meta_id ]['postId'] !== absint( $row->post_id ?? 0 ) ||
				$expected[ $meta_id ]['metaKey'] !== (string) ( $row->meta_key ?? '' ) ||
				$expected[ $meta_id ]['valueBytes'] !== $value_bytes ||
				! is_string( $row->meta_value ?? null )
			) {
				return self::usage_scan_error();
			}

			$ordered[ $meta_id ] = $row;
		}

		return array_values( $ordered );
	}

	/**
	 * Fallback bounded post query for isolated environments without wpdb methods.
	 *
	 * @param array $ids_lookup Selected attachment lookup.
	 * @param array $meta_keys  Bricks postmeta keys.
	 * @param array $budget     Request budget passed by reference.
	 * @return array|\WP_Error
	 */
	private static function collect_post_usage_with_query( $ids_lookup, $meta_keys, &$budget ) {
		$post_types      = function_exists( 'get_post_types' ) ? get_post_types( [], 'names' ) : [ 'post', 'page' ];
		$post_types      = array_values( array_diff( (array) $post_types, [ 'attachment', 'revision' ] ) );
		$post_statuses   = function_exists( 'get_post_stati' ) ? array_values( get_post_stati( [ 'exclude_from_search' => false ] ) ) : [ 'publish', 'future', 'draft', 'pending', 'private' ];
		$post_statuses   = array_values( array_diff( $post_statuses, [ 'inherit', 'auto-draft' ] ) );
		$post_statuses[] = 'trash';
		$meta_query      = [ 'relation' => 'OR' ];
		$post_usage      = [];
		$post_count      = 0;
		$scanned         = 0;

		foreach ( array_merge( $meta_keys, [ '_thumbnail_id' ] ) as $meta_key ) {
			$meta_query[] = [
				'key'     => $meta_key,
				'compare' => 'EXISTS',
			];
		}

		do {
			$query_limit = min( self::USAGE_SCAN_BATCH_SIZE, self::MAX_USAGE_SCAN_ROWS - $scanned + 1 );
			$post_ids    = empty( $post_types )
				? []
				: get_posts(
					[
						'post_type'              => $post_types,
						'post_status'            => $post_statuses,
						'posts_per_page'         => $query_limit, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Explicitly bounded compatibility batch, not a front-end page size.
						'offset'                 => $scanned,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'suppress_filters'       => true,
						'update_post_meta_cache' => false,
						'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Compatibility fallback; production uses the bounded direct postmeta reader above.
					]
				);

			if ( ! is_array( $post_ids ) ) {
				return self::usage_scan_error();
			}

			$scanned += count( $post_ids );

			if ( $scanned > self::MAX_USAGE_SCAN_ROWS ) {
				return self::usage_scan_limit_error();
			}

			foreach ( $post_ids as $post_id ) {
				foreach ( $meta_keys as $meta_key ) {
					$merged = self::merge_post_reference_value( $post_usage, $post_id, $meta_key, get_post_meta( $post_id, $meta_key, true ), $ids_lookup, $budget );

					if ( is_wp_error( $merged ) ) {
						return $merged;
					}
				}

				$merged = self::merge_post_reference_value( $post_usage, $post_id, '_thumbnail_id', get_post_meta( $post_id, '_thumbnail_id', true ), $ids_lookup, $budget );

				if ( is_wp_error( $merged ) ) {
					return $merged;
				}
			}

			$post_count = count( $post_ids );
		} while ( $post_count === $query_limit );

		ksort( $post_usage, SORT_NUMERIC );

		return $post_usage;
	}

	/**
	 * Merge references from one postmeta value into a sparse per-post map.
	 *
	 * @param array  $post_usage Per-post usage passed by reference.
	 * @param int    $post_id    Source post ID.
	 * @param string $meta_key   Source meta key.
	 * @param mixed  $value      Stored meta value.
	 * @param array  $ids_lookup Selected attachment lookup.
	 * @param array  $budget     Request budget passed by reference.
	 * @param bool   $budgeted   Whether the serialized value was already counted.
	 * @return true|\WP_Error
	 */
	private static function merge_post_reference_value( &$post_usage, $post_id, $meta_key, $value, $ids_lookup, &$budget, $budgeted = false ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return true;
		}

		if ( ! $budgeted ) {
			$value_budget = self::consume_usage_value( $budget, $value );

			if ( is_wp_error( $value_budget ) ) {
				return $value_budget;
			}
		}

		$counts          = [];
		$reference_paths = [];

		if ( $meta_key === '_thumbnail_id' ) {
			$attachment_id = self::get_canonical_attachment_id( $value );

			if ( isset( $ids_lookup[ $attachment_id ] ) ) {
				$counts[ $attachment_id ]          = 1;
				$reference_paths[ $attachment_id ] = [ [ 'featuredImage' ] ];
			}
		} else {
			self::collect_attachment_references( $value, $ids_lookup, $counts, $meta_key, $reference_paths, [], true );
		}

		if ( empty( $counts ) ) {
			return true;
		}

		$new_references = 0;

		foreach ( $counts as $attachment_id => $count ) {
			if ( ! isset( $post_usage[ $post_id ]['counts'][ $attachment_id ] ) ) {
				$new_references++;
			}
		}

		$retained = self::consume_usage_references( $budget, $new_references );

		if ( is_wp_error( $retained ) ) {
			return $retained;
		}

		if ( ! isset( $post_usage[ $post_id ] ) ) {
			$post_usage[ $post_id ] = [
				'counts'               => [],
				'referencePaths'       => [],
				'bricksReferenceCount' => 0,
			];
		}

		self::merge_reference_counts( $post_usage[ $post_id ]['counts'], $counts );
		self::merge_reference_paths( $post_usage[ $post_id ]['referencePaths'], $reference_paths );

		if ( $meta_key !== '_thumbnail_id' ) {
			$post_usage[ $post_id ]['bricksReferenceCount'] += array_sum( $counts );
		}

		return true;
	}

	/**
	 * Merge per-field attachment reference counts into a source total.
	 *
	 * @param array $totals Source totals passed by reference.
	 * @param array $counts Counts found in one field.
	 * @return void
	 */
	private static function merge_reference_counts( &$totals, $counts ) {
		foreach ( $counts as $attachment_id => $count ) {
			$totals[ $attachment_id ] = (int) ( $totals[ $attachment_id ] ?? 0 ) + (int) $count;
		}
	}

	/**
	 * Merge unique per-attachment setting paths into a source total.
	 *
	 * @param array $totals Source paths passed by reference.
	 * @param array $paths  Paths found in one field.
	 * @return void
	 */
	private static function merge_reference_paths( &$totals, $paths ) {
		foreach ( $paths as $attachment_id => $attachment_paths ) {
			$totals[ $attachment_id ] = $totals[ $attachment_id ] ?? [];

			foreach ( $attachment_paths as $path ) {
				if ( ! in_array( $path, $totals[ $attachment_id ], true ) ) {
					$totals[ $attachment_id ][] = $path;
				}
			}
		}
	}

	/**
	 * Return a useful label for one global design-system resource.
	 *
	 * @param string     $type     Global resource type.
	 * @param array      $item     Global resource data.
	 * @param int|string $item_key Resource array key.
	 * @return string
	 */
	private static function get_global_usage_title( $type, $item, $item_key ) {
		if ( $type === 'global-class' ) {
			return (string) ( $item['name'] ?? $item['label'] ?? $item_key );
		}

		if ( $type === 'theme-style' ) {
			return (string) ( $item['label'] ?? $item['name'] ?? $item_key );
		}

		$root_id = (string) ( $item['id'] ?? '' );

		foreach ( is_array( $item['elements'] ?? null ) ? $item['elements'] : [] as $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $root_id ) {
				return (string) ( $element['label'] ?? $element['name'] ?? $item['label'] ?? $root_id );
			}
		}

		$title = (string) ( $item['label'] ?? $item['name'] ?? $root_id );

		return $title !== '' ? $title : (string) $item_key;
	}

	/**
	 * Return the empty media-usage response shape.
	 *
	 * @return array
	 */
	private static function empty_usage_summary() {
		return [
			'attachmentCount'  => 0,
			'referenceCount'   => 0,
			'sourceCount'      => 0,
			'usedOnSources'    => [],
			'sourcesTruncated' => false,
			'byAttachment'     => [],
		];
	}

	/**
	 * Return a fresh parser budget for one usage request.
	 *
	 * @return array
	 */
	private static function create_usage_budget() {
		return [
			'bytes'      => 0,
			'references' => 0,
		];
	}

	/**
	 * Count one serialized metadata or option value against parser byte limits.
	 *
	 * @param array $budget Request budget passed by reference.
	 * @param mixed $value  Stored value.
	 * @return true|\WP_Error
	 */
	private static function consume_usage_value( &$budget, $value ) {
		if ( is_string( $value ) ) {
			$bytes = strlen( $value );
		} elseif ( is_scalar( $value ) || $value === null ) {
			$bytes = strlen( (string) $value );
		} else {
			$encoded = wp_json_encode( $value );

			if ( ! is_string( $encoded ) ) {
				return self::usage_scan_limit_error();
			}

			$bytes = strlen( $encoded );
		}

		return self::consume_usage_bytes( $budget, $bytes );
	}

	/**
	 * Normalize an SQL byte-length result without accepting lossy numeric values.
	 *
	 * @param mixed $value Raw byte length.
	 * @return int|null
	 */
	private static function get_usage_byte_length( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/', $value ) ) {
			return null;
		}

		$length = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			[
				'options' => [ 'min_range' => 0 ],
			]
		);

		return is_int( $length ) ? $length : null;
	}

	/**
	 * Count an already measured stored value against parser byte limits.
	 *
	 * @param array $budget Request budget passed by reference.
	 * @param mixed $bytes  Exact stored byte length.
	 * @return true|\WP_Error
	 */
	private static function consume_usage_bytes( &$budget, $bytes ) {
		$bytes = self::get_usage_byte_length( $bytes );

		if (
			$bytes === null ||
			$bytes > self::MAX_USAGE_VALUE_BYTES ||
			$bytes > self::MAX_USAGE_TOTAL_BYTES - $budget['bytes']
		) {
			return self::usage_scan_limit_error();
		}

		$budget['bytes'] += $bytes;

		return true;
	}

	/**
	 * Bound sparse source-to-attachment entries retained for one response.
	 *
	 * @param array $budget Request budget passed by reference.
	 * @param int   $count  New sparse entries.
	 * @return true|\WP_Error
	 */
	private static function consume_usage_references( &$budget, $count ) {
		$budget['references'] += max( 0, (int) $count );

		return $budget['references'] <= self::MAX_USAGE_REFERENCES
			? true
			: self::usage_scan_limit_error();
	}

	/**
	 * Load one global usage option and budget its raw stored representation.
	 *
	 * @param string $option_name Option name.
	 * @param array  $budget      Request budget passed by reference.
	 * @return mixed|\WP_Error
	 */
	private static function get_usage_option_value( $option_name, &$budget ) {
		$descriptor = self::get_uncached_option_descriptor( $option_name );

		if ( is_wp_error( $descriptor ) ) {
			return self::usage_scan_error();
		}

		if ( $descriptor === null ) {
			return [];
		}

		$consumed = self::consume_usage_bytes( $budget, $descriptor['valueBytes'] );

		if ( is_wp_error( $consumed ) ) {
			return $consumed;
		}

		$value = self::fetch_uncached_option_value( $descriptor );

		if ( is_wp_error( $value ) ) {
			return self::usage_scan_error();
		}

		return function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : $value;
	}

	/**
	 * Return a stable error when media usage cannot be read safely.
	 *
	 * @return \WP_Error
	 */
	private static function usage_scan_error() {
		return new \WP_Error(
			'media_bulk_usage_scan_failed',
			__( 'Media usage could not be verified. No files were changed. Try again.', 'bricks' )
		);
	}

	/**
	 * Return a stable error when a usage scan exceeds its request budget.
	 *
	 * @return \WP_Error
	 */
	private static function usage_scan_limit_error() {
		return new \WP_Error(
			'media_bulk_usage_scan_too_large',
			__( 'Media usage could not be verified within the safety limit. Reduce the selection and try again.', 'bricks' )
		);
	}

	/**
	 * Return a stable error when the uncached usage revision is unavailable.
	 *
	 * @return \WP_Error
	 */
	private static function usage_version_error() {
		return new \WP_Error(
			'media_bulk_usage_version_unavailable',
			__( 'Media usage could not be verified. No files were changed. Try again.', 'bricks' )
		);
	}

	/**
	 * Return the error used when usage changes after confirmation.
	 *
	 * @return \WP_Error
	 */
	private static function usage_changed_error() {
		return new \WP_Error(
			'media_bulk_usage_changed',
			__( 'Media usage changed after deletion was confirmed. Review the updated usage before trying again.', 'bricks' )
		);
	}

	/**
	 * Revalidate the durable usage revision immediately before deletion.
	 *
	 * @param array $selection Prepared selection state.
	 * @return true|\WP_Error
	 */
	private static function validate_usage_version( $selection ) {
		if ( ! isset( $selection['usageVersion'] ) ) {
			return new \WP_Error(
				'media_bulk_preflight_required',
				__( 'Media safety checks have changed. Reload the Builder, select the media again, and retry.', 'bricks' )
			);
		}

		$current = self::get_usage_version();

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		return is_string( $selection['usageVersion'] ) && $selection['usageVersion'] === $current
			? true
			: self::usage_changed_error();
	}

	/**
	 * Recursively collect attachment IDs from Bricks media control values.
	 *
	 * @param mixed  $value         Value to inspect.
	 * @param array  $ids_lookup    Selected attachment lookup.
	 * @param array  $counts        Reference counts passed by reference.
	 * @param string $context       Parent setting key.
	 * @param array  $reference_paths Reference paths grouped by attachment ID.
	 * @param array  $path          Current nested setting path.
	 * @param bool   $include_element Whether to include element labels in paths.
	 * @return void
	 */
	private static function collect_attachment_references( $value, $ids_lookup, &$counts, $context = '', &$reference_paths = null, $path = [], $include_element = false ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( defined( 'BRICKS_DB_CUSTOM_FONT_FACES' ) && $context === BRICKS_DB_CUSTOM_FONT_FACES ) {
			self::collect_font_attachment_references( $value, $ids_lookup, $counts, $reference_paths );
			return;
		}

		if ( $include_element && isset( $value['name'], $value['settings'] ) && is_array( $value['settings'] ) ) {
			$element_title = $value['label'] ?? $value['name'];

			if ( is_scalar( $element_title ) && (string) $element_title !== '' ) {
				$path[] = (string) $element_title;
			}
		}

		$media_context = (bool) preg_match( '/(?:image|media|video|audio|gallery|logo|icon|svg|file|poster|thumbnail|background|mask|woff|ttf|otf|eot)/i', $context );
		$media_shape   = count( array_intersect( [ 'url', 'filename', 'full', 'size', 'source', 'mime', 'mimeType' ], array_keys( $value ) ) ) > 0;
		$counted_id    = 0;

		if ( isset( $value['id'] ) && is_scalar( $value['id'] ) && ( $media_context || $media_shape ) ) {
			$counted_id = self::get_canonical_attachment_id( $value['id'] );

			if ( isset( $ids_lookup[ $counted_id ] ) ) {
				$counts[ $counted_id ] = (int) ( $counts[ $counted_id ] ?? 0 ) + 1;
				self::add_attachment_reference_path( $reference_paths, $counted_id, $path );
			}
		}

		foreach ( $value as $key => $child ) {
			$key = (string) $key;

			if ( $key === 'id' && $counted_id ) {
				continue;
			}

			if ( in_array( $key, [ 'attachmentId', 'attachment_id', '_thumbnail_id' ], true ) && is_scalar( $child ) ) {
				$attachment_id = self::get_canonical_attachment_id( $child );

				if ( isset( $ids_lookup[ $attachment_id ] ) ) {
					$counts[ $attachment_id ] = (int) ( $counts[ $attachment_id ] ?? 0 ) + 1;
					self::add_attachment_reference_path( $reference_paths, $attachment_id, $path );
				}
				continue;
			}

			$child_context = is_numeric( $key ) ? $context : ( $key !== '' ? $key : $context );
			$child_path    = $path;

			if ( ! is_numeric( $key ) && $key !== '' ) {
				$child_path[] = $key;
			}

			self::collect_attachment_references( $child, $ids_lookup, $counts, $child_context, $reference_paths, $child_path, $include_element );
		}
	}

	/**
	 * Collect format-key IDs from legacy font faces and current subset arrays.
	 *
	 * Font descriptors also contain numbers, so only supported format keys are IDs.
	 *
	 * @param array      $faces           Stored custom font faces.
	 * @param array      $ids_lookup      Selected attachment lookup.
	 * @param array      $counts          Reference counts passed by reference.
	 * @param array|null $reference_paths Reference paths grouped by attachment ID.
	 * @return void
	 */
	private static function collect_font_attachment_references( $faces, $ids_lookup, &$counts, &$reference_paths ) {
		$formats = [ 'woff2', 'woff', 'ttf', 'otf', 'eot' ];

		if ( class_exists( Custom_Fonts::class ) ) {
			$formats = array_unique( array_merge( $formats, array_keys( Custom_Fonts::get_public_custom_fonts_mime_types() ) ) );
		}

		foreach ( $faces as $variant => $face ) {
			if ( ! is_array( $face ) ) {
				continue;
			}

			$subsets = isset( $face[0] ) && is_array( $face[0] ) ? $face : [ $face ];

			foreach ( $subsets as $subset ) {
				if ( ! is_array( $subset ) ) {
					continue;
				}

				foreach ( $formats as $format ) {
					$attachment_id = self::get_canonical_attachment_id( $subset[ $format ] ?? null );

					if ( isset( $ids_lookup[ $attachment_id ] ) ) {
						$counts[ $attachment_id ] = (int) ( $counts[ $attachment_id ] ?? 0 ) + 1;
						self::add_attachment_reference_path( $reference_paths, $attachment_id, [ (string) $variant, $format ] );
					}
				}
			}
		}
	}

	/**
	 * Retain one unique setting path for an attachment reference.
	 *
	 * @param array|null $reference_paths Reference paths grouped by attachment ID.
	 * @param int        $attachment_id   Attachment ID.
	 * @param array      $path            Nested setting path.
	 * @return void
	 */
	private static function add_attachment_reference_path( &$reference_paths, $attachment_id, $path ) {
		if ( ! is_array( $reference_paths ) ) {
			return;
		}

		$path = array_values(
			array_filter(
				$path,
				static function( $segment ) {
					return is_string( $segment ) && $segment !== '' && ! in_array( $segment, [ 'settings', 'elements' ], true );
				}
			)
		);

		if ( empty( $path ) ) {
			return;
		}

		$reference_paths[ $attachment_id ] = $reference_paths[ $attachment_id ] ?? [];

		if ( ! in_array( $path, $reference_paths[ $attachment_id ], true ) ) {
			$reference_paths[ $attachment_id ][] = $path;
		}
	}

	/**
	 * Parse only IDs represented by the serialized tokens used by candidate SQL.
	 *
	 * @param mixed $value Potential attachment ID.
	 * @return int Positive canonical ID, or zero when invalid.
	 */
	private static function get_canonical_attachment_id( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return 0;
		}

		$attachment_id = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			[
				'options' => [ 'min_range' => 1 ],
			]
		);

		return is_int( $attachment_id ) ? $attachment_id : 0;
	}

	/**
	 * Merge one post or global resource into the media-usage summary.
	 *
	 * @param array $summary       Usage summary passed by reference.
	 * @param array $source_counts Attachment reference counts for this source.
	 * @param array $source        Source details.
	 * @return void
	 */
	private static function add_usage_source( &$summary, $source_counts, $source ) {
		$source_counts = array_filter( $source_counts );

		if ( empty( $source_counts ) ) {
			return;
		}

		// WordPress titles can contain encoded entities; decode them while the UI still renders them as safe text.
		if ( isset( $source['title'] ) && is_scalar( $source['title'] ) ) {
			$source['title'] = html_entity_decode( (string) $source['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$reference_count            = array_sum( $source_counts );
		$summary['referenceCount'] += $reference_count;
		$summary['sourceCount']++;

		foreach ( $source_counts as $attachment_id => $count ) {
			$summary['byAttachment'][ $attachment_id ] = (int) ( $summary['byAttachment'][ $attachment_id ] ?? 0 ) + $count;
		}

		if ( count( $summary['usedOnSources'] ) < self::MAX_USAGE_SOURCES ) {
			$summary['usedOnSources'][] = array_merge(
				$source,
				[
					'attachmentIds'             => array_map( 'absint', array_keys( $source_counts ) ),
					'attachmentReferenceCounts' => array_map( 'intval', $source_counts ),
					'referenceCount'            => $reference_count,
				]
			);
		} else {
			$summary['sourcesTruncated'] = true;
		}
	}

	/**
	 * Return attachment details captured before a destructive action.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function get_attachment_snapshot( $attachment_id ) {
		$post     = get_post( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$url      = wp_get_attachment_url( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : [];
		$filename = ! empty( $metadata['file'] ) ? wp_basename( $metadata['file'] ) : ( $url ? wp_basename( $url ) : '' );

		return [
			'id'       => $attachment_id,
			'title'    => $post->post_title ?? '',
			'filename' => $filename,
			'url'      => $url ? $url : '',
			'mimeType' => $post->post_mime_type ?? ( $post->mime ?? '' ),
			'status'   => $post->post_status ?? '',
			'width'    => (int) ( $metadata['width'] ?? 0 ),
			'height'   => (int) ( $metadata['height'] ?? 0 ),
			'filesize' => (int) ( $metadata['filesize'] ?? 0 ),
		];
	}

	/**
	 * Get a user-owned selection token.
	 *
	 * @param string $token Selection token.
	 * @return array|\WP_Error
	 */
	private static function get_selection( $token ) {
		$selection = get_transient( self::selection_key( sanitize_key( $token ) ) );

		if ( ! is_array( $selection ) || (int) ( $selection['userId'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'media_bulk_selection_expired', __( 'The media selection has expired. Select the files again.', 'bricks' ) );
		}

		return $selection;
	}

	/**
	 * Update selected attachment metadata.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $options       Operation options.
	 * @return array
	 */
	private static function update_metadata( $attachment_id, $options ) {
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return self::item_result( 'skipped', 'not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		$metadata = isset( $options['metadata'] ) && is_array( $options['metadata'] ) ? $options['metadata'] : [];
		$update   = [ 'ID' => $attachment_id ];
		$changed  = false;

		if ( array_key_exists( 'title', $metadata ) ) {
			$update['post_title'] = sanitize_text_field( $metadata['title'] );
			$changed              = true;
		}

		if ( array_key_exists( 'caption', $metadata ) ) {
			$update['post_excerpt'] = wp_kses_post( $metadata['caption'] );
			$changed                = true;
		}

		if ( array_key_exists( 'description', $metadata ) ) {
			$update['post_content'] = wp_kses_post( $metadata['description'] );
			$changed                = true;
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $result ) ) {
				return self::item_result( 'error', $result->get_error_code(), $result->get_error_message() );
			}
		}

		if ( array_key_exists( 'alt', $metadata ) ) {
			if ( wp_attachment_is_image( $attachment_id ) ) {
				$alt = sanitize_text_field( $metadata['alt'] );
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

				if ( $alt !== '' && class_exists( Media_Browser_Health::class ) ) {
					delete_post_meta( $attachment_id, Media_Browser_Health::META_DECORATIVE );
				}

				$changed = true;
			} elseif ( ! $changed ) {
				return self::item_result( 'skipped', 'alt_not_supported', __( 'Alternative text only applies to images.', 'bricks' ) );
			} else {
				return self::item_result( 'success', 'metadata_updated_alt_skipped', __( 'Metadata updated; alternative text was skipped because this is not an image.', 'bricks' ) );
			}
		}

		if ( array_key_exists( 'decorative', $metadata ) ) {
			if ( wp_attachment_is_image( $attachment_id ) ) {
				$decorative = ! empty( $metadata['decorative'] );

				if ( $decorative ) {
					update_post_meta( $attachment_id, Media_Browser_Health::META_DECORATIVE, 1 );
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' );
				} else {
					delete_post_meta( $attachment_id, Media_Browser_Health::META_DECORATIVE );
				}

				$changed = true;
			} elseif ( ! $changed ) {
				return self::item_result( 'skipped', 'decorative_not_supported', __( 'Decorative classification only applies to images.', 'bricks' ) );
			}
		}

		return self::item_result( 'success', 'metadata_updated', __( 'Metadata updated', 'bricks' ) );
	}

	/**
	 * Move an attachment through the active folder provider.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $options       Operation options.
	 * @return array
	 */
	private static function move_attachment( $attachment_id, $options ) {
		$provider = Media_Folder_Providers::get_active();

		if ( ! $provider ) {
			return self::item_result( 'error', 'media_folder_provider_unavailable', __( 'No media folder provider is available.', 'bricks' ) );
		}

		if ( ! $provider->can_assign() || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return self::item_result( 'skipped', 'not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		$folder_id = isset( $options['folderId'] ) ? absint( $options['folderId'] ) : 0;
		$result    = $provider->assign_attachment( $attachment_id, $folder_id );

		if ( is_wp_error( $result ) ) {
			return self::item_result( 'error', $result->get_error_code(), $result->get_error_message() );
		}

		return self::item_result( 'success', 'media_moved', __( 'Media moved', 'bricks' ) );
	}

	/**
	 * Regenerate one image's attachment metadata and sizes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function regenerate_attachment( $attachment_id ) {
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return self::item_result( 'skipped', 'not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return self::item_result( 'skipped', 'not_image', __( 'Only images can regenerate image sizes.', 'bricks' ) );
		}

		if ( ! class_exists( Media_Browser_Health_Actions::class ) ) {
			return self::item_result( 'error', 'regenerate_unavailable', __( 'Image sizes could not be regenerated safely.', 'bricks' ) );
		}

		$metadata = Media_Browser_Health_Actions::regenerate_attachment_sizes( $attachment_id );

		if ( is_wp_error( $metadata ) ) {
			$code = $metadata->get_error_code();

			return self::item_result(
				$code === 'media_health_action_file_unavailable' ? 'skipped' : 'error',
				$code,
				$metadata->get_error_message()
			);
		}

		if ( class_exists( Media_Browser_Health::class ) ) {
			Media_Browser_Health::audit_attachment( $attachment_id, true );
		}

		return self::item_result( 'success', 'sizes_regenerated', __( 'Image sizes regenerated', 'bricks' ) );
	}

	/**
	 * Move one attachment to Trash.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function trash_attachment( $attachment_id ) {
		if ( ! self::trash_enabled() ) {
			return self::item_result( 'skipped', 'trash_disabled', __( 'Media trash is disabled on this site.', 'bricks' ) );
		}

		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return self::item_result( 'skipped', 'not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		if ( get_post_status( $attachment_id ) === 'trash' ) {
			return self::item_result( 'skipped', 'already_trashed', __( 'Already in Trash', 'bricks' ) );
		}

		return wp_trash_post( $attachment_id )
			? self::item_result( 'success', 'trashed', __( 'Moved to trash', 'bricks' ) )
			: self::item_result( 'error', 'trash_failed', __( 'Media could not be moved to Trash.', 'bricks' ) );
	}

	/**
	 * Restore one attachment from Trash.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function restore_attachment( $attachment_id ) {
		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return self::item_result( 'skipped', 'not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		if ( get_post_status( $attachment_id ) !== 'trash' ) {
			return self::item_result( 'skipped', 'not_trashed', __( 'This media item is not in Trash.', 'bricks' ) );
		}

		return wp_untrash_post( $attachment_id )
			? self::item_result( 'success', 'restored', __( 'Restored', 'bricks' ) )
			: self::item_result( 'error', 'restore_failed', __( 'Media could not be restored.', 'bricks' ) );
	}

	/**
	 * Permanently delete one attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $selection     Prepared delete selection.
	 * @return array|\WP_Error
	 */
	private static function delete_attachment( $attachment_id, $selection ) {
		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return self::item_result( 'skipped', 'not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		if ( self::trash_enabled() && get_post_status( $attachment_id ) !== 'trash' ) {
			return self::item_result( 'skipped', 'delete_requires_trash', __( 'Move this media item to Trash before deleting it permanently.', 'bricks' ) );
		}

		$before_delete = self::get_attachment_snapshot( $attachment_id );
		$validation    = self::validate_usage_version( $selection );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// WordPress offers no transaction spanning attachment deletion and every
		// reference source. Check at the narrowest boundary, then check again so a
		// concurrent invalidation stops every later deletion and is still reported.
		$deleted         = wp_delete_attachment( $attachment_id, true );
		$post_validation = self::validate_usage_version( $selection );
		$result          = $deleted
			? self::item_result( 'success', 'deleted', __( 'Deleted', 'bricks' ), [ 'beforeDelete' => $before_delete ] )
			: self::item_result( 'error', 'delete_failed', __( 'Media could not be deleted.', 'bricks' ), [ 'beforeDelete' => $before_delete ] );

		if ( is_wp_error( $post_validation ) ) {
			$result['_usageChanged'] = true;
		}

		return $result;
	}

	/**
	 * Create or retrieve an archive job.
	 *
	 * @param string $selection_token Selection token.
	 * @param array  $ids             Attachment IDs.
	 * @param array  $options         Operation options.
	 * @return array|\WP_Error
	 */
	private static function get_or_create_archive( $selection_token, $ids, $options ) {
		$download_token = isset( $options['downloadToken'] ) ? sanitize_key( $options['downloadToken'] ) : '';
		$archive        = $download_token ? get_transient( self::archive_key( $download_token ) ) : false;

		if (
			is_array( $archive ) &&
			(int) ( $archive['userId'] ?? 0 ) === get_current_user_id() &&
			( $archive['selectionToken'] ?? '' ) === $selection_token
		) {
			return $archive;
		}

		if ( count( $ids ) > self::MAX_ARCHIVE_ITEMS ) {
			return new \WP_Error(
				'media_archive_too_many_items',
				// translators: %d: Maximum number of files in one media archive.
				sprintf( __( 'A media archive can contain at most %d files.', 'bricks' ), self::MAX_ARCHIVE_ITEMS )
			);
		}

		if ( ! class_exists( '\\ZipArchive' ) ) {
			return new \WP_Error( 'media_archive_zip_unavailable', __( 'ZIP downloads are not available on this server.', 'bricks' ) );
		}

		$files      = [];
		$total_size = 0;

		foreach ( $ids as $attachment_id ) {
			$file = self::get_downloadable_file( $attachment_id );

			if ( is_wp_error( $file ) ) {
				continue;
			}

			$total_size             += filesize( $file );
			$files[ $attachment_id ] = $file;
		}

		if ( $total_size > self::MAX_ARCHIVE_BYTES ) {
			return new \WP_Error( 'media_archive_too_large', __( 'The selected media exceeds the 1 GiB archive limit.', 'bricks' ) );
		}

		if ( empty( $files ) ) {
			return new \WP_Error( 'media_archive_empty', __( 'None of the selected original files are available for download.', 'bricks' ) );
		}

		$path = wp_tempnam( 'bricks-media.zip' );

		if ( ! $path ) {
			return new \WP_Error( 'media_archive_temp_failed', __( 'The temporary media archive could not be created.', 'bricks' ) );
		}

		$download_token = str_replace( '-', '', wp_generate_uuid4() );
		$archive        = [
			'token'          => $download_token,
			'selectionToken' => $selection_token,
			'userId'         => get_current_user_id(),
			'path'           => $path,
			'files'          => $files,
			'names'          => [],
			'started'        => false,
			'expiresAt'      => time() + self::TOKEN_TTL,
		];

		self::save_archive( $archive );
		wp_schedule_single_event( time() + self::TOKEN_TTL, 'bricks_media_browser_cleanup_archive', [ $download_token ] );

		return $archive;
	}

	/**
	 * Add one attachment to an archive.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $archive       Archive state passed by reference.
	 * @return array
	 */
	private static function add_attachment_to_archive( $attachment_id, &$archive ) {
		if ( empty( $archive['files'][ $attachment_id ] ) ) {
			$file = self::get_downloadable_file( $attachment_id );

			return is_wp_error( $file )
				? self::item_result( 'skipped', $file->get_error_code(), $file->get_error_message() )
				: self::item_result( 'skipped', 'file_unavailable', __( 'The original media file is unavailable.', 'bricks' ) );
		}

		$zip  = new \ZipArchive();
		$mode = $archive['started'] ? \ZipArchive::CREATE : \ZipArchive::CREATE | \ZipArchive::OVERWRITE;

		if ( $zip->open( $archive['path'], $mode ) !== true ) {
			return self::item_result( 'error', 'archive_open_failed', __( 'The media archive could not be opened.', 'bricks' ) );
		}

		$file       = $archive['files'][ $attachment_id ];
		$entry_name = self::unique_archive_name( wp_basename( $file ), $archive['names'] );
		$added      = $zip->addFile( $file, $entry_name );
		$zip->close();

		if ( ! $added ) {
			return self::item_result( 'error', 'archive_add_failed', __( 'The file could not be added to the media archive.', 'bricks' ) );
		}

		$archive['started'] = true;
		$archive['names'][] = $entry_name;

		return self::item_result( 'success', 'archive_added', __( 'Added to archive', 'bricks' ) );
	}

	/**
	 * Validate a local original file for download.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|\WP_Error
	 */
	private static function get_downloadable_file( $attachment_id ) {
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		$file       = get_attached_file( $attachment_id );
		$real_file  = $file ? realpath( $file ) : false;
		$upload_dir = wp_get_upload_dir();
		$real_base  = realpath( $upload_dir['basedir'] );

		if ( ! $real_file || ! $real_base || ! is_file( $real_file ) || ! is_readable( $real_file ) ) {
			return new \WP_Error( 'file_unavailable', __( 'The original media file is unavailable.', 'bricks' ) );
		}

		$base_prefix = trailingslashit( $real_base );

		if ( strpos( $real_file, $base_prefix ) !== 0 ) {
			return new \WP_Error( 'file_outside_uploads', __( 'The media file is outside the WordPress uploads directory.', 'bricks' ) );
		}

		return $real_file;
	}

	/**
	 * Return a unique name inside an archive.
	 *
	 * @param string $filename Existing filename.
	 * @param array  $used     Used names.
	 * @return string
	 */
	private static function unique_archive_name( $filename, $used ) {
		$filename = sanitize_file_name( $filename );
		$path     = pathinfo( $filename );
		$name     = $path['filename'] ?? 'media';
		$ext      = ! empty( $path['extension'] ) ? '.' . $path['extension'] : '';
		$result   = $name . $ext;
		$index    = 2;

		while ( in_array( $result, $used, true ) ) {
			$result = $name . '-' . $index . $ext;
			$index++;
		}

		return $result;
	}

	/**
	 * Save archive state.
	 *
	 * @param array $archive Archive state.
	 * @return array
	 */
	private static function save_archive( $archive ) {
		set_transient( self::archive_key( $archive['token'] ), $archive, self::ARCHIVE_RECORD_TTL );

		return $archive;
	}

	/**
	 * Create a consistent per-item result.
	 *
	 * @param string $status  Result status.
	 * @param string $code    Result code.
	 * @param string $message Result message.
	 * @param array  $data    Additional result data.
	 * @return array
	 */
	private static function item_result( $status, $code, $message, $data = [] ) {
		return array_merge(
			[
				'status'  => $status,
				'code'    => $code,
				'message' => $message,
			],
			$data
		);
	}

	/**
	 * Get a user-scoped selection transient key.
	 *
	 * @param string $token Selection token.
	 * @return string
	 */
	private static function selection_key( $token ) {
		return 'bricks_media_bulk_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/**
	 * Get an archive transient key.
	 *
	 * @param string $token Download token.
	 * @return string
	 */
	private static function archive_key( $token ) {
		return 'bricks_media_archive_' . sanitize_key( $token );
	}
}
