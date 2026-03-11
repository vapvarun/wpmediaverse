<?php
/**
 * Tests for AccessRulesService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Services\AccessRulesService;

class AccessRulesServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Service under test.
	 *
	 * @var AccessRulesService
	 */
	private $service;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new AccessRulesService();
		$this->user_id = 1;

		// Create a test media post.
		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Access Rules Test Media',
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);
	}

	protected function tearDown(): void {
		global $wpdb;

		// Clean up rules and grants.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rules_table  = $wpdb->prefix . 'mvs_access_rules';
		$grants_table = $wpdb->prefix . 'mvs_access_grants';
		$wpdb->query( "DELETE FROM {$rules_table} WHERE media_id = {$this->media_id}" );
		$wpdb->query( "DELETE FROM {$grants_table} WHERE media_id = {$this->media_id}" );
		// phpcs:enable

		wp_delete_post( $this->media_id, true );

		parent::tearDown();
	}

	/**
	 * Test: add_rule returns a valid ID.
	 */
	public function test_add_rule_returns_valid_id(): void {
		$rule_id = $this->service->add_rule( $this->media_id, 'role', 'subscriber' );

		$this->assertIsInt( $rule_id );
		$this->assertGreaterThan( 0, $rule_id );
	}

	/**
	 * Test: add_rule with invalid type returns false.
	 */
	public function test_add_rule_invalid_type_returns_false(): void {
		$result = $this->service->add_rule( $this->media_id, 'invalid_type', 'value' );

		$this->assertFalse( $result );
	}

	/**
	 * Test: get_rules returns all rules for media.
	 */
	public function test_get_rules_returns_all_rules(): void {
		$this->service->add_rule( $this->media_id, 'role', 'subscriber' );
		$this->service->add_rule( $this->media_id, 'capability', 'edit_posts' );

		$rules = $this->service->get_rules( $this->media_id );

		$this->assertCount( 2, $rules );
	}

	/**
	 * Test: get_rules filtered by rule_type.
	 */
	public function test_get_rules_filtered_by_type(): void {
		$this->service->add_rule( $this->media_id, 'role', 'subscriber' );
		$this->service->add_rule( $this->media_id, 'capability', 'edit_posts' );

		$rules = $this->service->get_rules( $this->media_id, 'capability' );

		$this->assertCount( 1, $rules );
		$this->assertEquals( 'capability', $rules[0]['rule_type'] );
	}

	/**
	 * Test: delete_rule removes a rule.
	 */
	public function test_delete_rule_removes_rule(): void {
		$rule_id = $this->service->add_rule( $this->media_id, 'role', 'editor' );

		$deleted = $this->service->delete_rule( $rule_id );

		$this->assertTrue( $deleted );
		$this->assertCount( 0, $this->service->get_rules( $this->media_id ) );
	}

	/**
	 * Test: set_rules replaces all existing rules.
	 */
	public function test_set_rules_replaces_all(): void {
		$this->service->add_rule( $this->media_id, 'role', 'subscriber' );
		$this->service->add_rule( $this->media_id, 'role', 'author' );

		$count = $this->service->set_rules(
			$this->media_id,
			array(
				array(
					'rule_type'  => 'capability',
					'rule_value' => 'edit_posts',
				),
			)
		);

		$this->assertEquals( 1, $count );
		$rules = $this->service->get_rules( $this->media_id );
		$this->assertCount( 1, $rules );
		$this->assertEquals( 'capability', $rules[0]['rule_type'] );
	}

	/**
	 * Test: has_active_rules returns correct boolean.
	 */
	public function test_has_active_rules(): void {
		$this->assertFalse( $this->service->has_active_rules( $this->media_id ) );

		$this->service->add_rule( $this->media_id, 'role', 'subscriber' );

		$this->assertTrue( $this->service->has_active_rules( $this->media_id ) );
	}

	/**
	 * Test: grant_access returns a grant ID.
	 */
	public function test_grant_access_returns_grant_id(): void {
		$grant_id = $this->service->grant_access( $this->media_id, 2, 'manual' );

		$this->assertIsInt( $grant_id );
		$this->assertGreaterThan( 0, $grant_id );
	}

	/**
	 * Test: has_access returns true after grant.
	 */
	public function test_has_access_after_grant(): void {
		$this->assertFalse( $this->service->has_access( $this->media_id, 2 ) );

		$this->service->grant_access( $this->media_id, 2, 'manual' );

		$this->assertTrue( $this->service->has_access( $this->media_id, 2 ) );
	}

	/**
	 * Test: revoke_access makes has_access return false.
	 */
	public function test_revoke_access(): void {
		$this->service->grant_access( $this->media_id, 2, 'manual' );
		$this->assertTrue( $this->service->has_access( $this->media_id, 2 ) );

		$revoked = $this->service->revoke_access( $this->media_id, 2 );

		$this->assertTrue( $revoked );
		$this->assertFalse( $this->service->has_access( $this->media_id, 2 ) );
	}

	/**
	 * Test: expired grant means no access.
	 */
	public function test_grant_with_expiration_expired(): void {
		$past = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
		$this->service->grant_access( $this->media_id, 2, 'code', $past );

		$this->assertFalse( $this->service->has_access( $this->media_id, 2 ) );
	}

	/**
	 * Test: get_user_grants returns paginated list.
	 */
	public function test_get_user_grants_paginated(): void {
		// Create a second media item for variety.
		$media_id_2 = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Access Test Media 2',
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);

		$this->service->grant_access( $this->media_id, 2, 'manual' );
		$this->service->grant_access( $media_id_2, 2, 'code' );

		$result = $this->service->get_user_grants( 2, 10, 1, true );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertEquals( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );

		// Clean up second media.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_access_grants WHERE media_id = {$media_id_2}" );
		wp_delete_post( $media_id_2, true );
	}

	/**
	 * Test: cleanup_expired sets revoked_at on expired grants.
	 */
	public function test_cleanup_expired(): void {
		$past = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
		$this->service->grant_access( $this->media_id, 2, 'code', $past );

		$cleaned = $this->service->cleanup_expired();

		$this->assertGreaterThanOrEqual( 1, $cleaned );
	}

	/**
	 * Test: filter_privacy_can_view returns null when no rules exist.
	 */
	public function test_filter_privacy_no_rules_returns_null(): void {
		$result = $this->service->filter_privacy_can_view( null, $this->media_id, 2, 'public' );

		$this->assertNull( $result );
	}

	/**
	 * Test: filter_privacy_can_view returns true when user has grant.
	 */
	public function test_filter_privacy_with_grant_returns_true(): void {
		$this->service->add_rule( $this->media_id, 'code', 'UNLOCK2024' );
		$this->service->grant_access( $this->media_id, 2, 'code' );

		$result = $this->service->filter_privacy_can_view( null, $this->media_id, 2, 'public' );

		$this->assertTrue( $result );
	}

	/**
	 * Test: filter_privacy_can_view returns false when rules exist but no grant.
	 */
	public function test_filter_privacy_rules_no_grant_returns_false(): void {
		$this->service->add_rule( $this->media_id, 'code', 'UNLOCK2024' );

		$result = $this->service->filter_privacy_can_view( null, $this->media_id, 2, 'public' );

		$this->assertFalse( $result );
	}
}
