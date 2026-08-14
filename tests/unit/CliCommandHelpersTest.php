<?php
/**
 * The CLI command class's own helpers.
 *
 * WRITTEN AFTER SHIPPING AN INFINITE RECURSION. The Rule 7 migration added a
 * `repo()` helper to `CLI\Commands` and then ran a global find-and-replace to
 * route every inline container lookup through it — which rewrote the helper's
 * OWN body, leaving `repo()` returning `$this->repo()`. Every WP-CLI command
 * that touches media broke: reindex, migrate-storage, optimize-bulk,
 * backfill_ai, moderation-stats, all of them.
 *
 * Nothing caught it. It is valid PHP, so lint passed; PHPStan does not flag
 * self-recursion; WPCS has no opinion; and NOT ONE TEST IN EITHER PLUGIN EVER
 * INSTANTIATED THIS CLASS, so 420 green tests said nothing about a file that is
 * the entire maintenance surface of the product.
 *
 * These tests are cheap and they close exactly that hole: the helpers are pure
 * or near-pure, so they can be exercised without WP_CLI being defined.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use ReflectionMethod;
use WP_UnitTestCase;
use WPMediaVerse\CLI\Commands;
use WPMediaVerse\Core\MediaTypes;

class CliCommandHelpersTest extends WP_UnitTestCase {

	/**
	 * Call a private method on a fresh Commands instance.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call( string $method, array $args = array() ) {
		$ref = new ReflectionMethod( Commands::class, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( new Commands(), $args );
	}

	/**
	 * `repo()` returns the repository rather than calling itself.
	 *
	 * THE REGRESSION TEST FOR THE SHIPPED BUG. Note what failure looks like: an
	 * infinite recursion has no exception to catch, so this does not fail
	 * politely — PHP exhausts the stack and the process dies. That is still a
	 * red CI run and still infinitely better than the alternative, which is what
	 * actually happened: the break reached a push, and was found only because a
	 * command was run by hand afterwards.
	 */
	public function test_repo_returns_the_repository_and_does_not_recurse(): void {
		$repo = $this->call( 'repo' );

		$this->assertInstanceOf( \WPMediaVerse\Repository\MediaRepository::class, $repo );
		$this->assertSame(
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' ),
			$repo,
			'The helper must hand back the container instance, not build its own.'
		);
	}

	/**
	 * The storage walk covers every stored type, including legacy_document.
	 *
	 * The second bug this pass nearly shipped, pinned at the place that decides
	 * it. `MediaTypes::ALL` omits `legacy_document`, so using it here would make
	 * `relocalize-private` skip a private legacy file sitting on a public bucket
	 * — and report success.
	 */
	public function test_the_storage_walk_applies_no_type_predicate(): void {
		$args = $this->call( 'storage_walk_args' );

		$this->assertArrayHasKey( 'media_types', $args );
		$this->assertNull(
			$args['media_types'],
			'A storage walk must not filter by type — storage is a property of the file. MediaTypes::ALL is NOT a safe way to say "everything"; it omits legacy_document.'
		);

		$this->assertNotSame( MediaTypes::ALL, $args['media_types'] );
	}

	/**
	 * It walks drafts as well as published rows.
	 *
	 * `status_in` has to replace the `publish` default rather than join it. If
	 * it ever ANDs instead, every draft silently drops out of every storage
	 * command and each one still reports success.
	 */
	public function test_the_storage_walk_covers_drafts(): void {
		$args = $this->call( 'storage_walk_args' );

		$this->assertSame( array( 'publish', 'draft' ), $args['status_in'] );
		$this->assertSame( '', $args['status'], 'The single-status default must be cleared, or it narrows the set back to publish.' );
	}

	/**
	 * And only rows that actually have a file.
	 */
	public function test_the_storage_walk_requires_a_file(): void {
		$this->assertTrue( $this->call( 'storage_walk_args' )['has_file'] );
	}

	/**
	 * A single media id narrows the walk to that row.
	 */
	public function test_a_single_media_id_narrows_the_walk(): void {
		$args = $this->call( 'storage_walk_args', array( array( 'media_id' => 4242 ) ) );

		$this->assertSame( array( 4242 ), $args['media_ids'] );
	}

	/**
	 * No id means no id filter — not a filter matching nothing.
	 *
	 * `media_ids => array()` is "no restriction" on the query builder, so the
	 * helper must omit the key rather than pass an empty array with intent.
	 */
	public function test_no_media_id_means_no_id_filter(): void {
		$args = $this->call( 'storage_walk_args' );

		$this->assertArrayNotHasKey( 'media_ids', $args );
	}

	/**
	 * Absent `--limit` means every matching row, as these commands have always behaved.
	 */
	public function test_no_limit_means_unbounded(): void {
		$this->assertSame( PHP_INT_MAX, $this->call( 'storage_walk_args' )['limit'] );
		$this->assertSame( 25, $this->call( 'storage_walk_args', array( array( 'limit' => 25 ) ) )['limit'] );
	}

	/**
	 * Callers can override any default — that is what the argument is for.
	 */
	public function test_callers_can_override_the_defaults(): void {
		$args = $this->call(
			'storage_walk_args',
			array(
				array(
					'status_in'  => array(),
					'privacy_in' => array( 'public' ),
				),
			)
		);

		$this->assertSame( array(), $args['status_in'], 'relocalize-private clears the status set deliberately.' );
		$this->assertSame( array( 'public' ), $args['privacy_in'] );
	}
}
