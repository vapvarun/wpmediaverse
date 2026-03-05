<?php
/**
 * Tests for SignedUrlService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Services\SignedUrlService;
use WPMediaVerse\Services\AccessRulesService;
use WPMediaVerse\Services\PrivacyService;

class SignedUrlServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Signed URL service under test.
	 *
	 * @var SignedUrlService
	 */
	private $service;

	/**
	 * Access rules service.
	 *
	 * @var AccessRulesService
	 */
	private $access_rules;

	/**
	 * Privacy service.
	 *
	 * @var PrivacyService
	 */
	private $privacy;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	/**
	 * Test user ID (author).
	 *
	 * @var int
	 */
	private $author_id;

	protected function setUp(): void {
		parent::setUp();

		$this->access_rules = new AccessRulesService();
		$this->privacy      = new PrivacyService();
		$this->service      = new SignedUrlService( $this->access_rules, $this->privacy );

		$this->author_id = 1;

		// Create a test media post.
		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Signed URL Test Media',
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);

		update_post_meta( $this->media_id, '_mvs_privacy', 'public' );
		update_post_meta( $this->media_id, '_mvs_file_path', '2026/03/test.jpg' );
		update_post_meta( $this->media_id, '_mvs_file_url', 'http://example.com/wp-content/uploads/wpmediaverse/2026/03/test.jpg' );
		update_post_meta( $this->media_id, '_mvs_file_type', 'image/jpeg' );
	}

	protected function tearDown(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_access_rules WHERE media_id = {$this->media_id}" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_access_grants WHERE media_id = {$this->media_id}" );
		// phpcs:enable

		wp_delete_post( $this->media_id, true );
		delete_option( 'mvs_signed_url_secret' );

		parent::tearDown();
	}

	/**
	 * Test: generate returns a signed URL string for authorized user.
	 */
	public function test_generate_returns_url_for_authorized_user(): void {
		// Author should have access to own public media.
		$url = $this->service->generate( $this->media_id, $this->author_id );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'mvs_sig=', $url );
		$this->assertStringContainsString( 'mvs_exp=', $url );
		$this->assertStringContainsString( 'mvs_id=', $url );
	}

	/**
	 * Test: generate returns false for unauthorized user on private media.
	 */
	public function test_generate_returns_false_for_unauthorized_user(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'private' );

		// User 999 is not the owner and doesn't have moderate cap.
		$url = $this->service->generate( $this->media_id, 999 );

		$this->assertFalse( $url );
	}

	/**
	 * Test: validate returns media_id for valid signed URL params.
	 */
	public function test_validate_valid_signature(): void {
		$url = $this->service->generate( $this->media_id, $this->author_id, 3600 );

		// Parse the URL to extract query params.
		$parts  = wp_parse_url( $url );
		$params = array();
		parse_str( $parts['query'], $params );

		$result = $this->service->validate( $params );

		$this->assertEquals( $this->media_id, $result );
	}

	/**
	 * Test: validate returns false for tampered signature.
	 */
	public function test_validate_tampered_signature(): void {
		$url = $this->service->generate( $this->media_id, $this->author_id, 3600 );

		$parts  = wp_parse_url( $url );
		$params = array();
		parse_str( $parts['query'], $params );

		// Tamper with the signature.
		$params[ SignedUrlService::PARAM_SIGNATURE ] = 'tampered_signature_value';

		$result = $this->service->validate( $params );

		$this->assertFalse( $result );
	}

	/**
	 * Test: validate returns false for expired URL.
	 */
	public function test_validate_expired_url(): void {
		// Generate with a TTL of 1 second.
		$url = $this->service->generate( $this->media_id, $this->author_id, 1 );

		$parts  = wp_parse_url( $url );
		$params = array();
		parse_str( $parts['query'], $params );

		// Force expiration by manipulating the expires param and re-signing won't work.
		// Instead, set expires to past time.
		$params[ SignedUrlService::PARAM_EXPIRES ] = time() - 100;

		$result = $this->service->validate( $params );

		$this->assertFalse( $result );
	}

	/**
	 * Test: validate returns false with missing parameters.
	 */
	public function test_validate_missing_params(): void {
		$result = $this->service->validate( array( 'mvs_id' => 1 ) );

		$this->assertFalse( $result );
	}

	/**
	 * Test: generate includes download parameter.
	 */
	public function test_generate_with_download_flag(): void {
		$url = $this->service->generate( $this->media_id, $this->author_id, 3600, true );

		$this->assertStringContainsString( 'mvs_dl=1', $url );
	}

	/**
	 * Test: validate preserves download parameter in signature.
	 */
	public function test_validate_with_download_flag(): void {
		$url = $this->service->generate( $this->media_id, $this->author_id, 3600, true );

		$parts  = wp_parse_url( $url );
		$params = array();
		parse_str( $parts['query'], $params );

		$result = $this->service->validate( $params );

		$this->assertEquals( $this->media_id, $result );
	}

	/**
	 * Test: requires_signed_url returns true when media has access rules.
	 */
	public function test_requires_signed_url_with_rules(): void {
		$this->assertFalse( $this->service->requires_signed_url( $this->media_id ) );

		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 4.99, 'USD' );

		$this->assertTrue( $this->service->requires_signed_url( $this->media_id ) );
	}

	/**
	 * Test: requires_signed_url returns false when no rules.
	 */
	public function test_requires_signed_url_without_rules(): void {
		$this->assertFalse( $this->service->requires_signed_url( $this->media_id ) );
	}

	/**
	 * Test: signed URL uses REST serve endpoint.
	 */
	public function test_signed_url_uses_serve_endpoint(): void {
		$url = $this->service->generate( $this->media_id, $this->author_id );

		$this->assertStringContainsString( 'mvs/v1/serve', $url );
	}

	/**
	 * Test: validate rejects download-tampered URL.
	 * If URL was generated without download flag, adding it should invalidate signature.
	 */
	public function test_validate_rejects_added_download_flag(): void {
		$url = $this->service->generate( $this->media_id, $this->author_id, 3600, false );

		$parts  = wp_parse_url( $url );
		$params = array();
		parse_str( $parts['query'], $params );

		// Tamper by adding download flag.
		$params[ SignedUrlService::PARAM_DOWNLOAD ] = 1;

		$result = $this->service->validate( $params );

		$this->assertFalse( $result );
	}

	/**
	 * Test: secret key is auto-generated and persisted.
	 */
	public function test_secret_key_auto_generated(): void {
		delete_option( 'mvs_signed_url_secret' );

		// Generate a URL which triggers secret creation.
		$this->service->generate( $this->media_id, $this->author_id );

		$secret = get_option( 'mvs_signed_url_secret' );
		$this->assertNotEmpty( $secret );
		$this->assertGreaterThanOrEqual( 64, strlen( $secret ) );
	}
}
