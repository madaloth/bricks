<?php
/**
 * Global settings abilities
 *
 * Read and write keys inside `bricks_global_settings` through an allow-list
 * registry. Callers discover what's writable via `bricks/list-settings-schema`,
 * read current values via `bricks/get-global-settings`, and update values
 * (partial merge, never whole-option overwrite) via `bricks/set-global-settings`.
 *
 * Three security boundaries:
 *
 * 1. **Registry allow-list** - only keys declared in `REGISTRY` are reachable.
 *    Unknown keys return `bricks_setting_unknown`.
 * 2. **Exclusion list** - credentials and code-execution toggles are
 *    HARD-excluded at both read and write; callers never see them even if a
 *    future contributor forgets to omit them from the registry. The check lives
 *    in a constant list that must not be bypassed. Credential presence is
 *    exposed separately via `bricks/list-credential-status`.
 * 3. **Partial merge** - writes touch only the keys the caller sent. Nothing
 *    else in the global settings option can be clobbered, so callers can't
 *    accidentally wipe unrelated config.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	/**
	 * Setting keys permanently excluded from the MCP surface.
	 *
	 * These are security-sensitive and must never be readable or writable
	 * via the ability API - even if a future contributor accidentally adds
	 * them to the registry. The exclusion list is consulted at both read
	 * and write time and takes precedence over the registry.
	 *
	 * - `licenseKey` + anything license-related - stops remote license theft
	 * - `apiKey*`, `apiSecretKey*`, access tokens, remote-library passwords; a
	 *    compromised MCP must not be able to exfiltrate these
	 * - code-execution toggles - flipping these on would enable the custom
	 *    PHP/HTML execution path; that capability must remain admin-only
	 * - code signature settings - downgrading these would let a malicious
	 *    caller bypass Bricks' signature-verification defense-in-depth
	 *
	 * Keep this list additive. Matching is literal OR by `apiKey` prefix.
	 *
	 * @since 2.4
	 */
	const EXCLUDED_SETTING_KEYS = [
		'licenseKey',
		'instagramAccessToken',
		'myTemplatesPassword',
		'remoteTemplatesPassword',
		'executeCodeEnabled',
		'executeCodeCapabilities',
		'codeSignaturesLocked',
		'codeExecutionMode',
		'htmlExecutionMode',
	];

	/**
	 * Credential status registry.
	 *
	 * Values are never returned by MCP. This registry only lets clients learn
	 * whether required credentials have been configured so they can choose the
	 * right recovery path.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function credential_registry(): array {
		return [
			'licenseKey'                  => [
				'label'    => __( 'Bricks license key', 'bricks' ),
				'category' => 'license',
				'source'   => 'optionOrConstant',
				'option'   => 'bricks_license_key',
				'constant' => 'BRICKS_LICENSE_KEY',
				'usedBy'   => [ 'license', 'template library', 'updates' ],
			],
			'apiKeyUnsplash'              => [
				'label'    => __( 'Unsplash API key', 'bricks' ),
				'category' => 'media',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'unsplash' ],
			],
			'apiKeyGoogleMaps'            => [
				'label'    => __( 'Google Maps API key', 'bricks' ),
				'category' => 'maps',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'map element', 'map integrations' ],
			],
			'apiKeyGoogleRecaptcha'       => [
				'label'    => __( 'Google reCAPTCHA site key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'reCAPTCHA' ],
			],
			'apiSecretKeyGoogleRecaptcha' => [
				'label'    => __( 'Google reCAPTCHA secret key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'reCAPTCHA' ],
			],
			'apiKeyHCaptcha'              => [
				'label'    => __( 'hCaptcha site key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'hCaptcha' ],
			],
			'apiSecretKeyHCaptcha'        => [
				'label'    => __( 'hCaptcha secret key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'hCaptcha' ],
			],
			'apiKeyTurnstile'             => [
				'label'    => __( 'Cloudflare Turnstile site key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'Turnstile' ],
			],
			'apiSecretKeyTurnstile'       => [
				'label'    => __( 'Cloudflare Turnstile secret key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'Turnstile' ],
			],
			'apiKeyMailchimp'             => [
				'label'    => __( 'Mailchimp API key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'Mailchimp action' ],
			],
			'apiKeySendgrid'              => [
				'label'    => __( 'SendGrid API key', 'bricks' ),
				'category' => 'forms',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'form element', 'SendGrid action' ],
			],
			'instagramAccessToken'        => [
				'label'    => __( 'Instagram access token', 'bricks' ),
				'category' => 'social',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'Instagram feed element' ],
			],
			'myTemplatesPassword'         => [
				'label'    => __( 'Remote access password', 'bricks' ),
				'category' => 'templates',
				'source'   => 'globalSettings',
				'usedBy'   => [ 'remote template access', 'remote component access' ],
			],
			'remoteTemplates.password'    => [
				'label'    => __( 'Remote template passwords', 'bricks' ),
				'category' => 'templates',
				'source'   => 'remoteTemplates',
				'usedBy'   => [ 'remote template library' ],
			],
		];
	}

	/**
	 * Returns the setting registry.
	 *
	 * Kept behind a method so WordPress i18n has loaded before we populate
	 * description strings. Each row declares type, category, and the cap
	 * required to write it (write cap > read cap by convention).
	 *
	 * Row shape:
	 *   [
	 *     'type'        => 'bool|int|string|array|enum|object',
	 *     'category'    => 'general|performance|maintenance|builder|templates|forms',
	 *     'label'       => __( 'Human label', 'bricks' ),
	 *     'description' => __( 'One-line description', 'bricks' ),
	 *     'values'      => ['a', 'b'], // for enum
	 *     'items'       => 'string',    // for array<string>
	 *     'writeCap'    => 'manage_options', // defaults to manage_options
	 *     'readCap'     => 'manage_options',
	 *   ]
	 *
	 * @since 2.4
	 */
	public static function registry(): array {
		return [
			// ------------------------------------------------------------------
			// General
			// ------------------------------------------------------------------
			'postTypes'                                => [
				'type'        => 'array',
				'items'       => 'string',
				'category'    => 'general',
				'label'       => __( 'Bricks-enabled post types', 'bricks' ),
				'description' => __( 'Post type slugs on which the Bricks builder is enabled. Post types not in this list cannot be opened in the builder.', 'bricks' ),
			],
			'wp_to_bricks'                             => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Convert WP to Bricks on edit', 'bricks' ),
				'description' => __( 'When editing a post in WordPress, offer to convert Gutenberg content into Bricks elements.', 'bricks' ),
			],
			'bricks_to_wp'                             => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Sync Bricks to WP content', 'bricks' ),
				'description' => __( 'Also write a plain-text representation of Bricks content into `post_content` for SEO plugins that read core post content.', 'bricks' ),
			],
			'disableThemeStylesInBlockEditor'          => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Disable theme styles in the block editor', 'bricks' ),
				'description' => __( 'Keep the block editor default styling while retaining Bricks default and component styles.', 'bricks' ),
			],
			'disableClassManager'                      => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Disable class manager', 'bricks' ),
				'description' => __( 'Disable the global class manager UI for all builder users.', 'bricks' ),
			],
			'disableVariablesManager'                  => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Disable variables manager', 'bricks' ),
				'description' => __( 'Disable the CSS variables manager UI for all builder users.', 'bricks' ),
			],
			'disableOpenGraph'                         => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Disable OpenGraph', 'bricks' ),
				'description' => __( 'Stop Bricks from writing OG tags (use only if a dedicated SEO plugin handles them).', 'bricks' ),
			],
			'disableSeo'                               => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Disable SEO', 'bricks' ),
				'description' => __( 'Stop Bricks from writing meta title/description tags.', 'bricks' ),
			],
			'elementAttsAsNeeded'                      => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Output element attributes as needed', 'bricks' ),
				'description' => __( 'Only output Bricks element IDs and classes when CSS, custom selectors, or element behavior needs them.', 'bricks' ),
			],
			'customImageSizes'                         => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Enable custom image sizes', 'bricks' ),
				'description' => __( 'Allow site-specific custom image sizes in the image element.', 'bricks' ),
			],
			'disableSkipLinks'                         => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Disable skip-to-content links', 'bricks' ),
				'description' => __( 'Skip links improve accessibility; turning this on removes them entirely.', 'bricks' ),
			],
			'smoothScroll'                             => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Smooth scroll', 'bricks' ),
				'description' => __( 'Enable smooth scrolling on anchor links across the frontend.', 'bricks' ),
			],
			'deleteBricksData'                         => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Delete Bricks data button', 'bricks' ),
				'description' => __( 'Show the admin-bar "Delete Bricks data" action for the current post. It removes Bricks-generated data for that post only.', 'bricks' ),
			],
			'searchResultsQueryBricksData'             => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Include Bricks content in search', 'bricks' ),
				'description' => __( 'WordPress core only searches `post_content`. Enable this to also search the serialized Bricks element tree.', 'bricks' ),
			],
			'themeStylesLoadingMethod'                 => [
				'type'        => 'enum',
				'values'      => [ '', 'all' ],
				'category'    => 'general',
				'label'       => __( 'Theme styles loading method', 'bricks' ),
				'description' => __( 'Empty = load only the most specific matching theme style. `all` = load all matching theme styles.', 'bricks' ),
			],
			'duplicateContent'                         => [
				'type'        => 'enum',
				'values'      => [ '', 'disable_all', 'disable_wp' ],
				'category'    => 'general',
				'label'       => __( 'Duplicate content', 'bricks' ),
				'description' => __( 'Controls Bricks duplicate-content behavior. Empty enables it, `disable_all` turns it off globally, and `disable_wp` duplicates Bricks data only.', 'bricks' ),
			],
			'enableQueryFilters'                       => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Enable query filters', 'bricks' ),
				'description' => __( 'Create the Bricks query-filters database table and allow filter elements to render on the frontend.', 'bricks' ),
			],
			'saveFormSubmissions'                      => [
				'type'        => 'bool',
				'category'    => 'forms',
				'label'       => __( 'Save form submissions', 'bricks' ),
				'description' => __( 'Persist Bricks form submissions to the `bricks_form_submissions` database table.', 'bricks' ),
			],

			// ------------------------------------------------------------------
			// Templates
			// ------------------------------------------------------------------
			'publicTemplates'                          => [
				'type'        => 'bool',
				'category'    => 'templates',
				'label'       => __( 'Public template library', 'bricks' ),
				'description' => __( 'Make Bricks template posts public and viewable online.', 'bricks' ),
			],
			'myTemplatesAccess'                        => [
				'type'        => 'bool',
				'category'    => 'templates',
				'label'       => __( 'My templates access', 'bricks' ),
				'description' => __( 'Allow other Bricks sites to browse and insert this site\'s templates from their template library.', 'bricks' ),
			],
			'myComponentsAccess'                       => [
				'type'        => 'bool',
				'category'    => 'templates',
				'label'       => __( 'My components access', 'bricks' ),
				'description' => __( 'Allow other Bricks sites to browse and import this site\'s components.', 'bricks' ),
			],
			'myTemplatesPassword'                      => [
				'type'        => 'string',
				'category'    => 'templates',
				'label'       => __( 'Remote access password', 'bricks' ),
				'description' => __( 'Password required by remote Bricks installs to fetch templates or components from this site.', 'bricks' ),
			],
			'myTemplatesWhitelist'                     => [
				'type'        => 'string',
				'category'    => 'templates',
				'label'       => __( 'Remote access URL whitelist', 'bricks' ),
				'description' => __( 'Newline-separated list of URLs that are allowed to fetch templates or components from this site.', 'bricks' ),
			],
			'remoteTemplates'                          => [
				'type'        => 'array',
				'category'    => 'templates',
				'label'       => __( 'Remote template URLs', 'bricks' ),
				'description' => __( 'Sites this install fetches remote templates from. MCP can manage `url` and optional `name`; stored passwords are preserved but never returned or written.', 'bricks' ),
			],

			// ------------------------------------------------------------------
			// Builder
			// ------------------------------------------------------------------
			'builderAutosaveInterval'                  => [
				'type'        => 'int',
				'category'    => 'builder',
				'label'       => __( 'Builder autosave interval', 'bricks' ),
				'description' => __( 'Seconds between builder autosaves. Minimum 15.', 'bricks' ),
				'min'         => 15,
			],
			'builderQueryMaxResults'                   => [
				'type'        => 'int',
				'category'    => 'builder',
				'label'       => __( 'Builder query max results', 'bricks' ),
				'description' => __( 'Upper bound on results returned by builder UI queries. Minimum 2.', 'bricks' ),
				'min'         => 2,
			],
			'builderPostSelectorShowUrlPath'           => [
				'type'        => 'bool',
				'category'    => 'builder',
				'label'       => __( 'Show URL path in post/page selectors', 'bricks' ),
				'description' => __( 'Display the URL path alongside post/page titles throughout the builder.', 'bricks' ),
			],
			'builderMediaPicker'                       => [
				'type'        => 'enum',
				'values'      => [ 'bricks', 'wordpress' ],
				'category'    => 'builder',
				'label'       => __( 'Builder media picker', 'bricks' ),
				'description' => __( 'Interface used to select files in Builder controls. Both options use the WordPress Media Library.', 'bricks' ),
			],
			'builderMediaDetailsDocked'                => [
				'type'        => 'bool',
				'default'     => true,
				'storeFalse'  => true,
				'category'    => 'builder',
				'label'       => __( 'Docked media attachment details', 'bricks' ),
				'description' => __( 'Show attachment details beside the media grid in the Media Browser and media controls.', 'bricks' ),
			],
			'builderMediaHealthEnabled'                => [
				'type'        => 'bool',
				'default'     => true,
				'storeFalse'  => true,
				'category'    => 'builder',
				'label'       => __( 'Media health checks', 'bricks' ),
				'description' => __( 'Incrementally audit Media Library accessibility, file integrity, formats, derivatives, and file sizes.', 'bricks' ),
			],
			'builderMediaHealthCheckOversized'         => [
				'type'        => 'bool',
				'default'     => true,
				'storeFalse'  => true,
				'category'    => 'builder',
				'label'       => __( 'Check oversized media', 'bricks' ),
				'description' => __( 'Flag originals larger than their configured media-type threshold.', 'bricks' ),
			],
			'builderMediaHealthCheckMissingAlt'        => [
				'type'        => 'bool',
				'default'     => true,
				'storeFalse'  => true,
				'category'    => 'builder',
				'label'       => __( 'Check missing alt text', 'bricks' ),
				'description' => __( 'Flag images with empty alt text unless they are explicitly classified as decorative.', 'bricks' ),
			],
			'builderMediaHealthCheckBroken'            => [
				'type'        => 'bool',
				'default'     => true,
				'storeFalse'  => true,
				'category'    => 'builder',
				'label'       => __( 'Check broken media files', 'bricks' ),
				'description' => __( 'Flag local original files that are missing, unreadable, or empty.', 'bricks' ),
			],
			'builderMediaHealthCheckObsolete'          => [
				'type'        => 'bool',
				'default'     => true,
				'storeFalse'  => true,
				'category'    => 'builder',
				'label'       => __( 'Check obsolete media formats', 'bricks' ),
				'description' => __( 'Flag files whose extension matches the configured obsolete-format list.', 'bricks' ),
			],
			'builderMediaHealthCheckDerivatives'       => [
				'type'        => 'bool',
				'default'     => true,
				'storeFalse'  => true,
				'category'    => 'builder',
				'label'       => __( 'Check missing image sizes', 'bricks' ),
				'description' => __( 'Flag eligible registered image sizes that are missing from metadata or local storage.', 'bricks' ),
			],
			'builderMediaHealthImageMaxSize'           => [
				'type'        => 'string',
				'default'     => '1',
				'category'    => 'builder',
				'label'       => __( 'Image size warning threshold', 'bricks' ),
				'description' => __( 'Image original size threshold in MB. Set to 0 to disable this threshold.', 'bricks' ),
			],
			'builderMediaHealthFontMaxSize'            => [
				'type'        => 'string',
				'default'     => '0.5',
				'category'    => 'builder',
				'label'       => __( 'Font size warning threshold', 'bricks' ),
				'description' => __( 'Font file size threshold in MB. Set to 0 to disable this threshold.', 'bricks' ),
			],
			'builderMediaHealthDocumentMaxSize'        => [
				'type'        => 'string',
				'default'     => '5',
				'category'    => 'builder',
				'label'       => __( 'Document size warning threshold', 'bricks' ),
				'description' => __( 'Document file size threshold in MB. Set to 0 to disable this threshold.', 'bricks' ),
			],
			'builderMediaHealthAudioMaxSize'           => [
				'type'        => 'string',
				'default'     => '10',
				'category'    => 'builder',
				'label'       => __( 'Audio size warning threshold', 'bricks' ),
				'description' => __( 'Audio file size threshold in MB. Set to 0 to disable this threshold.', 'bricks' ),
			],
			'builderMediaHealthVideoMaxSize'           => [
				'type'        => 'string',
				'default'     => '50',
				'category'    => 'builder',
				'label'       => __( 'Video size warning threshold', 'bricks' ),
				'description' => __( 'Video file size threshold in MB. Set to 0 to disable this threshold.', 'bricks' ),
			],
			'builderMediaHealthObsoleteExtensions'     => [
				'type'        => 'string',
				'default'     => 'bmp,tif,tiff',
				'category'    => 'builder',
				'label'       => __( 'Obsolete media extensions', 'bricks' ),
				'description' => __( 'Comma-separated file extensions that Media Health should flag as obsolete.', 'bricks' ),
			],
			'builderLocale'                            => [
				'type'        => 'string',
				'category'    => 'builder',
				'label'       => __( 'Builder locale', 'bricks' ),
				'description' => __( 'Locale used inside the builder UI (e.g. `en_US`, `de_DE`). Empty falls back to US English.', 'bricks' ),
			],
			'abilitiesApi'                             => [
				'type'        => 'bool',
				'category'    => 'builder',
				'label'       => __( 'Abilities API', 'bricks' ),
				'description' => __( 'Registers Bricks abilities with the WordPress MCP Adapter plugin. Same value as the master toggle under Settings > AI.', 'bricks' ),
				'writeCap'    => 'manage_options',
			],

			// ------------------------------------------------------------------
			// WooCommerce
			// ------------------------------------------------------------------
			'woocommerceDisableBuilder'                => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Disable WooCommerce builder', 'bricks' ),
				'description' => __( 'Disable the Bricks WooCommerce builder integration while leaving WooCommerce itself active.', 'bricks' ),
			],
			'woocommerceUseAdvancedModularElements'    => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Enable advanced modular WooCommerce elements', 'bricks' ),
				'description' => __( 'Experimental opt-in for Cart v2, Checkout v2, Account Page v2, state previews, and predefined WooCommerce element generation. Agents should explain the experimental status before enabling it.', 'bricks' ),
			],
			'woocommerceUseBricksWooNotice'            => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Enable Bricks WooCommerce notice element', 'bricks' ),
				'description' => __( 'Remove native WooCommerce notices and require explicit Bricks WooCommerce Notice elements where notices should render.', 'bricks' ),
			],
			'woocommerceUseBricksWooCheckoutCoupon'    => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Enable Bricks checkout coupon element', 'bricks' ),
				'description' => __( 'Remove the native WooCommerce checkout coupon form and use the Bricks Checkout Coupon element instead. WooCommerce coupon codes must still be enabled.', 'bricks' ),
			],
			'woocommerceUseBricksWooCheckoutLogin'     => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Enable Bricks checkout login element', 'bricks' ),
				'description' => __( 'Remove the native WooCommerce checkout login form and use the Bricks Checkout Login element instead. WooCommerce checkout login must still be enabled.', 'bricks' ),
			],
			'woocommerceUseQtyInLoop'                  => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Show quantity input in product loops', 'bricks' ),
				'description' => __( 'Show a quantity input for purchasable, in-stock simple products and individual variations with all attributes specified inside product loops.', 'bricks' ),
			],
			'woocommerceUseVariationSwatches'          => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Enable product variation swatches', 'bricks' ),
				'description' => __( 'Convert variation dropdowns to color, image, or label swatches on the Add to Cart element.', 'bricks' ),
			],
			'woocommerceVariationAwareAttributeStockFilter' => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Variation-aware attribute stock filter', 'bricks' ),
				'description' => __( 'Use variation-level stock logic when attribute filters are combined with stock filters. May affect performance on large catalogs.', 'bricks' ),
			],
			'woocommerceBadgeSale'                     => [
				'type'        => 'enum',
				'values'      => [ '', 'text', 'percentage' ],
				'category'    => 'woocommerce',
				'label'       => __( 'Product sale badge', 'bricks' ),
				'description' => __( 'Sale badge output mode for WooCommerce products: none, text, or percentage.', 'bricks' ),
			],
			'woocommerceBadgeNew'                      => [
				'type'        => 'int',
				'category'    => 'woocommerce',
				'label'       => __( 'Product new badge days', 'bricks' ),
				'description' => __( 'Show a New badge for products younger than this many days. Use 0 or unset to disable.', 'bricks' ),
				'min'         => 0,
			],
			'woocommerceDisableProductGalleryZoom'     => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Disable product gallery zoom', 'bricks' ),
				'description' => __( 'Disable WooCommerce single-product gallery zoom.', 'bricks' ),
			],
			'woocommerceDisableProductGalleryLightbox' => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Disable product gallery lightbox', 'bricks' ),
				'description' => __( 'Disable WooCommerce single-product gallery lightbox.', 'bricks' ),
			],
			'woocommerceEnableAjaxAddToCart'           => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Enable Bricks AJAX add to cart', 'bricks' ),
				'description' => __( 'Use Bricks AJAX add to cart for simple products in product loops, replacing native WooCommerce archive AJAX behavior.', 'bricks' ),
			],
			'woocommerceAjaxAddingText'                => [
				'type'        => 'string',
				'category'    => 'woocommerce',
				'label'       => __( 'AJAX adding button text', 'bricks' ),
				'description' => __( 'Button text shown while an AJAX add-to-cart request is in progress.', 'bricks' ),
			],
			'woocommerceAjaxAddedText'                 => [
				'type'        => 'string',
				'category'    => 'woocommerce',
				'label'       => __( 'AJAX added button text', 'bricks' ),
				'description' => __( 'Button text shown after a successful AJAX add-to-cart request.', 'bricks' ),
			],
			'woocommerceAjaxResetTextAfter'            => [
				'type'        => 'int',
				'category'    => 'woocommerce',
				'label'       => __( 'AJAX reset text delay', 'bricks' ),
				'description' => __( 'Seconds before the AJAX add-to-cart button text resets after a successful add.', 'bricks' ),
				'min'         => 1,
			],
			'woocommerceAjaxHideViewCart'              => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Hide AJAX View cart button', 'bricks' ),
				'description' => __( 'Hide the View cart button in Bricks AJAX add-to-cart notices.', 'bricks' ),
			],
			'woocommerceAjaxShowNotice'                => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Show AJAX add-to-cart notice', 'bricks' ),
				'description' => __( 'Show a notice after successful Bricks AJAX add to cart.', 'bricks' ),
			],
			'woocommerceAjaxScrollToNotice'            => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Scroll to AJAX add-to-cart notice', 'bricks' ),
				'description' => __( 'Scroll to the success notice after Bricks AJAX add to cart.', 'bricks' ),
			],
			'woocommerceAjaxErrorAction'               => [
				'type'        => 'enum',
				'values'      => [ '', 'notice' ],
				'category'    => 'woocommerce',
				'label'       => __( 'AJAX add-to-cart error action', 'bricks' ),
				'description' => __( 'Empty redirects to the product page on AJAX add-to-cart errors. notice shows an inline notice.', 'bricks' ),
			],
			'woocommerceAjaxErrorScrollToNotice'       => [
				'type'        => 'bool',
				'category'    => 'woocommerce',
				'label'       => __( 'Scroll to AJAX error notice', 'bricks' ),
				'description' => __( 'Scroll to the error notice when AJAX add to cart fails and error action is notice.', 'bricks' ),
			],

			// ------------------------------------------------------------------
			// Performance
			// ------------------------------------------------------------------
			'cssLoading'                               => [
				'type'        => 'enum',
				'values'      => [ '', 'file' ],
				'category'    => 'performance',
				'label'       => __( 'CSS loading method', 'bricks' ),
				'description' => __( 'Empty = inline CSS in <head>. `file` = write CSS to `/uploads/bricks/css` and enqueue.', 'bricks' ),
			],
			'disableBricksCascadeLayer'                => [
				'type'        => 'bool',
				'category'    => 'performance',
				'label'       => __( 'Disable Bricks @layer', 'bricks' ),
				'description' => __( 'Remove the outer `@layer bricks` wrapper so Bricks CSS participates in the default cascade.', 'bricks' ),
			],
			'disableEmojis'                            => [
				'type'        => 'bool',
				'category'    => 'performance',
				'label'       => __( 'Disable WordPress emojis', 'bricks' ),
				'description' => __( 'Dequeue the WordPress emoji script/style on the frontend.', 'bricks' ),
			],
			'disableJqueryMigrate'                     => [
				'type'        => 'bool',
				'category'    => 'performance',
				'label'       => __( 'Disable jQuery Migrate', 'bricks' ),
				'description' => __( 'Frontend only. Drop the deprecation-warning layer shipped with WordPress core.', 'bricks' ),
			],

			// ------------------------------------------------------------------
			// Maintenance
			// ------------------------------------------------------------------
			'maintenanceMode'                          => [
				'type'        => 'enum',
				'values'      => [ '', 'maintenance', 'comingSoon' ],
				'category'    => 'maintenance',
				'label'       => __( 'Maintenance mode', 'bricks' ),
				'description' => __( 'Put the frontend into maintenance or coming soon mode. Empty = off.', 'bricks' ),
			],
			'maintenanceTemplate'                      => [
				'type'        => 'int',
				'category'    => 'maintenance',
				'label'       => __( 'Maintenance template', 'bricks' ),
				'description' => __( 'Template post ID rendered while maintenance mode is active.', 'bricks' ),
			],
			'bypassMaintenanceUserRoles'               => [
				'type'        => 'enum',
				'values'      => [ '', 'custom' ],
				'category'    => 'maintenance',
				'label'       => __( 'Maintenance bypass mode', 'bricks' ),
				'description' => __( 'Empty lets any logged-in user bypass maintenance mode. `custom` limits bypass to the roles selected in the Bricks settings UI.', 'bricks' ),
			],
			'maintenanceExcludedPosts'                 => [
				'type'        => 'array',
				'items'       => 'string',
				'category'    => 'maintenance',
				'label'       => __( 'Maintenance excluded posts', 'bricks' ),
				'description' => __( 'Post IDs excluded from maintenance mode.', 'bricks' ),
			],

			// ------------------------------------------------------------------
			// Password protection
			// ------------------------------------------------------------------
			'passwordProtectionEnabled'                => [
				'type'        => 'bool',
				'category'    => 'general',
				'label'       => __( 'Password protection', 'bricks' ),
				'description' => __( 'Require a site-wide password to view the frontend.', 'bricks' ),
			],
		];
	}

	/**
	 * Is a key in the exclusion list?
	 *
	 * Also rejects anything starting with `apiKey`, `apiSecretKey`, or
	 * `license` as a belt-and-braces guard against future contributors adding
	 * new keys.
	 *
	 * @since 2.4
	 */
	public static function is_excluded_key( string $key ): bool {
		if ( in_array( $key, self::EXCLUDED_SETTING_KEYS, true ) ) {
			return true;
		}

		if ( strpos( $key, 'apiKey' ) === 0 ) {
			return true;
		}

		if ( strpos( $key, 'apiSecretKey' ) === 0 ) {
			return true;
		}

		if ( strpos( $key, 'license' ) === 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Return registry keys visible through the MCP settings write surface.
	 *
	 * @since 2.4
	 *
	 * @return string[]
	 */
	private static function public_setting_keys(): array {
		return array_values(
			array_filter(
				array_keys( self::registry() ),
				function( $key ) {
					return ! self::is_excluded_key( $key );
				}
			)
		);
	}

	// ------------------------------------------------------------------
	// Permissions
	// ------------------------------------------------------------------

	/**
	 * Permission: discover the registry.
	 *
	 * Low bar - readable by anyone who can edit posts. Registry content is
	 * schema-only (no secret values), so exposing it at `edit_posts` lets
	 * less-privileged clients at least learn what they'd need to ask for.
	 *
	 * @since 2.4
	 */
	public static function schema_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		return true;
	}

	/**
	 * Permission: read current values.
	 *
	 * Values may include deployment-shape information (enabled post types,
	 * CSS loading mode). Gate on `manage_options` to match the admin UI.
	 *
	 * @since 2.4
	 */
	public static function read_permission( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		return true;
	}

	/**
	 * Permission: write site-wide settings.
	 *
	 * @since 2.4
	 */
	public static function write_permission( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// list-settings-schema
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-settings-schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_settings_schema_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'category' => [
					'type'        => 'string',
					'description' => __( 'Filter by category: general, performance, maintenance, builder, templates, forms, woocommerce.', 'bricks' ),
				],
			],
		];
	}

	public static function list_settings_schema_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings' => [
					'type'        => 'array',
					'description' => __( 'Registry entries: [{ key, label, category, type, values?, items?, default?, min?, description }]. Only keys listed here can be read or written.', 'bricks' ),
				],
			],
		];
	}

	public static function list_settings_schema( $input ) {
		$category = $input['category'] ?? '';
		$rows     = [];

		foreach ( self::registry() as $key => $meta ) {
			// Defense-in-depth: skip excluded keys even if a future
			// contributor accidentally adds one to the registry.
			if ( self::is_excluded_key( $key ) ) {
				continue;
			}

			if ( $category && ( $meta['category'] ?? '' ) !== $category ) {
				continue;
			}

			$row = [
				'key'         => $key,
				'label'       => $meta['label'] ?? $key,
				'category'    => $meta['category'] ?? 'general',
				'type'        => $meta['type'] ?? 'string',
				'description' => $meta['description'] ?? '',
			];

			if ( isset( $meta['values'] ) ) {
				$row['values'] = $meta['values'];
			}
			if ( isset( $meta['items'] ) ) {
				$row['items'] = $meta['items'];
			}
			if ( isset( $meta['min'] ) ) {
				$row['min'] = $meta['min'];
			}
			if ( array_key_exists( 'default', $meta ) ) {
				$row['default'] = $meta['default'];
			}

			$rows[] = $row;
		}

		return [ 'settings' => $rows ];
	}

	// ------------------------------------------------------------------
	// list-credential-status
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-credential-status.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_credential_status_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'category' => [
					'type'        => 'string',
					'description' => __( 'Optional filter by category: license, media, maps, forms, social, templates.', 'bricks' ),
				],
				'keys'     => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Optional list of credential keys to return. Unknown keys are reported in `missing`.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for list-credential-status.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_credential_status_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'credentials' => [
					'type'        => 'array',
					'description' => __( 'Credential status rows. Values are never returned.', 'bricks' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'key'        => [ 'type' => 'string' ],
							'label'      => [ 'type' => 'string' ],
							'category'   => [ 'type' => 'string' ],
							'configured' => [ 'type' => 'boolean' ],
							'readable'   => [ 'type' => 'boolean' ],
							'writable'   => [ 'type' => 'boolean' ],
							'usedBy'     => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
						],
					],
				],
				'missing'     => [
					'type'        => 'array',
					'description' => __( 'Requested credential keys that are not known.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Callback: list credential status without exposing secret values.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_credential_status( $input ) {
		Manager::flush_options_cache();

		$current   = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		$registry  = self::credential_registry();
		$category  = $input['category'] ?? '';
		$requested = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_map( 'strval', $input['keys'] ) ) : [];
		$missing   = [];
		$rows      = [];

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		$keys_to_emit = $requested ? $requested : array_keys( $registry );

		foreach ( $keys_to_emit as $key ) {
			if ( ! isset( $registry[ $key ] ) ) {
				$missing[] = $key;
				continue;
			}

			$meta = $registry[ $key ];

			if ( $category && ( $meta['category'] ?? '' ) !== $category ) {
				continue;
			}

			$rows[] = [
				'key'        => $key,
				'label'      => $meta['label'] ?? $key,
				'category'   => $meta['category'] ?? 'credentials',
				'configured' => self::credential_is_configured( $key, $meta, $current ),
				'readable'   => false,
				'writable'   => false,
				'usedBy'     => $meta['usedBy'] ?? [],
			];
		}

		return [
			'credentials' => $rows,
			'missing'     => $missing,
		];
	}

	// ------------------------------------------------------------------
	// get-global-settings
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-global-settings.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_global_settings_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'keys' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Optional list of setting keys to return. Omit to return all registry values. Unknown keys are silently skipped.', 'bricks' ),
				],
			],
		];
	}

	public static function get_global_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings' => [
					'type'        => 'object',
					'description' => __( 'Current values keyed by setting name. Missing keys are unset in the option.', 'bricks' ),
				],
				'missing'  => [
					'type'        => 'array',
					'description' => __( 'Keys that the caller requested but that are not in the registry (and therefore not returned).', 'bricks' ),
				],
			],
		];
	}

	public static function get_global_settings( $input ) {
		Manager::flush_options_cache();

		$current   = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		$registry  = self::registry();
		$requested = isset( $input['keys'] ) && is_array( $input['keys'] ) ? $input['keys'] : [];
		$missing   = [];
		$output    = [];

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		$keys_to_emit = $requested ? $requested : array_keys( $registry );

		foreach ( $keys_to_emit as $key ) {
			if ( self::is_excluded_key( $key ) ) {
				if ( $requested ) {
					$missing[] = $key;
				}
				continue;
			}

			if ( ! isset( $registry[ $key ] ) ) {
				$missing[] = $key;
				continue;
			}

			$value          = self::normalize_read_value( $key, $current[ $key ] ?? null, $registry[ $key ] );
			$output[ $key ] = self::redact_setting_value( $key, $value );
		}

		return [
			'settings' => $output,
			'missing'  => $missing,
		];
	}

	// ------------------------------------------------------------------
	// set-global-settings
	// ------------------------------------------------------------------

	/**
	 * Input schema for set-global-settings.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_global_settings_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'Map of setting key to new value. Only keys listed in `list-settings-schema` are accepted. Excluded keys (credentials and code execution) return `bricks_setting_excluded`. Unknown keys return `bricks_setting_unknown`. Partial merge: keys not passed are untouched.', 'bricks' ),
					// Values vary per-key; defer shape validation to the callback.
					'additionalProperties' => true,
				],
			],
			'required'             => [ 'settings' ],
		];
	}

	public static function set_global_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'updated'        => [
					'type'        => 'array',
					'description' => __( 'List of keys that were actually changed (value differed from prior state).', 'bricks' ),
				],
				'unchanged'      => [
					'type'        => 'array',
					'description' => __( 'Keys whose new value matched the existing value, so no write was performed.', 'bricks' ),
				],
				'beforeSnapshot' => [
					'type'        => 'object',
					'description' => __( 'Prior values for every key touched (updated + unchanged), so the caller can confirm or revert.', 'bricks' ),
				],
			],
		];
	}

	public static function set_global_settings( $input ) {
		if ( ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) || empty( $input['settings'] ) ) {
			return Error::missing_param( 'settings', [ 'settings' ] );
		}

		Manager::flush_options_cache();

		$incoming = $input['settings'];
		$registry = self::registry();
		$current  = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}

		$updated         = [];
		$unchanged       = [];
		$before_snapshot = [];
		$next            = $current;

		foreach ( $incoming as $key => $value ) {
			// Exclusion takes precedence over everything.
			if ( self::is_excluded_key( $key ) ) {
				return Error::setting_excluded( $key );
			}

			if ( ! isset( $registry[ $key ] ) ) {
				return Error::setting_unknown( $key, self::public_setting_keys() );
			}

			$meta = $registry[ $key ];

			// Per-setting cap: may raise the bar above the default `manage_options`.
			$write_cap = $meta['writeCap'] ?? 'manage_options';
			if ( $write_cap !== 'manage_options' && ! current_user_can( $write_cap ) ) {
				return Error::forbidden_builder_permission( $write_cap );
			}

			// `null` resets a setting to its unwritten default by removing the
			// key from the stored option. Enables enum settings (e.g. cssLoading)
			// to round-trip back to their original null state.
			if ( $value === null ) {
				$before_snapshot[ $key ] = self::redact_setting_value( $key, $current[ $key ] ?? null );

				if ( ! array_key_exists( $key, $current ) ) {
					$unchanged[] = $key;
					continue;
				}

				unset( $next[ $key ] );
				$updated[] = $key;
				continue;
			}

			if ( $key === 'remoteTemplates' ) {
				if ( ! is_array( $value ) ) {
					return Error::invalid_param( $key, 'array', $value );
				}

				$validation = self::normalize_remote_templates( $value, $current );
			} else {
				$validation = self::validate_value( $key, $value, $meta );
			}

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$value = $validation;

			$before_snapshot[ $key ] = self::redact_setting_value( $key, $current[ $key ] ?? null );

			if (
				( $meta['type'] ?? '' ) === 'bool' &&
				$value === false &&
				empty( $meta['storeFalse'] )
			) {
				if ( ! array_key_exists( $key, $current ) ) {
					$unchanged[] = $key;
					continue;
				}

				unset( $next[ $key ] );
				$updated[] = $key;
				continue;
			}

			if ( ( $current[ $key ] ?? null ) === $value ) {
				$unchanged[] = $key;
				continue;
			}

			$next[ $key ] = $value;
			$updated[]    = $key;
		}

		if ( $updated ) {
			update_option( BRICKS_DB_GLOBAL_SETTINGS, $next );
			self::sync_runtime_global_settings( $next, $updated );
		}

		return [
			'updated'        => $updated,
			'unchanged'      => $unchanged,
			'beforeSnapshot' => $before_snapshot,
		];
	}

	/**
	 * Keep same-request Bricks runtime state in sync after an MCP settings write.
	 *
	 * The normal admin settings save path updates side-effect tables and future
	 * requests reload `Database::$global_settings` from the option. MCP runs in
	 * a long-lived adapter process, so writes must refresh that static state and
	 * create dependent tables immediately.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Full next settings option.
	 * @param array $updated  Setting keys that changed.
	 * @return void
	 */
	private static function sync_runtime_global_settings( array $settings, array $updated ): void {
		Manager::flush_options_cache();

		if ( class_exists( '\\Bricks\\Database' ) ) {
			\Bricks\Database::$global_data['settings'] = $settings;
			\Bricks\Database::$global_settings         = $settings;
		}

		$media_health_updated = array_filter(
			$updated,
			static function( $key ) {
				return strpos( $key, 'builderMediaHealth' ) === 0;
			}
		);

		if ( $media_health_updated && class_exists( '\\Bricks\\Media_Browser_Health' ) ) {
			\Bricks\Media_Browser_Health::reset_runtime_cache();
		}

		if ( in_array( 'saveFormSubmissions', $updated, true )
			&& isset( $settings['saveFormSubmissions'] )
			&& class_exists( '\\Bricks\\Integrations\\Form\\Submission_Database' )
		) {
			\Bricks\Integrations\Form\Submission_Database::maybe_create_table();
		}

		if ( in_array( 'enableQueryFilters', $updated, true )
			&& isset( $settings['enableQueryFilters'] )
			&& class_exists( '\\Bricks\\Query_Filters' )
		) {
			\Bricks\Query_Filters::get_instance()->maybe_create_tables();
		}
	}

	/**
	 * Normalize stored setting values to the public schema type on read.
	 *
	 * Older settings can store boolean-like values as strings (`"true"`,
	 * `"false"`) because they predate this MCP surface. Reads should match
	 * `list-settings-schema` instead of leaking storage quirks to clients.
	 *
	 * @since 2.4
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Stored value.
	 * @param array  $meta  Registry row.
	 * @return mixed
	 */
	private static function normalize_read_value( string $key, $value, array $meta ) {
		$type = $meta['type'] ?? 'string';

		if ( $value === null && array_key_exists( 'default', $meta ) ) {
			$value = $meta['default'];
		}

		if ( $key === 'remoteTemplates' ) {
			return $value;
		}

		switch ( $type ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}

				if ( in_array( $value, [ 1, '1', 'true' ], true ) ) {
					return true;
				}

				if ( in_array( $value, [ 0, '0', 'false', '', null ], true ) ) {
					return false;
				}

				return (bool) $value;

			case 'int':
				return is_numeric( $value ) ? (int) $value : $value;

			case 'string':
				return is_scalar( $value )
					? self::sanitize_setting_scalar( $key, (string) $value )
					: $value;
		}

		return $value;
	}

	/**
	 * Validate + coerce a single incoming setting value against its registry
	 * entry. Returns the normalized value or a WP_Error.
	 *
	 * @since 2.4
	 *
	 * @param string $key   Registry key.
	 * @param mixed  $value Raw incoming value.
	 * @param array  $meta  Registry row.
	 * @return mixed|\WP_Error
	 */
	private static function validate_value( string $key, $value, array $meta ) {
		$type = $meta['type'] ?? 'string';

		switch ( $type ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( in_array( $value, [ 0, '0', 'false' ], true ) ) {
					return false;
				}
				if ( in_array( $value, [ 1, '1', 'true' ], true ) ) {
					return true;
				}
				return Error::invalid_param( $key, 'boolean', $value );

			case 'int':
				if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( ltrim( $value, '-' ) ) ) ) {
					$value = (int) $value;
					$min   = isset( $meta['min'] ) ? (int) $meta['min'] : null;

					if ( $min !== null && $value < $min ) {
						return Error::invalid_param( $key, 'integer >= ' . $min, $value );
					}

					return $value;
				}
				return Error::invalid_param( $key, 'integer', $value );

			case 'string':
				if ( is_string( $value ) ) {
					return self::sanitize_setting_scalar( $key, $value );
				}
				if ( is_scalar( $value ) ) {
					return self::sanitize_setting_scalar( $key, (string) $value );
				}
				return Error::invalid_param( $key, 'string', $value );

			case 'enum':
				$allowed = $meta['values'] ?? [];
				if ( ! in_array( $value, $allowed, true ) ) {
					return Error::invalid_param( $key, 'one of: ' . implode( ', ', array_map( 'strval', $allowed ) ), $value );
				}
				return $value;

			case 'array':
				if ( ! is_array( $value ) ) {
					return Error::invalid_param( $key, 'array', $value );
				}
				if ( ( $meta['items'] ?? '' ) === 'string' ) {
					foreach ( $value as $item ) {
						if ( ! is_string( $item ) && ! is_scalar( $item ) ) {
							return Error::invalid_param( $key, 'array of strings', $value );
						}
					}
					return array_values(
						array_map(
							function ( $item ) use ( $key ) {
								return self::sanitize_setting_scalar( $key, (string) $item );
							},
							$value
						)
					);
				}
				return array_values( $value );

			case 'object':
				if ( ! is_array( $value ) ) {
					return Error::invalid_param( $key, 'object', $value );
				}
				return $value;
		}

		return $value;
	}

	/**
	 * Sanitize scalar setting values before they are persisted through MCP.
	 *
	 * Mirrors the admin settings save path while preserving textarea semantics
	 * for newline-based fields such as the My Templates whitelist.
	 *
	 * @since 2.4
	 *
	 * @param string $key   Registry key.
	 * @param string $value Scalar value.
	 * @return string
	 */
	private static function sanitize_setting_scalar( string $key, string $value ): string {
		if ( $key === 'myTemplatesWhitelist' ) {
			return sanitize_textarea_field( $value );
		}

		if (
			in_array(
				$key,
				[
					'builderMediaHealthImageMaxSize',
					'builderMediaHealthFontMaxSize',
					'builderMediaHealthDocumentMaxSize',
					'builderMediaHealthAudioMaxSize',
					'builderMediaHealthVideoMaxSize',
				],
				true
			)
		) {
			return (string) ( is_numeric( $value ) ? min( 10240, max( 0, (float) $value ) ) : 0 );
		}

		if ( $key === 'builderMediaHealthObsoleteExtensions' ) {
			$extensions = preg_split( '/[\s,]+/', $value );
			$extensions = array_map(
				static function( $extension ) {
					return sanitize_key( ltrim( (string) $extension, '.' ) );
				},
				$extensions
			);

			return implode( ',', array_values( array_unique( array_filter( $extensions ) ) ) );
		}

		return \Bricks\Helpers::sanitize_value( $value );
	}

	/**
	 * Normalize remote template source settings while keeping new passwords out
	 * of the MCP write path. Existing stored passwords are preserved by URL so
	 * an MCP update to source names/URLs does not accidentally erase secrets it
	 * never received.
	 *
	 * @since 2.4
	 *
	 * @param array $templates Raw remote template rows.
	 * @param array $current   Current global settings.
	 * @return array|\WP_Error
	 */
	private static function normalize_remote_templates( array $templates, array $current = [] ) {
		$normalized       = [];
		$passwords_by_url = self::remote_template_passwords_by_url( $current );

		foreach ( array_values( $templates ) as $index => $template ) {
			if ( ! is_array( $template ) ) {
				return Error::invalid_param( "remoteTemplates[{$index}]", 'an object with url and optional name', $template );
			}

			if ( self::value_is_configured( $template['password'] ?? null ) ) {
				return Error::setting_excluded( "remoteTemplates[{$index}].password" );
			}

			$url = '';

			if ( isset( $template['url'] ) ) {
				$url = self::normalize_remote_template_url( (string) $template['url'], "remoteTemplates[{$index}].url" );
			}

			if ( is_wp_error( $url ) ) {
				return $url;
			}

			if ( $url === '' ) {
				continue;
			}

			$row = [ 'url' => $url ];

			if ( isset( $template['name'] ) && self::value_is_configured( $template['name'] ) ) {
				$row['name'] = sanitize_text_field( (string) $template['name'] );
			}

			if ( isset( $passwords_by_url[ $url ] ) ) {
				$row['password'] = $passwords_by_url[ $url ];
			}

			$normalized[] = $row;
		}

		return $normalized;
	}

	/**
	 * Existing remote-template passwords keyed by source URL.
	 *
	 * @since 2.4
	 *
	 * @param array $current Current global settings.
	 * @return array
	 */
	private static function remote_template_passwords_by_url( array $current ): array {
		$passwords = [];

		$templates = $current['remoteTemplates'] ?? [];
		if ( is_array( $templates ) ) {
			foreach ( $templates as $template ) {
				if ( ! is_array( $template ) ) {
					continue;
				}

				$url = isset( $template['url'] ) ? esc_url_raw( (string) $template['url'] ) : '';
				if ( $url === '' || ! self::value_is_configured( $template['password'] ?? null ) ) {
					continue;
				}

				$passwords[ $url ] = $template['password'];
			}
		}

		$legacy_url      = isset( $current['remoteTemplatesUrl'] ) ? esc_url_raw( (string) $current['remoteTemplatesUrl'] ) : '';
		$legacy_password = $current['remoteTemplatesPassword'] ?? null;

		if ( $legacy_url !== '' && self::value_is_configured( $legacy_password ) && ! isset( $passwords[ $legacy_url ] ) ) {
			$passwords[ $legacy_url ] = $legacy_password;
		}

		return $passwords;
	}

	/**
	 * Validate a remote template URL accepted through the MCP settings surface.
	 *
	 * Admin-configured remote template URLs are later used for outbound template
	 * fetches. Keep MCP-created rows public-only so the settings ability cannot
	 * become an indirect server-side fetch primitive.
	 *
	 * @since 2.4
	 *
	 * @param string $url   Raw URL.
	 * @param string $param Error parameter path.
	 * @return string|\WP_Error
	 */
	private static function normalize_remote_template_url( string $url, string $param ) {
		$url = trim( $url );

		if ( $url === '' ) {
			return '';
		}

		$validated_url = wp_http_validate_url( $url );

		if ( ! $validated_url ) {
			return Error::invalid_param( $param, 'a public http/https URL without credentials or unsafe ports', $url );
		}

		$host = wp_parse_url( $validated_url, PHP_URL_HOST );

		if ( ! is_string( $host ) || $host === '' || ! self::host_resolves_publicly( $host ) ) {
			return Error::invalid_param( $param, 'a public host that does not resolve to a private, loopback, or link-local address', $url );
		}

		return esc_url_raw( $validated_url );
	}

	/**
	 * Revalidate a remote template URL immediately before an MCP fetch.
	 *
	 * Save-time validation alone can go stale if DNS changes after a URL is
	 * stored. MCP remote-template reads call this before delegating to the core
	 * template fetcher so the MCP path does not become a stale SSRF primitive.
	 *
	 * @since 2.4
	 *
	 * @param string $url   Remote template source URL.
	 * @param string $param Error parameter path.
	 * @return string|\WP_Error
	 */
	public static function validate_remote_template_fetch_url( string $url, string $param = 'source' ) {
		return self::normalize_remote_template_url( $url, $param );
	}

	/**
	 * Determine whether a host resolves only to public IP addresses.
	 *
	 * @since 2.4
	 *
	 * @param string $host URL host.
	 * @return bool
	 */
	private static function host_resolves_publicly( string $host ): bool {
		$host = trim( $host, '[] .' );

		if ( $host === '' ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}

		$ips = [];

		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_A + DNS_AAAA );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}

					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		$a_records = gethostbynamel( $host );

		if ( is_array( $a_records ) ) {
			$ips = array_merge( $ips, $a_records );
		}

		$ips = array_unique( array_filter( $ips ) );

		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether an IP is globally routable.
	 *
	 * @since 2.4
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_public_ip( string $ip ): bool {
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Redact sensitive nested data from otherwise-readable settings.
	 *
	 * @since 2.4
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Stored setting value.
	 * @return mixed
	 */
	private static function redact_setting_value( string $key, $value ) {
		if ( $key !== 'remoteTemplates' || ! is_array( $value ) ) {
			return $value;
		}

		$redacted = [];

		foreach ( array_values( $value ) as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}

			$row = [
				'url'                => isset( $template['url'] ) ? (string) $template['url'] : '',
				'passwordConfigured' => self::value_is_configured( $template['password'] ?? null ),
			];

			if ( isset( $template['name'] ) ) {
				$row['name'] = (string) $template['name'];
			}

			$redacted[] = $row;
		}

		return $redacted;
	}

	/**
	 * Determine if a credential registry row has a value configured.
	 *
	 * @since 2.4
	 *
	 * @param string $key      Credential key.
	 * @param array  $meta     Credential registry row.
	 * @param array  $settings Current global settings.
	 * @return bool
	 */
	private static function credential_is_configured( string $key, array $meta, array $settings ): bool {
		$source = $meta['source'] ?? 'globalSettings';

		if ( $source === 'option' ) {
			return self::value_is_configured( get_option( $meta['option'] ?? '', false ) );
		}

		if ( $source === 'optionOrConstant' ) {
			return self::option_or_constant_is_configured( $meta );
		}

		if ( $source === 'remoteTemplates' ) {
			if ( self::value_is_configured( $settings['remoteTemplatesPassword'] ?? null ) ) {
				return true;
			}

			$templates = $settings['remoteTemplates'] ?? [];
			if ( ! is_array( $templates ) ) {
				return false;
			}

			foreach ( $templates as $template ) {
				if ( is_array( $template ) && self::value_is_configured( $template['password'] ?? null ) ) {
					return true;
				}
			}

			return false;
		}

		return self::value_is_configured( $settings[ $key ] ?? null );
	}

	/**
	 * Determine if an option-backed credential is configured through a PHP constant.
	 *
	 * @since 2.4
	 *
	 * @param array $meta Credential registry row.
	 * @return bool
	 */
	private static function option_or_constant_is_configured( array $meta ): bool {
		if ( self::value_is_configured( get_option( $meta['option'] ?? '', false ) ) ) {
			return true;
		}

		$constant = $meta['constant'] ?? '';

		if ( ! $constant || ! defined( $constant ) ) {
			return false;
		}

		if ( $constant === 'BRICKS_LICENSE_KEY' && class_exists( '\Bricks\License' ) ) {
			return (bool) \Bricks\License::get_constant_license_key();
		}

		return self::value_is_configured( constant( $constant ) );
	}

	/**
	 * Does a stored credential-like value contain meaningful data?
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private static function value_is_configured( $value ): bool {
		if ( is_string( $value ) ) {
			return trim( $value ) !== '';
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::value_is_configured( $item ) ) {
					return true;
				}
			}

			return false;
		}

		return ! empty( $value );
	}
}
