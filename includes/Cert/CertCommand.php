<?php
/**
 * `wp mvs cert` — functional certification gate.
 *
 * Runs the behavioural cert (boot smoke + dead-toggle contract) against the
 * live site and exits non-zero on any failure, so CI and the release pipeline
 * can gate on it identically on any machine.
 *
 * @package WPMediaVerse\Cert
 * @since   1.8.1
 */

declare( strict_types=1 );

namespace WPMediaVerse\Cert;

/**
 * WP-CLI handler for the functional certification gate.
 */
class CertCommand {

	/**
	 * Run the functional certification.
	 *
	 * ## OPTIONS
	 *
	 * [<check>]
	 * : Which check to run. One of: all, contract, boot. Default: all.
	 *
	 * [--porcelain]
	 * : Emit the machine-readable JSON ledger instead of the table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs cert
	 *     wp mvs cert boot
	 *     wp mvs cert contract --porcelain
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$which     = strtolower( (string) ( $args[0] ?? 'all' ) );
		$checks    = ( 'all' === $which ) ? array() : array( $which );
		$porcelain = isset( $assoc_args['porcelain'] );

		$result = ( new CertRunner() )->run( $checks );

		if ( $porcelain ) {
			\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$this->render( $result );
		}

		if ( ! $result['ok'] ) {
			\WP_CLI::error(
				sprintf(
					'Functional certification FAILED: %d failure(s), %d hole(s).',
					$result['summary']['fail'],
					$result['summary']['hole']
				)
			);
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Functional certification passed: %d checks, %d hole(s) tracked.',
				$result['summary']['pass'],
				$result['summary']['hole']
			)
		);
	}

	/**
	 * Render the per-check rows as a readable table.
	 *
	 * @param array{summary:array<string,int>,rows:array<int,array<string,string>>,ok:bool} $result Verdict.
	 */
	private function render( array $result ): void {
		foreach ( $result['rows'] as $row ) {
			$mark = array(
				'pass' => '[PASS]',
				'fail' => '[FAIL]',
				'hole' => '[HOLE]',
			)[ $row['status'] ] ?? '[????]';
			\WP_CLI::line( sprintf( '%-7s %-9s %-44s %s', $mark, $row['check'], $row['entity'], $row['detail'] ) );
		}
		\WP_CLI::line( '' );
		\WP_CLI::line(
			sprintf(
				'Summary: %d pass, %d fail, %d hole.',
				$result['summary']['pass'],
				$result['summary']['fail'],
				$result['summary']['hole']
			)
		);
	}
}
