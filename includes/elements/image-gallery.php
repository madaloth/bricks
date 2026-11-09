<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Image_Gallery extends Element {
	public $block    = 'core/gallery';
	public $category = 'media';
	public $name     = 'image-gallery';
	public $icon     = 'ti-gallery';
	public $scripts  = [ 'bricksIsotope' ];

	public function get_label() {
		return esc_html__( 'Image gallery', 'bricks' );
	}

	public function set_control_groups() {
		$this->control_groups['loadMore'] = [
			'title' => esc_html__( 'Load more', 'bricks' ),
		];
	}

	/**
	 * Get responsive load more setting values per breakpoint.
	 *
	 * @since 2.3
	 *
	 * @param array  $settings      Element settings.
	 * @param string $setting_key   Setting key.
	 * @param array  $breakpoints   Available breakpoints.
	 * @param string $setting_type  Setting type.
	 *
	 * @return array
	 */
	public function get_responsive_load_more_setting_values( $settings, $setting_key, $breakpoints, $setting_type = 'count' ) {
		$values = [];

		foreach ( $breakpoints as $breakpoint ) {
			if ( isset( $breakpoint['paused'] ) || empty( $breakpoint['key'] ) ) {
				continue;
			}

			$breakpoint_key = $breakpoint['key'];
			$control_key    = $breakpoint_key === 'desktop' ? $setting_key : "{$setting_key}:{$breakpoint_key}";

			if ( ! array_key_exists( $control_key, $settings ) || $settings[ $control_key ] === '' || $settings[ $control_key ] === null ) {
				continue;
			}

			$value = intval( $settings[ $control_key ] );
			$value = max( 0, $value );

			$values[ $breakpoint_key ] = $value;
		}

		return $values;
	}

	public function enqueue_scripts() {
		$layout = $this->settings['layout'] ?? 'grid';

		if ( $layout === 'masonry' ) {
			wp_enqueue_script( 'bricks-isotope' );
			wp_enqueue_style( 'bricks-isotope' );
		}

		$link_to = $this->settings['link'] ?? false;

		if ( $link_to === 'lightbox' ) {
			wp_enqueue_script( 'bricks-photoswipe' );
			wp_enqueue_script( 'bricks-photoswipe-lightbox' );
			wp_enqueue_style( 'bricks-photoswipe' );

			// Lightbox caption (@since 1.10)
			if ( isset( $this->settings['lightboxCaption'] ) ) {
				wp_enqueue_script( 'bricks-photoswipe-caption' );
			}
		}
	}

	public function set_controls() {
		$this->controls['_border']['css'][0]['selector']    = '.image';
		$this->controls['_boxShadow']['css'][0]['selector'] = '.image';

		$this->controls['items'] = [
			'tab'   => 'content',
			'type'  => 'image-gallery',
			'label' => esc_html__( 'Images', 'bricks' ),
		];

		// Settings

		$this->controls['settingsSep'] = [
			'tab'  => 'content',
			'type' => 'separator',
		];

		$this->controls['layout'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Layout', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'grid'    => esc_html__( 'Grid', 'bricks' ),
				'masonry' => 'Masonry',
				'metro'   => 'Metro',
			],
			'placeholder' => esc_html__( 'Grid', 'bricks' ),
			'inline'      => true,
			'rerender'    => true,
		];

		$this->controls['columns'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Columns', 'bricks' ),
			'type'        => 'number',
			'min'         => 2,
			'css'         => [
				[
					'property' => '--columns',
					'selector' => '',
				],
			],
			'rerender'    => true,
			'placeholder' => 3,
			'required'    => [ 'layout', '!=', [ 'metro' ] ],
		];

		$this->controls['gutter'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Spacing', 'bricks' ),
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[
					'property' => '--gutter',
					'selector' => '',
				],
			],
			'placeholder' => 0,
		];

		$this->controls['imageHeight'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Image height', 'bricks' ),
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[
					'property' => 'height',
					'selector' => '.image',
					// 'important' => true, // No need to use !important here, as we have Cascade layers. Used to override height in different Layouts (@since 2.2 #86c3p8f91)
				],
			],
			'placeholder' => '',
			'required'    => [ 'layout', '!=', [ 'masonry', 'metro' ] ],
		];

		$this->controls['imageRatio'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Image ratio', 'bricks' ),
			'desc'     => esc_html__( 'Example', 'bricks' ) . ': 4/3, 16/9, 1/1 (square)',
			'type'     => 'text',
			'css'      => [
				[
					'selector' => '.image',
					'property' => 'aspect-ratio',
				],
			],
			'inline'   => true,
			'required' => [ 'layout', '!=', [ 'masonry', 'metro' ] ],
		];

		/**
		 * Custom aspect ratio (remove control from style tab)
		 *
		 * NOTE: No longer in use @since 2.0.2 as 'imageRatio' is a "text" instead of a "select" control
		 *
		 * @since 1.9.7
		 */
		unset( $this->controls['_aspectRatio'] );
		$this->controls['_aspectRatio'] = [
			'tab'          => 'content',
			'label'        => esc_html__( 'Aspect ratio', 'bricks' ),
			'type'         => 'text',
			'inline'       => true,
			'dd'           => false,
			'hasVariables' => true,
			'css'          => [
				[
					'selector' => '.image',
					'property' => 'aspect-ratio',
				],
			],
			'required'     => [ 'imageRatio', '=', 'custom' ],
		];

		$this->controls['caption'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Image caption', 'bricks' ),
			'type'  => 'checkbox',
		];

		$this->controls['link'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Link to', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'lightbox'   => esc_html__( 'Lightbox', 'bricks' ),
				'attachment' => esc_html__( 'Attachment Page', 'bricks' ),
				'media'      => esc_html__( 'Media File', 'bricks' ),
				'custom'     => esc_html__( 'Custom URL', 'bricks' ),
			],
			'inline'      => true,
			'placeholder' => esc_html__( 'None', 'bricks' ),
		];

		$this->controls['linkCustom'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Custom links', 'bricks' ),
			'type'        => 'repeater',
			'fields'      => [
				'link' => [
					'label'   => esc_html__( 'Link', 'bricks' ),
					'type'    => 'link',
					'exclude' => [
						'lightboxImage',
						'lightboxVideo',
					],
				],
			],
			'placeholder' => esc_html__( 'Custom link', 'bricks' ),
			'required'    => [ 'link', '=', 'custom' ],
		];

		// LIGHTBOX

		$this->controls['lightboxSep'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Lightbox', 'bricks' ),
			'type'     => 'separator',
			'required' => [ 'link', '=', 'lightbox' ],
		];

		$this->controls['lightboxImageSize'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Image size', 'bricks' ),
			'type'        => 'select',
			'options'     => $this->control_options['imageSizes'],
			'placeholder' => esc_html__( 'Full', 'bricks' ),
			'required'    => [ 'link', '=', 'lightbox' ],
		];

		// https://photoswipe.com/click-and-tap-actions/#supported-action-values
		$this->controls['lightboxImageClick'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Image click action', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'zoom'            => esc_html__( 'Zoom', 'bricks' ),
				'zoom-or-close'   => esc_html__( 'Zoom or close', 'bricks' ),
				'toogle-controls' => esc_html__( 'Toggle controls', 'bricks' ),
				'next'            => esc_html__( 'Next', 'bricks' ),
				'close'           => esc_html__( 'Close', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Zoom', 'bricks' ),
			'required'    => [ 'link', '=', 'lightbox' ],
		];

		$this->controls['lightboxAnimationType'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Animation', 'bricks' ),
			'type'        => 'select',
			'options'     => $this->control_options['lightboxAnimationTypes'],
			'placeholder' => esc_html__( 'Zoom', 'bricks' ),
			'required'    => [ 'link', '=', 'lightbox' ],
		];

		$this->controls['lightboxCaption'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Caption', 'bricks' ),
			'type'     => 'checkbox',
			'required' => [ 'link', '=', 'lightbox' ],
		];

		$this->controls['lightboxThumbnails'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Thumbnail navigation', 'bricks' ),
			'type'     => 'checkbox',
			'required' => [ 'link', '=', 'lightbox' ],
		];

		$this->controls['lightboxThumbnailSize'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Thumbnail size', 'bricks' ),
			'type'        => 'number',
			'units'       => true,
			'placeholder' => 60,
			'required'    => [
				[ 'link', '=', 'lightbox' ],
				[ 'lightboxThumbnails', '=', true ],
			],
		];

		$this->controls['lightboxThumbnailInfo'] = [
			'tab'      => 'content',
			'content'  => esc_html__( 'We recommend setting a padding for your lightbox to accommodate the thumbnail navigation.', 'bricks' ),
			'type'     => 'info',
			'required' => [
				[ 'link', '=', 'lightbox', ],
				[ 'lightboxThumbnails', '=', true ],
				[ 'lightboxPadding', '=', '' ],
			],
		];

		$this->controls['lightboxPadding'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Padding', 'bricks' ) . ' (px)',
			'type'     => 'dimensions',
			'required' => [ 'link', '=', 'lightbox' ],
		];

		// Lightbox ID (@since 1.12)
		$this->controls['lightboxId'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Lightbox', 'bricks' ) . ': ID',
			'type'        => 'text',
			'inline'      => true,
			'required'    => [ 'link', '=', 'lightbox' ],
			'description' => esc_html__( 'Images of the same lightbox ID are grouped together.', 'bricks' ),
		];

		// Attribute: fetchpriority (@since 2.0)
		$this->controls['fetchpriorityAttribute'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Fetch priority', 'bricks' ),
			'inline'  => true,
			'type'    => 'select',
			'options' => [
				'high' => esc_html__( 'High', 'bricks' ),
				'low'  => esc_html__( 'Low', 'bricks' ),
				'auto' => esc_html__( 'Auto', 'bricks' ),
			],
		];

		// Attribute: loading (@since 2.0)
		$this->controls['loadingAttribute'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Loading', 'bricks' ),
			'inline'  => true,
			'type'    => 'select',
			'options' => [
				'lazy'  => esc_html__( 'Lazy', 'bricks' ),
				'eager' => esc_html__( 'Eager', 'bricks' ),
			],
		];

		/**
		 * Load more & infinite scroll
		 *
		 * @since 2.3
		 */

		// Add info control with hint to create a button element with an "Load more" interaction.
		$this->controls['loadMoreInfo']    = [
			'group'    => 'loadMore',
			'type'     => 'info',
			'content'  => esc_html__( 'Set the "Load more (Image gallery)" interaction on the element which should trigger loading more images. Load more does not run in the builder.', 'bricks' ),
			'required' => [ 'loadMoreInitial', '!=', '' ],
		];
		$this->controls['loadMoreInitial'] = [
			'group'        => 'loadMore',
			'label'        => esc_html__( 'Initial items', 'bricks' ),
			'type'         => 'number',
			'min'          => 1,
			'css'          => true,
			'hasVariables' => false,
			'description'  => esc_html__( 'Number of images to render initially. The next batch of images is rendered when the "Load more" interaction is triggered. Responsive value is read on page load.', 'bricks' ),
		];

		$this->controls['loadMoreStep'] = [
			'group'        => 'loadMore',
			'label'        => esc_html__( 'Items per load', 'bricks' ),
			'type'         => 'number',
			'min'          => 1,
			'css'          => true,
			'hasVariables' => false,
			'description'  => esc_html__( 'How many images to reveal on each "Load more" interaction. Leave empty or set to 0 to reveal all remaining images.', 'bricks' ),
			'required'     => [ 'loadMoreInitial', '!=', '' ],
		];

		$this->controls['loadMoreInfiniteScroll'] = [
			'group'       => 'loadMore',
			'label'       => esc_html__( 'Infinite scroll', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Load more items when user scrolls near the end of the gallery.', 'bricks' ),
			'required'    => [ 'loadMoreInitial', '!=', '' ],
		];

		$this->controls['loadMoreInfiniteScrollDelay'] = [
			'group'       => 'loadMore',
			'label'       => esc_html__( 'Infinite scroll delay', 'bricks' ) . ' (ms)',
			'type'        => 'number',
			'units'       => true,
			'placeholder' => 600,
			'description' => esc_html__( 'Delay between triggering infinite scroll and loading more items, in milliseconds.', 'bricks' ),
			'required'    => [
				[ 'loadMoreInitial', '!=', '' ],
				[ 'loadMoreInfiniteScroll', '=', true ],
			],
		];

		$this->controls['loadMoreInfiniteScrollOffset'] = [
			'group'       => 'loadMore',
			'label'       => esc_html__( 'Infinite scroll offset', 'bricks' ) . ' (px)',
			'type'        => 'number',
			'placeholder' => '200',
			'description' => esc_html__( 'Distance from the end of the gallery at which to trigger loading more items when infinite scroll is enabled. px only.', 'bricks' ),
			'required'    => [
				[ 'loadMoreInitial', '!=', '' ],
				[ 'loadMoreInfiniteScroll', '=', true ],
			],
		];
	}

	public function render() {
		$settings          = Helpers::get_normalized_image_settings( $this, $this->settings );
		$images            = $settings['items']['images'] ?? false;
		$size              = $settings['items']['size'] ?? BRICKS_DEFAULT_IMAGE_SIZE;
		$layout            = $settings['layout'] ?? 'grid';
		$link_to           = $settings['link'] ?? false;
		$columns           = $settings['columns'] ?? 3;
		$breakpoints       = Breakpoints::get_breakpoints();
		$is_builder_render = bricks_is_builder() || bricks_is_builder_call();

		$total_images             = is_array( $images ) ? count( $images ) : 0;
		$load_more_initial        = 0;
		$load_more_step           = 0;
		$load_more_render_initial = 0;
		$load_more_enabled        = false;
		$infinite_scroll          = false;
		$scroll_delay             = isset( $settings['loadMoreInfiniteScrollDelay'] ) ? esc_attr( $settings['loadMoreInfiniteScrollDelay'] ) : '600ms';
		$scroll_offset            = isset( $settings['loadMoreInfiniteScrollOffset'] ) ? esc_attr( $settings['loadMoreInfiniteScrollOffset'] ) : '200px';

		if ( ! $is_builder_render ) {
			$load_more_initial_values = $this->get_responsive_load_more_setting_values( $settings, 'loadMoreInitial', $breakpoints );
			$load_more_step_values    = $this->get_responsive_load_more_setting_values( $settings, 'loadMoreStep', $breakpoints, 'step' );
			$load_more_initial_values = array_map(
				function ( $value ) use ( $total_images ) {
					return max( 0, min( intval( $value ), $total_images ) );
				},
				$load_more_initial_values
			);
			$load_more_step_values    = array_map(
				function ( $value ) {
					return max( 0, intval( $value ) );
				},
				$load_more_step_values
			);
			$load_more_enabled_values = array_filter(
				$load_more_initial_values,
				function ( $value ) use ( $total_images ) {
					return $value > 0 && $value < $total_images;
				}
			);
			$load_more_initial        = isset( $load_more_initial_values['desktop'] ) ? $load_more_initial_values['desktop'] : 0;
			$load_more_step           = isset( $load_more_step_values['desktop'] ) ? $load_more_step_values['desktop'] : 0;
			$load_more_render_initial = ! empty( $load_more_enabled_values ) ? min( $load_more_enabled_values ) : 0;
			$load_more_enabled        = ! empty( $load_more_enabled_values );
			$infinite_scroll          = ! empty( $settings['loadMoreInfiniteScroll'] );
		}

		// STEP: Return placeholder
		if ( ! $images ) {
			if ( ! empty( $settings['items']['useDynamicData'] ) ) {
				if ( ! Helpers::is_bricks_template( $this->post_id ) ) {
					return $this->render_element_placeholder(
						[
							'title' => esc_html__( 'Dynamic data is empty.', 'bricks' )
						]
					);
				}
			} else {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'No image selected.', 'bricks' ),
					]
				);
			}
		}

		$root_classes = [ 'bricks-layout-wrapper' ];

		// Set isotopeJS CSS class
		if ( $layout === 'masonry' ) {
			$root_classes[] = 'isotope';
			$root_classes[] = 'isotope-before-init'; // Avoid unstyled content visible (@since 1.12)
		}

		$this->set_attribute( '_root', 'class', $root_classes );
		$this->set_attribute( '_root', 'data-layout', $layout );

		// Load more (@since 2.3)
		if ( $load_more_enabled ) {
			$load_more_settings = [
				'initial'      => $load_more_initial,
				'step'         => $load_more_step,
				'responsive'   => [
					'initial' => $load_more_initial_values,
					'step'    => $load_more_step_values,
				],
				'breakpoints'  => array_values(
					array_map(
						function ( $breakpoint ) {
							return [
								'key'   => $breakpoint['key'],
								'width' => intval( $breakpoint['width'] ),
								'base'  => isset( $breakpoint['base'] ),
							];
						},
						array_filter(
							$breakpoints,
							function ( $breakpoint ) {
								return ! isset( $breakpoint['paused'] ) && ! empty( $breakpoint['key'] ) && isset( $breakpoint['width'] );
							}
						)
					)
				),
				'infinite'     => $infinite_scroll,
				'scrollDelay'  => $scroll_delay,
				'scrollOffset' => $scroll_offset,
				'total'        => $total_images,
			];

			$this->set_attribute( '_root', 'data-brx-load-more', 'gallery' );
			$this->set_attribute( '_root', 'data-brx-load-more-settings', wp_json_encode( $load_more_settings ) );
		}

		// STEP: Render
		if ( $link_to === 'lightbox' ) {
			$this->set_attribute( '_root', 'class', 'bricks-lightbox' );

			if ( isset( $settings['lightboxCaption'] ) ) {
				$this->set_attribute( '_root', 'class', 'has-lightbox-caption' );
			}

			if ( isset( $settings['lightboxImageClick'] ) ) {
				$this->set_attribute( '_root', 'data-lightbox-image-click', esc_attr( $settings['lightboxImageClick'] ) );
			}

			if ( ! empty( $settings['lightboxAnimationType'] ) ) {
				$this->set_attribute( '_root', 'data-animation-type', esc_attr( $settings['lightboxAnimationType'] ) );
			}

			if ( ! empty( $settings['lightboxPadding'] ) ) {
				$this->set_attribute( '_root', 'data-lightbox-padding', wp_json_encode( $settings['lightboxPadding'] ) );
			}

			if ( ! empty( $settings['lightboxThumbnails'] ) ) {
				$this->set_attribute( '_root', 'class', 'has-lightbox-thumbnails' );
			}

			if ( ! empty( $settings['lightboxThumbnailSize'] ) ) {
				$this->set_attribute( '_root', 'data-lightbox-thumbnail-size', esc_attr( $settings['lightboxThumbnailSize'] ) );
			}

			// Lightbox ID (@since 1.12)
			if ( ! empty( $settings['lightboxId'] ) ) {
				$this->set_attribute( '_root', 'data-lightbox-id', esc_attr( $settings['lightboxId'] ) );
			}
		}

		echo "<ul {$this->render_attributes( '_root' )}>";

		$deferred_items_html = [];

		foreach ( $images as $index => $item ) {
			$item_html   = '';
			$close_a_tag = false;
			$image_id    = $item['id'] ?? false;

			$image_classes = [ 'image' ];

			// Image lazy load
			if ( $this->lazy_load() ) {
				$image_classes[] = 'bricks-lazy-hidden';
				$image_classes[] = 'bricks-lazy-load-isotope';
			}

			if ( $layout !== 'masonry' ) {
				$image_classes[] = 'bricks-layout-inner';
			}

			// CSS filters
			$image_classes[] = 'css-filter';

			// List item
			$this->set_attribute( "li-{$index}", 'class', 'bricks-layout-item' );

			// Add 'data-id' attribute to image <li> (helps to perform custom JS logic based on attachment ID)
			if ( $image_id ) {
				$this->set_attribute( "li-{$index}", 'data-id', $image_id );
			}

			$item_html .= "<li {$this->render_attributes( "li-{$index}" )}>";

			// Open 'figure' tag (@since 1.9.7)
			$item_html .= '<figure>';

			if ( $link_to === 'attachment' && $image_id ) {
				$close_a_tag = true;

				$item_html .= '<a href="' . get_permalink( $image_id ) . '" target="_blank">';
			}

			// Media file
			elseif ( $link_to === 'media' ) {
				$close_a_tag = true;

				$item_html .= '<a href="' . esc_url( $item['url'] ) . '" target="_blank">';
			}

			// Custom link
			elseif ( $link_to === 'custom' && isset( $settings['linkCustom'][ $index ]['link'] ) ) {
				$close_a_tag = true;

				$this->set_link_attributes( "a-$index", $settings['linkCustom'][ $index ]['link'] );

				$item_html .= "<a {$this->render_attributes( "a-$index" )}>";
			}

			// Lightbox attributes
			elseif ( $link_to === 'lightbox' ) {
				$close_a_tag         = true;
				$lightbox_image_size = $settings['lightboxImageSize'] ?? 'full';
				$lightbox_image      = $image_id ? wp_get_attachment_image_src( $image_id, $lightbox_image_size ) : false;
				$lightbox_image      = is_array( $lightbox_image ) && ! empty( $lightbox_image ) ? $lightbox_image : [ ! empty( $item['url'] ) ? $item['url'] : '', 800, 600 ];

				$this->set_attribute( "a-$index", 'href', $lightbox_image[0] );
				$this->set_attribute( "a-$index", 'data-pswp-src', $lightbox_image[0] );
				$this->set_attribute( "a-$index", 'data-pswp-width', $lightbox_image[1] );
				$this->set_attribute( "a-$index", 'data-pswp-height', $lightbox_image[2] );
				// Commented out to support same lightbox ID for multiple galleries on the same page (@since 1.12)
				// $this->set_attribute( "a-$index", 'data-pswp-index', $index );

				// Lightbox ID (@since 1.12)
				if ( ! empty( $settings['lightboxId'] ) ) {
					$this->set_attribute( "a-$index", 'data-pswp-id', esc_attr( $settings['lightboxId'] ) );
				}

				// Lightbox caption (@since 1.10)
				$lightbox_caption = isset( $settings['lightboxCaption'] ) && $image_id ? wp_get_attachment_caption( $image_id ) : false;

				if ( $lightbox_caption ) {
					$this->set_attribute( "a-$index", 'data-lightbox-caption', $lightbox_caption );
				}

				$item_html .= "<a {$this->render_attributes( "a-$index" )}>";
			}

			// STEP: Render image
			$image_atts        = [ 'class' => implode( ' ', $image_classes ) ];
			$image_atts_string = '';

			// Set fetchpriority attribute (@since 2.0)
			$attribute_fetchpriority = ! empty( $settings['fetchpriorityAttribute'] ) ? esc_attr( $settings['fetchpriorityAttribute'] ) : '';
			if ( ! empty( $attribute_fetchpriority ) ) {
				$image_atts['fetchpriority'] = $attribute_fetchpriority;
				$image_atts_string          .= ' fetchpriority="' . $attribute_fetchpriority . '"';
			}

			// Set loading attribute (@since 2.0)
			$attribute_loading = ! empty( $settings['loadingAttribute'] ) ? esc_attr( $settings['loadingAttribute'] ) : '';
			if ( ! empty( $attribute_loading ) ) {
				$image_atts['loading'] = $attribute_loading;
				$image_atts_string    .= ' loading="' . $attribute_loading . '"';
			}

			if ( $image_id ) {
				$item_html .= wp_get_attachment_image( $image_id, $size, false, $image_atts );
			} elseif ( ! empty( $item['url'] ) && isset( $item['isPlaceholder'] ) && $item['isPlaceholder'] ) {
				// Maybe is a temporary placeholder image in Bricks (@since 1.12.2)
				$item_html .= '<img src="' . esc_url( $item['url'] ) . '" alt="" width="800" height="600"' . $image_atts_string . ' />';
			}

			if ( $close_a_tag ) {
				$item_html .= '</a>';
			}

			// Image caption
			$caption = isset( $settings['caption'] ) && $image_id ? wp_get_attachment_caption( $image_id ) : false;

			if ( $caption ) {
				$item_html .= '<figcaption class="bricks-image-caption">' . $caption . '</figcaption>';
			}

			// Close 'figure' tag (@since 1.9.7)
			$item_html .= '</figure>';

			$item_html .= '</li>';

			if ( $load_more_enabled && $index >= $load_more_render_initial ) {
				$deferred_items_html[] = $item_html;
			} else {
				echo $item_html;
			}
		}

		if ( $layout === 'masonry' ) {
			// Item sizer (Isotope requirement)
			echo '<li class="bricks-isotope-sizer"></li>';
			echo '<li class="bricks-gutter-sizer"></li>';
		}

		if ( $load_more_enabled && $infinite_scroll ) {
			echo '<li class="brx-gallery-load-more-trail" aria-hidden="true"></li>';
		}

		if ( ! empty( $deferred_items_html ) ) {
			echo '<template class="brx-gallery-load-more-template">';
			echo implode( '', $deferred_items_html );
			echo '</template>';
		}

		echo '</ul>';
	}

	public function convert_element_settings_to_block( $settings ) {
		$settings   = Helpers::get_normalized_image_settings( $this, $settings );
		$images     = ! empty( $settings['items']['images'] ) ? $settings['items']['images'] : false;
		$image_size = $settings['items']['size'];

		if ( ! $images ) {
			return;
		}

		$columns = isset( $settings['columns'] ) ? intval( $settings['columns'] ) : 3;

		if ( count( $images ) < $columns ) {
			$columns = count( $images );
		}

		$block = [
			'blockName'    => $this->block,
			'attrs'        => [
				'ids'      => [],
				'columns'  => $columns,
				'sizeSlug' => $image_size,
			],
			'innerContent' => [],
		];

		$image_gallery_html  = '<figure class="wp-block-gallery columns-' . esc_attr( $columns ) . ' is-cropped">';
		$image_gallery_html .= '<ul class="blocks-gallery-grid">';

		foreach ( $images as $image ) {
			$image_id = isset( $image['id'] ) && ! empty( $image['id'] ) ? intval( $image['id'] ) : false;

			if ( $image_id ) {
				$block['attrs']['ids'][] = $image_id;

				$image_url           = wp_get_attachment_image_url( $image_id, $image_size );
				$image_url_full_size = wp_get_attachment_image_url( $image_id, 'full' );

				$image_gallery_html .= '<li class="blocks-gallery-item">';
				$image_gallery_html .= '<figure>';

				// Retrieve the alt text for the image
				$alt_text = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

				$image_gallery_html .= '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $alt_text ) . '" data-id="' . esc_attr( $image_id ) . '" data-full-url="' . esc_url( $image_url_full_size ) . '" data-link="' . esc_url( get_permalink( $image_id ) ) . '" class="wp-image-' . esc_attr( $image_id ) . '"/>';

				$image_gallery_html .= '</figure>';
				$image_gallery_html .= '</li>';
			}
		}

		$image_gallery_html .= '</ul></figure>';

		$block['innerContent'] = [ $image_gallery_html ];

		return $block;
	}

	public function convert_block_to_element_settings( $block, $attributes ) {
		$image_ids  = isset( $attributes['ids'] ) ? $attributes['ids'] : [];
		$image_size = isset( $attributes['sizeSlug'] ) ? $attributes['sizeSlug'] : 'large';
		$columns    = isset( $attributes['columns'] ) ? intval( $attributes['columns'] ) : 3;

		if ( ! count( $image_ids ) ) {
			return;
		}

		$element_settings = [
			'gutter'  => 15,
			'columns' => $columns,
		];

		if ( isset( $attributes['linkTo'] ) && in_array( $attributes['linkTo'], [ 'attachment', 'media' ] ) ) {
			$element_settings['link'] = $attributes['linkTo'];
		}

		$items = [];

		foreach ( $image_ids as $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, $image_size );

			if ( $image_id && $image_url ) {
				$items['images'][] = [
					'full' => wp_get_attachment_image_url( $image_id, 'full' ),
					'id'   => $image_id,
					'size' => $image_size,
					'url'  => $image_url,
				];
			}
		}

		if ( ! empty( $items ) ) {
			$element_settings['items'] = $items;
		}

		return $element_settings;
	}
}
