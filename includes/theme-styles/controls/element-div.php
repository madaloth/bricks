<?php
$controls = \Bricks\Element_Container::get_display_grid_controls( '.brxe-div:where(:not(.brx-dropdown-content))' );

$controls['_display']['options']     = [
	'block'       => 'block',
	'flex'        => 'flex',
	'inline-flex' => 'inline-flex', // @since 2.4
	'grid'        => 'grid',
];
$controls['_display']['placeholder'] = 'block';
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
			'selector' => '.brxe-div',
		],
	],
	'inline'      => true,
	'rerender'    => true,
	'placeholder' => 'row',
	'required'    => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
];

$controls['_justifyContent'] = [
	'label'    => esc_html__( 'Align main axis', 'bricks' ),
	'tooltip'  => [
		'content'  => 'justify-content',
		'position' => 'top-left',
	],
	'type'     => 'justify-content',
	'css'      => [
		[
			'property' => 'justify-content',
			'selector' => '.brxe-div',
		],
	],
	'required' => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
];

$controls['_alignItems'] = [
	'label'    => esc_html__( 'Align cross axis', 'bricks' ),
	'tooltip'  => [
		'content'  => 'align-items',
		'position' => 'top-left',
	],
	'type'     => 'align-items',
	'css'      => [
		[
			'property' => 'align-items',
			'selector' => '.brxe-div',
		],
	],
	'required' => [ '_display', '=', [ 'flex', 'inline-flex' ] ],
];

$controls['width'] = [
	'label' => esc_html__( 'Width', 'bricks' ),
	'type'  => 'number',
	'units' => true,
	'css'   => [
		[
			'property' => 'width',
			'selector' => '.brxe-div',
		],
	],
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
			'selector' => '.brxe-div',
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
			'selector' => '.brxe-div',
		],
	],
];

// Div has no inherited flex placeholder in Theme Styles; expose the complete
// gap family only for an explicit flex, inline-flex, or grid display.
// #86c2v7zk1; @since 2.4
$controls['_columnGap'] = [
	'label'    => esc_html__( 'Column gap', 'bricks' ),
	'type'     => 'number',
	'units'    => true,
	'css'      => [
		[
			'property' => 'column-gap',
			'selector' => '.brxe-div',
		],
	],
	'required' => [ '_display', '=', [ 'flex', 'inline-flex', 'grid' ] ],
];

$controls['_rowGap'] = [
	'label'    => esc_html__( 'Row gap', 'bricks' ),
	'type'     => 'number',
	'units'    => true,
	'css'      => [
		[
			'property' => 'row-gap',
			'selector' => '.brxe-div',
		],
	],
	'required' => [ '_display', '=', [ 'flex', 'inline-flex', 'grid' ] ],
];

$controls['margin'] = [
	'label' => esc_html__( 'Margin', 'bricks' ),
	'type'  => 'spacing',
	'css'   => [
		[
			'property' => 'margin',
			'selector' => '.brxe-div',
		],
	],
];

$controls['padding'] = [
	'label' => esc_html__( 'Padding', 'bricks' ),
	'type'  => 'spacing',
	'css'   => [
		[
			'property' => 'padding',
			'selector' => '.brxe-div',
		],
	],
];

return [
	'name'     => 'div',
	'controls' => $controls,
];
