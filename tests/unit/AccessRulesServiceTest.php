<?php
/**
 * Test AccessRulesService rule lifecycle + rule-builder options.
 *
 * Regression coverage for the 1.9.0 access-rules UI: rules created through the
 * new frontend/admin surfaces flow through this service, and the watermark
 * feature is gated on has_active_rules(). These tests lock the contract those
 * UIs depend on.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\AccessRulesService;

class AccessRulesServiceTest extends WP_UnitTestCase {

	private AccessRulesService $service;
	private int $media_id;

	public function set_up(): void {
		parent::set_up();

		$this->service  = new AccessRulesService();
		$this->media_id = 123456; // Arbitrary — the service keys purely on media_id.
	}

	/**
	 * Adding a rule persists it and flips has_active_rules() to true.
	 *
	 * This is the exact gate the watermark feature reads — before 1.9.0 no UI
	 * populated the table, so it was always false and the watermark never fired.
	 */
	public function test_add_rule_activates_has_active_rules(): void {
		$this->assertFalse( $this->service->has_active_rules( $this->media_id ) );

		$rule_id = $this->service->add_rule( $this->media_id, 'role', 'subscriber' );

		$this->assertIsInt( $rule_id );
		$this->assertGreaterThan( 0, $rule_id );
		$this->assertTrue( $this->service->has_active_rules( $this->media_id ) );
	}

	/**
	 * add_rule() rejects rule types outside RULE_TYPES.
	 */
	public function test_add_rule_rejects_unknown_type(): void {
		$this->assertFalse( $this->service->add_rule( $this->media_id, 'not_a_type', 'x' ) );
		$this->assertFalse( $this->service->has_active_rules( $this->media_id ) );
	}

	/**
	 * set_rules() replaces the whole set (the frontend modal's save semantics).
	 */
	public function test_set_rules_replaces_all(): void {
		$this->service->add_rule( $this->media_id, 'role', 'editor' );

		$count = $this->service->set_rules(
			$this->media_id,
			array(
				array(
					'rule_type'  => 'role',
					'rule_value' => 'subscriber',
				),
				array(
					'rule_type'  => 'capability',
					'rule_value' => 'read',
				),
			)
		);

		$this->assertSame( 2, $count );

		$rules  = $this->service->get_rules( $this->media_id );
		$values = wp_list_pluck( $rules, 'rule_value' );

		$this->assertCount( 2, $rules );
		$this->assertContains( 'subscriber', $values );
		$this->assertContains( 'read', $values );
		$this->assertNotContains( 'editor', $values ); // old rule replaced.
	}

	/**
	 * set_rules() with an empty array clears every rule.
	 */
	public function test_set_rules_empty_clears(): void {
		$this->service->add_rule( $this->media_id, 'role', 'subscriber' );
		$this->assertTrue( $this->service->has_active_rules( $this->media_id ) );

		$this->service->set_rules( $this->media_id, array() );

		$this->assertFalse( $this->service->has_active_rules( $this->media_id ) );
		$this->assertSame( array(), $this->service->get_rules( $this->media_id ) );
	}

	/**
	 * delete_rule() removes a single rule and clears the presence cache.
	 */
	public function test_delete_rule_removes_it(): void {
		$rule_id = $this->service->add_rule( $this->media_id, 'role', 'subscriber' );

		$this->assertTrue( $this->service->delete_rule( (int) $rule_id ) );
		$this->assertFalse( $this->service->has_active_rules( $this->media_id ) );
	}

	/**
	 * get_builder_options() returns the bounded set the rule-builder UIs read:
	 * member-facing rule types plus the site's editable roles.
	 */
	public function test_get_builder_options_shape(): void {
		$options = $this->service->get_builder_options();

		$this->assertArrayHasKey( 'rule_types', $options );
		$this->assertArrayHasKey( 'roles', $options );
		$this->assertArrayHasKey( 'bp_groups_active', $options );

		$type_values = wp_list_pluck( $options['rule_types'], 'value' );
		$this->assertContains( 'role', $type_values );
		$this->assertContains( 'capability', $type_values );

		// Every rule value in the site's roles must be a real editable role slug.
		$role_values = wp_list_pluck( $options['roles'], 'value' );
		$this->assertContains( 'subscriber', $role_values );
		$this->assertContains( 'administrator', $role_values );

		// Without BuddyPress groups, "membership" is not offered.
		$this->assertFalse( $options['bp_groups_active'] );
		$this->assertNotContains( 'membership', $type_values );
	}

	/**
	 * The mvs_access_rule_types_ui filter lets Pro add rule types.
	 */
	public function test_rule_types_filterable(): void {
		$cb = static function ( $types ) {
			$types[] = array(
				'value' => 'code',
				'label' => 'Access code',
			);
			return $types;
		};
		add_filter( 'mvs_access_rule_types_ui', $cb );

		$options     = $this->service->get_builder_options();
		$type_values = wp_list_pluck( $options['rule_types'], 'value' );

		remove_filter( 'mvs_access_rule_types_ui', $cb );

		$this->assertContains( 'code', $type_values );
	}
}
