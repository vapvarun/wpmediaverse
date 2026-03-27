<?php
/**
 * Test WP-CLI command registration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class CLITest extends WP_UnitTestCase {

	public function test_cli_commands_registered(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			$this->markTestSkipped( 'WP-CLI not available.' );
		}

		$commands = \WP_CLI::get_runner()->find_command_to_run( array( 'mvs' ) );
		$this->assertNotEmpty( $commands );
	}
}
