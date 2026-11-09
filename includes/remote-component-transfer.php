<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Build and import portable remote component packages.
 *
 * Remote components are copied into the local component store. Their remote
 * identifiers are never trusted as local identifiers; provenance keeps repeat
 * imports deterministic without adding fields to the component schema.
 *
 * @since 2.4
 */
class Remote_Component_Transfer {
	const SCHEMA_VERSION = 1;

	/**
	 * Build a paginated component catalog.
	 *
	 * @param array $args     Catalog filters.
	 * @param array $excluded Excluded component IDs.
	 * @return array
	 */
	public static function build_catalog( $args = [], $excluded = [] ) {
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page   = min( 100, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
		$search     = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		$category   = trim( (string) ( $args['category'] ?? '' ) );
		$excluded   = array_fill_keys( array_map( 'strval', is_array( $excluded ) ? $excluded : [] ), true );
		$components = [];

		foreach ( Component_Repository::get_all() as $component ) {
			$component_id = (string) ( $component['id'] ?? '' );

			if ( ! $component_id || isset( $excluded[ $component_id ] ) ) {
				continue;
			}

			$label = self::get_component_label( $component );

			if ( $search && strpos( strtolower( $label . ' ' . ( $component['desc'] ?? '' ) ), $search ) === false ) {
				continue;
			}

			if ( $category && (string) ( $component['category'] ?? '' ) !== $category ) {
				continue;
			}

			$components[] = [
				'id'        => $component_id,
				'label'     => $label,
				'category'  => (string) ( $component['category'] ?? '' ),
				'thumbnail' => esc_url_raw( $component['thumbnail'] ?? '' ),
			];
		}

		usort(
			$components,
			function( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		$total = count( $components );
		$items = array_slice( $components, ( $page - 1 ) * $per_page, $per_page );

		return [
			'protocol' => Remote_Library::PROTOCOL,
			'revision' => Component_Repository::get_design_system_version(),
			'items'    => array_values( $items ),
			'total'    => $total,
			'page'     => $page,
			'perPage'  => $per_page,
			'pages'    => $total ? (int) ceil( $total / $per_page ) : 0,
		];
	}

	/**
	 * Build a dependency-complete component package.
	 *
	 * @param string $component_id Root component ID.
	 * @param array  $excluded     Excluded component IDs.
	 * @return array|\WP_Error
	 */
	public static function build_package( $component_id, $excluded = [] ) {
		$graph = self::collect_component_graph( $component_id, $excluded );

		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$components = array_map( [ self::class, 'portable_component' ], $graph );
		$package    = [
			'manifest'     => [
				'protocol'       => Remote_Library::PROTOCOL,
				'schemaVersion'  => self::SCHEMA_VERSION,
				'resource'       => 'component',
				'rootId'         => (string) $component_id,
				'sourceVersion'  => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
				'sourceRevision' => Component_Repository::get_design_system_version(),
			],
			'components'   => $components,
			'dependencies' => self::build_dependencies( $components ),
			'warnings'     => self::collect_portability_warnings( $components ),
		];

		$package['manifest']['checksum'] = self::package_checksum( $package );

		return $package;
	}

	/**
	 * Build a component package for component instances in arbitrary elements.
	 *
	 * @param array $elements Element rows.
	 * @param array $excluded Excluded component IDs.
	 * @param array $template Additional template settings and theme styles.
	 * @return array|\WP_Error
	 */
	public static function build_package_for_elements( $elements, $excluded = [], $template = [] ) {
		$components = [];

		foreach ( self::collect_component_reference_ids( $elements ) as $component_id ) {
			$graph = self::collect_component_graph( $component_id, $excluded );

			if ( is_wp_error( $graph ) ) {
				return $graph;
			}

			foreach ( $graph as $component ) {
				$components[ $component['id'] ] = self::portable_component( $component );
			}
		}

		$components       = array_values( $components );
		$property_lookup  = self::build_component_class_property_lookup( $components );
		$instance_classes = self::collect_instance_class_ids( [ $elements, $components ], $property_lookup );

		return [
			'components'   => $components,
			'dependencies' => self::build_dependencies( [ $components, $elements, $template, [ '_cssGlobalClasses' => $instance_classes ] ] ),
		];
	}

	/**
	 * Collect class property overrides, including nested component instances.
	 *
	 * @since 2.4.1
	 *
	 * @param array $data Elements and component definitions.
	 * @param array $lookup Class property IDs by component ID.
	 * @return array
	 */
	private static function collect_instance_class_ids( $data, $lookup ) {
		$ids = [];
		foreach ( $lookup[ $data['cid'] ?? '' ] ?? [] as $property_id ) {
			$ids = array_merge( $ids, self::flatten_scalar_values( $data['properties'][ $property_id ] ?? [] ) );
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$ids = array_merge( $ids, self::collect_instance_class_ids( $value, $lookup ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Validate the envelope before exposing or applying a template package.
	 *
	 * @since 2.4.1
	 *
	 * @param array $package Remote template package.
	 * @return true|\WP_Error
	 */
	private static function validate_template_package( $package ) {
		$manifest = is_array( $package['manifest'] ?? null ) ? $package['manifest'] : [];

		if (
			( $manifest['protocol'] ?? '' ) !== Remote_Library::PROTOCOL ||
			(int) ( $manifest['schemaVersion'] ?? 0 ) !== self::SCHEMA_VERSION ||
			( $manifest['resource'] ?? '' ) !== 'template' ||
			empty( $manifest['checksum'] ) ||
			! hash_equals( (string) $manifest['checksum'], self::package_checksum( $package ) ) ||
			! is_array( $package['template'] ?? null ) ||
			! is_array( $package['components'] ?? null ) ||
			! is_array( $package['dependencies'] ?? [] ) ||
			! is_array( $package['dependencies']['globalClasses'] ?? [] )
		) {
			return new \WP_Error( 'remote_template_invalid_package', esc_html__( 'The remote template package is invalid.', 'bricks' ), [ 'status' => 400 ] );
		}

		return true;
	}

	/**
	 * Expose portable dependencies to the template review without importing them.
	 *
	 * @since 2.4.1
	 *
	 * @param array $package Remote template package.
	 * @return array|\WP_Error
	 */
	public static function template_for_review( $package ) {
		$valid = self::validate_template_package( $package );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$template               = $package['template'];
		$template['components'] = $package['components'] ?? [];

		foreach ( [
			'globalClasses'             => 'global_classes',
			'globalVariables'           => 'globalVariables',
			'globalVariablesCategories' => 'globalVariablesCategories',
			'colorPalette'              => 'colorPalette'
		] as $key => $template_key ) {
			$template[ $template_key ] = $package['dependencies'][ $key ] ?? [];
		}

		return $template;
	}

	/**
	 * Inspect a package against the local component store.
	 *
	 * @param array  $package     Remote package.
	 * @param string $source_id   Configured source ID.
	 * @param string $source_name Configured source label.
	 * @param array  $options Reviewed import options.
	 * @return array|\WP_Error
	 */
	public static function inspect( $package, $source_id, $source_name, $options = [] ) {
		$valid = self::validate_package( $package );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$root_id          = (string) $package['manifest']['rootId'];
		$package_checksum = self::import_checksum( $package, $options );
		$provenance       = Component_Repository::get_provenance();
		$key              = self::provenance_key( $source_id, $root_id );
		$record           = is_array( $provenance[ $key ] ?? null ) ? $provenance[ $key ] : [];
		$local_components = Component_Repository::get_all();
		$local            = self::find_component( $local_components, $record['localComponentId'] ?? '' );
		$status           = 'new';
		$has_changed      = false;

		if ( $local ) {
			$status = ( $record['packageChecksum'] ?? '' ) === $package_checksum ? 'identical' : 'changed';
		}

		foreach ( $package['components'] as $component ) {
			$remote_id        = (string) ( $component['id'] ?? '' );
			$component_key    = self::provenance_key( $source_id, $remote_id );
			$component_record = is_array( $provenance[ $component_key ] ?? null ) ? $provenance[ $component_key ] : [];

			if (
				! empty( $component_record['localComponentId'] ) &&
				( $component_record['componentChecksum'] ?? '' ) !== self::checksum( self::portable_component( $component ) )
			) {
				$has_changed = true;
			}
		}

		if ( $has_changed ) {
			$status = 'changed';
		}

		$root_component = self::find_component( $package['components'], $root_id );
		$label          = self::get_component_label( $root_component );
		$local_labels   = array_map( 'strtolower', array_map( [ self::class, 'get_component_label' ], $local_components ) );
		$label_conflict = false;

		foreach ( $local_components as $component ) {
			if ( $local && ( $component['id'] ?? '' ) === ( $local['id'] ?? '' ) ) {
				continue;
			}

			if ( strcasecmp( self::get_component_label( $component ), $label ) === 0 ) {
				$label_conflict = true;
				break;
			}
		}

		$warnings = array_values( (array) ( $package['warnings'] ?? [] ) );

		if ( empty( Elements::$elements ) ) {
			Elements::load_elements();
		}

		$supported_element_names = [];

		foreach ( Elements::$elements as $element_key => $element_definition ) {
			$supported_element_names[] = (string) ( $element_definition['name'] ?? $element_key );
		}

		foreach ( $package['components'] as $component ) {
			foreach ( (array) ( $component['elements'] ?? [] ) as $element ) {
				$element_name = (string) ( $element['name'] ?? '' );

				if ( $element_name && ! in_array( $element_name, $supported_element_names, true ) ) {
					$warnings[] = 'element:' . $element_name;
				}
			}
		}

		return [
			'status'              => $status,
			'rootId'              => $root_id,
			'label'               => $label,
			'suggestedLabel'      => $local ? self::get_component_label( $local ) : ( $label_conflict ? self::unique_label( $label, $local_labels ) : $label ),
			'labelConflict'       => $label_conflict,
			'warnings'            => array_values( array_unique( $warnings ) ),
			'review'              => self::build_import_review( $package, $source_id, $source_name, $provenance, $local_components ),
			'designSystemVersion' => Component_Repository::get_design_system_version(),
		];
	}

	/**
	 * Describe the outcome of every component and dependency before import.
	 *
	 * The review is informational: component dependencies form one portable graph,
	 * so matching items are skipped while missing or conflicting items are imported
	 * using the same deterministic rules as apply().
	 *
	 * @param array  $package          Remote component package.
	 * @param string $source_id        Configured source ID.
	 * @param string $source_name      Configured source label.
	 * @param array  $provenance       Existing remote component provenance.
	 * @param array  $local_components Local component definitions.
	 * @return array
	 */
	private static function build_import_review( $package, $source_id, $source_name, $provenance, $local_components ) {
		$review       = [
			'components'      => [],
			'globalClasses'   => [],
			'globalVariables' => [],
			'colorPalette'    => [],
		];
		$local_labels = array_map( 'strtolower', array_map( [ self::class, 'get_component_label' ], $local_components ) );

		foreach ( (array) ( $package['components'] ?? [] ) as $component ) {
			$remote_id    = (string) ( $component['id'] ?? '' );
			$record       = $provenance[ self::provenance_key( $source_id, $remote_id ) ] ?? [];
			$local        = self::find_component( $local_components, $record['localComponentId'] ?? '' );
			$label        = self::get_component_label( $component );
			$checksum     = self::checksum( self::portable_component( $component ) );
			$status       = 'import';
			$result_label = $label;

			if ( $local ) {
				$status = ( $record['componentChecksum'] ?? '' ) === $checksum ? 'skip' : 'replace';
			} elseif ( in_array( strtolower( $label ), $local_labels, true ) ) {
				$status       = 'rename';
				$result_label = self::unique_label( $label, $local_labels );
			}

			$review['components'][] = [
				'id'          => $remote_id,
				'label'       => $label,
				'resultLabel' => $result_label,
				'status'      => $status,
			];

			if ( $status !== 'skip' ) {
				$local_labels[] = strtolower( $result_label );
			}
		}

		$dependencies  = is_array( $package['dependencies'] ?? null ) ? $package['dependencies'] : [];
		$local_classes = self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES );

		foreach ( (array) ( $dependencies['globalClasses'] ?? [] ) as $class ) {
			$label       = (string) ( $class['name'] ?? '' );
			$id_match    = self::find_row_by_field( $local_classes, 'id', (string) ( $class['id'] ?? '' ) );
			$name_match  = self::find_row_by_field( $local_classes, 'name', $label );
			$exact_match = null;

			if ( ! $name_match ) {
				foreach ( $local_classes as $local_class ) {
					if ( strcasecmp( (string) ( $local_class['name'] ?? '' ), $label ) === 0 ) {
						$name_match = $local_class;
						break;
					}
				}
			}

			foreach ( $local_classes as $local_class ) {
				$local_name              = (string) ( $local_class['name'] ?? '' );
				$source_qualified_name   = self::source_label( $label, $source_name );
				$is_previous_remote_copy = $local_name === $source_qualified_name || strpos( $local_name, $source_qualified_name . ' (' ) === 0;

				if (
					self::dependency_rows_equal( $local_class, $class, [ 'id', 'category', '_categoryData' ] ) ||
					( $is_previous_remote_copy && self::dependency_rows_equal( $local_class, $class, [ 'id', 'name', 'category', '_categoryData' ] ) )
				) {
					$exact_match = $local_class;
					break;
				}
			}

			$local_match  = $id_match ? $id_match : ( $name_match ? $name_match : $exact_match );
			$status       = $local_match ? 'skip' : 'import';
			$result_label = $local_match ? (string) ( $local_match['name'] ?? $label ) : $label;

			$review['globalClasses'][] = [
				'id'          => (string) ( $class['id'] ?? '' ),
				'label'       => $label,
				'resultLabel' => $result_label,
				'status'      => $status,
			];
		}

		$referenced_class_ids = self::collect_class_ids( $package['components'] ?? [] );
		$packaged_class_ids   = array_values(
			array_filter(
				array_map( 'strval', array_column( (array) ( $dependencies['globalClasses'] ?? [] ), 'id' ) )
			)
		);
		$missing_class_ids    = array_values(
			array_unique(
				array_merge(
					array_diff( $referenced_class_ids, $packaged_class_ids ),
					(array) ( $dependencies['_missing']['globalClasses'] ?? [] )
				)
			)
		);

		foreach ( $missing_class_ids as $class_id ) {
			$local_match = self::find_row_by_field( $local_classes, 'id', (string) $class_id );

			$review['globalClasses'][] = [
				'id'          => (string) $class_id,
				'label'       => (string) $class_id,
				'resultLabel' => '',
				'status'      => $local_match ? 'skip' : 'missing',
			];
		}

		$local_variables = self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES );

		foreach ( (array) ( $dependencies['globalVariables'] ?? [] ) as $variable ) {
			$label      = (string) ( $variable['name'] ?? '' );
			$id_match   = self::find_row_by_field( $local_variables, 'id', (string) ( $variable['id'] ?? '' ) );
			$name_match = self::find_row_by_field( $local_variables, 'name', $label );

			if ( ! $name_match ) {
				foreach ( $local_variables as $local_variable ) {
					if ( strcasecmp( (string) ( $local_variable['name'] ?? '' ), $label ) === 0 ) {
						$name_match = $local_variable;
						break;
					}
				}
			}

			$local_match = $id_match ? $id_match : $name_match;

			if ( $local_match ) {
				$status       = 'skip';
				$result_label = (string) ( $local_match['name'] ?? $label );
			} else {
				$status       = 'import';
				$result_label = $label;
			}

			$review['globalVariables'][] = [
				'id'           => (string) ( $variable['id'] ?? '' ),
				'label'        => $label,
				'resultLabel'  => $result_label,
				'currentValue' => (string) ( $local_match['value'] ?? '' ),
				'value'        => (string) ( $variable['value'] ?? '' ),
				'status'       => $status,
			];
		}

		$local_palettes = self::get_dependency_option( BRICKS_DB_COLOR_PALETTE );

		foreach ( (array) ( $dependencies['colorPalette'] ?? [] ) as $palette ) {
			$label      = (string) ( $palette['name'] ?? '' );
			$id_match   = self::find_row_by_field( $local_palettes, 'id', (string) ( $palette['id'] ?? '' ) );
			$name_match = self::find_row_by_field( $local_palettes, 'name', $label );

			if ( ! $name_match ) {
				foreach ( $local_palettes as $local_palette ) {
					if ( strcasecmp( (string) ( $local_palette['name'] ?? '' ), $label ) === 0 ) {
						$name_match = $local_palette;
						break;
					}
				}
			}

			$local_match = $id_match ? $id_match : $name_match;

			if ( ! $local_match ) {
				foreach ( $local_palettes as $local_palette ) {
					$local_name              = (string) ( $local_palette['name'] ?? '' );
					$source_qualified_name   = self::source_label( $label, $source_name );
					$is_previous_remote_copy = $local_name === $source_qualified_name || strpos( $local_name, $source_qualified_name . ' (' ) === 0;

					if ( $is_previous_remote_copy && self::palette_signature( $local_palette, true ) === self::palette_signature( $palette, true ) ) {
						$local_match = $local_palette;
						break;
					}
				}
			}

			$status       = $local_match ? 'skip' : 'import';
			$result_label = $local_match ? (string) ( $local_match['name'] ?? $label ) : $label;

			$review['colorPalette'][] = [
				'id'          => (string) ( $palette['id'] ?? '' ),
				'label'       => $label,
				'resultLabel' => $result_label,
				'count'       => count( (array) ( $palette['colors'] ?? [] ) ),
				'status'      => $status,
			];
		}

		return $review;
	}

	/**
	 * Apply a previously inspected remote package.
	 *
	 * @param array  $package     Remote package.
	 * @param string $source_id   Configured source ID.
	 * @param string $source_name Configured source label.
	 * @param array  $options     Import options.
	 * @return array|\WP_Error
	 */
	public static function apply( $package, $source_id, $source_name, $options = [] ) {
		$valid = self::validate_package( $package );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$expected_version = isset( $options['expectedDesignSystemVersion'] ) ? (int) $options['expectedDesignSystemVersion'] : null;
		$current_version  = Component_Repository::get_design_system_version();

		if ( $expected_version !== null && $expected_version !== $current_version ) {
			return new \WP_Error(
				'remote_component_design_version_mismatch',
				esc_html__( 'The design system changed after this import was inspected. Review it again before importing.', 'bricks' ),
				[
					'status'              => 409,
					'designSystemVersion' => $current_version,
				]
			);
		}

		$lock = Component_Repository::acquire_lock();

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$snapshot = null;

		try {
			if ( $expected_version !== null && $expected_version !== Component_Repository::get_design_system_version() ) {
				return new \WP_Error(
					'remote_component_design_version_mismatch',
					esc_html__( 'The design system changed while the import was starting.', 'bricks' ),
					[ 'status' => 409 ]
				);
			}

			$root_id              = (string) $package['manifest']['rootId'];
			$skipped_dependencies = self::normalize_skipped_dependencies( $options['skippedDependencies'] ?? [] );
			$package_hash         = self::import_checksum( $package, $options );
			$reviewed_class_map   = $options['reviewedClassMap'] ?? null;

			$provenance    = Component_Repository::get_provenance();
			$root_key      = self::provenance_key( $source_id, $root_id );
			$root_record   = is_array( $provenance[ $root_key ] ?? null ) ? $provenance[ $root_key ] : [];
			$conflict_mode = ( $options['conflictMode'] ?? 'keep' ) === 'replace' ? 'replace' : 'keep';

			$all_components_identical  = self::all_components_match_provenance( $package['components'], $source_id, $provenance );
			$package_is_identical      = ( $root_record['packageChecksum'] ?? '' ) === $package_hash;
			$repair_missing_components = self::has_missing_provenance_components( $package['components'], $source_id, $provenance );

			if ( ! empty( $root_record['localComponentId'] ) && $all_components_identical && $package_is_identical ) {
				return array_merge(
					self::result_payload( $root_record['localComponentId'], 'identical', false ),
					self::provenance_maps( $package['components'], $source_id, $provenance, $root_id )
				);
			}

			if ( self::has_changed_provenance( $package['components'], $source_id, $provenance, $root_id, $package_hash ) && $conflict_mode !== 'replace' ) {
				return array_merge(
					self::result_payload( $root_record['localComponentId'] ?? '', 'kept', false ),
					self::provenance_maps( $package['components'], $source_id, $provenance, $root_id )
				);
			}

			$snapshot                   = self::snapshot_options();
			$source_component_checksums = [];

			foreach ( $package['components'] as $source_component ) {
				$source_component_checksums[ (string) ( $source_component['id'] ?? '' ) ] = self::checksum( self::portable_component( $source_component ) );
			}

			$components   = Components::upgrade_components( $package['components'], false );
			$dependencies = is_array( $package['dependencies'] ?? null ) ? $package['dependencies'] : [];
			$components   = self::remove_skipped_global_class_ids( $components, $skipped_dependencies['globalClasses'] ?? [] );
			$dependencies = self::filter_skipped_dependencies( $dependencies, $skipped_dependencies );
			// Template review owns dependency changes in Builder state until save/import.
			$variable_data    = is_array( $reviewed_class_map ) ? [] : self::import_global_variables(
				$dependencies['globalVariables'] ?? [],
				$dependencies['globalVariablesCategories'] ?? [],
				$source_name
			);
			$incoming_classes = self::remap_variable_names( $dependencies['globalClasses'] ?? [], $variable_data );
			$color_id_map     = is_array( $reviewed_class_map ) ? [] : self::import_color_palettes( $dependencies['colorPalette'] ?? [], $source_name );

			if ( count( $color_id_map ) ) {
				$incoming_classes = self::remap_color_ids( $incoming_classes, $color_id_map );
			}

			$class_id_map = is_array( $reviewed_class_map ) ? $reviewed_class_map : self::import_global_classes( $incoming_classes, $source_name );
			$components   = self::remap_variable_names( $components, $variable_data );

			if ( count( $color_id_map ) ) {
				$components = self::remap_color_ids( $components, $color_id_map );
			}

			if ( count( $class_id_map ) ) {
				$components = self::remap_global_class_ids_in_components( $components, $class_id_map );
			}

			$rekeyed = self::rekey_components(
				$components,
				$source_id,
				$source_name,
				$provenance,
				$conflict_mode,
				( ! empty( $root_record['localComponentId'] ) && ! $package_is_identical ) || $repair_missing_components,
				$repair_missing_components,
				$root_id,
				sanitize_text_field( $options['label'] ?? '' ),
				$source_component_checksums
			);

			if ( is_wp_error( $rekeyed ) ) {
				self::restore_options( $snapshot );
				return $rekeyed;
			}

			$incoming = $rekeyed['components'];
			$incoming = self::process_component_code( $incoming );
			$local    = Component_Repository::get_all();

			foreach ( $incoming as $component ) {
				$index = self::find_component_index( $local, $component['id'] ?? '' );

				if ( $index === null ) {
					$local[] = $component;
				} else {
					$local[ $index ] = $component;
				}
			}

			$validation = self::validate_component_store( $local, $incoming );

			if ( is_wp_error( $validation ) ) {
				self::restore_options( $snapshot );
				return $validation;
			}

			if ( ! empty( $options['importImages'] ) ) {
				$incoming = self::import_component_media( $incoming, $options['sourceUrl'] ?? '' );

				foreach ( $incoming as $component ) {
					$index = self::find_component_index( $local, $component['id'] ?? '' );

					if ( $index !== null ) {
						$local[ $index ] = $component;
					}
				}
			}

			foreach ( $rekeyed['provenance'] as $remote_id => $record ) {
				$provenance[ self::provenance_key( $source_id, $remote_id ) ] = $record;
			}

			$root_key = self::provenance_key( $source_id, $root_id );

			if ( is_array( $provenance[ $root_key ] ?? null ) ) {
				$provenance[ $root_key ]['packageChecksum'] = $package_hash;
				$provenance[ $root_key ]['dependencyMaps']  = [
					'classIdMap'      => $class_id_map,
					'colorIdMap'      => $color_id_map,
					'variableNameMap' => $variable_data,
				];
			}

			Component_Repository::update_all( $local );
			Component_Repository::update_provenance( $provenance );

			if ( is_multisite() && BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS ) {
				Component_Repository::bump_design_system_version();
			} elseif ( ! Database::get_setting( 'abilitiesApi', false ) ) {
				Abilities\Design::bump_design_system_version();
			}

			$local_root_id = $rekeyed['componentIdMap'][ $root_id ] ?? '';

			return array_merge(
				self::result_payload( $local_root_id, 'imported' ),
				[
					'classIdMap'      => $class_id_map,
					'colorIdMap'      => $color_id_map,
					'variableNameMap' => $variable_data,
					'componentIdMap'  => $rekeyed['componentIdMap'],
					'elementIdMap'    => $rekeyed['elementIdMap'],
				]
			);
		} catch ( \Throwable $error ) {
			if ( is_array( $snapshot ) ) {
				self::restore_options( $snapshot );
			}

			return new \WP_Error(
				'remote_component_import_failed',
				esc_html__( 'The remote component import failed and all changes were rolled back.', 'bricks' ),
				[ 'status' => 500 ]
			);
		} finally {
			Component_Repository::release_lock( $lock );
		}
	}

	/**
	 * Import component dependencies from a negotiated remote template package.
	 *
	 * @param array  $package     Remote template package.
	 * @param string $source_id   Configured source ID.
	 * @param string $source_name Configured source label.
	 * @param array  $options     Import options.
	 * @return array|\WP_Error
	 */
	public static function apply_template_package( $package, $source_id, $source_name, $options = [] ) {
		$valid = self::validate_template_package( $package );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$manifest = $package['manifest'];

		$template           = $package['template'];
		$components         = $package['components'];
		$reviewed_class_map = $options['reviewedClassMap'] ?? null;

		if ( ! Capabilities::current_user_can_execute_code() ) {
			foreach ( [ 'content', 'header', 'footer' ] as $area ) {
				if ( is_array( $template[ $area ] ?? null ) ) {
					$template[ $area ] = Abilities\Elements::redact_code_sensitive_elements( $template[ $area ] );
				}
			}
		}

		if ( is_array( $reviewed_class_map ) ) {
			$skipped = array_values( array_diff( array_column( $package['dependencies']['globalClasses'] ?? [], 'id' ), array_keys( $reviewed_class_map ) ) );
			$options['skippedDependencies']['globalClasses'] = $skipped;
			$property_lookup                                 = self::build_component_class_property_lookup( $components );

			foreach ( [ 'content', 'header', 'footer' ] as $area ) {
				if ( is_array( $template[ $area ] ?? null ) ) {
					$template[ $area ] = self::remove_global_class_ids_from_elements( $template[ $area ], $skipped, $property_lookup );
				}
			}
		}

		if ( empty( $components ) ) {
			return [
				'template' => self::remap_global_class_ids_in_template( $template, [], $reviewed_class_map ?? [] ),
				'refresh'  => self::result_payload( '', 'unchanged', false ),
			];
		}

		$elements = [];

		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( is_array( $template[ $area ] ?? null ) ) {
				$elements = array_merge( $elements, $template[ $area ] );
			}
		}

		$root_ids = self::collect_component_reference_ids( $elements );

		if ( empty( $root_ids ) ) {
			return new \WP_Error( 'remote_template_missing_component', esc_html__( 'The remote template component dependency is missing.', 'bricks' ), [ 'status' => 409 ] );
		}

		foreach ( $root_ids as $root_id ) {
			if ( ! self::find_component( $components, $root_id ) ) {
				return new \WP_Error( 'remote_template_missing_component', esc_html__( 'The remote template component dependency is missing.', 'bricks' ), [ 'status' => 409 ] );
			}
		}

		$component_package                         = [
			'manifest'     => [
				'protocol'      => Remote_Library::PROTOCOL,
				'schemaVersion' => self::SCHEMA_VERSION,
				'resource'      => 'component',
				'rootId'        => $root_ids[0],
				'sourceVersion' => (string) ( $manifest['sourceVersion'] ?? '' ),
			],
			'components'   => $components,
			'dependencies' => is_array( $package['dependencies'] ?? null ) ? $package['dependencies'] : [],
			'warnings'     => is_array( $package['warnings'] ?? null ) ? $package['warnings'] : [],
		];
		$component_package['manifest']['checksum'] = self::package_checksum( $component_package );
		$inspection                                = self::inspect( $component_package, $source_id, $source_name, $options );

		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}

		if ( $inspection['status'] === 'changed' && ( $options['conflictMode'] ?? 'keep' ) !== 'replace' ) {
			return new \WP_Error(
				'remote_template_component_changed',
				esc_html__( 'A component used by this remote template has changed since it was imported.', 'bricks' ),
				[
					'status'               => 409,
					'requiresConfirmation' => true,
				]
			);
		}

		$result = self::apply( $component_package, $source_id, $source_name, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$template = self::remap_global_class_ids_in_template( $template, $components, $result['classIdMap'] ?? [] );
		$template = self::remap_color_ids( $template, $result['colorIdMap'] ?? [] );
		$template = self::remap_variable_names( $template, $result['variableNameMap'] ?? [] );
		$template = self::remap_template_component_references(
			$template,
			$result['componentIdMap'] ?? [],
			$result['elementIdMap'] ?? []
		);

		return [
			'template' => $template,
			'refresh'  => $result,
		];
	}

	/**
	 * Validate a remote package and its checksum.
	 *
	 * @param array $package Package data.
	 * @return true|\WP_Error
	 */
	public static function validate_package( $package ) {
		if ( ! is_array( $package ) || ! is_array( $package['manifest'] ?? null ) ) {
			return new \WP_Error( 'remote_component_invalid_package', esc_html__( 'Invalid remote component package.', 'bricks' ), [ 'status' => 400 ] );
		}

		$manifest = $package['manifest'];

		if (
			( $manifest['protocol'] ?? '' ) !== Remote_Library::PROTOCOL ||
			(int) ( $manifest['schemaVersion'] ?? 0 ) !== self::SCHEMA_VERSION ||
			( $manifest['resource'] ?? '' ) !== 'component'
		) {
			return new \WP_Error( 'remote_component_unsupported_package', esc_html__( 'Unsupported remote component package version.', 'bricks' ), [ 'status' => 400 ] );
		}

		if ( empty( $manifest['rootId'] ) || empty( $manifest['checksum'] ) || ! is_array( $package['components'] ?? null ) ) {
			return new \WP_Error( 'remote_component_invalid_package', esc_html__( 'The remote component package is incomplete.', 'bricks' ), [ 'status' => 400 ] );
		}

		if ( ! hash_equals( (string) $manifest['checksum'], self::package_checksum( $package ) ) ) {
			return new \WP_Error( 'remote_component_checksum_mismatch', esc_html__( 'The remote component package checksum is invalid.', 'bricks' ), [ 'status' => 400 ] );
		}

		$ids         = [];
		$element_ids = [];

		foreach ( $package['components'] as $component ) {
			$id       = (string) ( $component['id'] ?? '' );
			$elements = $component['elements'] ?? null;

			if ( ! $id || ! is_array( $elements ) || empty( $elements ) || ( $elements[0]['id'] ?? '' ) !== $id ) {
				return new \WP_Error( 'remote_component_invalid_definition', esc_html__( 'The package contains an invalid component definition.', 'bricks' ), [ 'status' => 400 ] );
			}

			if ( isset( $ids[ $id ] ) ) {
				return new \WP_Error( 'remote_component_duplicate_id', esc_html__( 'The package contains duplicate component IDs.', 'bricks' ), [ 'status' => 400 ] );
			}

			$ids[ $id ] = true;

			foreach ( $elements as $element ) {
				$element_id = (string) ( $element['id'] ?? '' );

				if ( ! $element_id ) {
					return new \WP_Error( 'remote_component_invalid_element_id', esc_html__( 'The package contains an element without an ID.', 'bricks' ), [ 'status' => 400 ] );
				}

				if ( isset( $element_ids[ $element_id ] ) ) {
					return new \WP_Error( 'remote_component_duplicate_element_id', esc_html__( 'The package contains duplicate element IDs.', 'bricks' ), [ 'status' => 400 ] );
				}

				$element_ids[ $element_id ] = true;
			}
		}

		if ( ! isset( $ids[ (string) $manifest['rootId'] ] ) ) {
			return new \WP_Error( 'remote_component_missing_root', esc_html__( 'The package root component is missing.', 'bricks' ), [ 'status' => 400 ] );
		}

		return self::validate_component_graph( $package['components'] );
	}

	/**
	 * Build a transitive component graph.
	 *
	 * @param string $root_id  Root component ID.
	 * @param array  $excluded Excluded component IDs.
	 * @return array|\WP_Error
	 */
	private static function collect_component_graph( $root_id, $excluded ) {
		$lookup   = [];
		$excluded = array_fill_keys( array_map( 'strval', is_array( $excluded ) ? $excluded : [] ), true );

		foreach ( Component_Repository::get_all() as $component ) {
			if ( ! empty( $component['id'] ) ) {
				$lookup[ (string) $component['id'] ] = $component;
			}
		}

		$result = [];
		$state  = [];
		$visit  = function( $component_id ) use ( &$visit, &$result, &$state, $lookup, $excluded ) {
			if ( isset( $excluded[ $component_id ] ) ) {
				return new \WP_Error( 'remote_component_excluded_dependency', esc_html__( 'This component depends on another component that is excluded from remote access.', 'bricks' ), [ 'status' => 403 ] );
			}

			if ( ( $state[ $component_id ] ?? '' ) === 'visiting' ) {
				return new \WP_Error( 'remote_component_cycle', esc_html__( 'A circular component dependency was detected.', 'bricks' ), [ 'status' => 409 ] );
			}

			if ( ( $state[ $component_id ] ?? '' ) === 'done' ) {
				return true;
			}

			if ( empty( $lookup[ $component_id ] ) ) {
				return new \WP_Error( 'remote_component_missing_dependency', esc_html__( 'A component dependency is missing.', 'bricks' ), [ 'status' => 409 ] );
			}

			$state[ $component_id ]  = 'visiting';
			$result[ $component_id ] = $lookup[ $component_id ];

			foreach ( self::collect_component_reference_ids( $lookup[ $component_id ]['elements'] ?? [] ) as $nested_id ) {
				$visited = $visit( $nested_id );

				if ( is_wp_error( $visited ) ) {
					return $visited;
				}
			}

			$state[ $component_id ] = 'done';

			return true;
		};

		$visited = $visit( (string) $root_id );

		return is_wp_error( $visited ) ? $visited : array_values( $result );
	}

	/**
	 * Collect component references from element rows.
	 *
	 * @param array $elements Element rows.
	 * @return array
	 */
	private static function collect_component_reference_ids( $elements ) {
		$ids = [];

		foreach ( (array) $elements as $element ) {
			if ( ! empty( $element['cid'] ) ) {
				$ids[] = (string) $element['cid'];
			}

			if ( is_array( $element['elements'] ?? null ) ) {
				$ids = array_merge( $ids, self::collect_component_reference_ids( $element['elements'] ) );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Remove source-local metadata and code signatures from a component.
	 *
	 * @param array $component Component definition.
	 * @return array
	 */
	private static function portable_component( $component ) {
		unset( $component['_created'], $component['_user_id'], $component['thumbnail'], $component['thumbnailUpdated'] );

		return self::remove_signatures( $component );
	}

	/**
	 * Remove code signatures recursively while retaining source code.
	 *
	 * @param mixed $data Arbitrary component data.
	 * @return mixed
	 */
	private static function remove_signatures( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( $key === 'signature' ) {
				unset( $data[ $key ] );
				continue;
			}

			$data[ $key ] = self::remove_signatures( $value );
		}

		return $data;
	}

	/**
	 * Build design-resource dependencies for component definitions.
	 *
	 * @param array $components Component definitions.
	 * @return array
	 */
	private static function build_dependencies( $components ) {
		$dependencies = [];
		$class_ids    = self::collect_class_ids( $components );
		$classes      = [];

		foreach ( self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES ) as $class ) {
			if ( in_array( (string) ( $class['id'] ?? '' ), $class_ids, true ) ) {
				$classes[] = $class;
			}
		}

		$resolved_class_ids = array_values( array_filter( array_map( 'strval', array_column( $classes, 'id' ) ) ) );
		$missing_class_ids  = array_values( array_diff( $class_ids, $resolved_class_ids ) );

		if ( count( $classes ) ) {
			$dependencies['globalClasses'] = Helpers::add_category_metadata_to_classes( $classes );
		}

		if ( count( $missing_class_ids ) ) {
			$dependencies['_missing']['globalClasses'] = $missing_class_ids;
		}

		$palettes      = [];
		$variables     = [];
		$all_variables = self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES );
		$all_palettes  = self::get_dependency_option( BRICKS_DB_COLOR_PALETTE );

		// CSS-only references have no color ID. Follow palette/variable references to a fixed point.
		do {
			$previous_count = count( $variables ) + count( $palettes );
			$variable_names = self::collect_variable_names( [ $components, $classes, $palettes ] );
			$variables      = self::filter_variables( $all_variables, $variable_names );
			$variable_names = self::collect_variable_names( [ $components, $classes, $variables, $palettes ] );
			$color_ids      = Templates::get_template_used_color_ids( [ $components, $classes, $variables, $palettes ] );

			foreach ( $all_palettes as $index => $palette ) {
				$colors            = (array) ( $palette['colors'] ?? [] );
				$palette_color_ids = array_filter( array_column( $colors, 'id' ) );
				$palette_names     = self::collect_variable_names( array_column( $colors, 'raw' ) );
				foreach ( $palette_color_ids as $color_id ) {
					$palette_names[] = 'bricks-color-' . $color_id;
				}
				if ( array_intersect( $color_ids, $palette_color_ids ) || array_intersect( $variable_names, $palette_names ) ) {
					$palettes[ $index ] = $palette;
				}
			}
			$current_count = count( $variables ) + count( $palettes );
		} while ( $previous_count !== $current_count );

		if ( count( $variables ) ) {
			$dependencies['globalVariables'] = $variables;

			$category_ids = array_values( array_unique( array_filter( array_column( $variables, 'category' ) ) ) );
			$categories   = array_values(
				array_filter(
					self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES ),
					function( $category ) use ( $category_ids ) {
						return in_array( $category['id'] ?? '', $category_ids, true );
					}
				)
			);

			if ( count( $categories ) ) {
				$dependencies['globalVariablesCategories'] = $categories;
			}
		}

		if ( count( $palettes ) ) {
			$dependencies['colorPalette'] = array_values( $palettes );
		}

		return $dependencies;
	}

	/**
	 * Collect global class IDs used by components and class properties.
	 *
	 * @param mixed $data Component data.
	 * @return array
	 */
	private static function collect_class_ids( $data ) {
		$ids = [];

		if ( ! is_array( $data ) ) {
			return [];
		}

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, [ '_cssGlobalClasses', '_cssGlobalClassesProps' ], true ) ) {
				$ids = array_merge( $ids, self::flatten_scalar_values( $value ) );
			}

			if ( $key === 'properties' && is_array( $value ) ) {
				foreach ( $value as $property ) {
					if ( ( $property['type'] ?? '' ) !== 'class' ) {
						continue;
					}

					$ids = array_merge( $ids, self::flatten_scalar_values( $property['default'] ?? [] ) );

					foreach ( (array) ( $property['options'] ?? [] ) as $option ) {
						$ids = array_merge( $ids, self::flatten_scalar_values( $option['value'] ?? [] ) );
					}
				}
			}

			$ids = array_merge( $ids, self::collect_class_ids( $value ) );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	/**
	 * Flatten scalar array values.
	 *
	 * @param mixed $data Data to flatten.
	 * @return array
	 */
	private static function flatten_scalar_values( $data ) {
		if ( ! is_array( $data ) ) {
			return is_scalar( $data ) ? [ $data ] : [];
		}

		$values = [];

		foreach ( $data as $value ) {
			$values = array_merge( $values, self::flatten_scalar_values( $value ) );
		}

		return $values;
	}

	/**
	 * Collect CSS variable names from arbitrary data.
	 *
	 * @param mixed $data Arbitrary data.
	 * @return array
	 */
	private static function collect_variable_names( $data ) {
		$json = wp_json_encode( $data );

		if ( ! is_string( $json ) || ! preg_match_all( '/var\(\s*--([A-Za-z0-9_-]+)(?=\s*[,\)])/', $json, $matches ) ) {
			return [];
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Filter variables and their transitive variable dependencies.
	 *
	 * @param array $variables Variable definitions.
	 * @param array $names     Required names.
	 * @return array
	 */
	private static function filter_variables( $variables, $names ) {
		$lookup = [];

		foreach ( (array) $variables as $variable ) {
			if ( ! empty( $variable['name'] ) ) {
				$lookup[ (string) $variable['name'] ] = $variable;
			}
		}

		$selected = [];
		$pending  = array_values( array_unique( $names ) );

		while ( ! empty( $pending ) ) {
			$name = array_shift( $pending );

			if ( isset( $selected[ $name ] ) || empty( $lookup[ $name ] ) ) {
				continue;
			}

			$selected[ $name ] = $lookup[ $name ];
			$pending           = array_merge( $pending, self::collect_variable_names( $lookup[ $name ] ) );
		}

		return array_values( $selected );
	}

	/**
	 * Collect known non-portable component features.
	 *
	 * @param array $components Component definitions.
	 * @return array
	 */
	private static function collect_portability_warnings( $components ) {
		$json     = wp_json_encode( $components );
		$warnings = [];

		if ( strpos( $json, '"query"' ) !== false ) {
			$warnings[] = 'query';
		}

		if ( preg_match( '/"(?:globalQuery|globalQueryId|queryId)"\s*:/i', $json ) ) {
			$warnings[] = 'globalQuery';
		}

		if ( strpos( $json, '"icon"' ) !== false ) {
			$warnings[] = 'icon';
		}

		if ( preg_match( '/"(?:code|queryEditor|javascriptCode|cssCode)"\s*:/', $json ) ) {
			$warnings[] = 'executableCode';
		}

		if ( strpos( $json, '{' ) !== false && preg_match( '/\{(?:acf|cf|mb|woo|post|term|user)_/i', $json ) ) {
			$warnings[] = 'dynamicData';
		}

		foreach ( (array) get_option( BRICKS_DB_CUSTOM_FONTS, [] ) as $font ) {
			$family = (string) ( $font['name'] ?? $font['family'] ?? '' );

			if ( $family && strpos( $json, $family ) !== false ) {
				$warnings[] = 'customFont:' . $family;
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * Rekey component and element IDs and build provenance records.
	 *
	 * @param array  $components    Incoming components.
	 * @param string $source_id     Source ID.
	 * @param string $source_name   Source label.
	 * @param array  $provenance    Existing provenance.
	 * @param string $conflict_mode Conflict mode.
	 * @param bool   $rewrite_exact Whether unchanged components must adopt new dependency maps.
	 * @param bool   $repair_missing Rebuild existing mapped components when part of their graph is missing.
	 * @param string $root_id        Root component ID.
	 * @param string $root_label     Optional local root label.
	 * @param array  $source_checksums Remote component checksums before dependency remapping.
	 * @return array|\WP_Error
	 */
	private static function rekey_components( $components, $source_id, $source_name, $provenance, $conflict_mode, $rewrite_exact, $repair_missing, $root_id, $root_label, $source_checksums ) {
		$id_map           = [];
		$component_id_map = [];
		$used_ids         = [];
		$seen_source_ids  = [];
		$reused_ids       = [];
		$local_components = Component_Repository::get_all();

		foreach ( $local_components as $local_component ) {
			foreach ( (array) ( $local_component['elements'] ?? [] ) as $element ) {
				if ( ! empty( $element['id'] ) ) {
					$used_ids[ (string) $element['id'] ] = true;
				}
			}
		}

		foreach ( $components as $component ) {
			$remote_component_id = (string) ( $component['id'] ?? '' );
			$record              = $provenance[ self::provenance_key( $source_id, $remote_component_id ) ] ?? [];
			$component_checksum  = (string) ( $source_checksums[ $remote_component_id ] ?? '' );
			$has_local           = ! empty( $record['localComponentId'] ) && self::find_component( $local_components, $record['localComponentId'] );
			$is_exact_match      = $has_local && ( $record['componentChecksum'] ?? '' ) === $component_checksum;
			$reuse_component     = ! $rewrite_exact && $is_exact_match && self::record_maps_all_elements( $record, $component );
			$existing_map        = ( $reuse_component || ( ( $conflict_mode === 'replace' || $repair_missing ) && $has_local ) ) && is_array( $record['elementMap'] ?? null ) ? $record['elementMap'] : [];

			if ( $reuse_component ) {
				$reused_ids[ $remote_component_id ] = true;
			}

			foreach ( (array) ( $component['elements'] ?? [] ) as $element ) {
				$old_id = (string) ( $element['id'] ?? '' );

				if ( ! $old_id || isset( $seen_source_ids[ $old_id ] ) ) {
					return new \WP_Error( 'remote_component_duplicate_element_id', esc_html__( 'The package contains duplicate element IDs.', 'bricks' ), [ 'status' => 400 ] );
				}

				$seen_source_ids[ $old_id ] = true;
				$new_id                     = (string) ( $existing_map[ $old_id ] ?? '' );

				if ( strlen( $new_id ) !== 6 ) {
					$new_id = self::generate_unique_id( $used_ids );
				}

				$used_ids[ $new_id ] = true;
				$id_map[ $old_id ]   = $new_id;
			}

			$component_id_map[ $remote_component_id ] = $reuse_component
				? (string) $record['localComponentId']
				: $id_map[ $remote_component_id ];
		}

		$remapped       = self::remap_component_and_element_ids( $components, $component_id_map, $id_map );
		$local_labels   = [];
		$replaced_ids   = [];
		$new_provenance = [];
		$incoming       = [];

		foreach ( $component_id_map as $remote_id => $local_id ) {
			if ( ! isset( $reused_ids[ $remote_id ] ) ) {
				$replaced_ids[] = $local_id;
			}
		}

		foreach ( $local_components as $local_component ) {
			if ( ! in_array( $local_component['id'] ?? '', $replaced_ids, true ) ) {
				$local_labels[] = strtolower( self::get_component_label( $local_component ) );
			}
		}

		foreach ( $remapped as $index => $component ) {
			$remote_component = $components[ $index ];
			$remote_id        = (string) $remote_component['id'];
			$local_id         = (string) $component_id_map[ $remote_id ];

			if ( isset( $reused_ids[ $remote_id ] ) ) {
				continue;
			}

			$label = self::get_component_label( $component );

			if ( $remote_id === $root_id && $root_label ) {
				$label = $root_label;
			}

			if ( in_array( strtolower( $label ), $local_labels, true ) ) {
				$label = self::unique_label( $label, $local_labels );
			}

			$component['elements'][0]['label'] = $label;

			$local_labels[]        = strtolower( $label );
			$component['id']       = $local_id;
			$component['_created'] = time();
			$component['_user_id'] = get_current_user_id();
			$component['_version'] = defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '';
			$incoming[]            = $component;
			$component_element_map = [];

			foreach ( (array) $remote_component['elements'] as $element ) {
				$old_element_id = (string) ( $element['id'] ?? '' );

				if ( isset( $id_map[ $old_element_id ] ) ) {
					$component_element_map[ $old_element_id ] = $id_map[ $old_element_id ];
				}
			}

			$new_provenance[ $remote_id ] = [
				'localComponentId'  => $local_id,
				'componentChecksum' => (string) ( $source_checksums[ $remote_id ] ?? '' ),
				'elementMap'        => $component_element_map,
			];
		}

		return [
			'components'     => $incoming,
			'componentIdMap' => $component_id_map,
			'elementIdMap'   => $id_map,
			'provenance'     => $new_provenance,
		];
	}

	/**
	 * Check whether a provenance record can reuse every incoming element ID.
	 *
	 * @param array $record    Provenance record.
	 * @param array $component Remote component.
	 * @return bool
	 */
	private static function record_maps_all_elements( $record, $component ) {
		$element_map = is_array( $record['elementMap'] ?? null ) ? $record['elementMap'] : [];

		foreach ( (array) ( $component['elements'] ?? [] ) as $element ) {
			if ( empty( $element_map[ (string) ( $element['id'] ?? '' ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether every mapped incoming component is unchanged.
	 *
	 * @param array  $components Incoming components.
	 * @param string $source_id  Source ID.
	 * @param array  $provenance Provenance records.
	 * @return bool
	 */
	private static function all_components_match_provenance( $components, $source_id, $provenance ) {
		$local_components = Component_Repository::get_all();

		foreach ( $components as $component ) {
			$remote_id = (string) ( $component['id'] ?? '' );
			$record    = $provenance[ self::provenance_key( $source_id, $remote_id ) ] ?? [];

			if (
				empty( $record['localComponentId'] ) ||
				! self::find_component( $local_components, $record['localComponentId'] ) ||
				( $record['componentChecksum'] ?? '' ) !== self::checksum( self::portable_component( $component ) )
			) {
				return false;
			}
		}

		return ! empty( $components );
	}

	/**
	 * Check whether provenance points to a component that no longer exists locally.
	 *
	 * @param array  $components Incoming components.
	 * @param string $source_id  Source ID.
	 * @param array  $provenance Provenance records.
	 * @return bool
	 */
	private static function has_missing_provenance_components( $components, $source_id, $provenance ) {
		$local_components = Component_Repository::get_all();

		foreach ( $components as $component ) {
			$remote_id = (string) ( $component['id'] ?? '' );
			$record    = $provenance[ self::provenance_key( $source_id, $remote_id ) ] ?? [];

			if ( ! empty( $record['localComponentId'] ) && ! self::find_component( $local_components, $record['localComponentId'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an incoming component differs from a provenance-mapped copy.
	 *
	 * @param array  $components Incoming components.
	 * @param string $source_id  Source ID.
	 * @param array  $provenance Provenance records.
	 * @param string $root_id    Remote root component ID.
	 * @param string $package_checksum Portable package checksum.
	 * @return bool
	 */
	private static function has_changed_provenance( $components, $source_id, $provenance, $root_id, $package_checksum ) {
		$local_components = Component_Repository::get_all();
		$root_record      = $provenance[ self::provenance_key( $source_id, $root_id ) ] ?? [];
		$local_root       = self::find_component( $local_components, $root_record['localComponentId'] ?? '' );

		if ( $local_root && ( $root_record['packageChecksum'] ?? '' ) !== $package_checksum ) {
			return true;
		}

		foreach ( $components as $component ) {
			$remote_id = (string) ( $component['id'] ?? '' );
			$record    = $provenance[ self::provenance_key( $source_id, $remote_id ) ] ?? [];

			$local_component = self::find_component( $local_components, $record['localComponentId'] ?? '' );

			if ( $local_component && ( $record['componentChecksum'] ?? '' ) !== self::checksum( self::portable_component( $component ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build component and element maps from stored provenance.
	 *
	 * @param array  $components Incoming components.
	 * @param string $source_id  Source ID.
	 * @param array  $provenance Provenance records.
	 * @param string $root_id    Remote root component ID.
	 * @return array
	 */
	private static function provenance_maps( $components, $source_id, $provenance, $root_id ) {
		$component_map = [];
		$element_map   = [];

		foreach ( $components as $component ) {
			$remote_id = (string) ( $component['id'] ?? '' );
			$record    = $provenance[ self::provenance_key( $source_id, $remote_id ) ] ?? [];

			if ( ! empty( $record['localComponentId'] ) ) {
				$component_map[ $remote_id ] = (string) $record['localComponentId'];
			}

			if ( is_array( $record['elementMap'] ?? null ) ) {
				$element_map = array_merge( $element_map, $record['elementMap'] );
			}
		}

		$root_record     = $provenance[ self::provenance_key( $source_id, $root_id ) ] ?? [];
		$dependency_maps = is_array( $root_record['dependencyMaps'] ?? null ) ? $root_record['dependencyMaps'] : [];

		return [
			'componentIdMap'  => $component_map,
			'elementIdMap'    => $element_map,
			'classIdMap'      => is_array( $dependency_maps['classIdMap'] ?? null ) ? $dependency_maps['classIdMap'] : [],
			'colorIdMap'      => is_array( $dependency_maps['colorIdMap'] ?? null ) ? $dependency_maps['colorIdMap'] : [],
			'variableNameMap' => is_array( $dependency_maps['variableNameMap'] ?? null ) ? $dependency_maps['variableNameMap'] : [],
		];
	}

	/**
	 * Remap component and element identities without touching other ID namespaces.
	 *
	 * @param array $components       Component definitions.
	 * @param array $component_id_map Remote-to-local component ID map.
	 * @param array $element_id_map   Remote-to-local element ID map.
	 * @return array
	 */
	private static function remap_component_and_element_ids( $components, $component_id_map, $element_id_map ) {
		foreach ( $components as &$component ) {
			$component_id = (string) ( $component['id'] ?? '' );

			if ( isset( $component_id_map[ $component_id ] ) ) {
				$component['id'] = $component_id_map[ $component_id ];
			}

			foreach ( (array) ( $component['properties'] ?? [] ) as $property_index => $property ) {
				if ( ! is_array( $property['connections'] ?? null ) ) {
					continue;
				}

				$connections = [];

				foreach ( $property['connections'] as $element_id => $element_connections ) {
					$local_element_id                 = $element_id_map[ (string) $element_id ] ?? $element_id;
					$connections[ $local_element_id ] = $element_connections;
				}

				$component['properties'][ $property_index ]['connections'] = $connections;
			}

			$component['elements'] = self::remap_component_elements(
				$component['elements'] ?? [],
				$component_id_map,
				$element_id_map
			);
		}

		unset( $component );

		return self::remap_prefixed_id_tokens( $components, $component_id_map, $element_id_map );
	}

	/**
	 * Remap structural IDs in component element rows.
	 *
	 * @param array $elements         Element rows.
	 * @param array $component_id_map Remote-to-local component ID map.
	 * @param array $element_id_map   Remote-to-local element ID map.
	 * @return array
	 */
	private static function remap_component_elements( $elements, $component_id_map, $element_id_map ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			foreach ( [ 'id', 'parent' ] as $key ) {
				$value = (string) ( $element[ $key ] ?? '' );

				if ( isset( $element_id_map[ $value ] ) ) {
					$element[ $key ] = $element_id_map[ $value ];
				}
			}

			$component_id = (string) ( $element['cid'] ?? '' );

			if ( isset( $component_id_map[ $component_id ] ) ) {
				$element['cid'] = $component_id_map[ $component_id ];
			}

			if ( is_array( $element['children'] ?? null ) ) {
				$element['children'] = self::remap_id_list( $element['children'], $element_id_map );
			}

			if ( is_array( $element['slotChildren'] ?? null ) ) {
				$slot_children = [];

				foreach ( $element['slotChildren'] as $slot_id => $child_ids ) {
					$local_slot_id                   = $element_id_map[ (string) $slot_id ] ?? $slot_id;
					$slot_children[ $local_slot_id ] = is_array( $child_ids ) ? self::remap_id_list( $child_ids, $element_id_map ) : $child_ids;
				}

				$element['slotChildren'] = $slot_children;
			}

			if ( is_array( $element['elements'] ?? null ) ) {
				$element['elements'] = self::remap_component_elements( $element['elements'], $component_id_map, $element_id_map );
			}
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Remap component references in a template without changing template element IDs.
	 *
	 * @param array $template         Template data.
	 * @param array $component_id_map Remote-to-local component ID map.
	 * @param array $element_id_map   Remote-to-local component element ID map.
	 * @return array
	 */
	private static function remap_template_component_references( $template, $component_id_map, $element_id_map ) {
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( is_array( $template[ $area ] ?? null ) ) {
				$template[ $area ] = self::remap_template_elements( $template[ $area ], $component_id_map, $element_id_map );
			}
		}

		return self::remap_prefixed_id_tokens( $template, $component_id_map, [] );
	}

	/**
	 * Remap component and slot references in template element rows.
	 *
	 * @param array $elements         Template element rows.
	 * @param array $component_id_map Remote-to-local component ID map.
	 * @param array $element_id_map   Remote-to-local component element ID map.
	 * @return array
	 */
	private static function remap_template_elements( $elements, $component_id_map, $element_id_map ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$component_id = (string) ( $element['cid'] ?? '' );

			if ( isset( $component_id_map[ $component_id ] ) ) {
				$element['cid'] = $component_id_map[ $component_id ];
			}

			if ( is_array( $element['slotChildren'] ?? null ) ) {
				$slot_children = [];

				foreach ( $element['slotChildren'] as $slot_id => $child_ids ) {
					$local_slot_id                   = $element_id_map[ (string) $slot_id ] ?? $slot_id;
					$slot_children[ $local_slot_id ] = $child_ids;
				}

				$element['slotChildren'] = $slot_children;
			}

			if ( is_array( $element['elements'] ?? null ) ) {
				$element['elements'] = self::remap_template_elements( $element['elements'], $component_id_map, $element_id_map );
			}
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Remap IDs in a list while preserving non-ID values.
	 *
	 * @param array $values ID values.
	 * @param array $id_map Old-to-new ID map.
	 * @return array
	 */
	private static function remap_id_list( $values, $id_map ) {
		return array_map(
			function( $value ) use ( $id_map ) {
				return $id_map[ (string) $value ] ?? $value;
			},
			$values
		);
	}

	/**
	 * Remap explicitly prefixed component and element tokens in strings.
	 *
	 * @param mixed $data             Arbitrary data.
	 * @param array $component_id_map Remote-to-local component ID map.
	 * @param array $element_id_map   Remote-to-local element ID map.
	 * @return mixed
	 */
	private static function remap_prefixed_id_tokens( $data, $component_id_map, $element_id_map ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::remap_prefixed_id_tokens( $value, $component_id_map, $element_id_map );
			}

			return $data;
		}

		if ( ! is_string( $data ) ) {
			return $data;
		}

		$data = self::remap_prefixed_token( $data, 'cid_', $component_id_map );

		return self::remap_prefixed_token( $data, 'brxe-', $element_id_map );
	}

	/**
	 * Remap one prefixed ID token without cascading replacements.
	 *
	 * @param string $value  Source string.
	 * @param string $prefix Token prefix.
	 * @param array  $id_map Old-to-new ID map.
	 * @return string
	 */
	private static function remap_prefixed_token( $value, $prefix, $id_map ) {
		$id_map = array_filter(
			$id_map,
			function( $local_id, $remote_id ) {
				return (string) $remote_id !== '' && (string) $local_id !== '';
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $id_map ) ) {
			return $value;
		}

		$pattern = '/(' . preg_quote( $prefix, '/' ) . ')(' . implode( '|', array_map( 'preg_quote', array_keys( $id_map ) ) ) . ')(?![A-Za-z0-9_-])/';
		$result  = preg_replace_callback(
			$pattern,
			function( $matches ) use ( $id_map ) {
				return $matches[1] . $id_map[ $matches[2] ];
			},
			$value
		);

		return is_string( $result ) ? $result : $value;
	}

	/**
	 * Remap class IDs only in class-aware component fields.
	 *
	 * @param array $components Component definitions.
	 * @param array $class_id_map Remote-to-local class ID map.
	 * @return array
	 */
	private static function remap_global_class_ids_in_components( $components, $class_id_map ) {
		if ( empty( $class_id_map ) ) {
			return $components;
		}

		foreach ( $components as &$component ) {
			if ( is_array( $component['properties'] ?? null ) ) {
				$component['properties'] = self::remap_global_class_ids_in_component_properties( $component['properties'], $class_id_map );
			}
		}

		unset( $component );

		$property_lookup = self::build_component_class_property_lookup( $components );

		foreach ( $components as &$component ) {
			$component['elements'] = self::remap_global_class_ids_in_elements(
				$component['elements'] ?? [],
				$class_id_map,
				$property_lookup
			);
		}

		unset( $component );

		return $components;
	}

	/**
	 * Remap class IDs used by template elements and component properties.
	 *
	 * @param array $template     Template data.
	 * @param array $components   Remote component definitions.
	 * @param array $class_id_map Remote-to-local class ID map.
	 * @return array
	 */
	private static function remap_global_class_ids_in_template( $template, $components, $class_id_map ) {
		if ( empty( $class_id_map ) ) {
			return $template;
		}

		$property_lookup = self::build_component_class_property_lookup( $components );

		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( is_array( $template[ $area ] ?? null ) ) {
				$template[ $area ] = self::remap_global_class_ids_in_elements(
					$template[ $area ],
					$class_id_map,
					$property_lookup
				);
			}
		}

		return $template;
	}

	/**
	 * Remap class-type component property defaults and options.
	 *
	 * @param array $properties   Component property definitions.
	 * @param array $class_id_map Remote-to-local class ID map.
	 * @return array
	 */
	private static function remap_global_class_ids_in_component_properties( $properties, $class_id_map ) {
		foreach ( $properties as &$property ) {
			if ( ( $property['type'] ?? '' ) !== 'class' ) {
				continue;
			}

			foreach ( [ 'default', 'value' ] as $property_key ) {
				if ( isset( $property[ $property_key ] ) ) {
					$property[ $property_key ] = self::remap_exact_id_value( $property[ $property_key ], $class_id_map );
				}
			}

			foreach ( (array) ( $property['options'] ?? [] ) as $option_index => $option ) {
				if ( isset( $option['value'] ) ) {
					$property['options'][ $option_index ]['value'] = self::remap_exact_id_value( $option['value'], $class_id_map );
				}
			}
		}

		unset( $property );

		return $properties;
	}

	/**
	 * Remap class IDs in element class settings and class-type instance properties.
	 *
	 * @param array $elements        Element rows.
	 * @param array $class_id_map    Remote-to-local class ID map.
	 * @param array $property_lookup Component class property lookup.
	 * @return array
	 */
	private static function remap_global_class_ids_in_elements( $elements, $class_id_map, $property_lookup ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			foreach ( [ '_cssGlobalClasses', '_cssGlobalClassesProps' ] as $setting_key ) {
				if ( isset( $element['settings'][ $setting_key ] ) ) {
					$element['settings'][ $setting_key ] = self::remap_exact_id_value( $element['settings'][ $setting_key ], $class_id_map );
				}
			}

			$component_id = (string) ( $element['cid'] ?? '' );

			foreach ( $property_lookup[ $component_id ] ?? [] as $property_id ) {
				if ( isset( $element['properties'][ $property_id ] ) ) {
					$element['properties'][ $property_id ] = self::remap_exact_id_value( $element['properties'][ $property_id ], $class_id_map );
				}
			}

			if ( is_array( $element['elements'] ?? null ) ) {
				$element['elements'] = self::remap_global_class_ids_in_elements( $element['elements'], $class_id_map, $property_lookup );
			}
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Build a lookup of class-type property IDs by remote component ID.
	 *
	 * @param array $components Component definitions.
	 * @return array
	 */
	private static function build_component_class_property_lookup( $components ) {
		$lookup = [];

		foreach ( $components as $component ) {
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

	/**
	 * Remap exact scalar IDs recursively without changing array keys.
	 *
	 * @param mixed $value  Value to remap.
	 * @param array $id_map Old-to-new ID map.
	 * @return mixed
	 */
	private static function remap_exact_id_value( $value, $id_map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::remap_exact_id_value( $item, $id_map );
			}

			return $value;
		}

		$value_key = (string) $value;

		return isset( $id_map[ $value_key ] ) ? $id_map[ $value_key ] : $value;
	}

	/**
	 * Remap palette IDs only in Bricks color values and color CSS variables.
	 *
	 * @param mixed $data         Arbitrary design data.
	 * @param array $color_id_map Remote-to-local color ID map.
	 * @return mixed
	 */
	private static function remap_color_ids( $data, $color_id_map ) {
		if ( empty( $color_id_map ) ) {
			return $data;
		}

		if ( is_array( $data ) ) {
			if ( self::is_color_value( $data ) ) {
				$color_id = (string) $data['id'];

				if ( isset( $color_id_map[ $color_id ] ) ) {
					$data['id'] = $color_id_map[ $color_id ];
				}
			}

			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::remap_color_ids( $value, $color_id_map );
			}

			return $data;
		}

		if ( ! is_string( $data ) ) {
			return $data;
		}

		return self::remap_prefixed_token( $data, '--bricks-color-', $color_id_map );
	}

	/**
	 * Determine whether an array is a Bricks color value.
	 *
	 * @param array $value Candidate value.
	 * @return bool
	 */
	private static function is_color_value( $value ) {
		return ! empty( $value['id'] ) && (
			isset( $value['hex'] ) ||
			isset( $value['rgb'] ) ||
			isset( $value['hsl'] ) ||
			isset( $value['raw'] ) ||
			isset( $value['light'] ) ||
			isset( $value['dark'] )
		);
	}

	/**
	 * Validate component dependency cycles.
	 *
	 * @param array $components Component definitions.
	 * @return true|\WP_Error
	 */
	private static function validate_component_graph( $components ) {
		$lookup = [];

		foreach ( $components as $component ) {
			$lookup[ (string) ( $component['id'] ?? '' ) ] = self::collect_component_reference_ids( $component['elements'] ?? [] );
		}

		$state = [];
		$visit = function( $id ) use ( &$visit, &$state, $lookup ) {
			if ( ( $state[ $id ] ?? '' ) === 'visiting' ) {
				return new \WP_Error( 'remote_component_cycle', esc_html__( 'A circular component dependency was detected.', 'bricks' ), [ 'status' => 409 ] );
			}

			if ( ( $state[ $id ] ?? '' ) === 'done' ) {
				return true;
			}

			$state[ $id ] = 'visiting';

			foreach ( $lookup[ $id ] ?? [] as $dependency_id ) {
				if ( ! isset( $lookup[ $dependency_id ] ) ) {
					return new \WP_Error( 'remote_component_missing_dependency', esc_html__( 'A component dependency is missing.', 'bricks' ), [ 'status' => 409 ] );
				}

				$result = $visit( $dependency_id );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$state[ $id ] = 'done';

			return true;
		};

		foreach ( array_keys( $lookup ) as $component_id ) {
			$result = $visit( $component_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Validate every component against the prospective component store.
	 *
	 * @param array $components Prospective component definitions.
	 * @param array $incoming   Incoming component definitions.
	 * @return true|\WP_Error
	 */
	private static function validate_component_store( $components, $incoming ) {
		$previous                            = Database::$global_data['components'] ?? [];
		Database::$global_data['components'] = $components;

		if ( empty( Elements::$elements ) ) {
			Elements::load_elements();
		}

		foreach ( $incoming as $component ) {
			$result = Abilities\Element_Validator::validate( $component['elements'] ?? [] );

			if ( is_wp_error( $result ) ) {
				Database::$global_data['components'] = $previous;
				return $result;
			}
		}

		Database::$global_data['components'] = $previous;

		return true;
	}

	/**
	 * Sign allowed code or redact it for users without code permission.
	 *
	 * @param array $components Component definitions.
	 * @return array
	 */
	private static function process_component_code( $components ) {
		foreach ( $components as $index => $component ) {
			$elements = (array) ( $component['elements'] ?? [] );

			if ( Capabilities::current_user_can_execute_code() ) {
				$elements = Admin::process_elements_for_signature( $elements );
			} else {
				$elements = Abilities\Elements::redact_code_sensitive_elements( $elements );
			}

			$components[ $index ]['elements'] = $elements;
		}

		return $components;
	}

	/**
	 * Import media URLs that originate from the configured source host.
	 *
	 * @param array  $components Component definitions.
	 * @param string $source_url Configured source URL.
	 * @return array
	 */
	private static function import_component_media( $components, $source_url ) {
		$source_host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );

		if ( ! $source_host ) {
			return $components;
		}

		$import = function( &$data ) use ( &$import, $source_host ) {
			if ( ! is_array( $data ) ) {
				return;
			}

			if ( Templates::is_image( $data ) ) {
				$image_host = strtolower( (string) wp_parse_url( $data['url'] ?? '', PHP_URL_HOST ) );

				if ( $image_host === $source_host ) {
					$imported = Templates::import_image( $data, true );

					if ( is_array( $imported ) && empty( $imported['error'] ) ) {
						$data = $imported;
					}
				}

				return;
			}

			foreach ( $data as &$value ) {
				$import( $value );
			}

			unset( $value );
		};

		$import( $components );

		return $components;
	}

	/**
	 * Normalize dependency IDs the user chose not to import.
	 *
	 * @param mixed $skipped Raw dependency selection.
	 * @return array
	 */
	private static function normalize_skipped_dependencies( $skipped ) {
		if ( ! is_array( $skipped ) ) {
			return [];
		}

		$normalized = [];

		foreach ( [ 'globalClasses', 'globalVariables', 'colorPalette' ] as $key ) {
			$ids = array_values(
				array_unique(
					array_filter(
						array_map( 'strval', is_array( $skipped[ $key ] ?? null ) ? $skipped[ $key ] : [] )
					)
				)
			);

			if ( ! empty( $ids ) ) {
				sort( $ids );
				$normalized[ $key ] = $ids;
			}
		}

		return $normalized;
	}

	/**
	 * Remove dependencies the user skipped in the review screens.
	 *
	 * @param array $dependencies Package dependencies.
	 * @param array $skipped      Skipped dependency IDs by type.
	 * @return array
	 */
	private static function filter_skipped_dependencies( $dependencies, $skipped ) {
		foreach ( [ 'globalClasses', 'globalVariables', 'colorPalette' ] as $key ) {
			$skipped_ids = $skipped[ $key ] ?? [];

			if ( empty( $skipped_ids ) ) {
				continue;
			}

			$dependencies[ $key ] = array_values(
				array_filter(
					(array) ( $dependencies[ $key ] ?? [] ),
					function( $row ) use ( $skipped_ids ) {
						return ! in_array( (string) ( $row['id'] ?? '' ), $skipped_ids, true );
					}
				)
			);
		}

		if ( ! empty( $dependencies['globalVariablesCategories'] ) ) {
			$used_category_ids                         = array_values(
				array_filter( array_map( 'strval', array_column( (array) ( $dependencies['globalVariables'] ?? [] ), 'category' ) ) )
			);
			$dependencies['globalVariablesCategories'] = array_values(
				array_filter(
					(array) $dependencies['globalVariablesCategories'],
					function( $category ) use ( $used_category_ids ) {
						return in_array( (string) ( $category['id'] ?? '' ), $used_category_ids, true );
					}
				)
			);
		}

		return $dependencies;
	}

	/**
	 * Remove skipped class IDs from class-aware component fields.
	 *
	 * @param array $components Component definitions.
	 * @param array $class_ids  Skipped remote class IDs.
	 * @return array
	 */
	private static function remove_skipped_global_class_ids( $components, $class_ids ) {
		if ( empty( $class_ids ) ) {
			return $components;
		}

		foreach ( $components as &$component ) {
			foreach ( (array) ( $component['properties'] ?? [] ) as $property_index => $property ) {
				if ( ( $property['type'] ?? '' ) !== 'class' ) {
					continue;
				}

				foreach ( [ 'default', 'value' ] as $property_key ) {
					if ( isset( $property[ $property_key ] ) ) {
						$component['properties'][ $property_index ][ $property_key ] = self::remove_exact_id_values( $property[ $property_key ], $class_ids );
					}
				}

				foreach ( (array) ( $property['options'] ?? [] ) as $option_index => $option ) {
					if ( isset( $option['value'] ) ) {
						$component['properties'][ $property_index ]['options'][ $option_index ]['value'] = self::remove_exact_id_values( $option['value'], $class_ids );
					}
				}
			}
		}

		unset( $component );

		$property_lookup = self::build_component_class_property_lookup( $components );

		foreach ( $components as &$component ) {
			$component['elements'] = self::remove_global_class_ids_from_elements( $component['elements'] ?? [], $class_ids, $property_lookup );
		}

		unset( $component );

		return $components;
	}

	/**
	 * Remove skipped class IDs from element class settings and class properties.
	 *
	 * @param array $elements        Element rows.
	 * @param array $class_ids       Skipped remote class IDs.
	 * @param array $property_lookup Component class property lookup.
	 * @return array
	 */
	private static function remove_global_class_ids_from_elements( $elements, $class_ids, $property_lookup ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			foreach ( [ '_cssGlobalClasses', '_cssGlobalClassesProps' ] as $setting_key ) {
				if ( isset( $element['settings'][ $setting_key ] ) ) {
					$element['settings'][ $setting_key ] = self::remove_exact_id_values( $element['settings'][ $setting_key ], $class_ids );
				}
			}

			$component_id = (string) ( $element['cid'] ?? '' );

			foreach ( $property_lookup[ $component_id ] ?? [] as $property_id ) {
				if ( isset( $element['properties'][ $property_id ] ) ) {
					$element['properties'][ $property_id ] = self::remove_exact_id_values( $element['properties'][ $property_id ], $class_ids );
				}
			}

			if ( is_array( $element['elements'] ?? null ) ) {
				$element['elements'] = self::remove_global_class_ids_from_elements( $element['elements'], $class_ids, $property_lookup );
			}
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Remove exact scalar IDs recursively and reindex lists.
	 *
	 * @param mixed $value Value to filter.
	 * @param array $ids   IDs to remove.
	 * @return mixed
	 */
	private static function remove_exact_id_values( $value, $ids ) {
		if ( is_array( $value ) ) {
			$filtered = [];

			foreach ( $value as $key => $item ) {
				if ( ! is_array( $item ) && in_array( (string) $item, $ids, true ) ) {
					continue;
				}

				$filtered[ $key ] = self::remove_exact_id_values( $item, $ids );
			}

			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );

			return $is_list ? array_values( $filtered ) : $filtered;
		}

		return in_array( (string) $value, $ids, true ) ? '' : $value;
	}

	/**
	 * Import global classes and return their ID map.
	 *
	 * @param array  $incoming    Incoming classes.
	 * @param string $source_name Source label.
	 * @return array
	 */
	private static function import_global_classes( $incoming, $source_name ) {
		if ( empty( $incoming ) ) {
			return [];
		}

		$local      = self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES );
		$categories = self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES );
		$map        = [];

		foreach ( (array) $incoming as $class ) {
			$remote_id             = (string) ( $class['id'] ?? '' );
			$match                 = null;
			$source_qualified_name = self::source_label( (string) ( $class['name'] ?? '' ), $source_name );

			if ( ! $remote_id ) {
				continue;
			}

			foreach ( $local as $local_class ) {
				$local_name              = (string) ( $local_class['name'] ?? '' );
				$is_previous_remote_copy = $local_name === $source_qualified_name || strpos( $local_name, $source_qualified_name . ' (' ) === 0;
				$id_matches              = (string) ( $local_class['id'] ?? '' ) === $remote_id;
				$name_matches            = strcasecmp( $local_name, (string) ( $class['name'] ?? '' ) ) === 0;

				if (
					$id_matches ||
					$name_matches ||
					( $is_previous_remote_copy && self::dependency_rows_equal( $local_class, $class, [ 'id', 'name', 'category', '_categoryData' ] ) )
				) {
					$match = $local_class;
					break;
				}
			}

			if ( $match ) {
				$map[ $remote_id ] = (string) $match['id'];
				continue;
			}

			if ( is_array( $class['_categoryData'] ?? null ) && ! empty( $class['_categoryData']['name'] ) ) {
				$category = self::find_row_by_field( $categories, 'name', $class['_categoryData']['name'] );

				if ( ! $category ) {
					$category     = [
						'id'   => self::generate_unique_id_from_rows( $categories ),
						'name' => sanitize_text_field( $class['_categoryData']['name'] ),
					];
					$categories[] = $category;
				}

				$class['category'] = $category['id'];
			}

			unset( $class['_categoryData'] );

			$class['id']       = self::generate_unique_id_from_rows( $local );
			$map[ $remote_id ] = $class['id'];
			$local[]           = $class;
		}

		self::update_dependency_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, $categories );
		self::update_dependency_option( BRICKS_DB_GLOBAL_CLASSES, $local );

		return $map;
	}

	/**
	 * Import variables/categories without overwriting matching names.
	 *
	 * @param array  $variables  Incoming variables.
	 * @param array  $categories Incoming categories.
	 * @param string $source_name Source label.
	 * @return array
	 */
	private static function import_global_variables( $variables, $categories, $source_name ) {
		if ( empty( $variables ) ) {
			return [];
		}

		$local    = self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES );
		$name_map = [];
		$pending  = [];

		foreach ( (array) $variables as $variable ) {
			$name                 = (string) ( $variable['name'] ?? '' );
			$match                = self::find_row_by_field( $local, 'id', (string) ( $variable['id'] ?? '' ) );
			$source_suffix        = sanitize_title( $source_name );
			$source_variable_name = sanitize_title( $name . ( $source_suffix ? '-' . $source_suffix : '-remote' ) );

			if ( ! $match ) {
				foreach ( $local as $local_variable ) {
					$local_name = (string) ( $local_variable['name'] ?? '' );

					if ( strcasecmp( $local_name, $name ) === 0 ) {
						$match = $local_variable;
						break;
					}
				}
			}

			if ( ! $match ) {
				foreach ( $local as $local_variable ) {
					$normalized_local = $local_variable;
					$local_name       = (string) ( $local_variable['name'] ?? '' );
					$is_previous_copy = $local_name === $source_variable_name || strpos( $local_name, $source_variable_name . '-' ) === 0;

					if ( ! $is_previous_copy ) {
						continue;
					}

					$normalized_local['name'] = $name;
					$normalized_local         = self::remap_variable_names( $normalized_local, [ $local_name => $name ] );

					if ( $name && self::dependency_rows_equal( $normalized_local, $variable, [ 'id', 'category' ] ) ) {
						$match = $local_variable;
						break;
					}
				}
			}

			if ( $match ) {
				$name_map[ $name ] = (string) $match['name'];
				continue;
			}

			$name_map[ $name ] = $name;
			$pending[]         = $variable;
		}

		$local_categories = self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES );
		$category_map     = [];
		$used_categories  = array_values( array_filter( array_map( 'strval', array_column( $pending, 'category' ) ) ) );

		foreach ( (array) $categories as $category ) {
			$remote_id = (string) ( $category['id'] ?? '' );

			if ( ! in_array( $remote_id, $used_categories, true ) ) {
				continue;
			}

			$match = self::find_row_by_field( $local_categories, 'id', $remote_id );

			if ( ! $match ) {
				$match = self::find_row_by_field( $local_categories, 'name', (string) ( $category['name'] ?? '' ) );
			}

			if ( $match ) {
				$category_map[ $remote_id ] = $match['id'];
				continue;
			}

			$category['id']             = self::generate_unique_id_from_rows( $local_categories );
			$category_map[ $remote_id ] = $category['id'];
			$local_categories[]         = $category;
		}

		foreach ( $pending as $variable ) {
			$variable = self::remap_variable_names( $variable, $name_map );

			$variable['id'] = self::generate_unique_id_from_rows( $local );

			if ( isset( $category_map[ $variable['category'] ?? '' ] ) ) {
				$variable['category'] = $category_map[ $variable['category'] ];
			}

			$local[] = $variable;
		}

		self::update_dependency_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, $local_categories );
		self::update_dependency_option( BRICKS_DB_GLOBAL_VARIABLES, $local );

		return $name_map;
	}

	/**
	 * Import missing color palettes.
	 *
	 * @param array  $incoming    Incoming palettes.
	 * @param string $source_name Source label.
	 * @return array
	 */
	private static function import_color_palettes( $incoming, $source_name ) {
		if ( empty( $incoming ) ) {
			return [];
		}

		$local     = self::get_dependency_option( BRICKS_DB_COLOR_PALETTE );
		$used_ids  = [];
		$color_map = [];

		foreach ( $local as $palette ) {
			if ( ! empty( $palette['id'] ) ) {
				$used_ids[ $palette['id'] ] = true;
			}

			foreach ( (array) ( $palette['colors'] ?? [] ) as $color ) {
				if ( ! empty( $color['id'] ) ) {
					$used_ids[ $color['id'] ] = true;
				}
			}
		}

		foreach ( (array) $incoming as $palette ) {
			$match                 = self::find_row_by_field( $local, 'id', (string) ( $palette['id'] ?? '' ) );
			$source_qualified_name = self::source_label( (string) ( $palette['name'] ?? '' ), $source_name );

			if ( ! $match ) {
				foreach ( $local as $local_palette ) {
					if ( strcasecmp( (string) ( $local_palette['name'] ?? '' ), (string) ( $palette['name'] ?? '' ) ) === 0 ) {
						$match = $local_palette;
						break;
					}
				}
			}

			if ( ! $match ) {
				foreach ( $local as $local_palette ) {
					$local_name              = (string) ( $local_palette['name'] ?? '' );
					$is_previous_remote_copy = $local_name === $source_qualified_name || strpos( $local_name, $source_qualified_name . ' (' ) === 0;

					if ( $is_previous_remote_copy && self::palette_signature( $local_palette, true ) === self::palette_signature( $palette, true ) ) {
						$match = $local_palette;
						break;
					}
				}
			}

			if ( $match ) {
				foreach ( (array) ( $palette['colors'] ?? [] ) as $index => $color ) {
					$remote_color_id = (string) ( $color['id'] ?? '' );
					$local_color     = self::find_row_by_field( $match['colors'] ?? [], 'id', $remote_color_id );

					if ( ! $local_color && ! empty( $match['colors'][ $index ] ) ) {
						$local_color = $match['colors'][ $index ];
					}

					if ( $remote_color_id && ! empty( $local_color['id'] ) ) {
						$color_map[ $remote_color_id ] = $local_color['id'];
					}
				}

				continue;
			}

			$palette['id'] = self::generate_unique_id( $used_ids );

			foreach ( (array) ( $palette['colors'] ?? [] ) as $index => $color ) {
				$remote_color_id = (string) ( $color['id'] ?? '' );
				$local_color_id  = self::generate_unique_id( $used_ids );

				if ( $remote_color_id ) {
					$color_map[ $remote_color_id ] = $local_color_id;
				}

				$palette['colors'][ $index ]['id'] = $local_color_id;
			}

			$local[] = $palette;
		}

		self::update_dependency_option( BRICKS_DB_COLOR_PALETTE, $local );

		return $color_map;
	}

	/**
	 * Build a palette signature that excludes transferable identity fields.
	 *
	 * @param array $palette     Palette row.
	 * @param bool  $ignore_name Exclude the palette name.
	 * @return string
	 */
	private static function palette_signature( $palette, $ignore_name = false ) {
		unset( $palette['id'] );

		if ( $ignore_name ) {
			unset( $palette['name'] );
		}

		foreach ( (array) ( $palette['colors'] ?? [] ) as $index => $color ) {
			unset( $color['id'] );
			$palette['colors'][ $index ] = $color;
		}

		return self::checksum( $palette );
	}

	/**
	 * Compare dependency rows while ignoring local identity fields.
	 *
	 * @param array $left        First row.
	 * @param array $right       Second row.
	 * @param array $ignored_keys Keys excluded from comparison.
	 * @return bool
	 */
	private static function dependency_rows_equal( $left, $right, $ignored_keys ) {
		foreach ( $ignored_keys as $key ) {
			unset( $left[ $key ], $right[ $key ] );
		}

		return self::checksum( $left ) === self::checksum( $right );
	}

	/**
	 * Find the first row with an exact field value.
	 *
	 * @param array  $rows  Rows.
	 * @param string $field Field name.
	 * @param mixed  $value Expected value.
	 * @return array|null
	 */
	private static function find_row_by_field( $rows, $field, $value ) {
		foreach ( (array) $rows as $row ) {
			if ( ( $row[ $field ] ?? null ) === $value ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Remap CSS variable references in arbitrary dependency data.
	 *
	 * @param mixed $data     Arbitrary data.
	 * @param array $name_map Old-to-new variable names.
	 * @return mixed
	 */
	private static function remap_variable_names( $data, $name_map ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::remap_variable_names( $value, $name_map );
			}

			return $data;
		}

		if ( ! is_string( $data ) ) {
			return $data;
		}

		$replacements = array_filter(
			$name_map,
			function( $new_name, $old_name ) {
				return $old_name !== '' && $old_name !== $new_name;
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $replacements ) ) {
			return $data;
		}

		$pattern  = '/--(' . implode(
			'|',
			array_map(
				function( $name ) {
					return preg_quote( $name, '/' );
				},
				array_keys( $replacements )
			)
		) . ')(?![A-Za-z0-9_-])/';
		$remapped = preg_replace_callback(
			$pattern,
			function( $matches ) use ( $replacements ) {
				return '--' . $replacements[ $matches[1] ];
			},
			$data
		);

		return is_string( $remapped ) ? $remapped : $data;
	}

	/**
	 * Read a component dependency from the site configured to own that resource.
	 *
	 * @since 2.4
	 *
	 * @param string $option_name Dependency option name.
	 * @return array
	 */
	private static function get_dependency_option( $option_name ) {
		if ( $option_name === BRICKS_DB_GLOBAL_VARIABLES ) {
			$value = Helpers::get_global_variables_option( $option_name, [] );
		} elseif ( self::dependency_uses_main_site( $option_name ) ) {
			$value = get_blog_option( get_main_site_id(), $option_name, [] );
		} else {
			$value = get_option( $option_name, [] );
		}

		return is_array( $value ) ? array_values( $value ) : [];
	}

	/**
	 * Save a component dependency on the site configured to own that resource.
	 *
	 * @since 2.4
	 *
	 * @param string $option_name Dependency option name.
	 * @param array  $value       Dependency rows.
	 * @return bool
	 */
	private static function update_dependency_option( $option_name, $value ) {
		$value = is_array( $value ) ? array_values( $value ) : [];

		if ( $option_name === BRICKS_DB_GLOBAL_VARIABLES ) {
			return (bool) Helpers::save_global_variables_array_option( $option_name, $value );
		}

		if ( self::dependency_uses_main_site( $option_name ) ) {
			return update_blog_option( get_main_site_id(), $option_name, $value );
		}

		return update_option( $option_name, $value );
	}

	/**
	 * Check whether a dependency option uses main-site storage.
	 *
	 * Each dependency honors its own multisite setting so sharing components does not implicitly share the entire design system.
	 *
	 * @since 2.4
	 *
	 * @param string $option_name Dependency option name.
	 * @return bool
	 */
	private static function dependency_uses_main_site( $option_name ) {
		if ( ! is_multisite() ) {
			return false;
		}

		switch ( $option_name ) {
			case BRICKS_DB_GLOBAL_CLASSES:
				return BRICKS_MULTISITE_USE_MAIN_SITE_CLASSES;

			case BRICKS_DB_GLOBAL_CLASSES_CATEGORIES:
				return BRICKS_MULTISITE_USE_MAIN_SITE_CLASSES_CATEGORIES;

			case BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES:
				return BRICKS_MULTISITE_USE_MAIN_SITE_VARIABLES_CATEGORIES;

			case BRICKS_DB_COLOR_PALETTE:
				return BRICKS_MULTISITE_USE_MAIN_SITE_COLOR_PALETTE;
		}

		return false;
	}

	/**
	 * Snapshot all options touched by an import.
	 *
	 * @return array
	 */
	private static function snapshot_options() {
		return [
			'components'         => Component_Repository::get_all(),
			'provenance'         => Component_Repository::get_provenance(),
			'globalClasses'      => self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES ),
			'classCategories'    => self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES ),
			'globalVariables'    => self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES ),
			'variableCategories' => self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES ),
			'colorPalette'       => self::get_dependency_option( BRICKS_DB_COLOR_PALETTE ),
		];
	}

	/**
	 * Restore import options after a failed validation or write.
	 *
	 * @param array $snapshot Option snapshot.
	 * @return void
	 */
	private static function restore_options( $snapshot ) {
		Component_Repository::update_all( $snapshot['components'] ?? [] );
		Component_Repository::update_provenance( $snapshot['provenance'] ?? [] );
		self::update_dependency_option( BRICKS_DB_GLOBAL_CLASSES, $snapshot['globalClasses'] ?? [] );
		self::update_dependency_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, $snapshot['classCategories'] ?? [] );
		self::update_dependency_option( BRICKS_DB_GLOBAL_VARIABLES, $snapshot['globalVariables'] ?? [] );
		self::update_dependency_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, $snapshot['variableCategories'] ?? [] );
		self::update_dependency_option( BRICKS_DB_COLOR_PALETTE, $snapshot['colorPalette'] ?? [] );
	}

	/**
	 * Build a standard import result and refresh payload.
	 *
	 * @param string $component_id  Local component ID.
	 * @param string $status        Result status.
	 * @param bool   $with_refresh Include refreshed design-system collections.
	 * @return array
	 */
	private static function result_payload( $component_id, $status, $with_refresh = true ) {
		$result = [
			'status'              => $status,
			'localComponentId'    => (string) $component_id,
			'designSystemVersion' => Component_Repository::get_design_system_version(),
		];

		if ( ! $with_refresh ) {
			return $result;
		}

		return array_merge(
			$result,
			[
				'components'                => Components::upgrade_components( Component_Repository::get_all(), false ),
				'globalClasses'             => self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES ),
				'globalClassesCategories'   => self::get_dependency_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES ),
				'globalVariables'           => self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES ),
				'globalVariablesCategories' => self::get_dependency_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES ),
				'colorPalette'              => self::get_dependency_option( BRICKS_DB_COLOR_PALETTE ),
			]
		);
	}

	/**
	 * Calculate a package checksum excluding its checksum field.
	 *
	 * @param array $package Package data.
	 * @return string
	 */
	public static function package_checksum( $package ) {
		unset( $package['manifest']['checksum'] );

		return self::checksum( $package );
	}

	/**
	 * Include reviewed dependency choices in repeat-import identity.
	 *
	 * @since 2.4.1
	 *
	 * @param array $package Remote package.
	 * @param array $options Import options.
	 * @return string
	 */
	private static function import_checksum( $package, $options ) {
		$hash = self::package_content_checksum( $package );
		if ( is_array( $options['reviewedClassMap'] ?? null ) ) {
			$hash = self::checksum( [ $hash, $options['reviewedClassMap'] ] );
		}
		$skipped = self::normalize_skipped_dependencies( $options['skippedDependencies'] ?? [] );
		return $skipped ? self::checksum(
			[
				'package'             => $hash,
				'skippedDependencies' => $skipped
			]
		) : $hash;
	}

	/**
	 * Calculate a package content checksum without transport-version metadata.
	 *
	 * @param array $package Package data.
	 * @return string
	 */
	private static function package_content_checksum( $package ) {
		unset(
			$package['manifest']['checksum'],
			$package['manifest']['sourceRevision'],
			$package['manifest']['sourceVersion'],
			$package['warnings']
		);

		return self::checksum( $package );
	}

	/**
	 * Calculate a stable SHA-256 checksum.
	 *
	 * @param mixed $data Data to hash.
	 * @return string
	 */
	private static function checksum( $data ) {
		return hash( 'sha256', wp_json_encode( self::canonicalize( $data ), JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Sort associative keys recursively for stable hashing.
	 *
	 * @param mixed $data Data to normalize.
	 * @return mixed
	 */
	private static function canonicalize( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );

		if ( ! $is_list ) {
			ksort( $data );
		}

		foreach ( $data as $key => $value ) {
			$data[ $key ] = self::canonicalize( $value );
		}

		return $data;
	}

	/**
	 * Generate a unique six-character ID.
	 *
	 * @param array $used_ids Used IDs indexed by ID.
	 * @return string
	 */
	private static function generate_unique_id( &$used_ids ) {
		do {
			$id = Helpers::generate_random_id( false );
		} while ( isset( $used_ids[ $id ] ) );

		$used_ids[ $id ] = true;

		return $id;
	}

	/**
	 * Generate a unique ID against rows containing an id field.
	 *
	 * @param array $rows Existing rows.
	 * @return string
	 */
	private static function generate_unique_id_from_rows( $rows ) {
		$used = array_fill_keys( array_filter( array_column( (array) $rows, 'id' ) ), true );

		return self::generate_unique_id( $used );
	}

	/**
	 * Find a component by ID.
	 *
	 * @param array  $components   Component definitions.
	 * @param string $component_id Component ID.
	 * @return array|null
	 */
	private static function find_component( $components, $component_id ) {
		$index = self::find_component_index( $components, $component_id );

		return $index === null ? null : $components[ $index ];
	}

	/**
	 * Find a component index by ID.
	 *
	 * @param array  $components   Component definitions.
	 * @param string $component_id Component ID.
	 * @return int|null
	 */
	private static function find_component_index( $components, $component_id ) {
		foreach ( (array) $components as $index => $component ) {
			if ( (string) ( $component['id'] ?? '' ) === (string) $component_id ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Get the user-facing component label.
	 *
	 * @param array $component Component definition.
	 * @return string
	 */
	private static function get_component_label( $component ) {
		return (string) ( $component['elements'][0]['label'] ?? $component['label'] ?? $component['id'] ?? esc_html__( 'Component', 'bricks' ) );
	}

	/**
	 * Build a source-qualified label.
	 *
	 * @param string $label       Original label.
	 * @param string $source_name Source label.
	 * @return string
	 */
	private static function source_label( $label, $source_name ) {
		return $source_name ? "$label — $source_name" : "$label — " . esc_html__( 'Remote', 'bricks' );
	}

	/**
	 * Make a label unique against normalized labels.
	 *
	 * @param string $label  Proposed label.
	 * @param array  $labels Existing lowercase labels.
	 * @return string
	 */
	private static function unique_label( $label, $labels ) {
		$candidate = $label;
		$counter   = 2;

		while ( in_array( strtolower( $candidate ), $labels, true ) ) {
			$candidate = "$label ($counter)";
			$counter++;
		}

		return $candidate;
	}

	/**
	 * Build the provenance key for a remote component.
	 *
	 * @param string $source_id  Source ID.
	 * @param string $remote_id Remote component ID.
	 * @return string
	 */
	private static function provenance_key( $source_id, $remote_id ) {
		return hash( 'sha256', $source_id . ':' . $remote_id );
	}

}
