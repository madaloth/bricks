<?php
namespace Bricks\Integrations\Dynamic_Data;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Providers {
	/**
	 * Holds the providers
	 *
	 * @var array
	 */
	private $providers_keys = [];

	/**
	 * Holds the providers instances
	 *
	 * @var array
	 */
	private static $providers = [];

	/**
	 * Holds the tags instances
	 *
	 * @var array
	 */
	private $tags = [];

	// Holds the functions that were already run when registering providers and tags (@since 2.0)
	private static $registered_flags          = [];
	private static $registered_tags_set_cache = [];

	// Tracks the currently rendered element for dynamic data providers (#86c4yu6kr; @since 2.3.8)
	private static $render_context_stack = [];

	public function __construct( $providers ) {
		$this->providers_keys = $providers;

		// @since 1.10
		add_filter( 'bricks/dynamic_data/format_value', [ $this, 'handle_fallback' ], 10, 5 );
	}

	/**
	 * Push current render context for dynamic data providers.
	 *
	 * Components can render the same source element multiple times, so providers
	 * need this internal stack to resolve tags against the active instance
	 * without changing the public dynamic data rendering API (#86c4yu6kr).
	 *
	 * @param array $context Render context data.
	 *
	 * @return void
	 * @since 2.3.8
	 */
	public static function push_render_context( $context = [] ) {
		self::$render_context_stack[] = is_array( $context ) ? $context : [];
	}

	/**
	 * Pop current render context for dynamic data providers.
	 *
	 * Keeps nested element renders scoped to the provider call that created
	 * them, so a later sibling does not inherit stale component context
	 * (#86c4yu6kr).
	 *
	 * @return void
	 * @since 2.3.8
	 */
	public static function pop_render_context() {
		array_pop( self::$render_context_stack );
	}

	/**
	 * Get current render context.
	 *
	 * Used by dynamic data providers that need to know the active element while
	 * preserving the existing provider method signatures (#86c4yu6kr).
	 *
	 * @param string $key Optional context key.
	 *
	 * @return mixed
	 * @since 2.3.8
	 */
	public static function get_current_render_context( $key = '' ) {
		if ( empty( self::$render_context_stack ) ) {
			return $key ? null : [];
		}

		$context = self::$render_context_stack[ count( self::$render_context_stack ) - 1 ];

		return $key ? ( $context[ $key ] ?? null ) : $context;
	}

	public static function register( $providers = [] ) {
		$instance = new self( $providers );

		// For Polylang without interfere other plugins. (#86c3htjt0)
		$register_hook = apply_filters( 'bricks/dynamic_data/register_hook', 'init' );

		// Register providers (priority 10000 due to CMB2 priority)
		add_action( $register_hook, [ $instance, 'register_providers' ], 10000 );

		// Register tags on init after register_providers (@since 1.9.8)
		add_action( $register_hook, [ $instance, 'register_tags' ], 10001 );

		// Trigger an action when all dynamic data tags are registered (@since 2.0)
		add_action( $register_hook, [ $instance, 'tag_registered' ], 10002 );
		// Keep 1 version for reference
		// Register providers and tags for normal requests or Bricks REST API requests (@since 2.0) (#86c3htjt0)
		// if ( \Bricks\Api::is_bricks_rest_request() ) {
		// Register providers during WP REST API call
		// rest_api_init is too early and causing Poylang no language set, use rest_pre_dispatch which will run after rest_api_init and before callback. (@since 2.0)
		// Note: rest_pre_dispatch will be running multiple times based on the number of registered REST API routes, so we need to check if the providers already registered (@since 2.0)
		// $register_hook = apply_filters( 'bricks/dynamic_data/register_hook', 'rest_pre_dispatch' );

		// add_action( $register_hook, [ $instance, 'register_providers' ], 10 );

		// Register tags after register_providers and Polylang set language (priority 10) (@since 2.0)
		// add_action( $register_hook, [ $instance, 'register_tags' ], 11 );

		// Trigger an action when all dynamic data tags are registered (@since 2.0)
		// add_action( $register_hook, [ $instance, 'tag_registered' ], 12 );

		// } else {
		// Register providers (priority 10000 due to CMB2 priority)
		// add_action( 'init', [ $instance, 'register_providers' ], 10000 );

		// Register tags on init after register_providers (@since 1.9.8)
		// add_action( 'init', [ $instance, 'register_tags' ], 10001 );

		// Trigger an action when all dynamic data tags are registered (@since 2.0)
		// add_action( 'init', [ $instance, 'tag_registered' ], 10002 );
		// }

		// Register tags before wp_enqueue_scripts (but not before wp to get the post custom fields)
		// Priority = 8 to run before Setup::init_control_options
		// Not in use (@since 1.9.8), Register on 'init' hook above (#86bw2ytax)
		// add_action( 'wp', [ $instance, 'register_tags' ], 8 );

		// Hook "wp" doesn't run on AJAX/REST API calls so we need this to register the tags when rendering elements (needed for Posts element) or fetching dynamic data content
		// Not in use (@since 1.9.8), Register on 'init' hook above (#86bw2ytax)
		// add_action( 'admin_init', [ $instance, 'register_tags' ], 8 );

		add_filter( 'bricks/dynamic_tags_list', [ $instance, 'add_tags_to_builder' ] );

		// Render dynamic data in builder too (when template preview post ID is set)
		add_filter( 'bricks/frontend/render_data', [ $instance, 'render' ], 10, 2 );

		add_filter( 'bricks/dynamic_data/render_content', [ $instance, 'render' ], 10, 3 );

		add_filter( 'bricks/dynamic_data/render_tag', [ $instance, 'get_tag_value' ], 10, 3 );
	}

