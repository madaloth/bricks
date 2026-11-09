<?php
$controls = [];

$controls['contextualSpacingRemoveDefaultMargins'] = [
	'label'             => esc_html__( 'Remove default margins', 'bricks' ),
	'desc'              => esc_html__( 'Select the elements for which you want to remove the default margins.', 'bricks' ),
	'type'              => 'select',
	'multiple'          => true,
	'add'               => true,
	'placeholder'       => esc_html__( 'Select HTML tags', 'bricks' ),
	'placeholderSearch' => esc_html__( 'Custom tag', 'bricks' ),
	'options'           => [
		'h1,h2,h3,h4,h5,h6' => esc_html__( 'Headings', 'bricks' ) . ' (h1 - h6)',
		'p'                 => esc_html__( 'Paragraph', 'bricks' ) . ' (p)',
		'ul'                => esc_html__( 'Unordered list', 'bricks' ) . ' (ul)',
		'ol'                => esc_html__( 'Ordered list', 'bricks' ) . ' (ol)',
		'li'                => esc_html__( 'List item', 'bricks' ) . ' (li)',
		'figure'            => esc_html__( 'Figure', 'bricks' ) . ' (figure)',
		'blockquote'        => esc_html__( 'Blockquote', 'bricks' ) . ' (blockquote)',
	],
];

$controls['contextualSpacingRemoveDefaultPadding'] = [
	'label'             => esc_html__( 'Remove default padding', 'bricks' ),
	'desc'              => esc_html__( 'Select the elements for which you want to remove the default padding.', 'bricks' ),
	'type'              => 'select',
	'multiple'          => true,
	'add'               => true,
	'placeholder'       => esc_html__( 'Select HTML tags', 'bricks' ),
	'placeholderSearch' => esc_html__( 'Custom tag', 'bricks' ),
	'options'           => [
		'ul'         => esc_html__( 'Unordered list', 'bricks' ) . ' (ul)',
		'ol'         => esc_html__( 'Ordered list', 'bricks' ) . ' (ol)',
		'blockquote' => esc_html__( 'Blockquote', 'bricks' ) . ' (blockquote)',
		'button'     => esc_html__( 'Button', 'bricks' ) . ' (button)',
	],
];

$controls['contextualSpacingSep'] = [
	'label' => esc_html__( 'Contextual spacing', 'bricks' ),
	'type'  => 'separator',
	'desc'  => sprintf(
		'<p>%s %s</p>',
		esc_html__( 'Contextual spacing applies a top margin to elements with a preceding sibling within embedded content.', 'bricks' ),
		\Bricks\Helpers::article_link( 'builder/styling/contextual-spacing/', esc_html__( 'Learn more', 'bricks' ) )
	),
];

// Woo compatibility classes also occur on page wrappers; target prose regions only.
$woo_content_roots     = \Bricks\Woocommerce::get_contextual_spacing_content_roots();
$woo_managed_exclusion = \Bricks\Woocommerce::get_contextual_spacing_managed_exclusion();

// Heading
$heading_spacing_css_selectors = [
	'.brxe-text * + :is(h1, h2, h3, h4, h5, h6)',
	'.brxe-post-content:not([data-source=bricks]) * + :is(h1, h2, h3, h4, h5, h6)',
	$woo_content_roots . ' * + :is(h1, h2, h3, h4, h5, h6)' . $woo_managed_exclusion,
];

$controls['contextualSpacingHeading'] = [
	'type'        => 'number',
	'units'       => true,
	'label'       => esc_html__( 'Heading', 'bricks' ),
	'info'        => 'margin-block-start',
	'css'         => [
		[
			'property' => 'margin-block-start',
			'selector' => join( ', ', $heading_spacing_css_selectors ),
		],
	],
	'placeholder' => '',
];

// Woo form fields and mini-cart totals have their own spacing, including inside Post Content.
$woo_spacing_exclusions = ':not(:where(.woocommerce-mini-cart__total, .woocommerce-checkout *, .brxe-woocommerce-account-form-edit-account *))';

// Paragraph
$paragraph_spacing_css_selectors = [
	'.brxe-text * + p' . $woo_spacing_exclusions,
	'.brxe-post-content:not([data-source=bricks]) * + p' . $woo_spacing_exclusions,
	$woo_content_roots . ' * + p' . $woo_spacing_exclusions . $woo_managed_exclusion,
];

$controls['contextualSpacingParagraph'] = [
	'type'        => 'number',
	'units'       => true,
	'label'       => esc_html__( 'Paragraph', 'bricks' ),
	'info'        => 'margin-block-start',
	'css'         => [
		[
			'property' => 'margin-block-start',
			'selector' => join( ', ', $paragraph_spacing_css_selectors ),
		],
	],
	'placeholder' => '',
];

