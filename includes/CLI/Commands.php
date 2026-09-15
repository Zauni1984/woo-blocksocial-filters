<?php
/**
 * WP-CLI commands.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\CLI;

use BlockSocial\Filters\Index\Schema;
use BlockSocial\Filters\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the filter index from the command line.
 */
class Commands {

	/**
	 * Rebuild the product index.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<number>]
	 * : Products per batch. Default 500.
	 *
	 * [--resume]
	 * : Continue an interrupted build instead of starting over.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bsf index
	 *     wp bsf index --batch=1000
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function index( $args, $assoc_args ): void {
		unset( $args );

		$indexer = bsf()->indexer();
		$batch   = max( 10, min( 5000, (int) ( $assoc_args['batch'] ?? 500 ) ) );
		$resume  = isset( $assoc_args['resume'] );

		if ( ! Schema::installed() ) {
			Schema::install();
			\WP_CLI::log( 'Index tables created.' );
		}

		$state = $resume ? $indexer->state() : $indexer->start( true );

		if ( $resume && 'running' !== $state['status'] ) {
			$state = $indexer->start( false );
		}

		$total    = max( 1, (int) $state['total'] );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing products', $total );
		$done     = (int) $state['processed'];

		$progress->tick( $done );

		while ( 'running' === $state['status'] ) {
			$previous = (int) $state['processed'];
			$state    = $indexer->run_batch( $batch );
			$step     = (int) $state['processed'] - $previous;

			if ( $step > 0 ) {
				$progress->tick( $step );
			}
		}

		$progress->finish();

		Cache::flush();

		\WP_CLI::success( sprintf( 'Indexed %d products.', (int) $state['processed'] ) );
	}

	/**
	 * Process queued index updates.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum products to process. Default 500.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function queue( $args, $assoc_args ): void {
		unset( $args );

		$limit     = max( 1, min( 5000, (int) ( $assoc_args['limit'] ?? 500 ) ) );
		$processed = bsf()->indexer()->process_queue( $limit );

		\WP_CLI::success( sprintf( 'Processed %d queued products.', $processed ) );
	}

	/**
	 * Show index statistics.
	 */
	public function status(): void {
		$indexer = bsf()->indexer();
		$state   = $indexer->state();
		$stats   = $indexer->stats();

		$rows = array(
			array(
				'metric' => 'status',
				'value'  => (string) $state['status'],
			),
			array(
				'metric' => 'catalogue products',
				'value'  => (string) $stats['indexable'],
			),
			array(
				'metric' => 'indexed products',
				'value'  => (string) $stats['products'],
			),
			array(
				'metric' => 'index rows',
				'value'  => (string) $stats['rows'],
			),
			array(
				'metric' => 'queued',
				'value'  => (string) $stats['queue'],
			),
			array(
				'metric' => 'tables installed',
				'value'  => Schema::installed() ? 'yes' : 'no',
			),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/**
	 * Flush every cached count and option lookup.
	 */
	public function flush(): void {
		Cache::flush();

		\WP_CLI::success( 'Filter caches flushed.' );
	}
}
