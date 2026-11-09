<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Product_Rating extends Element {
	public $category = 'woocommerce_product';
	public $name     = 'product-rating';
	public $icon     = 'ti-medall';

	public function get_label() {
		return esc_html__( 'Product rating', 'bricks' );
	}

	public function set_controls() {
		$this->controls['starColor'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Star color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'selector' => '.star-rating span::before',
					'property' => 'color',
				],
			],
		];

		$this->controls['emptyStarColor'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Empty star color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'selector' => '.star-rating::before',
					'property' => 'color',
				],
			],
		];

		/**
		 * Show Reviews Link
		 *
		 * Disable the output of reviews link instead of hiding it (@see Woocommerce_Helpers::render_product_rating)
		 *
		 * @since 1.8
		 */
		$this->controls['hideReviewsLink'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Hide reviews link', 'bricks' ),
			'type'  => 'checkbox',
		];

		// REVIEWS LINK TEXT
		$this->controls['reviewsLinkSeparator'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Reviews link', 'bricks' ),
			'type'        => 'separator',
			'description' => esc_html__( 'Use %s for the review count. Leave empty to use the default text.', 'bricks' ),
			'required'    => [ 'hideReviewsLink', '=', '' ],
		];

		$this->controls['reviewsLinkTextSingle'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Text', 'bricks' ) . ': ' . esc_html__( 'Single', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( '%s customer review', 'woocommerce' ),
			'required'    => [ 'hideReviewsLink', '=', '' ],
		];

		$this->controls['reviewsLinkTextPlural'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Text', 'bricks' ) . ': ' . esc_html__( 'Plural', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html__( '%s customer reviews', 'woocommerce' ),
			'required'    => [ 'hideReviewsLink', '=', '' ],
		];

		// NO RATINGS
		$this->controls['noRatings'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'No ratings', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['noRatingsText'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Text', 'bricks' ),
			'type'     => 'text',
			'required' => [ 'noRatingsStars', '=', '' ],
		];

		$this->controls['noRatingsStars'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Show empty stars', 'bricks' ),
			'type'  => 'checkbox',
		];
	}

	public function render() {
		$settings = $this->settings;

		if ( ! wc_review_ratings_enabled() ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'Product ratings are disabled.', 'bricks' ),
				]
			);
		}

		global $product;
		$product = wc_get_product( $this->post_id );

		if ( empty( $product ) ) {
			return $this->render_element_placeholder(
				[
					'title'       => esc_html__( 'For better preview select content to show.', 'bricks' ),
					'description' => esc_html__( 'Go to: Settings > Template Settings > Populate Content', 'bricks' ),
				]
			);
		}

		$show_empty_stars  = isset( $settings['noRatingsStars'] );
		$hide_reviews_link = isset( $settings['hideReviewsLink'] );

		$rating_html = '';

		if ( $show_empty_stars || $product->get_rating_count() ) {
			$params = [
				'wrapper'           => true,
				'show_empty_stars'  => $show_empty_stars,
				'hide_reviews_link' => $hide_reviews_link,
			];

			// Pass custom review link text if set
			if ( ! empty( $settings['reviewsLinkTextSingle'] ) ) {
				$params['reviews_link_text_single'] = $settings['reviewsLinkTextSingle'];
			}

			if ( ! empty( $settings['reviewsLinkTextPlural'] ) ) {
				$params['reviews_link_text_plural'] = $settings['reviewsLinkTextPlural'];
			}

			$rating_html = Woocommerce_Helpers::render_product_rating( $product, $params, false );
		}

		// No ratings txt
		elseif ( ! empty( $settings['noRatingsText'] ) ) {
			$rating_html = $settings['noRatingsText'];
		} else {
			$rating_html = $this->render_element_placeholder( [ 'title' => esc_html__( 'No ratings yet.', 'bricks' ) ] );
		}

		// Return: No ratings, and no text or stars to show
		if ( ! $rating_html ) {
			return;
		}

		echo "<div {$this->render_attributes( '_root' )}>$rating_html</div>";
	}
}
