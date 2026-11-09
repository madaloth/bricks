<?php
$controls = [];

// #86c8d8cma: Match only real Bricks button style classes so custom classes
// like "dark-mode-toggle" do not inherit style presets.
// #86c4q0j31: Keep the base button class specificity, but zero every qualifier.
// Global class CSS loads later and can then override theme styles without
// allowing the frontend button defaults to win over them.
$button_style_selector = static function( $style, $suffix = '' ) {
	return ".bricks-button:where(.bricks-background-{$style}{$suffix}), .bricks-button:where(.bricks-color-{$style}{$suffix})";
};

$button_background_selector = static function( $style, $suffix = '' ) {
	return ".bricks-button:where(.bricks-background-{$style}:not(.outline){$suffix})";
};

$button_outline_selector = static function( $style ) {
	return ".bricks-button:where(.bricks-color-{$style}.outline)";
};

// Default

$controls['defaultSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Default', 'bricks' ),
];

$controls['typography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => '.bricks-button',
		],
	],
];

$controls['background'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => '.bricks-button:where(:not([class*="bricks-background-"]):not([class*="bricks-color-"]):not(.outline))',
		],
	],
];

$controls['border'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => '.bricks-button',
		],
	],
];

$controls['boxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => '.bricks-button',
		],
	],
];

$controls['transition'] = [
	'label'          => esc_html__( 'Transition', 'bricks' ),
	'css'            => [
		[
			'property' => 'transition',
			'selector' => '.bricks-button',
		],
	],
	'hasDynamicData' => false,
	'hasVariables'   => true,
	'type'           => 'text',
	'description'    => sprintf( '<a href="https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_Transitions/Using_CSS_transitions" target="_blank">%s</a>', esc_html__( 'Learn more about CSS transitions', 'bricks' ) ),
];

$controls['outlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => '.bricks-button:where(.outline)',
		],
	],
];

$controls['outlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => '.bricks-button:where(.outline)',
		],
	],
];

$controls['outlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => '.bricks-button:where(.outline)',
		],
	],
];

$controls['outlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => '.bricks-button:where(.outline)',
		],
	],
];

// Primary

$controls['primarySeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Primary', 'bricks' ),
];

$controls['primaryTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'primary' ),
		],
	],
];

$controls['primaryBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'primary' ),
		],
	],
];

$controls['primaryBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'primary' ),
		],
	],
];

$controls['primaryBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'primary' ),
		],
	],
];

// Outline

$controls['primaryOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'primary' ),
		],
	],
];

$controls['primaryOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'primary' ),
		],
	],
];

$controls['primaryOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'primary' ),
		],
	],
];

$controls['primaryOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'primary' ),
		],
	],
];

// Secondary

$controls['secondarySeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Secondary', 'bricks' ),
];

$controls['secondaryTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'secondary' ),
		],
	],
];

$controls['secondaryBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'secondary' ),
		],
	],
];

$controls['secondaryBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'secondary' ),
		],
	],
];

$controls['secondaryBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'secondary' ),
		],
	],
];

// Outline

$controls['secondaryOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'secondary' ),
		],
	],
];

$controls['secondaryOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'secondary' ),
		],
	],
];

$controls['secondaryOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'secondary' ),
		],
	],
];

$controls['secondaryOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'secondary' ),
		],
	],
];

// Light

$controls['lightSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html_x( 'Light', 'color', 'bricks' ),
];

// #86c02rxbe excludes lightbox links. The selector helpers keep that
// exclusion at zero specificity so outline hover styles still win.
$controls['lightTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'light', ':not(.bricks-lightbox)' ),
		],
	],
];

$controls['lightBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'light', ':not(.bricks-lightbox)' ),
		],
	],
];

$controls['lightBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'light', ':not(.bricks-lightbox)' ),
		],
	],
];

$controls['lightBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'light', ':not(.bricks-lightbox)' ),
		],
	],
];

// Outline

$controls['lightOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'light' ),
		],
	],
];

$controls['lightOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'light' ),
		],
	],
];

$controls['lightOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'light' ),
		],
	],
];

$controls['lightOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'light' ),
		],
	],
];

// Dark

$controls['darkSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Dark', 'bricks' ),
];

$controls['darkTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'dark' ),
		],
	],
];

$controls['darkBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'dark' ),
		],
	],
];

$controls['darkBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'dark' ),
		],
	],
];

$controls['darkBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'dark' ),
		],
	],
];

// Outline

$controls['darkOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'dark' ),
		],
	],
];

$controls['darkOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'dark' ),
		],
	],
];

$controls['darkOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'dark' ),
		],
	],
];

$controls['darkOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'dark' ),
		],
	],
];

// Muted

$controls['mutedSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html_x( 'Muted', 'style variant', 'bricks' ),
];

$controls['mutedTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'muted' ),
		],
	],
];

$controls['mutedBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'muted' ),
		],
	],
];

$controls['mutedBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'muted' ),
		],
	],
];

$controls['mutedBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'muted' ),
		],
	],
];

// Outline

$controls['mutedOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'muted' ),
		],
	],
];

$controls['mutedOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'muted' ),
		],
	],
];

$controls['mutedOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'muted' ),
		],
	],
];

$controls['mutedOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'muted' ),
		],
	],
];

// Info

$controls['infoSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Info', 'bricks' ),
];

$controls['infoTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'info' ),
		],
	],
];

$controls['infoBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'info' ),
		],
	],
];

$controls['infoBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'info' ),
		],
	],
];

$controls['infoBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'info' ),
		],
	],
];

// Outline

$controls['infoOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'info' ),
		],
	],
];

$controls['infoOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'info' ),
		],
	],
];

$controls['infoOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'info' ),
		],
	],
];

$controls['infoOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'info' ),
		],
	],
];

