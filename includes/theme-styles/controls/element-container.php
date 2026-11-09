<?php
$controls = \Bricks\Element_Container::get_display_grid_controls( '.brxe-container' );

// Theme Styles intentionally exposes a smaller set of display values than the element control.
$controls['_display']['options']     = [
	'block'       => 'block',
	'flex'        => 'flex',
	'inline-flex' => 'inline-flex', // @since 2.4
	'grid'        => 'grid',
];
$controls['_display']['placeholder'] = 'flex';

// An empty Theme Style display inherits flex, so keep all gap controls available
// alongside explicit flex, inline-flex, and grid. #86c2v7zk1; @since 2.4
$controls['_gridGap']['required'] = [ '_display', '=', [ '', 'flex', 'inline-flex', 'grid' ] ];
unset( $controls['_display']['add'], $controls['_display']['lowercase'] );

$controls['_direction'] = [
	'label'       => esc_html__( 'Direction', 'bricks' ),
	'tooltip'     => [
		'content'  => 'flex-direction',
		'position' => 'top-left',
	],
	'type'        => 'direction',
	'css'         => [
		[
			'property' => 'flex-direction',
			'selector' => '.brxe-container',
		],
	],
	'inline'      => true,
	'rerender'    => true,
	'placeholder' => 'column',
	'required'    => [ '_display', '=', [ '', 'flex', 'inline-flex' ] ],
];

$controls['_justifyContent'] = [
	'label'        => esc_html__( 'Align main axis', 'bricks' ),
	'tooltip'      => [
		'content'  => 'justify-content',
		'position' => 'top-left',
	],
	'type'         => 'justify-content',
	'isHorizontal' => false,
	'css'          => [
		[
			'property' => 'justify-content',
			'selector' => '.brxe-container',
		],
	],
	'required'     => [ '_display', '=', [ '', 'flex', 'inline-flex' ] ],
];

$controls['_alignItems'] = [
	'label'        => esc_html__( 'Align cross axis', 'bricks' ),
	'tooltip'      => [
		'content'  => 'align-items',
		'position' => 'top-left',
	],
	'type'         => 'align-items',
	'isHorizontal' => false,
	'css'          => [
		[
			'property' => 'align-items',
			'selector' => '.brxe-container',
		],
	],
	'required'     => [ '_display', '=', [ '', 'flex', 'inline-flex' ] ],
];

$controls['width'] = [
	'label'       => esc_html__( 'Width', 'bricks' ),
	'type'        => 'number',
	'units'       => true,
	'css'         => [
		[
			'property' => 'width',
			'selector' => '.brxe-container',
		],

		// WooCommerce wrapper
		[
			'property' => 'width',
			'selector' => '.woocommerce main.site-main',
		],

		// Single post, WooCommerce cart, etc.
		[
			'property' => 'width',
			'selector' => '#brx-content.wordpress',
		],
	],
	'placeholder' => '1100px',
];

$controls['widthMin'] = [
	'tab'   => 'style',
	'group' => '_layout',
	'label' => esc_html__( 'Min. width', 'bricks' ),
	'type'  => 'number',
	'units' => true,
	'css'   => [
		[
			'property' => 'min-width',
			'selector' => '.brxe-container',
		],

		// Single post, WooCommerce cart, etc.
		[
			'property' => 'min-width',
			'selector' => '#brx-content.wordpress',
		],
	],
];

$controls['widthMax'] = [
	'tab'   => 'style',
	'group' => '_layout',
	'label' => esc_html__( 'Max. width', 'bricks' ),
	'type'  => 'number',
	'units' => true,
	'css'   => [
		[
			'property' => 'max-width',
			'selector' => '.brxe-container',
		],

		// Single post, WooCommerce cart, etc.
		[
			'property' => 'max-width',
			'selector' => '#brx-content.wordpress',
		],
	],
];

// Individual gaps intentionally remain visible with the shorthand so users can
// inspect and clear the declarations that override it. #86c2v7zk1; @since 2.4
$controls['_columnGap'] = [
	'label'    => esc_html__( 'Column gap', 'bricks' ),
	'type'     => 'number',
	'units'    => true,
	'css'      => [
		[
			'property' => 'column-gap',
			'selector' => '.brxe-container',
		],
	],
	'required' => [ '_display', '=', [ '', 'flex', 'inline-flex', 'grid' ] ],
];

$controls['_rowGap'] = [
	'label'    => esc_html__( 'Row gap', 'bricks' ),
	'type'     => 'number',
	'units'    => true,
	'css'      => [
		[
			'property' => 'row-gap',
			'selector' => '.brxe-container',
		],
	],
	'required' => [ '_display', '=', [ '', 'flex', 'inline-flex', 'grid' ] ],
];

$controls['margin'] = [
	'label' => esc_html__( 'Margin', 'bricks' ),
	'type'  => 'spacing',
	'css'   => [
		[
			'property' => 'margin',
			'selector' => '.brxe-container',
		],
	],
];

$controls['padding'] = [
	'label' => esc_html__( 'Padding', 'bricks' ),
	'type'  => 'spacing',
	'css'   => [
		[
			'property' => 'padding',
			'selector' => '.brxe-container',
		],
	],
];

return [
	'name'     => 'container',
	'controls' => $controls,
];
