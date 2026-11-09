<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * File element.
 *
 * @since 2.4
 */
class Element_File extends Element {
	public $block    = 'core/file';
	public $category = 'media';
	public $name     = 'file';
	public $icon     = 'ti-file';
	public $scripts  = [ 'bricksFile' ];

	/**
	 * Return the element label.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'File', 'bricks' );
	}

	/**
	 * Return searchable keywords.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public function get_keywords() {
		return [ 'document', 'download', 'embed', 'file', 'pdf' ];
	}

	/**
	 * Register element-specific control groups.
	 *
	 * @since 2.4
	 */
	public function set_control_groups() {
		$this->control_groups['file-name'] = [
			'title' => esc_html__( 'File name', 'bricks' ),
		];

		$this->control_groups['button'] = [
			'title' => esc_html__( 'Download button', 'bricks' ),
		];

		$this->control_groups['pdf-preview'] = [
			'title' => esc_html__( 'PDF preview', 'bricks' ),
		];
	}

	/**
	 * Register element controls.
	 *
	 * @since 2.4
	 */
	public function set_controls() {
		$breakpoints        = Breakpoints::get_breakpoints();
		$breakpoint_options = array_column( $breakpoints, 'label', 'key' );

		$this->controls['source'] = [
			'label'       => esc_html__( 'Source', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'file'     => esc_html__( 'File', 'bricks' ),
				'external' => esc_html__( 'External URL', 'bricks' ),
				'dynamic'  => esc_html__( 'Dynamic data', 'bricks' ),
			],
			'inline'      => true,
			'placeholder' => esc_html__( 'File', 'bricks' ),
		];

		$this->controls['file'] = [
			'type'     => 'file',
			'required' => [ 'source', '=', [ '', 'file' ] ],
		];

		$this->controls['external'] = [
			'type'        => 'text',
			'required'    => [ 'source', '=', 'external' ],
			'placeholder' => 'https://example.com/document.pdf',
		];

		$this->controls['useDynamicData'] = [
			'label'          => '',
			'type'           => 'text',
			'placeholder'    => esc_html__( 'Select dynamic data', 'bricks' ),
			'hasDynamicData' => 'media',
			'required'       => [ 'source', '=', 'dynamic' ],
		];

		// File name

		$this->controls['hideFileName'] = [
			'group' => 'file-name',
			'label' => esc_html__( 'Hide file name', 'bricks' ),
			'type'  => 'checkbox',
		];

		$this->controls['customFileName'] = [
			'group'       => 'file-name',
			'label'       => esc_html__( 'Custom file name', 'bricks' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'File name', 'bricks' ),
			'required'    => [ 'hideFileName', '=', '' ],
		];

		$this->controls['openInNewTab'] = [
			'group'    => 'file-name',
			'label'    => esc_html__( 'Open in new tab', 'bricks' ),
			'type'     => 'checkbox',
			'required' => [ 'hideFileName', '=', '' ],
		];

		// Button

		$this->controls['hideDownloadButton'] = [
			'group' => 'button',
			'label' => esc_html__( 'Hide download button', 'bricks' ),
			'type'  => 'checkbox',
		];

		$this->controls['downloadButtonText'] = [
			'group'       => 'button',
			'label'       => esc_html__( 'Text', 'bricks' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => esc_html_x( 'Download', 'button label', 'bricks' ),
			'required'    => [ 'hideDownloadButton', '=', '' ],
		];

		$this->controls['buttonSize'] = [
			'group'       => 'button',
			'label'       => esc_html__( 'Size', 'bricks' ),
			'type'        => 'select',
			'options'     => $this->control_options['buttonSizes'],
			'inline'      => true,
			'placeholder' => esc_html__( 'Default', 'bricks' ),
			'required'    => [ 'hideDownloadButton', '=', '' ],
		];

		$this->controls['buttonStyle'] = [
			'group'       => 'button',
			'label'       => esc_html__( 'Style', 'bricks' ),
			'type'        => 'select',
			'options'     => $this->control_options['styles'],
			'inline'      => true,
			'default'     => 'primary',
			'placeholder' => esc_html__( 'Default', 'bricks' ),
			'required'    => [ 'hideDownloadButton', '=', '' ],
		];

		$this->controls['buttonCircle'] = [
			'group'    => 'button',
			'label'    => esc_html__( 'Circle', 'bricks' ),
			'type'     => 'checkbox',
			'required' => [ 'hideDownloadButton', '=', '' ],
		];

		$this->controls['buttonOutline'] = [
			'group'    => 'button',
			'label'    => esc_html__( 'Outline', 'bricks' ),
			'type'     => 'checkbox',
			'required' => [ 'hideDownloadButton', '=', '' ],
		];

		// PDF preview

		$this->controls['hidePdfPreview'] = [
			'group'       => 'pdf-preview',
			'label'       => esc_html__( 'Hide PDF preview', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Browser support varies. The file link remains available as a fallback.', 'bricks' ),
		];

		$this->controls['previewTitle'] = [
			'group'       => 'pdf-preview',
			'label'       => esc_html__( 'Preview title', 'bricks' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'PDF preview', 'bricks' ),
			'required'    => [
				[ 'hidePdfPreview', '=', '' ],
			],
		];

		$this->controls['previewHeight'] = [
			'group'       => 'pdf-preview',
			'label'       => esc_html__( 'Height', 'bricks' ),
			'type'        => 'number',
			'units'       => [
				'px' => [
					'min' => 1,
					'max' => 2000,
				],
			],
			'placeholder' => '600px',
			'css'         => [
				[
					'property' => 'height',
					'selector' => '.file-preview-wrap',
				],
			],
			'required'    => [ 'hidePdfPreview', '=', '' ],
		];

		$this->controls['previewDisplay'] = [
			'group'       => 'pdf-preview',
			'label'       => esc_html__( 'Display', 'bricks' ),
			'placeholder' => esc_html__( 'Inline preview', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'inline'     => esc_html__( 'Inline preview', 'bricks' ),
				'hideMobile' => esc_html__( 'Inline on desktop, hidden on mobile', 'bricks' ),
			],
			'inline'      => true,
			'required'    => [ 'hidePdfPreview', '=', '' ],
		];

		$this->controls['previewHideBreakpoint'] = [
			'group'       => 'pdf-preview',
			'label'       => esc_html__( 'Hide at breakpoint', 'bricks' ),
			'type'        => 'select',
			'options'     => $breakpoint_options,
			'placeholder' => esc_html__( 'Select', 'bricks' ),
			'inline'      => true,
			'description' => esc_html__( 'Hides the inline preview at the selected viewport width and below.', 'bricks' ),
			'required'    => [ 'previewDisplay', '=', 'hideMobile' ],
		];

		$this->controls['previewLoadOnInteraction'] = [
			'group'       => 'pdf-preview',
			'label'       => esc_html__( 'Load PDF on click', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Shows the custom preview image, generated attachment preview, or file name until clicked.', 'bricks' ),
			'required'    => [ 'hidePdfPreview', '=', '' ],
		];

		$this->controls['previewImage'] = [
			'group'       => 'pdf-preview',
			'label'       => esc_html__( 'Preview image', 'bricks' ),
			'type'        => 'image',
			'description' => esc_html__( 'Optional image shown before the PDF preview loads on click.', 'bricks' ),
			'required'    => [
				[ 'hidePdfPreview', '=', '' ],
				[ 'previewLoadOnInteraction', '!=', '' ],
			],
		];
	}

	/**
	 * Render the element.
	 *
	 * @since 2.4
	 */
	public function render() {
		$file_data = $this->get_file_data( $this->settings );

		if ( empty( $file_data['url'] ) ) {
			return $this->render_element_placeholder(
				[
					'title' => $file_data['message'] ?? esc_html__( 'No file selected.', 'bricks' ),
				]
			);
		}

		$settings                   = $this->settings;
		$file_url                   = esc_url( $file_data['url'] );
		$file_name                  = $this->get_file_name( $settings, $file_data );
		$show_pdf_preview           = empty( $settings['hidePdfPreview'] ) && $this->is_pdf( $file_data );
		$preview_display            = $this->get_preview_display( $settings );
		$show_file_name             = empty( $settings['hideFileName'] );
		$show_download_button       = empty( $settings['hideDownloadButton'] );
		$show_preview_fallback_link = $show_pdf_preview && ! $show_file_name && ! $show_download_button;

		$this->set_attribute( '_root', 'class', 'file-wrapper' );

		if ( $show_pdf_preview ) {
			$preview_display_class = $preview_display === 'hideMobile' ? 'hide-mobile' : $preview_display;
			$this->set_attribute( '_root', 'class', "file-preview-display-{$preview_display_class}" );

			if ( $preview_display === 'hideMobile' ) {
				$this->set_attribute( '_root', 'data-preview-hide-breakpoint', $this->get_preview_hide_breakpoint_width( $settings ) );
			}
		}

		echo "<div {$this->render_attributes( '_root' )}>";

		if ( $show_pdf_preview ) {
			$this->render_pdf_preview( $settings, $file_data, $file_url, $file_name, $preview_display );
		}

		if ( $show_preview_fallback_link ) {
			$this->set_attribute( 'preview-fallback-link', 'class', [ 'file-name', 'file-preview-fallback-link' ] );
			$this->set_attribute( 'preview-fallback-link', 'href', $file_url );

			if ( ! empty( $settings['openInNewTab'] ) ) {
				$this->set_attribute( 'preview-fallback-link', 'target', '_blank' );
				$this->set_attribute( 'preview-fallback-link', 'rel', 'noopener' );
			}

			echo "<a {$this->render_attributes( 'preview-fallback-link' )} hidden>" . esc_html( $file_name ) . '</a>';
		}

		if ( $show_file_name || $show_download_button ) {
			$this->set_attribute( 'content', 'class', 'file-content' );
			echo "<div {$this->render_attributes( 'content' )}>";

			$file_name_id = "brxe-{$this->uid}-file-name";

			if ( $show_file_name ) {
				$this->set_attribute( 'file-name', 'id', $file_name_id );
				$this->set_attribute( 'file-name', 'class', 'file-name' );
				$this->set_attribute( 'file-name', 'href', $file_url );

				if ( ! empty( $settings['openInNewTab'] ) ) {
					$this->set_attribute( 'file-name', 'target', '_blank' );
					$this->set_attribute( 'file-name', 'rel', 'noopener' );
				}

				echo "<a {$this->render_attributes( 'file-name' )}>" . esc_html( $file_name ) . '</a>';
			}

			if ( $show_download_button ) {
				$button_text    = ! empty( $settings['downloadButtonText'] )
					? $this->render_dynamic_data( $settings['downloadButtonText'] )
					: esc_html_x( 'Download', 'button label', 'bricks' );
				$button_style   = ! empty( $settings['buttonStyle'] ) ? $settings['buttonStyle'] : '';
				$button_classes = [ 'file-download', 'bricks-button' ];

				if ( ! empty( $settings['buttonSize'] ) ) {
					$button_classes[] = $settings['buttonSize'];
				}

				if ( isset( $settings['buttonOutline'] ) ) {
					$button_classes[] = 'outline';

					if ( $button_style ) {
						$button_classes[] = "bricks-color-{$button_style}";
					}
				} elseif ( $button_style ) {
					$button_classes[] = "bricks-background-{$button_style}";
				}

				if ( isset( $settings['buttonCircle'] ) ) {
					$button_classes[] = 'circle';
				}

				$this->set_attribute( 'download', 'class', $button_classes );
				$this->set_attribute( 'download', 'href', $file_url );
				$this->set_attribute( 'download', 'download' );

				if ( $show_file_name ) {
					$this->set_attribute( 'download', 'aria-describedby', $file_name_id );
				}

				echo "<a {$this->render_attributes( 'download' )}>" . esc_html( $button_text ) . '</a>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render the PDF preview.
	 *
	 * @since 2.4
	 *
	 * @param array  $settings        Element settings.
	 * @param array  $file_data       Resolved file data.
	 * @param string $file_url        Escaped file URL.
	 * @param string $file_name       Displayed file name.
	 * @param string $preview_display Preview display mode.
	 */
	private function render_pdf_preview( $settings, $file_data, $file_url, $file_name, $preview_display ) {
		$preview_title = ! empty( $settings['previewTitle'] )
			? $this->render_dynamic_data( $settings['previewTitle'] )
			: sprintf(
				// translators: %s: File name.
				esc_html__( 'Embed of %s.', 'bricks' ),
				$file_name
			);

		$load_on_interaction = ! empty( $settings['previewLoadOnInteraction'] );
		$delay_preview_load  = $load_on_interaction || $preview_display === 'hideMobile';

		$this->set_attribute( 'preview-wrap', 'class', 'file-preview-wrap' );
		$this->set_attribute( 'preview', 'class', 'file-preview' );
		$this->set_attribute( 'preview', $delay_preview_load ? 'data-src' : 'data', $file_url );
		$this->set_attribute( 'preview', 'type', 'application/pdf' );
		$this->set_attribute( 'preview', 'aria-label', wp_strip_all_tags( $preview_title ) );
		$this->set_attribute( 'preview-fallback', 'href', $file_url );

		if ( ! empty( $settings['openInNewTab'] ) ) {
			$this->set_attribute( 'preview-fallback', 'target', '_blank' );
			$this->set_attribute( 'preview-fallback', 'rel', 'noopener' );
		}

		echo "<div {$this->render_attributes( 'preview-wrap' )}>";

		if ( $load_on_interaction ) {
			$preview_image_url = $this->get_pdf_preview_image_url( $settings, $file_data );

			$this->set_attribute( 'preview-load', 'class', 'file-preview-placeholder' );
			$this->set_attribute( 'preview-load', 'type', 'button' );

			echo "<button {$this->render_attributes( 'preview-load' )}>";

			if ( $preview_image_url ) {
				$this->set_attribute( 'preview-image', 'class', 'file-preview-placeholder-image' );
				$this->set_attribute( 'preview-image', 'src', esc_url( $preview_image_url ) );
				$this->set_attribute( 'preview-image', 'alt', '' );
				$this->set_attribute( 'preview-image', 'loading', 'lazy' );

				echo "<img {$this->render_attributes( 'preview-image' )}>";
			} else {
				echo '<span class="file-preview-placeholder-fallback">';
				echo '<span class="file-preview-placeholder-icon" aria-hidden="true">PDF</span>';
				echo '<span class="file-preview-placeholder-name">' . esc_html( $file_name ) . '</span>';
				echo '</span>';
			}

			echo '<span class="file-preview-load-label">' . esc_html__( 'Load PDF preview', 'bricks' ) . '</span>';
			echo '</button>';
		}

		echo "<object {$this->render_attributes( 'preview' )}>";
		echo '<p class="file-preview-fallback">';
		echo "<a {$this->render_attributes( 'preview-fallback' )}>" . esc_html( $file_name ) . '</a>';
		echo '</p>';
		echo '</object>';
		echo '</div>';
	}

	/**
	 * Convert the element to a WordPress File block.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Element settings.
	 *
	 * @return array|null
	 */
	public function convert_element_settings_to_block( $settings ) {
		$file_data = $this->get_file_data( $settings );

		if ( empty( $file_data['url'] ) ) {
			return;
		}

		$file_url        = esc_url( $file_data['url'] );
		$file_name       = $this->get_file_name( $settings, $file_data );
		$show_file_name  = empty( $settings['hideFileName'] );
		$show_download   = empty( $settings['hideDownloadButton'] );
		$display_preview = empty( $settings['hidePdfPreview'] ) && $this->is_pdf( $file_data );
		$open_in_new_tab = ! empty( $settings['openInNewTab'] );
		$download_button = ! empty( $settings['downloadButtonText'] ) ? $this->render_dynamic_data( $settings['downloadButtonText'] ) : esc_html_x( 'Download', 'button label', 'bricks' );
		$preview_height  = $this->get_preview_height( $settings );
		$file_id         = ! empty( $file_data['id'] ) ? (int) $file_data['id'] : 0;
		$file_name_id    = $file_id ? "wp-block-file--media-{$file_id}" : '';
		$preview_title   = ! empty( $settings['previewTitle'] ) ? $this->render_dynamic_data( $settings['previewTitle'] ) : sprintf(
			// translators: %s: File name.
			esc_html__( 'Embed of %s.', 'bricks' ),
			$file_name
		);

		$attributes = [
			'href'               => $file_url,
			'fileName'           => $show_file_name ? $file_name : '',
			'textLinkHref'       => $file_url,
			'textLinkTarget'     => $open_in_new_tab ? '_blank' : false,
			'showDownloadButton' => $show_download,
			'downloadButtonText' => $download_button,
			'displayPreview'     => $display_preview,
			'previewHeight'      => $preview_height,
		];

		if ( $file_id ) {
			$attributes['id']     = $file_id;
			$attributes['fileId'] = $file_name_id;
		}

		$html = '<div class="wp-block-file">';

		if ( $display_preview ) {
			$html .= '<object class="wp-block-file__embed" data="' . $file_url . '" type="application/pdf" style="width:100%;height:' . $preview_height . 'px" aria-label="' . esc_attr( wp_strip_all_tags( $preview_title ) ) . '"></object>';
		}

		if ( $show_file_name ) {
			$id_attribute     = $file_name_id ? ' id="' . esc_attr( $file_name_id ) . '"' : '';
			$target_attribute = $open_in_new_tab ? ' target="_blank" rel="noreferrer noopener"' : '';
			$html            .= '<a' . $id_attribute . ' href="' . $file_url . '"' . $target_attribute . '>' . esc_html( $file_name ) . '</a>';
		}

		if ( $show_download ) {
			$described_by = $file_name_id && $show_file_name ? ' aria-describedby="' . esc_attr( $file_name_id ) . '"' : '';
			$html        .= '<a href="' . $file_url . '" class="wp-block-file__button" download' . $described_by . '>' . esc_html( $download_button ) . '</a>';
		}

		$html .= '</div>';

		return [
			'blockName'    => $this->block,
			'attrs'        => $attributes,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * Convert a WordPress File block to element settings.
	 *
	 * @since 2.4
	 *
	 * @param array $block      Parsed block.
	 * @param array $attributes Block attributes.
	 *
	 * @return array|null
	 */
	public function convert_block_to_element_settings( $block, $attributes ) {
		$file_id  = ! empty( $attributes['id'] ) ? (int) $attributes['id'] : 0;
		$file_url = ! empty( $attributes['href'] ) ? $attributes['href'] : '';

		if ( ! $file_url && $file_id ) {
			$file_url = wp_get_attachment_url( $file_id );
		}

		if ( ! $file_url ) {
			return;
		}

		$file_name = ! empty( $attributes['fileName'] ) ? wp_strip_all_tags( $attributes['fileName'] ) : $this->get_filename_from_url( $file_url );

		$attachment_url = $file_id ? wp_get_attachment_url( $file_id ) : '';
		$is_attachment  = $file_id && $attachment_url;

		$element_settings = [
			'source'             => $is_attachment ? 'file' : 'external',
			'hideFileName'       => empty( $attributes['fileName'] ),
			'openInNewTab'       => ( $attributes['textLinkTarget'] ?? '' ) === '_blank',
			'hideDownloadButton' => empty( $attributes['showDownloadButton'] ?? true ),
			'downloadButtonText' => ! empty( $attributes['downloadButtonText'] ) ? wp_strip_all_tags( $attributes['downloadButtonText'] ) : esc_html_x( 'Download', 'button label', 'bricks' ),
			'hidePdfPreview'     => empty( $attributes['displayPreview'] ),
			'customFileName'     => $file_name,
		];

		if ( $is_attachment ) {
			$element_settings['file'] = [
				'id'       => $file_id,
				'filename' => $this->get_filename_from_url( $attachment_url ),
				'mime'     => get_post_mime_type( $file_id ),
				'url'      => $attachment_url,
			];
		} else {
			$element_settings['external'] = $file_url;
		}

		if ( ! empty( $attributes['previewHeight'] ) && (int) $attributes['previewHeight'] !== 600 ) {
			$element_settings['previewHeight'] = (int) $attributes['previewHeight'] . 'px';
		}

		return $element_settings;
	}

	/**
	 * Resolve the configured file source.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Element settings.
	 *
	 * @return array
	 */
	private function get_file_data( $settings ) {
		$source    = ! empty( $settings['source'] ) ? $settings['source'] : 'file';
		$file_data = [
			'id'       => 0,
			'url'      => '',
			'filename' => '',
		];

		if ( $source === 'file' ) {
			$file_data['id']       = ! empty( $settings['file']['id'] ) ? (int) $settings['file']['id'] : 0;
			$file_data['url']      = ! empty( $settings['file']['url'] ) ? $settings['file']['url'] : '';
			$file_data['filename'] = ! empty( $settings['file']['filename'] ) ? $settings['file']['filename'] : '';
		} elseif ( $source === 'external' ) {
			$file_data['url'] = ! empty( $settings['external'] ) ? $this->render_dynamic_data( $settings['external'] ) : '';
		} elseif ( $source === 'dynamic' ) {
			if ( empty( $settings['useDynamicData'] ) ) {
				$file_data['message'] = esc_html__( 'No dynamic data set.', 'bricks' );
				return $file_data;
			}

			$media = $this->render_dynamic_data_tag( $settings['useDynamicData'], 'media' );
			$media = is_array( $media ) && isset( $media[0] ) ? $media[0] : [];

			if ( is_array( $media ) ) {
				$file_data['id']       = ! empty( $media['id'] ) ? (int) $media['id'] : 0;
				$file_data['url']      = ! empty( $media['url'] ) ? $media['url'] : '';
				$file_data['filename'] = ! empty( $media['filename'] ) ? $media['filename'] : '';
			}

			if ( empty( $file_data['url'] ) ) {
				$file_data['message'] = esc_html__( 'The dynamic data is empty.', 'bricks' );
			}
		}

		if ( ! $file_data['url'] && $file_data['id'] ) {
			$file_data['url'] = wp_get_attachment_url( $file_data['id'] );
		}

		return $file_data;
	}

	/**
	 * Resolve the displayed file name.
	 *
	 * @since 2.4
	 *
	 * @param array $settings  Element settings.
	 * @param array $file_data Resolved file data.
	 *
	 * @return string
	 */
	private function get_file_name( $settings, $file_data ) {
		if ( ! empty( $settings['customFileName'] ) ) {
			return wp_strip_all_tags( $this->render_dynamic_data( $settings['customFileName'] ) );
		}

		if ( ! empty( $file_data['filename'] ) ) {
			return $file_data['filename'];
		}

		return $this->get_filename_from_url( $file_data['url'] );
	}

	/**
	 * Extract a human-readable file name from a URL.
	 *
	 * @since 2.4
	 *
	 * @param string $url File URL.
	 *
	 * @return string
	 */
	private function get_filename_from_url( $url ) {
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$filename = $path ? rawurldecode( wp_basename( $path ) ) : '';

		return $filename ? $filename : esc_html__( 'File', 'bricks' );
	}

	/**
	 * Return the numeric preview height used by the WordPress File block.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Element settings.
	 *
	 * @return int
	 */
	private function get_preview_height( $settings ) {
		$height = ! empty( $settings['previewHeight'] ) ? (int) $settings['previewHeight'] : 600;

		return max( 1, $height );
	}

	/**
	 * Return a supported PDF preview display mode.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Element settings.
	 *
	 * @return string
	 */
	private function get_preview_display( $settings ) {
		$display = ! empty( $settings['previewDisplay'] ) ? $settings['previewDisplay'] : 'inline';

		// Preserve the behavior of the previous mobile option for elements saved during development.
		if ( $display === 'buttonMobile' ) {
			return 'hideMobile';
		}

		return in_array( $display, [ 'inline', 'hideMobile' ], true ) ? $display : 'inline';
	}

	/**
	 * Return the selected viewport width for hiding the PDF preview.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Element settings.
	 *
	 * @return int
	 */
	private function get_preview_hide_breakpoint_width( $settings ) {
		$breakpoint_key = ! empty( $settings['previewHideBreakpoint'] ) ? $settings['previewHideBreakpoint'] : 'mobile_landscape';
		$breakpoints    = Breakpoints::get_breakpoints();

		foreach ( $breakpoints as $breakpoint ) {
			if ( $breakpoint['key'] === $breakpoint_key && ! empty( $breakpoint['width'] ) ) {
				return (int) $breakpoint['width'];
			}
		}

		return 767;
	}

	/**
	 * Return the custom or generated preview image for a PDF.
	 *
	 * @since 2.4
	 *
	 * @param array $settings  Element settings.
	 * @param array $file_data Resolved file data.
	 *
	 * @return string
	 */
	private function get_pdf_preview_image_url( $settings, $file_data ) {
		$custom_preview_image_url = $this->get_custom_preview_image_url( $settings );

		if ( $custom_preview_image_url ) {
			return $custom_preview_image_url;
		}

		if ( empty( $file_data['id'] ) ) {
			return '';
		}

		$preview_image_url = wp_get_attachment_image_url( $file_data['id'], 'large' );

		return $preview_image_url ? $preview_image_url : '';
	}

	/**
	 * Resolve the configured custom PDF preview image.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Element settings.
	 *
	 * @return string
	 */
	private function get_custom_preview_image_url( $settings ) {
		if ( empty( $settings['previewImage'] ) || ! is_array( $settings['previewImage'] ) ) {
			return '';
		}

		$image = $settings['previewImage'];
		$size  = ! empty( $image['size'] ) ? $image['size'] : 'large';

		if ( ! empty( $image['useDynamicData'] ) ) {
			$dynamic_image = $this->render_dynamic_data_tag( $image['useDynamicData'], 'image', [ 'size' => $size ] );
			$dynamic_image = ! empty( $dynamic_image[0] ) ? $dynamic_image[0] : '';

			if ( ! $dynamic_image ) {
				return '';
			}

			if ( is_numeric( $dynamic_image ) ) {
				$image['id'] = Helpers::resolve_attachment_id( $dynamic_image );
			} else {
				unset( $image['id'] );
				$image['url'] = $dynamic_image;
			}
		}

		if ( ! empty( $image['id'] ) ) {
			$image_id  = Helpers::resolve_attachment_id( $image['id'] );
			$image_url = wp_get_attachment_image_url( $image_id, $size );

			return $image_url ? $image_url : '';
		}

		if ( ! empty( $image['external'] ) && is_string( $image['external'] ) && strpos( $image['external'], '{' ) !== false && strpos( $image['external'], '}' ) !== false ) {
			$image['url'] = $this->render_dynamic_data( $image['external'] );
		} elseif ( ! empty( $image['url'] ) ) {
			$image['url'] = $this->render_dynamic_data( $image['url'] );
		}

		return ! empty( $image['url'] ) && is_string( $image['url'] ) ? $image['url'] : '';
	}

	/**
	 * Determine whether the configured file is a PDF.
	 *
	 * @since 2.4
	 *
	 * @param array $file_data Resolved file data.
	 *
	 * @return bool
	 */
	private function is_pdf( $file_data ) {
		if ( ! empty( $file_data['id'] ) && get_post_mime_type( $file_data['id'] ) === 'application/pdf' ) {
			return true;
		}

		$path = ! empty( $file_data['url'] ) ? wp_parse_url( $file_data['url'], PHP_URL_PATH ) : '';

		return $path && strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === 'pdf';
	}
}
