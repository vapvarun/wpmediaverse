<?php
/**
 * The number above a listing counts the rows the listing shows.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10297845497.
 *
 * The count mirrored query_by_author()'s PRIVACY rule and nothing else, so a
 * caller that listed with a moderation filter counted without one and the
 * header over-reported. These assert the two agree
 * whenever they are handed the same arguments - the property, not one
 * example of it.
 */
class ProfileCountMatchesListTest extends WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function repo() {
		return Plugin::container()->get( 'media_repository' );
	}

	/**
	 * Seed one row, and PROVE it landed.
	 *
	 * The slug column is UNIQUE. Without one, every insert after the first fails with
	 * "Duplicate entry ''", and a count-equals-count assertion then passes on
	 * a fixture of one row - green, and testing nothing. Asserting the insert
	 * is what stops that being invisible.
	 */
	private function seed( string $moderation, string $privacy = 'public', string $type = 'image' ): void {
		global $wpdb;
		$unique = wp_generate_password( 8, false );

		$ok = $wpdb->insert(
			$wpdb->prefix . 'mvs_media_index',
			array(
				'post_author'       => $this->owner,
				'title'             => 'Fixture ' . $unique,
				'slug'              => 'fixture-' . strtolower( $unique ),
				'media_type'        => $type,
				'file_url'          => 'https://example.test/' . $unique . '.jpg',
				'privacy'           => $privacy,
				'status'            => 'publish',
				'moderation_status' => $moderation,
				'created_at'        => current_time( 'mysql' ),
			)
		);

		$this->assertNotFalse( $ok, 'fixture row did not insert: ' . $wpdb->last_error );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function assertCountMatchesList( array $args, string $message ): void {
		$listed = count(
			$this->repo()->query_by_author(
				$this->owner,
				array_merge(
					$args,
					array(
						'viewer_id' => $this->owner,
						'limit'     => 500,
					)
				)
			)
		);
		$counted = $this->repo()->count_visible_by_author( $this->owner, $this->owner, $args );

		$this->assertSame( $listed, $counted, $message );
	}

	public function test_count_matches_list_when_moderation_is_filtered(): void {
		$this->seed( 'approved' );
		$this->seed( 'approved' );
		$this->seed( 'pending' );

		$this->assertCountMatchesList(
			array( 'moderation_status' => 'approved' ),
			'the header counted a row held for moderation that the grid drops'
		);
		// Absolute, not just equal: two approved of three seeded.
		$this->assertSame(
			2,
			$this->repo()->count_visible_by_author(
				$this->owner,
				$this->owner,
				array( 'moderation_status' => 'approved' )
			)
		);
	}

	public function test_count_matches_list_when_moderation_is_not_filtered(): void {
		$this->seed( 'approved' );
		$this->seed( 'pending' );

		// The BuddyPress tab lists without a moderation filter. Its count must
		// not quietly apply one either - agreement is the rule, not 'approved'.
		$this->assertCountMatchesList(
			array(),
			'the count applied a filter the list did not'
		);
	}

	public function test_count_matches_list_when_scoped_to_documents(): void {
		$this->seed( 'approved', 'public', 'image' );
		$this->seed( 'approved', 'public', 'document' );
		$this->seed( 'approved', 'public', 'document' );

		$this->assertCountMatchesList(
			array( 'media_types' => array( 'document' ) ),
			'a documents listing and its count disagreed'
		);
		// Absolute: both documents, and NOT the image. query_by_author() used
		// to discard media_types entirely and hand back the image instead.
		$this->assertSame(
			2,
			$this->repo()->count_visible_by_author(
				$this->owner,
				$this->owner,
				array( 'media_types' => array( 'document' ) )
			)
		);
		$rows = $this->repo()->query_by_author(
			$this->owner,
			array(
				'media_types' => array( 'document' ),
				'viewer_id'   => $this->owner,
				'limit'       => 500,
			)
		);
		$this->assertSame(
			array( 'document', 'document' ),
			array_map( static fn( $r ) => $r['media_type'], $rows ),
			'the listing returned rows outside the requested library'
		);
	}

	public function test_omitting_args_counts_what_it_always_counted(): void {
		$this->seed( 'approved' );
		$this->seed( 'pending' );

		// Additive parameter: a caller that passes nothing must be unaffected.
		$this->assertSame(
			$this->repo()->count_visible_by_author( $this->owner, $this->owner ),
			$this->repo()->count_visible_by_author( $this->owner, $this->owner, array() ),
			'passing no args changed the count'
		);
	}
}
