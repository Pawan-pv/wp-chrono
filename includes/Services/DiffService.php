<?php
/**
 * Compares a snapshot against the live database.
 *
 * Direction: "before" = snapshot, "after" = live now. So `added_tables` exist
 * live but not in the snapshot, and restoring would remove them (if chosen).
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;
use WPChrono\Services\Snapshot\TableCopier;

defined( 'ABSPATH' ) || exit;

final class DiffService {

	/** Live tables up to this many (estimated) rows get an exact COUNT(*). */
	const EXACT_COUNT_LIMIT = 500000;

	/** Tables up to this many rows get a CHECKSUM TABLE content comparison. */
	const CHECKSUM_LIMIT = 100000;

	const MAX_OPTION_CHANGES = 500;

	const VALUE_PREVIEW = 1200;

	/** SQL LIKE patterns (underscores escaped) for wp_options rows that churn constantly. */
	const NOISY_OPTION_PATTERNS = array( '\_transient\_%', '\_site\_transient\_%', 'cron', 'wpchrono\_%', 'wp\_chrono\_%', '\_wpc\_%' );

	const OPTION_LABELS = array(
		'active_plugins'      => 'Active plugins',
		'stylesheet'          => 'Active theme',
		'template'            => 'Parent theme',
		'blogname'            => 'Site title',
		'blogdescription'     => 'Tagline',
		'siteurl'             => 'WordPress address (URL)',
		'home'                => 'Site address (URL)',
		'permalink_structure' => 'Permalink structure',
		'admin_email'         => 'Administration email',
		'users_can_register'  => 'Anyone can register',
		'default_role'        => 'New user default role',
		'WPLANG'              => 'Site language',
		'timezone_string'     => 'Timezone',
		'posts_per_page'      => 'Posts per page',
		'show_on_front'       => 'Homepage displays',
		'page_on_front'       => 'Static homepage',
		'blog_public'         => 'Search engine visibility',
	);

	/** @var SnapshotService */
	private $snapshots;

	public function __construct( ?SnapshotService $snapshots = null ) {
		$this->snapshots = $snapshots ?? new SnapshotService();
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function compare( int $snapshot_id ) {
		$snapshot = $this->snapshots->get( $snapshot_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( 'ready' !== $snapshot['status'] ) {
			return new WP_Error( 'wp_chrono_snapshot_state', 'Only ready snapshots can be compared.', array( 'status' => 409 ) );
		}

		$started = microtime( true );
		$tables  = $this->snapshots->tables( $snapshot_id );

		return array(
			'snapshot'    => $snapshot,
			'tables'      => $this->compare_tables( $tables ),
			'options'     => $this->compare_options( $tables ),
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $snap Tracking rows keyed by original name.
	 * @return array<string,mixed>
	 */
	private function compare_tables( array $snap ): array {
		global $wpdb;
		$live = Database::site_tables();

		$added = array();
		foreach ( array_diff_key( $live, $snap ) as $name => $stats ) {
			$added[] = array( 'table' => $name, 'rows' => $this->row_count( $name, $stats['rows'] ), 'bytes' => $stats['bytes'] );
		}

		$removed = array();
		foreach ( array_diff_key( $snap, $live ) as $name => $row ) {
			$removed[] = array( 'table' => $name, 'rows' => (int) $row['row_count'], 'bytes' => (int) $row['size_bytes'] );
		}

		$modified  = array();
		$unchanged = 0;
		foreach ( array_intersect_key( $snap, $live ) as $name => $row ) {
			$rows_before = (int) $row['row_count'];
			$rows_after  = $this->row_count( $name, $live[ $name ]['rows'] );
			$create      = TableCopier::normalized_create( $name );
			$schema_diff = md5( $create ) === $row['schema_hash'] ? null : $this->line_diff( (string) $row['create_sql'], $create );

			$content_changed = null;
			if ( null === $schema_diff && $rows_before === $rows_after && $rows_after <= self::CHECKSUM_LIMIT ) {
				$content_changed = $this->checksum( $name ) !== $this->checksum( $row['snapshot_table'] );
			}

			if ( $rows_before === $rows_after && null === $schema_diff && true !== $content_changed ) {
				$unchanged++;
				continue;
			}

			$bytes_after = $live[ $name ]['bytes'];
			$modified[]  = array(
				'table'           => $name,
				'rows_before'     => $rows_before,
				'rows_after'      => $rows_after,
				'rows_delta'      => $rows_after - $rows_before,
				'bytes_before'    => (int) $row['size_bytes'],
				'bytes_after'     => $bytes_after,
				'bytes_delta'     => $bytes_after - (int) $row['size_bytes'],
				'schema_changed'  => null !== $schema_diff,
				'schema_diff'     => $schema_diff,
				'content_changed' => $content_changed,
			);
		}

		usort(
			$modified,
			static function ( $a, $b ) {
				return abs( $b['rows_delta'] ) <=> abs( $a['rows_delta'] );
			}
		);

		return array(
			'added_tables'     => $added,
			'removed_tables'   => $removed,
			'modified_tables'  => $modified,
			'unchanged_tables' => $unchanged,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $snap
	 * @return array<string,mixed>
	 */
	private function compare_options( array $snap ): array {
		global $wpdb;
		$empty = array( 'available' => false, 'added' => array(), 'deleted' => array(), 'modified' => array(), 'truncated' => false );
		if ( ! isset( $snap[ $wpdb->options ] ) ) {
			return $empty;
		}

		$before = $this->option_digest( $snap[ $wpdb->options ]['snapshot_table'] );
		$after  = $this->option_digest( $wpdb->options );

		$result = array( 'available' => true, 'added' => array(), 'deleted' => array(), 'modified' => array(), 'truncated' => false );
		$count  = 0;

		foreach ( $after as $name => $row ) {
			if ( ! isset( $before[ $name ] ) ) {
				$result['added'][] = $this->option_entry( $name, null, $row['value'] );
			} elseif ( $before[ $name ]['hash'] !== $row['hash'] ) {
				$result['modified'][] = $this->option_entry( $name, $before[ $name ]['value'], $row['value'] );
			} else {
				continue;
			}
			if ( ++$count >= self::MAX_OPTION_CHANGES ) {
				$result['truncated'] = true;
				return $result;
			}
		}
		foreach ( array_diff_key( $before, $after ) as $name => $row ) {
			$result['deleted'][] = $this->option_entry( $name, $row['value'], null );
			if ( ++$count >= self::MAX_OPTION_CHANGES ) {
				$result['truncated'] = true;
				break;
			}
		}

		return $result;
	}

	/**
	 * option_name => [hash of full value, value preview].
	 *
	 * @return array<string,array{hash:string,value:string}>
	 */
	private function option_digest( string $table ): array {
		global $wpdb;
		$where = array();
		foreach ( self::NOISY_OPTION_PATTERNS as $pattern ) {
			$where[] = $wpdb->prepare( 'option_name NOT LIKE %s', $pattern );
		}
		$q    = Database::quote_identifier( $table );
		$rows = $wpdb->get_results( 'SELECT option_name, MD5(option_value) AS h, LEFT(option_value, ' . ( self::VALUE_PREVIEW * 4 ) . ") AS v FROM {$q} WHERE " . implode( ' AND ', $where ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['option_name'] ] = array( 'hash' => $row['h'], 'value' => (string) $row['v'] );
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function option_entry( string $name, ?string $old, ?string $new ): array {
		$entry = array(
			'name'      => $name,
			'label'     => self::OPTION_LABELS[ $name ] ?? null,
			'old_value' => null === $old ? null : $this->format_value( $old ),
			'new_value' => null === $new ? null : $this->format_value( $new ),
		);

		if ( 'active_plugins' === $name ) {
			// Snapshot values may come from an uploaded dump or a cloud pull:
			// never instantiate objects from them.
			$was   = array_filter( (array) self::plain_unserialize( (string) $old ), 'is_string' );
			$is    = array_filter( (array) self::plain_unserialize( (string) $new ), 'is_string' );
			$parts = array();
			foreach ( array_diff( $is, $was ) as $plugin ) {
				$parts[] = '+' . $plugin;
			}
			foreach ( array_diff( $was, $is ) as $plugin ) {
				$parts[] = '−' . $plugin;
			}
			$entry['summary'] = $parts ? implode( ', ', $parts ) : 'Order changed';
		} elseif ( isset( self::OPTION_LABELS[ $name ] ) && null !== $old && null !== $new ) {
			$entry['summary'] = sprintf( '%s → %s', $this->short( $old ), $this->short( $new ) );
		}

		return $entry;
	}

	/**
	 * unserialize() that never creates objects (they become
	 * __PHP_Incomplete_Class), so crafted data can't trigger gadget chains.
	 *
	 * @return mixed
	 */
	private static function plain_unserialize( string $raw ) {
		return is_serialized( $raw ) ? @unserialize( trim( $raw ), array( 'allowed_classes' => false ) ) : $raw; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Serialized values are rendered as pretty JSON; everything is truncated.
	 */
	private function format_value( string $raw ): string {
		$value = is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw; // phpcs:ignore
		if ( false === $value && 'b:0;' !== $raw && is_serialized( $raw ) ) {
			$value = $raw; // Truncated preview of a long serialized value: show it raw.
		}
		$text = is_string( $value ) ? $value : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return mb_strlen( $text ) > self::VALUE_PREVIEW ? mb_substr( $text, 0, self::VALUE_PREVIEW ) . '…' : $text;
	}

	private function short( string $raw ): string {
		$text = '' === $raw ? '(empty)' : $raw;
		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 60 ) . '…' : $text;
	}

	private function row_count( string $table, int $estimate ): int {
		global $wpdb;
		if ( $estimate > self::EXACT_COUNT_LIMIT ) {
			return $estimate;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::quote_identifier( $table ) ); // phpcs:ignore WordPress.DB
	}

	private function checksum( string $table ): string {
		global $wpdb;
		$row = $wpdb->get_row( 'CHECKSUM TABLE ' . Database::quote_identifier( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB
		return (string) ( $row[1] ?? '' );
	}

	/**
	 * Minimal line diff of two CREATE TABLE statements.
	 *
	 * @return array{added:string[],removed:string[]}
	 */
	private function line_diff( string $before, string $after ): array {
		$a = array_map( 'trim', explode( "\n", $before ) );
		$b = array_map( 'trim', explode( "\n", $after ) );
		return array(
			'added'   => array_values( array_diff( $b, $a ) ),
			'removed' => array_values( array_diff( $a, $b ) ),
		);
	}
}
