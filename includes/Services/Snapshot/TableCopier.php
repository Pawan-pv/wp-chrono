<?php
/**
 * Copies one table into another in bounded batches.
 *
 * Strategy is chosen from the source's primary key:
 * - keyset:    single integer PK  → `WHERE pk > last ORDER BY pk LIMIT n`
 * - composite: any other PK       → `WHERE (a > x) OR (a = x AND b > y) … ORDER BY a, b LIMIT n`
 * - single:    no PK at all       → one `INSERT … SELECT` (without an ordering
 *              key, paging could skip or duplicate rows)
 *
 * Both keyset modes resume from the last key copied, so rows inserted into the
 * source while a copy is in progress can never shift a page and cause a
 * duplicate-key error the way LIMIT/OFFSET would. The expanded OR form is used
 * instead of a row constructor `(a,b) > (x,y)` because both MySQL and MariaDB
 * turn it into a range scan on the primary key.
 *
 * Generated columns are excluded from the column list (MySQL rejects explicit values).
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WPChrono\Services\Database;

defined( 'ABSPATH' ) || exit;

final class TableCopier {

	const BATCH = 5000;

	/**
	 * @return array{mode:string,keys:string[]}
	 */
	public static function strategy( string $table ): array {
		global $wpdb;
		$q    = Database::quote_identifier( $table );
		$keys = $wpdb->get_results( "SHOW KEYS FROM {$q} WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $keys ) {
			return array( 'mode' => 'single', 'keys' => array() );
		}

		usort(
			$keys,
			static function ( $a, $b ) {
				return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
			}
		);
		$columns = array_column( $keys, 'Column_name' );

		if ( 1 === count( $columns ) ) {
			$type = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$q} WHERE Field = %s", $columns[0] ), 1 ); // phpcs:ignore WordPress.DB
			if ( preg_match( '/^(tiny|small|medium|big)?int\b/i', $type ) ) {
				return array( 'mode' => 'keyset', 'keys' => $columns );
			}
		}

		return array( 'mode' => 'composite', 'keys' => $columns );
	}

	/**
	 * Insertable (non-generated) columns, quoted and comma-joined.
	 */
	public static function column_list( string $table ): string {
		global $wpdb;
		$columns = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND EXTRA NOT LIKE %s
				ORDER BY ORDINAL_POSITION",
				$wpdb->dbname,
				$table,
				'%GENERATED%'
			)
		);
		return implode( ',', array_map( array( Database::class, 'quote_identifier' ), $columns ) );
	}

	/**
	 * ` WHERE …` clause selecting rows after `$last` in key order ('' when starting).
	 *
	 * @param string[] $keys
	 * @param string   $last JSON array of key values, or '' for the first page.
	 */
	public static function after_clause( array $keys, string $last ): string {
		global $wpdb;
		$values = self::decode_key( $last );
		if ( null === $values || count( $values ) !== count( $keys ) ) {
			return '';
		}

		$quoted = array_map( array( Database::class, 'quote_identifier' ), $keys );
		$ors    = array();
		foreach ( $quoted as $i => $column ) {
			$terms = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$terms[] = $wpdb->prepare( "{$quoted[ $j ]} = %s", $values[ $j ] ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$terms[] = $wpdb->prepare( "{$column} > %s", $values[ $i ] ); // phpcs:ignore WordPress.DB.PreparedSQL
			$ors[]   = '(' . implode( ' AND ', $terms ) . ')';
		}
		return ' WHERE ' . implode( ' OR ', $ors );
	}

	/**
	 * @param string[] $keys
	 */
	public static function order_clause( array $keys ): string {
		return $keys ? ' ORDER BY ' . implode( ',', array_map( array( Database::class, 'quote_identifier' ), $keys ) ) : '';
	}

	/**
	 * JSON-encoded key of a row.
	 *
	 * @param string[]            $keys
	 * @param array<string,mixed> $row
	 */
	public static function encode_key( array $keys, array $row ): string {
		$values = array();
		foreach ( $keys as $key ) {
			$values[] = (string) $row[ $key ];
		}
		return (string) wp_json_encode( $values );
	}

	/**
	 * @return string[]|null
	 */
	private static function decode_key( string $last ): ?array {
		if ( '' === $last ) {
			return null;
		}
		$decoded = json_decode( $last, true );
		// Pre-v3 cursors stored a bare integer.
		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array( $last );
	}

	/**
	 * Copies the next batch. `$state` carries mode, keys, columns, last_key and
	 * rows (rows copied so far) and is updated in place.
	 *
	 * @param array{mode:string,keys:string[],columns:string,last_key:string,rows:int} $state
	 * @return bool|null True when the table is finished, false if more remains, null on SQL error.
	 */
	public static function copy_batch( string $from, string $to, array &$state ): ?bool {
		global $wpdb;
		$src    = Database::quote_identifier( $from );
		$dst    = Database::quote_identifier( $to );
		$cols   = $state['columns'];
		$insert = "INSERT INTO {$dst} ({$cols}) SELECT {$cols} FROM {$src}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		if ( 'single' === $state['mode'] || ! $state['keys'] ) {
			$copied = $wpdb->query( $insert );
			if ( false === $copied ) {
				return null;
			}
			$state['rows'] += (int) $copied;
			return true;
		}

		$where  = self::after_clause( $state['keys'], (string) $state['last_key'] );
		$order  = self::order_clause( $state['keys'] );
		$copied = $wpdb->query( "{$insert}{$where}{$order} LIMIT " . self::BATCH );
		if ( false === $copied ) {
			return null;
		}
		if ( $copied > 0 ) {
			// The destination only ever receives rows in ascending key order, so
			// its largest key is the cursor.
			$desc = implode( ',', array_map( static function ( $k ) { return Database::quote_identifier( $k ) . ' DESC'; }, $state['keys'] ) ); // phpcs:ignore
			$last = $wpdb->get_row( 'SELECT ' . implode( ',', array_map( array( Database::class, 'quote_identifier' ), $state['keys'] ) ) . " FROM {$dst} ORDER BY {$desc} LIMIT 1", ARRAY_A );
			$state['last_key'] = self::encode_key( $state['keys'], (array) $last );
		}
		// phpcs:enable

		$state['rows'] += (int) $copied;
		return $copied < self::BATCH;
	}

	/**
	 * `SHOW CREATE TABLE` with the table name, AUTO_INCREMENT counter and table
	 * comment stripped, so the same schema hashes identically under any name.
	 */
	public static function normalized_create( string $table ): string {
		global $wpdb;
		$row = $wpdb->get_row( 'SHOW CREATE TABLE ' . Database::quote_identifier( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return '';
		}
		$sql = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `t`', $row[1] );
		$sql = preg_replace( '/ AUTO_INCREMENT=\d+/', '', $sql );
		return (string) preg_replace( "/ COMMENT='(?:[^'\\\\]|\\\\.|'')*'\s*$/s", '', $sql );
	}

	/**
	 * `CONSTRAINT … FOREIGN KEY … REFERENCES …` clauses from a CREATE TABLE
	 * statement. CREATE TABLE … LIKE drops these, so they are stored with the
	 * snapshot and re-added after a restore.
	 *
	 * @return string[]
	 */
	public static function foreign_keys( string $create_sql ): array {
		preg_match_all( '/^\s*(CONSTRAINT\s+`(?:[^`]|``)+`\s+FOREIGN KEY\s.*?),?\s*$/m', $create_sql, $m );
		return $m[1];
	}

	public static function table_comment( string $table ): string {
		global $wpdb;
		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', $wpdb->dbname, $table )
		);
	}

	public static function set_comment( string $table, string $comment ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE ' . Database::quote_identifier( $table ) . ' COMMENT = %s', $comment ) ); // phpcs:ignore WordPress.DB
	}

	public static function exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB
	}

	public static function size( string $table ): int {
		global $wpdb;
		$q = Database::quote_identifier( $table );
		$wpdb->query( "ANALYZE TABLE {$q}" ); // phpcs:ignore WordPress.DB -- refreshes cached INFORMATION_SCHEMA stats.
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', $wpdb->dbname, $table )
		);
	}
}
