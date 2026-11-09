<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Prepared Woo withdrawal template context for modular account content.
 *
 * @since 2.4
 */
class Woocommerce_Order_Withdrawal {
	/**
	 * Arguments prepared by Woo for this request; reused to avoid processing a POST twice.
	 *
	 * @var array|null
	 */
	private static $prepared = null;
	/**
	 * Context exposed only while rendering the withdrawal subtree.
	 *
	 * @var array|null
	 */
	private static $context = null;
	/**
	 * Current custom template renderer.
	 *
	 * @var callable|null
	 */
	private static $renderer = null;

	/**
	 * Render through Woo's shortcode so its processor remains the authority.
	 *
	 * @param callable $renderer Custom subtree renderer receiving the prepared notice HTML.
	 * @return string
	 *
	 * @since 2.4
	 */
	public static function render( $renderer ) {
		if ( bricks_is_builder() || bricks_is_builder_call() || ! Woocommerce::is_order_withdrawal_request() || self::$renderer ) {
			return '';
		}

		self::$renderer = $renderer;
		$level          = ob_get_level();

		try {
			if ( self::$prepared !== null ) {
				ob_start();
				self::render_template();
				return ob_get_clean();
			}

			add_filter( 'wc_get_template', [ __CLASS__, 'capture_template' ], 100, 5 );
			return do_shortcode( '[woocommerce_my_account]' );
		} finally {
			remove_filter( 'wc_get_template', [ __CLASS__, 'capture_template' ], 100 );
			self::$renderer = null;
			self::$context  = null;
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Substitute only the withdrawal view, after Woo has prepared its arguments.
	 *
	 * @param string $template Located template.
	 * @param string $name Template name.
	 * @param array  $args Prepared arguments.
	 * @return string
	 *
	 * @since 2.4
	 */
	public static function capture_template( $template, $name, $args ) {
		if ( $name !== 'myaccount/form-order-withdrawal.php' || ! self::$renderer ) {
			return $template;
		}

		self::$prepared = is_array( $args ) ? $args : [];
		return __DIR__ . '/templates/order-withdrawal.php';
	}

	/**
	 * Render the custom subtree with locally scoped prepared data and notices.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public static function render_template() {
		if ( ! self::$renderer || self::$prepared === null ) {
			return;
		}

		self::$context = self::$prepared;
		add_filter( 'pre_do_shortcode_tag', [ __CLASS__, 'prevent_nested_shortcode' ], 10, 2 );
		try {
			// Capture after processing but before child Notice elements can consume the errors.
			// The renderer places them inside the state, not beside it in Woo's logged-in flex layout.
			$notices = wc_print_notices( true );
			call_user_func( self::$renderer, $notices );
		} finally {
			remove_filter( 'pre_do_shortcode_tag', [ __CLASS__, 'prevent_nested_shortcode' ], 10 );
			self::$context = null;
		}
	}

	/**
	 * Avoid processing the same request again through a shortcode in the subtree.
	 *
	 * @param mixed  $output Existing shortcode short circuit.
	 * @param string $tag Shortcode name.
	 * @return mixed
	 *
	 * @since 2.4
	 */
	public static function prevent_nested_shortcode( $output, $tag ) {
		return $tag === 'woocommerce_my_account' && self::$context !== null ? '' : $output;
	}

	/**
	 * Get prepared data or synthetic, non-submitting Builder data.
	 *
	 * @return array
	 *
	 * @since 2.4
	 */
	public static function get_context() {
		if ( ! Woocommerce::use_advanced_modular_elements() || ! Woocommerce::is_order_withdrawal_enabled() ) {
			return [];
		}

		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			return self::get_preview_context();
		}

		return self::$context ?? [];
	}

	/**
	 * Return the native screens available to frontend display conditions.
	 *
	 * @return array
	 *
	 * @since 2.4
	 */
	public static function get_screens() {
		return [
			'form'         => esc_html__( 'Your details', 'bricks' ),
			'review'       => esc_html__( 'Review', 'bricks' ),
			'confirmation' => esc_html__( 'Confirmation', 'bricks' ),
		];
	}

	/**
	 * Build illustrative data without invoking Woo's processor or reading POST.
	 *
	 * @return array
	 *
	 * @since 2.4
	 */
	public static function get_preview_context() {
		$labels = [
			'first_name'         => esc_html__( 'First name', 'bricks' ),
			'last_name'          => esc_html__( 'Last name', 'bricks' ),
			'email'              => esc_html__( 'Email address', 'bricks' ),
			'email_confirmation' => esc_html__( 'Confirm email address', 'bricks' ),
			'order_number'       => esc_html__( 'Order number', 'bricks' ),
			'withdrawal_type'    => esc_html__( 'Withdrawal type', 'bricks' ),
			'additional_details' => esc_html__( 'Additional details', 'bricks' ),
		];
		$fields = [];
		foreach ( $labels as $key => $label ) {
			$type = 'text';
			if ( in_array( $key, [ 'email', 'email_confirmation' ], true ) ) {
				$type = 'email';
			} elseif ( $key === 'withdrawal_type' ) {
				$type = 'radio';
			} elseif ( $key === 'additional_details' ) {
				$type = 'textarea';
			}
			$fields[ $key ] = [
				'name'     => 'order_withdrawal_' . $key,
				'id'       => 'order_withdrawal_' . $key,
				'type'     => $type,
				'label'    => $label,
				'required' => $key !== 'additional_details',
				'class'    => [ 'form-row-wide' ],
			];
		}

		// Builder data never selects a live screen or invokes submission processing.
		return [
			'screen'                  => '',
			'fields'                  => $fields,
			'errors'                  => [],
			'data'                    => [
				'first_name'         => 'Alex',
				'last_name'          => 'Example',
				'email'              => 'alex@example.com',
				'email_confirmation' => 'alex@example.com',
				'order_number'       => '1234',
				'withdrawal_type'    => 'full_order',
				'additional_details' => '',
			],
			'withdrawal_type_options' => [
				'full_order'          => esc_html__( 'Entire order', 'bricks' ),
				'specific_items_only' => esc_html__( 'Specific items only', 'bricks' ),
			],
		];
	}

	/**
	 * Return only supported submitted values, never data from a matched order.
	 *
	 * @param string $key Submitted field key.
	 * @return string
	 *
	 * @since 2.4
	 */
	public static function get_value( $key ) {
		$context = self::get_context();
		$keys    = [ 'first_name', 'last_name', 'email', 'email_confirmation', 'order_number', 'withdrawal_type', 'additional_details' ];
		if ( $key === 'withdrawal_type_label' ) {
			return (string) ( $context['withdrawal_type_options'][ $context['data']['withdrawal_type'] ?? '' ] ?? '' );
		}
		if ( $key === 'additional_details' && $context && ( $context['data'][ $key ] ?? '' ) === '' ) {
			return esc_html__( 'None provided', 'bricks' );
		}
		return in_array( $key, $keys, true ) ? (string) ( $context['data'][ $key ] ?? '' ) : '';
	}
}
