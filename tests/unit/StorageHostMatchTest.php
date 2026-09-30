<?php
/**
 * Storage hosts are matched on the parsed host, never on the whole URL.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\StorageService;

/**
 * Basecamp 10355018821. A substring match trusted a foreign URL that merely
 * contained a storage suffix in its path or query.
 */
class StorageHostMatchTest extends WP_UnitTestCase {

	public function test_real_storage_hosts_match(): void {
		$this->assertSame( 'r2', StorageService::driver_name_for_url( 'https://pub-abc.r2.dev/a.jpg' ) );
		$this->assertSame( 'bunnycdn', StorageService::driver_name_for_url( 'https://zone.b-cdn.net/a.jpg' ) );
		$this->assertSame( 's3', StorageService::driver_name_for_url( 'https://bucket.s3.us-east-1.amazonaws.com/a.jpg' ) );
	}

	public function test_a_suffix_in_the_path_or_query_does_not_match(): void {
		$this->assertSame( '', StorageService::driver_name_for_url( 'https://oldsite.wpcomstaging.com/x/.r2.dev/a.jpg' ) );
		$this->assertSame( '', StorageService::driver_name_for_url( 'https://evil.example/a.jpg?x=.b-cdn.net/' ) );
		$this->assertSame( '', StorageService::driver_name_for_url( 'https://notb-cdn.net/a.jpg' ) );
	}

	/**
	 * Basecamp 10355130450. PHP and browsers read these differently, so they are
	 * refused outright rather than parsed.
	 */
	public function test_ambiguous_urls_have_no_host(): void {
		$this->assertSame( '', StorageService::driver_name_for_url( 'https://evil.com\\@x.b-cdn.net/a.jpg' ) );
		$this->assertSame( '', StorageService::driver_name_for_url( 'https://user@x.b-cdn.net/a.jpg' ) );
		$this->assertSame( '', StorageService::driver_name_for_url( "https://x.b-cdn.net/a.jpg\n" ) );
		$this->assertSame( '', StorageService::host_of( 'https://evil.com\\@x.b-cdn.net/a.jpg' ) );
	}
}
