<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Theme {
	public $components;
	public $capabilities;
	public $database;
	public $helpers;
	public $cli;

	public $breakpoints;
	public $blocks;
	public $revisions;
	public $license;
	public $theme_styles;
	public $custom_fonts;
	public $settings;
	public $setup;
	public $search;
	public $ajax;
	public $media_browser_bulk;
	public $media_browser_health;
	public $media_browser_query;
	public $svg;
	public $templates;
	public $heartbeat;
	public $converter;
	public $maintenance;

	public $admin;
	public $feedback;

	public $api;
	public $abilities;
	public $elements;
	public $woocommerce;
	public $woo_setup_wizard;
	public $polylang;
	public $integrations_form;
	public $wpml;
	public $instagram;
	public $rank_math;
	public $yoast;
	public $block_editor;

	public $builder;
	public $frontend;

	public $assets;

	public $interactions;
	public $popups;

	public $conditions;

	public $auth_redirects;

	public $query_filters;
	public $query_filters_indexer;
	public $query_filters_fields;

	/**
	 * The one and only Theme instance
	 *
	 * @var Theme
	 */
	public static $instance = null;

	/**
	 * Autoload and init components
	 */
	public function __construct() {
		$this->autoloader();
		$this->init();
	}

	/**
	 * Autoload files
	 */
	private function autoloader() {
		require_once BRICKS_PATH . 'includes/autoloader.php';

		Autoloader::register();
	}

	/**
	 * Init components
	 */
	public function init() {
		Compatibility::register();
		Media_Browser_Upload::register();

		$this->components   = new Components();
		$this->capabilities = new Capabilities();
		$this->database     = new Database();
		$this->helpers      = new Helpers();
		$this->cli          = new CLI();

		$this->maintenance = Maintenance::get_instance();

		$this->breakpoints = new Breakpoints();
		$this->blocks      = new Blocks();
		$this->revisions   = new Revisions();

		$this->license      = new License();
		$this->setup        = new Setup();
		$this->search       = new Search();
		$this->custom_fonts = new Custom_Fonts();
		$this->interactions = new Interactions();
		$this->popups       = new Popups();

		// Element Conditions API
		$this->conditions = new Conditions();

		$this->auth_redirects = new Auth_Redirects();

		// Load before elements (theme style settings needed inside element render)
		$this->theme_styles = new Theme_Styles();

		// Load all elements in builder, but only requested elements on frontend
		$this->elements = new Elements();

		// Loads WooCommerce integration, if activated
		$this->woocommerce = new Woocommerce();

		// Woo Setup Wizard (@since 2.4)
		$this->woo_setup_wizard = new Woo_Setup_Wizard();

		$this->media_browser_bulk   = new Media_Browser_Bulk();
		$this->media_browser_health = new Media_Browser_Health();
		$this->media_browser_query  = new Media_Browser_Query();
		$this->ajax                 = new Ajax();
		$this->svg                  = new Svg();
		$this->templates            = new Templates();
		$this->settings             = new Settings();

		// Integrations
		$this->integrations_form = new Integrations\Form\Init();
		$this->polylang          = new Integrations\Polylang\Polylang();
		$this->wpml              = new Integrations\Wpml\Wpml();
		$this->instagram         = new Integrations\Instagram\Instagram();
		$this->rank_math         = new Integrations\Rank_Math\Rank_Math();
		$this->yoast             = new Integrations\Yoast\Yoast();
		$this->block_editor      = new Integrations\Block_Editor();

		if ( is_admin() ) {
			$this->admin     = new Admin();
			$this->converter = new Converter();
			$this->feedback  = new Feedback();
		}

		$this->api = new Api();

		// WordPress Abilities API (@since 2.4)
		// Interrupted agent journals must remain recoverable even after the
		// Abilities API setting is disabled or removed.
		add_action( 'bricks_cleanup_agent_import_journal', [ Abilities\Workspace::class, 'cleanup_journal' ] );
		add_action( 'bricks_cleanup_agent_design_journal', [ Abilities\Design_Workspace::class, 'cleanup_journal' ] );
		add_action( Abilities\Site_Changeset::CLEANUP_HOOK, [ Abilities\Site_Changeset::class, 'cleanup_journal' ] );

		if ( isset( Database::$global_settings['abilitiesApi'] ) ) {
			$this->abilities = new Abilities\Manager();
		}

		/**
		 * Dynamic Data
		 *
		 * Order matters: 'cmb2' before 'wp' so it can filter the custom fields correctly.
		 *
		 * NOTE: bricks/dynamic_data/register_providers Undocumented (@since 1.6.2)
		 */
		$dynamic_data_providers = apply_filters(
			'bricks/dynamic_data/register_providers',
			[
				'cmb2',
				'wp',
				'woo',
				'acf',
				'pods',
				'metabox',
				'toolset',
				'jetengine',
			]
		);

		Integrations\Dynamic_Data\Providers::register( $dynamic_data_providers );

		// Check for builder instance inside Heartbeat class
		$this->heartbeat = new Heartbeat();

		if ( bricks_is_builder() ) {
			$this->builder = new Builder();
		} else {
			if ( ! is_admin() ) {
				$this->frontend = new Frontend();
			}
		}

		$this->assets = new Assets();

		// Query Filters (@since 1.9.6)
		if ( Helpers::enabled_query_filters() ) {
			$this->query_filters         = Query_Filters::get_instance();
			$this->query_filters_indexer = Query_Filters_Indexer::get_instance(); // @since 1.10

			if ( Helpers::enabled_query_filters_integration() ) {
				$this->query_filters_fields = Integrations\Query_Filters\Fields::get_instance(); // @since 1.11.1
			}
		}

		// Include Query API class (@since 2.1)
		require_once BRICKS_PATH . 'includes/integrations/query/query-api.php';

		// Include Query Array class (@since 2.2)
		require_once BRICKS_PATH . 'includes/integrations/query/query-array.php';
	}

	/**
	 * Main Theme instance
	 *
	 * Ensure only one instance of Theme exists at any given time.
	 *
	 * @return object Theme The one and only Theme instance
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Theme ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

// Get the theme up and running
Theme::instance();
