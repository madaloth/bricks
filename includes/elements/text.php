<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Text extends Element {
	public $block    = [ 'core/paragraph', 'core/list' ];
	public $category = 'basic';
	public $name     = 'text';
	public $icon     = 'ti-align-left';

	public function get_label() {
		return esc_html__( 'Rich text', 'bricks' );
	}

	public function set_controls() {
		$this->controls['_background']['css'][0]['selector'] = '';
		$this->controls['_border']['css'][0]['selector']     = '';

		// Typography set in element should precede theme style link styles
		$this->controls['_typography']['css'][] = [
			'selector' => $this->css_selector . ' a',
			'property' => 'font',
		];

		// Inherit font-size set in typograhy on links to prevent issue with units like 'em' (@since 1.9.6)
		$this->controls['_typography']['css'][] = [
			'selector' => $this->css_selector . ' a',
			'property' => 'font-size',
			'value'    => 'inherit',
		];

		$this->controls['text'] = [
			'type'    => 'editor',
			'default' => '<p>' . esc_html__( 'Here goes your text ... Select any part of your text to access the formatting toolbar.', 'bricks' ) . '</p>',
		];

		$this->controls['type'] = [
			'label'       => esc_html__( 'Type', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'hero' => esc_html__( 'Hero', 'bricks' ),
				'lead' => esc_html__( 'Lead', 'bricks' ),
			],
			'inline'      => true,
			'reset'       => true,
			'placeholder' => esc_html__( 'None', 'bricks' ),
		];

		$this->controls['style'] = [
			'label'       => esc_html__( 'Style', 'bricks' ),
			'type'        => 'select',
			'options'     => $this->control_options['styles'],
			'inline'      => true,
			'reset'       => true,
			'placeholder' => esc_html__( 'None', 'bricks' ),
		];

		$this->controls['wordsLimit'] = [
			'label' => esc_html__( 'Words limit', 'bricks' ),
			'type'  => 'number',
			'min'   => 1,
		];

		$this->controls['readMore'] = [
			'label'          => esc_html__( 'Read more', 'bricks' ),
			'type'           => 'text',
			'inline'         => true,
			'hasDynamicData' => false,
			'required'       => [ 'wordsLimit', '!=', '' ],
		];
	}

	public function render() {
		$settings = $this->settings;

		if ( ! isset( $settings['text'] ) || $settings['text'] === '' ) {
			return;
		}

		$content = $settings['text'];

		$content = $this->render_dynamic_data( $content );

		$content = Helpers::parse_editor_content( $content );

		// Trimming the content to the specified number of words while handling HTML tags properly (@since 1.9.3)
		if ( ! empty( $settings['wordsLimit'] ) && is_numeric( $settings['wordsLimit'] ) ) {
			$more    = $settings['readMore'] ?? '';
			$content = Helpers::trim_words( $content, $settings['wordsLimit'], $more, true, false );
		}

		if ( ! empty( $settings['type'] ) ) {
			$this->set_attribute( '_root', 'class', "bricks-type-{$settings['type']}" );
		}

		if ( ! empty( $settings['style'] ) ) {
			$this->set_attribute( '_root', 'class', "bricks-color-{$settings['style']}" );
		}

		echo "<div {$this->render_attributes( '_root' )}>{$content}</div>";
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-text">
			<contenteditable
				:name="name"
				controlKey="text"
				toolbar="true"
				:settings="settings"
				:class="[
					settings.type ? `bricks-type-${settings.type}` : null,
					settings.style ? `bricks-color-${settings.style}` : null
				]"
			/>
		</script>
		<?php
	}

	public function convert_element_settings_to_block( $settings ) {
		$block = [ 'blockName' => $this->block ];

		$block['attrs']        = [];
		$block['innerContent'] = [];

		if ( ! isset( $settings['text'] ) ) {
			return;
		}

		$text = trim( $settings['text'] );

		// A Rich Text element can contain multiple block-level nodes. Keep that HTML
		// in a Custom HTML block instead of pretending it is one paragraph or list.
		// Unlike a Classic block, its delimiters survive editor saves so sync stays enabled.
		$block['blockName']    = 'core/html';
		$block['innerContent'] = [ $text ];

		$single_root = false;

		// WordPress adds its list class on save; other root attributes stay on the HTML path.
		if ( preg_match( '#^<(p|ul|ol)(?:\s+class=(["\'])wp-block-list\2)?\s*>#i', $text, $matches ) && ( strtolower( $matches[1] ) !== 'p' || empty( $matches[2] ) ) ) {
			// Count matching root tags so a nested list remains native, but sibling lists do not.
			preg_match_all( '#</?' . $matches[1] . '\b[^>]*>#i', $text, $tags, PREG_OFFSET_CAPTURE );
			$depth = 0;

			foreach ( $tags[0] as $tag ) {
				$depth += strpos( $tag[0], '</' ) === 0 ? -1 : 1;

				if ( $depth === 0 ) {
					$single_root = $tag[1] + strlen( $tag[0] ) === strlen( $text );
					break;
				}
			}
		}

		if ( $single_root ) {
			$matches[1]         = strtolower( $matches[1] );
			$block['blockName'] = $matches[1] === 'p' ? 'core/paragraph' : 'core/list';

			if ( $matches[1] === 'ol' ) {
				$block['attrs']['ordered'] = true;
			}
		}

		return $block;
	}

	public function convert_block_to_element_settings( $block, $attributes ) {
		// Modern lists store their items as inner blocks rather than in innerHTML.
		$text = ! empty( $block['innerBlocks'] ) && $block['blockName'] === 'core/list' ? render_block( $block ) : $block['innerHTML'];

		// Check for content
		$has_text = strip_tags( $text ); // Remove <p> tag
		$has_text = str_replace( [ "\r", "\n" ], '', $has_text ); // Remove line breaks

		if ( ! $has_text ) {
			return;
		}

		return [ 'text' => $text ];
	}
}
