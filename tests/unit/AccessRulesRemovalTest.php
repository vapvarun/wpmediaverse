<?php
/**
 * 2.6.0 removes per-media access rules (Migrator v38).
 *
 * A rule could lock an item that was otherwise public. Dropping the rules
 * table alone would have published it, so the migration first sets every
 * rule-locked item to private, then drops the table.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;
use WPMediaVerse\Core\Plugin;

class AccessRulesRemovalTest extends WP_UnitTestCase {

	public function test_rule_locked_items_become_private_and_the_table_goes(): void {
		global $wpdb;
		( new Migrator() )->run();
		$repo  = Plugin::container()->get( 'media_repository' );
		$owner = self::factory()->user->create();
		$make  = static function ( string $privacy ) use ( $repo, $owner ): int {
			return (int) $repo->insert(
				array(
					'title'       => 'Locked probe',
					'post_author' => $owner,
					'media_type'  => 'image',
					'status'      => 'publish',
					'privacy'     => $privacy,
					'slug'        => 'locked-' . wp_generate_password( 8, false, false ),
				)
			);
		};
		$locked_public  = $make( 'public' );
		$locked_private = $make( 'private' );
		$free           = $make( 'public' );

		// The table as 2.5.x created it.
		$rules = $wpdb->prefix . 'mvs_access_rules';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$rules} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, media_id bigint(20) unsigned NOT NULL, rule_type varchar(50) NOT NULL, rule_value text NOT NULL, price decimal(10,2) DEFAULT NULL, currency varchar(3) DEFAULT NULL, created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id) )" ); // phpcs:ignore
		$wpdb->insert( $rules, array( 'media_id' => $locked_public, 'rule_type' => 'role', 'rule_value' => 'subscriber' ) ); // phpcs:ignore
		$wpdb->insert( $rules, array( 'media_id' => $locked_private, 'rule_type' => 'code', 'rule_value' => 'x' ) ); // phpcs:ignore

		update_option( Migrator::VERSION_OPTION, 37 );
		( new Migrator() )->run();
		\WPMediaVerse\Repository\MediaRepository::reset_test_cache();

		$this->assertSame( 'private', $repo->get( $locked_public, 'privacy' ), 'A rule-locked public item was published when its rule went.' );
		$this->assertSame( 'private', $repo->get( $locked_private, 'privacy' ) );
		$this->assertSame( 'public', $repo->get( $free, 'privacy' ), 'An item with no rule changed privacy.' );
		$suppressed = $wpdb->suppress_errors();
		$left       = $wpdb->get_var( "SELECT COUNT(*) FROM {$rules}" ); // phpcs:ignore
		$wpdb->suppress_errors( $suppressed );
		$this->assertNull( $left, 'The rules table was not dropped.' );
		$this->assertFalse( Plugin::container()->has( 'access_rules' ) );
	}

	public function test_retired_shortcode_renders_nothing(): void {
		$this->assertSame( '', do_shortcode( '[mvs_lock_overlay id="1"]' ) );
	}
}
