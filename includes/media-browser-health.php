<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Audit and index Media Browser attachment health.
 *
 * Findings are stored per attachment so health filters and counts do not need
 * to inspect the filesystem during every media query. A policy hash makes old
 * findings stale whenever registered image sizes or health settings change.
 *
 * @since 2.4
 */
class Media_Browser_Health {
	const META_DECORATIVE = '_bricks_media_decorative';
	const META_ISSUE      = '_bricks_media_health_issue';
	const META_IGNORED    = '_bricks_media_health_ignored_issue';
	const META_DETAILS    = '_bricks_media_health_details';
	const META_POLICY     = '_bricks_media_health_policy';
	const META_CHECKED_AT = '_bricks_media_health_checked_at';

	const SCAN_STATE_OPTION = 'bricks_media_health_scan_state';
	const SCAN_LOCK         = 'bricks_media_health_scan_lock';
	const SCAN_BATCH_SIZE   = 25;
	const POLICY_VERSION    = 1;
	const SUMMARY_CACHE_TTL = 30;

	/**
	 * Normalized policy for the current request.
	 *
	 * @var array|null
	 */
	private static $policy_cache = null;

	/**
	 * Policy hash for the current request.
	 *
	 * @var string|null
	 */
	private static $policy_hash_cache = null;

	/**
	 * Registered image sizes for the current request.
	 *
	 * @var array|null
	 */
	private static $registered_image_sizes_cache = null;

	/**
	 * Summary counts already loaded during the current request.
	 *
	 * @var array
	 */
	private static $summary_counts_cache = [];

	/**
	 * Object-cache keys touched during the current request.
	 *
	 * @var array
	 */
	private static $summary_cache_keys = [];

	/**
	 * Defer repeated summary invalidation while a scan batch is writing.
	 *
	 * @var bool
	 */
	private static $summary_invalidation_suspended = false;

	/**
	 * Whether a deferred summary invalidation is waiting to run.
	 *
	 * @var bool
	 */
	private static $summary_invalidation_pending = false;

	/**
	 * Avoid rewriting scan state repeatedly during one metadata request.
	 *
	 * @var bool
	 */
	private static $scan_marked_incomplete = false;

	/**
	 * Prevent invalidation hooks from reacting to index writes.
	 *
	 * @var bool
	 */
	private static $writing_index = false;