// Success

$controls['successSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Success', 'bricks' ),
];

$controls['successTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'success' ),
		],
	],
];

$controls['successBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'success' ),
		],
	],
];

$controls['successBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'success' ),
		],
	],
];

$controls['successBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'success' ),
		],
	],
];

// Outline

$controls['successOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'success' ),
		],
	],
];

$controls['successOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'success' ),
		],
	],
];

$controls['successOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'success' ),
		],
	],
];

$controls['successOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'success' ),
		],
	],
];

// Warning

$controls['warningSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Warning', 'bricks' ),
];

$controls['warningTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'warning' ),
		],
	],
];

$controls['warningBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'warning' ),
		],
	],
];

$controls['warningBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'warning' ),
		],
	],
];

$controls['warningBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'warning' ),
		],
	],
];

// Outline

$controls['warningOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'warning' ),
		],
	],
];

$controls['warningOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'warning' ),
		],
	],
];

$controls['warningOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'warning' ),
		],
	],
];

$controls['warningOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'warning' ),
		],
	],
];

// Danger

$controls['dangerSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Style', 'bricks' ) . ' - ' . esc_html__( 'Danger', 'bricks' ),
];

$controls['dangerTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_style_selector( 'danger' ),
		],
	],
];

$controls['dangerBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Background color', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_background_selector( 'danger' ),
		],
	],
];

$controls['dangerBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_style_selector( 'danger' ),
		],
	],
];

$controls['dangerBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_style_selector( 'danger' ),
		],
	],
];

// Outline

$controls['dangerOutlineTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => $button_outline_selector( 'danger' ),
		],
	],
];

$controls['dangerOutlineBackground'] = [
	'type'  => 'color',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Background', 'bricks' ),
	'css'   => [
		[
			'property' => 'background-color',
			'selector' => $button_outline_selector( 'danger' ),
		],
	],
];

$controls['dangerOutlineBorder'] = [
	'type'  => 'border',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Border', 'bricks' ),
	'css'   => [
		[
			'property' => 'border',
			'selector' => $button_outline_selector( 'danger' ),
		],
	],
];

$controls['dangerOutlineBoxShadow'] = [
	'type'  => 'box-shadow',
	'label' => esc_html__( 'Outline', 'bricks' ) . ' - ' . esc_html__( 'Box shadow', 'bricks' ),
	'css'   => [
		[
			'property' => 'box-shadow',
			'selector' => $button_outline_selector( 'danger' ),
		],
	],
];

// Size - Default

$controls['sizeDefaultSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Size - Default', 'bricks' ),
];

$controls['sizeDefaultPadding'] = [
	'type'        => 'spacing',
	'label'       => esc_html__( 'Padding', 'bricks' ),
	'css'         => [
		[
			'property' => 'padding',
			'selector' => '.bricks-button',
		],
	],
	'placeholder' => [
		'top'    => '0.5em',
		'right'  => '1em',
		'bottom' => '0.5em',
		'left'   => '1em',
	],
];

// Size - Small

$controls['sizeSmSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Size - Small', 'bricks' ),
];

$controls['sizeSmPadding'] = [
	'type'        => 'spacing',
	'label'       => esc_html__( 'Padding', 'bricks' ),
	'css'         => [
		[
			'property' => 'padding',
			'selector' => '.bricks-button:where(.sm)',
		],
	],
	'placeholder' => [
		'top'    => '0.4em',
		'right'  => '1em',
		'bottom' => '0.4em',
		'left'   => '1em',
	],
];

$controls['sizeSmTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => '.bricks-button:where(.sm)',
		],
	],
];

// Size - Medium

$controls['sizeMdSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Size - Medium', 'bricks' ),
];

$controls['sizeMdPadding'] = [
	'type'        => 'spacing',
	'label'       => esc_html__( 'Padding', 'bricks' ),
	'css'         => [
		[
			'property' => 'padding',
			'selector' => '.bricks-button:where(.md)',
		],
	],
	'placeholder' => [
		'top'    => '0.5em',
		'right'  => '1em',
		'bottom' => '0.5em',
		'left'   => '1em',
	],
];


$controls['sizeMdTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => '.bricks-button:where(.md)',
		],
	],
];

// Size - Large

$controls['sizeLgSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Size - Large', 'bricks' ),
];

$controls['sizeLgPadding'] = [
	'type'        => 'spacing',
	'label'       => esc_html__( 'Padding', 'bricks' ),
	'css'         => [
		[
			'property' => 'padding',
			'selector' => '.bricks-button:where(.lg)',
		],
	],
	'placeholder' => [
		'top'    => '0.6em',
		'right'  => '1em',
		'bottom' => '0.6em',
		'left'   => '1em',
	],
];

$controls['sizeLgTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => '.bricks-button:where(.lg)',
		],
	],
];

// Size - Extra Large

$controls['sizeXlSeparator'] = [
	'type'  => 'separator',
	'label' => esc_html__( 'Size - Extra Large', 'bricks' ),
];

$controls['sizeXlPadding'] = [
	'type'        => 'spacing',
	'label'       => esc_html__( 'Padding', 'bricks' ),
	'css'         => [
		[
			'property' => 'padding',
			'selector' => '.bricks-button:where(.xl)',
		],
	],
	'placeholder' => [
		'top'    => '0.8em',
		'right'  => '1em',
		'bottom' => '0.8em',
		'left'   => '1em',
	],
];

$controls['sizeXlTypography'] = [
	'type'  => 'typography',
	'label' => esc_html__( 'Typography', 'bricks' ),
	'css'   => [
		[
			'property' => 'font',
			'selector' => '.bricks-button:where(.xl)',
		],
	],
];

return [
	'name'     => 'button',
	'controls' => $controls,
];
