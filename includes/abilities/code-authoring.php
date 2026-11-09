<?php
namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Apply ability authoring checks without changing native Code element behavior.
 *
 * @since 2.4
 */
class Code_Authoring {
	/**
	 * Whether the PHP/HTML field can invoke server-side code.
	 *
	 * Dynamic sources remain on the signed PHP path: classification of their
	 * current output alone cannot authorize future values.
	 *
	 * @param array $settings Code element settings.
	 * @return bool
	 */
	public static function requires_php( array $settings ): bool {
		return strpos( (string) ( $settings['code'] ?? '' ), '<?' ) !== false
			|| ! empty( $settings['useDynamicData'] )
			|| isset( $settings['parseDynamicData'] );
	}

	/**
	 * Check non-PHP content against the current WordPress capabilities.
	 *
	 * @param array $settings Code element settings.
	 * @return bool
	 */
	public static function can_author_without_php( array $settings ): bool {
		if ( self::requires_php( $settings ) ) {
			return false;
		}

		if ( current_user_can( 'unfiltered_html' ) ) {
			return true;
		}

		// CSS must not escape its style element and introduce active HTML.
		$css = (string) ( $settings['cssCode'] ?? '' );
		if ( ! empty( $settings['javascriptCode'] ) || strpos( $css, '<style>' ) !== false || preg_match( '~</style(?:[\s/>]|$)~i', $css ) ) {
			return false;
		}

		$html = (string) ( $settings['code'] ?? '' );
		return $html === '' || $html === wp_kses_post( $html );
	}

	/**
	 * Keep only fields whose authoring requires permissions the user lacks.
	 *
	 * Comparing these fields lets an editor change CSS beside unchanged PHP or
	 * JavaScript without granting access to that executable source.
	 *
	 * @param array $settings Code element settings.
	 * @param bool  $can_php Whether this operation permits PHP authoring.
	 * @return array
	 */
	public static function restricted_settings( array $settings, bool $can_php ): array {
		$keys = [];
		// Native Code elements require Execute code permission in execution mode, for every language.
		if ( isset( $settings['executeCode'] ) && ! \Bricks\Capabilities::current_user_can_execute_code() ) {
			return $settings;
		}
		// The native PHP/HTML field requires a signature even when it contains only HTML.
		if ( self::requires_php( $settings ) || ( isset( $settings['executeCode'] ) && (string) ( $settings['code'] ?? '' ) !== '' ) ) {
			if ( ! $can_php ) {
				$keys = [ 'code', 'useDynamicData', 'parseDynamicData', 'supressPhpErrors', 'signature', 'executeCode' ];
			}
		} elseif ( ! self::can_author_without_php( [ 'code' => $settings['code'] ?? '' ] ) ) {
			$keys = [ 'code', 'executeCode' ];
		}
		foreach ( [ 'cssCode', 'javascriptCode' ] as $key ) {
			if ( ! self::can_author_without_php( [ $key => $settings[ $key ] ?? '' ] ) ) {
				$keys[] = $key;
				$keys[] = 'executeCode';
			}
		}
		return array_intersect_key( $settings, array_flip( $keys ) );
	}

}
