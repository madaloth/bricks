<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Post_Reading_Time extends Element {
	public $category = 'single';
	public $name     = 'post-reading-time';
	public $icon     = 'ti-time';
	public $scripts  = [ 'bricksPostReadingTime' ];

	private static $rendering_reading_content = false;

	public function get_label() {
		return esc_html__( 'Reading time', 'bricks' );
	}

	public function set_controls() {
		$this->controls['contentSelector'] = [
			'label'       => esc_html__( 'Content selector', 'bricks' ),
			'type'        => 'text',
			'placeholder' => '.brxe-post-content',
			'description' => esc_html__( 'Fallback', 'bricks' ) . ': #brx-content',
		];

		$this->controls['prefix'] = [
			'label'   => esc_html__( 'Prefix', 'bricks' ),
			'type'    => 'text',
			'inline'  => true,
			'default' => 'Reading time: ',
		];

		$this->controls['suffix'] = [
			'label'   => esc_html__( 'Suffix', 'bricks' ),
			'type'    => 'text',
			'inline'  => true,
			'default' => ' minutes',
		];

		// Calculation method (@since 1.11)
		$this->controls['calculationMethod'] = [
			'label'       => esc_html__( 'Calculation method', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'words'      => esc_html__( 'Words per minute', 'bricks' ),
				'characters' => esc_html__( 'Characters per minute', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Words per minute', 'bricks' ),
			'inline'      => true,
		];

		$this->controls['wordsPerMinute'] = [
			'label'       => esc_html__( 'Words per minutes', 'bricks' ),
			'type'        => 'number',
			'placeholder' => 200,
			'required'    => [ 'calculationMethod', '!=', 'characters' ],
		];

		// Characters per minute (@since 1.11)
		$this->controls['charactersPerMinute'] = [
			'label'       => esc_html__( 'Characters per minute', 'bricks' ),
			'type'        => 'number',
			'placeholder' => 1000,
			'required'    => [ 'calculationMethod', '=', 'characters' ],
		];
	}

	/**
	 * Count words using the same rules as the frontend reading-time script.
	 *
	 * @since 2.3.13
	 *
	 * @param string $text Text to count.
	 *
	 * @return float
	 */
	private static function count_words( $text ) {
		$chinese_character_count  = preg_match_all( '/[\x{4e00}-\x{9fa5}]/u', $text );
		$japanese_character_count = preg_match_all( '/[\x{3040}-\x{30ff}]/u', $text );
		$other_text               = preg_replace( '/[\x{3040}-\x{30ff}\x{4e00}-\x{9fa5}]/u', '', $text );

		// Preserve the previous fallback for malformed non-UTF-8 content.
		if ( $chinese_character_count === false || $japanese_character_count === false || $other_text === null ) {
			return str_word_count( $text );
		}

		$other_words      = preg_split( '/\s+/u', $other_text, -1, PREG_SPLIT_NO_EMPTY );
		$other_word_count = 0;

		foreach ( $other_words as $word ) {
			$word_characters = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
			$word_length     = 0;

			foreach ( $word_characters as $character ) {
				// JavaScript counts supplementary Unicode characters as two UTF-16 code units.
				$word_length += strlen( $character ) === 4 ? 2 : 1;
			}

			$other_word_count += $word_length >= 15 ? ceil( $word_length / 5 ) : 1;
		}

		$chinese_word_count  = $chinese_character_count / 1.5;
		$japanese_word_count = $japanese_character_count / 2.5;

		return $chinese_word_count + $japanese_word_count + $other_word_count;
	}

	public function render() {
		// Reading elements in the measured content must not recurse or count their own labels.
		if ( self::$rendering_reading_content ) {
			return;
		}

		$settings           = $this->settings;
		$prefix             = $settings['prefix'] ?? '';
		$suffix             = $settings['suffix'] ?? '';
		$calculation_method = $settings['calculationMethod'] ?? 'words';

		/**
		 * STEP: Calculate reading time inside query loop
		 *
		 * If no content selector is set, calculate reading time based on the post content.
		 *
		 * @since 1.10
		 */
		if ( Query::is_any_looping() && ! isset( $settings['contentSelector'] ) ) {
			$post_content     = get_post_field( 'post_content', get_the_ID() );
			$stripped_content = strip_tags( $post_content );

			// Preserve editor-content calculations, including sites keeping both content formats.
			if ( trim( $stripped_content ) === '' ) {
				$bricks_data = get_post_meta( get_the_ID(), BRICKS_DB_PAGE_CONTENT, true );

				if ( is_array( $bricks_data ) && $bricks_data ) {
					$previous_elements               = Frontend::$elements;
					$previous_area                   = Frontend::$area;
					self::$rendering_reading_content = true;

					try {
						$content = Frontend::render_data( $bricks_data, 'content', get_the_ID() );
						// Keep adjacent elements separate and exclude inline scripts/styles from the count.
						$stripped_content = wp_strip_all_tags( str_replace( '<', ' <', $content ) );
					} finally {
						Frontend::$elements              = $previous_elements;
						Frontend::$area                  = $previous_area;
						self::$rendering_reading_content = false;
					}
				}
			}

			if ( $calculation_method === 'words' ) {
				$count      = self::count_words( $stripped_content );
				$per_minute = $settings['wordsPerMinute'] ?? 200;
			} else {
				// Remove whitespace before counting characters
				$count      = mb_strlen( preg_replace( '/\s+/', '', $stripped_content ) );
				$per_minute = $settings['charactersPerMinute'] ?? 1000;
			}

			$reading_time = ceil( $count / $per_minute );

			$text = $prefix . $reading_time . $suffix;

			echo "<div {$this->render_attributes( '_root' )}>$text</div>";

			return;
		}

		// STEP: Calculate reading time of content on the page outside any query loop (via JS)
		if ( $prefix ) {
			$this->set_attribute( '_root', 'data-prefix', $prefix );
		}

		if ( $suffix ) {
			$this->set_attribute( '_root', 'data-suffix', $suffix );
		}

		$this->set_attribute( '_root', 'data-calculation-method', $calculation_method );

		if ( $calculation_method === 'words' ) {
			$this->set_attribute( '_root', 'data-wpm', $settings['wordsPerMinute'] ?? 200 );
		} else {
			$this->set_attribute( '_root', 'data-cpm', $settings['charactersPerMinute'] ?? 1000 );
		}

		if ( ! empty( $settings['contentSelector'] ) ) {
			$this->set_attribute( '_root', 'data-content-selector', $settings['contentSelector'] );
		}

		echo "<div {$this->render_attributes( '_root' )}></div>";
	}
}
