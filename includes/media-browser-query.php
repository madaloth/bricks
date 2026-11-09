<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Query media attachments for the builder Browser popup.
 *
 * Keeps attachment-specific search and filters out of the shared post query.
 *
 * @since 2.4
 */
class Media_Browser_Query {
	const META_INDEX_VERSION = '_bricks_media_metadata_index_version';
	const META_WIDTH         = '_bricks_media_width';
	const META_HEIGHT        = '_bricks_media_height';
	const META_FILESIZE      = '_bricks_media_filesize';

	const INDEX_VERSION           = 1;
	const INDEX_STATE_OPTION      = 'bricks_media_metadata_index_state';
	const INDEX_GENERATION_OPTION = 'bricks_media_metadata_index_generation';
	const INDEX_LOCK              = 'bricks_media_metadata_index_lock';
	const INDEX_BATCH_SIZE        = 25;
	const INDEX_WRITE_ATTEMPTS    = 3;

	/**
	 * Prevent source metadata hooks from reacting to index writes.
	 *
	 * @var bool
	 */
	private static $writing_metadata_index = false;

	/**
	 * Attachment locks already held by this PHP request.
	 *
	 * @var array
	 */
	private static $attachment_index_locks = [];

	/**
	 * Attachment IDs awaiting one request-end metadata index refresh.
	 *
	 * @var array
	 */
	private static $pending_attachment_indexes = [];

