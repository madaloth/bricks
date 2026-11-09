<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Product_Gallery extends Element {
	public $category = 'woocommerce_product';
	public $name     = 'product-gallery';
	public $icon     = 'ti-gallery';
	public $scripts  = [ 'bricksWooProductGallery' ];
	public $product  = false;

	private static $quick_view_photoswipe_styles_added = false;

	public function enqueue_scripts() {
		wp_enqueue_script( 'wc-single-product' );

		$use_wc_script_prefix = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '10.3.0', '>=' );

		// Use 'wc-flexslider' for WooCommerce 10.3.0+
		wp_enqueue_script( $use_wc_script_prefix ? 'wc-flexslider' : 'flexslider' );

		/**
		 * Enqueue all PhotoSwipe assets on all pages
		 *
		 * Otherwise the gallery is not working correctly on non-single product pages.
		 *
		 * @since 2.1.3
		 */
		wp_enqueue_script( 'wc-single-product' );

		// Use 'wc-photoswipe-ui-default' for WooCommerce 10.3.0+
		wp_enqueue_script( $use_wc_script_prefix ? 'wc-photoswipe-ui-default' : 'photoswipe-ui-default' );

		wp_enqueue_style( 'photoswipe-default-skin' );

		// Only add Quick View PhotoSwipe default styles once to avoid duplicates if multiple galleries are present on the page. (#86c572wqp; @since 2.3.5)
		if ( ! self::$quick_view_photoswipe_styles_added ) {
			wp_add_inline_style( 'photoswipe-default-skin', self::get_quick_view_photoswipe_styles() );
			self::$quick_view_photoswipe_styles_added = true;
		}

		add_action( 'wp_footer', 'woocommerce_photoswipe' );

		if ( bricks_is_builder_iframe() ) {
			// Use 'wc-zoom' for WooCommerce 10.3.0+
			wp_enqueue_script( $use_wc_script_prefix ? 'wc-zoom' : 'zoom' );
		} elseif ( ! Database::get_setting( 'woocommerceDisableProductGalleryZoom', false ) ) {
			// Use 'wc-zoom' for WooCommerce 10.3.0+
			wp_enqueue_script( $use_wc_script_prefix ? 'wc-zoom' : 'zoom' );
		}
	}

	/**
	 * Get Quick View PhotoSwipe default styles.
	 *
	 * @since 2.3.5
	 *
	 * @return string
	 */
	public static function get_quick_view_photoswipe_styles() {
		// Woo PhotoSwipe is rendered outside the popup, so scope this override to an open Quick View popup. Similar to _photoswipe.scss
		return 'body:has(.brx-popup:not(.hide) .brx-woo-quick-view) .pswp:not(.brx){--pswp-bg:rgba(0,0,0,.8);z-index:100000;}body:has(.brx-popup:not(.hide) .brx-woo-quick-view) .pswp:not(.brx) .pswp__bg{background:var(--pswp-bg);}';
	}

	public function get_label() {
		return esc_html__( 'Product gallery', 'bricks' );
	}

	public function set_controls() {
		$this->controls['_width']['rerender']    = true;
		$this->controls['_widthMin']['rerender'] = true;
		$this->controls['_widthMax']['rerender'] = true;

		$this->controls['productImageSize'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Product', 'bricks' ) . ': ' . esc_html__( 'Image size', 'bricks' ),
			'type'        => 'select',
			'options'     => Setup::get_image_sizes_options(),
			'placeholder' => 'woocommerce_single',
		];

		$this->controls['thumbnailImageSize'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Thumbnail', 'bricks' ) . ': ' . esc_html__( 'Image size', 'bricks' ),
			'type'        => 'select',
			'options'     => Setup::get_image_sizes_options(),
			'placeholder' => 'woocommerce_gallery_thumbnail',
		];

		$this->controls['lightboxImageSize'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Lightbox', 'bricks' ) . ': ' . esc_html__( 'Image size', 'bricks' ),
			'type'        => 'select',
			'options'     => Setup::get_image_sizes_options(),
			'placeholder' => 'full',
		];

		// THUMBNAILS

		$this->controls['thumbnailSep'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Thumbnail navigation', 'bricks' ),
			'type'  => 'separator',
		];

		$this->controls['thumbnailPosition'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Position', 'bricks' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => [
				'top'    => esc_html_x( 'Top', 'position', 'bricks' ),
				'right'  => esc_html__( 'Right', 'bricks' ),
				'bottom' => esc_html__( 'Bottom', 'bricks' ),
				'left'   => esc_html__( 'Left', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Bottom', 'bricks' ),
		];

		$this->controls['itemWidth'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Item width', 'bricks' ) . ' (px)',
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[
					'selector' => '&[data-pos="right"] .woocommerce-product-gallery .flex-control-nav',
					'property' => 'width',
				],
				[
					'selector' => '&[data-pos="left"] .woocommerce-product-gallery .flex-control-nav',
					'property' => 'width',
				],
				[
					'selector' => '&[data-pos="right"] .brx-product-gallery-thumbnail-slider',
					'property' => 'width',
				],
				[
					'selector' => '&[data-pos="left"] .brx-product-gallery-thumbnail-slider',
					'property' => 'width',
				],
			],
			'placeholder' => '100px',
			'rerender'    => true,
			'required'    => [ 'thumbnailPosition', '=', [ 'left', 'right' ] ],
		];

		$this->controls['columns'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Columns', 'bricks' ),
			'type'        => 'number',
			'css'         => [
				[
					'selector' => '.flex-control-thumbs',
					'property' => 'grid-template-columns',
					'value'    => 'repeat(%s, 1fr)', // NOTE: Undocumented (@since 1.3)
				],
			],
			'placeholder' => 4,
			'required'    => [
				[ 'thumbnailSlider', '!=', true ],
				[ 'thumbnailPosition', '!=', [ 'left', 'right' ] ]
			],
		];

		$this->controls['gap'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Gap', 'bricks' ),
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[

					'selector' => '.flex-control-thumbs',
					'property' => 'gap',
				],
				[
					'selector' => '.woocommerce-product-gallery',
					'property' => 'gap',
				],
				[
					'selector' => '&.thumbnail-slider',
					'property' => 'gap',
				],
			],
			'placeholder' => '20px',
		];

		$this->controls['thumbnailOpacity'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Opacity', 'bricks' ),
			'type'        => 'number',
			'step'        => 0.1,
			'css'         => [
				[
					'selector' => '.woocommerce-product-gallery .flex-control-thumbs img:not(.flex-active)',
					'property' => 'opacity',
				],
				[
					'selector' => '&.thumbnail-slider .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image:not(.flex-active-slide) img',
					'property' => 'opacity',
				],
			],
			'placeholder' => '0.3',
		];

		$this->controls['thumbnailActiveOpacity'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Opacity', 'bricks' ) . ' (' . esc_html__( 'Active', 'bricks' ) . ')',
			'type'  => 'number',
			'step'  => 0.1,
			'css'   => [
				[
					'selector' => '.woocommerce-product-gallery .flex-control-thumbs img.flex-active',
					'property' => 'opacity',
				],
				[
					'selector' => '&.thumbnail-slider .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image.flex-active-slide img',
					'property' => 'opacity',
				],
			],
		];

		$this->controls['thumbnailBorder'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'selector' => '.woocommerce-product-gallery .flex-control-thumbs img',
					'property' => 'border',
				],
				[
					'selector' => '&.thumbnail-slider .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image img', // Target image to avoid overflow (#86bzpa085)
					'property' => 'border',
				],
			],
		];

		$this->controls['thumbnailActiveBorder'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Border', 'bricks' ) . ' (' . esc_html__( 'Active', 'bricks' ) . ')',
			'type'  => 'border',
			'css'   => [
				[
					'selector' => '.woocommerce-product-gallery .flex-control-thumbs img.flex-active',
					'property' => 'border',
				],
				[
					'selector' => '&.thumbnail-slider .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image.flex-active-slide img', // Target image to avoid overflow (#86bzpa085)
					'property' => 'border',
				],
			],
		];

		// Thumbnail slider (@since 1.9)
		$this->controls['thumbnailSlider'] = [
			'tab'      => 'content',
			'label'    => esc_html_x( 'Slider', 'carousel element', 'bricks' ),
			'type'     => 'checkbox',
			'rerender' => true,
		];

		$this->controls['thumbnailWrapperMaxHeight'] = [
			'tab'          => 'content',
			'label'        => esc_html_x( 'Slider', 'carousel element', 'bricks' ) . ': ' . esc_html__( 'Height', 'bricks' ) . ' (px)',
			'type'         => 'number',
			'hasVariables' => false,
			'units'        => true,
			'css'          => [
				[
					'selector' => '&.thumbnail-slider .brx-product-gallery-thumbnail-slider',
					'property' => 'max-height',
				],
			],
			'rerender'     => true,
			'required'     => [
				[ 'thumbnailSlider', '=', true ],
				[ 'thumbnailPosition', '=', [ 'left', 'right' ] ],
			],
		];

		// NOTE: 'itemMargin' doesn't support gap in 'vertical' direction, so we have to use 'magin-bottom' instead
		$this->controls['itemMargin'] = [
			'tab'          => 'content',
			'label'        => esc_html_x( 'Slider', 'carousel element', 'bricks' ) . ': ' . esc_html__( 'Gap', 'bricks' ) . ' (px)',
			'type'         => 'number',
			'hasVariables' => false,
			'units'        => true,
			'css'          => [
				[
					'selector' => '&.thumbnail-slider[data-pos="top"] .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image',
					'property' => 'margin-inline-end',
				],
				[
					'selector' => '&.thumbnail-slider[data-pos="bottom"] .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image',
					'property' => 'margin-inline-end',
				],
				[
					'selector' => '&.thumbnail-slider[data-pos="right"] .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image',
					'property' => 'margin-bottom',
				],
				[
					'selector' => '&.thumbnail-slider[data-pos="left"] .brx-product-gallery-thumbnail-slider .woocommerce-product-gallery__image',
					'property' => 'margin-bottom',
				],
			],
			'placeholder'  => '20',
			'rerender'     => true,
			'required'     => [ 'thumbnailSlider', '=', true ],
		];

		// $this->controls['minItems'] = [
		// 'tab'         => 'content',
		// 'label'       => esc_html__( 'Min. items', 'bricks' ),
		// 'type'        => 'number',
		// 'units'       => false,
		// 'placeholder' => '1',
		// 'rerender'    => true,
		// 'required'    => [ 'thumbnailSlider', '=', true ],
		// ];

		// Horizontal direction only (vertical slider doesn't support it)
		$this->controls['maxItems'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Max. items', 'bricks' ),
			'type'        => 'number',
			'units'       => false,
			'placeholder' => '4',
			'rerender'    => true,
			'required'    => [
				[ 'thumbnailSlider', '=', true ],
				[ 'thumbnailPosition', '!=', [ 'left', 'right' ] ],
			],
		];

		// THUMBNAIL NAV (ARROWS)

		$this->controls['arrowsSep'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Arrows', 'bricks' ),
			'type'     => 'separator',
			'required' => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['prevArrow'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Prev arrow', 'bricks' ),
			'type'        => 'icon',
			'placeholder' => 'ti-angle-left',
			'render'      => false,
			'required'    => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['nextArrow'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Next arrow', 'bricks' ),
			'type'        => 'icon',
			'placeholder' => 'ti-angle-right',
			'render'      => false,
			'required'    => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['arrowBackground'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => 'background-color',
					'selector' => '.flex-direction-nav a',
				],
			],
			'required' => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['arrowBorder'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Border', 'bricks' ),
			'type'     => 'border',
			'css'      => [
				[
					'property' => 'border',
					'selector' => '.flex-direction-nav a',
				],
			],
			'required' => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['arrowColor'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Color', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => 'color',
					'selector' => '.flex-direction-nav a',
				],
			],
			'required' => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['arrowSize'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Size', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'font-size',
					'selector' => '.flex-direction-nav a > *',
				],
				[
					'property' => 'height',
					'selector' => '.flex-direction-nav a > svg',
				],
			],
			'required' => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['arrowHeight'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Height', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'height',
					'selector' => '.flex-direction-nav a',
				],
			],
			'required' => [ 'thumbnailSlider', '=', true ],
		];

		$this->controls['arrowWidth'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Width', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'property' => 'width',
					'selector' => '.flex-direction-nav a',
				],
			],
			'required' => [ 'thumbnailSlider', '=', true ],
		];
	}

	/**
	 * Render the product gallery and pass element-specific thumbnail sizes to variation swaps.
	 *
	 * @since 2.4.2 Support independently rendered WooCommerce variation galleries.
	 */
	public function render() {
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

		$this->product = $product;
		$settings      = $this->settings;

		// Thumbnail position
		$thumbnail_position = ! empty( $settings['thumbnailPosition'] ) ? $settings['thumbnailPosition'] : 'bottom';
		$this->set_attribute( '_root', 'data-pos', esc_attr( $thumbnail_position ) );

		// Thumbnail slider enabled
		$thumbnail_slider = isset( $settings['thumbnailSlider'] ) ? $settings['thumbnailSlider'] : false;

		if ( $thumbnail_slider ) {
			$this->set_attribute( '_root', 'class', 'thumbnail-slider' );
		}

		// WooCommerce 11.1 generates variation galleries outside our image-size filters.
		// Pass this element's size and parent-image map to the replacement gallery. (#86cbj9ut2; @since 2.4.2)
		if ( ! empty( $settings['thumbnailImageSize'] ) && $product->is_type( 'variable' ) && function_exists( 'wc_get_product_gallery_html' ) ) {
			$this->set_attribute( '_root', 'data-variation-thumbnail-size', esc_attr( $settings['thumbnailImageSize'] ) );
			$this->set_attribute( '_root', 'data-variation-thumbnail-url', esc_url( \WC_AJAX::get_endpoint( 'bricks_variation_thumbnail_sizes' ) ) );
		}

		$variation_thumbnail_sizes = $this->get_variation_thumbnail_sizes();
		if ( $variation_thumbnail_sizes ) {
			$this->set_attribute( '_root', 'data-variation-thumbnail-sizes', wp_json_encode( $variation_thumbnail_sizes ) );
		}

		// STEP: Render
		echo "<div {$this->render_attributes( '_root' )}>";

		echo $this->product_gallery_html();

		if ( $thumbnail_slider ) {
			echo $this->bricks_product_gallery_thumbnails();
		}

		echo '</div>';
	}

	/**
	 * Get product gallery HTML
	 *
	 * @since 1.9
	 * @return string
	 */
	public function product_gallery_html() {
		add_filter( 'woocommerce_gallery_thumbnail_size', [ $this, 'set_gallery_thumbnail_size' ] );
		add_filter( 'woocommerce_gallery_image_size', [ $this, 'set_gallery_image_size' ] );
		add_filter( 'woocommerce_gallery_full_size', [ $this, 'set_gallery_full_size' ] );
		add_filter( 'woocommerce_gallery_image_html_attachment_image_params', [ $this, 'add_image_class_prevent_lazy_loading' ], 10, 4 );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', [ $this, 'add_gallery_link_aria_label' ], 10, 2 );
		add_filter( 'woocommerce_single_product_image_gallery_classes', [ $this, 'add_product_gallery_class' ] );

		ob_start();
		wc_get_template( 'single-product/product-image.php' );
		$gallery_html = ob_get_clean();

		remove_filter( 'woocommerce_gallery_thumbnail_size', [ $this, 'set_gallery_thumbnail_size' ] );
		remove_filter( 'woocommerce_gallery_image_size', [ $this, 'set_gallery_image_size' ] );
		remove_filter( 'woocommerce_gallery_full_size', [ $this, 'set_gallery_full_size' ] );
		remove_filter( 'woocommerce_gallery_image_html_attachment_image_params', [ $this, 'add_image_class_prevent_lazy_loading' ], 10, 4 );
		remove_filter( 'woocommerce_single_product_image_thumbnail_html', [ $this, 'add_gallery_link_aria_label' ], 10, 2 );
		remove_filter( 'woocommerce_single_product_image_gallery_classes', [ $this, 'add_product_gallery_class' ] );

		return $gallery_html;
	}

	/**
	 * Resolve element-specific thumbnails for WooCommerce's independently rendered variation galleries.
	 *
	 * Include only the displayed product; variation sources are requested when selected.
	 * Keep the map on the element so galleries with different image sizes remain independent.
	 * WooCommerce's replacement markup has no attachment IDs, so key by its default thumbnail URL.
	 *
	 * @since 2.4.2
	 * @param \WC_Product|null $gallery_product Product whose gallery is being displayed.
	 * @return array
	 */
	public function get_variation_thumbnail_sizes( $gallery_product = null ) {
		if ( empty( $this->settings['thumbnailImageSize'] ) || ! $this->product->is_type( 'variable' ) || ! function_exists( 'wc_get_product_gallery_html' ) ) {
			return [];
		}

		$gallery_product = $gallery_product ? $gallery_product : $this->product;
		$image_ids       = array_merge( [ $gallery_product->get_image_id() ], $gallery_product->get_gallery_image_ids() );

		$gallery_thumbnail = wc_get_image_size( 'gallery_thumbnail' );
		$default_size      = apply_filters( 'woocommerce_gallery_thumbnail_size', [ $gallery_thumbnail['width'], $gallery_thumbnail['height'] ] );
		$selected_size     = $this->set_gallery_thumbnail_size( $default_size );
		$thumbnails        = [];

		foreach ( array_unique( array_filter( $image_ids ) ) as $image_id ) {
			$default  = wp_get_attachment_image_src( $image_id, $default_size );
			$selected = wp_get_attachment_image_src( $image_id, $selected_size );

			if ( ! $default || ! $selected ) {
				continue;
			}

			$srcset = wp_get_attachment_image_srcset( $image_id, $selected_size );
			$sizes  = wp_get_attachment_image_sizes( $image_id, $selected_size );

			$thumbnails[ $default[0] ] = [
				'src'    => $selected[0],
				'srcset' => $srcset ? $srcset : '',
				'sizes'  => $sizes ? $sizes : '',
			];
		}

		return $thumbnails;
	}

	/**
	 * Render Bricks product gallery thumbnails
	 *
	 * @since 1.9
	 * @since 2.4.2 Keep a slider available when only a variation has gallery images.
	 *
	 * @return string
	 */
	public function bricks_product_gallery_thumbnails() {
		$product = $this->product;

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return '';
		}

		$attachment_ids        = $product->get_gallery_image_ids();
		$has_variation_gallery = false;

		if ( ! $attachment_ids ) {
			if ( ! $product->is_type( 'variable' ) ) {
				return '';
			}

			// Render a reusable slider only if a selectable variation can replace the one-image parent
			// gallery. The frontend hides it whenever the currently displayed gallery has one image.
			// (#86cbj9ut2; @since 2.4.2)
			foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
				if ( $variation->get_gallery_image_ids() ) {
					$has_variation_gallery = true;
					break;
				}
			}

			if ( ! $has_variation_gallery ) {
				return '';
			}
		}

		// STEP: Populate flexslider settings
		$settings    = $this->settings;
		$position    = ! empty( $settings['thumbnailPosition'] ) ? $settings['thumbnailPosition'] : 'bottom';
		$prev_arrow  = ! empty( $settings['prevArrow'] ) ? self::render_icon( $settings['prevArrow'] ) : '';
		$next_arrow  = ! empty( $settings['nextArrow'] ) ? self::render_icon( $settings['nextArrow'] ) : '';
		$direction   = in_array( $position, [ 'bottom', 'top' ], true ) ? 'horizontal' : 'vertical';
		$item_margin = isset( $settings['itemMargin'] ) ? absint( $settings['itemMargin'] ) : 30;

		/**
		 * Set item margin to 0 for RTL as gap is always set via margin-right inline CSS (flexslider)
		 *
		 * @since 1.11.1
		 */
		if ( is_rtl() ) {
			$item_margin = 0;
		}

		// flexslider settings https://gist.github.com/warrendholmes/9481310
		$thumbnail_settings = [
			'animation'      => 'slide',
			'direction'      => $direction,
			'itemWidth'      => isset( $settings['itemWidth'] ) ? absint( $settings['itemWidth'] ) : 100,
			'itemMargin'     => $item_margin, // Vertical direction doesn't support 'itemMargin' (need to set via margin-bottom)
			'minItems'       => isset( $settings['minItems'] ) ? absint( $settings['minItems'] ) : 1,
			'maxItems'       => isset( $settings['maxItems'] ) ? absint( $settings['maxItems'] ) : 4,
			'animationSpeed' => 500,
			'animationLoop'  => false,
			'smoothHeight'   => false,
			'controlNav'     => false,
			'slideshow'      => false,
			'prevText'       => $prev_arrow,
			'nextText'       => $next_arrow,
			'rtl'            => is_rtl(),
			// Target the correct main slider (@since 1.10.2)
			'asNavFor'       => '.brxe-product-gallery[data-script-id="' . $this->attributes['_root']['data-script-id'] . '"] > .woocommerce-product-gallery',
			'selector'       => '.brx-thumbnail-slider-wrapper > .woocommerce-product-gallery__image',
		];

		$this->set_attribute( 'product_thumbnails', 'class', 'brx-product-gallery-thumbnail-slider' );
		$this->set_attribute( 'product_thumbnails', 'data-thumbnail-settings', wp_json_encode( $thumbnail_settings ) );
		$this->set_attribute( 'product_thumbnails', 'data-prev-label', esc_attr__( 'Previous image', 'bricks' ) );
		$this->set_attribute( 'product_thumbnails', 'data-next-label', esc_attr__( 'Next image', 'bricks' ) );
		// Set opacity to avoid flickering on flexslider init
		$this->set_attribute( 'product_thumbnails', 'style', 'opacity: 0; transition: opacity .25s ease-in-out;' );

		// Set all possible variation IDs (@since 1.11)
		if ( $product->is_type( 'variable' ) ) {
			$this->set_attribute( 'product_thumbnails', 'data-variation-ids', wp_json_encode( $product->get_children() ) );
		}

		// STEP: single-product/product-image.php
		$post_thumbnail_id = $product->get_image_id();

		// NOTE: use woocommerce_gallery_image_size instead of woocommerce_gallery_thumbnail_size we are building a fake thumbnail
		add_filter( 'woocommerce_gallery_image_size', [ $this, 'set_gallery_thumbnail_size' ] );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', [ $this, 'add_gallery_link_aria_label' ], 10, 2 );

		if ( $post_thumbnail_id ) {
			$html = wc_get_gallery_image_html( $post_thumbnail_id, true );
		} else {
			// Match WooCommerce's placeholder slide class so FlexSlider can initialize before a
			// variation with gallery images is selected. (#86cbj9ut2; @since 2.4.2)
			$placeholder_class = $has_variation_gallery ? 'woocommerce-product-gallery__image woocommerce-product-gallery__image--placeholder' : 'woocommerce-product-gallery__image--placeholder';
			$html              = '<div class="' . esc_attr( $placeholder_class ) . '">';
			$html             .= sprintf( '<img src="%s" alt="%s" class="wp-post-image" />', esc_url( wc_placeholder_img_src( 'woocommerce_single' ) ), esc_html__( 'Awaiting product image', 'woocommerce' ) );
			$html             .= '</div>';
		}

		$html = apply_filters( 'woocommerce_single_product_image_thumbnail_html', $html, $post_thumbnail_id );

		// STEP: single-product/product-thumbnails.php
		if ( $attachment_ids && $product->get_image_id() ) {
			foreach ( $attachment_ids as $attachment_id ) {
				// Ensure the image still exists, svg is not supported in native product gallery too (@since 1.9.8)
				if ( ! wp_attachment_is_image( $attachment_id ) ) {
					continue;
				}

				$html .= apply_filters( 'woocommerce_single_product_image_thumbnail_html', wc_get_gallery_image_html( $attachment_id ), $attachment_id ); // phpcs:disable WordPress.XSS.EscapeOutput.OutputNotEscaped
			}
		}

		remove_filter( 'woocommerce_gallery_image_size', [ $this, 'set_gallery_thumbnail_size' ] );
		remove_filter( 'woocommerce_single_product_image_thumbnail_html', [ $this, 'add_gallery_link_aria_label' ], 10, 2 );

		// Return thumbnail slider HTML
		return "<div {$this->render_attributes( 'product_thumbnails' )}><div class=\"brx-thumbnail-slider-wrapper\">" . $html . '</div></div>';
	}

	/**
	 * Set gallery image size for the current product gallery
	 *
	 * hook: woocommerce_gallery_image_size
	 *
	 * @see woocommerce/includes/wc-template-functions.php
	 *
	 * @since 1.8
	 */
	public function set_gallery_image_size( $size ) {
		if ( ! empty( $this->settings['productImageSize'] ) ) {
			$size = $this->settings['productImageSize'];
		}

		return $size;
	}

	/**
	 * Set gallery thumbnail size for the current product gallery
	 *
	 * hook: woocommerce_gallery_thumbnail_size
	 *
	 * @see woocommerce/includes/wc-template-functions.php
	 *
	 * @since 1.8
	 */
	public function set_gallery_thumbnail_size( $size ) {
		if ( ! empty( $this->settings['thumbnailImageSize'] ) ) {
			$size = $this->settings['thumbnailImageSize'];
		}

		return $size;
	}

	/**
	 * Set gallery full size for the current product gallery (Lightbox)
	 *
	 * hook: woocommerce_gallery_full_size
	 *
	 * @see woocommerce/includes/wc-template-functions.php
	 *
	 * @since 1.8
	 */
	public function set_gallery_full_size( $size ) {
		if ( ! empty( $this->settings['lightboxImageSize'] ) ) {
			$size = $this->settings['lightboxImageSize'];
		}

		return $size;
	}

	public function add_image_class_prevent_lazy_loading( $attr, $attachment_id, $image_size, $main_image ) {
		// NOTE: Undocumented (used only internally in the Frontend::set_image_attributes)
		if ( $this->lazy_load() ) {
			$attr['_brx_disable_lazy_loading'] = 1;
		}

		// Photoswipe 5 (@since 1.7.2)
		// NOTE: Not in use as Photoswipe 5 is not supported by all major Woo product gallery plugins
		// $attachment               = wp_get_attachment_image_src( $attachment_id, $image_size );
		// $attr['data-pswp-src']    = ! empty( $attachment[0] ) ? $attachment[0] : '';
		// $attr['data-pswp-width']  = ! empty( $attachment[1] ) ? $attachment[1] : '';
		// $attr['data-pswp-height'] = ! empty( $attachment[2] ) ? $attachment[2] : '';
		// $attr['data-pswp-id']     = $this->id;

		return $attr;
	}

	/**
	 * Add an accessible name to WooCommerce product gallery image links.
	 *
	 * @since 2.4
	 *
	 * @param string $html          Gallery image HTML.
	 * @param int    $attachment_id Attachment ID.
	 *
	 * @return string
	 */
	public function add_gallery_link_aria_label( $html, $attachment_id ) {
		$label = esc_html__( 'View product image', 'bricks' );

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new \WP_HTML_Tag_Processor( $html );

			if ( $processor->next_tag( 'a' ) && ! $processor->get_attribute( 'aria-label' ) ) {
				$processor->set_attribute( 'aria-label', $label );
				return $processor->get_updated_html();
			}
		}

		if ( preg_match( '/<a\\s[^>]*aria-label=/i', $html ) ) {
			return $html;
		}

		return preg_replace( '/<a\\s/i', '<a aria-label="' . esc_attr( $label ) . '" ', $html, 1 );
	}

	/**
	 * Add unique class to product gallery for the current product so frontend scripts can target the correct gallery when multiple galleries are present on the page.
	 *
	 * @since 2.2
	 */
	public function add_product_gallery_class( $classes ) {
		$product = $this->product;

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $classes;
		}

		$classes[] = 'bricks-product-gallery-for-' . $product->get_id();
		return $classes;
	}
}
