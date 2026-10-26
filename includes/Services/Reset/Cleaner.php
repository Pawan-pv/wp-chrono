<?php
/**
 * Base class for partial-reset targets.
 *
 * Each cleaner is resumable: `run()` does as much work as fits before
 * `$deadline`, then reports `done => false` and an optional `cursor`. Calling
 * it again with that cursor continues where it stopped. This is what lets the
 * REST layer split long jobs into short requests on shared hosting.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

defined( 'ABSPATH' ) || exit;

abstract class Cleaner {

	/** Rows per DELETE / SELECT batch. */
	const BATCH = 5000;

	/**
	 * @return true|string True when the cleaner can run here, otherwise the reason it can't.
	 */
	public function availability() {
		return true;
	}

	/**
	 * Dry run: what `run()` would remove.
	 *
	 * @return array{items:int,bytes:int,approximate:bool,details:string[]}
	 */
	abstract public function preview(): array;

	/**
	 * @param array<string,mixed> $options  Cleaner-specific options plus an optional `cursor`.
	 * @param float               $deadline microtime(true) value after which to stop.
	 * @return array{done:bool,processed:int,cursor:int,log:string[]}
	 */
	abstract public function run( array $options, float $deadline ): array;

	protected static function out_of_time( float $deadline ): bool {
		return microtime( true ) >= $deadline;
	}

	/**
	 * @param string[] $log
	 * @return array{done:bool,processed:int,cursor:int,log:string[]}
	 */
	protected static function result( bool $done, int $processed, array $log, int $cursor = 0 ): array {
		return array(
			'done'      => $done,
			'processed' => $processed,
			'cursor'    => $cursor,
			'log'       => $log,
		);
	}

	/**
	 * @param string[] $details
	 * @return array{items:int,bytes:int,approximate:bool,details:string[]}
	 */
	protected static function summary( int $items, int $bytes, array $details = array(), bool $approximate = false ): array {
		return array(
			'items'       => $items,
			'bytes'       => $bytes,
			'approximate' => $approximate,
			'details'     => $details,
		);
	}
}
