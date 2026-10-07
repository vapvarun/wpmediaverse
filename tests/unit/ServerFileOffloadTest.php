<?php
/**
 * /serve hands file streaming to the web server only when proven.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\ServerFileOffload;

/**
 * Card 10369185494. Opt-in (constant or filter) and active only after the
 * probe proved the configured mode; otherwise PHP streams as before.
 */
class ServerFileOffloadTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'mvs_serve_offload' );
		delete_option( ServerFileOffload::OPTION );
		parent::tear_down();
	}

	private function configure( string $mode ): void {
		add_filter( 'mvs_serve_offload', static fn() => $mode );
	}

	public function test_off_unless_configured_with_a_known_mode(): void {
		$this->assertSame( '', ServerFileOffload::configured_mode() );

		$this->configure( 'x-turbo' );
		$this->assertSame( '', ServerFileOffload::configured_mode(), 'Unknown modes are ignored.' );
	}

	public function test_active_only_after_a_probe_proved_the_same_mode(): void {
		$this->configure( 'x-accel' );
		$this->assertSame( '', ServerFileOffload::active_mode(), 'Never probed.' );

		update_option(
			ServerFileOffload::OPTION,
			array(
				'mode'       => 'x-accel',
				'ok'         => false,
				'status'     => 404,
				'checked_at' => time(),
			)
		);
		$this->assertSame( '', ServerFileOffload::active_mode(), 'Probe failed.' );

		update_option(
			ServerFileOffload::OPTION,
			array(
				'mode'       => 'x-sendfile',
				'ok'         => true,
				'status'     => 200,
				'checked_at' => time(),
			)
		);
		$this->assertSame( '', ServerFileOffload::active_mode(), 'Probe proved a different mode.' );

		update_option(
			ServerFileOffload::OPTION,
			array(
				'mode'       => 'x-accel',
				'ok'         => true,
				'status'     => 200,
				'checked_at' => time(),
			)
		);
		$this->assertSame( 'x-accel', ServerFileOffload::active_mode() );
	}

	public function test_inactive_offload_leaves_streaming_to_php(): void {
		$file = wp_upload_dir()['basedir'] . '/qa-rft-offload.bin';
		file_put_contents( $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertFalse( ServerFileOffload::send( $file ) );

		wp_delete_file( $file );
	}

	public function test_nginx_location_aliases_the_uploads_folder(): void {
		$block = ServerFileOffload::nginx_location();

		// ^~ so the image, CSS and font regex locations cannot take the redirect.
		$this->assertStringContainsString( 'location ^~ /mvs-internal-files/ {', $block );
		$this->assertStringContainsString( 'internal;', $block );
		// nginx drops PHP's security headers on the redirect; the block adds them.
		$this->assertStringContainsString( 'add_header X-Content-Type-Options "nosniff" always;', $block );
		$this->assertStringContainsString( 'add_header Content-Security-Policy "' . ServerFileOffload::PROBE_CSP . '" always;', $block );
		// Quoted: a path with a space is otherwise two arguments and fails nginx -t.
		$this->assertStringContainsString( 'alias "' . trailingslashit( wp_upload_dir()['basedir'] ) . '";', $block );
	}
}
