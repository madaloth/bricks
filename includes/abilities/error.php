<?php
/**
 * Error factory for Bricks abilities
 *
 * Every helper returns a WP_Error with:
 * - A machine-readable code (consumed by MCP clients as `failure_reason`).
 * - A human-readable, action-oriented message.
 * - A structured `data` payload naming the offending param or resource.
 *
 * Error codes are additive and append-only: once shipped they never change
 * meaning. New codes may be introduced; consumers should treat unknown codes
 * as generic failures.
 *
 * The `wrap()` helper is the single construction point for every error in the
 * file. When a `$data` payload is present, `wrap()` also appends a JSON-encoded
 * copy of that payload to the message string so callers reaching us through
 * the mcp-adapter dispatcher tool (`mcp-adapter-execute-ability`), which calls
 * `WP_Error::get_error_message()` and discards the `data` array, can still
 * recover the structured context by parsing the ` | data: {...}` suffix.
 * Direct `tools/call` callers see the unsuffixed `data` array as usual.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Error {
	/**
	 * Marker that introduces the JSON-encoded data suffix in error messages.
	 *
	 * Stable string so clients can split on it deterministically.
	 *
	 * @since 2.4
	 */
	const DATA_SUFFIX_MARKER = ' | data: ';

	/**
	 * Single point of construction for every Bricks ability WP_Error.
	 *
	 * The JSON-encoded payload is appended to the message so it survives the
	 * mcp-adapter dispatcher's `get_error_message()` flattening. The structured
	 * array is also attached to the WP_Error itself for direct callers.
	 *
	 * @since 2.4
	 *
	 * @param string $code    Machine-readable error code (e.g. `bricks_forbidden_edit_post`).
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured data payload.
	 */
	private static function wrap( string $code, string $message, array $data = [] ): \WP_Error {
		$payload = array_merge( [ 'code' => $code ], $data );
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( is_string( $encoded ) ) {
			$message .= self::DATA_SUFFIX_MARKER . $encoded;
		}

		return new \WP_Error( $code, $message, $payload );
	}

	/**
	 * Invalid parameter value.
	 *
	 * @param string $param    Parameter name.
	 * @param string $expected Human-readable description of the expected shape.
	 * @param mixed  $received The value that was received.
	 */
	public static function invalid_param( string $param, string $expected, $received = null ): \WP_Error {
		return self::wrap(
			'bricks_invalid_param',
			sprintf( 'Invalid value for "%s". Expected %s.', $param, $expected ),
			[
				'param'    => $param,
				'expected' => $expected,
				'received' => $received,
			]
		);
	}

	/**
	 * Invalid / unsafe URL supplied for media sideload.
	 *
	 * Separate from `invalid_param` so callers can branch on URL-specific
	 * failures (bad scheme, private host, unsafe port) without string-matching.
	 *
	 * @param string $url      The received URL.
	 * @param string $expected Human-readable description of the expected URL shape.
	 * @param array  $extra    Optional extra context merged into the error data.
	 */
	public static function invalid_url( string $url, string $expected, array $extra = [] ): \WP_Error {
		return self::wrap(
			'bricks_invalid_url',
			sprintf( 'Invalid value for "url". Expected %s.', $expected ),
			array_merge(
				[
					'param'    => 'url',
					'expected' => $expected,
					'received' => $url,
				],
				$extra
			)
		);
	}

	/**
	 * File MIME type / extension is not in WordPress' allow-list.
	 *
	 * Separate from `invalid_param` so media upload errors are distinguishable
	 * from generic parameter validation failures.
	 *
	 * @param string $param    Offending parameter name (e.g. `filename`, `base64`).
	 * @param string $filename Sanitized filename for context.
	 * @param string $expected Human-readable description of what would be accepted.
	 * @param array  $extra    Optional extra context merged into the error data.
	 */
	public static function disallowed_mime( string $param, string $filename, string $expected, array $extra = [] ): \WP_Error {
		return self::wrap(
			'bricks_disallowed_mime',
			sprintf( 'Disallowed MIME type for "%s". Expected %s.', $param, $expected ),
			array_merge(
				[
					'param'    => $param,
					'filename' => $filename,
					'expected' => $expected,
				],
				$extra
			)
		);
	}

	/**
	 * Base64 payload is malformed or oversized for media upload.
	 *
	 * Separate from `invalid_param` so callers can recognize decode failures
	 * specifically.
	 *
	 * @param string $expected Human-readable description of what would be accepted.
	 * @param array  $extra    Optional extra context merged into the error data.
	 */
	public static function invalid_base64( string $expected, array $extra = [] ): \WP_Error {
		return self::wrap(
			'bricks_invalid_base64',
			sprintf( 'Invalid base64 payload. Expected %s.', $expected ),
			array_merge(
				[
					'param'    => 'base64',
					'expected' => $expected,
				],
				$extra
			)
		);
	}

	/**
	 * Invalid Bricks element ID.
	 *
	 * Element IDs are internal builder identifiers and also form the default
	 * frontend selector (`#brxe-{id}`). MCP callers may omit `id` for nested
	 * element input, but flat references need explicit valid ids.
	 *
	 * @param mixed  $id           The received element id value.
	 * @param string $element_name Element type, when known.
	 * @param string $path         Field path, e.g. `id` or `parent`.
	 */
	public static function invalid_element_id( $id, string $element_name = '', string $path = 'id' ): \WP_Error {
		$received      = is_scalar( $id ) ? (string) $id : wp_json_encode( $id );
		$length        = is_string( $id ) ? strlen( $id ) : null;
		$message       = sprintf(
			'Invalid Bricks element %1$s "%2$s". Element IDs are internal Bricks identifiers used for builder references and the default frontend selector #brxe-{id}; they must be exactly 6 characters.',
			$path,
			$received
		);
		$suggested_fix = 'Use a valid 6-character id when writing flat parent/children references, or omit id only in nested children format so Bricks can generate ids and resolve parents.';

		if ( $path === 'parent' ) {
			$suggested_fix = 'Use parent 0 for a root element, reference an existing 6-character element id from the same tree, or use nested children format so Bricks resolves parents.';
		} else {
			$message .= ' You may omit "id" in nested children format, but flat parent/children references need explicit valid ids.';
		}

		return self::wrap(
			'bricks_invalid_element_id',
			$message,
			[
				'path'           => $path,
				'elementId'      => $id,
				'elementName'    => $element_name,
				'expectedLength' => 6,
				'receivedLength' => $length,
				'suggestedFix'   => $suggested_fix,
			]
		);
	}

	/**
	 * Unknown / unrecognized parameter - caller passed a key the ability does not consume.
	 *
	 * Used by the strict-input pass so callers learn about silently-dropped keys instead
	 * of seeing a successful-looking response that ignored half of their input.
	 *
	 * @param string $param   The unknown parameter name.
	 * @param array  $allowed The set of accepted top-level parameter names.
	 */
	public static function unknown_param( string $param, array $allowed = [] ): \WP_Error {
		return self::wrap(
			'bricks_unknown_param',
			sprintf( 'Unknown parameter "%s". Accepted: %s.', $param, $allowed ? implode( ', ', $allowed ) : '(no parameters accepted)' ),
			[
				'param'   => $param,
				'allowed' => $allowed,
			]
		);
	}

	/**
	 * Internal / unexpected failure - wraps PHP exceptions and other non-recoverable errors
	 * into a structured envelope so MCP clients see a code instead of a raw stack trace.
	 *
	 * @param string $context  Short label for the operation that failed.
	 * @param string $message  Human-readable detail.
	 * @param array  $extra    Optional extra context.
	 */
	public static function internal_error( string $context, string $message, array $extra = [] ): \WP_Error {
		return self::wrap(
			'bricks_internal_error',
			$message,
			array_merge(
				[ 'context' => $context ],
				$extra
			)
		);
	}

	/**
	 * Missing required parameter.
	 *
	 * @param string $param        Missing parameter name.
	 * @param array  $alternatives Acceptable alternatives (e.g. ['postId', 'slug', 'path', 'title']).
	 */
	public static function missing_param( string $param, array $alternatives = [] ): \WP_Error {
		$message = $alternatives
			? sprintf( 'Missing required identifier. Pass one of: %s.', implode( ', ', $alternatives ) )
			: sprintf( 'Missing required parameter "%s".', $param );

		return self::wrap(
			'bricks_missing_param',
			$message,
			[
				'param'        => $param,
				'alternatives' => $alternatives,
			]
		);
	}

	/**
	 * Resource not found.
	 *
	 * @param string $resource   Resource kind (e.g. "post", "template", "element").
	 * @param mixed  $identifier The identifier that failed to resolve.
	 */
	public static function not_found( string $resource, $identifier ): \WP_Error {
		return self::wrap(
			'bricks_not_found',
			sprintf( 'No %s matched "%s".', $resource, is_scalar( $identifier ) ? (string) $identifier : wp_json_encode( $identifier ) ),
			[
				'resource'   => $resource,
				'identifier' => $identifier,
			]
		);
	}

	/**
	 * Multiple resources matched a non-unique identifier.
	 *
	 * @param string $resource Resource kind.
	 * @param array  $matches  List of minimal match descriptors (id + title/slug).
	 */
	public static function ambiguous_match( string $resource, array $matches ): \WP_Error {
		$count = count( $matches );

		return self::wrap(
			'bricks_ambiguous_match',
			sprintf( '%d %ss matched. Pass the exact postId to disambiguate.', $count, $resource ),
			[
				'resource' => $resource,
				'matches'  => $matches,
			]
		);
	}

	/**
	 * Current user lacks `edit_post` on the target.
	 */
	public static function forbidden_edit_post( int $post_id ): \WP_Error {
		return self::wrap(
			'bricks_forbidden_edit_post',
			sprintf( 'Current user cannot edit post %d.', $post_id ),
			[ 'postId' => $post_id ]
		);
	}

	/**
	 * Current user can edit the post but lacks Bricks builder access.
	 */
	public static function forbidden_builder_access( int $post_id ): \WP_Error {
		return self::wrap(
			'bricks_forbidden_builder_access',
			sprintf( 'Current user has no Bricks builder access for post %d.', $post_id ),
			[ 'postId' => $post_id ]
		);
	}

	/**
	 * Current user lacks a specific Bricks builder capability.
	 */
	public static function forbidden_builder_permission( string $permission ): \WP_Error {
		return self::wrap(
			'bricks_forbidden_builder_permission',
			sprintf( 'Current user lacks the "%s" builder permission.', $permission ),
			[ 'permission' => $permission ]
		);
	}

	/**
	 * Executable payloads cannot be written through Bricks abilities.
	 *
	 * This is intentionally separate from a capability error: granting the
	 * execute-code capability must not turn an authenticated ability request
	 * into a code-signing or code-execution path.
	 *
	 * @since 2.4
	 *
	 * @return \WP_Error
	 */
	public static function code_sensitive_write_forbidden(): \WP_Error {
		return self::wrap(
			'bricks_code_sensitive_write_forbidden',
			__( 'This request is not authorized to create or modify the supplied code content.', 'bricks' ),
			[
				'suggestedFix' => __( 'CSS requires target editing permissions; JavaScript requires unfiltered_html. Code elements retain their existing execution and signature requirements. PHP requires BRICKS_ENABLE_PHP_ABILITIES, the Bricks Execute code capability, and the PHP ability authorization prerequisites.', 'bricks' ),
			]
		);
	}

	/**
	 * Executable payloads cannot be rendered through Bricks abilities.
	 *
	 * @since 2.4
	 *
	 * @return \WP_Error
	 */
	public static function code_sensitive_execution_forbidden(): \WP_Error {
		return self::wrap(
			'bricks_code_sensitive_execution_forbidden',
			'Executable code and other code-sensitive payloads cannot be rendered through Bricks abilities.',
			[
				'suggestedFix' => 'Remove the executable payload before using the render ability.',
			]
		);
	}

	/**
	 * Target post exists but is in the trash.
	 */
	public static function post_in_trash( int $post_id ): \WP_Error {
		return self::wrap(
			'bricks_post_in_trash',
			sprintf( 'Post %d is in the trash. Restore it before editing.', $post_id ),
			[ 'postId' => $post_id ]
		);
	}

	/**
	 * Post exists but its post type is not Bricks-enabled.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @param array  $allowed   Post types currently enabled for Bricks.
	 */
	public static function bricks_not_enabled_on_post_type( int $post_id, string $post_type, array $allowed ): \WP_Error {
		return self::wrap(
			'bricks_not_enabled_on_post_type',
			sprintf(
				'Post type "%s" is not enabled for Bricks. Enable it in Bricks > Settings > General > Post types, or choose a post of an allowed type: %s.',
				$post_type,
				implode( ', ', $allowed )
			),
			[
				'postId'   => $post_id,
				'postType' => $post_type,
				'allowed'  => $allowed,
			]
		);
	}

	/**
	 * Another user currently holds the Bricks post lock.
	 */
	public static function locked_by_other_user( int $post_id, int $locked_by_user_id ): \WP_Error {
		$user         = get_userdata( $locked_by_user_id );
		$display_name = $user ? $user->display_name : '';
		$user_login   = $user ? $user->user_login : '';

		return self::wrap(
			'bricks_locked_by_other_user',
			sprintf(
				'Post %d is currently being edited by %s (user %d). Wait for them to release the lock, or take it over manually.',
				$post_id,
				$display_name ? $display_name : ( $user_login ? $user_login : 'another user' ),
				$locked_by_user_id
			),
			[
				'postId'      => $post_id,
				'lockedBy'    => $locked_by_user_id,
				'displayName' => $display_name,
				'userLogin'   => $user_login,
			]
		);
	}

	/**
	 * Generic conflict / precondition failure.
	 *
	 * @param string $reason Short machine-readable reason fragment.
	 * @param array  $ctx    Context payload.
	 */
	public static function conflict( string $reason, array $ctx = [] ): \WP_Error {
		return self::wrap(
			'bricks_conflict_' . $reason,
			$ctx['message'] ?? sprintf( 'Operation conflicts with current state (%s).', $reason ),
			$ctx
		);
	}

	/**
	 * Ability disabled by the site admin.
	 *
	 * Returned when an MCP client tries to call an ability that the admin has
	 * turned off under Bricks > Settings > AI. Disabled abilities normally
	 * don't appear in `tools/list` - this code exists for diagnostic paths
	 * that reach a call site without going through discovery.
	 *
	 * @param string $ability Fully-qualified ability name, e.g. `bricks/delete-post`.
	 */
	public static function ability_disabled( string $ability ): \WP_Error {
		return self::wrap(
			'bricks_ability_disabled',
			sprintf( 'The Bricks ability "%s" is disabled on this site. Ask the administrator to enable it under Bricks > Settings > AI.', $ability ),
			[ 'ability' => $ability ]
		);
	}

	/**
	 * PHP execution ability is unavailable for a stable security reason.
	 *
	 * @since 2.4
	 *
	 * @param string $reason  Machine-readable reason.
	 * @param string $message Action-oriented message.
	 */
	public static function execute_php_unavailable( string $reason, string $message ): \WP_Error {
		return self::wrap(
			'bricks_execute_php_unavailable',
			$message,
			[
				'ability' => 'bricks/execute-php',
				'reason'  => $reason,
			]
		);
	}

	/**
	 * PHP execution failed with a catchable Throwable.
	 *
	 * @since 2.4
	 *
	 * @param string $message Throwable message.
	 * @param string $type    Throwable class.
	 * @param string $output  Output captured before the failure.
	 */
	public static function execute_php_failed( string $message, string $type, string $output ): \WP_Error {
		return self::wrap(
			'bricks_execute_php_failed',
			$message,
			[
				'ability' => 'bricks/execute-php',
				'type'    => $type,
				'output'  => $output,
			]
		);
	}

	/**
	 * Attempt to read/write a setting key that is permanently excluded from
	 * the MCP surface (credentials, code-execution toggles).
	 *
	 * @param string $key The excluded setting key.
	 */
	public static function setting_excluded( string $key ): \WP_Error {
		return self::wrap(
			'bricks_setting_excluded',
			sprintf( 'The setting "%s" cannot be read or written via the MCP for security reasons (credentials or code-execution settings). Use the Bricks admin UI directly.', $key ),
			[ 'key' => $key ]
		);
	}

	/**
	 * Attempt to write a setting key that is not in the registry.
	 *
	 * @param string $key             The unrecognized setting key.
	 * @param array  $allowed         Optional: list of accepted keys for the message.
	 * @param string $discovery_hint  Optional: caller-specific discovery instruction.
	 */
	public static function setting_unknown( string $key, array $allowed = [], string $discovery_hint = '' ): \WP_Error {
		$message = sprintf( 'Unknown setting key "%s".', $key );

		if ( $allowed ) {
			if ( ! $discovery_hint ) {
				$discovery_hint = 'Call `bricks/list-settings-schema` to see accepted keys.';
			}

			$message .= ' ' . $discovery_hint;
		}

		return self::wrap(
			'bricks_setting_unknown',
			$message,
			[
				'key'           => $key,
				'allowed'       => $allowed,
				'discoveryHint' => $discovery_hint,
			]
		);
	}

	/**
	 * Duplicate-name conflict on a create/update of a named design-system resource.
	 *
	 * Produces resource-granular codes following the shipped
	 * `bricks_conflict_duplicate_{resource}_name` convention. The `$resource`
	 * argument is normalized (lowercased, non-alphanumerics to underscore) so
	 * callers can pass natural phrasing like "global class" or "color palette".
	 *
	 * Because error codes are append-only, each new resource introduces a new
	 * stable code. Do not collapse these into a single generic code.
	 *
	 * @param string $resource Resource kind - e.g. "global_class", "component", "theme_style".
	 * @param string $name     The duplicate name supplied by the caller.
	 * @param array  $ctx      Optional extra context merged into the error data.
	 */
	public static function conflict_duplicate_name( string $resource, string $name, array $ctx = [] ): \WP_Error {
		$slug = strtolower( trim( $resource ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug );
		$slug = trim( $slug, '_' );

		$label = str_replace( '_', ' ', $slug );

		return self::wrap(
			'bricks_conflict_duplicate_' . $slug . '_name',
			sprintf( 'A %s named "%s" already exists.', $label, $name ),
			array_merge(
				[
					'resource' => $slug,
					'name'     => $name,
				],
				$ctx
			)
		);
	}

	/**
	 * HTML/CSS-to-Bricks conversion failed.
	 *
	 * Used by `bricks/convert-html-css-to-bricks-data` to surface conversion failures as a proper
	 * `WP_Error` instead of `{success: false, errors: [...]}`. The structured
	 * `errors`, `warnings`, and any partial-conversion `elements` are attached
	 * to the data payload so dispatcher callers (via the message suffix) and
	 * direct callers (via `error.data`) can both inspect what went wrong.
	 *
	 * @param array $errors           Conversion error entries from Html_To_Bricks_Converter.
	 * @param array $warnings         Non-fatal warnings from the same converter.
	 * @param array $partial_elements Optional partial element tree the converter produced before failing.
	 *
	 * @since 2.4
	 */
	public static function conversion_failed( array $errors, array $warnings = [], array $partial_elements = [] ): \WP_Error {
		$count = count( $errors );

		$data = [
			'errors'   => $errors,
			'warnings' => $warnings,
		];

		if ( ! empty( $partial_elements ) ) {
			$data['partialElements'] = $partial_elements;
		}

		return self::wrap(
			'bricks_conversion_failed',
			sprintf( 'HTML/CSS-to-Bricks conversion failed with %d error%s.', $count, $count === 1 ? '' : 's' ),
			$data
		);
	}
}