	/**
	 * Register attachment invalidation hooks.
	 *
	 * @since 2.4
	 */
	public function __construct() {
		add_action( 'add_attachment', [ __CLASS__, 'invalidate_attachment' ] );
		add_action( 'edit_attachment', [ __CLASS__, 'invalidate_attachment' ] );
		add_action( 'attachment_updated', [ __CLASS__, 'invalidate_attachment' ] );
		add_action( 'delete_attachment', [ __CLASS__, 'delete_tracked_attachment_files' ], 9 );
		add_action( 'delete_attachment', [ __CLASS__, 'handle_attachment_deleted' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'handle_attachment_status_change' ], 10, 3 );
		add_action( 'added_post_meta', [ __CLASS__, 'handle_attachment_meta_change' ], 10, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'handle_attachment_meta_change' ], 10, 4 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'handle_attachment_meta_change' ], 10, 4 );
	}

	/**
	 * Delete Bricks-managed recovery files when the action handler is available.
	 *
	 * WordPress deletes an uploaded theme ZIP as an attachment after replacing the
	 * theme files. During a downgrade, the installed version may no longer contain
	 * the lazily loaded action handler, while this class remains loaded for the
	 * current request.
	 *
	 * @since 2.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function delete_tracked_attachment_files( $attachment_id ) {
		$callback = [ Media_Browser_Health_Actions::class, 'delete_tracked_attachment_files' ];

		if ( ! is_callable( $callback ) ) {
			return;
		}

		Media_Browser_Health_Actions::delete_tracked_attachment_files( $attachment_id );
	}

	/**
	 * Return whether Media Browser health checks are enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Database::get_setting( 'builderMediaHealthEnabled', true );
	}

	/**
	 * Return settings needed by the Builder UI.
	 *
	 * @return array
	 */
	public static function get_client_config() {
		$policy = self::get_policy();

		return [
			'enabled'         => self::enabled(),
			'dockedInspector' => (bool) Database::get_setting( 'builderMediaDetailsDocked', true ),
			'checks'          => $policy['checks'],
		];
	}

	/**
	 * Return the normalized health policy.
	 *
	 * @return array
	 */
	public static function get_policy() {
		if ( is_array( self::$policy_cache ) ) {
			return self::$policy_cache;
		}

		$defaults  = [
			'image'    => 1,
			'font'     => 0.5,
			'document' => 5,
			'audio'    => 10,
			'video'    => 50,
		];
		$max_bytes = [];

		foreach ( $defaults as $type => $default ) {
			$key                = 'builderMediaHealth' . ucfirst( $type ) . 'MaxSize';
			$value              = Database::get_setting( $key, $default );
			$megabytes          = is_numeric( $value ) ? max( 0, (float) $value ) : $default;
			$max_bytes[ $type ] = (int) round( $megabytes * 1048576 );
		}

		$obsolete = Database::get_setting( 'builderMediaHealthObsoleteExtensions', 'bmp,tif,tiff' );
		$obsolete = is_array( $obsolete ) ? $obsolete : preg_split( '/[\s,]+/', (string) $obsolete );
		$obsolete = array_values(
			array_unique(
				array_filter(
					array_map(
						static function( $extension ) {
							return sanitize_key( ltrim( (string) $extension, '.' ) );
						},
						$obsolete
					)
				)
			)
		);

		self::$policy_cache = [
			'checks'             => [
				'oversized'           => (bool) Database::get_setting( 'builderMediaHealthCheckOversized', true ),
				'missing_alt'         => (bool) Database::get_setting( 'builderMediaHealthCheckMissingAlt', true ),
				'broken'              => (bool) Database::get_setting( 'builderMediaHealthCheckBroken', true ),
				'obsolete_format'     => (bool) Database::get_setting( 'builderMediaHealthCheckObsolete', true ),
				'missing_derivatives' => (bool) Database::get_setting( 'builderMediaHealthCheckDerivatives', true ),
			],
			'maxBytes'           => $max_bytes,
			'obsoleteExtensions' => $obsolete,
		];

		return self::$policy_cache;
	}

	/**
	 * Return the current policy hash.
	 *
	 * @return string
	 */
	public static function get_policy_hash() {
		if ( is_string( self::$policy_hash_cache ) ) {
			return self::$policy_hash_cache;
		}

		$policy           = self::get_policy();
		$registered_sizes = ! empty( $policy['checks']['missing_derivatives'] )
			? self::get_registered_image_sizes()
			: [];
		$payload          = [
			'version' => self::POLICY_VERSION,
			'policy'  => $policy,
			'sizes'   => $registered_sizes,
		];

		self::$policy_hash_cache = hash( 'sha256', wp_json_encode( $payload ) );

		return self::$policy_hash_cache;
	}

	/**
	 * Clear request-level policy and summary caches.
	 *
	 * Used when global settings are changed inside a long-lived request.
	 *
	 * @return void
	 */
	public static function reset_runtime_cache() {
		self::invalidate_summary_cache( true );

		self::$policy_cache                   = null;
		self::$policy_hash_cache              = null;
		self::$registered_image_sizes_cache   = null;
		self::$summary_counts_cache           = [];
		self::$summary_invalidation_pending   = false;
		self::$summary_invalidation_suspended = false;
		self::$scan_marked_incomplete         = false;
	}

	/**
	 * Evaluate normalized attachment facts against a policy.
	 *
	 * Kept independent of WordPress storage so the detection rules can be unit
	 * tested without a database or filesystem.
	 *
	 * @param array $facts  Attachment facts.
	 * @param array $policy Normalized policy.
	 * @return array
	 */
	public static function evaluate_facts( $facts, $policy ) {
		$mime       = strtolower( (string) ( $facts['mime'] ?? '' ) );
		$is_image   = strpos( $mime, 'image/' ) === 0;
		$alt        = trim( (string) ( $facts['alt'] ?? '' ) );
		$decorative = $is_image && ! empty( $facts['decorative'] ) && $alt === '';
		$file_state = sanitize_key( (string) ( $facts['fileState'] ?? 'unknown' ) );
		$file_size  = isset( $facts['fileSize'] ) && is_numeric( $facts['fileSize'] )
			? max( 0, (int) $facts['fileSize'] )
			: 0;
		$extension  = sanitize_key( ltrim( (string) ( $facts['extension'] ?? '' ), '.' ) );
		$bucket     = self::get_media_bucket( $mime );
		$checks     = isset( $policy['checks'] ) && is_array( $policy['checks'] ) ? $policy['checks'] : [];
		$max_bytes  = isset( $policy['maxBytes'][ $bucket ] ) ? (int) $policy['maxBytes'][ $bucket ] : 0;
		$issues     = [];
		$details    = [];

		if ( ! empty( $checks['oversized'] ) && $max_bytes > 0 && $file_size > $max_bytes ) {
			$issues[]             = 'oversized';
			$details['oversized'] = [
				'bytes'          => $file_size,
				'thresholdBytes' => $max_bytes,
			];
		}

		if ( ! empty( $checks['missing_alt'] ) && $is_image && $alt === '' && ! $decorative ) {
			$issues[]               = 'missing_alt';
			$details['missing_alt'] = [];
		}

		if ( ! empty( $checks['broken'] ) && in_array( $file_state, [ 'missing', 'unreadable', 'empty' ], true ) ) {
			$issues[]          = 'broken';
			$details['broken'] = [ 'state' => $file_state ];
		}

		$obsolete_extensions      = isset( $policy['obsoleteExtensions'] ) && is_array( $policy['obsoleteExtensions'] )
			? $policy['obsoleteExtensions']
			: [];
		$obsolete_mime_extensions = [
			'image/bmp'      => [ 'bmp' ],
			'image/x-ms-bmp' => [ 'bmp' ],
			'image/tiff'     => [ 'tif', 'tiff' ],
			'image/x-tiff'   => [ 'tif', 'tiff' ],
		];
		$obsolete_mime_candidates = $obsolete_mime_extensions[ $mime ] ?? [];
		$obsolete_mime            = (bool) array_intersect( $obsolete_mime_candidates, $obsolete_extensions );

		if (
			! empty( $checks['obsolete_format'] ) &&
			( ( $extension && in_array( $extension, $obsolete_extensions, true ) ) || $obsolete_mime )
		) {
			$issues[]                   = 'obsolete_format';
			$details['obsolete_format'] = [
				'extension' => $extension,
				'mime'      => $mime,
			];
		}

		$missing_sizes = isset( $facts['missingSizes'] ) && is_array( $facts['missingSizes'] )
			? array_values(
				array_unique(
					array_filter(
						array_map(
							static function( $size_name ) {
								return is_string( $size_name ) ? $size_name : '';
							},
							$facts['missingSizes']
						),
						static function( $size_name ) {
							return $size_name !== '';
						}
					)
				)
			)
			: [];

		if (
			! empty( $checks['missing_derivatives'] ) &&
			$is_image &&
			! in_array( $file_state, [ 'missing', 'unreadable', 'empty' ], true ) &&
			$missing_sizes
		) {
			$issues[]                       = 'missing_derivatives';
			$details['missing_derivatives'] = [ 'sizes' => $missing_sizes ];
		}

		return [
			'decorative' => $decorative,
			'issues'     => array_values( array_unique( $issues ) ),
			'details'    => $details,
		];
	}

	/**
	 * Audit and persist one attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Whether to ignore a current cached result.
	 * @return array
	 */
	public static function audit_attachment( $attachment_id, $force = false ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			return self::empty_result( 'unknown' );
		}

		if ( ! self::enabled() ) {
			return self::empty_result( 'disabled' );
		}

		$policy_hash = self::get_policy_hash();

		if ( ! $force && get_post_meta( $attachment_id, self::META_POLICY, true ) === $policy_hash ) {
			return self::get_result( $attachment_id, false );
		}

		$policy         = self::get_policy();
		$facts          = self::get_attachment_facts( $attachment_id, $policy );
		$evaluation     = self::evaluate_facts( $facts, $policy );
		$ignored_issues = get_post_meta( $attachment_id, self::META_IGNORED, false );
		$partition      = self::partition_issues( $evaluation['issues'], $ignored_issues );

		self::$writing_index = true;

		try {
			if ( ! $evaluation['decorative'] && ! empty( $facts['alt'] ) ) {
				delete_post_meta( $attachment_id, self::META_DECORATIVE );
			}

			delete_post_meta( $attachment_id, self::META_ISSUE );

			foreach ( $partition['active'] as $issue ) {
				add_post_meta( $attachment_id, self::META_ISSUE, $issue, false );
			}

			update_post_meta( $attachment_id, self::META_DETAILS, $evaluation['details'] );
			update_post_meta( $attachment_id, self::META_POLICY, $policy_hash );
			update_post_meta( $attachment_id, self::META_CHECKED_AT, time() );
		} finally {
			self::$writing_index = false;
		}

		self::invalidate_summary_cache();

		return self::get_result( $attachment_id, false );
	}

	/**
	 * Return a prepared health result for one attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $ensure_current Whether to refresh stale findings.
	 * @return array
	 */
	public static function get_result( $attachment_id, $ensure_current = false ) {
		$attachment_id = absint( $attachment_id );

		if ( ! self::enabled() ) {
			return self::empty_result( 'disabled' );
		}

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			return self::empty_result( 'unknown' );
		}

		$policy_hash = self::get_policy_hash();
		$is_current  = get_post_meta( $attachment_id, self::META_POLICY, true ) === $policy_hash;

		if ( $ensure_current && ! $is_current ) {
			return self::audit_attachment( $attachment_id );
		}

		if ( ! $is_current ) {
			return self::empty_result( 'pending' );
		}

		$issues           = array_values( array_intersect( self::issue_codes(), get_post_meta( $attachment_id, self::META_ISSUE, false ) ) );
		$ignored          = array_values( array_intersect( self::issue_codes(), get_post_meta( $attachment_id, self::META_IGNORED, false ) ) );
		$details          = get_post_meta( $attachment_id, self::META_DETAILS, true );
		$details          = is_array( $details ) ? $details : [];
		$detected_ignored = array_values( array_intersect( $ignored, array_keys( $details ) ) );
		$alt              = self::get_attachment_alt( $attachment_id );
		$decorative       = strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) === 0 &&
			$alt === '' &&
			(bool) get_post_meta( $attachment_id, self::META_DECORATIVE, true );
		$prepared_issues  = [];
		$prepared_ignored = [];

		foreach ( self::issue_codes() as $issue ) {
			if ( ! in_array( $issue, $issues, true ) ) {
				continue;
			}

			$prepared_issues[] = [
				'code' => $issue,
				'data' => isset( $details[ $issue ] ) && is_array( $details[ $issue ] ) ? $details[ $issue ] : [],
			];
		}

		foreach ( self::issue_codes() as $issue ) {
			if ( ! in_array( $issue, $detected_ignored, true ) ) {
				continue;
			}

			$prepared_ignored[] = [
				'code' => $issue,
				'data' => isset( $details[ $issue ] ) && is_array( $details[ $issue ] ) ? $details[ $issue ] : [],
			];
		}

		return [
			'auditState'    => 'current',
			'decorative'    => $decorative,
			'issues'        => $prepared_issues,
			'ignoredIssues' => $prepared_ignored,
			'checkedAt'     => absint( get_post_meta( $attachment_id, self::META_CHECKED_AT, true ) ),
		];
	}

	/**
	 * Return permission-scoped health counts and scan progress.
	 *
	 * @return array
	 */
	public static function get_summary() {
		$defaults = [
			'all_issues'          => 0,
			'oversized'           => 0,
			'missing_alt'         => 0,
			'broken'              => 0,
			'obsolete_format'     => 0,
			'missing_derivatives' => 0,
			'decorative'          => 0,
			'healthy'             => 0,
			'checked'             => 0,
			'pending'             => 0,
			'total'               => 0,
		];

		if ( ! self::enabled() ) {
			return array_merge(
				$defaults,
				[
					'enabled' => false,
					'scan'    => self::get_scan_state()
				]
			);
		}

		$counts              = self::get_summary_counts();
		$defaults            = array_merge( $defaults, $counts );
		$defaults['checked'] = min( $defaults['total'], $defaults['checked'] );
		$defaults['pending'] = max( 0, $defaults['total'] - $defaults['checked'] );

		return array_merge(
			$defaults,
			[
				'enabled' => true,
				'scan'    => self::get_scan_state()
			]
		);
	}

	/**
	 * Return cached attachment counts for the current policy and visibility.
	 *
	 * The aggregate query replaces five separate count queries. Scan progress is
	 * deliberately excluded from this cache so its cursor remains live.
	 *
	 * @return array
	 */
	private static function get_summary_counts() {
		$cache_key = self::get_summary_cache_key();

		if ( isset( self::$summary_counts_cache[ $cache_key ] ) ) {
			return self::$summary_counts_cache[ $cache_key ];
		}

		$cached      = wp_cache_get( $cache_key, 'bricks' );
		$policy_hash = self::get_policy_hash();

		if (
			is_array( $cached ) &&
			( $cached['policy'] ?? '' ) === $policy_hash &&
			isset( $cached['counts'] ) &&
			is_array( $cached['counts'] )
		) {
			self::$summary_counts_cache[ $cache_key ] = $cached['counts'];
			self::$summary_cache_keys[ $cache_key ]   = true;

			return $cached['counts'];
		}

		global $wpdb;

		$post_statuses       = self::get_visible_attachment_statuses();
		$status_placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
		$issue_selects       = [];
		$issue_values        = [];

		foreach ( self::issue_codes() as $issue ) {
			$issue_selects[] = "COUNT(DISTINCT CASE WHEN policy.post_id IS NOT NULL AND issue.meta_value = %s THEN p.ID END) AS {$issue}";
			$issue_values[]  = $issue;
		}

		$sql_values = array_merge(
			[ 'image/%' ],
			$issue_values,
			[
				self::META_POLICY,
				$policy_hash,
				self::META_ISSUE,
				self::META_DECORATIVE,
				'1',
				'attachment',
			],
			$post_statuses
		);
		$sql        = 'SELECT
				COUNT(DISTINCT p.ID) AS total,
				COUNT(DISTINCT CASE WHEN policy.post_id IS NOT NULL THEN p.ID END) AS checked,
				COUNT(DISTINCT CASE WHEN policy.post_id IS NOT NULL AND issue.post_id IS NOT NULL THEN p.ID END) AS all_issues,
				COUNT(DISTINCT CASE WHEN policy.post_id IS NOT NULL AND issue.post_id IS NULL THEN p.ID END) AS healthy,
				COUNT(DISTINCT CASE WHEN policy.post_id IS NOT NULL AND decorative.post_id IS NOT NULL AND p.post_mime_type LIKE %s THEN p.ID END) AS decorative,
				' . implode( ",\n\t\t\t\t", $issue_selects ) . "
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} policy ON policy.post_id = p.ID AND policy.meta_key = %s AND policy.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} issue ON issue.post_id = p.ID AND issue.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} decorative ON decorative.post_id = p.ID AND decorative.meta_key = %s AND decorative.meta_value = %s
			WHERE p.post_type = %s AND p.post_status IN ({$status_placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One cached aggregate replaces five count queries; clauses and aliases are fixed, and every value is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $sql_values ) );

		$counts = [
			'all_issues' => absint( $row->all_issues ?? 0 ),
			'decorative' => absint( $row->decorative ?? 0 ),
			'healthy'    => absint( $row->healthy ?? 0 ),
			'checked'    => absint( $row->checked ?? 0 ),
			'total'      => absint( $row->total ?? 0 ),
		];

		foreach ( self::issue_codes() as $issue ) {
			$counts[ $issue ] = absint( $row->{$issue} ?? 0 );
		}

		self::$summary_counts_cache[ $cache_key ] = $counts;
		self::$summary_cache_keys[ $cache_key ]   = true;
		wp_cache_set(
			$cache_key,
			[
				'policy' => $policy_hash,
				'counts' => $counts,
			],
			'bricks',
			self::SUMMARY_CACHE_TTL
		);

		return $counts;
	}

	/**
	 * Process the next resumable audit chunk.
	 *
	 * @param int  $cursor Last processed attachment ID.
	 * @param bool $force  Whether to start a fresh scan.
	 * @return array|\WP_Error
	 */
	public static function scan( $cursor = 0, $force = false ) {
		if ( ! self::enabled() ) {
			return [
				'summary'    => self::get_summary(),
				'complete'   => true,
				'nextCursor' => 0,
				'processed'  => 0,
				'skipped'    => 0
			];
		}

		if ( get_transient( self::SCAN_LOCK ) ) {
			return new \WP_Error( 'media_health_scan_busy', __( 'The media health scan is already running.', 'bricks' ) );
		}

		set_transient( self::SCAN_LOCK, 1, 30 );

		try {
			$policy_hash = self::get_policy_hash();
			$state       = self::get_scan_state();

			if ( $force ) {
				$cursor = 0;
				$state  = [
					'policy'     => $policy_hash,
					'nextCursor' => 0,
					'complete'   => false,
					'force'      => true,
					'startedAt'  => time(),
				];
			} elseif ( ( $state['policy'] ?? '' ) !== $policy_hash ) {
				$cursor = 0;
				$state  = [
					'policy'     => $policy_hash,
					'nextCursor' => 0,
					'complete'   => false,
					'force'      => false,
					'startedAt'  => time(),
				];
			}

			$forced_scan = ! empty( $state['force'] ) && empty( $state['complete'] );

			if ( ! $force ) {
				$cursor = max( absint( $cursor ), absint( $state['nextCursor'] ?? 0 ) );
			}

			global $wpdb;

			$post_statuses       = self::get_visible_attachment_statuses();
			$status_placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );

			if ( $forced_scan ) {
				$sql_values = array_merge( [ 'attachment' ], $post_statuses, [ absint( $cursor ), self::SCAN_BATCH_SIZE ] );
				$sql        = "SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status IN ({$status_placeholders})
					AND ID > %d
					ORDER BY ID ASC
					LIMIT %d";
			} else {
				$sql_values = array_merge(
					[ 'attachment' ],
					$post_statuses,
					[ self::META_POLICY, $policy_hash, absint( $cursor ), self::SCAN_BATCH_SIZE ]
				);
				$sql        = "SELECT ID FROM {$wpdb->posts} p
					WHERE p.post_type = %s
					AND p.post_status IN ({$status_placeholders})
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} policy
						WHERE policy.post_id = p.ID
						AND policy.meta_key = %s
						AND policy.meta_value = %s
					)
					AND p.ID > %d
					ORDER BY p.ID ASC
					LIMIT %d";
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Scan candidates must be fresh; clauses are fixed, status placeholders are generated internally, and all values are prepared.
			$attachment_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $sql_values ) ) );

			self::$summary_invalidation_suspended = true;

			$editable_ids = [];

			try {
				foreach ( $attachment_ids as $attachment_id ) {
					if ( current_user_can( 'edit_post', $attachment_id ) ) {
						$editable_ids[] = $attachment_id;
					}
				}

				foreach ( $editable_ids as $attachment_id ) {
					self::audit_attachment( $attachment_id, $forced_scan );
				}
			} finally {
				self::$summary_invalidation_suspended = false;

				if ( self::$summary_invalidation_pending ) {
					self::invalidate_summary_cache( true );
				}
			}

			$processed   = count( $attachment_ids );
			$next_cursor = $processed ? end( $attachment_ids ) : absint( $cursor );
			$complete    = $processed < self::SCAN_BATCH_SIZE;
			$state       = array_merge(
				$state,
				[
					'policy'      => $policy_hash,
					'nextCursor'  => $next_cursor,
					'complete'    => $complete,
					'force'       => $complete ? false : $forced_scan,
					'completedAt' => $complete ? time() : 0,
				]
			);

			update_option( self::SCAN_STATE_OPTION, $state, false );

			$response = [
				'processed'  => $processed,
				'skipped'    => $processed - count( $editable_ids ),
				'nextCursor' => $next_cursor,
				'complete'   => $complete,
				'scan'       => self::prepare_scan_state( $state ),
			];

			// The aggregate summary scans the complete media library. Running it after
			// every 25-item batch turns one audit into N whole-library count queries.
			// Intermediate responses carry only progress; calculate fresh counts once
			// the scan is complete.
			if ( $complete ) {
				$response['summary'] = self::get_summary();
			}

			return $response;
		} finally {
			delete_transient( self::SCAN_LOCK );
		}
	}

	/**
	 * Return the current scan state, resetting stale policy cursors.
	 *
	 * @return array
	 */
	public static function get_scan_state() {
		if ( ! self::enabled() ) {
			return [
				'policy'      => '',
				'nextCursor'  => 0,
				'complete'    => true,
				'force'       => false,
				'startedAt'   => 0,
				'completedAt' => 0,
			];
		}

		$state       = get_option( self::SCAN_STATE_OPTION, [] );
		$policy_hash = self::get_policy_hash();

		if ( ! is_array( $state ) || ( $state['policy'] ?? '' ) !== $policy_hash ) {
			return [
				'policy'      => $policy_hash,
				'nextCursor'  => 0,
				'complete'    => false,
				'force'       => false,
				'startedAt'   => 0,
				'completedAt' => 0,
			];
		}

		return self::prepare_scan_state( $state );
	}

	/**
	 * Normalize persisted scan state for client responses.
	 *
	 * @param array $state Raw persisted scan state.
	 * @return array
	 */
	private static function prepare_scan_state( $state ) {
		return [
			'policy'      => self::get_policy_hash(),
			'nextCursor'  => absint( $state['nextCursor'] ?? 0 ),
			'complete'    => ! empty( $state['complete'] ),
			'force'       => ! empty( $state['force'] ),
			'startedAt'   => absint( $state['startedAt'] ?? 0 ),
			'completedAt' => absint( $state['completedAt'] ?? 0 ),
		];
	}

	/**
	 * Mark one attachment's cached findings stale.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function invalidate_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || self::$writing_index ) {
			return;
		}

		self::$writing_index = true;

		try {
			delete_post_meta( $attachment_id, self::META_POLICY );
		} finally {
			self::$writing_index = false;
		}

		self::mark_scan_incomplete();
		self::invalidate_summary_cache();
	}

	/**
	 * Invalidate aggregate counts when an attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function handle_attachment_deleted( $attachment_id ) {
		if ( ! absint( $attachment_id ) ) {
			return;
		}

		self::mark_scan_incomplete();
		self::invalidate_summary_cache();
	}

	/**
	 * Invalidate counts when an attachment enters or leaves a visible status.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Updated post.
	 * @return void
	 */
	public static function handle_attachment_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
			return;
		}

		self::mark_scan_incomplete();
		self::invalidate_summary_cache();
	}

	/**
	 * Invalidate when WordPress attachment metadata relevant to health changes.
	 *
	 * @param int    $meta_id       Meta ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $meta_key      Meta key.
	 * @param mixed  $meta_value    Meta value.
	 * @return void
	 */
	public static function handle_attachment_meta_change( $meta_id, $attachment_id, $meta_key, $meta_value = null ) {
		if (
			self::$writing_index ||
			! in_array( $meta_key, [ '_wp_attachment_image_alt', '_wp_attachment_alt', '_wp_attachment_metadata', '_wp_attached_file', self::META_DECORATIVE ], true ) ||
			get_post_type( $attachment_id ) !== 'attachment'
		) {
			return;
		}

		self::invalidate_attachment( $attachment_id );
	}

	/**
	 * Save standard attachment fields and decorative classification.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $values        Submitted field values.
	 * @return array|\WP_Error
	 */
	public static function save_attachment_details( $attachment_id, $values ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			return new \WP_Error( 'media_health_attachment_not_found', __( 'Not found', 'bricks' ) );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'media_health_attachment_not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		$mime        = (string) get_post_mime_type( $attachment_id );
		$is_image    = strpos( $mime, 'image/' ) === 0;
		$alt         = $is_image && isset( $values['alt'] ) ? sanitize_text_field( $values['alt'] ) : '';
		$decorative  = $is_image && array_key_exists( 'decorative', $values )
			? ! empty( $values['decorative'] )
			: $alt === '' && (bool) get_post_meta( $attachment_id, self::META_DECORATIVE, true );
		$alt         = $decorative ? '' : $alt;
		$post_update = [
			'ID'           => $attachment_id,
			'post_title'   => isset( $values['title'] ) ? sanitize_text_field( $values['title'] ) : '',
			'post_excerpt' => isset( $values['caption'] ) ? wp_kses_post( $values['caption'] ) : '',
			'post_content' => isset( $values['description'] ) ? wp_kses_post( $values['description'] ) : '',
		];
		$result      = wp_update_post( wp_slash( $post_update ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $is_image ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		if ( $decorative ) {
			update_post_meta( $attachment_id, self::META_DECORATIVE, 1 );
		} else {
			delete_post_meta( $attachment_id, self::META_DECORATIVE );
		}

		return self::audit_attachment( $attachment_id, true );
	}

	/**
	 * Ignore or restore one finding for one attachment.
	 *
	 * Ignored findings remain detectable so they can be restored later, but are
	 * excluded from active issue metadata, filters, and summary counts.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $issue_code    Health issue code.
	 * @param bool   $ignored       Whether to ignore the finding.
	 * @return array|\WP_Error
	 */
	public static function set_issue_ignored( $attachment_id, $issue_code, $ignored ) {
		$attachment_id = absint( $attachment_id );
		$issue_code    = sanitize_key( $issue_code );

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			return new \WP_Error( 'media_health_attachment_not_found', __( 'Not found', 'bricks' ) );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'media_health_attachment_not_allowed', __( 'Not allowed', 'bricks' ) );
		}

		if ( ! in_array( $issue_code, self::issue_codes(), true ) ) {
			return new \WP_Error( 'media_health_issue_invalid', __( 'Invalid parameter', 'bricks' ) );
		}

		$current         = self::get_result( $attachment_id, true );
		$active_codes    = array_column( $current['issues'] ?? [], 'code' );
		$ignored_codes   = array_column( $current['ignoredIssues'] ?? [], 'code' );
		$currently_saved = in_array( $issue_code, $ignored_codes, true );

		if ( $ignored && ! in_array( $issue_code, $active_codes, true ) ) {
			return new \WP_Error( 'media_health_issue_not_found', __( 'Not found', 'bricks' ) );
		}

		if ( (bool) $ignored === $currently_saved ) {
			return $current;
		}

		if ( $ignored ) {
			add_post_meta( $attachment_id, self::META_IGNORED, $issue_code, true );
		} else {
			delete_post_meta( $attachment_id, self::META_IGNORED, $issue_code );
		}

		return self::audit_attachment( $attachment_id, true );
	}

	/**
	 * Partition detected findings into active and ignored issue codes.
	 *
	 * @param array $issues  Detected issue codes.
	 * @param array $ignored Ignored issue codes.
	 * @return array
	 */
	private static function partition_issues( $issues, $ignored ) {
		$known_codes = self::issue_codes();
		$issues      = array_values( array_intersect( $known_codes, is_array( $issues ) ? $issues : [] ) );
		$ignored     = array_values( array_intersect( $known_codes, is_array( $ignored ) ? $ignored : [] ) );

		return [
			'active'  => array_values( array_diff( $issues, $ignored ) ),
			'ignored' => array_values( array_intersect( $issues, $ignored ) ),
		];
	}

	/**
	 * Collect facts required by the pure evaluator.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $policy        Normalized health policy.
	 * @return array
	 */
	private static function get_attachment_facts( $attachment_id, $policy ) {
		$requirements         = self::get_fact_requirements( $policy );
		$mime                 = (string) get_post_mime_type( $attachment_id );
		$is_image             = strpos( $mime, 'image/' ) === 0;
		$metadata             = [];
		$metadata_loaded      = false;
		$file                 = false;
		$file_state           = 'unknown';
		$file_size            = 0;
		$detected_file_size   = null;
		$missing_sizes        = [];
		$expected_derivatives = [];

		if ( $requirements['metadata'] && $is_image ) {
			$metadata        = wp_get_attachment_metadata( $attachment_id );
			$metadata        = is_array( $metadata ) ? $metadata : [];
			$metadata_loaded = true;
		}

		if ( $requirements['fileSize'] ) {
			if ( ! $metadata_loaded ) {
				$metadata        = wp_get_attachment_metadata( $attachment_id );
				$metadata        = is_array( $metadata ) ? $metadata : [];
				$metadata_loaded = true;
			}

			$file_size = isset( $metadata['filesize'] ) && is_numeric( $metadata['filesize'] )
				? max( 0, (int) $metadata['filesize'] )
				: 0;
		}

		if ( $requirements['derivatives'] && $is_image ) {
			$expected_derivatives = self::get_expected_derivatives( $metadata );
		}

		$needs_file_state = $requirements['fileState'] ||
			( $requirements['fileSize'] && ! $file_size ) ||
			(bool) $expected_derivatives;

		if ( $needs_file_state ) {
			$file       = get_attached_file( $attachment_id );
			$file_state = self::get_file_state( $attachment_id, $file, $detected_file_size );
		}

		if ( $requirements['fileSize'] && ! $file_size && is_numeric( $detected_file_size ) ) {
			$file_size = max( 0, (int) $detected_file_size );
		}

		if ( $expected_derivatives ) {
			$missing_sizes = self::get_missing_derivatives( $file, $file_state, $metadata, $expected_derivatives );
		}

		$filename = $requirements['extension']
			? (string) get_post_meta( $attachment_id, '_wp_attached_file', true )
			: '';

		return [
			'mime'         => $mime,
			'alt'          => $is_image ? self::get_attachment_alt( $attachment_id ) : '',
			'decorative'   => $is_image && (bool) get_post_meta( $attachment_id, self::META_DECORATIVE, true ),
			'fileState'    => $file_state,
			'fileSize'     => $file_size,
			'extension'    => strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ),
			'missingSizes' => $missing_sizes,
		];
	}

	/**
	 * Return which attachment facts the enabled checks require.
	 *
	 * @param array $policy Normalized health policy.
	 * @return array
	 */
	private static function get_fact_requirements( $policy ) {
		$checks = isset( $policy['checks'] ) && is_array( $policy['checks'] ) ? $policy['checks'] : [];

		return [
			'metadata'    => ! empty( $checks['oversized'] ) || ! empty( $checks['missing_derivatives'] ),
			'fileState'   => ! empty( $checks['broken'] ),
			'fileSize'    => ! empty( $checks['oversized'] ),
			'extension'   => ! empty( $checks['obsolete_format'] ),
			'derivatives' => ! empty( $checks['missing_derivatives'] ),
		];
	}

	/**
	 * Return the current local file state for an attachment.
	 *
	 * Remote and offloaded media can return "unknown" through the existing
	 * file-status filter, so callers only suppress definitively unavailable
	 * local files.
	 *
	 * @since 2.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function get_attachment_file_state( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return 'unknown';
		}

		$file      = get_attached_file( $attachment_id );
		$file_size = null;

		return self::get_file_state( $attachment_id, $file, $file_size );
	}

	/**
	 * Determine whether the original file is locally usable.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param string|false $file          Attached file path.
	 * @param int|null     $file_size     Detected local file size.
	 * @return string
	 */
	private static function get_file_state( $attachment_id, $file, &$file_size = null ) {
		$file_size = null;

		if ( $file && is_file( $file ) ) {
			if ( ! is_readable( $file ) ) {
				$state = 'unreadable';
			} else {
				$size      = filesize( $file );
				$file_size = is_numeric( $size ) ? max( 0, (int) $size ) : null;
				$state     = $file_size > 0 ? 'exists' : 'empty';
			}
		} elseif ( ! $file ) {
			$state = 'missing';
		} elseif ( preg_match( '#^https?://#i', (string) $file ) ) {
			$state = 'unknown';
		} else {
			$uploads = wp_get_upload_dir();
			$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
			$path    = wp_normalize_path( $file );
			$state   = $basedir && strpos( $path, trailingslashit( $basedir ) ) === 0 ? 'missing' : 'unknown';
		}

		/**
		 * Filter a file status when media is served by an offload provider.
		 *
		 * Return one of exists, missing, unreadable, empty, or unknown.
		 *
		 * @param string       $state         Detected state.
		 * @param int          $attachment_id Attachment ID.
		 * @param string|false $file          Attached file path.
		 * @param string|false $url           Attachment URL.
		 */
		$state = apply_filters( 'bricks/media_browser/health/file_status', $state, $attachment_id, $file, wp_get_attachment_url( $attachment_id ) );
		$state = sanitize_key( $state );

		return in_array( $state, [ 'exists', 'missing', 'unreadable', 'empty', 'unknown' ], true ) ? $state : 'unknown';
	}

	/**
	 * Return registered image sizes that should exist but do not.
	 *
	 * @param string|false $file          Original file path.
	 * @param string       $file_state    Original file state.
	 * @param array        $metadata      Attachment metadata.
	 * @param array        $expected      Expected derivative names.
	 * @return array
	 */
	private static function get_missing_derivatives( $file, $file_state, $metadata, $expected ) {
		$missing = [];

		foreach ( $expected as $name ) {
			if ( empty( $metadata['sizes'][ $name ]['file'] ) ) {
				$missing[] = (string) $name;
				continue;
			}

			if ( $file_state !== 'exists' || ! $file ) {
				continue;
			}

			$derivative = trailingslashit( dirname( $file ) ) . wp_basename( $metadata['sizes'][ $name ]['file'] );

			if ( ! is_file( $derivative ) || ! is_readable( $derivative ) || filesize( $derivative ) <= 0 ) {
				$missing[] = (string) $name;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Return registered derivative names applicable to an image's dimensions.
	 *
	 * @param array $metadata Attachment metadata.
	 * @return array
	 */
	private static function get_expected_derivatives( $metadata ) {
		if (
			! function_exists( 'image_resize_dimensions' ) ||
			empty( $metadata['width'] ) ||
			empty( $metadata['height'] )
		) {
			return [];
		}

		$expected = [];
		$width    = absint( $metadata['width'] );
		$height   = absint( $metadata['height'] );

		foreach ( self::get_registered_image_sizes() as $name => $size ) {
			$target_width  = absint( $size['width'] ?? 0 );
			$target_height = absint( $size['height'] ?? 0 );
			$crop          = $size['crop'] ?? false;

			if ( ! $target_width && ! $target_height ) {
				continue;
			}

			if ( image_resize_dimensions( $width, $height, $target_width, $target_height, $crop ) ) {
				$expected[] = (string) $name;
			}
		}

		return array_values( array_unique( $expected ) );
	}

	/**
	 * Return registered image sizes once per request.
	 *
	 * @return array
	 */
	private static function get_registered_image_sizes() {
		if ( is_array( self::$registered_image_sizes_cache ) ) {
			return self::$registered_image_sizes_cache;
		}

		$sizes = function_exists( 'wp_get_registered_image_subsizes' )
			? wp_get_registered_image_subsizes()
			: [];

		self::$registered_image_sizes_cache = is_array( $sizes ) ? $sizes : [];

		return self::$registered_image_sizes_cache;
	}

	/**
	 * Return the aggregate cache key for one attachment-visibility scope.
	 *
	 * @param bool|null $include_private Whether private attachments are visible.
	 * @return string
	 */
	private static function get_summary_cache_key( $include_private = null ) {
		if ( $include_private === null ) {
			$include_private = in_array( 'private', self::get_visible_attachment_statuses(), true );
		}

		return 'media_health_summary_' . ( $include_private ? 'private' : 'public' );
	}

	/**
	 * Invalidate request and persistent summary caches.
	 *
	 * @param bool $force Whether to bypass scan-batch deferral.
	 * @return void
	 */
	private static function invalidate_summary_cache( $force = false ) {
		self::$summary_counts_cache = [];

		if ( self::$summary_invalidation_suspended && ! $force ) {
			self::$summary_invalidation_pending = true;
			return;
		}

		self::$summary_invalidation_pending = false;

		if ( ! function_exists( 'wp_cache_delete' ) ) {
			self::$summary_cache_keys = [];
			return;
		}

		$cache_keys = array_keys( self::$summary_cache_keys );
		$cache_keys = array_merge(
			$cache_keys,
			[
				self::get_summary_cache_key( false ),
				self::get_summary_cache_key( true ),
			]
		);

		foreach ( array_unique( $cache_keys ) as $cache_key ) {
			wp_cache_delete( $cache_key, 'bricks' );
		}

		self::$summary_cache_keys = [];
	}

	/**
	 * Mark automatic scan progress incomplete after attachment data changes.
	 *
	 * @return void
	 */
	private static function mark_scan_incomplete() {
		if ( self::$scan_marked_incomplete ) {
			return;
		}

		self::$scan_marked_incomplete = true;
		$state                        = get_option( self::SCAN_STATE_OPTION, [] );
		$policy_hash                  = self::get_policy_hash();

		if ( ! is_array( $state ) || ( $state['policy'] ?? '' ) !== $policy_hash ) {
			return;
		}

		$state['complete']    = false;
		$state['completedAt'] = 0;

		if ( empty( $state['force'] ) ) {
			$state['nextCursor'] = 0;
		}

		update_option( self::SCAN_STATE_OPTION, $state, false );
	}

	/**
	 * Return standard or legacy attachment alternative text.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function get_attachment_alt( $attachment_id ) {
		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		if ( $alt === '' ) {
			$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_alt', true ) );
		}

		return $alt;
	}

	/**
	 * Return a media threshold bucket for a MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private static function get_media_bucket( $mime ) {
		foreach ( [ 'image', 'video', 'audio' ] as $type ) {
			if ( strpos( $mime, $type . '/' ) === 0 ) {
				return $type;
			}
		}

		if (
			strpos( $mime, 'font/' ) === 0 ||
			strpos( $mime, 'application/font-' ) === 0 ||
			strpos( $mime, 'application/x-font-' ) === 0 ||
			in_array( $mime, [ 'application/vnd.ms-fontobject', 'application/vnd.ms-opentype' ], true )
		) {
			return 'font';
		}

		return 'document';
	}

	/**
	 * Return attachment statuses visible to the current user.
	 *
	 * @return array
	 */
	private static function get_visible_attachment_statuses() {
		$statuses  = [ 'inherit' ];
		$post_type = get_post_type_object( 'attachment' );
		$cap       = $post_type && isset( $post_type->cap->read_private_posts )
			? $post_type->cap->read_private_posts
			: 'read_private_posts';

		if ( current_user_can( $cap ) ) {
			$statuses[] = 'private';
		}

		return $statuses;
	}

	/**
	 * Return stable issue codes in display priority order.
	 *
	 * @return array
	 */
	public static function issue_codes() {
		return [ 'broken', 'missing_alt', 'missing_derivatives', 'oversized', 'obsolete_format' ];
	}

	/**
	 * Return an empty prepared result.
	 *
	 * @param string $state Audit state.
	 * @return array
	 */
	private static function empty_result( $state ) {
		return [
			'auditState'    => $state,
			'decorative'    => false,
			'issues'        => [],
			'ignoredIssues' => [],
			'checkedAt'     => 0,
		];
	}
}
