<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Notice extends Element {
	public $category = 'woocommerce';
	public $name     = 'woocommerce-notice';
	public $icon     = 'ti-announcement';

	public function get_label() {
		return esc_html__( 'Notice', 'bricks' );
	}

	public function get_keywords() {
		return [ 'alert', 'message', 'woo' ];
	}

	public function set_control_groups() {
		$this->control_groups['error'] = [
			'title' => esc_html__( 'Type', 'bricks' ) . ' - ' . esc_html__( 'Error', 'bricks' ),
			'tab'   => 'content',
		];

		$this->control_groups['success'] = [
			'title' => esc_html__( 'Type', 'bricks' ) . ' - ' . esc_html__( 'Success', 'bricks' ),
			'tab'   => 'content',
		];

		$this->control_groups['notice'] = [
			'title' => esc_html__( 'Type', 'bricks' ) . ' - ' . esc_html__( 'Notice', 'bricks' ),
			'tab'   => 'content',
		];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'tab'     => 'content',
			'type'    => 'info',
			'content' => esc_html__( 'Style notices globally under Settings > Theme Styles > WooCommerce - Notice.', 'bricks' ),
		];

		$this->controls['previewType'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Preview notice type', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'all'     => esc_html__( 'All', 'bricks' ),
				'success' => esc_html__( 'Success', 'bricks' ),
				'notice'  => esc_html__( 'Notice', 'bricks' ),
				'error'   => esc_html__( 'Error', 'bricks' ),
			],
			'default'     => 'all',
			'inline'      => true,
			'clearable'   => false,
			'description' => esc_html__( 'Only applied in builder and template preview.', 'bricks' ),
		];

		$sections = [
			'layout' => [
				'success' => '.woocommerce-message',
				'notice'  => '.woocommerce-info',
				'error'   => '.woocommerce-error',
			],
			'link'   => [
				'success' => '.woocommerce-message a, .woocommerce-message a.button',
				'notice'  => '.woocommerce-info a, .woocommerce-info a.button',
				'error'   => '.woocommerce-error a, .woocommerce-error a.button',
			],
		];

		foreach ( $sections as $section => $types ) {
			foreach ( $types as $type => $selector ) {
				// successMargin, successLinkMargin, noticeMargin, noticeLinkMargin, errorMargin, errorLinkMargin
				$control_prefix = $type;

				if ( $section === 'link' ) {
					$control_prefix = "{$control_prefix}Link";

					$this->controls[ $control_prefix . 'Separator' ] = [
						'tab'   => 'content',
						'label' => esc_html__( 'Link', 'bricks' ),
						'group' => $type,
						'type'  => 'separator',
					];
				}

				$this->controls[ $control_prefix . 'Margin' ] = [
					'tab'   => 'content',
					'label' => esc_html__( 'Margin', 'bricks' ),
					'group' => $type,
					'type'  => 'spacing',
					'css'   => [
						[
							'property' => 'margin',
							'selector' => $selector,
						],
					],
				];

				$this->controls[ $control_prefix . 'Padding' ] = [
					'tab'   => 'content',
					'label' => esc_html__( 'Padding', 'bricks' ),
					'group' => $type,
					'type'  => 'spacing',
					'css'   => [
						[
							'property' => 'padding',
							'selector' => $selector,
						],
					],
				];

				$this->controls[ $control_prefix . 'BackgroundColor' ] = [
					'tab'   => 'content',
					'label' => esc_html__( 'Background color', 'bricks' ),
					'group' => $type,
					'type'  => 'color',
					'css'   => [
						[
							'property' => 'background-color',
							'selector' => $selector,
						],
					],
				];

				$this->controls[ $control_prefix . 'Border' ] = [
					'tab'   => 'content',
					'label' => esc_html__( 'Border', 'bricks' ),
					'group' => $type,
					'type'  => 'border',
					'css'   => [
						[
							'property' => 'border',
							'selector' => $selector,
						],
					],
				];

				$this->controls[ $control_prefix . 'BoxShadow' ] = [
					'tab'   => 'content',
					'label' => esc_html__( 'Box shadow', 'bricks' ),
					'group' => $type,
					'type'  => 'box-shadow',
					'css'   => [
						[
							'property' => 'box-shadow',
							'selector' => $selector,
						],
					],
				];

				$this->controls[ $control_prefix . 'Typography' ] = [
					'tab'   => 'content',
					'group' => $type,
					'label' => esc_html__( 'Typography', 'bricks' ),
					'type'  => 'typography',
					'css'   => [
						[
							'property' => 'font',
							'selector' => $selector,
						],
					],
				];
			}
		}
	}

	public function render() {
		$notices = $this->get_woo_notices_or_populate_builder_notices();

		$this->set_attribute( '_root', 'class', 'woocommerce-notices-wrapper' );

		echo "<div {$this->render_attributes( '_root' )}>" . $notices . '</div>';
	}

	/**
	 * Populate some notices for the builder and template preview or return the WooCommerce notices
	 *
	 * @return string
	 */
	public function get_woo_notices_or_populate_builder_notices() {
		// In Rest API, wc frontend function is not available (@since 1.9.4)
		if ( ! function_exists( 'wc_print_notices' ) || ! function_exists( 'wc_clear_notices' ) || ! function_exists( 'wc_get_notices' ) ) {
			return '';
		}

		// Return & render actual WooCommerce notices on the frontend
		if (
			! bricks_is_builder_main() &&
			! bricks_is_builder_iframe() &&
			! bricks_is_builder_call() &&
			! isset( $_GET['bricks_preview'] )
		) {
			/**
			 * Classic Account pages can render this element before WooCommerce queues the reset notice.
			 * Account v2 normally queues it first, so the preparation method reuses that existing notice.
			 *
			 * @since 2.3.12 #86cb6uj1x
			 */
			Woocommerce::maybe_prepare_password_reset_notice();

			$checkout_notices = Woocommerce::get_checkout_notices();
			$notices          = wc_get_notices();

			return $checkout_notices . $this->enhance_notice_markup( wc_print_notices( true ), $notices );
		}

		$notices = '';

		// To clear any notices that may have been set by WooCommerce as we are populating the builder with some dummy notices
		wc_clear_notices();

		$dummy_messages = [
			'success' => [
				[
					'text' => '<a href="#" tabindex="1" class="button wc-forward">View cart</a> This is a success notice.',
					'data' => [],
				],
			],

			'notice'  => [
				[
					'text' => 'This is a notice. <a href="#">This is a button</a>',
					'data' => [],
				],
			],

			'error'   => [
				[
					'text' => 'This is an error notice. <a href="#" class="button wc-forward">View cart</a>',
					'data' => [],
				],
				[
					'text' => '<strong>Billing Postcode / ZIP</strong> is a required field.',
					'data' => [ 'id' => 'billing_postcode' ],
				],
				[
					'text' => '<strong>Billing Phone</strong> is a required field.',
					'data' => [ 'id' => 'billing_phone' ],
				],
			],
		];

		$preview_type = ! empty( $this->settings['previewType'] ) ? $this->settings['previewType'] : '';

		switch ( $preview_type ) {
			case 'all':
				break;

			case 'notice':
				unset( $dummy_messages['success'], $dummy_messages['error'] );
				break;

			case 'error':
				unset( $dummy_messages['success'], $dummy_messages['notice'] );
				break;

			default:
			case 'success':
				unset( $dummy_messages['notice'], $dummy_messages['error'] );
				break;
		}

		foreach ( $dummy_messages as $type => $messages ) {
			foreach ( $messages as $message ) {
				wc_add_notice( $message['text'], $type, $message['data'] );
			}
		}

		$notices = wc_get_notices();
		ob_start();
		wc_print_notices();
		return $this->enhance_notice_markup( ob_get_clean(), $notices );
	}

	/**
	 * Add stable field-target metadata to error notices.
	 *
	 * Checkout step routing and Account edit-address field highlighting both need
	 * a reliable field ID when custom layouts move fields away from Woo's native templates.
	 *
	 * @since 2.4
	 *
	 * @param string $markup Notice markup.
	 * @param array  $notices Notice registry.
	 *
	 * @return string
	 */
	private function enhance_notice_markup( $markup, $notices ) {
		if ( empty( $markup ) ) {
			return $markup;
		}

		if ( ( empty( $notices['error'] ) || ! is_array( $notices['error'] ) ) && strpos( $markup, 'data-id=' ) === false ) {
			return $markup;
		}

		// Builder dummy notices are only illustrative and should not mark preview fields invalid.
		if ( ! bricks_is_builder() && ! bricks_is_builder_call() && ! bricks_is_builder_iframe() && ! isset( $_GET['bricks_preview'] ) ) {
			Woocommerce::get_woo_notice_field_errors( $notices );
		}

		$field_ids     = Woocommerce::get_woo_notice_field_error_ids( $notices );
		$has_field_ids = (bool) array_filter( $field_ids );

		if ( ! $has_field_ids && strpos( $markup, 'data-id=' ) === false ) {
			return $markup;
		}

		if ( ! class_exists( '\DOMDocument' ) ) {
			return $markup;
		}

		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="brx-woo-notices-root">' . $markup . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		if ( ! $loaded ) {
			return $markup;
		}

		$xpath      = new \DOMXPath( $dom );
		$list_items = $xpath->query(
			'//ul[contains(concat(" ", normalize-space(@class), " "), " woocommerce-error ")]/li[not(ancestor::li)]'
		);
		$error_i    = 0;

		if ( ! $list_items ) {
			return $markup;
		}

		foreach ( $list_items as $list_item ) {
			$field_id = $field_ids[ $error_i ] ?? '';

			// Some Woo/plugin notices already include data-id, so preserve that target even when no registry entry was available.
			if ( ! $field_id && $list_item->hasAttribute( 'data-id' ) ) {
				$field_id = sanitize_text_field( $list_item->getAttribute( 'data-id' ) );
			}

			if ( $field_id ) {
				$list_item->setAttribute( 'data-id', $field_id );
				$list_item->setAttribute( 'data-brx-notice-field-id', $field_id );
			}

			$error_i++;
		}

		$root = $dom->getElementById( 'brx-woo-notices-root' );

		if ( ! $root ) {
			return $markup;
		}

		$enhanced_markup = '';

		foreach ( $root->childNodes as $child ) {
			$enhanced_markup .= $dom->saveHTML( $child );
		}

		return $enhanced_markup ? $enhanced_markup : $markup;
	}
}
