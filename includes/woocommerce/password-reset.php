<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Share Woo's validated reset credentials between Bricks account elements.
 *
 * WooCommerce 11.2 accepts a single-use URL bridge when the reset cookie is
 * unavailable, and signed form credentials on validation retries. Let its
 * shortcode choose the view so both V1 and V2 inherit the same validation.
 * Preparation is cached for this request only; never persist reset secrets.
 *
 * @see https://github.com/woocommerce/woocommerce/pull/68334
 * @since 2.4.3
 */
class Woocommerce_Password_Reset {
	/**
	 * Native view and arguments, cached because bridge handles are single use.
	 *
	 * @var array|null
	 */
	private static $prepared = null;

	/**
	 * Prepare before an earlier Notice element, only when Bricks owns the account.
	 *
	 * Preparing a native shortcode account here would consume its bridge before
	 * Woo renders it. Check both saved page content and active template content
	 * because either can supply the Bricks V1/V2 account element.
	 *
	 * @since 2.4.3
	 * @return void
	 */
	public static function maybe_prepare() {
		global $wp;

		if ( bricks_is_builder() || bricks_is_builder_call() || ! is_account_page() || ! isset( $wp->query_vars['lost-password'] ) ) {
			return;
		}

		$page_data    = get_post_meta( wc_get_page_id( 'myaccount' ), BRICKS_DB_PAGE_CONTENT, true );
		$content_data = Database::$bricks_data['content'] ?? [];
		$data         = array_merge( is_array( $page_data ) ? $page_data : [], is_array( $content_data ) ? $content_data : [] );

		foreach ( $data as $element ) {
			$name = $element['name'] ?? '';
			if ( $name === 'woocommerce-account-page' || ( $name === 'woocommerce-account-page-v2' && Woocommerce::use_advanced_modular_elements() ) ) {
				self::prepare();
				return;
			}
		}
	}

	/**
	 * Prepare through Woo so it owns cookie, bridge, and signed POST validation.
	 *
	 * Capturing the selected template preserves Woo's validation notices without
	 * rendering its form. Output buffering alone would still let that form print
	 * and clear notices before the Bricks Notice element can display them.
	 *
	 * @since 2.4.3
	 * @return void
	 */
	private static function prepare() {
		global $wp;

		if ( self::$prepared !== null || bricks_is_builder() || bricks_is_builder_call() || ! is_account_page() || ! isset( $wp->query_vars['lost-password'] ) ) {
			return;
		}

		self::$prepared = [
			'state' => 'lost-password',
			'args'  => []
		];

		// Preserve the legacy confirmation URL accepted by Bricks.
		if ( isset( $_GET['wc-reset-password'] ) && $_GET['wc-reset-password'] === 'reset-link-sent' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only state; Woo validates credentials below.
			self::$prepared['state'] = 'lost-password-confirmation';
			return;
		}

		add_filter( 'wc_get_template', [ __CLASS__, 'capture_template' ], PHP_INT_MAX, 3 );
		$level = ob_get_level();
		ob_start();

		try {
			\WC_Shortcode_My_Account::lost_password();
		} finally {
			remove_filter( 'wc_get_template', [ __CLASS__, 'capture_template' ], PHP_INT_MAX );
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Capture the prepared view without rendering or consuming its notices.
	 *
	 * @since 2.4.3
	 *
	 * @param string $template Located template.
	 * @param string $name     Template name.
	 * @param array  $args     Native template arguments.
	 * @return string Original template or the empty capture view.
	 */
	public static function capture_template( $template, $name, $args ) {
		$states = [
			'myaccount/form-reset-password.php'        => 'reset-password',
			'myaccount/form-lost-password.php'         => 'lost-password',
			'myaccount/lost-password-confirmation.php' => 'lost-password-confirmation',
		];

		if ( ! isset( $states[ $name ] ) ) {
			return $template;
		}

		self::$prepared = [
			'state' => $states[ $name ],
			'args'  => $args
		];
		return __DIR__ . '/templates/password-reset.php';
	}

	/**
	 * Get the validated frontend view for either account setup.
	 *
	 * @since 2.4.3
	 * @return string Woo-selected state, or lost-password outside a reset request.
	 */
	public static function get_state() {
		self::prepare();
		return self::$prepared['state'] ?? 'lost-password';
	}

	/**
	 * Reuse the validated credentials without exposing real secrets in previews.
	 *
	 * @since 2.4.3
	 * @return array Validated key and login, or empty strings for Builder previews.
	 */
	public static function get_args() {
		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			return [
				'key'   => '',
				'login' => ''
			];
		}

		self::prepare();
		$args = self::$prepared['args'] ?? [];
		return [
			'key'   => $args['key'] ?? '',
			'login' => $args['login'] ?? ''
		];
	}
}
