<?php
/**
 * Serialize-safe search and replace engine.
 *
 * @package WPFH_Search_Replace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPFH_BSR_Engine {

	const BATCH_SIZE  = 200;
	const MAX_SAMPLES = 25;

	/**
	 * Column types we are willing to touch.
	 *
	 * @var string[]
	 */
	private static $text_types = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' );

	/**
	 * Columns that must never be rewritten (credentials, tokens).
	 *
	 * @var string[]
	 */
	private static $protected_columns = array( 'user_pass', 'user_activation_key' );

	/**
	 * List the tables of the current site (prefix scoped), excluding the plugin's own backup table.
	 *
	 * @return string[]
	 */
	public static function list_tables() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all            = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$out            = array();
		$options_table  = '';
		$subsite_regex  = ( is_multisite() && is_main_site() )
			? '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/'
			: '';

		foreach ( (array) $all as $table ) {
			if ( $wpdb->prefix . 'wpfh_bsr_backup' === $table ) {
				continue;
			}
			// On a Multisite main site, exclude subsite tables (e.g. wp_2_posts) whose prefix shares wp_.
			if ( '' !== $subsite_regex && preg_match( $subsite_regex, $table ) ) {
				continue;
			}
			if ( $wpdb->options === $table ) {
				$options_table = $table;
				continue;
			}
			$out[] = $table;
		}

		// Always process wp_options last so siteurl/home changes do not break mid-run AJAX requests.
		if ( '' !== $options_table ) {
			$out[] = $options_table;
		}

		return $out;
	}

	/**
	 * Describe a table: primary key column and replaceable text columns.
	 *
	 * @param string $table Table name (must come from list_tables()).
	 * @param bool   $guid  Whether the guid column is included.
	 * @return array{pk:string,columns:string[]}
	 */
	public static function describe_table( $table, $guid = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$pks  = array();
		$text = array();
		foreach ( (array) $cols as $col ) {
			$name = $col['Field'];
			if ( 'PRI' === $col['Key'] ) {
				$pks[] = $name;
				// Never rewrite primary key columns (even VARCHAR/TEXT PKs) to preserve row identity and Undo.
				continue;
			}
			$type = strtolower( preg_replace( '/\(.*$/', '', $col['Type'] ) );
			if ( ! in_array( $type, self::$text_types, true ) ) {
				continue;
			}
			if ( in_array( $name, self::$protected_columns, true ) ) {
				continue;
			}
			// The guid column is a permanent identifier; it is excluded unless explicitly requested.
			if ( 'guid' === $name && ! $guid ) {
				continue;
			}
			$text[] = $name;
		}
		return array(
			'pk'      => 1 === count( $pks ) ? $pks[0] : '',
			'columns' => $text,
		);
	}

	/**
	 * Recursively replace inside any value, keeping serialized data valid.
	 *
	 * @param mixed  $data    Value (string, array, object, serialized string).
	 * @param string $search  Search string.
	 * @param string $replace Replacement.
	 * @param bool   $ci      Case-insensitive.
	 * @param int    $count   Replacement counter (by reference).
	 * @param bool   $skipped Set to true when an unsafe value was left untouched (by reference).
	 * @return mixed
	 */
	public static function replace_value( $data, $search, $replace, $ci, &$count, &$skipped ) {
		if ( is_string( $data ) ) {
			if ( is_serialized( $data ) ) {
				// Only stdClass is instantiated while parsing data; any other class stays an inert placeholder.
				$un = @unserialize( $data, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				if ( false !== $un || 'b:0;' === $data ) {
					$inner = self::replace_value( $un, $search, $replace, $ci, $count, $skipped );
					// Use serialize() directly so serialized scalars (e.g. s:10:"...";) stay serialized.
					return serialize( $inner ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				}
				// Corrupted serialized string: replacing inside it would break byte lengths. Leave it alone.
				if ( false !== ( $ci ? stripos( $data, $search ) : strpos( $data, $search ) ) ) {
					$skipped = true;
				}
				return $data;
			}
			$n   = 0;
			$out = $ci ? str_ireplace( $search, $replace, $data, $n ) : str_replace( $search, $replace, $data, $n );
			$count += $n;
			return $out;
		}
		if ( is_array( $data ) ) {
			$new = array();
			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) ) {
					$key = self::replace_value( $key, $search, $replace, $ci, $count, $skipped );
				}
				$new[ $key ] = self::replace_value( $value, $search, $replace, $ci, $count, $skipped );
			}
			return $new;
		}
		if ( is_object( $data ) ) {
			if ( $data instanceof __PHP_Incomplete_Class ) {
				// Class not loaded: its private state cannot be rewritten safely, so keep it untouched.
				$skipped = true;
				return $data;
			}
			foreach ( get_object_vars( $data ) as $prop => $value ) {
				$data->$prop = self::replace_value( $value, $search, $replace, $ci, $count, $skipped );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * Fingerprint of the parameters of a run. A live run is only accepted after a dry run with the same fingerprint.
	 *
	 * @param array $p Normalised params.
	 * @return string
	 */
	public static function fingerprint( $p ) {
		return md5( wp_json_encode( array( $p['search'], $p['replace'], (int) $p['ci'], (int) $p['guid'], $p['tables'], $p['columns'] ) ) );
	}

	/**
	 * Transient key of the dry-run proof for the current user.
	 *
	 * @return string
	 */
	public static function proof_key() {
		return 'wpfh_bsr_dry_' . get_current_user_id();
	}

	/**
	 * Process one batch of rows (LIMIT/OFFSET).
	 *
	 * @param array $p Normalised request (search, replace, ci, guid, dry, tables, columns, table_index, offset, run_id).
	 * @return array
	 */
	public static function process_batch( $p ) {
		global $wpdb;

		$tables = self::list_tables();
		if ( ! empty( $p['tables_explicit'] ) ) {
			$tables = ! empty( $p['tables'] ) ? array_values( array_intersect( $tables, $p['tables'] ) ) : array();
		} elseif ( ! empty( $p['tables'] ) ) {
			$tables = array_values( array_intersect( $tables, $p['tables'] ) );
		}

		$idx    = (int) $p['table_index'];
		$result = array(
			'done'         => false,
			'table'        => '',
			'next_table'   => $idx,
			'next_offset'  => (int) $p['offset'],
			'total_tables' => count( $tables ),
			'scanned'      => 0,
			'rows'         => 0,
			'cells'        => 0,
			'replacements' => 0,
			'samples'      => array(),
			'skipped_rows' => 0,
			'notes'        => array(),
		);

		if ( $idx >= count( $tables ) ) {
			$result['done'] = true;
			return $result;
		}

		$table           = $tables[ $idx ];
		$result['table'] = $table;
		$info            = self::describe_table( $table, ! empty( $p['guid'] ) );

		$columns = $info['columns'];
		if ( ! empty( $p['columns'][ $table ] ) && is_array( $p['columns'][ $table ] ) ) {
			$selected_cols = $p['columns'][ $table ];
			if ( ! empty( $p['guid'] ) && in_array( 'guid', $columns, true ) && ! in_array( 'guid', $selected_cols, true ) ) {
				$selected_cols[] = 'guid';
			}
			$columns = array_values( array_intersect( $columns, $selected_cols ) );
		}

		if ( '' === $info['pk'] || empty( $columns ) ) {
			if ( 0 === (int) $p['offset'] ) {
				$result['notes'][] = '' === $info['pk'] ? 'no-single-primary-key' : 'no-text-columns';
			}
			$result['next_table']  = $idx + 1;
			$result['next_offset'] = 0;
			$result['done']        = ( $idx + 1 ) >= count( $tables );
			return $result;
		}

		$pk     = $info['pk'];
		$fields = array_merge( array( $pk ), $columns );
		$ph     = implode( ', ', array_fill( 0, count( $fields ), '%i' ) );
		$args   = array_merge( $fields, array( $table, $pk, self::BATCH_SIZE, (int) $p['offset'] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT $ph FROM %i ORDER BY %i ASC LIMIT %d OFFSET %d", $args ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$result['scanned']++;
			// Never rewrite this plugin's own dry-run proof transient in wp_options.
			if ( $wpdb->options === $table && isset( $row['option_name'] ) && 0 === strpos( (string) $row['option_name'], '_transient_wpfh_bsr_dry_' ) ) {
				continue;
			}
			$changes     = array();
			$row_skipped = false;
			foreach ( $columns as $col ) {
				$val = $row[ $col ];
				if ( null === $val || '' === $val ) {
					continue;
				}
				$count   = 0;
				$skipped = false;
				$new     = self::replace_value( $val, $p['search'], $p['replace'], ! empty( $p['ci'] ), $count, $skipped );
				if ( $skipped ) {
					$row_skipped = true;
				}
				if ( $count > 0 && $new !== $val ) {
					$changes[ $col ] = $new;
					$result['cells']++;
					$result['replacements'] += $count;
					if ( count( $result['samples'] ) < self::MAX_SAMPLES ) {
						$result['samples'][] = array(
							'table'  => $table,
							'column' => $col,
							'id'     => $row[ $pk ],
							'before' => self::snippet( $val, $p['search'], ! empty( $p['ci'] ) ),
							'after'  => self::snippet( $new, $p['replace'], ! empty( $p['ci'] ) ),
						);
					}
				}
			}
			if ( $row_skipped ) {
				$result['skipped_rows']++;
			}
			if ( empty( $changes ) ) {
				continue;
			}
			$result['rows']++;
			if ( empty( $p['dry'] ) ) {
				foreach ( $changes as $col => $new ) {
					/**
					 * Fires right before a cell is rewritten (Pro stores a restorable backup here).
					 *
					 * @param string $run_id Run identifier.
					 * @param string $table  Table.
					 * @param string $pk     Primary key column.
					 * @param mixed  $pk_val Primary key value.
					 * @param string $col    Column.
					 * @param string $old    Original value.
					 */
					do_action( 'wpfh_bsr_before_cell_update', $p['run_id'], $table, $pk, $row[ $pk ], $col, $row[ $col ] );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $table, $changes, array( $pk => $row[ $pk ] ) );
			}
		}

		if ( count( (array) $rows ) < self::BATCH_SIZE ) {
			$result['next_table']  = $idx + 1;
			$result['next_offset'] = 0;
			$result['done']        = ( $idx + 1 ) >= count( $tables );
		} else {
			$result['next_offset'] = (int) $p['offset'] + self::BATCH_SIZE;
		}

		return $result;
	}

	/**
	 * Short, readable context around the first occurrence of $needle.
	 *
	 * @param string $text   Text.
	 * @param string $needle Needle.
	 * @param bool   $ci     Case-insensitive.
	 * @return string
	 */
	private static function snippet( $text, $needle, $ci ) {
		$text       = (string) $text;
		$has_mb     = function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && function_exists( 'mb_strpos' ) && function_exists( 'mb_stripos' );
		if ( '' === $needle ) {
			return $has_mb ? mb_substr( $text, 0, 120 ) : substr( $text, 0, 120 );
		}
		if ( $has_mb ) {
			$pos = $ci ? mb_stripos( $text, $needle ) : mb_strpos( $text, $needle );
			if ( false === $pos ) {
				return mb_substr( $text, 0, 120 );
			}
			$start = max( 0, $pos - 40 );
			return ( $start > 0 ? '…' : '' ) . mb_substr( $text, $start, mb_strlen( $needle ) + 80 ) . '…';
		}
		$pos = $ci ? stripos( $text, $needle ) : strpos( $text, $needle );
		if ( false === $pos ) {
			return substr( $text, 0, 120 );
		}
		$start = max( 0, $pos - 40 );
		return ( $start > 0 ? '…' : '' ) . substr( $text, $start, strlen( $needle ) + 80 ) . '…';
	}
}