// Fallback spacing
$fallback_css_selectors = [
	'.brxe-text * + *' . $woo_spacing_exclusions,
	'.brxe-post-content:not([data-source=bricks]) * + *' . $woo_spacing_exclusions,
	$woo_content_roots . ' * + *' . $woo_spacing_exclusions . $woo_managed_exclusion,
];

$controls['contextualSpacingFallback'] = [
	'type'        => 'number',
	'units'       => true,
	'label'       => esc_html__( 'Fallback spacing', 'bricks' ),
	'info'        => 'margin-block-start',
	'css'         => [
		[
			'property' => 'margin-block-start',
			'selector' => join( ', ', $fallback_css_selectors ),
		],
	],
	'placeholder' => '',
	'desc'        => esc_html__( 'Fallback applies to elements in embedded content without a specific spacing rule.', 'bricks' ),
];

// Additional target elements: Use unit-aware number controls for spacing. (#86cbengc6; @since 2.4.3)
$controls['contextualSpacingCustomSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Additional target elements', 'bricks' ),
	'desc'  => esc_html__( 'Extend contextual spacing to other elements within embedded content.', 'bricks' ),
];

$controls['contextualSpacingCustomTarget'] = [
	'type'          => 'repeater',
	'titleProperty' => 'selector',
	'selector'      => 'contextualSpacing',
	'placeholder'   => esc_html__( 'Target', 'bricks' ),
	'fields'        => [
		'selector'     => [
			'type'              => 'select',
			'add'               => true,
			'label'             => esc_html__( 'Selector', 'bricks' ),
			'placeholder'       => esc_html__( 'HTML tag', 'bricks' ),
			'placeholderSearch' => esc_html__( 'Custom tag', 'bricks' ) . ' / ' . esc_html__( 'Selector', 'bricks' ),
			'options'           => [
				'ul'         => esc_html__( 'Unordered list', 'bricks' ) . ' (ul)',
				'ol'         => esc_html__( 'Ordered list', 'bricks' ) . ' (ol)',
				'li'         => esc_html__( 'List item', 'bricks' ) . ' (li)',
				'figure'     => esc_html__( 'Figure', 'bricks' ) . ' (figure)',
				'blockquote' => esc_html__( 'Blockquote', 'bricks' ) . ' (blockquote)',
			],
		],
		'marginStart'  => [
			'type'           => 'number',
			'units'          => true,
			'label'          => esc_html__( 'Margin', 'bricks' ) . ': ' . esc_html_x( 'Top', 'position', 'bricks' ),
			'info'           => 'margin-block-start',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'required'       => [
				'selector',
				'!=' => ''
			],
			'css'            => [
				[
					'property' => 'margin-block-start',
				],
			],
		],
		'marginEnd'    => [
			'type'           => 'number',
			'units'          => true,
			'label'          => esc_html__( 'Margin', 'bricks' ) . ': ' . esc_html__( 'Bottom', 'bricks' ),
			'info'           => 'margin-block-end',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'required'       => [
				'selector',
				'!=' => ''
			],
			'css'            => [
				[
					'property' => 'margin-block-end',
				],
			],
		],
		'paddingStart' => [
			'type'           => 'number',
			'units'          => true,
			'label'          => esc_html__( 'Padding', 'bricks' ) . ': ' . esc_html__( 'Start', 'bricks' ),
			'info'           => 'padding-inline-start',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'required'       => [
				'selector',
				'!=' => ''
			],
			'css'            => [
				[
					'property' => 'padding-inline-start',
				],
			],
		],
		'paddingEnd'   => [
			'type'           => 'number',
			'units'          => true,
			'label'          => esc_html__( 'Padding', 'bricks' ) . ': ' . esc_html__( 'End', 'bricks' ),
			'info'           => 'padding-inline-end',
			'hasDynamicData' => false,
			'hasVariables'   => true,
			'required'       => [
				'selector',
				'!=' => ''
			],
			'css'            => [
				[
					'property' => 'padding-inline-end',
				],
			],
		],
	],
];

// Apply spacing inside
$controls['contextualSpacingApplyToSep'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Apply spacing inside', 'bricks' ),
];

$controls['contextualSpacingApplyTo'] = [
	'type'        => 'text',
	'desc'        => esc_html__( 'Contextual spacing targets embedded content (Rich Text, Post Content, WooCommerce). Use this field to apply contextual spacing to additional selectors, separated by commas.', 'bricks' ),
	'placeholder' => '.contextual-spacing, .brxe-shortcode',
];

return [
	'name'     => 'contextualSpacing',
	'controls' => $controls,
];
