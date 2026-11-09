<?php
/**
 * Execute PHP ability.
 *
 * Runs non-persistent PHP for an authenticated, authorized user when explicitly
 * enabled through PHP configuration.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Execute_Php {
	const ABILITY_NAME     = 'bricks/execute-php';
	const MAX_CODE_BYTES   = 65536;
	const MAX_OUTPUT_BYTES = 262144;

	/**
	 * Ability input schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'code' ],
			'properties'           => [
				'code' => [
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => self::MAX_CODE_BYTES,
					'description' => __( 'PHP statements without opening or closing PHP tags. Standard buffered output is captured and bounded; execution is not sandboxed and can disrupt the request.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Ability output schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'output'          => [ 'type' => 'string' ],
				'outputTruncated' => [ 'type' => 'boolean' ],
				'returnType'      => [ 'type' => 'string' ],
				'returnValue'     => [ 'type' => 'string' ],
				'returnTruncated' => [ 'type' => 'boolean' ],
				'durationMs'      => [ 'type' => 'number' ],
			],
		];
	}

	/**
	 * Permission callback.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function permission( $input = [] ) {
		return self::authorize_request();
	}

	/**
	 * Execute PHP after repeating the complete authorization check.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input ) {
		$authorization = self::authorize_request();

		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		$code = isset( $input['code'] ) ? (string) $input['code'] : '';

		if ( trim( $code ) === '' ) {
			return Error::invalid_param( 'code', 'non-empty PHP statements without PHP tags', $code );
		}

		if ( strlen( $code ) > self::MAX_CODE_BYTES ) {
			return Error::invalid_param( 'code', sprintf( 'at most %d bytes', self::MAX_CODE_BYTES ), strlen( $code ) );
		}

		if ( strpos( $code, '<?' ) !== false || strpos( $code, '?>' ) !== false ) {
			return Error::invalid_param( 'code', 'PHP statements without opening or closing PHP tags', null );
		}

		$started_at       = microtime( true );
		$start_level      = ob_get_level();
		$result           = null;
		$output           = '';
		$output_truncated = false;
		$throwable        = null;
		$capture_closed   = false;

		// Retain a fallback buffer if evaluated code closes the primary capture once.
		ob_start();

		ob_start(
			static function ( $chunk, $phase ) use ( &$output, &$output_truncated, &$capture_closed ) {
				self::append_output( $output, $output_truncated, (string) $chunk );
				$capture_closed = $capture_closed || (bool) ( $phase & PHP_OUTPUT_HANDLER_FINAL );

				return '';
			},
			8192
		);
		$capture_level = ob_get_level();

		try {
			$result = self::evaluate( $code );
		} catch ( \Throwable $caught ) {
			$throwable = $caught;
		} finally {
			self::close_output_buffers( $start_level, $capture_level, $capture_closed, $output, $output_truncated );
		}

		if ( $throwable ) {
			return Error::execute_php_failed( $throwable->getMessage(), get_class( $throwable ), $output );
		}

		list( $return_value, $return_truncated ) = self::truncate_string( self::format_return_value( $result ) );

		return [
			'output'          => $output,
			'outputTruncated' => $output_truncated,
			'returnType'      => self::value_type( $result ),
			'returnValue'     => $return_value,
			'returnTruncated' => $return_truncated,
			'durationMs'      => round( ( microtime( true ) - $started_at ) * 1000, 2 ),
		];
	}

	/**
	 * Whether the ability is explicitly enabled through PHP configuration.
	 *
	 * @since 2.4
	 */
	public static function is_configured(): bool {
		return defined( 'BRICKS_ENABLE_PHP_ABILITIES' ) && constant( 'BRICKS_ENABLE_PHP_ABILITIES' ) === true;
	}

	/**
	 * Whether the ability is armed and callable by the current user.
	 *
	 * @since 2.4
	 */
	public static function is_available(): bool {
		return self::is_configured()
			&& ! is_wp_error( self::check_runtime_prerequisites() )
			&& self::application_passwords_available();
	}

	/**
	 * State used by the wp-admin experience.
	 *
	 * @since 2.4
	 */
	public static function admin_state(): array {
		$constant_status = self::constant_status();
		$configured      = $constant_status === 'true';

		return [
			'enabled'              => $configured && self::is_available(),
			'configured'           => $configured,
			'constantStatus'       => $constant_status,
			'abilitiesEnabled'     => Manager::is_enabled(),
			'codeExecution'        => \Bricks\Helpers::code_execution_enabled(),
			'canManageOptions'     => current_user_can( 'manage_options' ),
			'canExecuteCode'       => \Bricks\Capabilities::current_user_can_execute_code(),
			'signaturesLocked'     => self::signature_generation_locked(),
			'applicationPasswords' => self::application_passwords_available(),
		];
	}

	/**
	 * Authorize the current ability request.
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	public static function authorize_request() {
		$prerequisite = self::check_runtime_prerequisites();

		if ( is_wp_error( $prerequisite ) ) {
			return $prerequisite;
		}

		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return Error::execute_php_unavailable( 'application_password_required', __( 'PHP execution requires an authenticated WordPress Application Password request.', 'bricks' ) );
		}

		$credential_uuid = rest_get_authenticated_app_password();

		if ( ! is_string( $credential_uuid ) || $credential_uuid === '' ) {
			return Error::execute_php_unavailable( 'application_password_required', __( 'PHP execution requires an authenticated WordPress Application Password request.', 'bricks' ) );
		}

		return true;
	}

	/**
	 * Check non-negotiable runtime permissions.
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	private static function check_runtime_prerequisites() {
		if ( ! Manager::is_enabled() ) {
			return Error::execute_php_unavailable( 'abilities_disabled', __( 'Bricks abilities are disabled on this site.', 'bricks' ) );
		}

		if ( ! self::is_configured() ) {
			return Error::execute_php_unavailable( 'not_enabled', __( 'PHP execution is disabled. Set BRICKS_ENABLE_PHP_ABILITIES to true in PHP configuration to enable it.', 'bricks' ) );
		}

		if ( self::signature_generation_locked() ) {
			return Error::execute_php_unavailable( 'signatures_locked', __( 'PHP execution is unavailable while code signature generation is locked.', 'bricks' ) );
		}

		if ( ! self::application_passwords_available() ) {
			return Error::execute_php_unavailable( 'application_passwords_unavailable', __( 'Application Passwords are unavailable for the current user.', 'bricks' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::execute_php_unavailable( 'manage_options_required', __( 'PHP execution requires the manage_options capability.', 'bricks' ) );
		}

		if ( ! \Bricks\Helpers::code_execution_enabled() ) {
			return Error::execute_php_unavailable( 'code_execution_disabled', __( 'Enable Bricks code execution before using this ability.', 'bricks' ) );
		}

		if ( ! \Bricks\Capabilities::current_user_can_execute_code() ) {
			return Error::execute_php_unavailable( 'execute_code_required', __( 'PHP execution requires the Bricks Execute code capability.', 'bricks' ) );
		}

		return true;
	}

	/**
	 * Return a safe representation of the configuration constant.
	 *
	 * Do not expose unexpected constant values in wp-admin because they may
	 * contain sensitive configuration data.
	 *
	 * @since 2.4
	 */
	private static function constant_status(): string {
		if ( ! defined( 'BRICKS_ENABLE_PHP_ABILITIES' ) ) {
			return 'undefined';
		}

		$value = constant( 'BRICKS_ENABLE_PHP_ABILITIES' );

		if ( $value === true ) {
			return 'true';
		}

		if ( $value === false ) {
			return 'false';
		}

		return 'invalid';
	}

	/**
	 * Whether code signature generation is locked.
	 *
	 * @since 2.4
	 */
	private static function signature_generation_locked(): bool {
		return (bool) \Bricks\Helpers::code_signature_generation_locked();
	}

	/**
	 * Whether Application Password authentication is available for this user.
	 *
	 * @since 2.4
	 */
	private static function application_passwords_available(): bool {
		return class_exists( '\\WP_Application_Passwords' )
			&& function_exists( 'wp_is_application_passwords_available_for_user' )
			&& wp_is_application_passwords_available_for_user( wp_get_current_user() );
	}

	/**
	 * Close only output buffers created during execution.
	 *
	 * @since 2.4
	 *
	 * @param int    $start_level      Buffer depth before execution.
	 * @param int    $capture_level    Depth of the primary capture buffer.
	 * @param bool   $capture_closed   Whether evaluated code closed the primary capture buffer.
	 * @param string $output           Captured output, updated in place.
	 * @param bool   $output_truncated Whether captured output exceeded the response bound, updated in place.
	 */
	private static function close_output_buffers( int $start_level, int $capture_level, bool &$capture_closed, string &$output, bool &$output_truncated ): void {
		while ( ob_get_level() > $start_level ) {
			$current_level = ob_get_level();
			$status        = ob_get_status();

			// Evaluated code can create a non-removable buffer, which this request cannot safely close.
			if ( is_array( $status ) && isset( $status['flags'] ) && ! ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
				break;
			}

			if ( $current_level > $capture_level || ( $current_level === $capture_level && $capture_closed ) ) {
				if ( is_array( $status ) && isset( $status['flags'] ) && ! ( $status['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE ) ) {
					break;
				}

				ob_end_flush();
			} else {
				$chunk = ob_get_clean();

				if ( $current_level < $capture_level && is_string( $chunk ) ) {
					self::append_output( $output, $output_truncated, $chunk );
				}
			}

			// A user-defined output handler may still prevent removal despite reporting otherwise.
			if ( ob_get_level() >= $current_level ) {
				break;
			}
		}
	}

	/**
	 * Retain only the bounded output prefix while execution is in progress.
	 *
	 * @since 2.4
	 *
	 * @param string $output    Captured output, updated in place.
	 * @param bool   $truncated Whether output exceeded the response bound, updated in place.
	 * @param string $chunk     New output chunk.
	 */
	private static function append_output( string &$output, bool &$truncated, string $chunk ): void {
		$remaining = self::MAX_OUTPUT_BYTES - strlen( $output );

		if ( strlen( $chunk ) > $remaining ) {
			$truncated = true;
		}

		if ( $remaining > 0 ) {
			$output .= substr( $chunk, 0, $remaining );
		}
	}

	/**
	 * Evaluate code outside the response-control variable scope.
	 *
	 * @since 2.4
	 *
	 * @param string $code PHP statements.
	 * @return mixed
	 */
	private static function evaluate( string $code ) {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- This explicitly armed ability exists to execute administrator-supplied PHP.
		return eval( $code );
	}

	/**
	 * Bound a returned string so one call cannot produce an oversized response.
	 *
	 * @since 2.4
	 *
	 * @param string $value Value to bound.
	 * @return array{0: string, 1: bool}
	 */
	private static function truncate_string( string $value ): array {
		$truncated = strlen( $value ) > self::MAX_OUTPUT_BYTES;

		return [ $truncated ? substr( $value, 0, self::MAX_OUTPUT_BYTES ) : $value, $truncated ];
	}

	/**
	 * Convert an arbitrary PHP return value into a stable string.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Return value to format.
	 */
	private static function format_return_value( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) || $value === null ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Produces a stable response representation without emitting debug output.
			return var_export( $value, true );
		}

		if ( is_resource( $value ) ) {
			return sprintf( '[resource: %s]', get_resource_type( $value ) );
		}

		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		return is_string( $encoded ) ? $encoded : sprintf( '[%s]', self::value_type( $value ) );
	}

	/**
	 * Return a useful PHP 7.4-compatible type label.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Value to inspect.
	 */
	private static function value_type( $value ): string {
		return is_object( $value ) ? get_class( $value ) : gettype( $value );
	}
}
