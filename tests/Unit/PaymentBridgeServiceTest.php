<?php
/**
 * Tests for PaymentBridgeService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Services\PaymentBridgeService;
use WPMediaVerse\Services\AccessRulesService;

class PaymentBridgeServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Payment bridge service under test.
	 *
	 * @var PaymentBridgeService
	 */
	private $service;

	/**
	 * Access rules service.
	 *
	 * @var AccessRulesService
	 */
	private $access_rules;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	/**
	 * Test user IDs.
	 *
	 * @var int
	 */
	private $author_id;
	private $buyer_id;

	protected function setUp(): void {
		parent::setUp();

		$this->access_rules = new AccessRulesService();
		$this->service      = new PaymentBridgeService( $this->access_rules );
		$this->service->init();

		$this->author_id = 1;
		$this->buyer_id  = 2;

		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Payment Test Media',
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);

		update_post_meta( $this->media_id, '_mvs_privacy', 'public' );
	}

	protected function tearDown(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_access_rules WHERE media_id = {$this->media_id}" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_access_grants WHERE media_id = {$this->media_id}" );
		// phpcs:enable

		wp_delete_post( $this->media_id, true );

		// Remove hooks to prevent accumulation across tests.
		remove_all_actions( 'mvs_payment_completed' );
		remove_all_actions( 'mvs_subscription_cancelled' );

		parent::tearDown();
	}

	/**
	 * Test: checkout returns already_granted when user has access.
	 */
	public function test_checkout_already_granted(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 4.99, 'USD' );
		$this->access_rules->grant_access( $this->media_id, $this->buyer_id, 'purchase' );

		$result = $this->service->create_checkout( $this->media_id, $this->buyer_id );

		$this->assertEquals( 'already_granted', $result['status'] );
		$this->assertTrue( $result['granted'] );
	}

	/**
	 * Test: checkout returns no_rules when no purchase rules exist.
	 */
	public function test_checkout_no_rules(): void {
		$result = $this->service->create_checkout( $this->media_id, $this->buyer_id );

		$this->assertEquals( 'no_rules', $result['status'] );
		$this->assertFalse( $result['granted'] );
	}

	/**
	 * Test: checkout grants access for free items (price = 0).
	 */
	public function test_checkout_free_item_grants_immediately(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'free', 0, 'USD' );

		$result = $this->service->create_checkout( $this->media_id, $this->buyer_id );

		$this->assertEquals( 'granted', $result['status'] );
		$this->assertTrue( $result['granted'] );
		$this->assertTrue( $this->access_rules->has_access( $this->media_id, $this->buyer_id ) );
	}

	/**
	 * Test: checkout returns pending when no payment provider handles it.
	 */
	public function test_checkout_pending_no_provider(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 9.99, 'USD' );

		$result = $this->service->create_checkout( $this->media_id, $this->buyer_id );

		$this->assertEquals( 'pending', $result['status'] );
		$this->assertFalse( $result['granted'] );
	}

	/**
	 * Test: checkout uses payment provider filter when available.
	 */
	public function test_checkout_uses_provider_filter(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 9.99, 'USD' );

		// Simulate a payment provider.
		add_filter(
			'mvs_checkout_process',
			function () {
				return array(
					'status'       => 'redirect',
					'redirect_url' => 'https://pay.example.com/checkout/123',
					'granted'      => false,
				);
			}
		);

		$result = $this->service->create_checkout( $this->media_id, $this->buyer_id );

		$this->assertEquals( 'redirect', $result['status'] );
		$this->assertEquals( 'https://pay.example.com/checkout/123', $result['redirect_url'] );

		// Clean up filter.
		remove_all_filters( 'mvs_checkout_process' );
	}

	/**
	 * Test: payment_completed hook grants access.
	 */
	public function test_payment_completed_grants_access(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 9.99, 'USD' );

		$this->assertFalse( $this->access_rules->has_access( $this->media_id, $this->buyer_id ) );

		// Simulate payment provider completing payment.
		do_action( 'mvs_payment_completed', $this->media_id, $this->buyer_id, 'purchase' );

		$this->assertTrue( $this->access_rules->has_access( $this->media_id, $this->buyer_id ) );
	}

	/**
	 * Test: subscription_cancelled hook revokes access.
	 */
	public function test_subscription_cancelled_revokes_access(): void {
		$this->access_rules->grant_access( $this->media_id, $this->buyer_id, 'subscription' );
		$this->assertTrue( $this->access_rules->has_access( $this->media_id, $this->buyer_id ) );

		do_action( 'mvs_subscription_cancelled', $this->media_id, $this->buyer_id );

		$this->assertFalse( $this->access_rules->has_access( $this->media_id, $this->buyer_id ) );
	}

	/**
	 * Test: redeem_code grants access for valid code.
	 */
	public function test_redeem_code_valid(): void {
		$this->access_rules->add_rule( $this->media_id, 'code', 'UNLOCK2026' );

		$result = $this->service->redeem_code( $this->media_id, $this->buyer_id, 'UNLOCK2026' );

		$this->assertTrue( $result );
		$this->assertTrue( $this->access_rules->has_access( $this->media_id, $this->buyer_id ) );
	}

	/**
	 * Test: redeem_code returns false for invalid code.
	 */
	public function test_redeem_code_invalid(): void {
		$this->access_rules->add_rule( $this->media_id, 'code', 'UNLOCK2026' );

		$result = $this->service->redeem_code( $this->media_id, $this->buyer_id, 'WRONGCODE' );

		$this->assertFalse( $result );
		$this->assertFalse( $this->access_rules->has_access( $this->media_id, $this->buyer_id ) );
	}

	/**
	 * Test: get_pricing returns price info for media with purchase rules.
	 */
	public function test_get_pricing_with_rules(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 4.99, 'USD' );

		$pricing = $this->service->get_pricing( $this->media_id );

		$this->assertEquals( 4.99, $pricing['price'] );
		$this->assertEquals( 'USD', $pricing['currency'] );
		$this->assertEquals( 'purchase', $pricing['rule_type'] );
	}

	/**
	 * Test: get_pricing returns nulls for media without purchase rules.
	 */
	public function test_get_pricing_without_rules(): void {
		$pricing = $this->service->get_pricing( $this->media_id );

		$this->assertNull( $pricing['price'] );
		$this->assertNull( $pricing['currency'] );
	}
}
