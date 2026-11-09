<?php
$controls = [
	'stylesheet' => [
		'type'  => 'code',
		'mode'  => 'css',
		'label' => esc_html__( 'Custom CSS', 'bricks' ),
		'desc'  => esc_html__( 'Custom CSS is not breakpoint-aware. You have to add media queries manually.', 'bricks' ),
	]
];

return [
	'name'     => 'css',
	'controls' => $controls,
];
