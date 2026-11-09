<?php
/**
 * HTML to Bricks Error Handling
 *
 * Simple error/warning utilities for the converter.
 * Only logs meaningful errors - suppresses expected warnings.
 *
 * PHP port of src/vue/utils/htmlToBricks/errors.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error codes for actual failures.
 */
class Html_To_Bricks_Error_Codes {
	const PARSE_ERROR       = 'PARSE_ERROR';
	const INVALID_HTML      = 'INVALID_HTML';
	const EMPTY_INPUT       = 'EMPTY_INPUT';
	const CONVERSION_FAILED = 'CONVERSION_FAILED';
	const VALIDATION_FAILED = 'VALIDATION_FAILED';
}

/**
 * Warning codes (for internal tracking, not logged by default).
 */
class Html_To_Bricks_Warning_Codes {
	const UNSUPPORTED_ELEMENT = 'UNSUPPORTED_ELEMENT';
	const DEEP_NESTING        = 'DEEP_NESTING';
}

/**
 * Simple error collector for internal tracking.
 *
 * @since 2.4
 */
class Html_To_Bricks_Error_Collector {
	/**
	 * Collected errors.
	 *
	 * @var array
	 */
	private $errors = [];

	/**
	 * Collected warnings.
	 *
	 * @var array
	 */
	private $warnings = [];

	/**
	 * Add an error.
	 *
	 * @since 2.4
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $details Additional details.
	 */
	public function add_error( $code, $message, $details = [] ) {
		$error          = array_merge(
			[
				'code'    => $code,
				'message' => $message
			],
			$details
		);
		$this->errors[] = $error;
	}

	/**
	 * Add a warning.
	 *
	 * @since 2.4
	 *
	 * @param string $code    Warning code.
	 * @param string $message Warning message.
	 * @param array  $details Additional details.
	 */
	public function add_warning( $code, $message, $details = [] ) {
		$warning          = array_merge(
			[
				'code'    => $code,
				'message' => $message
			],
			$details
		);
		$this->warnings[] = $warning;
	}

	/**
	 * Check if there are any errors.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	public function has_errors() {
		return count( $this->errors ) > 0;
	}

	/**
	 * Get all errors.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Get all warnings.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public function get_warnings() {
		return $this->warnings;
	}

	/**
	 * Merge another error collector into this one.
	 *
	 * @since 2.4
	 *
	 * @param Html_To_Bricks_Error_Collector $other The other collector.
	 */
	public function merge( $other ) {
		if ( $other instanceof self ) {
			$this->errors   = array_merge( $this->errors, $other->errors );
			$this->warnings = array_merge( $this->warnings, $other->warnings );
		}
	}

	/**
	 * Log all collected errors (uses error_log in debug mode).
	 *
	 * @since 2.4
	 */
	public function log_errors() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		foreach ( $this->errors as $err ) {
			$message = '[Bricks HTML Import] ' . ( $err['message'] ?? '' );

			if ( ! empty( $err['reason'] ) ) {
				$message .= ' | Reason: ' . $err['reason'];
			}

			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Clear all errors and warnings.
	 *
	 * @since 2.4
	 */
	public function clear() {
		$this->errors   = [];
		$this->warnings = [];
	}
}