	/**
	 * Trigger an action when all dynamic data tags are registered
	 *
	 * @since 2.0
	 */
	public function tag_registered() {
		// Check if this function already called
		if ( ! in_array( 'tags_registered', self::$registered_flags ) ) {
			self::$registered_flags[] = 'tags_registered';
		} else {
			return; // Already registered
		}

		do_action( 'bricks/dynamic_data/tags_registered' );
	}

	/**
	 * Get a registered provider
	 *
	 * @since 1.9.9
	 */
	public static function get_registered_provider( $provider ) {
		return self::$providers[ $provider ] ?? null;
	}

	/**
	 * Get a provider instance for rendering without registering its tags.
	 *
	 * Normal admin requests skip provider registration to avoid interfering with
	 * custom-field plugins. CSS generation can still render a direct dynamic tag
	 * in that context, so instantiate only the requested provider on demand.
	 *
	 * @param string $provider Provider key.
	 * @return object|null
	 * @since 2.3.12
	 */
	private function get_provider_for_rendering( $provider ) {
		if ( ! in_array( $provider, $this->providers_keys, true ) ) {
			return null;
		}

		if ( isset( self::$providers[ $provider ] ) ) {
			return self::$providers[ $provider ];
		}

		$classname = 'Bricks\Integrations\Dynamic_Data\Providers\Provider_' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $provider ) ) );

		if ( ! class_exists( $classname ) || ! $classname::load_me() ) {
			return null;
		}

		self::$providers[ $provider ] = new $classname( str_replace( '-', '_', $provider ) );

		return self::$providers[ $provider ];
	}

	public function register_providers() {
		// Check if this function already called
		if ( ! in_array( 'register_providers', self::$registered_flags ) ) {
			self::$registered_flags[] = 'register_providers';
		} else {
			return; // Already registered
		}

		foreach ( $this->providers_keys as $provider ) {
			$classname = 'Bricks\Integrations\Dynamic_Data\Providers\Provider_' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $provider ) ) );

			// Check if required_hooks() method exists (@since 1.11)
			if ( class_exists( $classname ) && method_exists( $classname, 'required_hooks' ) ) {
				$classname::required_hooks();
			}

			/**
			 * Don't register providers in WP admin
			 *
			 * Interferes with default ACF logic of displaying ACF fields in the backend.
			 *
			 * @see #86byqukg8, #86bx5f831
			 *
			 * @since 1.9.9
			 */
			if ( is_admin() && ! bricks_is_ajax_call() && ! bricks_is_rest_call() ) {
				continue;
			}

			if ( $classname::load_me() ) {
				self::$providers[ $provider ] = new $classname( str_replace( '-', '_', $provider ) );
			}
		}
	}

	public function register_tags() {
		// Check if this function already called
		if ( ! in_array( 'register_tags', self::$registered_flags ) ) {
			self::$registered_flags[] = 'register_tags';
		} else {
			return; // Already registered
		}

		foreach ( self::$providers as $key => $provider ) {
			$this->tags = array_merge( $this->tags, $provider->get_tags() );
		}
	}

	public function get_tags() {
		return $this->tags;
	}

	/**
	 * Adds tags to the tags picker list (used in the builder)
	 *
	 * @param array $tags
	 * @return array
	 */
	public function add_tags_to_builder( $tags ) {
		$list = $this->get_tags();

		foreach ( $list as $tag ) {
			if ( isset( $tag['deprecated'] ) ) {
				continue;
			}

			// Get the field config, if any (@since 2.0)
			$field = isset( $tag['field'] ) ? $tag['field'] : false;

			$tag_data = [
				'name'                   => $tag['name'],
				'label'                  => $tag['label'],
				'group'                  => $tag['group'],
				'provider'               => $tag['provider'] ?? '', // @since 2.0,
				'queryFiltersExcludeTag' => $tag['queryFiltersExcludeTag'] ?? false, // @since 2.0.2
			];

			// Add field type to the tag if available (@since 2.0)
			if ( isset( $field['type'] ) ) {
				$tag_data['fieldType'] = $field['type'];
			}

			// Add CSS file that needs to be loaded for icon to work (@since 2.0)
			if (
				$tag_data['provider'] === 'metabox' &&
				! empty( $field['icon_css'] ) &&
				! is_object( $field['icon_css'] ) &&
				is_string( $field['icon_css'] )
				) {
				$tag_data['fieldIconCss'] = [
					'css'    => $field['icon_css'],
					'handle' => 'bricks-icon-' . md5( $field['icon_css'] ) . '-css', // Must match with handle in provider-metabox.php
				];
			}

			$tags[] = $tag_data;

		}

		return $tags;
	}

	/**
	 * Dynamic tag exists in $content: Replaces dynamic tag with requested data
	 *
	 * @param string  $content
	 * @param WP_Post $post
	 */
	public function render( $content, $post, $context = 'text' ) {
		$content = apply_filters( 'bricks/dynamic_data/provider_render_input', $content );
		// Get a list of tags to exclude from the Dynamic Data logic
		$exclude_tags = apply_filters( 'bricks/dynamic_data/exclude_tags', [] );

		/**
		 * STEP: Determine how many times we need to run the DD parser
		 *
		 * Previously we ran the parser by counting the number of open curly braces in the content. (@since 1.8)
		 * But this is not reliable because the content could contain curly braces in the code elements or any shortcodes.
		 * Causing the website to load extremely slow.
		 *
		 * @since 1.8.2 (#862jyyryg)
		 */
		// Get all registered tags except the excluded ones.
		// Example: [0 => "post_title", 1 => "woo_product_price", 2 => "echo"]
		$registered_tags = array_filter(
			array_keys( $this->get_tags() ),
			function( $tag ) use ( $exclude_tags ) {
				return ! in_array( $tag, $exclude_tags );
			}
		);
		$cache_key       = md5( serialize( $registered_tags ) ); // Unique cache key based on registered tags

		if ( ! isset( self::$registered_tags_set_cache[ $cache_key ] ) ) {
			// Create a set of registered tags for faster lookup (#86c93jt9n; @since 2.3.2)
			$maximum_name_length = 0;

			foreach ( $registered_tags as $tag ) {
				$maximum_name_length = max( $maximum_name_length, strlen( (string) $tag ) );
			}

			self::$registered_tags_set_cache[ $cache_key ] = [
				'tags'                => array_fill_keys( $registered_tags, true ),
				'maximum_name_length' => $maximum_name_length,
			];
		}

		$registered_tags_set = self::$registered_tags_set_cache[ $cache_key ]['tags'];
		$maximum_name_length = self::$registered_tags_set_cache[ $cache_key ]['maximum_name_length'];

		$dd_tags_in_content = [];

		// Find all dynamic data tags in the content
		$dd_tags_in_content = $this->extract_dynamic_data_tags( $content, $registered_tags_set, $maximum_name_length );

		$dd_tags_in_content = ! empty( $dd_tags_in_content[1] ) ? $dd_tags_in_content[1] : [];

		// Get the count of found dynamic data tags
		$dd_tag_count = count( $dd_tags_in_content );

		$max_attempts  = 10; // Prevent infinite loop (@since 2.2)
		$rerun_attemps = 0; // Count how many times we rerun the parser (@since 2.2)

		// STEP: Run the parser based on the count of found dynamic data tags
		for ( $i = 0; $i < $dd_tag_count; $i++ ) {
			$matches = $this->extract_dynamic_data_tags( $content, $registered_tags_set, $maximum_name_length );

			if ( empty( $matches[0] ) ) {
				return $content;
			}

			$run_again = false;

			foreach ( $matches[1] as $key => $match ) {
				$tag = $matches[0][ $key ];

				if ( in_array( $match, $exclude_tags ) ) {
					continue;
				}

				$value = $this->get_tag_value( $match, $post, $context );

				// Value is a WP_Error: Set value to false to avoid error in builder (#862k4cyc8)
				if ( is_a( $value, 'WP_Error' ) ) {
					$value = false;
				}

				// Value is null: Set value to false to avoid error (@since 1.12)
				if ( is_null( $value ) ) {
					$value = false;
				}

				// NOTE: Undocumented (only enable if really needed)
				$echo_everywhere = apply_filters( 'bricks/code/echo_everywhere', false );
				if ( $value && strpos( $value, '{echo:' ) !== false ) {
					if ( $echo_everywhere !== true ) {
						// Default: Stop the parser if the value contains an echo tag
						continue;
					}

					/**
					 * Certain tags might not be parsed correctly after {echo:}
					 *
					 * So we need to run the parser again later
					 *
					 * @since 1.9.9
					 */
					$run_again = true;
				}

				$content = str_replace( $tag, $value, $content );

				// Nested dynamic tag might not be fully parsed like {format_date}.
				// Avoid building oversized alternation regex from all registered tags (can hit PCRE2 compile limits on large sites). (#86c93jt9n; @since 2.3.2)
				if ( $this->has_supported_dynamic_tag( $content, $registered_tags_set, $maximum_name_length ) && $rerun_attemps < $max_attempts ) {
					$run_again = true;
					$rerun_attemps++;
				}
			}

			if ( $run_again ) {
				$dd_tag_count++;
			}
		}

		return $content;
	}

	/**
	 * Extract complete dynamic data tags from content.
	 *
	 * Uses registered tag names as the gate and reuses indexed closing boundaries
	 * while ignoring braces inside quoted arguments such as @fallback:'{post_title}'.
	 *
	 * @param string   $content             Content containing brace candidates.
	 * @param array    $registered_tags_set Registered tag names as array keys.
	 * @param int|null $maximum_name_length Optional cached longest registered name.
	 * @return array Matches in the same shape as preg_match_all(): [0] full tags, [1] inner tags.
	 *
	 * @since 2.4
	 */
	private function extract_dynamic_data_tags( $content, $registered_tags_set, $maximum_name_length = null ) {
		$matches = [
			0 => [],
			1 => [],
		];

		if ( strpos( $content, '{' ) === false ) {
			return $matches;
		}

		if ( $maximum_name_length === null ) {
			$maximum_name_length = 0;

			foreach ( $registered_tags_set as $name => $registered ) {
				$maximum_name_length = max( $maximum_name_length, strlen( (string) $name ) );
			}
		}

		[ $positions, $boundaries, $name_starts, $name_ends ] = $this->index_dynamic_data_boundaries( $content, $registered_tags_set, $maximum_name_length );
		$event_count = count( $positions );

		for ( $event = 0; $event < $event_count; $event++ ) {
			$start = $positions[ $event ];

			if ( $content[ $start ] !== '{' || $boundaries[ $event + 1 ] === 0 ) {
				continue;
			}

			$closing_event = intdiv( $boundaries[ $event + 1 ], 3 ) - 1;
			$end           = $positions[ $closing_event ];
			$name_start    = min( $name_starts[ $event ], $end );
			$name_end      = min( $name_ends[ $event ], $end );

			[ $base_tag, $base_end ] = $this->dynamic_data_tag_name_at( $content, $name_start, $name_end, $end, $registered_tags_set, $maximum_name_length );

			if ( $base_tag === null ) {
				continue;
			}

			/**
			 * PHP echo statements share a registered tag name, but their enclosing blocks are not dynamic tags.
			 *
			 * @since 2.4.4
			 * @see #123pt1nz8fn
			 */
			if ( $base_tag === 'echo' && $base_end < $end && $content[ $base_end ] !== ':' ) {
				$argument_start = $base_end + strspn( $content, " \t\n\r\0\x0B", $base_end );

				// PHP's @ error-control operator is not a named dynamic-data modifier.
				$is_named_modifier = false;

				if ( $argument_start < $end && $content[ $argument_start ] === '@' ) {
					// Keep recognition local: a failed regex can search the remaining content for its required colon.
					$modifier_start  = $argument_start + 1;
					$modifier_length = strspn( $content, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-', $modifier_start, $end - $modifier_start );
					$modifier_end    = $modifier_start + $modifier_length;

					$is_named_modifier = $modifier_length > 0 && $modifier_end < $end && $content[ $modifier_end ] === ':';

					if ( $is_named_modifier && $modifier_end + 1 < $end && $content[ $modifier_end + 1 ] === ':' ) {
						// Unquoted ::method(...) is PHP call syntax; literal call-shaped values can be quoted.
						$method_start  = $modifier_end + 2;
						$method_start += strspn( $content, " \t\n\r\0\x0B", $method_start, $end - $method_start );
						$method_end    = $method_start + strspn( $content, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_', $method_start, $end - $method_start );
						$call_start    = $method_end + strspn( $content, " \t\n\r\0\x0B", $method_end, $end - $method_end );

						if ( $method_end > $method_start && $call_start < $end && $content[ $call_start ] === '(' ) {
							$is_named_modifier = false;
						}
					}
				}

				if ( $argument_start < $end && ! $is_named_modifier ) {
					continue;
				}
			}

			// Keep the existing object-literal guard, including echo and taxonomy precedence.
			if ( $base_end < $end && $content[ $base_end ] === ':' ) {
				$argument_start      = $base_end + 1;
				$first_argument_char = $argument_start < $end ? $content[ $argument_start ] : '';

				$is_echo_callback = false;

				if ( $base_tag === 'echo' ) {
					while ( $argument_start < $end && strpos( " \t\n\r\0\x0B", $content[ $argument_start ] ) !== false ) {
						$argument_start++;
					}

					$is_echo_callback = $argument_start < $end && preg_match( '/[a-zA-Z0-9_]/', $content[ $argument_start ] );
				}

				// Registered taxonomy tags preserve literal whitespace, including : true (#123pt1nrptv).
				$is_terms_separator = strpos( $base_tag, 'post_terms_' ) === 0;

				$is_object_value = ( ctype_space( $first_argument_char ) && ! $is_terms_separator ) || in_array( $first_argument_char, [ '\'', '"', '{', '[' ], true );

				if ( ! $is_echo_callback && $is_object_value ) {
					continue;
				}
			}

			$matches[0][] = substr( $content, $start, $end - $start + 1 );
			$matches[1][] = substr( $content, $start + 1, $end - $start - 1 );
			$event        = $closing_event;
		}

		return $matches;
	}

	/**
	 * Index closing boundaries without rescanning a suffix for every opening brace.
	 *
	 * A nested scan must carry its outgoing quote state back to its parent. Quotes
	 * can open inside an unquoted nested block, but cannot close until that block
	 * ends (#86cagh1f8). The six states preserve that behavior without CSS heuristics
	 * while avoiding quadratic work on inline CSS (#123pt1nrmcc).
	 *
	 * @param string $content             Content containing brace candidates.
	 * @param array  $registered_tags_set Registered tag names as array keys.
	 * @param int    $maximum_name_length Longest registered name, not a tag length limit.
	 * @return array Event offsets, candidate boundaries, and trimmed name offsets.
	 *
	 * @since 2.4.3
	 */
	private function index_dynamic_data_boundaries( $content, $registered_tags_set, $maximum_name_length ) {
		$positions        = [];
		$quote_boundaries = [];
		$name_starts      = [];
		$name_ends        = [];
		$pending_names    = [];
		$pending_index    = 0;
		$content_length   = strlen( $content );

		for ( $position = 0; $position < $content_length; $position++ ) {
			// Skip ordinary text in C rather than visiting every byte for each scan state.
			$position += strcspn( $content, "{}'\"\\: ", $position );

			if ( $position >= $content_length ) {
				break;
			}

			$char = $content[ $position ];

			if ( $char === ':' || $char === ' ' ) {
				while ( isset( $pending_names[ $pending_index ] ) && $name_starts[ $pending_names[ $pending_index ] ] <= $position ) {
					$name_ends[ $pending_names[ $pending_index ] ] = $position;
					$pending_index++;
				}

				if ( $char === ' ' ) {
					$position += strspn( $content, ' ', $position ) - 1;
				}

				continue;
			}

			$event              = count( $positions );
			$positions[]        = $position;
			$quote_boundaries[] = 0;
			$name_starts[]      = 0;
			$name_ends[]        = $content_length;

			if ( $char === '{' ) {
				$name_starts[ $event ] = $position + 1 + strspn( $content, " \t\n\r\0\x0B", $position + 1 );
				$pending_names[]       = $event;
			} elseif ( $char === '\'' || $char === '"' ) {
				$previous = $position - 1;

				while ( $previous >= 0 && $content[ $previous ] === ' ' ) {
					$previous--;
				}

				$next = $position + 1 + strspn( $content, ' ', $position + 1 );

				// Only ASCII spaces are padding here; retain the existing apostrophe rules (#86c0c8ynd).
				if ( in_array( $previous >= 0 ? $content[ $previous ] : '', [ '(', ',', ':' ], true ) ) {
					$quote_boundaries[ $event ] |= 1;
				}

				if ( in_array( $next < $content_length ? $content[ $next ] : '', [ '', ',', ')', '}', '@' ], true ) ) {
					$quote_boundaries[ $event ] |= 2;
				}
			}
		}

		unset( $pending_names );
		$event_count = count( $positions );
		$boundaries  = [];

		// States 0..2 are candidate-level scans; 3..5 are nested scans. Quote modes: none, single, double.
		for ( $state = 0; $state < 6; $state++ ) {
			$boundaries[] = array_fill( 0, $event_count + 1, 0 );
		}

		for ( $event = $event_count - 1; $event >= 0; $event-- ) {
			$position         = $positions[ $event ];
			$char             = $content[ $position ];
			$supported_nested = false;

			if ( $char === '{' ) {
				[ $base_tag ]     = $this->dynamic_data_tag_name_at( $content, $name_starts[ $event ], $name_ends[ $event ], $content_length, $registered_tags_set, $maximum_name_length );
				$supported_nested = $base_tag !== null;
			}

			for ( $state = 0; $state < 6; $state++ ) {
				$quote  = $state % 3;
				$nested = $state >= 3;
				$next   = $event + 1;

				if ( $char === '\\' && $quote !== 0 ) {
					// Escaping consumes one byte, which may itself be an indexed event.
					if ( $next < $event_count && $positions[ $next ] === $position + 1 ) {
						$next++;
					}
				} elseif ( $char === '{' && ( $quote === 0 || $supported_nested ) ) {
					$child = $boundaries[ 3 + $quote ][ $next ];

					// Zero also memoizes unfinished scans; subsequent braces must not repeat them.
					$boundaries[ $state ][ $event ] = $child === 0 ? 0 : $boundaries[ ( $nested ? 3 : 0 ) + ( $child % 3 ) ][ intdiv( $child, 3 ) ];
					continue;
				} elseif ( $char === '}' && ( $nested || $quote === 0 ) ) {
					// Encode the boundary event and outgoing quote in one integer; zero means no boundary.
					$boundaries[ $state ][ $event ] = ( $event + 1 ) * 3 + $quote;
					continue;
				} elseif ( $char === '\'' || $char === '"' ) {
					$char_quote = $char === '\'' ? 1 : 2;

					if ( $quote === 0 && ( $quote_boundaries[ $event ] & 1 ) ) {
						$quote = $char_quote;
					} elseif ( ! $nested && $quote === $char_quote && ( $quote_boundaries[ $event ] & 2 ) ) {
						$quote = 0;
					}
				}

				$boundaries[ $state ][ $event ] = $boundaries[ ( $nested ? 3 : 0 ) + $quote ][ $next ];
			}
		}

		return [ $positions, $boundaries[0], $name_starts, $name_ends ];
	}

	/**
	 * Recognize a base tag at indexed offsets without copying the remaining content.
	 *
	 * @param string $content             Original content.
	 * @param int    $start               First character after leading ltrim() padding.
	 * @param int    $name_end            Legacy first-colon/space boundary.
	 * @param int    $end                 End of the candidate, exclusive.
	 * @param array  $registered_tags_set Registered tag names as array keys.
	 * @param int    $maximum_name_length Longest registered name.
	 * @return array Recognized name (null if unsupported) and its ending offset.
	 *
	 * @since 2.4.3
	 */
	private function dynamic_data_tag_name_at( $content, $start, $name_end, $end, $registered_tags_set, $maximum_name_length ) {
		$prefix = substr( $content, $start, min( 3, $end - $start ) );

		// Arbitrary cf_ fields are supported without registration or a name-length bound (#862k3f2md).
		if ( $prefix === 'cf_' ) {
			return [ 'cf_', $name_end ];
		}

		$registered_tag = null;

		if ( $prefix === 'je_' ) {
			// Longer substrings cannot match the registry; this is not a fixed limit on valid names.
			$scan_end = min( $end, $start + $maximum_name_length );

			for ( $position = $start + 1; $position <= $scan_end; $position++ ) {
				$separator = $position < $end ? $content[ $position ] : '';

				if ( $separator !== '' && $separator !== ':' && $separator !== '}' && ! ctype_space( $separator ) ) {
					continue;
				}

				$candidate = rtrim( substr( $content, $start, $position - $start ) );

				if ( isset( $registered_tags_set[ $candidate ] ) ) {
					$registered_tag = $candidate;
				}

				if ( $separator === '}' ) {
					break;
				}
			}
		}

		if ( $registered_tag !== null ) {
			return [ $registered_tag, $start + strlen( $registered_tag ) ];
		}

		if ( $name_end - $start <= $maximum_name_length ) {
			$candidate = substr( $content, $start, $name_end - $start );

			if ( isset( $registered_tags_set[ $candidate ] ) ) {
				return [ $candidate, $name_end ];
			}
		}

		return [ null, $name_end ];
	}

	/**
	 * Check whether content still contains any supported dynamic data tag.
	 *
	 * @param string $content             Content to check.
	 * @param array  $registered_tags_set Registered tag names as array keys.
	 * @param int    $maximum_name_length Cached longest registered name.
	 * @return bool
	 *
	 * @since 2.3.2
	 */
	private function has_supported_dynamic_tag( $content, $registered_tags_set, $maximum_name_length ) {
		$matches = $this->extract_dynamic_data_tags( $content, $registered_tags_set, $maximum_name_length );

		return ! empty( $matches[0] );
	}

	/**
	 * Get the value of a dynamic data tag
	 *
	 * @param string  $tag without curly brackets {}.
	 * @param WP_Post $post The post object.
	 * @param string  $context text, link, image.
	 */
	public function get_tag_value( $tag, $post, $context = 'text' ) {
		// Parse the tag and extract arguments
		$parser = new \Bricks\Integrations\Dynamic_Data\Dynamic_Data_Parser();
		$parsed = $parser->parse( $tag );

		$tag          = $parsed['tag'] ?? $tag;
		$args         = $parsed['args'];
		$original_tag = $parsed['original_tag'];
		$value        = null;
		$provider     = null;

		// Return original tag if "raw" argument is set (#86c5heted; @since 2.1)
		if ( is_array( $args ) && in_array( 'raw', $args, true ) ) {
			// Remove all :raw from original tag.
			$original_tag = str_replace( ':raw', '', $original_tag );

			// Nested tags must also use entities or the render rerun will resolve them despite the outer :raw filter. (#86caqdew9; @since 2.4)
			$original_tag = str_replace( [ '{', '}' ], [ '&#123;', '&#125;' ], $original_tag );
			$value        = '&#123;' . $original_tag . '&#125;';
		} else {
			$tags = $this->get_tags();

			if ( ! array_key_exists( $tag, $tags ) ) {
				// Last resort: Try to get field content if it is a WordPress custom field
				if ( strpos( $tag, 'cf_' ) === 0 ) {
					$provider          = 'wp';
					$provider_instance = $this->get_provider_for_rendering( $provider );

					// Use get_tag_value function in provider-wp.php (@since 1.9.8)
					$value = $provider_instance ? $this->get_provider_tag_value( $provider_instance, $tag, $post, $args, $context ) : null;
				} else {
					/**
					 * If true, Bricks replaces not existing DD tags with an empty string
					 *
					 * true caused unwanted replacement of inline <script> & <style> tag data.
					 *
					 * Set to false @since 1.4 to render all non-matching DD tags (#2ufh0uf)
					 *
					 * https://academy.bricksbuilder.io/article/filter-bricks-dynamic_data-replace_nonexistent_tags/
					 */
					$replace_tag = apply_filters( 'bricks/dynamic_data/replace_nonexistent_tags', false );
					$value       = $replace_tag ? '' : '{' . $original_tag . '}';
				}
			} else {
				$provider = $tags[ $tag ]['provider'];
				$value    = $this->get_provider_tag_value( self::$providers[ $provider ], $tag, $post, $args, $context );
			}
		}

		/**
		 * Action hook fired after a dynamic data tag value is retrieved
		 *
		 * Allows tracking/logging of all parsed dynamic data tag values
		 *
		 * @param mixed   $value The parsed tag value
		 * @param string  $tag The tag name (without arguments)
		 * @param string  $original_tag The original tag string (with arguments)
		 * @param array   $args The parsed arguments
		 * @param WP_Post $post The post object
		 * @param string  $context The context (text, link, image)
		 * @param string|null $provider The provider name (if available)
		 *
		 * @since 2.2 #86c4tzdxq
		 */
		do_action( 'bricks/dynamic_data/tag_value_parsed', $value, $tag, $original_tag, $args, $post, $context, $provider );

		return $value;
	}

	/**
	 * Invoke a provider with literal arguments without exposing them to parser reruns.
	 *
	 * @param object $provider Provider instance.
	 * @param string $tag      Parsed tag name.
	 * @param object $post     Post context.
	 * @param array  $args     Parsed arguments.
	 * @param string $context Render context.
	 * @return mixed Provider result.
	 * @since 2.4.3
	 */
	private function get_provider_tag_value( $provider, $tag, $post, $args, $context ) {
		$provider_args = apply_filters( 'bricks/dynamic_data/provider_args', $args, $tag );
		try {
			$value = $provider->get_tag_value( $tag, $post, $provider_args, $context );
			return apply_filters( 'bricks/dynamic_data/provider_result', $value, $tag, $args );
		} finally {
			do_action( 'bricks/dynamic_data/provider_finished', $tag );
		}
	}

	/**
	 * Handle fallbacks for dynamic data tags
	 *
	 * @since 1.10
	 */
	public function handle_fallback( $value, $tag, $post_id, $filters, $context ) {
		// STEP: Check if the value is empty based on value type (#86c15qkw6)
		$is_empty_value = is_string( $value ) && $value === '' || is_array( $value ) && empty( $value ) || is_null( $value );

		// STEP: Check for fallback argument
		if ( $is_empty_value && isset( $filters['fallback'] ) ) {
			// Remove the single quotes and handle escaped characters
			$fallback = stripslashes( $filters['fallback'] );

			if ( substr( $fallback, 0, 1 ) === "'" && substr( $fallback, -1 ) === "'" ) {
				$fallback = substr( $fallback, 1, -1 );
			}

			if ( is_string( $fallback ) && strpos( $fallback, '{' ) !== false ) {
				$fallback = self::render_content( $fallback, $post_id, $context );
			}

			return $fallback;
		}

		// STEP: Check for fallback-image arugment
		if ( $is_empty_value && isset( $filters['fallback-image'] ) ) {
			// Remove the single quotes and handle escaped characters
			$fallback_image = stripslashes( $filters['fallback-image'] );

			if ( substr( $fallback_image, 0, 1 ) === "'" && substr( $fallback_image, -1 ) === "'" ) {
				$fallback_image = substr( $fallback_image, 1, -1 );
			}

			// Check if the fallback is a numeric ID
			if ( is_numeric( $fallback_image ) ) {
				$attachment_id = intval( $fallback_image );

				if ( $context === 'image' ) {
					return [ $attachment_id ];
				}

				$image = wp_get_attachment_image( $attachment_id, 'full' );
				if ( $image ) {
					return $image;
				}
			} else {
				if ( $context === 'image' ) {
					return [ $fallback_image ];
				}

				// Assume fallback is an image URL
				return '<img src="' . esc_url( $fallback_image ) . '" />';
			}
		}

		return $value;
	}

	public static function render_tag( $tag = '', $post_id = 0, $context = 'text', $args = [] ) {
		// Support for dynamic data picker and input text (@since 1.5)
		$tag = ! empty( $tag['name'] ) ? $tag['name'] : (string) $tag;

		$tag = $original_tag = trim( $tag );

		$tag_has_curly = substr( $tag, 0, 1 ) === '{' && substr( $tag, -1 ) === '}';

		// Only remove outermost curly brackets from DD tag (@since 1.9.9)
		if ( $tag_has_curly ) {
			$tag = substr( $tag, 1, -1 );
		}

		// Image is user avatar (get_avatar_url): Set the size
		if ( $context === 'image' && in_array( $tag, [ 'wp_user_picture', 'author_avatar' ] ) && isset( $args['size'] ) ) {
			$all_image_sizes = \Bricks\Setup::get_image_sizes();

			if ( ! empty( $all_image_sizes[ $args['size'] ]['width'] ) ) {
				$tag = $tag . ':' . abs( $all_image_sizes[ $args['size'] ]['width'] );
			}
		}

		// Preserve Gutenberg preview autosaves for dynamic tags such as {post_content}
		// (#86c9wy4u5; @since 2.3.6).
		$post = \Bricks\Helpers::get_post_preserving_preview( $post_id );

		$value = apply_filters( 'bricks/dynamic_data/render_tag', $tag, $post, $context );

		// STEP: Parse nested dynamic tags for image context only, may extend to all contexts (#86c3nyng1; @since 2.2)
		// Only parse if the original tag is dynamic tag (starts with '{' and ends with '}')
		if ( $context === 'image' && $tag_has_curly ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					if ( is_string( $item ) && substr( $item, 0, 1 ) === '{' && substr( $item, -1 ) === '}' && $item !== $original_tag ) {
						$value[ $key ] = self::parse_nested_tags( $item, $post_id, $context, $args );
					}
				}
			} elseif ( is_string( $value ) ) {
				if ( substr( $value, 0, 1 ) === '{' && substr( $value, -1 ) === '}' && $value !== $original_tag ) {
					$value = self::parse_nested_tags( $value, $post_id, $context, $args );
				}
			}
		}

		return $value;
	}

	/**
	 * Parse nested dynamic tags recursively
	 * #86c3nyng1
	 *
	 * @since 2.2
	 * @param string $tag Dynamic tag
	 * @param int    $post_id Post ID
	 * @param string $context Context (text, image, link)
	 * @param array  $args Additional arguments
	 * @return string Parsed content
	 */
	private static function parse_nested_tags( $tag, $post_id, $context, $args ) {
		if ( ! is_string( $tag ) || substr( $tag, 0, 1 ) !== '{' || substr( $tag, -1 ) !== '}' ) {
			// Not a valid nested tag, return as is
			return $tag;
		}

		$max_attempts = 10;
		$attempts     = 0;

		while ( strpos( $tag, '{' ) !== false && $attempts < $max_attempts ) {
			$previous_value = $tag;

			// Remove outermost curly brackets
			if ( substr( $tag, 0, 1 ) === '{' && substr( $tag, -1 ) === '}' ) {
				$nested_tag = substr( $tag, 1, -1 );
			} else {
				// Content has curly brackets but not wrapped, break to avoid infinite loop
				break;
			}

			// Recursively call render_tag to parse the nested tag
			$nested_value = self::render_tag( $nested_tag, $post_id, $context, $args );

			// Handle different return types
			if ( is_array( $nested_value ) && ! empty( $nested_value ) ) {
				$tag = $nested_value[0];
			} elseif ( is_string( $nested_value ) ) {
				$tag = $nested_value;
			} else {
				// Unsupported type, break to avoid infinite loop
				break;
			}

			// Break if value didn't change (prevents infinite loop)
			if ( $tag === $previous_value ) {
				break;
			}

			$attempts++;
		}

		return $tag;
	}

	public static function render_content( $content, $post_id = 0, $context = 'text' ) {
		// Return: Content is a flat array (Example: 'user_role' element conditions @since 1.5.6)
		if ( is_array( $content ) && isset( $content[0] ) ) {
			return $content;
		}

		// Support for dynamic data picker and input text (@since 1.5)
		$content = ! empty( $content['name'] ) ? $content['name'] : (string) $content;

		// Return: $content doesn't contain opening DD tag character '{' (@since 1.5)
		if ( strpos( $content, '{' ) === false ) {
			return $content;
		}

		// Strip slashes for DD "echo" function to allow DD preview render in builder (@since 1.5.3)
		if ( strpos( $content, '{echo:' ) !== false ) {
			$content = stripslashes( $content );
		}

		$post_id = empty( $post_id ) ? get_the_ID() : $post_id;
		$post    = \Bricks\Helpers::get_post_preserving_preview( $post_id );

		// Get the post object if we are in a loop and the loop object type is not a post (@since 1.11) (#86c0arcxn)
		if ( \Bricks\Query::is_looping() && \Bricks\Query::get_loop_object_type() !== 'post' ) {
			$post = get_post();
		}

		// Set the correct post when previewing (@since 1.12; #86bw6re4w)
		if ( \Bricks\Helpers::is_bricks_preview() ) {

			if ( ! \Bricks\Query::is_looping() && isset( \Bricks\Database::$page_data['preview_or_post_id'] ) ) {
				$post = get_post( \Bricks\Database::$page_data['preview_or_post_id'] );
			}

		}

		// Rendering dynamic data in nested query (Before Query run)
		// Previously applied in is_bricks_preview only, but it should apply to the frontend as well (#86c3ynnup; @since 2.0)
		if ( ! \Bricks\Query::is_looping() && \Bricks\Query::is_any_looping() ) {
			$loop_object_type = \Bricks\Query::get_loop_object_type( \Bricks\Query::is_any_looping() );
			$loop_object      = \Bricks\Query::get_loop_object( \Bricks\Query::is_any_looping() );

			if ( $loop_object_type === 'post' ) {
				$post = $loop_object;
			}
		}

		return apply_filters( 'bricks/dynamic_data/render_content', $content, $post, $context );
	}

	public static function get_dynamic_tags_list() {
		// NOTE: Undocumented. This allows the dynamic data providers to add their tags to the builder
		$tags = apply_filters( 'bricks/dynamic_tags_list', [] );

		return $tags;
	}

	/**
	 * Get a list of all supported dynamic data tags - for builder
	 *
	 * @since 1.12
	 */
	public static function get_query_supported_tags_list() {
		$tags = [];

		foreach ( self::$providers as $provider ) {
			if ( method_exists( $provider, 'get_query_supported_tags' ) ) {
				$tags = array_merge( $tags, $provider->get_query_supported_tags() );
			}
		}

		return $tags;
	}

	/**
	 * Get a list of all supported dynamic data tags that can use Result Filters (array_conditions)
	 *
	 * @since 2.2
	 */
	public static function get_array_supported_tags_list() {
		$tags = [];

		foreach ( self::$providers as $provider ) {
			if ( method_exists( $provider, 'get_array_supported_tags' ) ) {
				$tags = array_merge( $tags, $provider->get_array_supported_tags() );
			}
		}

		return $tags;
	}
}
