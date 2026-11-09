<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Unified_Global_Transfer {
	const MANIFEST_SCHEMA   = 'bricks/unified-global-transfer';
	const MANIFEST_VERSION  = 1;
	const MCP_MAX_ZIP_BYTES = 33554432; // 32 MB.

	/**
	 * Settings tabs that can be transferred independently.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_settings_transfer_tabs() {
		return [
			'general'     => [
				'label'    => esc_html__( 'General', 'bricks' ),
				'settings' => [
					'postTypes',
					'wp_to_bricks',
					'bricks_to_wp',
					'bricksComponentsInBlockEditor',
					'disableThemeStylesInBlockEditor',
					'disableClassManager',
					'disableVariablesManager',
					'disableOpenGraph',
					'disableSeo',
					'customImageSizes',
					'elementAttsAsNeeded',
					'disableSkipLinks',
					'smoothScroll',
					'deleteBricksData',
					'searchResultsQueryBricksData',
					'themeStylesLoadingMethod',
					'duplicateContent',
					'saveFormSubmissions',
					'enableQueryFilters',
					'enableQueryFiltersIntegration',
					'customBreakpoints',
					'convert_container',
					'convert_element_ids_classes',
					'add_position_relative',
					'entry_animation_to_interaction',
					'login_page',
					'registration_page',
					'lost_password_page',
					'reset_password_page',
					'wp_auth_url_behavior',
					'wp_auth_url_redirect_page',
					'disable_brx_use_wp_login',
					'userActivationEnabled',
					'userActivationAutoLogin',
					'userActivationLinkSuccessPage',
					'userActivationLinkFailurePage',
					'userActivationLinkEmailFrom',
					'userActivationLinkEmailFromName',
					'userActivationLinkEmailSubject',
					'userActivationLinkEmailContent',
					'userActivationLinkEmailIsHtml',
					'passwordProtectionEnabled',
				],
			],
			'templates'   => [
				'label'    => esc_html__( 'Templates', 'bricks' ),
				'settings' => [
					'generateComponentScreenshots',
					'generateTemplateScreenshots',
					'templateScreenshotsAdminColumn',
					'templateAdminColumnThumbnailWidth',
					'templateAdminColumnThumbnailHeight',
					'defaultTemplatesDisabled',
					'publicTemplates',
					'myTemplatesAccess',
					'myTemplatesWhitelist',
					'myTemplatesPassword',
					'excludedTemplates',
					'remoteTemplates',
					'remoteTemplatesUrl',
					'remoteTemplatesPassword',
				],
			],
			'builder'     => [
				'label'    => esc_html__( 'Builder', 'bricks' ),
				'settings' => [
					'builderAutosaveDisabled',
					'builderAutosaveInterval',
					'builderMode',
					'builderModeCss',
					'builderLocale',
					'builderLanguageDirection',
					'builderToolbarLogoLink',
					'builderToolbarLogoLinkCustom',
					'builderToolbarLogoLinkNewTab',
					'builderClassPreviewOnHover',
					'builderClassAutoSelectFirst',
					'builderDisableClassAutoSelectLast',
					'builderColorPreviewOnHover',
					'builderVariablePreviewOnHover',
					'builderDisablePanelAutoExpand',
					'builderDisablePinnedControlGroups',
					'builderDisableGlobalClassesInterface',
					'builderVariablePickerHideValue',
					'builderCodeVim',
					'builderRememberSpacingLinkState',
					'builderElementBreadcrumbs',
					'builderResponsiveControlIndicator',
					'builderControlGroupVisibility',
					'builderFontFamilyControl',
					'builderMediaPicker',
					'builderMediaDetailsDocked',
					'builderMediaHealthEnabled',
					'builderMediaHealthCheckOversized',
					'builderMediaHealthCheckMissingAlt',
					'builderMediaHealthCheckBroken',
					'builderMediaHealthCheckObsolete',
					'builderMediaHealthCheckDerivatives',
					'builderMediaHealthImageMaxSize',
					'builderMediaHealthFontMaxSize',
					'builderMediaHealthDocumentMaxSize',
					'builderMediaHealthAudioMaxSize',
					'builderMediaHealthVideoMaxSize',
					'builderMediaHealthObsoleteExtensions',
					'disableElementSpacing',
					'canvasScrollIntoView',
					'structureDuplicateElement',
					'structureDeleteElement',
					'structureCollapsed',
					'structureAutoSync',
					'builderWrapElement',
					'builderInsertElement',
					'builderInsertLayout',
					'importImageOnPaste',
					'builderWpPolyfill',
					'builderCloudflareRocketLoader',
					'builderQueryMaxResults',
					'builderQueryObjectType',
					'enableDynamicDataPreview',
					'builderDisableWpCustomFields',
					'builderDynamicDropdownKey',
					'builderDynamicDropdownNoLabel',
					'builderDynamicDropdownExpand',
					'builderGlobalClassesSync',
					'builderGlobalClassesImport',
					'builderHtmlCssConverter',
					'builderDisableRestApi',
					'builderInstantNavigation',
					'builderDisableInstantNavigation',
				],
			],
			'performance' => [
				'label'    => esc_html__( 'Performance', 'bricks' ),
				'settings' => [
					'disableEmojis',
					'disableEmbed',
					'disableGoogleFonts',
					'disableLazyLoad',
					'offsetLazyLoad',
					'disableJqueryMigrate',
					'cacheQueryLoops',
					'disableClassChaining',
					'cssLoading',
					'webfontLoading',
					'customFontsPreload',
					'disableBricksCascadeLayer',
				],
			],
			'maintenance' => [
				'label'    => esc_html__( 'Maintenance mode', 'bricks' ),
				'settings' => [
					'maintenanceMode',
					'maintenanceTemplate',
					'maintenanceRenderHeader',
					'maintenanceRenderFooter',
					'maintenanceRenderPopups',
					'bypassMaintenanceUserRoles',
					'maintenanceExcludedPosts',
				],
			],
			'api-keys'    => [
				'label'     => esc_html__( 'API keys', 'bricks' ),
				'sensitive' => true,
				'settings'  => [
					'adobeFontsProjectId',
					'apiKeyUnsplash',
					'apiKeyGoogleMaps',
					'apiKeyGoogleRecaptcha',
					'apiSecretKeyGoogleRecaptcha',
					'apiKeyHCaptcha',
					'apiSecretKeyHCaptcha',
					'apiKeyTurnstile',
					'apiSecretKeyTurnstile',
					'apiKeyMailchimp',
					'apiKeySendgrid',
					'facebookAppId',
					'instagramAccessToken',
				],
				'labels'    => [
					'adobeFontsProjectId'         => esc_html__( 'Adobe fonts: Project ID', 'bricks' ),
					'apiKeyUnsplash'              => esc_html__( 'Unsplash: API key', 'bricks' ),
					'apiKeyGoogleMaps'            => esc_html__( 'Google Maps: API key', 'bricks' ),
					'apiKeyGoogleRecaptcha'       => esc_html__( 'Google reCAPTCHA v3: Site key', 'bricks' ),
					'apiSecretKeyGoogleRecaptcha' => esc_html__( 'Google reCAPTCHA v3: Secret key', 'bricks' ),
					'apiKeyHCaptcha'              => esc_html__( 'hCaptcha: Site key', 'bricks' ),
					'apiSecretKeyHCaptcha'        => esc_html__( 'hCaptcha: Secret key', 'bricks' ),
					'apiKeyTurnstile'             => esc_html__( 'Cloudflare Turnstile: Site key', 'bricks' ),
					'apiSecretKeyTurnstile'       => esc_html__( 'Cloudflare Turnstile: Secret key', 'bricks' ),
					'apiKeyMailchimp'             => esc_html__( 'Mailchimp: API key', 'bricks' ),
					'apiKeySendgrid'              => esc_html__( 'Sendgrid: API key', 'bricks' ),
					'facebookAppId'               => esc_html__( 'Facebook App ID', 'bricks' ),
					'instagramAccessToken'        => esc_html__( 'Instagram access token', 'bricks' ),
				],
			],
			'custom-code' => [
				'label'     => esc_html__( 'Custom code', 'bricks' ),
				'sensitive' => true,
				'settings'  => [
					'executeCodeEnabled',
					'customCss',
					'customScriptsHeader',
					'customScriptsBodyHeader',
					'customScriptsBodyFooter',
				],
				'labels'    => [
					'executeCodeEnabled'      => esc_html__( 'Code execution', 'bricks' ),
					'customCss'               => esc_html__( 'Custom CSS', 'bricks' ),
					'customScriptsHeader'     => esc_html__( 'Header scripts', 'bricks' ),
					'customScriptsBodyHeader' => esc_html__( 'Body (header) scripts', 'bricks' ),
					'customScriptsBodyFooter' => esc_html__( 'Body (footer) scripts', 'bricks' ),
				],
			],
			'woocommerce' => [
				'label'    => esc_html__( 'WooCommerce', 'bricks' ),
				'settings' => [
					'woocommerceDisableBuilder',
					'woocommerceUseBricksWooNotice',
					'woocommerceUseBricksWooCheckoutCoupon',
					'woocommerceUseBricksWooCheckoutLogin',
					'woocommerceUseQtyInLoop',
					'woocommerceUseVariationSwatches',
					'woocommerceBadgeSale',
					'woocommerceBadgeNew',
					'woocommerceDisableProductGalleryZoom',
					'woocommerceDisableProductGalleryLightbox',
					'woocommerceEnableAjaxAddToCart',
					'woocommerceAjaxAddingText',
					'woocommerceAjaxAddedText',
					'woocommerceAjaxResetTextAfter',
					'woocommerceAjaxHideViewCart',
					'woocommerceAjaxShowNotice',
					'woocommerceAjaxScrollToNotice',
					'woocommerceAjaxErrorAction',
					'woocommerceAjaxErrorScrollToNotice',
				],
			],
		];
	}

	/**
	 * Get a readable settings key label.
	 *
	 * @since 2.4
	 *
	 * @param array  $tab Settings transfer tab.
	 * @param string $key Settings key.
	 *
	 * @return string
	 */
	private static function get_settings_transfer_key_label( $tab, $key ) {
		if ( ! empty( $tab['labels'][ $key ] ) ) {
			return $tab['labels'][ $key ];
		}

		$label = preg_replace( '/(?<!^)[A-Z]/', ' $0', (string) $key );
		$label = str_replace( [ '-', '_' ], ' ', $label );

		return ucwords( $label );
	}

	/**
	 * Format imported settings values for the manifest preview.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Settings value.
	 *
	 * @return string
	 */
	private static function format_settings_preview_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? esc_html__( 'Enabled', 'bricks' ) : esc_html__( 'Disabled', 'bricks' );
		}

		if ( $value === null ) {
			return 'null';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Get select-style options for imported setting previews.
	 *
	 * @since 2.4
	 *
	 * @param string $key Settings key.
	 *
	 * @return array
	 */
	private static function get_settings_preview_options( $key ) {
		$options = [
			'builderMode'                => [
				'dark',
				'light',
			],
			'cssLoading'                 => [
				'',
				'file',
			],
			'themeStylesLoadingMethod'   => [
				'specific',
				'all',
			],
			'webfontLoading'             => [
				'',
				'webfontloader',
			],
			'woocommerceAjaxErrorAction' => [
				'',
				'notice',
			],
			'wp_auth_url_behavior'       => [
				'default',
				'404',
				'home',
				'custom',
			],
		];

		return $options[ $key ] ?? [];
	}

	/**
	 * Format imported settings preview options for the builder.
	 *
	 * @since 2.4
	 *
	 * @param string $key   Settings key.
	 * @param mixed  $value Settings value.
	 *
	 * @return array
	 */
	private static function format_settings_preview_options( $key, $value ) {
		$option_values = self::get_settings_preview_options( $key );

		if ( ! is_scalar( $value ) || is_bool( $value ) || empty( $option_values ) ) {
			return [];
		}

		$value         = (string) $value;
		$option_values = array_map( 'strval', $option_values );

		if ( ! in_array( $value, $option_values, true ) ) {
			$option_values[] = $value;
		}

		return array_map(
			function ( $option_value ) {
				return [
					'value' => $option_value,
					'label' => $option_value === '' ? esc_html__( 'Default', 'bricks' ) : $option_value,
				];
			},
			$option_values
		);
	}

	/**
	 * Build one imported settings preview entry.
	 *
	 * @since 2.4
	 *
	 * @param array  $tab      Settings transfer tab.
	 * @param string $key      Settings key.
	 * @param mixed  $value    Imported settings value.
	 * @param bool   $redacted Whether the value is redacted.
	 *
	 * @return array
	 */
	private static function build_settings_preview_entry( $tab, $key, $value, $redacted ) {
		$entry = [
			'key'      => $key,
			'label'    => self::get_settings_transfer_key_label( $tab, $key ),
			'value'    => $redacted ? '********' : self::format_settings_preview_value( $value ),
			'redacted' => $redacted,
			'empty'    => $value === '' || $value === null || $value === [],
			'renderer' => 'text',
		];

		if ( $redacted ) {
			$entry['renderer'] = 'redacted';

			return $entry;
		}

		$options = self::format_settings_preview_options( $key, $value );

		if ( ! empty( $options ) ) {
			$entry['renderer'] = 'options';
			$entry['rawValue'] = (string) $value;
			$entry['options']  = $options;
		} elseif ( is_bool( $value ) ) {
			$entry['renderer'] = 'boolean';
			$entry['enabled']  = $value;
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			$entry['renderer'] = 'code';
			$entry['mode']     = 'application/json';
		}

		return $entry;
	}

	/**
	 * Attach imported settings preview data to a manifest item.
	 *
	 * @since 2.4
	 *
	 * @param array $item          Manifest item.
	 * @param array $tab           Settings transfer tab.
	 * @param array $settings_data Imported settings tab data.
	 *
	 * @return void
	 */
	private static function attach_settings_item_preview( &$item, $tab, $settings_data ) {
		$settings = is_array( $settings_data['settings'] ?? null ) ? $settings_data['settings'] : [];
		$entries  = [];
		$redact   = ! empty( $tab['sensitive'] ) && ( $item['id'] ?? '' ) === 'api-keys';
		$message  = '';

		if ( $redact ) {
			$message = esc_html__( 'Imported API key values are hidden for security.', 'bricks' );
		} elseif ( ! empty( $tab['sensitive'] ) ) {
			$message = esc_html__( 'Review imported custom code carefully before importing.', 'bricks' );
		}

		foreach ( $tab['settings'] ?? [] as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$value             = $settings[ $key ];
			$entry_is_redacted = $redact && $value !== '' && $value !== null;

			$entries[] = self::build_settings_preview_entry( $tab, $key, $value, $entry_is_redacted );
		}

		$entry_count  = count( $entries );
		$item['meta'] = (string) $entry_count;

		$item['preview'] = [
			'type'       => 'settings',
			'sensitive'  => ! empty( $tab['sensitive'] ),
			'redacted'   => $redact,
			'message'    => $message,
			'count'      => $entry_count,
			'countLabel' => sprintf(
				// translators: %s: Number of imported settings in the preview.
				_n( '%s setting', '%s settings', $entry_count, 'bricks' ),
				number_format_i18n( $entry_count )
			),
			'labels'     => [
				'included' => esc_html__( 'Included in import', 'bricks' ),
				'excluded' => esc_html__( 'Not included in import', 'bricks' ),
			],
			'entries'    => $entries,
		];
	}

	/**
	 * Get breakpoint preview details.
	 *
	 * @since 2.4
	 *
	 * @param array $breakpoints Breakpoints.
	 *
	 * @return array
	 */
	private static function get_breakpoints_preview_details( $breakpoints ) {
		$base_index = 0;

		foreach ( $breakpoints as $index => $breakpoint ) {
			if ( ! empty( $breakpoint['base'] ) ) {
				$base_index = $index;
				break;
			}
		}

		$base_breakpoint = $breakpoints[ $base_index ] ?? [];
		$base_label      = $base_breakpoint['label'] ?? esc_html__( 'Base', 'bricks' );
		$widths          = array_map( 'intval', array_column( $breakpoints, 'width' ) );
		$is_mobile_first = ! empty( $base_breakpoint['width'] ) && ! empty( $widths ) && intval( $base_breakpoint['width'] ) === min( $widths );
		$mode_label      = $is_mobile_first ? esc_html__( 'Mobile first', 'bricks' ) : esc_html__( 'Desktop first', 'bricks' );

		return [
			'baseLabel' => $base_label,
			'modeLabel' => $mode_label,
		];
	}

	/**
	 * Format one breakpoint preview value.
	 *
	 * @since 2.4
	 *
	 * @param array $breakpoint Breakpoint.
	 *
	 * @return string
	 */
	private static function format_breakpoint_preview_value( $breakpoint ) {
		$details = [ intval( $breakpoint['width'] ?? 0 ) . 'px' ];

		if ( ! empty( $breakpoint['widthBuilder'] ) ) {
			$details[] =
				esc_html__( 'Builder', 'bricks' ) . ': ' .
				intval( $breakpoint['widthBuilder'] ) . 'px';
		}

		if ( ! empty( $breakpoint['base'] ) ) {
			$details[] = esc_html__( 'Base', 'bricks' );
		}

		if ( ! empty( $breakpoint['custom'] ) ) {
			$details[] = esc_html__( 'Custom', 'bricks' );
		}

		if ( ! empty( $breakpoint['paused'] ) ) {
			$details[] = esc_html__( 'Paused', 'bricks' );
		}

		return implode( ' - ', $details );
	}

	/**
	 * Attach imported breakpoints preview data to a manifest item.
	 *
	 * @since 2.4
	 *
	 * @param array $item        Manifest item.
	 * @param array $breakpoints Imported breakpoints.
	 *
	 * @return void
	 */
	private static function attach_breakpoints_item_preview( &$item, $breakpoints ) {
		if ( is_wp_error( $breakpoints ) || ! is_array( $breakpoints ) || empty( $breakpoints ) ) {
			return;
		}

		$details = self::get_breakpoints_preview_details( $breakpoints );
		$entries = [
			[
				'key'      => 'mode',
				'label'    => esc_html__( 'Mode', 'bricks' ),
				'value'    => $details['modeLabel'],
				'empty'    => false,
				'renderer' => 'text',
			],
			[
				'key'      => 'base',
				'label'    => esc_html__( 'Base breakpoint', 'bricks' ),
				'value'    => $details['baseLabel'],
				'empty'    => false,
				'renderer' => 'text',
			],
		];

		foreach ( $breakpoints as $breakpoint ) {
			$entries[] = [
				'key'      => $breakpoint['key'] ?? '',
				'label'    => $breakpoint['label'] ?? esc_html__( 'Breakpoint', 'bricks' ),
				'value'    => self::format_breakpoint_preview_value( $breakpoint ),
				'empty'    => empty( $breakpoint['width'] ),
				'renderer' => 'text',
			];
		}

		$item['preview'] = [
			'type'       => 'breakpoints',
			'count'      => count( $breakpoints ),
			'countLabel' => sprintf(
				// translators: %s: Number of imported breakpoints in the preview.
				_n( '%s breakpoint', '%s breakpoints', count( $breakpoints ), 'bricks' ),
				number_format_i18n( count( $breakpoints ) )
			),
			'labels'     => [
				'included' => esc_html__( 'Included in import', 'bricks' ),
				'excluded' => esc_html__( 'Not included in import', 'bricks' ),
			],
			'entries'    => $entries,
		];
	}

	/**
	 * Get selected setting tab IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $selected_item_ids Selected manifest item IDs.
	 *
	 * @return array
	 */
	private static function get_selected_settings_tab_ids( $selected_item_ids ) {
		$tabs         = self::get_settings_transfer_tabs();
		$selected_ids = array_values( array_filter( array_map( 'strval', is_array( $selected_item_ids ) ? $selected_item_ids : [] ) ) );

		if ( empty( $selected_ids ) || in_array( 'settings', $selected_ids, true ) ) {
			return array_keys( $tabs );
		}

		return array_values( array_filter( $selected_ids, fn( $tab_id ) => isset( $tabs[ $tab_id ] ) ) );
	}

	/**
	 * Get settings values by key list.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Settings.
	 * @param array $keys     Keys to include.
	 *
	 * @return array
	 */
	private static function get_settings_values_by_keys( $settings, $keys ) {
		$values = [];

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$values[ $key ] = $settings[ $key ];
			}
		}

		return $values;
	}

	/**
	 * Build settings transfer data for selected tabs.
	 *
	 * @since 2.4
	 *
	 * @param array $settings          Global settings.
	 * @param array $selected_item_ids Selected item IDs.
	 *
	 * @return array
	 */
	private static function build_settings_transfer_data( $settings, $selected_item_ids ) {
		$tabs     = self::get_settings_transfer_tabs();
		$tab_ids  = self::get_selected_settings_tab_ids( $selected_item_ids );
		$settings = is_array( $settings ) ? $settings : [];
		$data     = [
			'version' => 1,
			'tabs'    => [],
		];

		foreach ( $tab_ids as $tab_id ) {
			$tab      = $tabs[ $tab_id ];
			$tab_data = [];

			if ( ! empty( $tab['settings'] ) ) {
				$tab_data['settings'] = self::get_settings_values_by_keys( $settings, $tab['settings'] );

				// Explicit defaults distinguish new exports from older bundles that omitted the locale.
				if ( $tab_id === 'builder' && ! array_key_exists( 'builderLocale', $tab_data['settings'] ) ) {
					$tab_data['settings']['builderLocale'] = 'site-default';
				}
			}

			$data['tabs'][ $tab_id ] = $tab_data;
		}

		return $data;
	}

	/**
	 * Apply selected settings tabs from transfer data.
	 *
	 * @since 2.4
	 *
	 * @param array $data              Transfer data.
	 * @param array $selected_item_ids Selected item IDs.
	 *
	 * @return int Imported tab count.
	 */
	private static function import_settings_transfer_data( $data, $selected_item_ids ) {
		$tabs = self::get_settings_transfer_tabs();

		if ( ! isset( $data['tabs'] ) || ! is_array( $data['tabs'] ) ) {
			update_option( BRICKS_DB_GLOBAL_SETTINGS, $data );
			return 1;
		}

		$tab_ids          = self::get_selected_settings_tab_ids( $selected_item_ids );
		$current_settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		$current_settings = is_array( $current_settings ) ? $current_settings : [];
		$imported         = 0;

		foreach ( $tab_ids as $tab_id ) {
			if ( empty( $data['tabs'][ $tab_id ] ) || empty( $tabs[ $tab_id ] ) ) {
				continue;
			}

			$tab      = $tabs[ $tab_id ];
			$tab_data = is_array( $data['tabs'][ $tab_id ] ) ? $data['tabs'][ $tab_id ] : [];

			foreach ( $tab['settings'] ?? [] as $setting_key ) {
				// Older bundles never exported the locale, so retain the destination selection.
				if ( $setting_key === 'builderLocale' && ! array_key_exists( $setting_key, (array) ( $tab_data['settings'] ?? [] ) ) ) {
					continue;
				}

				unset( $current_settings[ $setting_key ] );
			}

			if ( ! empty( $tab_data['settings'] ) && is_array( $tab_data['settings'] ) ) {
				$current_settings = array_merge(
					$current_settings,
					self::get_settings_values_by_keys( $tab_data['settings'], $tab['settings'] ?? [] )
				);
			}

			$imported++;
		}

		update_option( BRICKS_DB_GLOBAL_SETTINGS, $current_settings );

		return $imported;
	}

	/**
	 * Normalize builder interface profiles to the shape used by their dedicated exporter.
	 *
	 * @since 2.4
	 *
	 * @param array $data Builder interface profiles transfer data.
	 *
	 * @return array|null
	 */
	private static function normalize_builder_interface_profiles_transfer_data( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( isset( $data['builderInterfaceProfiles'] ) && is_array( $data['builderInterfaceProfiles'] ) ) {
			$data = $data['builderInterfaceProfiles'];
		} elseif ( isset( $data['builderInterfaceProfile'] ) && is_array( $data['builderInterfaceProfile'] ) ) {
			$data = [ $data['builderInterfaceProfile'] ];
		}

		if ( isset( $data['config'] ) && is_array( $data['config'] ) ) {
			$data = [ $data ];
		}

		$profiles = [];

		foreach ( $data as $profile_id => $profile ) {
			if ( ! is_array( $profile ) || ! isset( $profile['config'] ) || ! is_array( $profile['config'] ) ) {
				continue;
			}

			$profile_id = ! empty( $profile['id'] ) ? sanitize_key( $profile['id'] ) : sanitize_key( $profile_id );

			if ( ! $profile_id ) {
				continue;
			}

			$profiles[ $profile_id ] = $profile;
		}

		return $profiles ? Builder::sanitize_builder_ui_profiles( $profiles ) : null;
	}

	/**
	 * Filter builder interface profiles by selected IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $profiles          Builder interface profiles.
	 * @param array $selected_item_ids Selected profile IDs.
	 *
	 * @return array
	 */
	private static function filter_builder_interface_profiles_by_ids( $profiles, $selected_item_ids ) {
		$profiles     = is_array( $profiles ) ? $profiles : [];
		$selected_ids = array_values( array_filter( array_map( 'strval', is_array( $selected_item_ids ) ? $selected_item_ids : [] ) ) );

		if ( empty( $selected_ids ) ) {
			return $profiles;
		}

		return array_filter(
			$profiles,
			function( $profile, $profile_id ) use ( $selected_ids ) {
				$profile_id = (string) ( $profile['id'] ?? $profile_id );

				return in_array( $profile_id, $selected_ids, true );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	public static function export_from_request() {
		$package = self::create_export_package(
			self::get_json_request_value( 'types', [] ),
			self::get_json_request_value( 'items', [] ),
			self::get_json_request_value( 'payloads', [] )
		);

		if ( is_wp_error( $package ) ) {
			wp_send_json_error( [ 'message' => $package->get_error_message() ] );
		}

		$zip_path = $package['path'];

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $package['filename'] . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Expires: 0' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $zip_path ) );

		ini_set( 'zlib.output_compression', '0' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		@readfile( $zip_path );

		unlink( $zip_path );

		die;
	}

	public static function inspect_from_request() {
		$package = self::open_request_transfer_package();

		if ( is_wp_error( $package ) ) {
			wp_send_json_error( [ 'message' => $package->get_error_message() ] );
		}

		$manifest = self::read_manifest_from_zip( $package['zip'] );

		if ( is_wp_error( $manifest ) ) {
			self::cleanup_request_transfer_package( $package );
			wp_send_json_error( [ 'message' => $manifest->get_error_message() ] );
		}

		$manifest = self::filter_manifest_by_permissions( $manifest, 'import' );
		$manifest = self::attach_conflicts_to_manifest( $package['zip'], $manifest );
		self::cleanup_request_transfer_package( $package );

		wp_send_json_success(
			[
				'manifest' => $manifest,
			]
		);
	}

	public static function apply_from_request() {
		$types         = self::sanitize_types( self::get_json_request_value( 'types', [] ) );
		$items         = self::sanitize_items_map( self::get_json_request_value( 'items', [] ) );
		$conflict_mode = self::sanitize_conflict_mode( self::get_text_request_value( 'conflictMode', 'skip' ) );
		$decisions     = self::sanitize_conflict_decisions( self::get_json_request_value( 'conflictDecisions', [] ) );
		$import_images = self::get_text_request_value( 'importImages', 'false' ) === 'true';

		if ( empty( $types ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No data selected.', 'bricks' ) ] );
		}

		foreach ( $types as $type ) {
			if ( ! self::user_can_access_type( $type, 'import' ) ) {
				wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
			}
		}

		$package = self::open_request_transfer_package();

		if ( is_wp_error( $package ) ) {
			wp_send_json_error( [ 'message' => $package->get_error_message() ] );
		}

		$manifest = self::read_manifest_from_zip( $package['zip'] );

		if ( is_wp_error( $manifest ) ) {
			self::cleanup_request_transfer_package( $package );
			wp_send_json_error( [ 'message' => $manifest->get_error_message() ] );
		}

		$results        = [];
		$import_context = [];
		$types          = self::order_import_types( $types );

		$code_check = self::preflight_code_sensitive_import( $package['zip'], $manifest, $types, $items );

		if ( is_wp_error( $code_check ) ) {
			self::cleanup_request_transfer_package( $package );
			wp_send_json_error( [ 'message' => $code_check->get_error_message() ] );
		}

		foreach ( $types as $type ) {
			$result = self::import_type_from_zip( $package['zip'], $manifest, $type, $items[ $type ] ?? [], $conflict_mode, $decisions[ $type ] ?? [], $import_images, $import_context );

			if ( is_wp_error( $result ) ) {
				self::cleanup_request_transfer_package( $package );
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			$results[ $type ] = $result;
		}

		self::cleanup_request_transfer_package( $package );

		$refresh = self::get_refresh_payload( $types );

		wp_send_json_success(
			[
				'results' => $results,
				'refresh' => $refresh,
			]
		);
	}

	/**
	 * Extract global class data from a Class Manager JSON export or unified transfer ZIP.
	 *
	 * @since 2.4
	 *
	 * @return array|\WP_Error
	 */
	public static function extract_classes_from_request() {
		return self::extract_transfer_type_from_request( 'classes' );
	}

	/**
	 * Extract one transfer type from a manager JSON export or unified transfer ZIP.
	 *
	 * @since 2.4
	 *
	 * @param string $type Transfer type.
	 * @return array|\WP_Error
	 */
	public static function extract_transfer_type_from_request( $type ) {
		$type = sanitize_text_field( $type );

		if ( ! array_key_exists( $type, self::get_type_labels() ) ) {
			return new \WP_Error( 'invalid_transfer_type', esc_html__( 'The import file format is not supported.', 'bricks' ) );
		}

		if ( ! self::user_can_access_type( $type, 'import' ) ) {
			return new \WP_Error( 'not_allowed', esc_html__( 'Not allowed', 'bricks' ) );
		}

		$package = self::open_request_transfer_package( $type );

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$manifest = self::read_manifest_from_zip( $package['zip'] );

		if ( is_wp_error( $manifest ) ) {
			self::cleanup_request_transfer_package( $package );
			return $manifest;
		}

		if ( empty( $manifest['types'][ $type ] ) ) {
			self::cleanup_request_transfer_package( $package );
			return new \WP_Error( 'missing_transfer_type', esc_html__( 'The import file does not contain the selected data type.', 'bricks' ) );
		}

		$data = self::extract_transfer_type_from_zip( $package['zip'], $manifest, $type );

		self::cleanup_request_transfer_package( $package );

		return $data;
	}

	/**
	 * Extract templates from a unified transfer ZIP.
	 *
	 * @since 2.4
	 *
	 * @param string $file_path ZIP file path.
	 * @return array|\WP_Error
	 */
	public static function extract_templates_from_zip_file( $file_path ) {
		$zip = self::open_zip_file( $file_path );

		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$manifest = self::read_manifest_from_zip( $zip );

		if ( is_wp_error( $manifest ) ) {
			$zip->close();
			return $manifest;
		}

		if ( empty( $manifest['types']['templates'] ) ) {
			$zip->close();
			return new \WP_Error( 'missing_templates', esc_html__( 'The import file does not contain templates.', 'bricks' ) );
		}

		$templates = self::extract_transfer_type_from_zip( $zip, $manifest, 'templates' );

		$zip->close();

		return $templates;
	}

	/**
	 * Maximum decoded ZIP payload accepted over JSON/MCP transport.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	public static function get_mcp_transfer_max_zip_bytes() {
		$upload_max = (int) wp_max_upload_size();

		return $upload_max > 0 ? min( $upload_max, self::MCP_MAX_ZIP_BYTES ) : self::MCP_MAX_ZIP_BYTES;
	}

	/**
	 * Transfer type identifiers supported by the unified engine.
	 *
	 * @since 2.4
	 *
	 * @return string[]
	 */
	public static function get_transfer_type_ids() {
		return array_keys( self::get_type_labels() );
	}

	/**
	 * List exportable transfer items for the current user.
	 *
	 * @since 2.4
	 *
	 * @param array $types Optional transfer type allow-list.
	 * @return array
	 */
	public static function list_export_items( $types = [] ) {
		$requested_types = self::sanitize_types( $types );
		$types           = ! empty( $requested_types ) ? $requested_types : self::get_transfer_type_ids();
		$output          = [];

		foreach ( $types as $type ) {
			if ( ! self::user_can_access_type( $type, 'export' ) ) {
				continue;
			}

			$type_data = self::build_export_type_data( $type );

			if ( ! empty( $type_data ) ) {
				$output[] = $type_data;
			}
		}

		return [
			'types' => $output,
		];
	}

	/**
	 * Create a base64 transfer package for MCP transport.
	 *
	 * @since 2.4
	 *
	 * @param array $types    Transfer type IDs.
	 * @param array $items    Selected item IDs keyed by transfer type.
	 * @param array $payloads Optional UI payloads. MCP callers usually omit this.
	 * @return array|\WP_Error
	 */
	public static function export_package( $types, $items = [], $payloads = [] ) {
		$package = self::create_export_package( $types, $items, $payloads );

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$zip_path = $package['path'];
		$bytes    = file_get_contents( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local ZIP generated by this method.

		wp_delete_file( $zip_path );

		if ( ! is_string( $bytes ) ) {
			return \Bricks\Abilities\Error::internal_error( 'export_transfer_package', 'Could not read generated ZIP archive.' );
		}

		$byte_count = strlen( $bytes );
		$max_bytes  = self::get_mcp_transfer_max_zip_bytes();

		if ( $byte_count > $max_bytes ) {
			return \Bricks\Abilities\Error::invalid_param(
				'types',
				sprintf( 'a transfer package no larger than %d bytes; export fewer types or items', $max_bytes ),
				[
					'bytes'    => $byte_count,
					'maxBytes' => $max_bytes,
				]
			);
		}

		return [
			'filename'  => $package['filename'],
			'zipBase64' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a ZIP download payload for MCP transport.
			'zipHash'   => hash( 'sha256', $bytes ),
			'zipBytes'  => $byte_count,
			'manifest'  => $package['manifest'],
		];
	}

	/**
	 * Inspect a decoded transfer package without writing data.
	 *
	 * @since 2.4
	 *
	 * @param string $bytes Decoded ZIP bytes.
	 * @return array|\WP_Error
	 */
	public static function inspect_package_bytes( $bytes ) {
		$handle = self::open_zip_bytes( $bytes );

		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$manifest = self::read_manifest_from_zip( $handle['zip'] );

		if ( is_wp_error( $manifest ) ) {
			self::cleanup_zip_handle( $handle );
			return $manifest;
		}

		$manifest = self::filter_manifest_by_permissions( $manifest, 'import' );
		$manifest = self::attach_conflicts_to_manifest( $handle['zip'], $manifest );

		self::cleanup_zip_handle( $handle );

		return [
			'manifest' => $manifest,
			'zipHash'  => hash( 'sha256', $bytes ),
			'zipBytes' => strlen( $bytes ),
		];
	}

	/**
	 * Import selected items from a decoded transfer package.
	 *
	 * @since 2.4
	 *
	 * @param string $bytes              Decoded ZIP bytes.
	 * @param array  $types              Transfer type IDs to import.
	 * @param array  $items              Selected item IDs keyed by type.
	 * @param string $conflict_mode      Default conflict behavior: skip|replace.
	 * @param array  $conflict_decisions Per-item conflict decisions keyed by type.
	 * @param bool   $import_images      Whether to download template images.
	 * @param bool   $include_refresh    Whether to include builder refresh payload.
	 * @return array|\WP_Error
	 */
	public static function import_package_bytes( $bytes, $types, $items, $conflict_mode = 'skip', $conflict_decisions = [], $import_images = false, $include_refresh = false ) {
		$types              = self::sanitize_types( $types );
		$items              = self::sanitize_items_map( $items );
		$conflict_mode      = self::sanitize_conflict_mode( $conflict_mode );
		$conflict_decisions = self::sanitize_conflict_decisions( $conflict_decisions );
		$import_images      = (bool) $import_images;

		if ( empty( $types ) ) {
			return \Bricks\Abilities\Error::missing_param( 'types' );
		}

		foreach ( $types as $type ) {
			if ( ! self::user_can_access_type( $type, 'import' ) ) {
				return \Bricks\Abilities\Error::forbidden_builder_permission( "import {$type}" );
			}

			if ( empty( $items[ $type ] ) ) {
				return \Bricks\Abilities\Error::missing_param( "items.{$type}" );
			}
		}

		if ( $import_images && in_array( 'templates', $types, true ) && ! current_user_can( 'upload_files' ) ) {
			return \Bricks\Abilities\Error::forbidden_builder_permission( 'upload_files' );
		}

		$handle = self::open_zip_bytes( $bytes );

		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$manifest = self::read_manifest_from_zip( $handle['zip'] );

		if ( is_wp_error( $manifest ) ) {
			self::cleanup_zip_handle( $handle );
			return $manifest;
		}

		foreach ( $types as $type ) {
			if ( empty( $manifest['types'][ $type ] ) ) {
				self::cleanup_zip_handle( $handle );

				return \Bricks\Abilities\Error::invalid_param( 'types', 'types present in the transfer manifest', $type );
			}
		}

		$results        = [];
		$import_context = [];
		$types          = self::order_import_types( $types );

		$code_check = self::preflight_code_sensitive_import( $handle['zip'], $manifest, $types, $items );

		if ( is_wp_error( $code_check ) ) {
			self::cleanup_zip_handle( $handle );
			return $code_check;
		}

		foreach ( $types as $type ) {
			$result = self::import_type_from_zip( $handle['zip'], $manifest, $type, $items[ $type ], $conflict_mode, $conflict_decisions[ $type ] ?? [], $import_images, $import_context );

			if ( is_wp_error( $result ) ) {
				self::cleanup_zip_handle( $handle );
				return $result;
			}

			$results[ $type ] = $result;
		}

		self::cleanup_zip_handle( $handle );

		$response = [
			'results' => $results,
		];

		if ( $include_refresh ) {
			$response['refresh'] = self::get_refresh_payload( $types );
		}

		return $response;
	}

	/**
	 * Shared ZIP creation for AJAX download and MCP export.
	 *
	 * @since 2.4
	 *
	 * @param array $types    Transfer type IDs.
	 * @param array $items    Selected item IDs keyed by type.
	 * @param array $payloads Optional UI payloads.
	 * @return array|\WP_Error
	 */
	private static function create_export_package( $types, $items = [], $payloads = [] ) {
		$types    = self::sanitize_types( $types );
		$items    = self::sanitize_items_map( $items );
		$payloads = is_array( $payloads ) ? $payloads : [];

		if ( empty( $types ) ) {
			return \Bricks\Abilities\Error::missing_param( 'types' );
		}

		foreach ( $types as $type ) {
			if ( ! self::user_can_access_type( $type, 'export' ) ) {
				return \Bricks\Abilities\Error::forbidden_builder_permission( "export {$type}" );
			}
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return \Bricks\Abilities\Error::internal_error( 'export_transfer_package', 'ZipArchive PHP extension is not available on this host.' );
		}

		$wp_upload_dir = wp_upload_dir();

		if ( ! empty( $wp_upload_dir['error'] ) ) {
			return \Bricks\Abilities\Error::internal_error( 'export_transfer_package', 'wp_upload_dir error: ' . $wp_upload_dir['error'] );
		}

		$temp_path = trailingslashit( $wp_upload_dir['basedir'] ) . BRICKS_TEMP_DIR;

		if ( ! wp_mkdir_p( $temp_path ) ) {
			return \Bricks\Abilities\Error::internal_error( 'export_transfer_package', 'Could not create the Bricks temporary export directory.' );
		}

		$zip_filename = 'bricks-global-data-' . date( 'Y-m-d' ) . '.zip';
		$zip_path     = trailingslashit( $temp_path ) . wp_unique_filename( $temp_path, $zip_filename );
		$zip          = new \ZipArchive();

		if ( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			return \Bricks\Abilities\Error::internal_error( 'export_transfer_package', 'Unable to create ZIP file.' );
		}

		$manifest = [
			'schema'        => self::MANIFEST_SCHEMA,
			'version'       => self::MANIFEST_VERSION,
			'createdAt'     => gmdate( 'c' ),
			'site'          => [
				'name'          => get_bloginfo( 'name' ),
				'homeUrl'       => home_url(),
				'bricksVersion' => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
			],
			'selectedTypes' => $types,
			'types'         => [],
		];

		foreach ( $types as $type ) {
			$manifest_type = self::export_type_to_zip( $zip, $type, $items[ $type ] ?? [], $payloads[ $type ] ?? null );

			if ( ! empty( $manifest_type ) ) {
				$manifest['types'][ $type ] = $manifest_type;
			}
		}

		self::add_dependency_types_to_manifest( $zip, $manifest );

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->close();

		clearstatcache( true, $zip_path );

		return [
			'filename' => basename( $zip_path ),
			'path'     => $zip_path,
			'manifest' => $manifest,
			'bytes'    => filesize( $zip_path ),
		];
	}

	/**
	 * Open decoded ZIP bytes from a temporary file.
	 *
	 * @since 2.4
	 *
	 * @param string $bytes Decoded ZIP bytes.
	 * @return array|\WP_Error
	 */
	private static function open_zip_bytes( $bytes ) {
		if ( ! is_string( $bytes ) || $bytes === '' ) {
			return new \WP_Error( 'invalid_zip', 'Unable to open ZIP file.' );
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'missing_ziparchive', 'ZipArchive PHP extension is not available.' );
		}

		$wp_upload_dir = wp_upload_dir();

		if ( ! empty( $wp_upload_dir['error'] ) ) {
			return \Bricks\Abilities\Error::internal_error( 'transfer_package_zip', 'wp_upload_dir error: ' . $wp_upload_dir['error'] );
		}

		$temp_base = trailingslashit( $wp_upload_dir['basedir'] ) . BRICKS_TEMP_DIR;

		if ( ! wp_mkdir_p( $temp_base ) ) {
			return \Bricks\Abilities\Error::internal_error( 'transfer_package_zip', 'Could not create the Bricks temporary import directory.' );
		}

		$temp_path = trailingslashit( $temp_base ) . 'mcp-transfer-' . wp_generate_password( 8, false, false );

		if ( ! wp_mkdir_p( $temp_path ) ) {
			return \Bricks\Abilities\Error::internal_error( 'transfer_package_zip', 'Could not create a request-scoped import directory.' );
		}

		$zip_path = trailingslashit( $temp_path ) . 'package.zip';
		$written  = file_put_contents( $zip_path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Writes caller-provided ZIP bytes to a local temp file for ZipArchive.

		if ( $written === false ) {
			self::remove_empty_dir( $temp_path );

			return \Bricks\Abilities\Error::internal_error( 'transfer_package_zip', 'Could not write ZIP archive to a temp file.' );
		}

		$zip = new \ZipArchive();

		if ( $zip->open( $zip_path ) !== true ) {
			wp_delete_file( $zip_path );
			self::remove_empty_dir( $temp_path );

			return new \WP_Error( 'invalid_zip', 'Unable to open ZIP file.' );
		}

		return [
			'zip'  => $zip,
			'path' => $zip_path,
			'dir'  => $temp_path,
		];
	}

	/**
	 * Close and delete a temporary ZIP handle.
	 *
	 * @since 2.4
	 *
	 * @param array $handle ZIP handle from open_zip_bytes().
	 * @return void
	 */
	private static function cleanup_zip_handle( $handle ) {
		if ( isset( $handle['zip'] ) && $handle['zip'] instanceof \ZipArchive ) {
			$handle['zip']->close();
		}

		if ( ! empty( $handle['path'] ) ) {
			wp_delete_file( $handle['path'] );
		}

		if ( ! empty( $handle['dir'] ) ) {
			self::remove_empty_dir( $handle['dir'] );
		}
	}

	/**
	 * Remove an empty temporary directory.
	 *
	 * @since 2.4
	 *
	 * @param string $path Directory path.
	 * @return void
	 */
	private static function remove_empty_dir( $path ) {
		if ( is_string( $path ) && is_dir( $path ) ) {
			rmdir( $path );
		}
	}

	/**
	 * Build selector metadata for one transfer type without creating a ZIP.
	 *
	 * @since 2.4
	 *
	 * @param string $type Transfer type ID.
	 * @return array
	 */
	private static function build_export_type_data( $type ) {
		$labels = self::get_type_labels();
		$data   = [
			'id'         => $type,
			'group'      => self::get_type_group( $type ),
			'label'      => $labels[ $type ] ?? $type,
			'count'      => 0,
			'singleton'  => false,
			'items'      => [],
			'categories' => [],
		];

		switch ( $type ) {
			case 'color-palettes':
				$palettes = get_option( BRICKS_DB_COLOR_PALETTE, [] );

				foreach ( array_values( is_array( $palettes ) ? $palettes : [] ) as $palette_index => $palette ) {
					$category_id = self::get_color_palette_category_id( $palette, $palette_index );
					$colors      = is_array( $palette['colors'] ?? null ) ? array_values( $palette['colors'] ) : [];

					$data['categories'][] = [
						'id'   => $category_id,
						'name' => $palette['name'] ?? esc_html__( 'Color palette', 'bricks' ),
					];

					foreach ( $colors as $color_index => $color ) {
						$data['items'][] = [
							'id'       => self::get_color_transfer_item_id( $palette, $color, $palette_index, $color_index ),
							'label'    => self::get_color_transfer_label( $color ),
							'category' => $category_id,
							'meta'     => $color['light'] ?? ( $color['dark'] ?? '' ),
						];
					}
				}

				$data['count'] = count( is_array( $palettes ) ? $palettes : [] );
				break;

			case 'theme-styles':
				$styles = get_option( BRICKS_DB_THEME_STYLES, [] );

				foreach ( ( is_array( $styles ) ? $styles : [] ) as $style_id => $style ) {
					$data['items'][] = [
						'id'       => (string) $style_id,
						'label'    => $style['label'] ?? $style_id,
						'category' => '',
						'meta'     => '',
					];
				}
				break;

			case 'classes':
				$classes            = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
				$data['categories'] = get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );

				foreach ( ( is_array( $classes ) ? $classes : [] ) as $class ) {
					$data['items'][] = [
						'id'       => (string) ( $class['id'] ?? '' ),
						'label'    => $class['name'] ?? esc_html__( 'Class', 'bricks' ),
						'category' => $class['category'] ?? '',
						'meta'     => '',
					];
				}
				break;

			case 'variables':
				$variables          = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
				$data['categories'] = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );

				foreach ( ( is_array( $variables ) ? $variables : [] ) as $variable ) {
					$data['items'][] = [
						'id'       => (string) ( $variable['id'] ?? '' ),
						'label'    => $variable['name'] ?? esc_html_x( 'Variable', 'CSS variable', 'bricks' ),
						'category' => $variable['category'] ?? '',
						'meta'     => $variable['value'] ?? '',
					];
				}
				break;

			case 'custom-fonts':
				foreach ( self::get_custom_font_posts( 'publish' ) as $font_post ) {
					$font_faces      = get_post_meta( $font_post->ID, BRICKS_DB_CUSTOM_FONT_FACES, true );
					$data['items'][] = [
						'id'       => "custom_font_{$font_post->ID}",
						'label'    => html_entity_decode( get_the_title( $font_post ), ENT_QUOTES, 'UTF-8' ),
						'category' => '',
						'meta'     => is_array( $font_faces ) ? count( $font_faces ) : 0,
					];
				}
				break;

			case 'icon-manager':
				$icon_sets          = get_option( BRICKS_DB_ICON_SETS, [] );
				$custom_icons       = get_option( BRICKS_DB_CUSTOM_ICONS, [] );
				$disabled_icon_sets = get_option( BRICKS_DB_DISABLED_ICON_SETS, [] );
				$custom_icons       = is_array( $custom_icons ) ? $custom_icons : [];
				$disabled_icon_sets = is_array( $disabled_icon_sets ) ? $disabled_icon_sets : [];

				foreach ( ( is_array( $icon_sets ) ? $icon_sets : [] ) as $icon_set ) {
					$icon_count = count(
						array_filter(
							$custom_icons,
							function( $icon ) use ( $icon_set ) {
								return (string) ( $icon['setId'] ?? '' ) === (string) ( $icon_set['id'] ?? '' );
							}
						)
					);

					$data['items'][] = [
						'id'       => (string) ( $icon_set['id'] ?? '' ),
						'label'    => $icon_set['name'] ?? esc_html__( 'Icon set', 'bricks' ),
						'category' => 'custom-icon-sets',
						'meta'     => $icon_count,
					];
				}

				if ( count( $disabled_icon_sets ) ) {
					$data['items'][] = [
						'id'       => 'disabled-icon-sets',
						'label'    => esc_html__( 'Disabled icon sets', 'bricks' ),
						'category' => 'settings',
						'meta'     => count( $disabled_icon_sets ),
					];
				}

				$data['categories'] = self::get_icon_manager_categories();
				break;

			case 'breakpoints':
				$breakpoints       = Breakpoints::get_breakpoints();
				$data['singleton'] = true;
				$data['items'][]   = [
					'id'       => 'all',
					'label'    => esc_html__( 'Breakpoints', 'bricks' ),
					'category' => '',
					'meta'     => self::get_breakpoints_transfer_meta( $breakpoints ),
				];
				break;

			case 'global-queries':
				$queries            = Database::get_global_queries();
				$data['categories'] = get_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, [] );

				foreach ( ( is_array( $queries ) ? $queries : [] ) as $query ) {
					$data['items'][] = [
						'id'       => (string) ( $query['id'] ?? '' ),
						'label'    => $query['name'] ?? esc_html__( 'Query', 'bricks' ),
						'category' => $query['category'] ?? '',
						'meta'     => '',
					];
				}
				break;

			case 'components':
				$components = get_option( BRICKS_DB_COMPONENTS, [] );

				foreach ( ( is_array( $components ) ? $components : [] ) as $component ) {
					$data['items'][] = [
						'id'       => (string) ( $component['id'] ?? '' ),
						'label'    => self::get_component_label( $component ),
						'category' => $component['category'] ?? 'components',
						'meta'     => '',
					];
				}

				$data['categories'] = self::build_categories_from_items( $data['items'] );
				break;

			case 'templates':
				foreach ( self::get_exportable_template_ids() as $template_id ) {
					$template_type   = get_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, true );
					$data['items'][] = [
						'id'       => (string) $template_id,
						'label'    => get_the_title( $template_id ),
						'category' => $template_type,
						'meta'     => '',
					];
				}

				$data['categories'] = self::build_categories_from_items( $data['items'] );
				break;

			case 'settings':
				foreach ( self::get_settings_transfer_tabs() as $tab_id => $tab ) {
					$data['items'][] = [
						'id'        => $tab_id,
						'label'     => $tab['label'] ?? $tab_id,
						'category'  => '',
						'meta'      => '',
						'sensitive' => ! empty( $tab['sensitive'] ),
					];
				}
				break;

			case 'builder-interface':
				foreach ( Builder::get_builder_ui_profiles() as $profile ) {
					$data['items'][] = [
						'id'       => (string) ( $profile['id'] ?? '' ),
						'label'    => $profile['label'] ?? ( $profile['id'] ?? esc_html__( 'Builder interface profile', 'bricks' ) ),
						'category' => '',
						'meta'     => '',
					];
				}
				break;

			case 'custom-capabilities':
				foreach ( self::get_custom_capabilities_for_transfer() as $capability ) {
					$data['items'][] = [
						'id'       => (string) ( $capability['id'] ?? '' ),
						'label'    => $capability['label'] ?? ( $capability['id'] ?? esc_html__( 'Capability', 'bricks' ) ),
						'category' => '',
						'meta'     => count( $capability['permissions'] ?? [] ),
					];
				}
				break;
		}

		$data['count'] = $data['count'] ? $data['count'] : count( $data['items'] );

		return $data;
	}

	private static function export_type_to_zip( $zip, $type, $selected_item_ids, $payload ) {
		switch ( $type ) {
			case 'color-palettes':
				$palettes   = is_array( $payload ) ? $payload : get_option( BRICKS_DB_COLOR_PALETTE, [] );
				$items      = [];
				$categories = [];

				foreach ( array_values( $palettes ) as $palette_index => $palette ) {
					$palette_colors         = is_array( $palette['colors'] ?? null ) ? array_values( $palette['colors'] ) : [];
					$selected_colors        = [];
					$selected_palette_items = [];
					$category_id            = self::get_color_palette_category_id( $palette, $palette_index );

					foreach ( $palette_colors as $color_index => $color ) {
						$item_id = self::get_color_transfer_item_id( $palette, $color, $palette_index, $color_index );

						if ( ! empty( $selected_item_ids ) && ! in_array( $item_id, $selected_item_ids, true ) ) {
							continue;
						}

						$export_color_index       = count( $selected_colors );
						$selected_colors[]        = $color;
						$selected_palette_items[] = [
							'id'         => $item_id,
							'label'      => self::get_color_transfer_label( $color ),
							'path'       => '',
							'category'   => $category_id,
							'meta'       => $color['light'] ?? ( $color['dark'] ?? '' ),
							'colorId'    => (string) ( $color['id'] ?? '' ),
							'colorIndex' => $export_color_index,
						];
					}

					if ( empty( $selected_colors ) ) {
						continue;
					}

					$export_palette           = $palette;
					$export_palette['colors'] = array_values( $selected_colors );

					$file_name = self::sanitize_file_name( $palette['name'] ?? 'palette' ) . '-' . ( $palette['id'] ?? Helpers::generate_random_id( false ) ) . '.json';
					$path      = 'styles/color-palettes/' . $file_name;
					$zip->addFromString( $path, wp_json_encode( $export_palette, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

					$categories[] = [
						'id'   => $category_id,
						'name' => $palette['name'] ?? esc_html__( 'Color palette', 'bricks' ),
					];

					foreach ( $selected_palette_items as $selected_palette_item ) {
						$selected_palette_item['path'] = $path;
						$items[]                       = $selected_palette_item;
					}
				}

				return self::manifest_type_data( $type, false, $categories, $items, count( $categories ) );

			case 'theme-styles':
				$styles = is_array( $payload ) ? $payload : get_option( BRICKS_DB_THEME_STYLES, [] );
				$items  = [];

				foreach ( $styles as $style_id => $style ) {
					if ( ! empty( $selected_item_ids ) && ! in_array( (string) $style_id, $selected_item_ids, true ) ) {
						continue;
					}

					$export_style = [
						'id'       => $style_id,
						'label'    => $style['label'] ?? $style_id,
						'settings' => $style['settings'] ?? [],
					];

					$file_name = self::sanitize_file_name( $export_style['label'] ) . '-' . $style_id . '.json';
					$path      = 'styles/theme-styles/' . $file_name;
					$zip->addFromString( $path, wp_json_encode( $export_style, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

					$items[] = [
						'id'       => $style_id,
						'label'    => $export_style['label'],
						'path'     => $path,
						'category' => '',
					];
				}

				return self::manifest_type_data( $type, false, [], $items );

			case 'classes':
				$data       = is_array( $payload ) ? $payload : [];
				$classes    = is_array( $data['items'] ?? null ) ? $data['items'] : get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
				$categories = is_array( $data['categories'] ?? null ) ? $data['categories'] : get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );
				$classes    = self::filter_list_by_ids( $classes, $selected_item_ids );
				$categories = self::filter_categories_for_items( $classes, $categories );

				return self::add_classes_to_zip( $zip, $classes, $categories );

			case 'variables':
				$data       = is_array( $payload ) ? $payload : [];
				$variables  = is_array( $data['items'] ?? null ) ? $data['items'] : get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
				$categories = is_array( $data['categories'] ?? null ) ? $data['categories'] : get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
				$variables  = self::filter_list_by_ids( $variables, $selected_item_ids );
				$categories = self::filter_categories_for_items( $variables, $categories );

				$zip->addFromString( 'styles/variables/variables.json', wp_json_encode( array_values( $variables ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				$zip->addFromString( 'styles/variables/categories.json', wp_json_encode( array_values( $categories ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

				$items = array_map(
					function( $variable ) {
						return [
							'id'       => $variable['id'] ?? '',
							'label'    => $variable['name'] ?? esc_html_x( 'Variable', 'CSS variable', 'bricks' ),
							'path'     => 'styles/variables/variables.json',
							'category' => $variable['category'] ?? '',
						];
					},
					array_values( $variables )
				);

				return self::manifest_type_data( $type, false, array_values( $categories ), $items );

			case 'custom-fonts':
				return self::export_custom_fonts_to_zip( $zip, $selected_item_ids );

			case 'icon-manager':
				$data = is_array( $payload ) ? $payload : [];

				return self::export_icon_manager_to_zip(
					$zip,
					[
						'sets'             => is_array( $data['sets'] ?? null ) ? $data['sets'] : get_option( BRICKS_DB_ICON_SETS, [] ),
						'icons'            => is_array( $data['icons'] ?? null ) ? $data['icons'] : get_option( BRICKS_DB_CUSTOM_ICONS, [] ),
						'disabledIconSets' => is_array( $data['disabledIconSets'] ?? null ) ? $data['disabledIconSets'] : get_option( BRICKS_DB_DISABLED_ICON_SETS, [] ),
					],
					$selected_item_ids
				);

			case 'breakpoints':
				$breakpoints = is_array( $payload ) ? $payload : Breakpoints::get_breakpoints();
				$path        = 'structure/breakpoints/breakpoints.json';
				$breakpoints = self::sanitize_breakpoints_for_import( $breakpoints );

				if ( is_wp_error( $breakpoints ) ) {
					$breakpoints = self::sanitize_breakpoints_for_import( Breakpoints::get_breakpoints() );
				}

				$zip->addFromString( $path, wp_json_encode( array_values( $breakpoints ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				$item = [
					'id'       => 'all',
					'label'    => esc_html__( 'Breakpoints', 'bricks' ),
					'path'     => $path,
					'category' => '',
					'meta'     => self::get_breakpoints_transfer_meta( $breakpoints ),
				];

				self::attach_breakpoints_item_preview( $item, $breakpoints );

				return self::manifest_type_data(
					$type,
					true,
					[],
					[ $item ]
				);

			case 'global-queries':
				$data       = is_array( $payload ) ? $payload : [];
				$queries    = is_array( $data['items'] ?? null ) ? $data['items'] : Database::get_global_queries();
				$categories = is_array( $data['categories'] ?? null ) ? $data['categories'] : get_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, [] );
				$queries    = self::filter_list_by_ids( $queries, $selected_item_ids );
				$categories = self::filter_categories_for_items( $queries, $categories );

				$zip->addFromString( 'structure/global-queries/queries.json', wp_json_encode( array_values( $queries ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				$zip->addFromString( 'structure/global-queries/categories.json', wp_json_encode( array_values( $categories ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

				$items = array_map(
					function( $query ) {
						return [
							'id'       => $query['id'] ?? '',
							'label'    => $query['name'] ?? esc_html__( 'Query', 'bricks' ),
							'path'     => 'structure/global-queries/queries.json',
							'category' => $query['category'] ?? '',
						];
					},
					array_values( $queries )
				);

				return self::manifest_type_data( $type, false, array_values( $categories ), $items );

			case 'components':
				$components = is_array( $payload ) ? $payload : get_option( BRICKS_DB_COMPONENTS, [] );
				$components = self::filter_list_by_ids( $components, $selected_item_ids );
				$items      = [];

				foreach ( $components as $component ) {
					$component_id           = $component['id'] ?? Helpers::generate_random_id( false );
					$component_dependencies = self::collect_components_from_elements( $component['elements'] ?? [] );
					$components_to_export   = array_merge( [ $component ], $component_dependencies );
					$components_to_export   = self::redact_code_sensitive_components( $components_to_export );
					$component              = $components_to_export[0] ?? $component;
					$component_ui           = [
						'components' => self::unique_components_by_id( $components_to_export ),
					];

					$dependencies = self::build_transfer_dependencies_for_elements( $component['elements'] ?? [], $component_ui['components'] );

					if ( count( $dependencies ) ) {
						$component_ui['dependencies'] = $dependencies;
					}

					$file_name = self::sanitize_file_name( self::get_component_label( $component ) ) . '-' . $component_id . '.json';
					$path      = 'structure/components/' . $file_name;
					$zip->addFromString( $path, wp_json_encode( $component_ui, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

					$manifest_item = [
						'id'       => $component_id,
						'label'    => self::get_component_label( $component ),
						'path'     => $path,
						'category' => $component['category'] ?? 'components',
					];

					self::attach_dependency_summary_to_manifest_item( $manifest_item, $dependencies );

					$items[] = $manifest_item;
				}

				return self::manifest_type_data( $type, false, self::build_categories_from_items( $items ), $items );

			case 'templates':
				$items             = [];
				$selected_item_ids = ! empty( $selected_item_ids ) ? $selected_item_ids : self::get_exportable_template_ids();

				foreach ( $selected_item_ids as $template_id ) {
					if ( ! current_user_can( 'edit_post', (int) $template_id ) ) {
						continue;
					}

					$template_export = self::build_template_export( intval( $template_id ) );

					if ( empty( $template_export['name'] ) || ! isset( $template_export['content'] ) ) {
						continue;
					}

					$path = 'structure/templates/' . $template_export['name'];
					$zip->addFromString( $path, $template_export['content'] );

					$template_data = json_decode( $template_export['content'], true );

					$manifest_item = [
						'id'       => (string) $template_id,
						'label'    => $template_data['title'] ?? get_the_title( $template_id ),
						'path'     => $path,
						'category' => $template_data['templateType'] ?? '',
					];

					self::attach_dependency_summary_to_manifest_item( $manifest_item, $template_data );

					$items[] = $manifest_item;
				}

				$categories = self::build_categories_from_items( $items );

				return self::manifest_type_data( $type, false, $categories, $items );

			case 'settings':
				$settings      = is_array( $payload ) ? $payload : get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
				$path          = 'settings/wp-dashboard/settings.json';
				$settings_data = self::build_settings_transfer_data( $settings, $selected_item_ids );
				$tabs          = self::get_settings_transfer_tabs();
				$tab_ids       = array_keys( $settings_data['tabs'] ?? [] );

				$zip->addFromString( $path, wp_json_encode( $settings_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

				return self::manifest_type_data(
					$type,
					false,
					[],
					array_map(
						function( $tab_id ) use ( $tabs, $path ) {
							return [
								'id'       => $tab_id,
								'label'    => $tabs[ $tab_id ]['label'] ?? $tab_id,
								'path'     => $path,
								'category' => '',
							];
						},
						$tab_ids
					)
				);

			case 'builder-interface':
				$profiles = self::normalize_builder_interface_profiles_transfer_data( $payload );
				$profiles = $profiles ?? Builder::get_builder_ui_profiles();
				$profiles = self::filter_builder_interface_profiles_by_ids( $profiles, $selected_item_ids );
				$path     = 'settings/builder-interface/profiles.json';

				$zip->addFromString(
					$path,
					wp_json_encode( [ 'builderInterfaceProfiles' => $profiles ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				);

				$items = array_map(
					function( $profile ) use ( $path ) {
						return [
							'id'       => (string) ( $profile['id'] ?? '' ),
							'label'    => $profile['label'] ?? ( $profile['id'] ?? esc_html__( 'Builder interface profile', 'bricks' ) ),
							'path'     => $path,
							'category' => '',
						];
					},
					array_values( $profiles )
				);

				return self::manifest_type_data( $type, false, [], $items );

			case 'custom-capabilities':
				$capabilities = self::get_custom_capabilities_for_transfer();
				$capabilities = self::filter_capabilities_by_ids( $capabilities, $selected_item_ids );
				$path         = 'settings/custom-capabilities/capabilities.json';

				$zip->addFromString( $path, wp_json_encode( array_values( $capabilities ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

				$items = array_map(
					function( $capability ) use ( $path ) {
						return [
							'id'       => $capability['id'] ?? '',
							'label'    => $capability['label'] ?? ( $capability['id'] ?? esc_html__( 'Capability', 'bricks' ) ),
							'path'     => $path,
							'category' => '',
							'meta'     => count( $capability['permissions'] ?? [] ),
						];
					},
					array_values( $capabilities )
				);

				return self::manifest_type_data( $type, false, [], $items );
		}

		return [];
	}

	private static function import_type_from_zip( $zip, $manifest, $type, $selected_item_ids, $conflict_mode, $conflict_decisions, $import_images, &$import_context ) {
		$type_manifest = $manifest['types'][ $type ] ?? null;

		if ( empty( $type_manifest ) ) {
			return [
				'imported' => 0,
				'skipped'  => 0,
				'items'    => [],
			];
		}

		$result = [
			'imported' => 0,
			'skipped'  => 0,
			'items'    => [],
		];

		switch ( $type ) {
			case 'color-palettes':
				$local_palettes = get_option( BRICKS_DB_COLOR_PALETTE, [] );
				$items_by_path  = [];

				foreach ( $type_manifest['items'] as $item ) {
					if ( ! self::is_item_selected( $item['id'], $selected_item_ids, $type_manifest['singleton'] ?? false ) ) {
						continue;
					}

					if ( empty( $item['path'] ) ) {
						continue;
					}

					$items_by_path[ $item['path'] ][] = $item;
				}

				foreach ( $items_by_path as $path => $path_items ) {
					$palette = self::read_json_from_zip( $zip, $path );

					if ( ! is_array( $palette ) ) {
						continue;
					}

					$palette_name   = $palette['name'] ?? esc_html__( 'Color palette', 'bricks' );
					$palette_colors = is_array( $palette['colors'] ?? null ) ? array_values( $palette['colors'] ) : [];
					$matched_colors = [];

					foreach ( $path_items as $path_item ) {
						foreach ( $palette_colors as $color_index => $color ) {
							if ( self::matches_color_manifest_item( $path_item, $color, $color_index ) ) {
								$matched_colors[] = [
									'item'  => $path_item,
									'color' => $color,
								];
								break;
							}
						}
					}

					if ( empty( $matched_colors ) ) {
						continue;
					}

					$target_index    = self::find_palette_index_by_name( $local_palettes, $palette_name );
					$created_palette = false;

					if ( $target_index === null ) {
						$palette_id = $palette['id'] ?? Helpers::generate_random_id( false );

						if ( self::id_exists_in_list( $local_palettes, $palette_id ) ) {
							$palette_id = Helpers::generate_random_id( false );
						}

						$local_palettes[] = [
							'id'     => $palette_id,
							'name'   => $palette_name,
							'colors' => [],
						];

						$target_index    = count( $local_palettes ) - 1;
						$created_palette = true;
					}

					if ( ! is_array( $local_palettes[ $target_index ]['colors'] ?? null ) ) {
						$local_palettes[ $target_index ]['colors'] = [];
					}

					foreach ( $matched_colors as $matched_color ) {
						$color                   = $matched_color['color'];
						$item                    = $matched_color['item'];
						$raw                     = strtolower( (string) ( $color['raw'] ?? '' ) );
						$item_conflict_mode      = self::get_item_conflict_mode( $item['id'] ?? '', $conflict_mode, $conflict_decisions );
						$existing_color_location = $raw ? self::find_color_location_by_raw( $local_palettes, $raw ) : null;

						if ( $existing_color_location ) {
							if ( $item_conflict_mode !== 'replace' ) {
								$result['skipped']++;
								$result['items'][] = self::result_item( $item['label'], 'skipped' );
								continue;
							}

							$existing_color = $local_palettes[ $existing_color_location['paletteIndex'] ]['colors'][ $existing_color_location['colorIndex'] ] ?? [];

							if ( ! empty( $existing_color['id'] ) ) {
								$color['id'] = $existing_color['id'];
							}

							$local_palettes[ $existing_color_location['paletteIndex'] ]['colors'][ $existing_color_location['colorIndex'] ] = $color;

							$result['imported']++;
							$result['items'][] = self::result_item( $item['label'], 'replaced' );
							continue;
						}

						if ( $raw && self::color_raw_exists_in_palettes( $local_palettes, $raw ) ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $item['label'], 'skipped' );
							continue;
						}

						$color_id = $color['id'] ?? Helpers::generate_random_id( false );

						if ( self::color_id_exists_in_palettes( $local_palettes, $color_id ) ) {
							$color_id = Helpers::generate_random_id( false );
						}

						$color['id'] = $color_id;

						$local_palettes[ $target_index ]['colors'][] = $color;

						$result['imported']++;
						$result['items'][] = self::result_item( $item['label'], 'imported' );
					}

					if ( $created_palette && empty( $local_palettes[ $target_index ]['colors'] ) ) {
						array_splice( $local_palettes, $target_index, 1 );
					}
				}

				update_option( BRICKS_DB_COLOR_PALETTE, array_values( $local_palettes ) );
				Ajax::generate_style_manager_css_file();
				break;

			case 'theme-styles':
				$local_styles = get_option( BRICKS_DB_THEME_STYLES, [] );

				foreach ( $type_manifest['items'] as $item ) {
					if ( ! self::is_item_selected( $item['id'], $selected_item_ids, false ) ) {
						continue;
					}

					$style = self::read_json_from_zip( $zip, $item['path'] );

					if ( ! is_array( $style ) ) {
						continue;
					}

					$style['settings'] = self::remap_import_ids_in_data(
						$style['settings'] ?? [],
						$import_context['id_maps']['custom-fonts'] ?? []
					);

					$existing_style_key = self::find_theme_style_key(
						$local_styles,
						$style['label'] ?? '',
						$style['id'] ?? ''
					);

					if ( $existing_style_key !== null ) {
						$item_conflict_mode = self::get_item_conflict_mode( $item['id'] ?? '', $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $item['label'], 'skipped' );
							continue;
						}

						$local_styles[ $existing_style_key ] = [
							'label'    => $style['label'] ?? $existing_style_key,
							'settings' => $style['settings'] ?? [],
						];

						$result['imported']++;
						$result['items'][] = self::result_item( $item['label'], 'replaced' );
						continue;
					}

					$style_id = $style['id'] ?? Helpers::generate_random_id( false );

					if ( array_key_exists( $style_id, $local_styles ) ) {
						$style_id = Helpers::generate_random_id( false );
					}

					$local_styles[ $style_id ] = [
						'label'    => $style['label'] ?? $style_id,
						'settings' => $style['settings'] ?? [],
					];

					$result['imported']++;
					$result['items'][] = self::result_item( $item['label'], 'imported' );
				}

				update_option( BRICKS_DB_THEME_STYLES, $local_styles );
				break;

			case 'classes':
				$classes    = self::read_json_from_zip( $zip, 'styles/classes/classes.json' );
				$categories = self::read_json_from_zip( $zip, 'styles/classes/categories.json' );
				$local      = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
				$local_cats = get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );
				$remap      = self::merge_categories( $local_cats, is_array( $categories ) ? $categories : [] );

				foreach ( (array) $classes as $class ) {
					if ( ! self::is_item_selected( $class['id'] ?? '', $selected_item_ids, false ) ) {
						continue;
					}

					$class = self::remap_import_ids_in_data(
						$class,
						$import_context['id_maps']['custom-fonts'] ?? []
					);

					if ( ! empty( $class['category'] ) && isset( $remap[ $class['category'] ] ) ) {
						$class['category'] = $remap[ $class['category'] ];
					}

					unset( $class['_categoryData'] );

					$existing_class_index = self::find_list_index_by_text_field( $local, 'name', $class['name'] ?? '' );

					if ( $existing_class_index === null ) {
						$existing_class_index = self::find_list_index_by_id( $local, $class['id'] ?? '' );
					}

					if ( $existing_class_index !== null ) {
						$item_conflict_mode = self::get_item_conflict_mode( $class['id'] ?? '', $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $class['name'] ?? esc_html__( 'Class', 'bricks' ), 'skipped' );
							continue;
						}

						$existing_class_id = $local[ $existing_class_index ]['id'] ?? '';

						if ( $existing_class_id ) {
							$class['id'] = $existing_class_id;
						}

						$local[ $existing_class_index ] = $class;

						$result['imported']++;
						$result['items'][] = self::result_item( $class['name'] ?? esc_html__( 'Class', 'bricks' ), 'replaced' );
						continue;
					}

					if ( self::id_exists_in_list( $local, $class['id'] ?? '' ) ) {
						$class['id'] = Helpers::generate_random_id( false );
					}

					$local[] = $class;

					$result['imported']++;
					$result['items'][] = self::result_item( $class['name'] ?? esc_html__( 'Class', 'bricks' ), 'imported' );
				}

				Helpers::save_global_classes_in_db( array_values( $local ) );
				update_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, array_values( $local_cats ), false );
				break;

			case 'variables':
				$variables  = self::read_json_from_zip( $zip, 'styles/variables/variables.json' );
				$categories = self::read_json_from_zip( $zip, 'styles/variables/categories.json' );
				$local      = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
				$local_cats = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
				$remap      = self::merge_categories( $local_cats, is_array( $categories ) ? $categories : [] );

				foreach ( (array) $variables as $variable ) {
					if ( ! self::is_item_selected( $variable['id'] ?? '', $selected_item_ids, false ) ) {
						continue;
					}

					if ( ! empty( $variable['category'] ) && isset( $remap[ $variable['category'] ] ) ) {
						$variable['category'] = $remap[ $variable['category'] ];
					}

					$existing_variable_index = self::find_list_index_by_text_field( $local, 'name', $variable['name'] ?? '' );

					if ( $existing_variable_index === null ) {
						$existing_variable_index = self::find_list_index_by_id( $local, $variable['id'] ?? '' );
					}

					if ( $existing_variable_index !== null ) {
						$item_conflict_mode = self::get_item_conflict_mode( $variable['id'] ?? '', $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $variable['name'] ?? esc_html_x( 'Variable', 'CSS variable', 'bricks' ), 'skipped' );
							continue;
						}

						$existing_variable_id = $local[ $existing_variable_index ]['id'] ?? '';

						if ( $existing_variable_id ) {
							$variable['id'] = $existing_variable_id;
						}

						$local[ $existing_variable_index ] = $variable;

						$result['imported']++;
						$result['items'][] = self::result_item( $variable['name'] ?? esc_html_x( 'Variable', 'CSS variable', 'bricks' ), 'replaced' );
						continue;
					}

					if ( self::id_exists_in_list( $local, $variable['id'] ?? '' ) ) {
						$variable['id'] = Helpers::generate_random_id( false );
					}

					$local[] = $variable;

					$result['imported']++;
					$result['items'][] = self::result_item( $variable['name'] ?? esc_html_x( 'Variable', 'CSS variable', 'bricks' ), 'imported' );
				}

				Helpers::save_global_variables_in_db( array_values( $local ) );
				update_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, array_values( $local_cats ), false );
				Ajax::generate_style_manager_css_file();
				break;

			case 'custom-fonts':
				$result = self::import_custom_fonts_from_zip( $zip, $type_manifest, $selected_item_ids, $conflict_mode, $conflict_decisions, $import_context );
				break;

			case 'icon-manager':
				$result = self::import_icon_manager_from_zip( $zip, $type_manifest, $selected_item_ids, $conflict_mode, $conflict_decisions );
				break;

			case 'breakpoints':
				if ( self::is_singleton_selected( $selected_item_ids ) ) {
					$breakpoints = self::read_json_from_zip( $zip, 'structure/breakpoints/breakpoints.json' );

					if ( is_array( $breakpoints ) ) {
						$breakpoints = self::sanitize_breakpoints_for_import( $breakpoints );

						if ( is_wp_error( $breakpoints ) ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( esc_html__( 'Breakpoints', 'bricks' ), 'skipped' );
							break;
						}

						$local_breakpoints = self::sanitize_breakpoints_for_import( Breakpoints::get_breakpoints() );

						$item_conflict_mode = self::get_item_conflict_mode( $type_manifest['items'][0]['id'] ?? 'breakpoints', $conflict_mode, $conflict_decisions );

						if ( self::normalize_for_compare( $local_breakpoints ) !== self::normalize_for_compare( $breakpoints ) && $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( esc_html__( 'Breakpoints', 'bricks' ), 'skipped' );
							break;
						}

						if ( self::breakpoints_match_default( $breakpoints ) ) {
							delete_option( BRICKS_DB_BREAKPOINTS );
						} else {
							update_option( BRICKS_DB_BREAKPOINTS, array_values( $breakpoints ) );
							self::set_custom_breakpoints_enabled( true );
						}

						Breakpoints::init_breakpoints();

						if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
							$css_files_list = Assets_Files::get_css_files_list( true );

							foreach ( $css_files_list as $css_file_index => $css_file ) {
								Assets_Files::regenerate_css_file( $css_file, $css_file_index, true );
							}
						}

						$result['imported'] = 1;
						$result['items'][]  = self::result_item( esc_html__( 'Breakpoints', 'bricks' ), 'imported' );
					}
				}
				break;

			case 'global-queries':
				$queries    = self::read_json_from_zip( $zip, 'structure/global-queries/queries.json' );
				$categories = self::read_json_from_zip( $zip, 'structure/global-queries/categories.json' );
				$local      = Database::get_global_queries();
				$local_cats = get_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, [] );
				$remap      = self::merge_categories( $local_cats, is_array( $categories ) ? $categories : [] );

				foreach ( (array) $queries as $query ) {
					if ( ! self::is_item_selected( $query['id'] ?? '', $selected_item_ids, false ) ) {
						continue;
					}

					if ( self::global_query_contains_code_sensitive_payload( $query ) && ! \Bricks\Abilities\Elements::can_author_php() ) {
						return \Bricks\Abilities\Error::code_sensitive_write_forbidden();
					}
					if ( ! empty( $query['category'] ) && isset( $remap[ $query['category'] ] ) ) {
						$query['category'] = $remap[ $query['category'] ];
					}

					$query = self::prepare_global_query_for_import( $query );
					if ( is_wp_error( $query ) ) {
						return $query;
					}

					$existing_query_index = self::find_list_index_by_id( $local, $query['id'] ?? '' );

					if ( $existing_query_index !== null ) {
						$item_conflict_mode = self::get_item_conflict_mode( $query['id'] ?? '', $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $query['name'] ?? esc_html__( 'Query', 'bricks' ), 'skipped' );
							continue;
						}

						$local[ $existing_query_index ] = $query;

						$result['imported']++;
						$result['items'][] = self::result_item( $query['name'] ?? esc_html__( 'Query', 'bricks' ), 'replaced' );
						continue;
					}

					$local[] = $query;

					$result['imported']++;
					$result['items'][] = self::result_item( $query['name'] ?? esc_html__( 'Query', 'bricks' ), 'imported' );
				}

				Database::update_global_queries( array_values( $local ) );
				update_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, array_values( $local_cats ) );
				break;

			case 'components':
				$local = get_option( BRICKS_DB_COMPONENTS, [] );

				foreach ( $type_manifest['items'] as $item ) {
					if ( ! self::is_item_selected( $item['id'], $selected_item_ids, false ) ) {
						continue;
					}

					$data = self::read_json_from_zip( $zip, $item['path'] );
					$data = self::remap_import_ids_in_data(
						$data,
						$import_context['id_maps']['custom-fonts'] ?? []
					);

					$components = is_array( $data['components'] ?? null ) ? $data['components'] : [];
					$components = Components::upgrade_components( $components, true );
					$component  = $components[0] ?? null;

					if ( empty( $component ) ) {
						continue;
					}

					$code_check = self::check_components_code_permissions( $components );

					if ( is_wp_error( $code_check ) ) {
						return $code_check;
					}

					$existing_component_index = self::find_list_index_by_id( $local, $component['id'] ?? '' );

					if ( $existing_component_index !== null ) {
						$item_conflict_mode = self::get_item_conflict_mode( $component['id'] ?? '', $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( self::get_component_label( $component ), 'skipped' );
							continue;
						}

						self::remap_global_class_ids_in_components( $components, self::map_transfer_dependency_class_ids( $data ) );
						$components = self::prepare_components_code_for_import( $local, $components );
						if ( is_wp_error( $components ) ) {
							return $components;
						}

						$component = $components[0] ?? $component;
						$local     = self::upsert_components( $local, $components, $conflict_mode );

						$existing_component_index           = self::find_list_index_by_id( $local, $component['id'] ?? '' );
						$local[ $existing_component_index ] = $component;

						$result['imported']++;
						$result['items'][] = self::result_item( self::get_component_label( $component ), 'replaced' );
						continue;
					}

					self::remap_global_class_ids_in_components( $components, self::map_transfer_dependency_class_ids( $data ) );
					$components = self::prepare_components_code_for_import( $local, $components );
					if ( is_wp_error( $components ) ) {
						return $components;
					}

					$component = $components[0] ?? $component;
					$local     = self::upsert_components( $local, $components, $conflict_mode );

					$result['imported']++;
					$result['items'][] = self::result_item( self::get_component_label( $component ), 'imported' );
				}

				update_option( BRICKS_DB_COMPONENTS, array_values( $local ) );
				break;

			case 'templates':
				foreach ( $type_manifest['items'] as $item ) {
					if ( ! self::is_item_selected( $item['id'], $selected_item_ids, false ) ) {
						continue;
					}

					$template = self::read_json_from_zip( $zip, $item['path'] );

					if ( ! is_array( $template ) ) {
						continue;
					}

					$template = self::remap_import_ids_in_data(
						$template,
						$import_context['id_maps']['custom-fonts'] ?? []
					);

					if ( is_array( $template['components'] ?? null ) ) {
						$template['components'] = Components::upgrade_components( $template['components'], true );
					}

					$code_check = self::check_template_data_code_permissions( $template );

					if ( is_wp_error( $code_check ) ) {
						return $code_check;
					}

					$existing_template_id = self::find_template_id( $template['title'] ?? '', $template['templateType'] ?? '' );

					if ( $existing_template_id ) {
						$item_conflict_mode = self::get_item_conflict_mode( $item['id'] ?? '', $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $template['title'] ?? esc_html__( 'Template', 'bricks' ), 'skipped' );
							continue;
						}
					}

					self::remap_global_class_ids_in_template( $template, self::map_transfer_dependency_class_ids( $template ) );
					$template = self::prepare_template_code_for_import( $template );
					if ( is_wp_error( $template ) ) {
						return $template;
					}

					if ( empty( $manifest['types']['components'] ) ) {
						self::import_template_components( $template );
					}

					$new_template_id = self::upsert_template_from_export( $template, $existing_template_id, $import_images );

					if ( is_wp_error( $new_template_id ) ) {
						return $new_template_id;
					}

					if ( $new_template_id ) {
						$result['imported']++;
						$result['items'][] = self::result_item( $template['title'] ?? esc_html__( 'Template', 'bricks' ), $existing_template_id ? 'replaced' : 'imported' );
					} else {
						$result['skipped']++;
						$result['items'][] = self::result_item( $template['title'] ?? esc_html__( 'Template', 'bricks' ), 'skipped' );
					}
				}
				break;

			case 'settings':
				$settings = self::read_json_from_zip( $zip, 'settings/wp-dashboard/settings.json' );

				if ( is_array( $settings ) ) {
					$imported = self::import_settings_transfer_data( $settings, $selected_item_ids );

					if ( isset( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
						$tabs = self::get_settings_transfer_tabs();

						foreach ( self::get_selected_settings_tab_ids( $selected_item_ids ) as $tab_id ) {
							if ( isset( $settings['tabs'][ $tab_id ] ) ) {
								$result['items'][] = self::result_item( $tabs[ $tab_id ]['label'] ?? $tab_id, 'imported' );
							}
						}
					} else {
						$result['items'][] = self::result_item( esc_html__( 'Settings', 'bricks' ), 'imported' );
					}

					$result['imported'] = $imported;
				}
				break;

			case 'builder-interface':
				$incoming_profiles = self::read_json_from_zip( $zip, 'settings/builder-interface/profiles.json' );
				$incoming_profiles = self::normalize_builder_interface_profiles_transfer_data( $incoming_profiles );
				$existing_profiles = Builder::get_builder_ui_profiles();

				foreach ( (array) $incoming_profiles as $profile_id => $profile ) {
					if ( ! self::is_item_selected( $profile_id, $selected_item_ids ) ) {
						continue;
					}

					$label = $profile['label'] ?? $profile_id;

					if ( isset( $existing_profiles[ $profile_id ] ) ) {
						$item_conflict_mode = self::get_item_conflict_mode( $profile_id, $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $label, 'skipped' );
							continue;
						}

						$existing_profiles[ $profile_id ] = $profile;
						$result['imported']++;
						$result['items'][] = self::result_item( $label, 'replaced' );
						continue;
					}

					$existing_profiles[ $profile_id ] = $profile;
					$result['imported']++;
					$result['items'][] = self::result_item( $label, 'imported' );
				}

				Builder::save_builder_ui_profiles( $existing_profiles );
				break;

			case 'custom-capabilities':
				$incoming_capabilities = self::read_json_from_zip( $zip, 'settings/custom-capabilities/capabilities.json' );
				$existing_capabilities = self::get_custom_capabilities_for_transfer();

				foreach ( (array) $incoming_capabilities as $capability ) {
					if ( ! is_array( $capability ) || ! self::is_item_selected( $capability['id'] ?? '', $selected_item_ids, false ) ) {
						continue;
					}

					$capability = self::sanitize_custom_capability_for_import( $capability );

					if ( empty( $capability['id'] ) || empty( $capability['label'] ) || isset( Builder_Permissions::DEFAULT_CAPABILITIES[ $capability['id'] ] ) ) {
						continue;
					}

					$existing_index = self::find_list_index_by_id( $existing_capabilities, $capability['id'] );

					if ( $existing_index !== null ) {
						$item_conflict_mode = self::get_item_conflict_mode( $capability['id'] ?? '', $conflict_mode, $conflict_decisions );

						if ( $item_conflict_mode !== 'replace' ) {
							$result['skipped']++;
							$result['items'][] = self::result_item( $capability['label'], 'skipped' );
							continue;
						}

						$capability['id'] = $existing_capabilities[ $existing_index ]['id'];

						$existing_capabilities[ $existing_index ] = $capability;

						$result['imported']++;
						$result['items'][] = self::result_item( $capability['label'], 'replaced' );
						continue;
					}

					$existing_capabilities[] = $capability;

					$result['imported']++;
					$result['items'][] = self::result_item( $capability['label'], 'imported' );
				}

				Builder_Permissions::save_custom_capabilities( $existing_capabilities );
				break;
		}

		return $result;
	}

	private static function open_request_zip() {
		if ( empty( $_FILES['file']['tmp_name'] ) ) {
			return new \WP_Error( 'missing_file', 'No ZIP file uploaded.' );
		}

		$file = $_FILES['file'];

		if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
			return new \WP_Error( 'upload_error', 'The ZIP file upload failed.' );
		}

		if ( ! empty( $file['size'] ) && $file['size'] > wp_max_upload_size() ) {
			return new \WP_Error( 'upload_too_large', 'The ZIP file exceeds the maximum upload size.' );
		}

		return self::open_zip_file( $file['tmp_name'] );
	}

	/**
	 * Open a ZIP file.
	 *
	 * @since 2.4
	 *
	 * @param string $file_path ZIP file path.
	 * @return \ZipArchive|\WP_Error
	 */
	private static function open_zip_file( $file_path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'missing_ziparchive', 'ZipArchive PHP extension is not available.' );
		}

		$zip = new \ZipArchive();

		if ( $zip->open( $file_path ) !== true ) {
			return new \WP_Error( 'invalid_zip', 'Unable to open ZIP file.' );
		}

		return $zip;
	}

	/**
	 * Open a unified transfer package from the current request.
	 *
	 * Accepts native unified ZIP files and supported manager JSON exports. Legacy
	 * JSON is wrapped in a temporary unified package so the regular import flow can
	 * handle preview, conflicts, and writes.
	 *
	 * @since 2.4
	 *
	 * @param string $expected_type Optional transfer type expected by the caller.
	 * @return array|\WP_Error
	 */
	private static function open_request_transfer_package( $expected_type = '' ) {
		$zip = self::open_request_zip();

		if ( ! is_wp_error( $zip ) ) {
			return [
				'zip'       => $zip,
				'temporary' => false,
			];
		}

		if ( $zip->get_error_code() !== 'invalid_zip' ) {
			return $zip;
		}

		$legacy = self::read_legacy_transfer_from_uploaded_file( $expected_type );

		if ( is_wp_error( $legacy ) ) {
			return $legacy;
		}

		if ( ! is_array( $legacy ) ) {
			return $zip;
		}

		return self::create_legacy_transfer_package( $legacy['type'], $legacy['data'] );
	}

	/**
	 * Close a request package and remove temporary files when needed.
	 *
	 * @since 2.4
	 *
	 * @param array $package Package returned by open_request_transfer_package().
	 * @return void
	 */
	private static function cleanup_request_transfer_package( $package ) {
		if ( ! empty( $package['temporary'] ) ) {
			self::cleanup_zip_handle( $package );
			return;
		}

		if ( isset( $package['zip'] ) && $package['zip'] instanceof \ZipArchive ) {
			$package['zip']->close();
		}
	}

	/**
	 * Read supported manager JSON from the uploaded file.
	 *
	 * @since 2.4
	 *
	 * @param string $expected_type Optional transfer type expected by the caller.
	 * @return array|null|\WP_Error Returns null when the uploaded file is not supported JSON.
	 */
	private static function read_legacy_transfer_from_uploaded_file( $expected_type = '' ) {
		if ( empty( $_FILES['file']['tmp_name'] ) ) {
			return new \WP_Error( 'missing_file', esc_html__( 'No file uploaded.', 'bricks' ) );
		}

		$file = $_FILES['file'];

		if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
			return new \WP_Error( 'upload_error', esc_html__( 'The file upload failed.', 'bricks' ) );
		}

		if ( ! empty( $file['size'] ) && $file['size'] > wp_max_upload_size() ) {
			return new \WP_Error( 'upload_too_large', esc_html__( 'The file exceeds the maximum upload size.', 'bricks' ) );
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the uploaded import file.

		if ( ! is_string( $contents ) || trim( $contents ) === '' ) {
			return null;
		}

		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::normalize_legacy_transfer_json( $data, $expected_type );
	}

	/**
	 * Normalize supported manager JSON into a transfer type and payload.
	 *
	 * @since 2.4
	 *
	 * @param array  $data          Uploaded JSON data.
	 * @param string $expected_type Optional transfer type expected by the caller.
	 * @return array|null
	 */
	private static function normalize_legacy_transfer_json( $data, $expected_type = '' ) {
		$types = $expected_type ? [ $expected_type ] : [
			'builder-interface',
			'variables',
			'global-queries',
			'color-palettes',
			'theme-styles',
			'components',
			'templates',
			'settings',
			'icon-manager',
			'classes',
		];

		foreach ( $types as $type ) {
			$normalized = self::normalize_legacy_transfer_type_json( $data, $type );

			if ( $normalized !== null ) {
				return [
					'type' => $type,
					'data' => $normalized,
				];
			}
		}

		return null;
	}

	/**
	 * Normalize JSON for one transfer type.
	 *
	 * @since 2.4
	 *
	 * @param array  $data Uploaded JSON data.
	 * @param string $type Transfer type.
	 * @return array|null
	 */
	private static function normalize_legacy_transfer_type_json( $data, $type ) {
		switch ( $type ) {
			case 'builder-interface':
				return self::normalize_builder_interface_profiles_transfer_data( $data );

			case 'classes':
				return self::normalize_legacy_classes_json( $data );

			case 'variables':
				if ( isset( $data['variables'] ) && is_array( $data['variables'] ) ) {
					return [
						'items'      => $data['variables'],
						'categories' => is_array( $data['categories'] ?? null ) ? $data['categories'] : [],
					];
				}

				if ( isset( $data['globalVariables'] ) && is_array( $data['globalVariables'] ) ) {
					return [
						'items'      => $data['globalVariables'],
						'categories' => is_array( $data['globalVariablesCats'] ?? null ) ? $data['globalVariablesCats'] : [],
					];
				}

				break;

			case 'global-queries':
				if ( isset( $data['queries'] ) && is_array( $data['queries'] ) ) {
					return [
						'items'      => $data['queries'],
						'categories' => is_array( $data['categories'] ?? null ) ? $data['categories'] : [],
					];
				}

				break;

			case 'color-palettes':
				if ( self::is_color_palette_row( $data ) ) {
					return [ $data ];
				}

				if ( self::is_list_array( $data ) ) {
					$palettes = array_values(
						array_filter(
							$data,
							function( $palette ) {
								return self::is_color_palette_row( $palette );
							}
						)
					);

					return count( $palettes ) ? $palettes : null;
				}

				if ( isset( $data['colorPalette'] ) && is_array( $data['colorPalette'] ) ) {
					return array_values( $data['colorPalette'] );
				}

				break;

			case 'theme-styles':
				if ( self::is_theme_style_row( $data ) ) {
					return [ $data ];
				}

				if ( self::is_list_array( $data ) ) {
					$styles = array_values(
						array_filter(
							$data,
							function( $style ) {
								return self::is_theme_style_row( $style );
							}
						)
					);

					return count( $styles ) ? $styles : null;
				}

				if ( isset( $data['themeStyles'] ) && is_array( $data['themeStyles'] ) ) {
					return self::normalize_theme_styles_map_to_rows( $data['themeStyles'] );
				}

				break;

			case 'components':
				$components     = null;
				$global_classes = [];

				if ( isset( $data['components'] ) && is_array( $data['components'] ) ) {
					$components     = $data['components'];
					$global_classes = is_array( $data['globalClasses'] ?? null ) ? $data['globalClasses'] : [];
				} elseif ( self::is_list_array( $data ) ) {
					$components = $data;
				}

				if ( is_array( $components ) && count( $components ) && self::is_component_row( $components[0] ?? [] ) ) {
					return [
						'components'    => $components,
						'globalClasses' => $global_classes,
					];
				}

				break;

			case 'templates':
				if ( self::is_template_export_row( $data ) ) {
					return [ $data ];
				}

				if ( self::is_list_array( $data ) ) {
					$templates = array_values(
						array_filter(
							$data,
							function( $template ) {
								return self::is_template_export_row( $template );
							}
						)
					);

					return count( $templates ) ? $templates : null;
				}

				if ( isset( $data['templates'] ) && is_array( $data['templates'] ) ) {
					return array_values( $data['templates'] );
				}

				break;

			case 'settings':
				if ( isset( $data['tabs'] ) && is_array( $data['tabs'] ) ) {
					return $data;
				}

				if ( isset( $data['globalSettings'] ) && is_array( $data['globalSettings'] ) ) {
					return $data['globalSettings'];
				}

				if ( isset( $data['settings'] ) && is_array( $data['settings'] ) && ! self::is_theme_style_row( $data ) ) {
					return $data['settings'];
				}

				if ( self::has_global_settings_keys( $data ) ) {
					return $data;
				}

				break;

			case 'icon-manager':
				if ( isset( $data['iconSets'] ) || isset( $data['customIcons'] ) || isset( $data['disabledIconSets'] ) ) {
					return [
						'sets'             => is_array( $data['iconSets'] ?? null ) ? $data['iconSets'] : [],
						'icons'            => is_array( $data['customIcons'] ?? null ) ? $data['customIcons'] : [],
						'disabledIconSets' => is_array( $data['disabledIconSets'] ?? null ) ? $data['disabledIconSets'] : [],
					];
				}

				if ( isset( $data['sets'] ) || isset( $data['icons'] ) || isset( $data['disabledIconSets'] ) ) {
					return [
						'sets'             => is_array( $data['sets'] ?? null ) ? $data['sets'] : [],
						'icons'            => is_array( $data['icons'] ?? null ) ? $data['icons'] : [],
						'disabledIconSets' => is_array( $data['disabledIconSets'] ?? null ) ? $data['disabledIconSets'] : [],
					];
				}

				break;
		}

		return null;
	}

	/**
	 * Normalize a Class Manager JSON payload.
	 *
	 * @since 2.4
	 *
	 * @param array $data Uploaded JSON data.
	 * @return array|null
	 */
	private static function normalize_legacy_classes_json( $data ) {
		$classes    = null;
		$categories = [];

		if ( self::is_list_array( $data ) ) {
			$classes = $data;
		} elseif ( isset( $data['classes'] ) && is_array( $data['classes'] ) ) {
			$classes    = $data['classes'];
			$categories = is_array( $data['categories'] ?? null ) ? $data['categories'] : [];
		} elseif ( isset( $data['globalClasses'] ) && is_array( $data['globalClasses'] ) ) {
			$classes    = $data['globalClasses'];
			$categories = is_array( $data['globalClassesCats'] ?? null ) ? $data['globalClassesCats'] : [];
		} elseif ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$classes    = $data['items'];
			$categories = is_array( $data['categories'] ?? null ) ? $data['categories'] : [];
		}

		if ( ! is_array( $classes ) ) {
			return null;
		}

		$normalized_classes = [];

		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) || empty( $class['name'] ) ) {
				continue;
			}

			if ( empty( $class['id'] ) ) {
				$class['id'] = Helpers::generate_random_id( false );
			}

			$normalized_classes[] = $class;
		}

		if ( empty( $normalized_classes ) ) {
			return null;
		}

		return self::split_class_category_metadata( $normalized_classes, $categories );
	}

	/**
	 * Split Class Manager category metadata from class rows.
	 *
	 * @since 2.4
	 *
	 * @param array $classes    Class rows.
	 * @param array $categories Existing category rows.
	 * @return array
	 */
	private static function split_class_category_metadata( $classes, $categories = [] ) {
		$categories_by_id = [];

		foreach ( is_array( $categories ) ? $categories : [] as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) || empty( $category['name'] ) ) {
				continue;
			}

			$categories_by_id[ $category['id'] ] = [
				'id'   => $category['id'],
				'name' => $category['name'],
			];
		}

		foreach ( $classes as &$class ) {
			if ( ! empty( $class['_categoryData'] ) && is_array( $class['_categoryData'] ) ) {
				$category = $class['_categoryData'];

				if ( ! empty( $category['id'] ) && ! empty( $category['name'] ) ) {
					$categories_by_id[ $category['id'] ] = [
						'id'   => $category['id'],
						'name' => $category['name'],
					];
				}
			}

			unset( $class['_categoryData'] );
		}
		unset( $class );

		return [
			'classes'    => array_values( $classes ),
			'categories' => array_values( $categories_by_id ),
		];
	}

	/**
	 * Create a temporary unified transfer ZIP from supported manager JSON data.
	 *
	 * @since 2.4
	 *
	 * @param string $type Transfer type.
	 * @param array  $data Normalized transfer data.
	 * @return array|\WP_Error
	 */
	private static function create_legacy_transfer_package( $type, $data ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'missing_ziparchive', 'ZipArchive PHP extension is not available.' );
		}

		$temp = self::create_temp_zip_path( 'legacy-transfer-' );

		if ( is_wp_error( $temp ) ) {
			return $temp;
		}

		$zip = new \ZipArchive();

		if ( $zip->open( $temp['path'], \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			self::remove_empty_dir( $temp['dir'] );

			return new \WP_Error( 'invalid_zip', 'Unable to create ZIP file.' );
		}

		$type_manifest = self::add_legacy_transfer_type_to_zip( $zip, $type, $data );

		if ( is_wp_error( $type_manifest ) ) {
			$zip->close();
			wp_delete_file( $temp['path'] );
			self::remove_empty_dir( $temp['dir'] );

			return $type_manifest;
		}

		$manifest = [
			'schema'        => self::MANIFEST_SCHEMA,
			'version'       => self::MANIFEST_VERSION,
			'createdAt'     => gmdate( 'c' ),
			'site'          => [
				'name'          => '',
				'homeUrl'       => '',
				'bricksVersion' => '',
			],
			'selectedTypes' => [ $type ],
			'types'         => [
				$type => $type_manifest,
			],
		];

		if ( in_array( $type, [ 'components', 'templates' ], true ) ) {
			self::add_dependency_types_to_manifest( $zip, $manifest );
		}

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->close();

		$zip = self::open_zip_file( $temp['path'] );

		if ( is_wp_error( $zip ) ) {
			wp_delete_file( $temp['path'] );
			self::remove_empty_dir( $temp['dir'] );

			return $zip;
		}

		return [
			'zip'       => $zip,
			'path'      => $temp['path'],
			'dir'       => $temp['dir'],
			'temporary' => true,
		];
	}

	/**
	 * Add supported manager JSON data to a transfer ZIP.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip  ZIP archive.
	 * @param string      $type Transfer type.
	 * @param array       $data Normalized transfer data.
	 * @return array|\WP_Error
	 */
	private static function add_legacy_transfer_type_to_zip( $zip, $type, $data ) {
		switch ( $type ) {
			case 'classes':
				return self::add_classes_to_zip( $zip, $data['classes'] ?? [], $data['categories'] ?? [] );

			case 'variables':
				return self::export_type_to_zip( $zip, 'variables', [], $data );

			case 'global-queries':
				return self::export_type_to_zip( $zip, 'global-queries', [], $data );

			case 'color-palettes':
				return self::export_type_to_zip( $zip, 'color-palettes', [], $data );

			case 'theme-styles':
				return self::export_type_to_zip( $zip, 'theme-styles', [], self::theme_style_rows_to_map( $data ) );

			case 'components':
				return self::add_legacy_components_to_zip( $zip, $data );

			case 'templates':
				return self::add_legacy_templates_to_zip( $zip, $data );

			case 'settings':
				return self::add_legacy_settings_to_zip( $zip, $data );

			case 'builder-interface':
				return self::export_type_to_zip( $zip, 'builder-interface', [], $data );

			case 'icon-manager':
				return self::export_icon_manager_to_zip( $zip, $data, [] );
		}

		return new \WP_Error( 'invalid_transfer_type', esc_html__( 'The import file format is not supported.', 'bricks' ) );
	}

	/**
	 * Add legacy component data to a transfer ZIP.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip  ZIP archive.
	 * @param array       $data Component transfer data.
	 * @return array
	 */
	private static function add_legacy_components_to_zip( $zip, $data ) {
		$components     = self::unique_components_by_id( $data['components'] ?? [] );
		$global_classes = is_array( $data['globalClasses'] ?? null ) ? $data['globalClasses'] : [];
		$items          = [];

		foreach ( $components as $component ) {
			$component_id = $component['id'] ?? Helpers::generate_random_id( false );
			$component_ui = [
				'components' => [ $component ],
			];

			$dependencies = self::build_transfer_dependencies_for_elements( $component['elements'] ?? [], [ $component ] );

			if ( count( $global_classes ) ) {
				$dependencies['globalClasses'] = array_values( array_merge( $dependencies['globalClasses'] ?? [], $global_classes ) );
			}

			if ( count( $dependencies ) ) {
				$component_ui['dependencies'] = $dependencies;
			}

			$file_name = self::sanitize_file_name( self::get_component_label( $component ) ) . '-' . $component_id . '.json';
			$path      = 'structure/components/' . $file_name;

			$zip->addFromString( $path, wp_json_encode( $component_ui, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			$item = [
				'id'       => $component_id,
				'label'    => self::get_component_label( $component ),
				'path'     => $path,
				'category' => $component['category'] ?? 'components',
			];

			self::attach_dependency_summary_to_manifest_item( $item, $dependencies );

			$items[] = $item;
		}

		return self::manifest_type_data( 'components', false, self::build_categories_from_items( $items ), $items );
	}

	/**
	 * Add legacy template data to a transfer ZIP.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip       ZIP archive.
	 * @param array       $templates Template exports.
	 * @return array
	 */
	private static function add_legacy_templates_to_zip( $zip, $templates ) {
		$items = [];

		foreach ( array_values( $templates ) as $index => $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}

			$template_id = (string) ( $template['id'] ?? $template['templateId'] ?? ( 'template-' . $index ) );
			$file_name   = self::sanitize_file_name( $template['title'] ?? esc_html__( 'Template', 'bricks' ) ) . '-' . $template_id . '.json';
			$path        = 'structure/templates/' . $file_name;

			$zip->addFromString( $path, wp_json_encode( $template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			$item = [
				'id'       => $template_id,
				'label'    => $template['title'] ?? esc_html__( 'Template', 'bricks' ),
				'path'     => $path,
				'category' => $template['templateType'] ?? '',
			];

			self::attach_dependency_summary_to_manifest_item( $item, $template );

			$items[] = $item;
		}

		return self::manifest_type_data( 'templates', false, self::build_categories_from_items( $items ), $items );
	}

	/**
	 * Add legacy global settings data to a transfer ZIP.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip      ZIP archive.
	 * @param array       $settings Settings or settings transfer data.
	 * @return array
	 */
	private static function add_legacy_settings_to_zip( $zip, $settings ) {
		$path          = 'settings/wp-dashboard/settings.json';
		$settings_data = isset( $settings['tabs'] ) && is_array( $settings['tabs'] )
			? $settings
			: self::build_settings_transfer_data( $settings, [] );
		$tabs          = self::get_settings_transfer_tabs();
		$tab_ids       = array_keys( $settings_data['tabs'] ?? [] );

		$zip->addFromString( $path, wp_json_encode( $settings_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		return self::manifest_type_data(
			'settings',
			false,
			[],
			array_map(
				function( $tab_id ) use ( $tabs, $path ) {
					return [
						'id'       => $tab_id,
						'label'    => $tabs[ $tab_id ]['label'] ?? $tab_id,
						'path'     => $path,
						'category' => '',
					];
				},
				$tab_ids
			)
		);
	}

	/**
	 * Extract one transfer type from an open ZIP in the legacy manager shape.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip      ZIP archive.
	 * @param array       $manifest Transfer manifest.
	 * @param string      $type     Transfer type.
	 * @return array|\WP_Error
	 */
	private static function extract_transfer_type_from_zip( $zip, $manifest, $type ) {
		switch ( $type ) {
			case 'classes':
				$classes    = self::read_json_from_zip( $zip, 'styles/classes/classes.json' );
				$categories = self::read_json_from_zip( $zip, 'styles/classes/categories.json' );

				if ( ! is_array( $classes ) ) {
					return new \WP_Error( 'invalid_classes', esc_html__( 'The import file does not contain valid classes.', 'bricks' ) );
				}

				return self::attach_category_metadata_to_classes( $classes, is_array( $categories ) ? $categories : [] );

			case 'variables':
				$variables  = self::read_json_from_zip( $zip, 'styles/variables/variables.json' );
				$categories = self::read_json_from_zip( $zip, 'styles/variables/categories.json' );

				return [
					'variables'  => is_array( $variables ) ? array_values( $variables ) : [],
					'categories' => is_array( $categories ) ? array_values( $categories ) : [],
				];

			case 'global-queries':
				$queries    = self::read_json_from_zip( $zip, 'structure/global-queries/queries.json' );
				$categories = self::read_json_from_zip( $zip, 'structure/global-queries/categories.json' );

				return [
					'queries'    => is_array( $queries ) ? array_values( $queries ) : [],
					'categories' => is_array( $categories ) ? array_values( $categories ) : [],
				];

			case 'color-palettes':
				return self::extract_transfer_items_from_zip( $zip, $manifest, $type );

			case 'theme-styles':
				return self::extract_transfer_items_from_zip( $zip, $manifest, $type );

			case 'icon-manager':
				$icon_manager = self::read_json_from_zip( $zip, 'styles/icon-manager/icon-manager.json' );

				return is_array( $icon_manager ) ? $icon_manager : [];

			case 'components':
				$component_files = self::extract_transfer_items_from_zip( $zip, $manifest, $type );
				$components      = [];
				$global_classes  = [];

				foreach ( $component_files as $component_file ) {
					if ( is_array( $component_file['components'] ?? null ) ) {
						$components = array_merge( $components, $component_file['components'] );
					}

					if ( is_array( $component_file['dependencies']['globalClasses'] ?? null ) ) {
						$global_classes = array_merge( $global_classes, $component_file['dependencies']['globalClasses'] );
					}
				}

				if ( ! empty( $manifest['types']['classes'] ) ) {
					$manifest_classes = self::extract_transfer_type_from_zip( $zip, $manifest, 'classes' );

					if ( is_array( $manifest_classes ) ) {
						$global_classes = array_merge( $global_classes, $manifest_classes );
					}
				}

				return [
					'components'    => self::unique_components_by_id( $components ),
					'globalClasses' => self::unique_rows_by_id( $global_classes ),
				];

			case 'templates':
				return self::extract_transfer_items_from_zip( $zip, $manifest, $type );

			case 'settings':
				$settings = self::read_json_from_zip( $zip, 'settings/wp-dashboard/settings.json' );

				return is_array( $settings ) ? $settings : [];

			case 'builder-interface':
				$builder_interface = self::read_json_from_zip( $zip, 'settings/builder-interface/profiles.json' );

				return is_array( $builder_interface ) ? $builder_interface : [];
		}

		return new \WP_Error( 'invalid_transfer_type', esc_html__( 'The import file format is not supported.', 'bricks' ) );
	}

	/**
	 * Extract item JSON files for a transfer type.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip      ZIP archive.
	 * @param array       $manifest Transfer manifest.
	 * @param string      $type     Transfer type.
	 * @return array
	 */
	private static function extract_transfer_items_from_zip( $zip, $manifest, $type ) {
		$items = [];
		$paths = [];

		foreach ( (array) ( $manifest['types'][ $type ]['items'] ?? [] ) as $item ) {
			$path = $item['path'] ?? '';

			if ( ! $path || in_array( $path, $paths, true ) ) {
				continue;
			}

			$data = self::read_json_from_zip( $zip, $path );

			if ( is_array( $data ) ) {
				$items[] = $data;
				$paths[] = $path;
			}
		}

		return $items;
	}

	/**
	 * Add global classes to a transfer ZIP and return manifest metadata.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip        ZIP archive.
	 * @param array       $classes    Class rows.
	 * @param array       $categories Category rows.
	 * @return array
	 */
	private static function add_classes_to_zip( $zip, $classes, $categories ) {
		$zip->addFromString( 'styles/classes/classes.json', wp_json_encode( array_values( $classes ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->addFromString( 'styles/classes/categories.json', wp_json_encode( array_values( $categories ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$items = array_map(
			function( $class ) {
				return [
					'id'       => $class['id'] ?? '',
					'label'    => $class['name'] ?? esc_html__( 'Class', 'bricks' ),
					'path'     => 'styles/classes/classes.json',
					'category' => $class['category'] ?? '',
				];
			},
			array_values( $classes )
		);

		return self::manifest_type_data( 'classes', false, array_values( $categories ), $items );
	}

	/**
	 * Attach category metadata to classes for the Class Manager import UI.
	 *
	 * @since 2.4
	 *
	 * @param array $classes    Class rows.
	 * @param array $categories Category rows.
	 * @return array
	 */
	private static function attach_category_metadata_to_classes( $classes, $categories ) {
		$categories_by_id = [];

		foreach ( is_array( $categories ) ? $categories : [] as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) || empty( $category['name'] ) ) {
				continue;
			}

			$categories_by_id[ $category['id'] ] = [
				'id'   => $category['id'],
				'name' => $category['name'],
			];
		}

		foreach ( $classes as &$class ) {
			if ( ! is_array( $class ) || empty( $class['category'] ) || empty( $categories_by_id[ $class['category'] ] ) ) {
				continue;
			}

			$class['_categoryData'] = $categories_by_id[ $class['category'] ];
		}
		unset( $class );

		return array_values( $classes );
	}

	/**
	 * Create a temporary ZIP path.
	 *
	 * @since 2.4
	 *
	 * @param string $prefix Directory prefix.
	 * @return array|\WP_Error
	 */
	private static function create_temp_zip_path( $prefix ) {
		$wp_upload_dir = wp_upload_dir();

		if ( ! empty( $wp_upload_dir['error'] ) ) {
			return \Bricks\Abilities\Error::internal_error( 'transfer_package_zip', 'wp_upload_dir error: ' . $wp_upload_dir['error'] );
		}

		$temp_base = trailingslashit( $wp_upload_dir['basedir'] ) . BRICKS_TEMP_DIR;

		if ( ! wp_mkdir_p( $temp_base ) ) {
			return \Bricks\Abilities\Error::internal_error( 'transfer_package_zip', 'Could not create the Bricks temporary import directory.' );
		}

		$temp_path = trailingslashit( $temp_base ) . sanitize_file_name( $prefix ) . wp_generate_password( 8, false, false );

		if ( ! wp_mkdir_p( $temp_path ) ) {
			return \Bricks\Abilities\Error::internal_error( 'transfer_package_zip', 'Could not create a request-scoped import directory.' );
		}

		return [
			'dir'  => $temp_path,
			'path' => trailingslashit( $temp_path ) . 'package.zip',
		];
	}

	/**
	 * Check whether an array is a sequential list.
	 *
	 * @since 2.4
	 *
	 * @param array $array Array to inspect.
	 * @return bool
	 */
	private static function is_list_array( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		if ( empty( $array ) ) {
			return true;
		}

		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * Check whether an array looks like a color palette.
	 *
	 * @since 2.4
	 *
	 * @param mixed $row Row to inspect.
	 * @return bool
	 */
	private static function is_color_palette_row( $row ) {
		return is_array( $row ) && ! empty( $row['name'] ) && is_array( $row['colors'] ?? null );
	}

	/**
	 * Check whether an array looks like a theme style export.
	 *
	 * @since 2.4
	 *
	 * @param mixed $row Row to inspect.
	 * @return bool
	 */
	private static function is_theme_style_row( $row ) {
		return is_array( $row ) && ! empty( $row['label'] ) && isset( $row['settings'] ) && is_array( $row['settings'] );
	}

	/**
	 * Check whether an array looks like a component export.
	 *
	 * @since 2.4
	 *
	 * @param mixed $row Row to inspect.
	 * @return bool
	 */
	private static function is_component_row( $row ) {
		return is_array( $row ) && ! empty( $row['id'] ) && is_array( $row['elements'] ?? null );
	}

	/**
	 * Check whether an array looks like a template export.
	 *
	 * @since 2.4
	 *
	 * @param mixed $row Row to inspect.
	 * @return bool
	 */
	private static function is_template_export_row( $row ) {
		return is_array( $row ) && ! empty( $row['title'] ) && ( is_array( $row['content'] ?? null ) || is_array( $row['header'] ?? null ) || is_array( $row['footer'] ?? null ) );
	}

	/**
	 * Normalize a theme styles option map to import rows.
	 *
	 * @since 2.4
	 *
	 * @param array $styles Theme styles map.
	 * @return array
	 */
	private static function normalize_theme_styles_map_to_rows( $styles ) {
		$rows = [];

		foreach ( $styles as $style_id => $style ) {
			if ( ! is_array( $style ) ) {
				continue;
			}

			$rows[] = [
				'id'       => $style['id'] ?? $style_id,
				'label'    => $style['label'] ?? $style_id,
				'settings' => $style['settings'] ?? [],
			];
		}

		return $rows;
	}

	/**
	 * Convert theme style rows to the option map used by unified exports.
	 *
	 * @since 2.4
	 *
	 * @param array $rows Theme style rows.
	 * @return array
	 */
	private static function theme_style_rows_to_map( $rows ) {
		$styles = [];

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$style_id = (string) ( $row['id'] ?? Helpers::generate_random_id( false ) );

			$styles[ $style_id ] = [
				'label'    => $row['label'] ?? $style_id,
				'settings' => $row['settings'] ?? [],
			];
		}

		return $styles;
	}

	/**
	 * De-duplicate rows by id.
	 *
	 * @since 2.4
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function unique_rows_by_id( $rows ) {
		$unique = [];

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}

			$unique[ (string) $row['id'] ] = $row;
		}

		return array_values( $unique );
	}

	/**
	 * Check whether data contains known global Bricks settings keys.
	 *
	 * @since 2.4
	 *
	 * @param array $data Settings data.
	 * @return bool
	 */
	private static function has_global_settings_keys( $data ) {
		$known_keys = [];

		foreach ( self::get_settings_transfer_tabs() as $tab ) {
			$known_keys = array_merge( $known_keys, $tab['settings'] ?? [] );
		}

		return count( array_intersect( array_keys( $data ), $known_keys ) ) > 0;
	}

	private static function read_manifest_from_zip( $zip ) {
		$manifest_json = self::read_json_string_from_zip( $zip, 'manifest.json' );

		if ( $manifest_json === null ) {
			return new \WP_Error( 'missing_manifest', 'The ZIP file does not contain manifest.json.' );
		}

		if ( is_wp_error( $manifest_json ) ) {
			return $manifest_json;
		}

		$manifest = json_decode( $manifest_json, true );

		if ( ! is_array( $manifest ) || empty( $manifest['types'] ) ) {
			return new \WP_Error( 'invalid_manifest', 'The manifest is invalid.' );
		}

		if ( ( $manifest['schema'] ?? '' ) !== self::MANIFEST_SCHEMA ) {
			return new \WP_Error( 'invalid_manifest_schema', 'The manifest schema is not supported.' );
		}

		return $manifest;
	}

	private static function filter_manifest_by_permissions( $manifest, $context ) {
		// Permission notices must describe the destination user, never the exporting site.
		$manifest['warnings'] = [];

		foreach ( array_keys( $manifest['types'] ) as $type ) {
			if ( ! self::user_can_access_type( $type, $context ) ) {
				if ( $context === 'import' && $type === 'icon-manager' &&
					Builder_Permissions::user_has_permission( 'access_icon_manager' ) &&
					current_user_can( 'upload_files' ) &&
					class_exists( '\Bricks\Capabilities' ) && ! Capabilities::current_user_can_upload_svg() ) {
					$manifest['warnings'][] = [
						'code'    => 'svg_upload_permission_required',
						'type'    => $type,
						'message' => __( 'Icon Manager data in this bundle is unavailable because you do not have SVG-upload permission. Ask an administrator to check Bricks > Settings > General > SVG uploads and the Upload SVG override in your user profile, then reopen the bundle.', 'bricks' ),
					];
				}

				unset( $manifest['types'][ $type ] );
			}
		}

		$manifest['selectedTypes'] = array_keys( $manifest['types'] );

		return $manifest;
	}

	private static function attach_conflicts_to_manifest( $zip, $manifest ) {
		foreach ( array_keys( $manifest['types'] ) as $type ) {
			$manifest['types'][ $type ] = self::attach_type_conflicts_to_manifest(
				$zip,
				$type,
				$manifest['types'][ $type ]
			);
		}

		return $manifest;
	}

	private static function attach_type_conflicts_to_manifest( $zip, $type, $type_manifest ) {
		$type_manifest['items'] = is_array( $type_manifest['items'] ?? null ) ? $type_manifest['items'] : [];
		$conflict_count         = 0;

		switch ( $type ) {
			case 'color-palettes':
				$local_palettes = get_option( BRICKS_DB_COLOR_PALETTE, [] );
				$path_cache     = [];

				foreach ( $type_manifest['items'] as &$item ) {
					$path = $item['path'] ?? '';

					if ( ! $path ) {
						continue;
					}

					if ( ! array_key_exists( $path, $path_cache ) ) {
						$path_cache[ $path ] = self::read_json_from_zip( $zip, $path );
					}

					$palette = $path_cache[ $path ];
					$colors  = array_values( is_array( $palette['colors'] ?? null ) ? $palette['colors'] : [] );

					foreach ( $colors as $color_index => $color ) {
						if ( ! self::matches_color_manifest_item( $item, $color, $color_index ) ) {
							continue;
						}

						$raw = strtolower( (string) ( $color['raw'] ?? '' ) );

						if ( $raw && self::find_color_location_by_raw( $local_palettes, $raw ) ) {
							self::set_manifest_item_conflict( $item, esc_html__( 'A color with the same variable name already exists on this site.', 'bricks' ) );
							$conflict_count++;
						}

						break;
					}
				}

				unset( $item );
				break;

			case 'theme-styles':
				$local_styles = get_option( BRICKS_DB_THEME_STYLES, [] );

				foreach ( $type_manifest['items'] as &$item ) {
					if ( self::find_theme_style_key( $local_styles, $item['label'] ?? '', $item['id'] ?? '' ) !== null ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A theme style with the same label already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'classes':
				$local_classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );

				foreach ( $type_manifest['items'] as &$item ) {
					if (
						self::find_list_index_by_text_field( $local_classes, 'name', $item['label'] ?? '' ) !== null ||
						self::find_list_index_by_id( $local_classes, $item['id'] ?? '' ) !== null
					) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A class with the same name already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'variables':
				$local_variables = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

				foreach ( $type_manifest['items'] as &$item ) {
					if (
						self::find_list_index_by_text_field( $local_variables, 'name', $item['label'] ?? '' ) !== null ||
						self::find_list_index_by_id( $local_variables, $item['id'] ?? '' ) !== null
					) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A variable with the same name already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'custom-fonts':
				$local_fonts = self::get_custom_font_posts();

				foreach ( $type_manifest['items'] as &$item ) {
					$post_id       = self::get_custom_font_post_id_from_item_id( $item['id'] ?? '' );
					$existing_post = $post_id ? get_post( $post_id ) : null;

					if (
						( $existing_post && $existing_post->post_type === BRICKS_DB_CUSTOM_FONTS ) ||
						self::find_custom_font_post_by_family( $local_fonts, $item['label'] ?? '' )
					) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A custom font with the same ID or family already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'icon-manager':
				$local_sets          = get_option( BRICKS_DB_ICON_SETS, [] );
				$local_disabled_sets = get_option( BRICKS_DB_DISABLED_ICON_SETS, [] );
				$icon_manager        = self::read_json_from_zip( $zip, 'styles/icon-manager/icon-manager.json' );
				$incoming_disabled   = is_array( $icon_manager['disabledIconSets'] ?? null ) ? array_values( $icon_manager['disabledIconSets'] ) : [];

				foreach ( $type_manifest['items'] as &$item ) {
					if ( ( $item['id'] ?? '' ) === 'disabled-icon-sets' ) {
						if ( self::normalize_for_compare( array_values( is_array( $local_disabled_sets ) ? $local_disabled_sets : [] ) ) !== self::normalize_for_compare( $incoming_disabled ) ) {
							self::set_manifest_item_conflict( $item, esc_html__( 'Disabled icon sets differ from the current site.', 'bricks' ) );
							$conflict_count++;
						}

						continue;
					}

					if (
						self::find_list_index_by_text_field( $local_sets, 'name', $item['label'] ?? '' ) !== null ||
						self::find_list_index_by_id( $local_sets, $item['id'] ?? '' ) !== null
					) {
						self::set_manifest_item_conflict( $item, esc_html__( 'An icon set with the same name or ID already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'breakpoints':
				$breakpoints       = self::sanitize_breakpoints_for_import( self::read_json_from_zip( $zip, 'structure/breakpoints/breakpoints.json' ) );
				$local_breakpoints = self::sanitize_breakpoints_for_import( Breakpoints::get_breakpoints() );

				if ( is_wp_error( $breakpoints ) ) {
					foreach ( $type_manifest['items'] as &$item ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'Breakpoint data is invalid.', 'bricks' ) );
						$conflict_count++;
					}

					unset( $item );
					break;
				}

				foreach ( $type_manifest['items'] as &$item ) {
					self::attach_breakpoints_item_preview( $item, $breakpoints );
				}

				unset( $item );

				if ( is_array( $local_breakpoints ) && self::normalize_for_compare( $local_breakpoints ) !== self::normalize_for_compare( $breakpoints ) ) {
					foreach ( $type_manifest['items'] as &$item ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'Breakpoints differ from the current site breakpoints.', 'bricks' ) );
						$conflict_count++;
					}

					unset( $item );
				}

				break;

			case 'global-queries':
				$existing_query_ids = array_map(
					function( $query ) {
						return (string) ( $query['id'] ?? '' );
					},
					Database::get_global_queries()
				);

				foreach ( $type_manifest['items'] as &$item ) {
					if ( in_array( (string) ( $item['id'] ?? '' ), $existing_query_ids, true ) ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A query with the same ID already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'components':
				$existing_component_ids = array_map(
					function( $component ) {
						return (string) ( $component['id'] ?? '' );
					},
					get_option( BRICKS_DB_COMPONENTS, [] )
				);

				foreach ( $type_manifest['items'] as &$item ) {
					$component = self::read_json_from_zip( $zip, $item['path'] ?? '' );

					if ( is_array( $component ) ) {
						self::attach_dependency_summary_to_manifest_item( $item, $component['dependencies'] ?? [] );
					}

					if ( in_array( (string) ( $item['id'] ?? '' ), $existing_component_ids, true ) ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A component with the same ID already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'templates':
				foreach ( $type_manifest['items'] as &$item ) {
					$template = self::read_json_from_zip( $zip, $item['path'] ?? '' );

					if ( ! is_array( $template ) ) {
						continue;
					}

					self::attach_dependency_summary_to_manifest_item( $item, $template );

					if ( self::find_template_id( $template['title'] ?? ( $item['label'] ?? '' ), $template['templateType'] ?? ( $item['category'] ?? '' ) ) ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A template with the same title and type already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'settings':
				$tabs          = self::get_settings_transfer_tabs();
				$settings_data = self::read_json_from_zip( $zip, 'settings/wp-dashboard/settings.json' );
				$settings_tabs = is_array( $settings_data['tabs'] ?? null ) ? $settings_data['tabs'] : [];

				foreach ( $type_manifest['items'] as &$item ) {
					$tab_id = (string) ( $item['id'] ?? '' );

					if ( isset( $tabs[ $tab_id ] ) ) {
						self::attach_settings_item_preview( $item, $tabs[ $tab_id ], $settings_tabs[ $tab_id ] ?? [] );
					}

					if ( ! empty( $tabs[ $tab_id ]['sensitive'] ) ) {
						self::set_manifest_item_warning( $item, esc_html__( 'Contains sensitive data. Review carefully before importing.', 'bricks' ) );
					}
				}

				unset( $item );
				break;

			case 'builder-interface':
				$local_profiles = Builder::get_builder_ui_profiles();

				foreach ( $type_manifest['items'] as &$item ) {
					if ( isset( $local_profiles[ $item['id'] ?? '' ] ) ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A builder interface profile with the same ID already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;

			case 'custom-capabilities':
				$local_capabilities = self::get_custom_capabilities_for_transfer();

				foreach ( $type_manifest['items'] as &$item ) {
					if ( self::find_list_index_by_id( $local_capabilities, $item['id'] ?? '' ) !== null ) {
						self::set_manifest_item_conflict( $item, esc_html__( 'A capability with the same key already exists on this site.', 'bricks' ) );
						$conflict_count++;
					}
				}

				unset( $item );
				break;
		}

		$type_manifest['conflictCount'] = $conflict_count;

		return $type_manifest;
	}

	private static function set_manifest_item_conflict( &$item, $message ) {
		$item['conflict'] = [
			'message' => $message,
		];
	}

	private static function set_manifest_item_warning( &$item, $message ) {
		$item['warning'] = [
			'message' => $message,
		];
	}

	private static function get_json_request_value( $key, $default = [] ) {
		if ( empty( $_POST[ $key ] ) ) {
			return $default;
		}

		$value = json_decode( wp_unslash( $_POST[ $key ] ), true );

		return is_array( $value ) ? $value : $default;
	}

	private static function get_text_request_value( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	private static function sanitize_conflict_mode( $mode ) {
		return in_array( $mode, [ 'skip', 'replace' ], true ) ? $mode : 'skip';
	}

	private static function sanitize_conflict_decisions( $decisions ) {
		$decisions = is_array( $decisions ) ? $decisions : [];
		$clean     = [];
		$types     = self::get_type_labels();

		foreach ( $decisions as $type => $type_decisions ) {
			$type = sanitize_text_field( $type );

			if ( ! array_key_exists( $type, $types ) || ! is_array( $type_decisions ) ) {
				continue;
			}

			foreach ( $type_decisions as $item_id => $decision ) {
				$item_id  = (string) sanitize_text_field( $item_id );
				$decision = sanitize_text_field( $decision );

				if ( $item_id && in_array( $decision, [ 'skip', 'replace' ], true ) ) {
					$clean[ $type ][ $item_id ] = $decision;
				}
			}
		}

		return $clean;
	}

	private static function get_item_conflict_mode( $item_id, $conflict_mode, $conflict_decisions ) {
		$item_id  = (string) $item_id;
		$decision = is_array( $conflict_decisions ) && isset( $conflict_decisions[ $item_id ] ) ? $conflict_decisions[ $item_id ] : $conflict_mode;

		return in_array( $decision, [ 'skip', 'replace' ], true ) ? $decision : 'skip';
	}

	private static function sanitize_types( $types ) {
		$types = is_array( $types ) ? $types : [];

		return array_values(
			array_filter(
				array_map( 'sanitize_text_field', $types ),
				function( $type ) {
					return array_key_exists( $type, self::get_type_labels() );
				}
			)
		);
	}

	/**
	 * Order import types so resources are resolved before dependent data.
	 *
	 * @since 2.4
	 *
	 * @param array $types Transfer type IDs.
	 * @return array
	 */
	private static function order_import_types( $types ) {
		$types = array_values( is_array( $types ) ? $types : [] );

		if ( ! in_array( 'custom-fonts', $types, true ) ) {
			return $types;
		}

		return array_merge( [ 'custom-fonts' ], array_values( array_diff( $types, [ 'custom-fonts' ] ) ) );
	}

	/**
	 * Recursively remap exact ID values in transfer data.
	 *
	 * @since 2.4
	 *
	 * @param mixed $data        Transfer data.
	 * @param array $id_map IDs keyed by their source IDs.
	 * @return mixed
	 */
	private static function remap_import_ids_in_data( $data, $id_map ) {
		if ( empty( $id_map ) ) {
			return $data;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::remap_import_ids_in_data( $value, $id_map );
			}

			return $data;
		}

		if ( ! is_string( $data ) ) {
			return $data;
		}

		return $id_map[ $data ] ?? $data;
	}

	/**
	 * Add a source-to-target ID mapping to the import context.
	 *
	 * @since 2.4
	 *
	 * @param array  $import_context Request-scoped import context.
	 * @param string $type           Transfer type ID.
	 * @param string $source_id      Source ID.
	 * @param string $target_id      Target ID.
	 * @return void
	 */
	private static function add_import_id_mapping( &$import_context, $type, $source_id, $target_id ) {
		if ( ! $type || ! $source_id || ! $target_id ) {
			return;
		}

		$import_context['id_maps'][ $type ][ $source_id ] = $target_id;
	}

	private static function sanitize_items_map( $items ) {
		$items = is_array( $items ) ? $items : [];
		$clean = [];

		foreach ( $items as $type => $ids ) {
			$clean[ sanitize_text_field( $type ) ] = array_values(
				array_map(
					function( $value ) {
						return (string) sanitize_text_field( $value );
					},
					is_array( $ids ) ? $ids : []
				)
			);
		}

		return $clean;
	}

	private static function filter_list_by_ids( $items, $selected_ids ) {
		$items        = is_array( $items ) ? $items : [];
		$selected_ids = array_values( array_filter( array_map( 'strval', is_array( $selected_ids ) ? $selected_ids : [] ) ) );

		if ( empty( $selected_ids ) ) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				function( $item ) use ( $selected_ids ) {
					return in_array( (string) ( $item['id'] ?? '' ), $selected_ids, true );
				}
			)
		);
	}

	private static function filter_categories_for_items( $items, $categories ) {
		$category_ids = [];

		foreach ( $items as $item ) {
			if ( ! empty( $item['category'] ) ) {
				$category_ids[] = $item['category'];
			}
		}

		if ( empty( $category_ids ) ) {
			return [];
		}

		return array_values(
			array_filter(
				is_array( $categories ) ? $categories : [],
				function( $category ) use ( $category_ids ) {
					return in_array( $category['id'] ?? '', $category_ids, true );
				}
			)
		);
	}

	private static function build_categories_from_items( $items ) {
		$categories = [];

		foreach ( $items as $item ) {
			if ( empty( $item['category'] ) ) {
				continue;
			}

			$categories[] = [
				'id'   => $item['category'],
				'name' => ucwords( str_replace( [ '_', '-' ], ' ', $item['category'] ) ),
			];
		}

		$unique = [];

		foreach ( $categories as $category ) {
			$unique[ $category['id'] ] = $category;
		}

		return array_values( $unique );
	}

	private static function manifest_type_data( $type, $singleton, $categories, $items, $count = null ) {
		$labels = self::get_type_labels();

		return [
			'id'         => $type,
			'label'      => $labels[ $type ] ?? $type,
			'group'      => self::get_type_group( $type ),
			'singleton'  => $singleton,
			'count'      => $count === null ? count( $items ) : (int) $count,
			'categories' => $categories,
			'items'      => $items,
		];
	}

	private static function get_dependency_manifest_items( $dependencies, $key, $fallback_key = 'name' ) {
		$items = [];

		foreach ( (array) ( $dependencies[ $key ] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id    = (string) ( $item['id'] ?? '' );
			$label = (string) ( $item[ $fallback_key ] ?? ( $item['name'] ?? $id ) );

			if ( ! $id || ! $label ) {
				continue;
			}

			$items[] = [
				'id'    => $id,
				'label' => $label,
				'name'  => (string) ( $item['name'] ?? $label ),
				'raw'   => (string) ( $item['raw'] ?? '' ),
			];
		}

		return $items;
	}

	/**
	 * Build manifest dependency items for component rows.
	 *
	 * @since 2.4
	 *
	 * @param array $dependencies Transfer dependencies.
	 * @return array
	 */
	private static function get_component_dependency_manifest_items( $dependencies ) {
		$items = [];

		foreach ( (array) ( $dependencies['components'] ?? [] ) as $component ) {
			if ( ! is_array( $component ) || empty( $component['id'] ) ) {
				continue;
			}

			$label = self::get_component_label( $component );

			$items[] = [
				'id'    => (string) $component['id'],
				'label' => $label,
				'name'  => $label,
				'raw'   => '',
			];
		}

		return $items;
	}

	private static function get_dependency_summary_label( $count, $type ) {
		if ( $type === 'variables' ) {
			return sprintf(
				// translators: %s: Number of variables.
				_n( '%s variable', '%s variables', $count, 'bricks' ),
				number_format_i18n( $count )
			);
		}

		if ( $type === 'components' ) {
			return sprintf(
				// translators: %s: Number of components.
				_n( '%s component', '%s components', $count, 'bricks' ),
				number_format_i18n( $count )
			);
		}

		return sprintf(
			// translators: %s: Number of classes.
			_n( '%s class', '%s classes', $count, 'bricks' ),
			number_format_i18n( $count )
		);
	}

	private static function attach_dependency_summary_to_manifest_item( &$item, $dependencies ) {
		$classes    = self::get_dependency_manifest_items( $dependencies, 'globalClasses' );
		$classes    = count( $classes ) ? $classes : self::get_dependency_manifest_items( $dependencies, 'global_classes' );
		$variables  = self::get_dependency_manifest_items( $dependencies, 'globalVariables' );
		$components = self::get_component_dependency_manifest_items( $dependencies );
		$count      = count( $classes ) + count( $variables ) + count( $components );

		if ( ! $count ) {
			return;
		}

		$summary = [];

		if ( count( $components ) ) {
			$summary[] = self::get_dependency_summary_label( count( $components ), 'components' );
		}

		if ( count( $variables ) ) {
			$summary[] = self::get_dependency_summary_label( count( $variables ), 'variables' );
		}

		if ( count( $classes ) ) {
			$summary[] = self::get_dependency_summary_label( count( $classes ), 'classes' );
		}

		$item['requires'] = [
			'components' => $components,
			'classes'    => $classes,
			'variables'  => $variables,
		];

		$item['meta'] = sprintf(
			// translators: %s: Dependency summary, such as "1 component, 3 variables, 2 classes".
			esc_html__( 'Requires %s', 'bricks' ),
			implode( ', ', $summary )
		);

		$entries = [];

		foreach ( $variables as $variable ) {
			$entries[] = [
				'key'      => $variable['label'],
				'label'    => esc_html_x( 'Variable', 'CSS variable', 'bricks' ),
				'value'    => $variable['id'],
				'empty'    => false,
				'renderer' => 'text',
			];
		}

		foreach ( $components as $component ) {
			$entries[] = [
				'key'      => $component['label'],
				'label'    => esc_html__( 'Component', 'bricks' ),
				'value'    => $component['label'],
				'empty'    => false,
				'renderer' => 'text',
			];
		}

		foreach ( $classes as $class ) {
			$entries[] = [
				'key'      => $class['label'],
				'label'    => esc_html__( 'Class', 'bricks' ),
				'value'    => $class['label'],
				'empty'    => false,
				'renderer' => 'text',
			];
		}

		$item['preview'] = [
			'type'       => 'dependencies',
			'count'      => $count,
			'countLabel' => sprintf(
				// translators: %s: Number of dependencies.
				_n( '%s dependency', '%s dependencies', $count, 'bricks' ),
				number_format_i18n( $count )
			),
			'labels'     => [
				'included' => esc_html__( 'Included in import', 'bricks' ),
				'excluded' => esc_html__( 'Not included in import', 'bricks' ),
			],
			'entries'    => $entries,
		];
	}

	private static function add_dependency_types_to_manifest( $zip, &$manifest ) {
		$dependencies = self::collect_manifest_dependencies( $zip, $manifest );

		if ( ! empty( $dependencies['globalClasses'] ) ) {
			self::merge_dependency_classes_into_manifest( $zip, $manifest, $dependencies['globalClasses'] );
		}

		if ( ! empty( $dependencies['components'] ) ) {
			self::merge_dependency_components_into_manifest( $zip, $manifest, $dependencies['components'] );
		}

		if ( ! empty( $dependencies['globalVariables'] ) ) {
			self::merge_dependency_variables_into_manifest(
				$zip,
				$manifest,
				$dependencies['globalVariables'],
				$dependencies['globalVariablesCategories'] ?? []
			);
		}
	}

	private static function collect_manifest_dependencies( $zip, $manifest ) {
		$dependencies = [
			'components'                => [],
			'globalClasses'             => [],
			'globalVariables'           => [],
			'globalVariablesCategories' => [],
		];

		foreach ( [ 'components', 'templates' ] as $source ) {
			foreach ( (array) ( $manifest['types'][ $source ]['items'] ?? [] ) as $item ) {
				if ( empty( $item['path'] ) ) {
					continue;
				}

				$data = self::read_json_from_zip( $zip, $item['path'] );

				if ( ! is_array( $data ) ) {
					continue;
				}

				$source_dependencies = $source === 'components'
					? ( is_array( $data['dependencies'] ?? null ) ? $data['dependencies'] : [] )
					: $data;

				$source_context = [
					'type'  => $source,
					'id'    => (string) ( $item['id'] ?? '' ),
					'label' => (string) ( $item['label'] ?? self::get_type_labels()[ $source ] ?? $source ),
				];

				self::collect_dependency_items(
					$dependencies['components'],
					$source_dependencies['components'] ?? [],
					$source_context,
					'label'
				);

				self::collect_dependency_items(
					$dependencies['globalClasses'],
					$source_dependencies['globalClasses'] ?? ( $source_dependencies['global_classes'] ?? [] ),
					$source_context,
					'name'
				);

				self::collect_dependency_items(
					$dependencies['globalVariables'],
					$source_dependencies['globalVariables'] ?? [],
					$source_context,
					'name'
				);

				self::collect_dependency_categories(
					$dependencies['globalVariablesCategories'],
					$source_dependencies['globalVariablesCategories'] ?? []
				);
			}
		}

		return $dependencies;
	}

	private static function collect_dependency_items( &$collection, $items, $source, $fallback_key = 'id' ) {
		$source_context = is_array( $source )
			? $source
			: [
				'type'  => (string) $source,
				'id'    => '',
				'label' => (string) $source,
			];

		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$key = (string) ( $item['id'] ?? '' );

			if ( ! $key && $fallback_key ) {
				$key = (string) ( $item[ $fallback_key ] ?? '' );
			}

			if ( ! $key ) {
				continue;
			}

			if ( empty( $collection[ $key ] ) ) {
				$collection[ $key ] = [
					'item'       => $item,
					'sources'    => [],
					'requiredBy' => [],
				];
			}

			$source_type = (string) ( $source_context['type'] ?? '' );

			if ( $source_type && ! in_array( $source_type, $collection[ $key ]['sources'], true ) ) {
				$collection[ $key ]['sources'][] = $source_type;
			}

			$required_by_key = implode(
				':',
				[
					(string) ( $source_context['type'] ?? '' ),
					(string) ( $source_context['id'] ?? '' ),
					(string) ( $source_context['label'] ?? '' ),
				]
			);

			if ( ! $required_by_key ) {
				continue;
			}

			$required_by_keys = array_map(
				function( $required_by ) {
					return implode(
						':',
						[
							(string) ( $required_by['type'] ?? '' ),
							(string) ( $required_by['id'] ?? '' ),
							(string) ( $required_by['label'] ?? '' ),
						]
					);
				},
				$collection[ $key ]['requiredBy']
			);

			if ( ! in_array( $required_by_key, $required_by_keys, true ) ) {
				$collection[ $key ]['requiredBy'][] = [
					'type'  => (string) ( $source_context['type'] ?? '' ),
					'id'    => (string) ( $source_context['id'] ?? '' ),
					'label' => (string) ( $source_context['label'] ?? '' ),
				];
			}
		}
	}

	private static function collect_dependency_categories( &$collection, $categories ) {
		foreach ( (array) $categories as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) ) {
				continue;
			}

			$collection[ (string) $category['id'] ] = $category;
		}
	}

	private static function merge_dependency_classes_into_manifest( $zip, &$manifest, $dependency_classes ) {
		$classes_path      = 'styles/classes/classes.json';
		$categories_path   = 'styles/classes/categories.json';
		$classes           = self::read_json_from_zip( $zip, $classes_path );
		$categories        = self::read_json_from_zip( $zip, $categories_path );
		$classes           = is_array( $classes ) ? array_values( $classes ) : [];
		$categories        = is_array( $categories ) ? array_values( $categories ) : [];
		$class_sources     = [];
		$class_required_by = [];

		foreach ( $dependency_classes as $dependency ) {
			$class = $dependency['item'] ?? [];

			if ( ! is_array( $class ) ) {
				continue;
			}

			if ( ! empty( $class['_categoryData'] ) && is_array( $class['_categoryData'] ) ) {
				$categories[] = $class['_categoryData'];
			}

			unset( $class['_categoryData'] );

			$class_key = self::get_dependency_item_key( $class, 'name' );

			if ( ! $class_key ) {
				continue;
			}

			$existing_index = self::find_dependency_item_index( $classes, $class, 'name' );

			if ( $existing_index === null ) {
				$classes[]      = $class;
				$existing_index = count( $classes ) - 1;
			}

			$class_id = (string) ( $classes[ $existing_index ]['id'] ?? '' );

			if ( ! $class_id ) {
				continue;
			}

			$class_sources[ $class_id ]     = array_values( array_unique( array_merge( $class_sources[ $class_id ] ?? [], $dependency['sources'] ?? [] ) ) );
			$class_required_by[ $class_id ] = self::merge_required_by_items( $class_required_by[ $class_id ] ?? [], $dependency['requiredBy'] ?? [] );
		}

		$categories = self::unique_categories_by_id( self::filter_categories_for_items( $classes, $categories ) );

		self::replace_zip_json( $zip, $classes_path, array_values( $classes ) );
		self::replace_zip_json( $zip, $categories_path, array_values( $categories ) );

		$items = array_map(
			function( $class ) use ( $classes_path, $class_sources, $class_required_by ) {
				$class_id = (string) ( $class['id'] ?? '' );
				$item     = [
					'id'       => $class_id,
					'label'    => $class['name'] ?? esc_html__( 'Class', 'bricks' ),
					'path'     => $classes_path,
					'category' => $class['category'] ?? '',
				];

				if ( ! empty( $class_sources[ $class_id ] ) ) {
					$item['dependency']           = true;
					$item['dependencySources']    = $class_sources[ $class_id ];
					$item['dependencyRequiredBy'] = $class_required_by[ $class_id ] ?? [];
				}

				return $item;
			},
			array_values( $classes )
		);

		$manifest['types']['classes'] = self::manifest_type_data( 'classes', false, $categories, $items );
		self::add_selected_type_to_manifest( $manifest, 'classes' );
	}

	/**
	 * Add component dependencies to the manifest and ZIP.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip                   ZIP archive.
	 * @param array       $manifest              Transfer manifest.
	 * @param array       $dependency_components Component dependency rows.
	 */
	private static function merge_dependency_components_into_manifest( $zip, &$manifest, $dependency_components ) {
		$type_manifest = is_array( $manifest['types']['components'] ?? null ) ? $manifest['types']['components'] : [];
		$items         = is_array( $type_manifest['items'] ?? null ) ? array_values( $type_manifest['items'] ) : [];
		$categories    = is_array( $type_manifest['categories'] ?? null ) ? array_values( $type_manifest['categories'] ) : [];
		$item_ids      = [];

		foreach ( $items as $item ) {
			if ( ! empty( $item['id'] ) ) {
				$item_ids[] = (string) $item['id'];
			}
		}

		foreach ( $dependency_components as $dependency ) {
			$component = $dependency['item'] ?? [];

			if ( ! is_array( $component ) || empty( $component['id'] ) ) {
				continue;
			}

			$component_id = (string) $component['id'];

			if ( in_array( $component_id, $item_ids, true ) ) {
				continue;
			}

			$component_dependencies = self::collect_components_from_elements( $component['elements'] ?? [] );
			$components_to_export   = array_merge( [ $component ], $component_dependencies );
			$components_to_export   = self::redact_code_sensitive_components( $components_to_export );
			$component              = $components_to_export[0] ?? $component;
			$component_ui           = [
				'components' => self::unique_components_by_id( $components_to_export ),
			];

			$dependencies = self::build_transfer_dependencies_for_elements( $component['elements'] ?? [], $component_ui['components'] );

			if ( count( $dependencies ) ) {
				$component_ui['dependencies'] = $dependencies;
			}

			$file_name = self::sanitize_file_name( self::get_component_label( $component ) ) . '-' . $component_id . '.json';
			$path      = 'structure/components/' . $file_name;
			$zip->addFromString( $path, wp_json_encode( $component_ui, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			$item = [
				'id'                   => $component_id,
				'label'                => self::get_component_label( $component ),
				'path'                 => $path,
				'category'             => $component['category'] ?? 'components',
				'dependency'           => true,
				'dependencySources'    => $dependency['sources'] ?? [],
				'dependencyRequiredBy' => $dependency['requiredBy'] ?? [],
			];

			self::attach_dependency_summary_to_manifest_item( $item, $dependencies );

			$items[]    = $item;
			$item_ids[] = $component_id;
		}

		if ( empty( $items ) ) {
			return;
		}

		$manifest['types']['components'] = self::manifest_type_data(
			'components',
			false,
			self::unique_categories_by_id( array_merge( $categories, self::build_categories_from_items( $items ) ) ),
			$items
		);

		self::add_selected_type_to_manifest( $manifest, 'components' );
	}

	private static function merge_dependency_variables_into_manifest( $zip, &$manifest, $dependency_variables, $dependency_categories ) {
		$variables_path       = 'styles/variables/variables.json';
		$categories_path      = 'styles/variables/categories.json';
		$variables            = self::read_json_from_zip( $zip, $variables_path );
		$categories           = self::read_json_from_zip( $zip, $categories_path );
		$variables            = is_array( $variables ) ? array_values( $variables ) : [];
		$categories           = is_array( $categories ) ? array_values( $categories ) : [];
		$variable_sources     = [];
		$variable_required_by = [];

		foreach ( (array) $dependency_categories as $category ) {
			if ( is_array( $category ) ) {
				$categories[] = $category;
			}
		}

		foreach ( $dependency_variables as $dependency ) {
			$variable = $dependency['item'] ?? [];

			if ( ! is_array( $variable ) ) {
				continue;
			}

			$variable_key = self::get_dependency_item_key( $variable, 'name' );

			if ( ! $variable_key ) {
				continue;
			}

			$existing_index = self::find_dependency_item_index( $variables, $variable, 'name' );

			if ( $existing_index === null ) {
				$variables[]    = $variable;
				$existing_index = count( $variables ) - 1;
			}

			$variable_id = (string) ( $variables[ $existing_index ]['id'] ?? '' );

			if ( ! $variable_id ) {
				continue;
			}

			$variable_sources[ $variable_id ]     = array_values( array_unique( array_merge( $variable_sources[ $variable_id ] ?? [], $dependency['sources'] ?? [] ) ) );
			$variable_required_by[ $variable_id ] = self::merge_required_by_items( $variable_required_by[ $variable_id ] ?? [], $dependency['requiredBy'] ?? [] );
		}

		$categories = self::unique_categories_by_id( self::filter_categories_for_items( $variables, $categories ) );

		self::replace_zip_json( $zip, $variables_path, array_values( $variables ) );
		self::replace_zip_json( $zip, $categories_path, array_values( $categories ) );

		$items = array_map(
			function( $variable ) use ( $variables_path, $variable_sources, $variable_required_by ) {
				$variable_id = (string) ( $variable['id'] ?? '' );
				$item        = [
					'id'       => $variable_id,
					'label'    => $variable['name'] ?? esc_html_x( 'Variable', 'CSS variable', 'bricks' ),
					'path'     => $variables_path,
					'category' => $variable['category'] ?? '',
					'meta'     => $variable['value'] ?? '',
				];

				if ( ! empty( $variable_sources[ $variable_id ] ) ) {
					$item['dependency']           = true;
					$item['dependencySources']    = $variable_sources[ $variable_id ];
					$item['dependencyRequiredBy'] = $variable_required_by[ $variable_id ] ?? [];
				}

				return $item;
			},
			array_values( $variables )
		);

		$manifest['types']['variables'] = self::manifest_type_data( 'variables', false, $categories, $items );
		self::add_selected_type_to_manifest( $manifest, 'variables' );
	}

	private static function merge_required_by_items( $existing, $incoming ) {
		$items = [];

		foreach ( array_merge( (array) $existing, (array) $incoming ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$key = implode(
				':',
				[
					(string) ( $item['type'] ?? '' ),
					(string) ( $item['id'] ?? '' ),
					(string) ( $item['label'] ?? '' ),
				]
			);

			if ( $key === '::' ) {
				continue;
			}

			$items[ $key ] = [
				'type'  => (string) ( $item['type'] ?? '' ),
				'id'    => (string) ( $item['id'] ?? '' ),
				'label' => (string) ( $item['label'] ?? '' ),
			];
		}

		return array_values( $items );
	}

	private static function get_dependency_item_key( $item, $fallback_key = '' ) {
		$key = (string) ( $item['id'] ?? '' );

		if ( ! $key && $fallback_key ) {
			$key = (string) ( $item[ $fallback_key ] ?? '' );
		}

		return $key;
	}

	private static function find_dependency_item_index( $items, $item, $fallback_key = '' ) {
		$item_id = (string) ( $item['id'] ?? '' );

		if ( $item_id ) {
			$index = self::find_list_index_by_id( $items, $item_id );

			if ( $index !== null ) {
				return $index;
			}
		}

		if ( $fallback_key && ! empty( $item[ $fallback_key ] ) ) {
			return self::find_list_index_by_text_field( $items, $fallback_key, $item[ $fallback_key ] );
		}

		return null;
	}

	private static function unique_categories_by_id( $categories ) {
		$unique = [];

		foreach ( (array) $categories as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) ) {
				continue;
			}

			$unique[ (string) $category['id'] ] = $category;
		}

		return array_values( $unique );
	}

	private static function replace_zip_json( $zip, $path, $data ) {
		$zip->deleteName( $path );
		$zip->addFromString( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	private static function add_selected_type_to_manifest( &$manifest, $type ) {
		$manifest['selectedTypes'] = is_array( $manifest['selectedTypes'] ?? null ) ? $manifest['selectedTypes'] : [];

		if ( ! in_array( $type, $manifest['selectedTypes'], true ) ) {
			$manifest['selectedTypes'][] = $type;
		}
	}

	private static function get_type_group( $type ) {
		$groups = [
			'color-palettes'      => 'style',
			'theme-styles'        => 'style',
			'classes'             => 'style',
			'variables'           => 'style',
			'custom-fonts'        => 'style',
			'icon-manager'        => 'style',
			'breakpoints'         => 'structure',
			'global-queries'      => 'structure',
			'components'          => 'structure',
			'templates'           => 'structure',
			'settings'            => 'settings',
			'builder-interface'   => 'settings',
			'custom-capabilities' => 'settings',
		];

		return $groups[ $type ] ?? 'style';
	}

	private static function get_type_labels() {
		return [
			'color-palettes'      => esc_html__( 'Color palette', 'bricks' ),
			'theme-styles'        => esc_html__( 'Theme styles', 'bricks' ),
			'classes'             => esc_html__( 'Classes', 'bricks' ),
			'variables'           => esc_html__( 'Variables', 'bricks' ),
			'custom-fonts'        => esc_html__( 'Custom fonts', 'bricks' ),
			'icon-manager'        => esc_html__( 'Icon manager', 'bricks' ),
			'breakpoints'         => esc_html__( 'Breakpoints', 'bricks' ),
			'global-queries'      => esc_html__( 'Queries', 'bricks' ),
			'components'          => esc_html__( 'Components', 'bricks' ),
			'templates'           => esc_html__( 'Templates', 'bricks' ),
			'settings'            => esc_html__( 'Settings', 'bricks' ),
			'builder-interface'   => esc_html__( 'Builder interface', 'bricks' ),
			'custom-capabilities' => esc_html__( 'Custom capabilities', 'bricks' ),
		];
	}

	private static function sanitize_file_name( $label ) {
		$label = strtolower( sanitize_file_name( $label ) );
		return $label ? $label : 'item';
	}

	private static function export_custom_fonts_to_zip( $zip, $selected_item_ids ) {
		$font_posts      = self::get_custom_font_posts( 'publish' );
		$items           = [];
		$exported_assets = [];

		foreach ( $font_posts as $font_post ) {
			$font_id = "custom_font_{$font_post->ID}";

			if ( ! empty( $selected_item_ids ) && ! in_array( $font_id, $selected_item_ids, true ) ) {
				continue;
			}

			$font_family = html_entity_decode( get_the_title( $font_post ), ENT_QUOTES, 'UTF-8' );
			$font_faces  = get_post_meta( $font_post->ID, BRICKS_DB_CUSTOM_FONT_FACES, true );
			$font_faces  = is_array( $font_faces ) ? $font_faces : [];
			$export_font = [
				'id'        => $font_id,
				'postId'    => $font_post->ID,
				'family'    => $font_family,
				'fontFaces' => self::build_custom_font_faces_export_data( $zip, $font_faces, $exported_assets ),
			];

			$file_name = self::sanitize_file_name( $font_family ) . '-' . $font_post->ID . '.json';
			$path      = 'styles/custom-fonts/' . $file_name;

			$zip->addFromString( $path, wp_json_encode( $export_font, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			$items[] = [
				'id'       => $font_id,
				'label'    => $font_family,
				'path'     => $path,
				'category' => '',
				'meta'     => count( $font_faces ),
				'postId'   => $font_post->ID,
			];
		}

		return self::manifest_type_data( 'custom-fonts', false, [], $items );
	}

	private static function build_custom_font_faces_export_data( $zip, $font_faces, &$exported_assets ) {
		$export_font_faces = [];

		foreach ( $font_faces as $variant_key => $font_face ) {
			$subsets        = self::normalize_custom_font_face_subsets( $font_face );
			$export_subsets = [];

			foreach ( $subsets as $subset ) {
				$export_subset = [];

				foreach ( $subset as $format => $attachment_id ) {
					if ( $format === 'unicode-range' ) {
						$export_subset['unicode-range'] = $attachment_id;
						continue;
					}

					if ( $format === 'font-weight-range' ) {
						$font_weight_range = is_string( $attachment_id ) ? Custom_Fonts::sanitize_font_weight_range( $attachment_id ) : '';

						if ( $font_weight_range ) {
							$export_subset['font-weight-range'] = $font_weight_range;
						}
						continue;
					}

					$asset = self::add_custom_font_asset_to_zip( $zip, $attachment_id, $exported_assets );

					if ( $asset ) {
						$export_subset[ $format ] = $asset;
					}
				}

				if ( count( $export_subset ) ) {
					$export_subsets[] = $export_subset;
				}
			}

			if ( count( $export_subsets ) ) {
				$export_font_faces[ $variant_key ] = $export_subsets;
			}
		}

		return $export_font_faces;
	}

	private static function normalize_custom_font_face_subsets( $font_face ) {
		if ( isset( $font_face[0] ) && is_array( $font_face[0] ) ) {
			return $font_face;
		}

		return is_array( $font_face ) ? [ $font_face ] : [];
	}

	private static function add_custom_font_asset_to_zip( $zip, $attachment_id, &$exported_assets ) {
		$attachment_id = intval( $attachment_id );

		if ( isset( $exported_assets[ $attachment_id ] ) ) {
			return $exported_assets[ $attachment_id ];
		}

		$file_path = $attachment_id ? get_attached_file( $attachment_id ) : '';

		if ( ! $file_path || ! is_readable( $file_path ) ) {
			return null;
		}

		$file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$allowed_types  = Custom_Fonts::get_public_custom_fonts_mime_types();

		if ( ! isset( $allowed_types[ $file_extension ] ) ) {
			return null;
		}

		$file_name = sanitize_file_name( basename( $file_path ) );
		$path      = sprintf(
			'styles/custom-fonts/assets/%d/%s',
			$attachment_id,
			$file_name
		);

		if ( ! $zip->addFile( $file_path, $path ) ) {
			return null;
		}

		$exported_assets[ $attachment_id ] = [
			'path'         => $path,
			'filename'     => $file_name,
			'mimeType'     => $allowed_types[ $file_extension ],
			'attachmentId' => $attachment_id,
		];

		return $exported_assets[ $attachment_id ];
	}

	private static function import_custom_fonts_from_zip( $zip, $type_manifest, $selected_item_ids, $conflict_mode, $conflict_decisions, &$import_context ) {
		$result = [
			'imported' => 0,
			'skipped'  => 0,
			'items'    => [],
		];

		self::load_media_file_helpers();

		$local_fonts = self::get_custom_font_posts();

		foreach ( $type_manifest['items'] as $item ) {
			if ( ! self::is_item_selected( $item['id'], $selected_item_ids, false ) ) {
				continue;
			}

			$font_data = self::read_json_from_zip( $zip, $item['path'] ?? '' );

			if ( ! is_array( $font_data ) ) {
				$result['skipped']++;
				$result['items'][] = self::result_item( $item['label'] ?? esc_html__( 'Custom font', 'bricks' ), 'skipped' );
				continue;
			}

			$original_post_id    = intval( $font_data['postId'] ?? self::get_custom_font_post_id_from_item_id( $font_data['id'] ?? '' ) );
			$font_family         = sanitize_text_field( $font_data['family'] ?? ( $item['label'] ?? '' ) );
			$existing_post       = $original_post_id ? get_post( $original_post_id ) : null;
			$target_font_by_id   = ( $existing_post && $existing_post->post_type === BRICKS_DB_CUSTOM_FONTS ) ? $existing_post : null;
			$target_font_by_name = self::find_custom_font_post_by_family( $local_fonts, $font_family );
			$target_font         = $target_font_by_id ? $target_font_by_id : $target_font_by_name;
			$has_conflict        = (bool) $target_font;
			$item_conflict_mode  = self::get_item_conflict_mode( $item['id'] ?? '', $conflict_mode, $conflict_decisions );
			$source_font_id      = $original_post_id ? "custom_font_{$original_post_id}" : '';

			if ( $target_font ) {
				self::add_import_id_mapping( $import_context, 'custom-fonts', $source_font_id, "custom_font_{$target_font->ID}" );
			}

			if ( $has_conflict && $item_conflict_mode !== 'replace' ) {
				$result['skipped']++;
				$result['items'][] = self::result_item( $font_family ? $font_family : esc_html__( 'Custom font', 'bricks' ), 'skipped' );
				continue;
			}

			$post_id = self::upsert_custom_font_post( $font_family, $original_post_id, $target_font );

			if ( ! $post_id ) {
				$result['skipped']++;
				$result['items'][] = self::result_item( $font_family ? $font_family : esc_html__( 'Custom font', 'bricks' ), 'skipped' );
				continue;
			}

			$font_faces = self::import_custom_font_faces_from_zip( $zip, $font_data['fontFaces'] ?? [], $post_id );

			if ( ! count( $font_faces ) && count( (array) ( $font_data['fontFaces'] ?? [] ) ) ) {
				if ( ! $target_font ) {
					wp_delete_post( $post_id, true );
				}

				$result['skipped']++;
				$result['items'][] = self::result_item( $font_family ? $font_family : esc_html__( 'Custom font', 'bricks' ), 'skipped' );
				continue;
			}

			if ( count( $font_faces ) ) {
				update_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, $font_faces );
			} else {
				delete_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES );
			}

			self::add_import_id_mapping( $import_context, 'custom-fonts', $source_font_id, "custom_font_{$post_id}" );

			$local_fonts = self::get_custom_font_posts();

			$result['imported']++;

			$status = $target_font ? 'replaced' : 'imported';
			$label  = $font_family ? $font_family : esc_html__( 'Custom font', 'bricks' );

			if ( $original_post_id && $post_id !== $original_post_id ) {
				$label .= sprintf( ' (%s custom_font_%d)', esc_html__( 'new ID:', 'bricks' ), $post_id );
			}

			$result['items'][] = self::result_item( $label, $status );
		}

		self::regenerate_custom_font_face_rules();

		return $result;
	}

	private static function export_icon_manager_to_zip( $zip, $data, $selected_item_ids ) {
		$icon_sets          = self::sanitize_icon_sets_for_transfer( $data['sets'] ?? [] );
		$custom_icons       = self::sanitize_custom_icons_for_transfer( $data['icons'] ?? [] );
		$disabled_icon_sets = self::sanitize_disabled_icon_sets_for_transfer( $data['disabledIconSets'] ?? [] );
		$selected_item_ids  = array_values( array_filter( array_map( 'strval', is_array( $selected_item_ids ) ? $selected_item_ids : [] ) ) );
		$export_disabled    = empty( $selected_item_ids ) || in_array( 'disabled-icon-sets', $selected_item_ids, true );
		$selected_set_ids   = array_values( array_diff( $selected_item_ids, [ 'disabled-icon-sets' ] ) );
		$selected_sets      = empty( $selected_item_ids ) ? $icon_sets : array_values(
			array_filter(
				$icon_sets,
				function( $icon_set ) use ( $selected_set_ids ) {
					return in_array( (string) ( $icon_set['id'] ?? '' ), $selected_set_ids, true );
				}
			)
		);
		$selected_set_ids   = array_map(
			function( $icon_set ) {
				return (string) ( $icon_set['id'] ?? '' );
			},
			$selected_sets
		);

		$custom_icons = array_values(
			array_filter(
				$custom_icons,
				function( $icon ) use ( $selected_set_ids ) {
					return in_array( (string) ( $icon['setId'] ?? '' ), $selected_set_ids, true );
				}
			)
		);

		foreach ( $custom_icons as $index => $icon ) {
			$custom_icons[ $index ] = self::add_custom_icon_asset_to_zip( $zip, $icon );
		}

		$path = 'styles/icon-manager/icon-manager.json';

		$zip->addFromString(
			$path,
			wp_json_encode(
				[
					'sets'             => array_values( $selected_sets ),
					'icons'            => array_values( $custom_icons ),
					'disabledIconSets' => $export_disabled ? $disabled_icon_sets : array_values( array_intersect( $disabled_icon_sets, $selected_set_ids ) ),
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);

		$items = [];

		foreach ( $selected_sets as $icon_set ) {
			$icon_count = count(
				array_filter(
					$custom_icons,
					function( $icon ) use ( $icon_set ) {
						return (string) ( $icon['setId'] ?? '' ) === (string) ( $icon_set['id'] ?? '' );
					}
				)
			);

			$items[] = [
				'id'       => (string) ( $icon_set['id'] ?? '' ),
				'label'    => $icon_set['name'] ?? esc_html__( 'Icon set', 'bricks' ),
				'path'     => $path,
				'category' => 'custom-icon-sets',
				'meta'     => $icon_count,
			];
		}

		if ( $export_disabled && count( $disabled_icon_sets ) ) {
			$items[] = [
				'id'       => 'disabled-icon-sets',
				'label'    => esc_html__( 'Disabled icon sets', 'bricks' ),
				'path'     => $path,
				'category' => 'settings',
				'meta'     => count( $disabled_icon_sets ),
			];
		}

		return self::manifest_type_data( 'icon-manager', false, self::get_icon_manager_categories(), $items );
	}

	private static function import_icon_manager_from_zip( $zip, $type_manifest, $selected_item_ids, $conflict_mode, $conflict_decisions = [] ) {
		$result = [
			'imported' => 0,
			'skipped'  => 0,
			'items'    => [],
		];

		$icon_manager = self::read_json_from_zip( $zip, 'styles/icon-manager/icon-manager.json' );

		if ( ! is_array( $icon_manager ) ) {
			return $result;
		}

		$incoming_sets     = self::sanitize_icon_sets_for_transfer( $icon_manager['sets'] ?? [] );
		$incoming_icons    = self::sanitize_custom_icons_for_transfer( $icon_manager['icons'] ?? [] );
		$incoming_disabled = self::sanitize_disabled_icon_sets_for_transfer( $icon_manager['disabledIconSets'] ?? [] );
		$local_sets        = self::sanitize_icon_sets_for_transfer( get_option( BRICKS_DB_ICON_SETS, [] ) );
		$local_icons       = self::sanitize_custom_icons_for_transfer( get_option( BRICKS_DB_CUSTOM_ICONS, [] ) );
		$local_disabled    = self::sanitize_disabled_icon_sets_for_transfer( get_option( BRICKS_DB_DISABLED_ICON_SETS, [] ) );
		$set_id_map        = [];

		foreach ( $incoming_sets as $incoming_set ) {
			$incoming_set_id = (string) ( $incoming_set['id'] ?? '' );

			if ( ! self::is_item_selected( $incoming_set_id, $selected_item_ids, false ) ) {
				continue;
			}

			$existing_set_index = self::find_list_index_by_text_field( $local_sets, 'name', $incoming_set['name'] ?? '' );

			if ( $existing_set_index === null ) {
				$existing_set_index = self::find_list_index_by_id( $local_sets, $incoming_set_id );
			}

			$item_conflict_mode = self::get_item_conflict_mode( $incoming_set_id, $conflict_mode, $conflict_decisions );
			$target_set_id      = $incoming_set_id;
			$status             = 'imported';

			if ( $existing_set_index !== null ) {
				if ( $item_conflict_mode !== 'replace' ) {
					$result['skipped']++;
					$result['items'][] = self::result_item( $incoming_set['name'] ?? esc_html__( 'Icon set', 'bricks' ), 'skipped' );
					continue;
				}

				$target_set_id                     = (string) ( $local_sets[ $existing_set_index ]['id'] ?? $incoming_set_id );
				$incoming_set['id']                = $target_set_id;
				$local_sets[ $existing_set_index ] = $incoming_set;
				$local_icons                       = array_values(
					array_filter(
						$local_icons,
						function( $icon ) use ( $target_set_id ) {
							return (string) ( $icon['setId'] ?? '' ) !== $target_set_id;
						}
					)
				);
				$status                            = 'replaced';
			} else {
				if ( self::id_exists_in_list( $local_sets, $target_set_id ) ) {
					$target_set_id      = 'set_' . substr( md5( uniqid( '', true ) ), 0, 10 );
					$incoming_set['id'] = $target_set_id;
				}

				$local_sets[] = $incoming_set;
			}

			$set_id_map[ $incoming_set_id ] = $target_set_id;

			foreach ( $incoming_icons as $incoming_icon ) {
				if ( (string) ( $incoming_icon['setId'] ?? '' ) !== $incoming_set_id ) {
					continue;
				}

				$imported_icon = self::import_custom_icon_from_zip( $zip, $incoming_icon, $target_set_id, $local_icons );

				if ( $imported_icon ) {
					$local_icons[] = $imported_icon;
				}
			}

			$local_disabled = self::apply_imported_icon_set_disabled_state( $local_disabled, $incoming_disabled, $incoming_set_id, $target_set_id );

			$result['imported']++;
			$result['items'][] = self::result_item( $incoming_set['name'] ?? esc_html__( 'Icon set', 'bricks' ), $status );
		}

		if ( self::is_item_selected( 'disabled-icon-sets', $selected_item_ids, false ) ) {
			$item_conflict_mode = self::get_item_conflict_mode( 'disabled-icon-sets', $conflict_mode, $conflict_decisions );

			if ( self::normalize_for_compare( $local_disabled ) !== self::normalize_for_compare( $incoming_disabled ) && $item_conflict_mode !== 'replace' ) {
				$result['skipped']++;
				$result['items'][] = self::result_item( esc_html__( 'Disabled icon sets', 'bricks' ), 'skipped' );
			} else {
				$local_disabled = array_map(
					function( $set_id ) use ( $set_id_map ) {
						return $set_id_map[ $set_id ] ?? $set_id;
					},
					$incoming_disabled
				);

				$result['imported']++;
				$result['items'][] = self::result_item( esc_html__( 'Disabled icon sets', 'bricks' ), 'imported' );
			}
		}

		self::update_icon_manager_options( $local_sets, $local_icons, $local_disabled );

		return $result;
	}

	private static function add_custom_icon_asset_to_zip( $zip, $icon ) {
		$attachment_id = intval( $icon['attachment_id'] ?? 0 );
		$file_path     = $attachment_id ? get_attached_file( $attachment_id ) : '';

		if ( ! $file_path || ! is_readable( $file_path ) || strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) !== 'svg' ) {
			return $icon;
		}

		$file_name = sanitize_file_name( basename( $file_path ) );
		$path      = sprintf(
			'styles/icon-manager/assets/%s/%s-%s',
			sanitize_file_name( $icon['setId'] ?? 'set' ),
			sanitize_file_name( $icon['id'] ?? 'icon' ),
			$file_name
		);

		$zip->addFile( $file_path, $path );

		$icon['asset'] = [
			'path'         => $path,
			'filename'     => $file_name,
			'mimeType'     => 'image/svg+xml',
			'attachmentId' => $attachment_id,
		];

		return $icon;
	}

	private static function import_custom_icon_from_zip( $zip, $icon, $target_set_id, $local_icons ) {
		$icon['setId'] = $target_set_id;

		if ( self::id_exists_in_list( $local_icons, $icon['id'] ?? '' ) ) {
			$icon['id'] = 'icon_' . substr( md5( uniqid( '', true ) ), 0, 10 );
		}

		$attachment = self::import_custom_icon_asset_from_zip( $zip, $icon['asset'] ?? [], $icon['name'] ?? '' );

		// A rejected packaged SVG must not fall back to an unverified URL/attachment.
		if ( isset( $icon['asset'] ) && ! $attachment ) {
			return null;
		}

		if ( $attachment ) {
			$icon['attachment_id'] = $attachment['id'];
			$icon['url']           = $attachment['url'];
		}

		unset( $icon['asset'] );

		return self::sanitize_custom_icon_for_transfer( $icon );
	}

	private static function import_custom_icon_asset_from_zip( $zip, $asset, $name ) {
		if ( ! is_array( $asset ) || empty( $asset['path'] ) ) {
			return null;
		}

		$path = (string) $asset['path'];

		if ( strpos( $path, '..' ) !== false ) {
			return null;
		}

		$file_stat = $zip->statName( $path );

		if ( ! is_array( $file_stat ) || ( ! empty( $file_stat['size'] ) && $file_stat['size'] > wp_max_upload_size() ) ) {
			return null;
		}

		$file_contents = $zip->getFromName( $path );

		if ( ! is_string( $file_contents ) || $file_contents === '' ) {
			return null;
		}

		$file_name = sanitize_file_name( $asset['filename'] ?? basename( $path ) );

		if ( strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) !== 'svg' ) {
			return null;
		}

		$svg = self::sanitize_svg_markup_for_import( $file_contents );

		if ( $svg === null ) {
			return null;
		}

		return self::create_svg_attachment_from_transfer( $svg, $name ? $name : $file_name );
	}

	private static function sanitize_svg_markup_for_import( $svg ) {
		$clean = Svg::sanitize_markup( $svg );
		return $clean === false ? null : $clean;
	}

	private static function create_svg_attachment_from_transfer( $svg, $name ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_name = sanitize_file_name( $name );

		if ( $file_name === '' ) {
			$file_name = 'bricks-custom-icon';
		}

		if ( substr( strtolower( $file_name ), -4 ) !== '.svg' ) {
			$file_name .= '.svg';
		}

		$upload = wp_upload_bits( $file_name, null, $svg );

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return null;
		}

		if ( ! Svg::file_matches_contents( $upload['file'], $svg ) ) {
			wp_delete_file( $upload['file'] );
			return null;
		}

		$attachment = [
			'post_title'     => pathinfo( $file_name, PATHINFO_FILENAME ),
			'post_mime_type' => 'image/svg+xml',
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		];

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $upload['file'] );
			return null;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );

		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return [
			'id'  => (int) $attachment_id,
			'url' => $upload['url'],
		];
	}

	private static function sanitize_icon_sets_for_transfer( $sets ) {
		$output = [];

		foreach ( (array) $sets as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}

			$id   = sanitize_text_field( $set['id'] ?? '' );
			$name = sanitize_text_field( $set['name'] ?? '' );

			if ( ! $id || ! $name ) {
				continue;
			}

			$row = [
				'id'   => $id,
				'name' => $name,
			];

			if ( isset( $set['backgroundColor'] ) && $set['backgroundColor'] !== '' ) {
				$row['backgroundColor'] = sanitize_text_field( $set['backgroundColor'] );
			}

			$output[] = $row;
		}

		return $output;
	}

	private static function sanitize_custom_icons_for_transfer( $icons ) {
		$output = [];

		foreach ( (array) $icons as $icon ) {
			$row = self::sanitize_custom_icon_for_transfer( $icon );

			if ( $row ) {
				$output[] = $row;
			}
		}

		return $output;
	}

	private static function sanitize_custom_icon_for_transfer( $icon ) {
		if ( ! is_array( $icon ) ) {
			return null;
		}

		$id     = sanitize_text_field( $icon['id'] ?? '' );
		$set_id = sanitize_text_field( $icon['setId'] ?? '' );
		$name   = sanitize_text_field( $icon['name'] ?? '' );
		$url    = esc_url_raw( $icon['url'] ?? '' );

		if ( ! $id || ! $set_id || ! $name || ! $url ) {
			return null;
		}

		$row = [
			'id'            => $id,
			'setId'         => $set_id,
			'name'          => $name,
			'url'           => $url,
			'attachment_id' => intval( $icon['attachment_id'] ?? 0 ),
		];

		if ( isset( $icon['asset'] ) && is_array( $icon['asset'] ) ) {
			$row['asset'] = $icon['asset'];
		}

		return $row;
	}

	private static function sanitize_disabled_icon_sets_for_transfer( $sets ) {
		$output = [];

		foreach ( (array) $sets as $set_id ) {
			$set_id = sanitize_text_field( $set_id );

			if ( $set_id ) {
				$output[ $set_id ] = true;
			}
		}

		return array_keys( $output );
	}

	private static function apply_imported_icon_set_disabled_state( $disabled_sets, $incoming_disabled_sets, $incoming_set_id, $target_set_id ) {
		$disabled_sets = array_values(
			array_filter(
				(array) $disabled_sets,
				function( $set_id ) use ( $target_set_id ) {
					return (string) $set_id !== (string) $target_set_id;
				}
			)
		);

		if ( in_array( (string) $incoming_set_id, array_map( 'strval', (array) $incoming_disabled_sets ), true ) ) {
			$disabled_sets[] = $target_set_id;
		}

		return self::sanitize_disabled_icon_sets_for_transfer( $disabled_sets );
	}

	private static function update_icon_manager_options( $icon_sets, $custom_icons, $disabled_icon_sets ) {
		$icon_sets          = array_values( $icon_sets );
		$custom_icons       = array_values( $custom_icons );
		$disabled_icon_sets = array_values( $disabled_icon_sets );

		if ( count( $icon_sets ) ) {
			update_option( BRICKS_DB_ICON_SETS, $icon_sets );
		} else {
			delete_option( BRICKS_DB_ICON_SETS );
		}

		if ( count( $custom_icons ) ) {
			update_option( BRICKS_DB_CUSTOM_ICONS, $custom_icons );
		} else {
			delete_option( BRICKS_DB_CUSTOM_ICONS );
		}

		if ( count( $disabled_icon_sets ) ) {
			update_option( BRICKS_DB_DISABLED_ICON_SETS, $disabled_icon_sets );
		} else {
			delete_option( BRICKS_DB_DISABLED_ICON_SETS );
		}
	}

	private static function get_icon_manager_categories() {
		return [
			[
				'id'   => 'custom-icon-sets',
				'name' => esc_html__( 'Custom icon sets', 'bricks' ),
			],
			[
				'id'   => 'settings',
				'name' => esc_html__( 'Settings', 'bricks' ),
			],
		];
	}

	private static function upsert_custom_font_post( $font_family, $original_post_id, $target_font = null ) {
		$post_data = [
			'post_title'  => $font_family ? $font_family : esc_html__( 'Custom font', 'bricks' ),
			'post_type'   => BRICKS_DB_CUSTOM_FONTS,
			'post_status' => 'publish',
		];

		if ( $target_font ) {
			$post_data['ID'] = $target_font->ID;
			$post_id         = wp_update_post( $post_data, true );
		} else {
			if ( $original_post_id && ! get_post( $original_post_id ) ) {
				$post_data['import_id'] = $original_post_id;
			}

			$post_id = wp_insert_post( $post_data, true );
		}

		return is_wp_error( $post_id ) ? 0 : intval( $post_id );
	}

	private static function import_custom_font_faces_from_zip( $zip, $font_faces, $post_id ) {
		$imported_font_faces = [];
		$imported_assets     = [];

		if ( ! is_array( $font_faces ) ) {
			return $imported_font_faces;
		}

		foreach ( $font_faces as $variant_key => $subsets ) {
			$subsets          = self::normalize_custom_font_face_subsets( $subsets );
			$imported_subsets = [];

			foreach ( $subsets as $subset ) {
				$imported_subset = [];

				foreach ( $subset as $format => $asset ) {
					if ( $format === 'unicode-range' ) {
						$imported_subset['unicode-range'] = sanitize_text_field( $asset );
						continue;
					}

					if ( $format === 'font-weight-range' ) {
						$font_weight_range = is_string( $asset ) ? Custom_Fonts::sanitize_font_weight_range( $asset ) : '';

						if ( $font_weight_range ) {
							$imported_subset['font-weight-range'] = $font_weight_range;
						}
						continue;
					}

					$attachment_id = self::import_custom_font_asset_from_zip( $zip, $asset, $format, $post_id, $imported_assets );

					if ( $attachment_id ) {
						$imported_subset[ $format ] = $attachment_id;
					}
				}

				if ( count( array_diff_key( $imported_subset, array_flip( [ 'unicode-range', 'font-weight-range' ] ) ) ) ) {
					$imported_subsets[] = $imported_subset;
				}
			}

			if ( count( $imported_subsets ) === 1 && empty( $imported_subsets[0]['unicode-range'] ) ) {
				$imported_font_faces[ sanitize_key( $variant_key ) ] = $imported_subsets[0];
			} elseif ( count( $imported_subsets ) ) {
				$imported_font_faces[ sanitize_key( $variant_key ) ] = $imported_subsets;
			}
		}

		return $imported_font_faces;
	}

	private static function import_custom_font_asset_from_zip( $zip, $asset, $format, $post_id, &$imported_assets ) {
		if ( ! is_array( $asset ) || empty( $asset['path'] ) ) {
			return 0;
		}

		$path = (string) $asset['path'];

		if ( strpos( $path, '..' ) !== false ) {
			return 0;
		}

		$file_stat = $zip->statName( $path );

		if ( ! is_array( $file_stat ) || ( ! empty( $file_stat['size'] ) && $file_stat['size'] > wp_max_upload_size() ) ) {
			return 0;
		}

		$file_contents = $zip->getFromName( $path );

		if ( $file_contents === false ) {
			return 0;
		}

		$allowed_types  = Custom_Fonts::get_public_custom_fonts_mime_types();
		$file_extension = strtolower( pathinfo( $asset['filename'] ?? $path, PATHINFO_EXTENSION ) );

		if ( ! isset( $allowed_types[ $file_extension ] ) || $file_extension !== strtolower( $format ) ) {
			return 0;
		}

		$asset_hash = $file_extension . ':' . hash( 'sha256', $file_contents );

		if ( isset( $imported_assets[ $asset_hash ] ) ) {
			return $imported_assets[ $asset_hash ];
		}

		$temp_file = wp_tempnam( 'bricks-custom-font-' );

		if ( ! $temp_file || file_put_contents( $temp_file, $file_contents ) === false ) {
			return 0;
		}

		$file_name = sanitize_file_name( $asset['filename'] ?? basename( $path ) );

		$file_array = [
			'name'     => $file_name,
			'tmp_name' => $temp_file,
			'type'     => $allowed_types[ $file_extension ],
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			return 0;
		}

		$attachment_id                  = intval( $attachment_id );
		$imported_assets[ $asset_hash ] = $attachment_id;

		return $attachment_id;
	}

	private static function load_media_file_helpers() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	private static function get_custom_font_posts( $post_status = null ) {
		return get_posts(
			[
				'post_type'      => BRICKS_DB_CUSTOM_FONTS,
				'post_status'    => $post_status ? $post_status : [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);
	}

	private static function get_custom_font_post_id_from_item_id( $item_id ) {
		if ( preg_match( '/^custom_font_(\d+)$/', (string) $item_id, $matches ) ) {
			return intval( $matches[1] );
		}

		return 0;
	}

	private static function find_custom_font_post_by_family( $font_posts, $font_family ) {
		$needle = self::normalize_compare_string( $font_family );

		if ( ! $needle ) {
			return null;
		}

		foreach ( $font_posts as $font_post ) {
			if ( self::normalize_compare_string( html_entity_decode( get_the_title( $font_post ), ENT_QUOTES, 'UTF-8' ) ) === $needle ) {
				return $font_post;
			}
		}

		return null;
	}

	private static function regenerate_custom_font_face_rules() {
		Custom_Fonts::$fonts           = false;
		Custom_Fonts::$font_face_rules = '';

		Custom_Fonts::get_custom_fonts();

		if ( Custom_Fonts::$font_face_rules ) {
			update_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES, Custom_Fonts::$font_face_rules );
		} else {
			delete_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES );
		}
	}

	private static function build_template_export( $template_id ) {
		if ( ! $template_id ) {
			return null;
		}

		$template_data = Templates::get_template_by_id( $template_id );

		if ( empty( $template_data ) ) {
			return null;
		}

		$template_type = get_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, true );

		$template_data['templateType'] = $template_type;

		if ( $template_type === 'header' || $template_type === 'footer' ) {
			$template_elements = $template_data[ $template_type ] ?? [];
		} else {
			$template_elements = $template_data['content'] ?? [];
		}

		$template_settings = Helpers::get_template_settings( $template_id );

		if ( isset( $template_settings['templateConditions'] ) ) {
			unset( $template_settings['templateConditions'] );
		}

		if ( is_array( $template_settings ) && ! empty( $template_settings ) ) {
			$template_data['templateSettings'] = $template_settings;
		}

		$dependencies = self::build_transfer_dependencies_for_elements( $template_elements );

		$components = self::redact_code_sensitive_components( self::collect_components_from_elements( $template_elements ) );

		if ( count( $components ) ) {
			$template_data['components'] = self::unique_components_by_id( $components );
		}

		if ( ! empty( $dependencies['globalClasses'] ) ) {
			$template_data['global_classes'] = $dependencies['globalClasses'];
		}

		if ( ! empty( $dependencies['globalVariables'] ) ) {
			$template_data['globalVariables'] = $dependencies['globalVariables'];
		}

		if ( ! empty( $dependencies['globalVariablesCategories'] ) ) {
			$template_data['globalVariablesCategories'] = $dependencies['globalVariablesCategories'];
		}

		$template_data = self::redact_code_sensitive_template_data( $template_data );

		$file_name = ! empty( $template_data['title'] ) ? strtolower( $template_data['title'] ) : 'no-title';
		$file_name = preg_replace( '/[^a-z0-9_\s-]/', '', $file_name );
		$file_name = preg_replace( '/[\s-]+/', ' ', $file_name );
		$file_name = preg_replace( '/[\s_]/', '-', $file_name );
		$file_name = 'template-' . $file_name . '-' . date( 'Y-m-d' ) . '.json';

		return [
			'name'    => $file_name,
			'content' => wp_json_encode( $template_data ),
		];
	}

	/**
	 * Get editable template IDs for transfer export.
	 *
	 * @since 2.4
	 *
	 * @return int[]
	 */
	private static function get_exportable_template_ids() {
		$template_post_type = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		$template_ids       = get_posts(
			[
				'post_type'      => $template_post_type,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		return array_values(
			array_filter(
				array_map( 'intval', is_array( $template_ids ) ? $template_ids : [] ),
				function( $template_id ) {
					return current_user_can( 'edit_post', $template_id );
				}
			)
		);
	}

	/**
	 * Redact code-sensitive component payloads when the user cannot execute code.
	 *
	 * @since 2.4
	 *
	 * @param array $components Component rows.
	 * @return array
	 */
	private static function redact_code_sensitive_components( $components ) {
		$components = is_array( $components ) ? $components : [];

		foreach ( $components as $index => $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			if ( is_array( $component['elements'] ?? null ) && ! self::current_user_can_handle_code_sensitive_payload() ) {
				$component['elements'] = \Bricks\Abilities\Elements::redact_code_sensitive_elements( $component['elements'] );
			}
			if ( is_array( $component['properties'] ?? null ) ) {
				$component['properties'] = \Bricks\Abilities\Elements::redact_component_code_defaults( $component['properties'] );
			}
			$components[ $index ] = $component;
		}

		return $components;
	}

	/**
	 * Redact code-sensitive template element payloads when needed.
	 *
	 * @since 2.4
	 *
	 * @param array $template_data Template export data.
	 * @return array
	 */
	private static function redact_code_sensitive_template_data( $template_data ) {
		$template_data = is_array( $template_data ) ? $template_data : [];

		if ( self::current_user_can_handle_code_sensitive_payload() ) {
			return $template_data;
		}

		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( is_array( $template_data[ $area ] ?? null ) ) {
				$template_data[ $area ] = \Bricks\Abilities\Elements::redact_code_sensitive_elements( $template_data[ $area ] );
			}
		}

		if ( is_array( $template_data['components'] ?? null ) ) {
			$template_data['components'] = self::redact_code_sensitive_components( $template_data['components'] );
		}

		return $template_data;
	}

	/**
	 * Validate all selected executable payloads before the first import write.
	 *
	 * @since 2.4
	 *
	 * @param \ZipArchive $zip      Open transfer ZIP.
	 * @param array       $manifest Transfer manifest.
	 * @param string[]    $types    Selected transfer types.
	 * @param array       $items    Selected item IDs keyed by type.
	 * @return true|\WP_Error
	 */
	private static function preflight_code_sensitive_import( $zip, array $manifest, array $types, array $items ) {
		foreach ( $types as $type ) {
			$selected_item_ids = $items[ $type ] ?? [];
			$type_manifest     = is_array( $manifest['types'][ $type ] ?? null ) ? $manifest['types'][ $type ] : [];

			if ( $type === 'global-queries' ) {
				$queries = self::read_json_from_zip( $zip, 'structure/global-queries/queries.json' );

				foreach ( (array) $queries as $query ) {
					if (
						self::is_item_selected( $query['id'] ?? '', $selected_item_ids, false ) &&
						self::global_query_contains_code_sensitive_payload( $query ) && ! \Bricks\Abilities\Elements::can_author_php()
					) {
						return \Bricks\Abilities\Error::code_sensitive_write_forbidden();
					}
				}

				continue;
			}

			if ( $type === 'components' ) {
				foreach ( (array) ( $type_manifest['items'] ?? [] ) as $item ) {
					if ( ! self::is_item_selected( $item['id'] ?? '', $selected_item_ids, false ) ) {
						continue;
					}

					$data       = self::read_json_from_zip( $zip, $item['path'] ?? '' );
					$components = is_array( $data['components'] ?? null ) ? $data['components'] : [];
					$components = Components::upgrade_components( $components, true );
					$code_check = self::check_components_code_permissions( $components );

					if ( is_wp_error( $code_check ) ) {
						return $code_check;
					}
				}

				continue;
			}

			if ( $type !== 'templates' ) {
				continue;
			}

			foreach ( (array) ( $type_manifest['items'] ?? [] ) as $item ) {
				if ( ! self::is_item_selected( $item['id'] ?? '', $selected_item_ids, false ) ) {
					continue;
				}

				$template = self::read_json_from_zip( $zip, $item['path'] ?? '' );

				if ( ! is_array( $template ) ) {
					continue;
				}

				if ( is_array( $template['components'] ?? null ) ) {
					$template['components'] = Components::upgrade_components( $template['components'], true );
				}

				$code_check = self::check_template_data_code_permissions( $template );

				if ( is_wp_error( $code_check ) ) {
					return $code_check;
				}
			}
		}

		return true;
	}

	/**
	 * Sign imported Query Editor bytes only after the final import payload is
	 * assembled and the caller remains explicitly authorized for PHP abilities.
	 *
	 * @param array $query Imported query.
	 * @return array|\WP_Error
	 */
	private static function prepare_global_query_for_import( array $query ) {
		if ( self::global_query_contains_code_sensitive_payload( $query ) && ! \Bricks\Abilities\Elements::can_author_php() ) {
			return \Bricks\Abilities\Error::code_sensitive_write_forbidden();
		}

		if ( is_array( $query['settings'] ?? null ) ) {
			$query['settings'] = \Bricks\Abilities\Elements::sign_php_settings( $query['settings'], 'queryEditor' );
		}

		return \Bricks\Abilities\Elements::sign_php_settings( $query, 'queryEditor' );
	}

	/**
	 * Apply final code signing to imported components after all ID remapping.
	 *
	 * @param array $existing_components Existing component rows.
	 * @param array $components          Imported component rows.
	 * @return array|\WP_Error
	 */
	private static function prepare_components_code_for_import( array $existing_components, array $components ) {
		foreach ( $components as $index => $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			$permission = self::check_components_code_permissions( [ $component ] );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			$existing_index        = self::find_list_index_by_id( $existing_components, $component['id'] ?? '' );
			$existing              = $existing_index === null ? [] : $existing_components[ $existing_index ];
			$component['elements'] = \Bricks\Abilities\Elements::sign_authorized_code(
				is_array( $existing['elements'] ?? null ) ? $existing['elements'] : [],
				is_array( $component['elements'] ?? null ) ? $component['elements'] : []
			);
			$properties            = \Bricks\Abilities\Elements::prepare_component_code_defaults(
				is_array( $existing['properties'] ?? null ) ? $existing['properties'] : [],
				is_array( $component['properties'] ?? null ) ? $component['properties'] : []
			);
			if ( is_wp_error( $properties ) ) {
				return $properties;
			}
			$component['properties'] = $properties;
			$components[ $index ]    = $component;
		}

		$permission = self::check_components_code_permissions( $components );

		return is_wp_error( $permission ) ? $permission : $components;
	}

	/**
	 * Prepare embedded component code after import normalization.
	 *
	 * @param array $template Imported template data.
	 * @return array|\WP_Error
	 */
	private static function prepare_template_code_for_import( array $template ) {
		if ( is_array( $template['components'] ?? null ) ) {
			$components = self::prepare_components_code_for_import( [], $template['components'] );
			if ( is_wp_error( $components ) ) {
				return $components;
			}
			$template['components'] = $components;
		}

		$permission = self::check_template_data_code_permissions( $template );

		return is_wp_error( $permission ) ? $permission : $template;
	}

	/**
	 * Reject component imports with executable payloads for this user.
	 *
	 * @since 2.4
	 *
	 * @param array $components Component rows.
	 * @return true|\WP_Error
	 */
	private static function check_components_code_permissions( $components ) {
		foreach ( ( is_array( $components ) ? $components : [] ) as $component ) {
			if (
				is_array( $component ) &&
				(
					self::elements_contain_code_sensitive_payload( $component['elements'] ?? [] ) ||
					self::component_properties_contain_code_sensitive_payload( $component['properties'] ?? [], $component['elements'] ?? [] )
				)
			) {
				return self::code_permission_error();
			}
		}

		return true;
	}

	/**
	 * Whether imported component property definitions can author executable data.
	 *
	 * @since 2.4
	 *
	 * @param mixed $properties Component property definitions.
	 * @param mixed $elements   Component element rows.
	 * @return bool
	 */
	private static function component_properties_contain_code_sensitive_payload( $properties, $elements = [] ) {
		if ( ! is_array( $properties ) ) {
			return false;
		}

		$element_names = [];

		foreach ( ( is_array( $elements ) ? $elements : [] ) as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$element_names[ (string) $element['id'] ] = (string) ( $element['name'] ?? '' );
			}
		}

		foreach ( $properties as $property ) {
			if ( ! is_array( $property ) ) {
				continue;
			}

			$property_type = (string) ( $property['type'] ?? '' );
			$default       = $property['default'] ?? [];
			if ( self::array_contains_code_sensitive_keys( $property['options'] ?? [] ) ) {
				return true;
			}
			if ( self::array_contains_code_sensitive_keys( $default ) ) {
				if ( $property_type !== 'query' || ! \Bricks\Abilities\Elements::can_author_php() ) {
					return true;
				}
			}

			foreach ( (array) ( $property['connections'] ?? [] ) as $element_id => $control_keys ) {
				foreach ( (array) $control_keys as $control_key ) {
					$parts                 = explode( ':', (string) $control_key );
					$setting_key           = $parts[0];
					$sub_key               = $parts[1] ?? '';
					$is_allowed_svg_source =
						( $element_names[ (string) $element_id ] ?? '' ) === 'svg' &&
						$setting_key === 'code' &&
						$sub_key === '' &&
						self::current_user_can_handle_code_sensitive_payload();

					if (
						count( $parts ) > 2 ||
						( ! $is_allowed_svg_source && in_array( $setting_key, [ 'executeCode', 'code', 'cssCode', 'javascriptCode', 'useDynamicData', 'parseDynamicData', 'supressPhpErrors', 'noRoot', 'noRootForce', 'signature' ], true ) ) ||
						( $setting_key === 'query' && ( $sub_key === '' ? $property_type !== 'query' : in_array( explode( '|', $sub_key )[0], [ 'useQueryEditor', 'queryEditor', 'signature' ], true ) ) )
					) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Whether a nested value contains executable setting keys.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Value tree.
	 * @return bool
	 */
	private static function array_contains_code_sensitive_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $key => $item ) {
			if ( in_array( (string) $key, [ 'executeCode', 'code', 'cssCode', 'javascriptCode', 'useDynamicData', 'parseDynamicData', 'supressPhpErrors', 'noRoot', 'noRootForce', 'queryEditor', 'useQueryEditor', 'signature' ], true ) && $item !== '' && $item !== null && $item !== false && $item !== [] ) {
				return true;
			}

			if ( is_array( $item ) && self::array_contains_code_sensitive_keys( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reject template imports with executable payloads for this user.
	 *
	 * @since 2.4
	 *
	 * @param array $template_data Template import data.
	 * @return true|\WP_Error
	 */
	private static function check_template_data_code_permissions( $template_data ) {
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( self::elements_contain_code_sensitive_payload( $template_data[ $area ] ?? [] ) ) {
				return self::code_permission_error();
			}
		}

		$component_check = self::check_components_code_permissions( $template_data['components'] ?? [] );

		if ( is_wp_error( $component_check ) ) {
			return $component_check;
		}

		$page_settings = is_array( $template_data['pageSettings'] ?? null ) ? $template_data['pageSettings'] : [];

		foreach ( [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ] as $script_key ) {
			if ( ! empty( $page_settings[ $script_key ] ) ) {
				return \Bricks\Abilities\Error::code_sensitive_write_forbidden();
			}
		}

		return true;
	}

	/**
	 * Whether a global query carries executable Query Editor settings.
	 *
	 * @since 2.4
	 *
	 * @param mixed $query Global query row.
	 * @return bool
	 */
	private static function global_query_contains_code_sensitive_payload( $query ) {
		if ( ! is_array( $query ) ) {
			return false;
		}

		$levels = [ $query ];

		if ( is_array( $query['settings'] ?? null ) ) {
			$levels[] = $query['settings'];
		}

		foreach ( $levels as $level ) {
			foreach ( [ 'useQueryEditor', 'queryEditor', 'signature' ] as $key ) {
				if ( ! empty( $level[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether element data contains executable or otherwise code-sensitive payloads.
	 *
	 * @since 2.4
	 *
	 * @param mixed $elements Element rows.
	 * @return bool
	 */
	private static function elements_contain_code_sensitive_payload( $elements ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( \Bricks\Abilities\Elements::code_sensitive_payload_for_current_user( $element ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current user may read/write code-sensitive element payloads.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private static function current_user_can_handle_code_sensitive_payload() {
		return class_exists( '\Bricks\Capabilities' ) && Capabilities::current_user_can_execute_code();
	}

	/**
	 * Standard forbidden response for code-sensitive transfer payloads.
	 *
	 * @since 2.4
	 *
	 * @return \WP_Error
	 */
	private static function code_permission_error() {
		$capability = defined( '\Bricks\Capabilities::EXECUTE_CODE' ) ? Capabilities::EXECUTE_CODE : 'execute_code';

		return \Bricks\Abilities\Error::forbidden_builder_permission( $capability );
	}

	private static function get_component_label( $component ) {
		if ( ! empty( $component['label'] ) ) {
			return $component['label'];
		}

		if ( ! empty( $component['elements'][0]['label'] ) ) {
			return $component['elements'][0]['label'];
		}

		return esc_html__( 'Component', 'bricks' );
	}

	private static function user_can_access_type( $type, $context ) {
		switch ( $type ) {
			case 'color-palettes':
				return Builder_Permissions::user_has_permission( 'edit_color_palettes' );
			case 'theme-styles':
				return Builder_Permissions::user_has_permission( 'access_theme_styles' );
			case 'classes':
				if ( $context === 'import' ) {
					return Builder_Permissions::user_has_permission( 'access_class_manager' ) &&
						(
							Builder_Permissions::user_has_permission( 'create_global_classes' ) ||
							Builder_Permissions::user_has_permission( 'edit_global_classes' )
						);
				}
				return Builder_Permissions::user_has_permission( 'access_class_manager' );
			case 'variables':
				return Builder_Permissions::user_has_permission( 'access_variable_manager' );
			case 'custom-fonts':
				return Builder_Permissions::user_has_permission( 'access_font_manager' ) &&
					( $context !== 'import' || current_user_can( 'upload_files' ) );
			case 'icon-manager':
				return Builder_Permissions::user_has_permission( 'access_icon_manager' ) &&
					(
						$context !== 'import' ||
						(
							current_user_can( 'upload_files' ) &&
							( ! class_exists( '\Bricks\Capabilities' ) || Capabilities::current_user_can_upload_svg() )
						)
					);
			case 'breakpoints':
				return Builder_Permissions::user_has_permission( 'access_breakpoints_manager' );
			case 'global-queries':
				return Builder_Permissions::user_has_permission( 'access_query_manager' );
			case 'components':
				return Builder_Permissions::user_has_permission( 'import_export_components' );
			case 'templates':
				return Builder_Permissions::user_has_permission( 'import_export_templates' );
			case 'settings':
				return current_user_can( 'manage_options' );
			case 'builder-interface':
				return ! Builder::builder_ui_customiser_disabled() && Builder_Permissions::user_has_permission( 'manage_builder_interface_profiles' );
			case 'custom-capabilities':
				return current_user_can( 'manage_options' );
		}

		return false;
	}

	/**
	 * Get custom capabilities for transfer.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_custom_capabilities_for_transfer() {
		$capabilities = get_option( BRICKS_DB_CAPABILITIES_PERMISSIONS, [] );
		$capabilities = array_diff_key( is_array( $capabilities ) ? $capabilities : [], Builder_Permissions::DEFAULT_CAPABILITIES );
		$items        = [];

		foreach ( $capabilities as $id => $capability ) {
			if ( empty( $id ) || ! is_array( $capability ) ) {
				continue;
			}

			$items[] = [
				'id'          => sanitize_key( $id ),
				'label'       => sanitize_text_field( $capability['label'] ?? $id ),
				'description' => sanitize_textarea_field( $capability['description'] ?? '' ),
				'permissions' => array_values( array_unique( array_map( 'sanitize_text_field', is_array( $capability['permissions'] ?? null ) ? $capability['permissions'] : [] ) ) ),
			];
		}

		return $items;
	}

	/**
	 * Filter custom capabilities by selected IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $capabilities Custom capabilities.
	 * @param array $selected_ids Selected capability IDs.
	 *
	 * @return array
	 */
	private static function filter_capabilities_by_ids( $capabilities, $selected_ids ) {
		$selected_ids = array_values( array_filter( array_map( 'strval', is_array( $selected_ids ) ? $selected_ids : [] ) ) );

		if ( empty( $selected_ids ) ) {
			return $capabilities;
		}

		return array_values(
			array_filter(
				$capabilities,
				function( $capability ) use ( $selected_ids ) {
					return in_array( (string) ( $capability['id'] ?? '' ), $selected_ids, true );
				}
			)
		);
	}

	/**
	 * Sanitize a custom capability before import.
	 *
	 * @since 2.4
	 *
	 * @param array $capability Custom capability data.
	 *
	 * @return array
	 */
	private static function sanitize_custom_capability_for_import( $capability ) {
		return [
			'id'          => sanitize_key( $capability['id'] ?? '' ),
			'label'       => sanitize_text_field( $capability['label'] ?? '' ),
			'description' => sanitize_textarea_field( $capability['description'] ?? '' ),
			'permissions' => array_values( array_unique( array_map( 'sanitize_text_field', is_array( $capability['permissions'] ?? null ) ? $capability['permissions'] : [] ) ) ),
		];
	}

	private static function read_json_from_zip( $zip, $path ) {
		$contents = self::read_json_string_from_zip( $zip, $path );

		if ( is_wp_error( $contents ) || $contents === null ) {
			return null;
		}

		return json_decode( $contents, true );
	}

	private static function read_json_string_from_zip( $zip, $path ) {
		$path = (string) $path;

		if ( strpos( $path, '..' ) !== false ) {
			return null;
		}

		$file_stat = $zip->statName( $path );

		if ( ! is_array( $file_stat ) ) {
			return null;
		}

		if ( ! empty( $file_stat['size'] ) && $file_stat['size'] > wp_max_upload_size() ) {
			return new \WP_Error( 'zip_json_too_large', esc_html__( 'The ZIP file contains JSON data that exceeds the maximum upload size.', 'bricks' ) );
		}

		$contents = $zip->getFromName( $path );

		if ( $contents === false ) {
			return null;
		}

		return $contents;
	}

	private static function is_item_selected( $item_id, $selected_item_ids, $singleton = false ) {
		return $singleton ? self::is_singleton_selected( $selected_item_ids ) : in_array( (string) $item_id, array_map( 'strval', (array) $selected_item_ids ), true );
	}

	private static function is_singleton_selected( $selected_item_ids ) {
		if ( empty( $selected_item_ids ) ) {
			return true;
		}

		$selected_item_ids = array_map( 'strval', (array) $selected_item_ids );

		return in_array( 'all', $selected_item_ids, true ) || in_array( 'settings', $selected_item_ids, true );
	}

	private static function normalize_compare_string( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	private static function id_exists_in_list( $items, $id ) {
		foreach ( (array) $items as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === (string) $id ) {
				return true;
			}
		}

		return false;
	}

	private static function find_list_index_by_id( $items, $id ) {
		foreach ( (array) $items as $index => $item ) {
			if ( (string) ( $item['id'] ?? '' ) === (string) $id ) {
				return $index;
			}
		}

		return null;
	}

	private static function find_list_index_by_text_field( $items, $field, $value ) {
		$needle = self::normalize_compare_string( $value );

		if ( ! $needle ) {
			return null;
		}

		foreach ( (array) $items as $index => $item ) {
			if ( self::normalize_compare_string( $item[ $field ] ?? '' ) === $needle ) {
				return $index;
			}
		}

		return null;
	}

	private static function get_color_palette_category_id( $palette, $index ) {
		return (string) ( $palette['id'] ?? "palette-$index" );
	}

	private static function get_color_transfer_item_id( $palette, $color, $palette_index, $color_index ) {
		return self::get_color_palette_category_id( $palette, $palette_index ) . ':' . (string) ( $color['id'] ?? $color_index );
	}

	private static function get_color_transfer_label( $color ) {
		if ( ! empty( $color['name'] ) ) {
			return $color['name'];
		}

		if ( ! empty( $color['raw'] ) && strpos( $color['raw'], 'var(' ) === 0 ) {
			return preg_replace( '/^var\(--|\\)$/', '', $color['raw'] );
		}

		if ( ! empty( $color['raw'] ) ) {
			return $color['raw'];
		}

		return $color['light'] ?? ( $color['dark'] ?? esc_html__( 'Color', 'bricks' ) );
	}

	private static function matches_color_manifest_item( $item, $color, $color_index ) {
		$item_color_id    = (string) ( $item['colorId'] ?? '' );
		$item_color_index = isset( $item['colorIndex'] ) ? (int) $item['colorIndex'] : -1;
		$color_id         = (string) ( $color['id'] ?? '' );

		if ( $item_color_id && $item_color_id === $color_id ) {
			return true;
		}

		return $item_color_index === $color_index;
	}

	private static function find_palette_index_by_name( $palettes, $name ) {
		$needle = self::normalize_compare_string( $name );

		if ( ! $needle ) {
			return null;
		}

		foreach ( array_values( (array) $palettes ) as $index => $palette ) {
			if ( strtolower( trim( (string) ( $palette['name'] ?? '' ) ) ) === $needle ) {
				return $index;
			}
		}

		return null;
	}

	private static function find_color_location_by_raw( $palettes, $raw ) {
		$needle = self::normalize_compare_string( $raw );

		if ( ! $needle ) {
			return null;
		}

		foreach ( (array) $palettes as $palette_index => $palette ) {
			foreach ( (array) ( $palette['colors'] ?? [] ) as $color_index => $color ) {
				if ( self::normalize_compare_string( $color['raw'] ?? '' ) === $needle ) {
					return [
						'paletteIndex' => $palette_index,
						'colorIndex'   => $color_index,
					];
				}
			}
		}

		return null;
	}

	private static function color_id_exists_in_palettes( $palettes, $id ) {
		foreach ( (array) $palettes as $palette ) {
			foreach ( (array) ( $palette['colors'] ?? [] ) as $color ) {
				if ( (string) ( $color['id'] ?? '' ) === (string) $id ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function color_raw_exists_in_palettes( $palettes, $raw ) {
		foreach ( (array) $palettes as $palette ) {
			foreach ( (array) ( $palette['colors'] ?? [] ) as $color ) {
				if ( self::normalize_compare_string( $color['raw'] ?? '' ) === self::normalize_compare_string( $raw ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function find_theme_style_key( $styles, $label, $style_id = '' ) {
		foreach ( (array) $styles as $existing_style_id => $style ) {
			if ( (string) $existing_style_id === (string) $style_id ) {
				return $existing_style_id;
			}
		}

		$needle = self::normalize_compare_string( $label );

		if ( ! $needle ) {
			return null;
		}

		foreach ( (array) $styles as $existing_style_id => $style ) {
			if ( self::normalize_compare_string( $style['label'] ?? '' ) === $needle ) {
				return $existing_style_id;
			}
		}

		return null;
	}

	private static function merge_categories( &$existing_categories, $incoming_categories ) {
		$existing_categories = is_array( $existing_categories ) ? array_values( $existing_categories ) : [];
		$incoming_categories = is_array( $incoming_categories ) ? $incoming_categories : [];
		$remap               = [];

		foreach ( $incoming_categories as $category ) {
			$incoming_id   = $category['id'] ?? Helpers::generate_random_id( false );
			$incoming_name = strtolower( trim( $category['name'] ?? '' ) );

			if ( ! $incoming_name ) {
				continue;
			}

			$matched = null;

			foreach ( $existing_categories as $existing_category ) {
				if ( strtolower( trim( $existing_category['name'] ?? '' ) ) === $incoming_name ) {
					$matched = $existing_category;
					break;
				}
			}

			if ( $matched ) {
				$remap[ $incoming_id ] = $matched['id'];
				continue;
			}

			$new_id = $incoming_id;

			if ( self::id_exists_in_list( $existing_categories, $new_id ) ) {
				$new_id = Helpers::generate_random_id( false );
			}

			$category['id']        = $new_id;
			$existing_categories[] = $category;
			$remap[ $incoming_id ] = $new_id;
		}

		return $remap;
	}

	private static function normalize_for_compare( $value ) {
		return wp_json_encode( $value );
	}

	private static function sanitize_breakpoints_for_import( $breakpoints ) {
		if ( ! is_array( $breakpoints ) || empty( $breakpoints ) ) {
			return new \WP_Error( 'invalid_breakpoints', esc_html__( 'Breakpoint data is invalid.', 'bricks' ) );
		}

		$sanitized      = [];
		$seen_keys      = [];
		$seen_widths    = [];
		$seen_builders  = [];
		$base_key       = '';
		$base_found     = false;
		$default_by_key = [];

		foreach ( Breakpoints::get_default_breakpoints() as $default_breakpoint ) {
			$default_by_key[ $default_breakpoint['key'] ] = $default_breakpoint;
		}

		foreach ( $breakpoints as $breakpoint ) {
			if ( ! is_array( $breakpoint ) ) {
				continue;
			}

			$key = strtolower( (string) ( $breakpoint['key'] ?? '' ) );
			$key = str_replace( [ '-', ' ' ], '_', $key );
			$key = sanitize_key( $key );

			if ( ! $key || isset( $seen_keys[ $key ] ) ) {
				return new \WP_Error( 'invalid_breakpoint_key', esc_html__( 'Breakpoint data is invalid.', 'bricks' ) );
			}

			$width = intval( $breakpoint['width'] ?? 0 );

			if ( $width <= 0 || isset( $seen_widths[ $width ] ) ) {
				return new \WP_Error( 'invalid_breakpoint_width', esc_html__( 'Breakpoint data is invalid.', 'bricks' ) );
			}

			$label = sanitize_text_field( $breakpoint['label'] ?? '' );
			$icon  = sanitize_text_field( $breakpoint['icon'] ?? '' );

			if ( ! $label ) {
				$label = $default_by_key[ $key ]['label'] ?? ucwords( str_replace( '_', ' ', $key ) );
			}

			if ( ! $icon ) {
				$icon = $default_by_key[ $key ]['icon'] ?? 'laptop';
			}

			$sanitized_breakpoint = [
				'key'   => $key,
				'label' => $label,
				'width' => $width,
				'icon'  => $icon,
			];

			if ( isset( $breakpoint['widthBuilder'] ) ) {
				$width_builder = intval( $breakpoint['widthBuilder'] );

				if ( $width_builder > 0 ) {
					if ( isset( $seen_builders[ $width_builder ] ) ) {
						return new \WP_Error( 'invalid_breakpoint_width_builder', esc_html__( 'Breakpoint data is invalid.', 'bricks' ) );
					}

					$sanitized_breakpoint['widthBuilder'] = $width_builder;
					$seen_builders[ $width_builder ]      = true;
				}
			}

			foreach ( [ 'custom', 'edited', 'paused' ] as $flag ) {
				if ( ! empty( $breakpoint[ $flag ] ) ) {
					$sanitized_breakpoint[ $flag ] = true;
				}
			}

			if ( ! empty( $breakpoint['base'] ) ) {
				if ( $base_found ) {
					unset( $sanitized_breakpoint['base'] );
				} else {
					$sanitized_breakpoint['base'] = true;
					$base_key                     = $key;
					$base_found                   = true;
				}
			}

			$seen_keys[ $key ]     = true;
			$seen_widths[ $width ] = true;
			$sanitized[]           = $sanitized_breakpoint;
		}

		if ( empty( $sanitized ) ) {
			return new \WP_Error( 'invalid_breakpoints', esc_html__( 'Breakpoint data is invalid.', 'bricks' ) );
		}

		if ( ! $base_found ) {
			$base_key = isset( $seen_keys['desktop'] ) ? 'desktop' : ( $sanitized[0]['key'] ?? '' );

			foreach ( $sanitized as &$breakpoint ) {
				if ( $breakpoint['key'] === $base_key ) {
					$breakpoint['base'] = true;
					break;
				}
			}

			unset( $breakpoint );
		}

		$widths = array_column( $sanitized, 'width' );
		array_multisort( $widths, SORT_DESC, $sanitized );

		return array_values( $sanitized );
	}

	private static function breakpoints_match_default( $breakpoints ) {
		$default_breakpoints = self::sanitize_breakpoints_for_import( Breakpoints::get_default_breakpoints() );

		if ( is_wp_error( $default_breakpoints ) || is_wp_error( $breakpoints ) ) {
			return false;
		}

		return self::normalize_breakpoints_for_default_compare( $breakpoints ) === self::normalize_breakpoints_for_default_compare( $default_breakpoints );
	}

	private static function get_breakpoints_transfer_meta( $breakpoints ) {
		if ( is_wp_error( $breakpoints ) || ! is_array( $breakpoints ) || empty( $breakpoints ) ) {
			return '';
		}

		$base_index = 0;

		foreach ( $breakpoints as $index => $breakpoint ) {
			if ( ! empty( $breakpoint['base'] ) ) {
				$base_index = $index;
				break;
			}
		}

		$base_breakpoint = $breakpoints[ $base_index ];
		$base_label      = $base_breakpoint['label'] ?? esc_html__( 'Base', 'bricks' );
		$widths          = array_map( 'intval', array_column( $breakpoints, 'width' ) );
		$is_mobile_first = ! empty( $base_breakpoint['width'] ) && ! empty( $widths ) && intval( $base_breakpoint['width'] ) === min( $widths );
		$mode_label      = $is_mobile_first ? esc_html__( 'Mobile first', 'bricks' ) : esc_html__( 'Desktop first', 'bricks' );

		return sprintf(
			/* translators: 1: breakpoint count, 2: base breakpoint label, 3: breakpoint mode */
			esc_html__( '%1$d - %2$s - %3$s', 'bricks' ),
			count( $breakpoints ),
			$base_label,
			$mode_label
		);
	}

	private static function normalize_breakpoints_for_default_compare( $breakpoints ) {
		return array_map(
			function( $breakpoint ) {
				return [
					'key'          => $breakpoint['key'] ?? '',
					'width'        => intval( $breakpoint['width'] ?? 0 ),
					'widthBuilder' => intval( $breakpoint['widthBuilder'] ?? 0 ),
					'base'         => ! empty( $breakpoint['base'] ),
					'custom'       => ! empty( $breakpoint['custom'] ),
					'edited'       => ! empty( $breakpoint['edited'] ),
					'paused'       => ! empty( $breakpoint['paused'] ),
				];
			},
			array_values( $breakpoints )
		);
	}

	private static function set_custom_breakpoints_enabled( $enabled ) {
		$settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		$settings = is_array( $settings ) ? $settings : [];

		if ( $enabled ) {
			$settings['customBreakpoints'] = true;
		} else {
			unset( $settings['customBreakpoints'] );
		}

		update_option( BRICKS_DB_GLOBAL_SETTINGS, $settings );
		Database::$global_settings = $settings;
	}

	private static function build_transfer_dependencies_for_elements( $elements, $components = [] ) {
		$dependencies = [];
		$class_ids    = self::collect_global_class_ids_from_elements( $elements );

		foreach ( self::collect_components_from_elements( $elements ) as $component ) {
			$components[] = $component;
		}

		foreach ( $components as $component ) {
			$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_elements( $component['elements'] ?? [] ) );
			$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_component_properties( [ $component ] ) );
		}

		$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_component_instances( $elements, $components ) );

		$class_ids = array_values( array_unique( array_filter( array_map( 'strval', $class_ids ) ) ) );

		if ( count( $class_ids ) ) {
			$global_classes = [];

			foreach ( get_option( BRICKS_DB_GLOBAL_CLASSES, [] ) as $global_class ) {
				if ( in_array( (string) ( $global_class['id'] ?? '' ), $class_ids, true ) ) {
					$global_classes[] = $global_class;
				}
			}

			if ( count( $global_classes ) ) {
				$dependencies['globalClasses'] = Helpers::add_category_metadata_to_classes( $global_classes );
			}
		}

		if ( ! Database::get_setting( 'disableVariablesManager', false ) ) {
			$global_variables      = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
			$global_variable_names = self::collect_global_variable_names_from_data(
				[
					'elements'      => $elements,
					'components'    => $components,
					'globalClasses' => $dependencies['globalClasses'] ?? [],
				]
			);
			$global_variables      = self::filter_global_variables_by_names( $global_variables, $global_variable_names );

			if ( count( $global_variables ) ) {
				$dependencies['globalVariables'] = $global_variables;
			}

			$global_variables_categories = self::filter_categories_for_items(
				$global_variables,
				get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] )
			);

			if ( count( $global_variables_categories ) ) {
				$dependencies['globalVariablesCategories'] = $global_variables_categories;
			}
		}

		return $dependencies;
	}

	private static function collect_global_variable_names_from_data( $data ) {
		$names = [];

		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				$names = array_merge( $names, self::collect_global_variable_names_from_data( $item ) );
			}

			return array_values( array_unique( array_filter( $names ) ) );
		}

		if ( ! is_string( $data ) ) {
			return [];
		}

		if ( ! preg_match_all( '/var\(\s*--([A-Za-z0-9_-]+)\s*\)/', $data, $matches ) ) {
			return [];
		}

		return array_values( array_unique( $matches[1] ) );
	}

	private static function filter_global_variables_by_names( $variables, $variable_names ) {
		$variables      = is_array( $variables ) ? array_values( $variables ) : [];
		$variable_names = array_values( array_unique( array_filter( array_map( 'strval', is_array( $variable_names ) ? $variable_names : [] ) ) ) );

		if ( empty( $variables ) || empty( $variable_names ) ) {
			return [];
		}

		$variables_by_name = [];

		foreach ( $variables as $variable ) {
			if ( ! empty( $variable['name'] ) ) {
				$variables_by_name[ (string) $variable['name'] ] = $variable;
			}
		}

		$selected_names = [];
		$pending_names  = $variable_names;

		while ( ! empty( $pending_names ) ) {
			$variable_name = array_shift( $pending_names );

			if ( isset( $selected_names[ $variable_name ] ) || empty( $variables_by_name[ $variable_name ] ) ) {
				continue;
			}

			$selected_names[ $variable_name ] = true;
			$nested_names                     = self::collect_global_variable_names_from_data( $variables_by_name[ $variable_name ] );

			foreach ( $nested_names as $nested_name ) {
				if ( ! isset( $selected_names[ $nested_name ] ) ) {
					$pending_names[] = $nested_name;
				}
			}
		}

		return array_values(
			array_filter(
				$variables,
				function( $variable ) use ( $selected_names ) {
					return isset( $selected_names[ (string) ( $variable['name'] ?? '' ) ] );
				}
			)
		);
	}

	private static function collect_components_from_elements( $elements ) {
		$components       = [];
		$all_components   = get_option( BRICKS_DB_COMPONENTS, [] );
		$component_lookup = [];
		$component_ids    = [];
		$seen_ids         = [];
		$collect_children = function( $items ) use ( &$collect_children ) {
			$ids = [];

			foreach ( (array) $items as $item ) {
				if ( ! empty( $item['cid'] ) ) {
					$ids[] = (string) $item['cid'];
				}

				if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
					$ids = array_merge( $ids, $collect_children( $item['elements'] ) );
				}
			}

			return $ids;
		};

		foreach ( $all_components as $component ) {
			if ( ! empty( $component['id'] ) ) {
				$component_lookup[ (string) $component['id'] ] = $component;
			}
		}

		$component_ids = array_values( array_unique( $collect_children( $elements ) ) );

		while ( ! empty( $component_ids ) ) {
			$component_id = array_shift( $component_ids );

			if ( isset( $seen_ids[ $component_id ] ) || empty( $component_lookup[ $component_id ] ) ) {
				continue;
			}

			$seen_ids[ $component_id ] = true;
			$component                 = $component_lookup[ $component_id ];
			$components[]              = $component;
			$nested_component_ids      = $collect_children( $component['elements'] ?? [] );

			foreach ( $nested_component_ids as $nested_component_id ) {
				if ( ! isset( $seen_ids[ $nested_component_id ] ) ) {
					$component_ids[] = $nested_component_id;
				}
			}

			$component_ids = array_values( array_unique( $component_ids ) );
		}

		return $components;
	}

	private static function unique_components_by_id( $components ) {
		$unique = [];

		foreach ( (array) $components as $component ) {
			$component_id = (string) ( $component['id'] ?? '' );

			if ( ! $component_id ) {
				continue;
			}

			$unique[ $component_id ] = $component;
		}

		return array_values( $unique );
	}

	private static function upsert_components( $local, $components, $conflict_mode ) {
		foreach ( (array) $components as $component ) {
			if ( empty( $component ) || ! is_array( $component ) ) {
				continue;
			}

			$existing_index = self::find_list_index_by_id( $local, $component['id'] ?? '' );

			if ( $existing_index !== null ) {
				if ( $conflict_mode === 'replace' ) {
					$local[ $existing_index ] = $component;
				}

				continue;
			}

			$local[] = $component;
		}

		return array_values( $local );
	}

	/**
	 * Import component definitions embedded in a template export.
	 *
	 * @since 2.4
	 *
	 * @param array $template_data Template import data.
	 */
	private static function import_template_components( &$template_data ) {
		if ( ! is_array( $template_data ) || ! is_array( $template_data['components'] ?? null ) ) {
			return;
		}

		$components                  = Components::upgrade_components( $template_data['components'], true );
		$components                  = self::unique_components_by_id( $components );
		$template_data['components'] = $components;

		if ( empty( $components ) ) {
			return;
		}

		$local   = get_option( BRICKS_DB_COMPONENTS, [] );
		$local   = is_array( $local ) ? $local : [];
		$updated = self::upsert_components( $local, $components, 'skip' );

		if ( self::normalize_for_compare( $local ) !== self::normalize_for_compare( $updated ) ) {
			update_option( BRICKS_DB_COMPONENTS, array_values( $updated ) );
			Database::$global_data['components'] = array_values( $updated );
		}
	}

	private static function collect_global_class_ids_from_elements( $elements ) {
		$class_ids = [];

		foreach ( (array) $elements as $element ) {
			if ( ! empty( $element['settings']['_cssGlobalClasses'] ) ) {
				$class_ids = array_merge( $class_ids, self::normalize_id_list( $element['settings']['_cssGlobalClasses'] ) );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_elements( $element['elements'] ) );
			}
		}

		return $class_ids;
	}

	private static function collect_global_class_ids_from_component_properties( $components ) {
		$class_ids = [];

		foreach ( (array) $components as $component ) {
			foreach ( (array) ( $component['properties'] ?? [] ) as $property ) {
				if ( ( $property['type'] ?? '' ) !== 'class' ) {
					continue;
				}

				$class_ids = array_merge( $class_ids, self::normalize_id_list( $property['default'] ?? [] ) );

				foreach ( (array) ( $property['options'] ?? [] ) as $option ) {
					$class_ids = array_merge( $class_ids, self::normalize_id_list( $option['value'] ?? [] ) );
				}
			}
		}

		return $class_ids;
	}

	private static function collect_global_class_ids_from_component_instances( $elements, $components ) {
		$class_ids        = [];
		$component_lookup = [];

		foreach ( (array) $components as $component ) {
			if ( ! empty( $component['id'] ) ) {
				$component_lookup[ (string) $component['id'] ] = $component;
			}
		}

		foreach ( (array) $elements as $element ) {
			if ( ! empty( $element['cid'] ) && ! empty( $element['properties'] ) ) {
				$component = $component_lookup[ (string) $element['cid'] ] ?? null;

				if ( $component ) {
					foreach ( (array) ( $component['properties'] ?? [] ) as $property ) {
						if ( ( $property['type'] ?? '' ) !== 'class' || empty( $property['id'] ) ) {
							continue;
						}

						$property_value = $element['properties'][ $property['id'] ] ?? null;

						$class_ids = array_merge( $class_ids, self::normalize_id_list( $property_value ) );

						foreach ( (array) ( $property['options'] ?? [] ) as $option ) {
							if ( in_array( (string) ( $option['id'] ?? '' ), self::normalize_id_list( $property_value ), true ) ) {
								$class_ids = array_merge( $class_ids, self::normalize_id_list( $option['value'] ?? [] ) );
							}
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_component_instances( $element['elements'], $components ) );
			}
		}

		return $class_ids;
	}

	private static function normalize_id_list( $value ) {
		if ( empty( $value ) ) {
			return [];
		}

		return array_map( 'strval', is_array( $value ) ? array_values( $value ) : [ $value ] );
	}

	private static function map_transfer_dependency_class_ids( $data ) {
		$dependencies = is_array( $data['dependencies'] ?? null ) ? $data['dependencies'] : $data;

		if ( ! is_array( $dependencies ) ) {
			return [];
		}

		return self::map_global_classes_to_local_ids( $dependencies['globalClasses'] ?? ( $dependencies['global_classes'] ?? [] ) );
	}

	private static function map_global_classes_to_local_ids( $classes ) {
		$class_id_map = [];

		if ( ! is_array( $classes ) || ! count( $classes ) ) {
			return $class_id_map;
		}

		$local = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );

		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}

			$original_id = (string) ( $class['id'] ?? '' );

			if ( ! $original_id ) {
				continue;
			}

			$existing_index = self::find_list_index_by_text_field( $local, 'name', $class['name'] ?? '' );

			if ( $existing_index === null ) {
				$existing_index = self::find_list_index_by_id( $local, $original_id );
			}

			if ( $existing_index === null ) {
				continue;
			}

			$existing_id = (string) ( $local[ $existing_index ]['id'] ?? '' );

			if ( $existing_id ) {
				$class_id_map[ $original_id ] = $existing_id;
			}
		}

		return $class_id_map;
	}

	private static function remap_global_class_ids_in_components( &$components, $class_id_map ) {
		if ( ! is_array( $components ) || empty( $class_id_map ) ) {
			return;
		}

		foreach ( $components as &$component ) {
			if ( ! empty( $component['properties'] ) && is_array( $component['properties'] ) ) {
				self::remap_global_class_ids_in_component_properties( $component['properties'], $class_id_map );
			}
		}

		unset( $component );

		$component_property_lookup = self::build_component_class_property_lookup( $components );

		foreach ( $components as &$component ) {
			if ( ! empty( $component['elements'] ) && is_array( $component['elements'] ) ) {
				self::remap_global_class_ids_in_elements( $component['elements'], $class_id_map, $component_property_lookup );
			}
		}

		unset( $component );
	}

	private static function remap_global_class_ids_in_template( &$template_data, $class_id_map ) {
		if ( empty( $class_id_map ) || ! is_array( $template_data ) ) {
			return;
		}

		if ( ! empty( $template_data['components'] ) && is_array( $template_data['components'] ) ) {
			self::remap_global_class_ids_in_components( $template_data['components'], $class_id_map );
		}

		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( ! empty( $template_data[ $area ] ) && is_array( $template_data[ $area ] ) ) {
				self::remap_global_class_ids_in_elements( $template_data[ $area ], $class_id_map, [], true );
			}
		}
	}

	private static function remap_global_class_ids_in_component_properties( &$properties, $class_id_map ) {
		foreach ( $properties as &$property ) {
			if ( ( $property['type'] ?? '' ) !== 'class' ) {
				continue;
			}

			foreach ( [ 'default', 'value' ] as $property_key ) {
				if ( isset( $property[ $property_key ] ) ) {
					$property[ $property_key ] = self::remap_global_class_id_value( $property[ $property_key ], $class_id_map );
				}
			}

			if ( ! empty( $property['options'] ) && is_array( $property['options'] ) ) {
				foreach ( $property['options'] as &$option ) {
					if ( isset( $option['value'] ) ) {
						$option['value'] = self::remap_global_class_id_value( $option['value'], $class_id_map );
					}
				}

				unset( $option );
			}
		}

		unset( $property );
	}

	private static function remap_global_class_ids_in_elements( &$elements, $class_id_map, $component_property_lookup = [], $remap_all_properties = false ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				self::remap_global_class_ids_in_settings( $element['settings'], $class_id_map );
			}

			$component_id       = (string) ( $element['cid'] ?? '' );
			$class_property_ids = $component_property_lookup[ $component_id ] ?? [];

			if ( $remap_all_properties && ! empty( $element['properties'] ) && is_array( $element['properties'] ) ) {
				$element['properties'] = self::remap_global_class_id_value( $element['properties'], $class_id_map );
			} elseif ( ! empty( $class_property_ids ) && ! empty( $element['properties'] ) && is_array( $element['properties'] ) ) {
				foreach ( $class_property_ids as $property_id ) {
					if ( isset( $element['properties'][ $property_id ] ) ) {
						$element['properties'][ $property_id ] = self::remap_global_class_id_value( $element['properties'][ $property_id ], $class_id_map );
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::remap_global_class_ids_in_elements( $element['elements'], $class_id_map, $component_property_lookup, $remap_all_properties );
			}
		}

		unset( $element );
	}

	private static function remap_global_class_ids_in_settings( &$settings, $class_id_map ) {
		foreach ( [ '_cssGlobalClasses', '_cssGlobalClassesProps' ] as $setting_key ) {
			if ( isset( $settings[ $setting_key ] ) ) {
				$settings[ $setting_key ] = self::remap_global_class_id_value( $settings[ $setting_key ], $class_id_map );
			}
		}
	}

	private static function remap_global_class_id_value( $value, $class_id_map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::remap_global_class_id_value( $item, $class_id_map );
			}

			return $value;
		}

		$value_key = (string) $value;

		return isset( $class_id_map[ $value_key ] ) ? $class_id_map[ $value_key ] : $value;
	}

	private static function build_component_class_property_lookup( $components ) {
		$lookup = [];

		foreach ( (array) $components as $component ) {
			$component_id = (string) ( $component['id'] ?? '' );

			if ( ! $component_id ) {
				continue;
			}

			$lookup[ $component_id ] = [];

			foreach ( (array) ( $component['properties'] ?? [] ) as $property ) {
				if ( ( $property['type'] ?? '' ) === 'class' && ! empty( $property['id'] ) ) {
					$lookup[ $component_id ][] = (string) $property['id'];
				}
			}
		}

		return $lookup;
	}

	private static function result_item( $label, $status ) {
		return [
			'label'  => $label,
			'status' => $status,
		];
	}

	private static function find_template_id( $title, $template_type ) {
		$templates = Templates::get_templates(
			[
				'post_status'           => 'any',
				'lang'                  => '',
				'remove_code_signature' => true,
			]
		);

		foreach ( $templates as $template ) {
			if ( $template['title'] === $title && ( $template['type'] ?? '' ) === $template_type ) {
				return intval( $template['id'] ?? 0 );
			}
		}

		return 0;
	}

	private static function upsert_template_from_export( $template_data, $template_id = 0, $import_images = false ) {
		$insert_post_data = [
			'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
			'post_title'  => ! empty( $template_data['title'] ) ? $template_data['title'] : esc_html__( '(no title)', 'bricks' ),
			'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
		];

		if ( $template_id ) {
			$current_post                    = get_post( $template_id );
			$insert_post_data['ID']          = $template_id;
			$insert_post_data['post_status'] = $current_post ? $current_post->post_status : $insert_post_data['post_status'];
		}

		$new_template_id = $template_id ? wp_update_post( $insert_post_data, true ) : wp_insert_post( $insert_post_data, true );

		if ( ! $new_template_id || is_wp_error( $new_template_id ) ) {
			return false;
		}

		wp_set_post_terms( $new_template_id, is_array( $template_data['tags'] ?? null ) ? $template_data['tags'] : [], BRICKS_DB_TEMPLATE_TAX_TAG, false );
		wp_set_post_terms( $new_template_id, is_array( $template_data['bundles'] ?? null ) ? $template_data['bundles'] : [], BRICKS_DB_TEMPLATE_TAX_BUNDLE, false );

		if ( ! empty( $template_data['templateType'] ) ) {
			update_post_meta( $new_template_id, BRICKS_DB_TEMPLATE_TYPE, $template_data['templateType'] );
		} elseif ( $template_id ) {
			delete_post_meta( $new_template_id, BRICKS_DB_TEMPLATE_TYPE );
		}

		$area     = 'content';
		$meta_key = BRICKS_DB_PAGE_CONTENT;

		if ( ! empty( $template_data['header'] ) ) {
			$area     = 'header';
			$meta_key = BRICKS_DB_PAGE_HEADER;
		} elseif ( ! empty( $template_data['footer'] ) ) {
			$area     = 'footer';
			$meta_key = BRICKS_DB_PAGE_FOOTER;
		}

		$elements = is_array( $template_data[ $area ] ?? null ) ? stripslashes_deep( $template_data[ $area ] ) : [];

		if ( $template_id ) {
			delete_post_meta( $new_template_id, BRICKS_DB_PAGE_HEADER );
			delete_post_meta( $new_template_id, BRICKS_DB_PAGE_CONTENT );
			delete_post_meta( $new_template_id, BRICKS_DB_PAGE_FOOTER );
		}

		if ( is_array( $template_data['pageSettings'] ?? null ) ) {
			update_post_meta( $new_template_id, BRICKS_DB_PAGE_SETTINGS, stripslashes_deep( $template_data['pageSettings'] ) );
		} elseif ( $template_id ) {
			delete_post_meta( $new_template_id, BRICKS_DB_PAGE_SETTINGS );
		}

		if ( is_array( $template_data['templateSettings'] ?? null ) ) {
			Helpers::set_template_settings( $new_template_id, stripslashes_deep( $template_data['templateSettings'] ) );
		} elseif ( $template_id ) {
			delete_post_meta( $new_template_id, BRICKS_DB_TEMPLATE_SETTINGS );
		}

		if ( count( $elements ) ) {
			$elements = Helpers::sanitize_bricks_data( $elements );
			$elements = self::maybe_import_template_images( $elements, $import_images );
			$elements = Helpers::generate_new_element_ids( $elements );

			$final_template          = $template_data;
			$final_template[ $area ] = $elements;
			$permission              = self::check_template_data_code_permissions( $final_template );

			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			$elements = \Bricks\Abilities\Elements::sign_authorized_code( [], $elements );

			update_post_meta( $new_template_id, $meta_key, $elements );

			if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
				Assets_Files::generate_post_css_file( $new_template_id, $area, $elements );
			}
		}

		return $new_template_id;
	}

	/**
	 * Import or replace remote template images.
	 *
	 * @since 2.4
	 *
	 * @param array $elements      Template elements.
	 * @param bool  $import_images Whether to download images to the media library.
	 *
	 * @return array
	 */
	private static function maybe_import_template_images( $elements, $import_images ) {
		Templates::$template_images = [];

		foreach ( $elements as $index => $element ) {
			$element_settings = ! empty( $element['settings'] ) ? $element['settings'] : [];
			$element_name     = ! empty( $element['name'] ) ? $element['name'] : '';

			if ( empty( $element_settings ) ) {
				continue;
			}

			if ( $element_name === 'image-gallery' ) {
				$images = $element_settings['items']['images'] ?? [];
				$size   = $element_settings['items']['size'] ?? 'full';

				if ( count( $images ) ) {
					foreach ( $images as $image_index => $image ) {
						$image['size'] = $size;

						$new_image = Templates::import_image( $image, $import_images );

						if ( is_array( $new_image ) && ! isset( $new_image['error'] ) ) {
							$elements[ $index ]['settings']['items']['images'][ $image_index ] = $new_image;
						}
					}
				}
			} else {
				Templates::import_images( $element_settings, $import_images );
			}
		}

		if ( count( Templates::$template_images ) ) {
			$elements_encoded = wp_json_encode( $elements );

			foreach ( Templates::$template_images as $template_image ) {
				$elements_encoded = str_replace(
					wp_json_encode( $template_image['old'] ),
					wp_json_encode( $template_image['new'] ),
					$elements_encoded
				);
			}

			$elements = json_decode( $elements_encoded, true );
		}

		Templates::$template_images = [];

		return is_array( $elements ) ? $elements : [];
	}

	private static function get_refresh_payload( $types ) {
		$refresh = [];

		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'color-palettes':
					$refresh['colorPalette'] = get_option( BRICKS_DB_COLOR_PALETTE, [] );
					break;
				case 'theme-styles':
					$refresh['themeStyles'] = get_option( BRICKS_DB_THEME_STYLES, [] );
					break;
				case 'classes':
					$refresh['globalClasses']           = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
					$refresh['globalClassesCategories'] = get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );
					$refresh['globalClassesLocked']     = get_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, [] );
					$refresh['globalClassesTimestamp']  = get_option( BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP, 0 );
					$user_id                            = get_option( BRICKS_DB_GLOBAL_CLASSES_USER, 0 );
					$refresh['globalClassesUser']       = $user_id ? ( get_userdata( $user_id )->display_name ?? '' ) : '';
					break;
				case 'variables':
					$refresh['globalVariables']           = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
					$refresh['globalVariablesCategories'] = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
					break;
				case 'custom-fonts':
					Custom_Fonts::$fonts           = false;
					Custom_Fonts::$font_face_rules = '';
					$refresh['fonts']              = Builder::get_fonts();
					break;
				case 'icon-manager':
					$refresh['iconSets']         = get_option( BRICKS_DB_ICON_SETS, [] );
					$refresh['customIcons']      = get_option( BRICKS_DB_CUSTOM_ICONS, [] );
					$refresh['disabledIconSets'] = get_option( BRICKS_DB_DISABLED_ICON_SETS, [] );
					break;
				case 'breakpoints':
					$refresh['breakpoints']       = Breakpoints::get_breakpoints();
					$refresh['customBreakpoints'] = Database::get_setting( 'customBreakpoints', false );
					$refresh['globalSettings']    = stripslashes_deep( get_option( BRICKS_DB_GLOBAL_SETTINGS, [] ) );
					break;
				case 'global-queries':
					$refresh['globalQueries']           = Database::get_global_queries();
					$refresh['globalQueriesCategories'] = get_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, [] );
					break;
				case 'components':
					$refresh['components'] = Components::upgrade_components( get_option( BRICKS_DB_COMPONENTS, [] ), false );
					self::add_dependency_refresh_payload( $refresh );
					break;
				case 'templates':
					self::add_dependency_refresh_payload( $refresh );
					break;
				case 'settings':
					$refresh['globalSettings'] = stripslashes_deep( get_option( BRICKS_DB_GLOBAL_SETTINGS, [] ) );
					break;
				case 'builder-interface':
					$user_id                      = get_current_user_id();
					$refresh['builderUiDefaults'] = Builder::get_builder_ui_defaults();
					$refresh['builderUiUser']     = Builder::get_builder_ui_user( $user_id );
					$refresh['builderUiProfiles'] = Builder::get_builder_ui_profiles();
					$refresh['builderUiResolved'] = Builder::get_resolved_builder_ui( $user_id );
					break;
				case 'custom-capabilities':
					$refresh['customCapabilities'] = self::get_custom_capabilities_for_transfer();
					break;
			}
		}

		return $refresh;
	}

	private static function add_dependency_refresh_payload( &$refresh ) {
		$refresh['globalClasses']             = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$refresh['globalClassesCategories']   = get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );
		$refresh['globalClassesLocked']       = get_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, [] );
		$refresh['globalClassesTimestamp']    = get_option( BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP, 0 );
		$user_id                              = get_option( BRICKS_DB_GLOBAL_CLASSES_USER, 0 );
		$refresh['globalClassesUser']         = $user_id ? ( get_userdata( $user_id )->display_name ?? '' ) : '';
		$refresh['globalVariables']           = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$refresh['globalVariablesCategories'] = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
		$refresh['components']                = Components::upgrade_components( get_option( BRICKS_DB_COMPONENTS, [] ), false );
	}
}