	/**
	 * Register hooks that keep numeric attachment metadata queryable.
	 *
	 * @since 2.4
	 */
	public function __construct() {
		add_action( 'add_attachment', [ __CLASS__, 'index_attachment_metadata' ], 20 );
		add_action( 'after_switch_theme', [ __CLASS__, 'invalidate_metadata_index_after_theme_switch' ] );
		add_filter( 'delete_post_metadata', [ __CLASS__, 'handle_global_attachment_meta_delete' ], 10, 5 );
		add_action( 'added_post_meta', [ __CLASS__, 'handle_attachment_meta_change' ], 20, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'handle_attachment_meta_change' ], 20, 4 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'handle_attachment_meta_change' ], 20, 4 );
		add_action( 'shutdown', [ __CLASS__, 'flush_pending_attachment_metadata_indexes' ], 20 );
	}

	/**
	 * Invalidate data that may have changed while Bricks was inactive.
	 *
	 * @return void
	 */
	public static function invalidate_metadata_index_after_theme_switch() {
		self::mark_metadata_index_incomplete();
	}

	/**
	 * Invalidate the complete index when a relevant meta key is deleted globally.
	 *
	 * `delete_post_meta_by_key()` does not identify individual attachments, so
	 * post-change hooks cannot rebuild them. The narrow delete-all prefilter makes
	 * the next metadata-filter request resume a complete bounded scan instead.
	 *
	 * @param mixed  $check      Metadata operation short-circuit value.
	 * @param int    $object_id  Object ID, zero for delete-all operations.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value.
	 * @param bool   $delete_all Whether all matching metadata is being deleted.
	 * @return mixed
	 */
	public static function handle_global_attachment_meta_delete( $check, $object_id, $meta_key, $meta_value = null, $delete_all = false ) {
		if (
			! self::$writing_metadata_index &&
			$delete_all &&
			in_array( $meta_key, [ '_wp_attachment_metadata', '_wp_attached_file' ], true )
		) {
			self::mark_metadata_index_incomplete();
		}

		return $check;
	}

	/**
	 * Run the attachment query.
	 *
	 * @param array  $query_args Base WP_Query arguments.
	 * @param string $search     Search term.
	 * @param array  $filters    Raw media filters.
	 * @return array|\WP_Error
	 */
	public static function run( $query_args, $search = '', $filters = [] ) {
		$filters = self::normalize_filters( $filters );
		$search  = trim( sanitize_text_field( $search ) );

		if ( self::has_metadata_filters( $filters ) && ! self::metadata_index_is_ready() ) {
			return new \WP_Error(
				'media_metadata_index_incomplete',
				__( 'Media metadata is still being indexed. Please wait for the scan to finish.', 'bricks' ),
				[ 'metadataIndex' => self::get_metadata_index_state() ]
			);
		}

		self::apply_query_filters( $query_args, $filters );
		self::apply_attachment_visibility( $query_args );
		$query_args = self::apply_media_library_restrictions( $query_args );

		$wp_query    = self::run_wp_query( $query_args, $search, $filters );
		$page_ids    = array_map( 'intval', $wp_query->posts );
		$total       = (int) $wp_query->found_posts;
		$total_pages = max( 1, (int) $wp_query->max_num_pages );

		return [
			'ids'        => $page_ids,
			'total'      => $total,
			'totalPages' => $total_pages,
			'filters'    => $filters,
		];
	}

	/**
	 * Index one attachment's numeric metadata for bounded database queries.
	 *
	 * The version marker is removed before any value changes and restored only
	 * after every scalar value has been verified. Concurrent queries therefore
	 * never treat a partially updated row set as current.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return true|\WP_Error
	 */
	public static function index_attachment_metadata( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			return new \WP_Error( 'media_metadata_index_attachment_not_found', __( 'Not found', 'bricks' ) );
		}

		$lock = self::acquire_attachment_index_lock( $attachment_id );

		if ( is_wp_error( $lock ) ) {
			self::mark_metadata_index_incomplete();

			return $lock;
		}

		try {
			for ( $attempt = 0; $attempt < self::INDEX_WRITE_ATTEMPTS; ++$attempt ) {
				$source_before = self::get_attachment_index_source_snapshot( $attachment_id );

				if ( is_wp_error( $source_before ) ) {
					self::mark_metadata_index_incomplete();

					return $source_before;
				}

				$metadata = is_array( $source_before['metadata'] ) ? $source_before['metadata'] : [];
				$values   = [
					self::META_WIDTH    => self::normalize_index_value( $metadata['width'] ?? null ),
					self::META_HEIGHT   => self::normalize_index_value( $metadata['height'] ?? null ),
					self::META_FILESIZE => self::get_attachment_index_filesize( $attachment_id, $metadata, $source_before['file'] ),
				];

				self::$writing_metadata_index = true;

				try {
					delete_post_meta( $attachment_id, self::META_INDEX_VERSION );

					if ( get_post_meta( $attachment_id, self::META_INDEX_VERSION, true ) !== '' ) {
						return self::get_attachment_index_write_error();
					}

					foreach ( $values as $meta_key => $value ) {
						if ( $value === null ) {
							delete_post_meta( $attachment_id, $meta_key );
						} else {
							update_post_meta( $attachment_id, $meta_key, $value );
						}

						$stored = get_post_meta( $attachment_id, $meta_key, true );

						if ( ( $value === null && $stored !== '' ) || ( $value !== null && (string) $stored !== (string) $value ) ) {
							return self::get_attachment_index_write_error();
						}
					}

					$source_after = self::get_attachment_index_source_snapshot( $attachment_id );

					if ( is_wp_error( $source_after ) ) {
						self::mark_metadata_index_incomplete();

						return $source_after;
					}

					if ( $source_after !== $source_before ) {
						continue;
					}

					update_post_meta( $attachment_id, self::META_INDEX_VERSION, self::INDEX_VERSION );

					if ( (string) get_post_meta( $attachment_id, self::META_INDEX_VERSION, true ) !== (string) self::INDEX_VERSION ) {
						return self::get_attachment_index_write_error();
					}

					$source_published = self::get_attachment_index_source_snapshot( $attachment_id );

					if ( is_wp_error( $source_published ) ) {
						delete_post_meta( $attachment_id, self::META_INDEX_VERSION );
						self::mark_metadata_index_incomplete();

						return $source_published;
					}

					if ( $source_published !== $source_before ) {
						delete_post_meta( $attachment_id, self::META_INDEX_VERSION );
						continue;
					}

					return true;
				} finally {
					self::$writing_metadata_index = false;
				}
			}

			self::mark_metadata_index_incomplete();

			return new \WP_Error(
				'media_metadata_index_source_changed',
				__( 'Media metadata changed repeatedly while it was being indexed.', 'bricks' )
			);
		} finally {
			self::release_attachment_index_lock( $lock );
		}
	}

	/**
	 * Reindex an attachment after WordPress changes its source metadata.
	 *
	 * @param int    $meta_id       Metadata row ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $meta_key      Metadata key.
	 * @param mixed  $meta_value    Metadata value.
	 * @return void
	 */
	public static function handle_attachment_meta_change( $meta_id, $attachment_id, $meta_key, $meta_value = null ) {
		if (
			self::$writing_metadata_index ||
			! in_array( $meta_key, [ '_wp_attachment_metadata', '_wp_attached_file' ], true ) ||
			get_post_type( $attachment_id ) !== 'attachment'
		) {
			return;
		}

		self::$pending_attachment_indexes[ absint( $attachment_id ) ] = true;
	}

	/**
	 * Reindex each changed attachment once after all metadata writes in the request finish.
	 *
	 * @return void
	 */
	public static function flush_pending_attachment_metadata_indexes() {
		$attachment_ids                   = array_keys( self::$pending_attachment_indexes );
		self::$pending_attachment_indexes = [];

		foreach ( $attachment_ids as $attachment_id ) {
			self::index_attachment_metadata( $attachment_id );
		}
	}

	/**
	 * Process one bounded metadata-index backfill chunk.
	 *
	 * @param int  $cursor Attachment ID after which scanning resumes.
	 * @param bool $force  Whether to restart the complete index.
	 * @return array|\WP_Error
	 */
	public static function scan_metadata_index( $cursor = 0, $force = false ) {
		if ( get_transient( self::INDEX_LOCK ) ) {
			return new \WP_Error( 'media_metadata_index_busy', __( 'The media metadata scan is already running.', 'bricks' ) );
		}

		set_transient( self::INDEX_LOCK, 1, 30 );

		try {
			if ( $force ) {
				if ( ! self::mark_metadata_index_incomplete( time() ) ) {
					return new \WP_Error( 'media_metadata_index_state_write_failed', __( 'Media metadata scan progress could not be saved.', 'bricks' ) );
				}
			}

			$generation = self::get_metadata_index_generation();
			$state      = self::get_metadata_index_state();

			if ( ! empty( $state['complete'] ) ) {
				return [
					'processed'  => 0,
					'nextCursor' => absint( $state['nextCursor'] ?? 0 ),
					'complete'   => true,
					'scan'       => $state,
				];
			}

			if ( empty( $state['startedAt'] ) ) {
				$state['startedAt'] = time();
			}

			if ( ! self::persist_metadata_index_state( $state, $generation ) ) {
				return self::get_metadata_index_persistence_error( $generation );
			}

			// Durable server state is authoritative. A client-supplied cursor may
			// be stale, but must never be able to skip unindexed attachments.
			$cursor = absint( $state['nextCursor'] ?? 0 );

			global $wpdb;

			$sql = "SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A cursor query is required for bounded/resumable indexing and every value is prepared.
			$attachment_ids = $wpdb->get_col( $wpdb->prepare( $sql, 'attachment', absint( $cursor ), self::INDEX_BATCH_SIZE ) );

			if ( ! is_array( $attachment_ids ) || ! empty( $wpdb->last_error ) ) {
				return new \WP_Error( 'media_metadata_index_query_failed', __( 'Media metadata could not be indexed.', 'bricks' ) );
			}

			$attachment_ids = array_map( 'absint', $attachment_ids );
			$processed      = 0;
			$next_cursor    = absint( $cursor );

			foreach ( $attachment_ids as $attachment_id ) {
				$result = self::index_attachment_metadata( $attachment_id );

				if ( is_wp_error( $result ) ) {
					if (
						$result->get_error_code() === 'media_metadata_index_locked' &&
						self::get_metadata_index_generation() !== $generation
					) {
						return self::get_metadata_index_persistence_error( $generation );
					}

					if ( self::get_metadata_index_generation() !== $generation ) {
						return $result;
					}

					$state['nextCursor'] = $next_cursor;

					if ( ! self::persist_metadata_index_state( $state, $generation ) ) {
						return self::get_metadata_index_persistence_error( $generation );
					}

					return $result;
				}

				++$processed;
				$next_cursor = $attachment_id;
			}

			$complete   = $processed < self::INDEX_BATCH_SIZE;
			$started_at = absint( $state['startedAt'] ?? 0 );
			$state      = [
				'version'     => self::INDEX_VERSION,
				'generation'  => $generation,
				'nextCursor'  => $next_cursor,
				'complete'    => $complete,
				'startedAt'   => $started_at ? $started_at : time(),
				'completedAt' => $complete ? time() : 0,
			];

			if ( ! self::persist_metadata_index_state( $state, $generation ) ) {
				return self::get_metadata_index_persistence_error( $generation );
			}

			return [
				'processed'  => $processed,
				'nextCursor' => $next_cursor,
				'complete'   => $complete,
				'scan'       => $state,
			];
		} finally {
			delete_transient( self::INDEX_LOCK );
		}
	}

	/**
	 * Return normalized metadata-index progress.
	 *
	 * @return array
	 */
	public static function get_metadata_index_state() {
		$state      = self::get_uncached_index_option( self::INDEX_STATE_OPTION, [] );
		$generation = self::get_metadata_index_generation();

		if (
			! is_array( $state ) ||
			(int) ( $state['version'] ?? 0 ) !== self::INDEX_VERSION ||
			(string) ( $state['generation'] ?? '' ) !== $generation
		) {
			return self::get_empty_metadata_index_state( 0, $generation );
		}

		return [
			'version'     => self::INDEX_VERSION,
			'generation'  => $generation,
			'nextCursor'  => absint( $state['nextCursor'] ?? 0 ),
			'complete'    => ! empty( $state['complete'] ),
			'startedAt'   => absint( $state['startedAt'] ?? 0 ),
			'completedAt' => absint( $state['completedAt'] ?? 0 ),
		];
	}

	/**
	 * Whether every existing attachment has a current scalar index.
	 *
	 * @return bool
	 */
	private static function metadata_index_is_ready() {
		$state = self::get_metadata_index_state();

		return ! empty( $state['complete'] );
	}

	/**
	 * Return an initial metadata-index state.
	 *
	 * @param int    $started_at Optional scan start timestamp.
	 * @param string $generation Optional index generation token.
	 * @return array
	 */
	private static function get_empty_metadata_index_state( $started_at = 0, $generation = '' ) {
		$generation = $generation ? (string) $generation : self::get_metadata_index_generation();

		return [
			'version'     => self::INDEX_VERSION,
			'generation'  => $generation,
			'nextCursor'  => 0,
			'complete'    => false,
			'startedAt'   => absint( $started_at ),
			'completedAt' => 0,
		];
	}

	/**
	 * Mark a failed incremental write for a complete bounded rescan.
	 *
	 * @param int $started_at Optional scan start timestamp.
	 * @return bool
	 */
	private static function mark_metadata_index_incomplete( $started_at = 0 ) {
		$generation = wp_generate_uuid4();

		update_option( self::INDEX_GENERATION_OPTION, $generation, false );

		if ( (string) self::get_uncached_index_option( self::INDEX_GENERATION_OPTION, '' ) !== $generation ) {
			update_option( self::INDEX_STATE_OPTION, self::get_empty_metadata_index_state( $started_at, $generation ), false );

			return false;
		}

		$state = self::get_empty_metadata_index_state( $started_at, $generation );

		update_option( self::INDEX_STATE_OPTION, $state, false );

		return self::get_uncached_index_option( self::INDEX_STATE_OPTION, [] ) === $state;
	}

	/**
	 * Return the durable generation changed only by index invalidation.
	 *
	 * @return string
	 */
	private static function get_metadata_index_generation() {
		$generation = self::get_uncached_index_option( self::INDEX_GENERATION_OPTION, '' );

		if ( is_string( $generation ) && $generation !== '' ) {
			return $generation;
		}

		$generation = wp_generate_uuid4();

		if ( add_option( self::INDEX_GENERATION_OPTION, $generation, '', false ) ) {
			return $generation;
		}

		$stored = self::get_uncached_index_option( self::INDEX_GENERATION_OPTION, '' );

		return is_string( $stored ) && $stored !== '' ? $stored : $generation;
	}

	/**
	 * Read index coordination state without WordPress's request-local cache.
	 *
	 * Cross-request metadata writers can invalidate a scan while it is running;
	 * direct reads ensure a stale cache cannot let that scan publish completion.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $default     Value returned when the option is absent.
	 * @return mixed
	 */
	private static function get_uncached_index_option( $option_name, $default = false ) {
		global $wpdb;

		$sql = "SELECT option_value
			FROM {$wpdb->options}
			WHERE option_name = %s
			LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Index generation checks must bypass request-local caches to detect concurrent writers.
		$value = $wpdb->get_var( $wpdb->prepare( $sql, $option_name ) );

		return $value === null ? $default : maybe_unserialize( $value );
	}

	/**
	 * Persist scan progress and verify that later requests can resume exactly.
	 *
	 * @param array  $state      Metadata-index scan state.
	 * @param string $generation Generation captured at scan start.
	 * @return bool
	 */
	private static function persist_metadata_index_state( $state, $generation ) {
		if ( (string) self::get_uncached_index_option( self::INDEX_GENERATION_OPTION, '' ) !== $generation ) {
			return false;
		}

		$state['generation'] = $generation;
		update_option( self::INDEX_STATE_OPTION, $state, false );

		$stored = self::get_uncached_index_option( self::INDEX_STATE_OPTION, [] );

		if (
			! is_array( $stored ) ||
			(string) self::get_uncached_index_option( self::INDEX_GENERATION_OPTION, '' ) !== $generation
		) {
			return false;
		}

		return (int) ( $stored['version'] ?? 0 ) === self::INDEX_VERSION &&
			(string) ( $stored['generation'] ?? '' ) === $generation &&
			absint( $stored['nextCursor'] ?? 0 ) === absint( $state['nextCursor'] ?? 0 ) &&
			! empty( $stored['complete'] ) === ! empty( $state['complete'] ) &&
			absint( $stored['startedAt'] ?? 0 ) === absint( $state['startedAt'] ?? 0 ) &&
			absint( $stored['completedAt'] ?? 0 ) === absint( $state['completedAt'] ?? 0 );
	}

	/**
	 * Return a retryable error when a concurrent invalidation changed generation.
	 *
	 * @param string $generation Generation captured at scan start.
	 * @return \WP_Error
	 */
	private static function get_metadata_index_persistence_error( $generation ) {
		$current_generation = self::get_metadata_index_generation();

		if ( $current_generation !== $generation ) {
			$state = self::get_empty_metadata_index_state( 0, $current_generation );
			update_option( self::INDEX_STATE_OPTION, $state, false );

			return new \WP_Error( 'media_metadata_index_invalidated', __( 'Media metadata changed while it was being indexed. The scan will restart.', 'bricks' ) );
		}

		return new \WP_Error( 'media_metadata_index_state_write_failed', __( 'Media metadata scan progress could not be saved.', 'bricks' ) );
	}

	/**
	 * Acquire a database-scoped lock for one attachment index writer.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|\WP_Error
	 */
	private static function acquire_attachment_index_lock( $attachment_id ) {
		global $wpdb;

		$database  = defined( 'DB_NAME' ) ? DB_NAME : '';
		$prefix    = isset( $wpdb->prefix ) ? $wpdb->prefix : '';
		$lock_name = 'bricks_media_' . md5( $database . ':' . $prefix . ':' . absint( $attachment_id ) );

		if ( isset( self::$attachment_index_locks[ $lock_name ] ) ) {
			return new \WP_Error( 'media_metadata_index_locked', __( 'This attachment is already being indexed.', 'bricks' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks serialize concurrent attachment index writers without persistent lock rows.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );

		if ( (string) $acquired !== '1' ) {
			return new \WP_Error( 'media_metadata_index_locked', __( 'This attachment is already being indexed.', 'bricks' ) );
		}

		self::$attachment_index_locks[ $lock_name ] = true;

		return $lock_name;
	}

	/**
	 * Release one attachment index writer lock.
	 *
	 * @param string $lock_name Database advisory lock name.
	 * @return void
	 */
	private static function release_attachment_index_lock( $lock_name ) {
		global $wpdb;

		unset( self::$attachment_index_locks[ $lock_name ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the advisory lock acquired by this request.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	/**
	 * Snapshot the exact stored inputs from which the scalar index is derived.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error
	 */
	private static function get_attachment_index_source_snapshot( $attachment_id ) {
		global $wpdb;

		$sql = "SELECT meta_key, meta_value
			FROM {$wpdb->postmeta}
			WHERE post_id = %d
			AND meta_key IN (%s, %s)
			ORDER BY meta_id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Uncached source reads are required to detect cross-request changes before publishing an index snapshot.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, absint( $attachment_id ), '_wp_attachment_metadata', '_wp_attached_file' ) );

		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'media_metadata_index_source_read_failed', __( 'Media metadata could not be read for indexing.', 'bricks' ) );
		}

		$raw_rows     = [];
		$source_value = [
			'_wp_attachment_metadata' => '',
			'_wp_attached_file'       => '',
		];
		$value_found  = [];

		foreach ( $rows as $row ) {
			$meta_key   = (string) ( $row->meta_key ?? '' );
			$meta_value = (string) ( $row->meta_value ?? '' );

			$raw_rows[] = [ $meta_key, $meta_value ];

			if ( array_key_exists( $meta_key, $source_value ) && empty( $value_found[ $meta_key ] ) ) {
				$source_value[ $meta_key ] = maybe_unserialize( $meta_value );
				$value_found[ $meta_key ]  = true;
			}
		}

		return [
			'raw'      => $raw_rows,
			'metadata' => $source_value['_wp_attachment_metadata'],
			'file'     => $source_value['_wp_attached_file'],
		];
	}

	/**
	 * Invalidate the global index after an attachment scalar write fails.
	 *
	 * @return \WP_Error
	 */
	private static function get_attachment_index_write_error() {
		self::mark_metadata_index_incomplete();

		return new \WP_Error(
			'media_metadata_index_write_failed',
			__( 'Media metadata could not be indexed.', 'bricks' )
		);
	}

	/**
	 * Normalize a non-negative scalar index value.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return int|null
	 */
	private static function normalize_index_value( $value ) {
		return is_numeric( $value ) && (int) $value >= 0 ? (int) $value : null;
	}

	/**
	 * Resolve the original file size without doing filesystem work at query time.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $metadata      Attachment metadata.
	 * @param string $source_file   Stored attached-file value.
	 * @return int|null
	 */
	private static function get_attachment_index_filesize( $attachment_id, $metadata, $source_file ) {
		if ( isset( $metadata['filesize'] ) && $metadata['filesize'] !== false ) {
			return self::normalize_index_value( $metadata['filesize'] );
		}

		$file = self::resolve_attachment_index_file( $attachment_id, $source_file );

		if ( ! is_string( $file ) || $file === '' || ! is_file( $file ) ) {
			return null;
		}

		// Match the legacy filter exactly: missing, null, and false metadata
		// fall back to the source file, while any other present invalid value
		// remains unavailable.
		$file_size = filesize( $file );

		return $file_size === false ? null : max( 0, (int) $file_size );
	}

	/**
	 * Resolve a stored attached-file value without using the postmeta cache.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_file   Stored attached-file value.
	 * @return string|false
	 */
	private static function resolve_attachment_index_file( $attachment_id, $source_file ) {
		$file = is_string( $source_file ) ? $source_file : '';

		if ( $file === '' ) {
			return false;
		}

		if ( ! path_is_absolute( $file ) ) {
			$uploads = wp_get_upload_dir();
			$basedir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';

			if ( $basedir === '' ) {
				return false;
			}

			$file = path_join( $basedir, $file );
		}

		return apply_filters( 'get_attached_file', $file, $attachment_id );
	}

	/**
	 * Return broad media-action capabilities for the current user.
	 *
	 * Object-level edit and delete checks are still returned per attachment.
	 *
	 * @return array
	 */
	public static function get_current_user_capabilities() {
		return [
			'canUpload'      => current_user_can( 'upload_files' ),
			'canEdit'        => current_user_can( self::get_attachment_primitive_cap( 'edit_posts', 'edit_posts' ) ),
			'canDelete'      => current_user_can( self::get_attachment_primitive_cap( 'delete_posts', 'delete_posts' ) ),
			'canAuditHealth' => current_user_can( self::get_attachment_primitive_cap( 'edit_others_posts', 'edit_others_posts' ) ),
		];
	}

	/**
	 * Normalize and validate media filters.
	 *
	 * @param array $filters Raw media filters.
	 * @return array
	 */
	public static function normalize_filters( $filters ) {
		$filters = is_array( $filters ) ? $filters : [];

		$normalized = [
			'mediaType'        => isset( $filters['mediaType'] ) ? sanitize_key( $filters['mediaType'] ) : '',
			'mimeSubtype'      => isset( $filters['mimeSubtype'] ) ? sanitize_mime_type( $filters['mimeSubtype'] ) : '',
			'dateFrom'         => self::normalize_date( $filters['dateFrom'] ?? '' ),
			'dateTo'           => self::normalize_date( $filters['dateTo'] ?? '' ),
			'uploader'         => isset( $filters['uploader'] ) ? absint( $filters['uploader'] ) : 0,
			'attachmentStatus' => isset( $filters['attachmentStatus'] ) ? sanitize_key( $filters['attachmentStatus'] ) : '',
			'minWidth'         => self::normalize_non_negative_integer( $filters['minWidth'] ?? null ),
			'maxWidth'         => self::normalize_non_negative_integer( $filters['maxWidth'] ?? null ),
			'minHeight'        => self::normalize_non_negative_integer( $filters['minHeight'] ?? null ),
			'maxHeight'        => self::normalize_non_negative_integer( $filters['maxHeight'] ?? null ),
			'minFileSizeBytes' => self::normalize_non_negative_integer( $filters['minFileSizeBytes'] ?? null ),
			'maxFileSizeBytes' => self::normalize_non_negative_integer( $filters['maxFileSizeBytes'] ?? null ),
			'missingAlt'       => self::normalize_boolean( $filters['missingAlt'] ?? false ),
			'healthIssue'      => isset( $filters['healthIssue'] ) ? sanitize_key( $filters['healthIssue'] ) : '',
			'folderId'         => self::normalize_folder( $filters['folderId'] ?? '' ),
		];

		if ( ! preg_match( '/^[a-z0-9.+-]+$/', $normalized['mediaType'] ) ) {
			$normalized['mediaType'] = '';
		}

		if ( ! in_array( $normalized['attachmentStatus'], [ '', 'attached', 'unattached' ], true ) ) {
			$normalized['attachmentStatus'] = '';
		}

		if ( ! in_array( $normalized['healthIssue'], [ '', 'all_issues', 'oversized', 'missing_alt', 'broken', 'obsolete_format', 'missing_derivatives', 'decorative', 'healthy' ], true ) ) {
			$normalized['healthIssue'] = '';
		}

		if ( $normalized['missingAlt'] || in_array( $normalized['healthIssue'], [ 'missing_alt', 'missing_derivatives', 'decorative' ], true ) ) {
			$normalized['mediaType'] = 'image';
		}

		if (
			$normalized['mediaType'] &&
			$normalized['mimeSubtype'] &&
			strpos( $normalized['mimeSubtype'], $normalized['mediaType'] . '/' ) !== 0
		) {
			$normalized['mimeSubtype'] = '';
		}

		$selected_media_type = $normalized['mediaType'];

		if ( ! $selected_media_type && $normalized['mimeSubtype'] ) {
			$selected_media_type = strstr( $normalized['mimeSubtype'], '/', true );
		}

		if ( $selected_media_type && ! in_array( $selected_media_type, [ 'image', 'video' ], true ) ) {
			foreach ( [ 'minWidth', 'maxWidth', 'minHeight', 'maxHeight' ] as $key ) {
				$normalized[ $key ] = null;
			}
		}

		return $normalized;
	}

	/**
	 * Return stable media filter options for the current user.
	 *
	 * @return array
	 */
	public static function get_filter_options() {
		global $wpdb;

		$allowed_mime_types = array_values(
			array_filter(
				array_unique( get_allowed_mime_types() ),
				static function( $mime_type ) {
					return strpos( $mime_type, 'audio/' ) === 0 || strpos( $mime_type, 'video/' ) === 0;
				}
			)
		);
		$post_statuses      = self::get_visible_attachment_statuses( true );
		$last_changed       = wp_cache_get( 'last_changed', 'posts' );
		$cache_key          = 'media_browser_filter_options_' . md5( '3|' . $last_changed . '|' . wp_json_encode( $post_statuses ) . '|' . wp_json_encode( $allowed_mime_types ) );
		$cached_options     = wp_cache_get( $cache_key, 'bricks' );

		if ( ! has_filter( 'ajax_query_attachments_args' ) && is_array( $cached_options ) ) {
			return $cached_options;
		}

		if ( has_filter( 'ajax_query_attachments_args' ) ) {
			$rows = self::get_restricted_filter_rows( $post_statuses );
		} else {
			$status_placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
			$sql                 = "SELECT post_mime_type, post_author, post_status, COUNT(*) AS attachment_count FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$status_placeholders}) GROUP BY post_mime_type, post_author, post_status";
			$sql_values          = array_merge( [ 'attachment' ], $post_statuses );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Status placeholders are generated internally; aggregation is cached against the posts last-changed key.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $sql_values ) );
		}

		$media_types       = [];
		$mime_types        = [];
		$uploader_ids      = [];
		$navigation_counts = self::get_media_navigation_counts( $rows );

		foreach ( $allowed_mime_types as $mime_type ) {
			$mime_type = sanitize_mime_type( $mime_type );

			if ( $mime_type ) {
				$mime_types[ $mime_type ] = $mime_type;
			}
		}

		foreach ( $rows as $row ) {
			if ( ( $row->post_status ?? '' ) === 'trash' ) {
				continue;
			}

			$mime_type = sanitize_mime_type( $row->post_mime_type ?? '' );

			if ( $mime_type ) {
				$mime_types[ $mime_type ] = $mime_type;
			}

			$uploader_id = absint( $row->post_author ?? 0 );

			if ( $uploader_id ) {
				$uploader_ids[ $uploader_id ] = $uploader_id;
			}
		}

		foreach ( $mime_types as $mime_type ) {
			$type = strstr( $mime_type, '/', true );

			if ( $type ) {
				$media_types[ $type ] = $type . '/*';
			}
		}

		ksort( $media_types, SORT_NATURAL | SORT_FLAG_CASE );
		ksort( $mime_types, SORT_NATURAL | SORT_FLAG_CASE );

		$uploaders = [];

		foreach ( $uploader_ids as $uploader_id ) {
			$user = get_userdata( $uploader_id );

			if ( $user ) {
				$uploaders[ $uploader_id ] = $user->display_name;
			}
		}

		natcasesort( $uploaders );

		$options = [
			'mediaTypes'      => $media_types,
			'mediaTypeCounts' => $navigation_counts['mediaTypeCounts'],
			'mimeSubtypes'    => $mime_types,
			'trashCount'      => $navigation_counts['trashCount'],
			'uploaders'       => $uploaders,
		];

		// Custom visibility can depend on user or external state absent from the shared cache key.
		if ( ! has_filter( 'ajax_query_attachments_args' ) ) {
			wp_cache_set( $cache_key, $options, 'bricks', HOUR_IN_SECONDS );
		}

		return $options;
	}

	/**
	 * Apply the site's WordPress media-modal policy after caller-supplied filters.
	 *
	 * @param array $query_args Attachment query arguments.
	 * @return array
	 */
	public static function apply_media_library_restrictions( $query_args ) {
		if ( ! defined( 'HAPPYFILES_TAXONOMY' ) ) {
			return apply_filters( 'ajax_query_attachments_args', $query_args );
		}

		// Bricks supplies its own folder tax_query. HappyFiles' all-items sentinel
		// prevents the remembered admin folder from becoming an additional restriction.
		// Keep the media-policy hook active so other plugins can still enforce access.
		$taxonomy                = HAPPYFILES_TAXONOMY;
		$had_folder              = array_key_exists( $taxonomy, $query_args );
		$original_folder         = $query_args[ $taxonomy ] ?? null;
		$query_args[ $taxonomy ] = -2;
		$query_args              = apply_filters( 'ajax_query_attachments_args', $query_args );

		if ( $had_folder ) {
			$query_args[ $taxonomy ] = $original_folder;
		} elseif ( ! array_key_exists( $taxonomy, $query_args ) || $query_args[ $taxonomy ] === -2 ) {
			unset( $query_args[ $taxonomy ] );
		}

		return $query_args;
	}

	/**
	 * Aggregate only attachments admitted by the media-modal policy.
	 *
	 * @param array $post_statuses Attachment statuses to count.
	 * @return array
	 */
	private static function get_restricted_filter_rows( $post_statuses ) {
		global $wpdb;

		$args = self::apply_media_library_restrictions(
			[
				'post_type'      => 'attachment',
				'post_status'    => $post_statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'none',
				'no_found_rows'  => true,
			]
		);

		// Compile the visibility query without loading every matching attachment into PHP.
		$args['fields']        = 'ids';
		$args['no_found_rows'] = true;
		$args['cache_results'] = false;
		$query                 = new \WP_Query();
		$permitted_ids         = null;
		$skip_results          = static function( $posts, $candidate ) use ( $query, &$permitted_ids ) {
			if ( $candidate !== $query ) {
				return $posts;
			}

			if ( $posts === null ) {
				return [];
			}

			// Short-circuit policies can restrict results without changing the generated SQL.
			$permitted_ids = [];
			foreach ( $posts as $post ) {
				$id = (int) ( $post instanceof \WP_Post ? $post->ID : $post );
				if ( $id > 0 ) {
					$permitted_ids[ $id ] = $id;
				}
			}
			$permitted_ids = array_values( $permitted_ids );

			// This query requests IDs, including when a policy supplied WP_Post objects.
			return $permitted_ids;
		};
		add_filter( 'posts_pre_query', $skip_results, PHP_INT_MAX, 2 );

		try {
			$query->query( $args );
		} finally {
			remove_filter( 'posts_pre_query', $skip_results, PHP_INT_MAX );
		}

		if ( $permitted_ids === [] ) {
			return [];
		}

		if ( $permitted_ids !== null ) {
			$id_placeholders = implode( ', ', array_fill( 0, count( $permitted_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only generated integer placeholders are interpolated.
			$sql = $wpdb->prepare( "SELECT post_mime_type, post_author, post_status, COUNT(*) AS attachment_count FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ({$id_placeholders}) GROUP BY post_mime_type, post_author, post_status", $permitted_ids );
		} else {
			// The derived table also supports policies that deliberately limit the eligible set.
			$sql = "SELECT post_mime_type, post_author, post_status, COUNT(*) AS attachment_count FROM {$wpdb->posts} WHERE ID IN (SELECT ID FROM ({$query->request}) AS bricks_visible_media) GROUP BY post_mime_type, post_author, post_status";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- WP_Query prepares the subquery; custom visibility must not reuse site-wide cached counts.
		return $wpdb->get_results( $sql );
	}

	/**
	 * Match normalized filters against attachment metadata.
	 *
	 * @param array    $filters   Normalized media filters.
	 * @param array    $metadata  Attachment metadata.
	 * @param int|bool $file_size Original file size in bytes, or false when unavailable.
	 * @return bool
	 */
	public static function metadata_matches( $filters, $metadata, $file_size ) {
		$metadata  = is_array( $metadata ) ? $metadata : [];
		$width     = isset( $metadata['width'] ) && is_numeric( $metadata['width'] ) ? (int) $metadata['width'] : null;
		$height    = isset( $metadata['height'] ) && is_numeric( $metadata['height'] ) ? (int) $metadata['height'] : null;
		$file_size = is_numeric( $file_size ) ? (int) $file_size : null;

		$comparisons = [
			[
				'filter'   => 'minWidth',
				'value'    => $width,
				'operator' => '>=',
			],
			[
				'filter'   => 'maxWidth',
				'value'    => $width,
				'operator' => '<=',
			],
			[
				'filter'   => 'minHeight',
				'value'    => $height,
				'operator' => '>=',
			],
			[
				'filter'   => 'maxHeight',
				'value'    => $height,
				'operator' => '<=',
			],
			[
				'filter'   => 'minFileSizeBytes',
				'value'    => $file_size,
				'operator' => '>=',
			],
			[
				'filter'   => 'maxFileSizeBytes',
				'value'    => $file_size,
				'operator' => '<=',
			],
		];

		foreach ( $comparisons as $comparison ) {
			$filter_value = $filters[ $comparison['filter'] ] ?? null;

			if ( $filter_value === null ) {
				continue;
			}

			if ( $comparison['value'] === null ) {
				return false;
			}

			if ( $comparison['operator'] === '>=' && $comparison['value'] < $filter_value ) {
				return false;
			}

			if ( $comparison['operator'] === '<=' && $comparison['value'] > $filter_value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Apply database-native media filters.
	 *
	 * @param array $query_args WP_Query arguments passed by reference.
	 * @param array $filters    Normalized media filters.
	 * @return void
	 */
	private static function apply_query_filters( &$query_args, $filters ) {
		$mime_type        = $filters['mimeSubtype'] ? $filters['mimeSubtype'] : $filters['mediaType'];
		$is_bucket_filter = ! $filters['mimeSubtype'] && in_array( $filters['mediaType'], [ 'document', 'font' ], true );

		if ( $mime_type && ! $is_bucket_filter ) {
			$query_args['post_mime_type'] = $mime_type;
		}

		if ( $filters['uploader'] ) {
			$query_args['author'] = $filters['uploader'];
		}

		$folder_provider = Media_Folder_Providers::get_active();

		if ( $folder_provider ) {
			$query_args = $folder_provider->apply_query( $query_args, $filters['folderId'] );
		}

		$date_query = [];

		if ( $filters['dateFrom'] ) {
			$date_query['after'] = self::date_query_value( $filters['dateFrom'] );
		}

		if ( $filters['dateTo'] ) {
			$date_query['before'] = self::date_query_value( $filters['dateTo'] );
		}

		if ( $date_query ) {
			$date_query['inclusive']    = true;
			$query_args['date_query'][] = $date_query;
		}
	}

	/**
	 * Normalize a media folder filter.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Raw folder filter.
	 * @return int|string
	 */
	private static function normalize_folder( $value ) {
		if ( $value === 'unfiled' ) {
			return 'unfiled';
		}

		$value = is_scalar( $value ) ? absint( $value ) : 0;

		return $value > 0 ? $value : '';
	}

	/**
	 * Apply the same attachment-status visibility used by the WordPress media library.
	 *
	 * @param array $query_args WP_Query arguments passed by reference.
	 * @return void
	 */
	private static function apply_attachment_visibility( &$query_args ) {
		$requested_statuses = isset( $query_args['post_status'] ) ? (array) $query_args['post_status'] : [];
		$trash_requested    = in_array( 'trash', $requested_statuses, true );

		if ( $trash_requested && defined( 'MEDIA_TRASH' ) && MEDIA_TRASH ) {
			$query_args['post_status'] = 'trash';
			return;
		}

		$query_args['post_status'] = self::get_visible_attachment_statuses();
	}

	/**
	 * Return attachment statuses visible in the WordPress media library.
	 *
	 * @param bool $include_trash Whether to include the trash status for filter counts.
	 * @return array
	 */
	public static function get_visible_attachment_statuses( $include_trash = false ) {
		$statuses = [ 'inherit' ];

		if ( self::current_user_can_read_private_media() ) {
			$statuses[] = 'private';
		}

		if ( $include_trash && defined( 'MEDIA_TRASH' ) && MEDIA_TRASH ) {
			$statuses[] = 'trash';
		}

		return $statuses;
	}

	/**
	 * Check whether the current user can read private attachments.
	 *
	 * @return bool
	 */
	private static function current_user_can_read_private_media() {
		return current_user_can( self::get_attachment_primitive_cap( 'read_private_posts', 'read_private_posts' ) );
	}

	/**
	 * Resolve one primitive capability from the attachment post type.
	 *
	 * @param string $key      Capability property.
	 * @param string $fallback Fallback capability.
	 * @return string
	 */
	private static function get_attachment_primitive_cap( $key, $fallback ) {
		$post_type = get_post_type_object( 'attachment' );

		return $post_type && isset( $post_type->cap->{$key} ) ? $post_type->cap->{$key} : $fallback;
	}

	/**
	 * Run WP_Query with scoped attachment search filters.
	 *
	 * @param array  $query_args WP_Query arguments.
	 * @param string $search     Search term.
	 * @param array  $filters    Normalized media filters.
	 * @return \WP_Query
	 */
	private static function run_wp_query( $query_args, $search, $filters ) {
		global $wpdb;

		$needs_alt_join     = $search !== '';
		$query_token        = wp_generate_uuid4();
		$health_issue       = class_exists( Media_Browser_Health::class ) && Media_Browser_Health::enabled()
			? $filters['healthIssue']
			: '';
		$health_policy_hash = $health_issue ? Media_Browser_Health::get_policy_hash() : '';

		$query_args['bricks_media_browser_query'] = $query_token;
		$query_args['suppress_filters']           = false;

		$join_filter = static function( $join, $query ) use ( $wpdb, $query_token, $search, $needs_alt_join ) {
			if ( ! $query instanceof \WP_Query || $query->get( 'bricks_media_browser_query' ) !== $query_token ) {
				return $join;
			}

			if ( $search !== '' ) {
				$join .= " LEFT JOIN {$wpdb->postmeta} AS bricks_media_file_meta ON ({$wpdb->posts}.ID = bricks_media_file_meta.post_id AND bricks_media_file_meta.meta_key = '_wp_attached_file')";
			}

			if ( $needs_alt_join ) {
				$join .= " LEFT JOIN {$wpdb->postmeta} AS bricks_media_alt_meta ON ({$wpdb->posts}.ID = bricks_media_alt_meta.post_id AND bricks_media_alt_meta.meta_key = '_wp_attachment_image_alt')";
				$join .= " LEFT JOIN {$wpdb->postmeta} AS bricks_media_legacy_alt_meta ON ({$wpdb->posts}.ID = bricks_media_legacy_alt_meta.post_id AND bricks_media_legacy_alt_meta.meta_key = '_wp_attachment_alt')";
			}

			return $join;
		};

		$where_filter = static function( $where, $query ) use ( $wpdb, $query_token, $search, $filters, $health_issue, $health_policy_hash ) {
			if ( ! $query instanceof \WP_Query || $query->get( 'bricks_media_browser_query' ) !== $query_token ) {
				return $where;
			}

			if ( $search !== '' ) {
				$search_like = '%' . $wpdb->esc_like( $search ) . '%';
				$id_clause   = '';
				$values      = array_fill( 0, 6, $search_like );

				if ( ctype_digit( $search ) && (int) $search > 0 ) {
					$id_clause = " OR {$wpdb->posts}.ID = %d";
					$values[]  = (int) $search;
				}

				$sql = " AND (
					{$wpdb->posts}.post_title LIKE %s
					OR {$wpdb->posts}.post_excerpt LIKE %s
					OR {$wpdb->posts}.post_content LIKE %s
					OR bricks_media_file_meta.meta_value LIKE %s
					OR bricks_media_alt_meta.meta_value LIKE %s
					OR bricks_media_legacy_alt_meta.meta_value LIKE %s
					{$id_clause}
				)";

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is built from fixed clauses and every variable value uses a placeholder.
				$where .= $wpdb->prepare( $sql, $values );
			}

			$where .= self::get_attachment_status_where_clause(
				$filters['attachmentStatus'],
				$wpdb->posts,
				$wpdb->postmeta,
				"{$wpdb->posts}.ID",
				"{$wpdb->posts}.post_parent"
			);

			if ( $filters['missingAlt'] ) {
				$where .= $wpdb->prepare(
					" AND NOT EXISTS (
						SELECT 1
						FROM {$wpdb->postmeta} AS bricks_media_present_alt_meta
						WHERE bricks_media_present_alt_meta.post_id = {$wpdb->posts}.ID
						AND bricks_media_present_alt_meta.meta_key IN (%s, %s)
						AND bricks_media_present_alt_meta.meta_value REGEXP %s
					)",
					'_wp_attachment_image_alt',
					'_wp_attachment_alt',
					'[^[:space:]]'
				);
				$where .= $wpdb->prepare(
					" AND NOT EXISTS (
						SELECT 1
						FROM {$wpdb->postmeta} AS bricks_media_decorative_alt_meta
						WHERE bricks_media_decorative_alt_meta.post_id = {$wpdb->posts}.ID
						AND bricks_media_decorative_alt_meta.meta_key = %s
						AND bricks_media_decorative_alt_meta.meta_value = %s
					)",
					Media_Browser_Health::META_DECORATIVE,
					'1'
				);
			}

			if ( $health_issue ) {
				$where .= $wpdb->prepare(
					" AND EXISTS (
						SELECT 1
						FROM {$wpdb->postmeta} AS bricks_media_health_policy_meta
						WHERE bricks_media_health_policy_meta.post_id = {$wpdb->posts}.ID
						AND bricks_media_health_policy_meta.meta_key = %s
						AND bricks_media_health_policy_meta.meta_value = %s
					)",
					Media_Browser_Health::META_POLICY,
					$health_policy_hash
				);

				if ( $health_issue === 'decorative' ) {
					$where .= $wpdb->prepare(
						" AND EXISTS (
							SELECT 1
							FROM {$wpdb->postmeta} AS bricks_media_health_decorative_meta
							WHERE bricks_media_health_decorative_meta.post_id = {$wpdb->posts}.ID
							AND bricks_media_health_decorative_meta.meta_key = %s
							AND bricks_media_health_decorative_meta.meta_value = %s
						)",
						Media_Browser_Health::META_DECORATIVE,
						'1'
					);
				} elseif ( $health_issue === 'healthy' ) {
					$where .= $wpdb->prepare(
						" AND NOT EXISTS (
							SELECT 1
							FROM {$wpdb->postmeta} AS bricks_media_health_issue_meta
							WHERE bricks_media_health_issue_meta.post_id = {$wpdb->posts}.ID
							AND bricks_media_health_issue_meta.meta_key = %s
						)",
						Media_Browser_Health::META_ISSUE
					);
				} else {
					$issue_value = $health_issue === 'all_issues' ? '' : $health_issue;
					$value_where = $issue_value ? ' AND bricks_media_health_issue_meta.meta_value = %s' : '';
					$sql         = " AND EXISTS (
						SELECT 1
						FROM {$wpdb->postmeta} AS bricks_media_health_issue_meta
						WHERE bricks_media_health_issue_meta.post_id = {$wpdb->posts}.ID
						AND bricks_media_health_issue_meta.meta_key = %s
						{$value_where}
					)";
					$values      = $issue_value
						? [ Media_Browser_Health::META_ISSUE, $issue_value ]
						: [ Media_Browser_Health::META_ISSUE ];

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The optional clause is fixed and every value uses a placeholder.
					$where .= $wpdb->prepare( $sql, $values );
				}
			}

			if ( $filters['mediaType'] === 'document' ) {
				$where .= " AND {$wpdb->posts}.post_mime_type NOT LIKE 'image/%'
					AND {$wpdb->posts}.post_mime_type NOT LIKE 'video/%'
					AND {$wpdb->posts}.post_mime_type NOT LIKE 'audio/%'
					AND {$wpdb->posts}.post_mime_type NOT LIKE 'font/%'
					AND {$wpdb->posts}.post_mime_type NOT LIKE 'application/font-%'
					AND {$wpdb->posts}.post_mime_type NOT LIKE 'application/x-font-%'
					AND {$wpdb->posts}.post_mime_type NOT IN ('application/vnd.ms-fontobject', 'application/vnd.ms-opentype')";
			}

			if ( $filters['mediaType'] === 'font' && ! $filters['mimeSubtype'] ) {
				$where .= self::get_font_mime_where_clause( "{$wpdb->posts}.post_mime_type" );
			}

			if ( self::has_metadata_filters( $filters ) ) {
				$where .= self::get_metadata_filter_where_clause( $filters, "{$wpdb->posts}.ID", $wpdb->postmeta );
			}

			return $where;
		};

		$distinct_filter = static function( $distinct, $query ) use ( $query_token ) {
			if ( ! $query instanceof \WP_Query || $query->get( 'bricks_media_browser_query' ) !== $query_token ) {
				return $distinct;
			}

			return 'DISTINCT';
		};

		add_filter( 'posts_join', $join_filter, 10, 2 );
		add_filter( 'posts_where', $where_filter, 10, 2 );
		add_filter( 'posts_distinct', $distinct_filter, 10, 2 );

		try {
			return new \WP_Query( $query_args );
		} finally {
			remove_filter( 'posts_join', $join_filter, 10 );
			remove_filter( 'posts_where', $where_filter, 10 );
			remove_filter( 'posts_distinct', $distinct_filter, 10 );
		}
	}

	/**
	 * Build the attachment-status condition.
	 *
	 * WordPress does not consistently set an attachment's post_parent when an
	 * existing media item is selected as a featured image. Treat the explicit
	 * `_thumbnail_id` relationship as attached too, matching the relationship
	 * recognized by the media deletion usage check.
	 *
	 * @param string $attachment_status        Attached or unattached filter value.
	 * @param string $posts_table              Fully-qualified posts table.
	 * @param string $postmeta_table           Fully-qualified postmeta table.
	 * @param string $attachment_id_column     Fully-qualified attachment ID column.
	 * @param string $attachment_parent_column Fully-qualified attachment parent column.
	 * @return string
	 */
	private static function get_attachment_status_where_clause( $attachment_status, $posts_table, $postmeta_table, $attachment_id_column, $attachment_parent_column ) {
		if ( ! in_array( $attachment_status, [ 'attached', 'unattached' ], true ) ) {
			return '';
		}

		$featured_image_exists = "EXISTS (
			SELECT 1
			FROM {$postmeta_table} AS bricks_media_featured_image_meta
			INNER JOIN {$posts_table} AS bricks_media_featured_image_post
				ON bricks_media_featured_image_post.ID = bricks_media_featured_image_meta.post_id
			WHERE bricks_media_featured_image_meta.meta_key = '_thumbnail_id'
			AND bricks_media_featured_image_meta.meta_value = CAST({$attachment_id_column} AS CHAR)
			AND bricks_media_featured_image_post.post_type NOT IN ('attachment', 'revision')
			AND bricks_media_featured_image_post.post_status NOT IN ('inherit', 'trash', 'auto-draft')
		)";

		if ( $attachment_status === 'attached' ) {
			return " AND ({$attachment_parent_column} > 0 OR {$featured_image_exists})";
		}

		return " AND {$attachment_parent_column} = 0 AND NOT {$featured_image_exists}";
	}

	/**
	 * Check whether a query needs attachment metadata or file-system values.
	 *
	 * @param array $filters Normalized media filters.
	 * @return bool
	 */
	private static function has_metadata_filters( $filters ) {
		foreach ( [ 'minWidth', 'maxWidth', 'minHeight', 'maxHeight', 'minFileSizeBytes', 'maxFileSizeBytes' ] as $key ) {
			if ( $filters[ $key ] !== null ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build database-native attachment metadata filters.
	 *
	 * Scalar values are maintained when attachment metadata changes and populated
	 * for existing libraries by a resumable scan. The version marker makes all
	 * comparisons use one complete index snapshot. This keeps WP_Query pagination
	 * and totals exact without parsing serialized metadata or touching files here.
	 *
	 * @param array  $filters        Normalized media filters.
	 * @param string $post_id_column Fully-qualified posts ID column.
	 * @param string $postmeta_table Fully-qualified postmeta table.
	 * @return string
	 */
	private static function get_metadata_filter_where_clause( $filters, $post_id_column, $postmeta_table ) {
		$comparisons = [
			'minWidth'         => [ self::META_WIDTH, '>=', 'width_min' ],
			'maxWidth'         => [ self::META_WIDTH, '<=', 'width_max' ],
			'minHeight'        => [ self::META_HEIGHT, '>=', 'height_min' ],
			'maxHeight'        => [ self::META_HEIGHT, '<=', 'height_max' ],
			'minFileSizeBytes' => [ self::META_FILESIZE, '>=', 'filesize_min' ],
			'maxFileSizeBytes' => [ self::META_FILESIZE, '<=', 'filesize_max' ],
		];
		$conditions  = [];

		foreach ( $comparisons as $filter_key => $comparison ) {
			if ( ! isset( $filters[ $filter_key ] ) ) {
				continue;
			}

			list( $meta_key, $operator, $alias_suffix ) = $comparison;

			$conditions[] = "EXISTS (
				SELECT 1
				FROM {$postmeta_table} AS bricks_media_{$alias_suffix}
				WHERE bricks_media_{$alias_suffix}.post_id = {$post_id_column}
				AND bricks_media_{$alias_suffix}.meta_key = '{$meta_key}'
				AND CAST(bricks_media_{$alias_suffix}.meta_value AS UNSIGNED) {$operator} " . (int) $filters[ $filter_key ] . '
			)';
		}

		if ( ! $conditions ) {
			return '';
		}

		array_unshift(
			$conditions,
			"EXISTS (
				SELECT 1
				FROM {$postmeta_table} AS bricks_media_index_version
				WHERE bricks_media_index_version.post_id = {$post_id_column}
				AND bricks_media_index_version.meta_key = '" . self::META_INDEX_VERSION . "'
				AND bricks_media_index_version.meta_value = '" . self::INDEX_VERSION . "'
			)"
		);

		return ' AND ' . implode( "\n\t\t\tAND ", $conditions );
	}

	/**
	 * Map an attachment MIME type to a Library navigation bucket.
	 *
	 * Documents are the complement of the four dedicated media buckets so the
	 * counts and the special document query use the same partition.
	 *
	 * @param string $mime_type Attachment MIME type.
	 * @return string
	 */
	private static function get_media_type_bucket( $mime_type ) {
		foreach ( [ 'image', 'video', 'audio' ] as $type ) {
			if ( strpos( $mime_type, $type . '/' ) === 0 ) {
				return $type;
			}
		}

		if ( self::is_font_mime_type( $mime_type ) ) {
			return 'font';
		}

		return 'document';
	}

	/**
	 * Whether a MIME type represents a modern or legacy WordPress font upload.
	 *
	 * @param string $mime_type Attachment MIME type.
	 * @return bool
	 */
	private static function is_font_mime_type( $mime_type ) {
		return strpos( $mime_type, 'font/' ) === 0 ||
			strpos( $mime_type, 'application/font-' ) === 0 ||
			strpos( $mime_type, 'application/x-font-' ) === 0 ||
			in_array( $mime_type, [ 'application/vnd.ms-fontobject', 'application/vnd.ms-opentype' ], true );
	}

	/**
	 * Build the scoped SQL condition for the broad Fonts navigation bucket.
	 *
	 * The column name is supplied only from the internal WordPress posts table.
	 *
	 * @param string $column Fully qualified post MIME type column.
	 * @return string
	 */
	private static function get_font_mime_where_clause( $column ) {
		return " AND (
			{$column} LIKE 'font/%'
			OR {$column} LIKE 'application/font-%'
			OR {$column} LIKE 'application/x-font-%'
			OR {$column} IN ('application/vnd.ms-fontobject', 'application/vnd.ms-opentype')
		)";
	}

	/**
	 * Count Library and Trash attachments from grouped database rows.
	 *
	 * @param array $rows Rows containing MIME type, status, and attachment count.
	 * @return array
	 */
	private static function get_media_navigation_counts( $rows ) {
		$media_type_counts = [
			'all'      => 0,
			'image'    => 0,
			'video'    => 0,
			'audio'    => 0,
			'font'     => 0,
			'document' => 0,
		];
		$trash_count       = 0;

		foreach ( $rows as $row ) {
			$attachment_count = absint( $row->attachment_count ?? 0 );

			if ( ( $row->post_status ?? '' ) === 'trash' ) {
				$trash_count += $attachment_count;
				continue;
			}

			$type = self::get_media_type_bucket( sanitize_mime_type( $row->post_mime_type ?? '' ) );

			$media_type_counts['all']   += $attachment_count;
			$media_type_counts[ $type ] += $attachment_count;
		}

		return [
			'mediaTypeCounts' => $media_type_counts,
			'trashCount'      => $trash_count,
		];
	}

	/**
	 * Normalize a YYYY-MM-DD date.
	 *
	 * @param mixed $value Date value.
	 * @return string
	 */
	private static function normalize_date( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return '';
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $value : '';
	}

	/**
	 * Convert a normalized date to a WP_Date_Query value.
	 *
	 * @param string $date Date value.
	 * @return array
	 */
	private static function date_query_value( $date ) {
		$parts = array_map( 'intval', explode( '-', $date ) );

		return [
			'year'  => $parts[0],
			'month' => $parts[1],
			'day'   => $parts[2],
		];
	}

	/**
	 * Normalize a non-negative integer while preserving an unset value.
	 *
	 * @param mixed $value Numeric value.
	 * @return int|null
	 */
	private static function normalize_non_negative_integer( $value ) {
		if ( $value === null || $value === '' || ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Normalize common boolean request values.
	 *
	 * @param mixed $value Boolean value.
	 * @return bool
	 */
	private static function normalize_boolean( $value ) {
		return in_array( $value, [ true, 1, '1', 'true', 'yes', 'on' ], true );
	}
}
