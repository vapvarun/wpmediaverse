<?php
/**
 * Regression guards for media title validation on PATCH /mvs/v1/media/{id}.
 *
 * Found by the 2.3.1 free-mode pre-release smoke: the edit modal let the Title
 * field be cleared and saved, and the REST route accepted it — HTTP 200 with
 * `title` persisted as an empty string. A media item could be left with no name
 * at all, rendering as an untitled tile in every grid, search result and
 * activity row.
 *
 * Two layers now refuse it, and both are tested here because they catch
 * different inputs: the schema's `minLength` rejects `''`, while only the
 * controller's `trim()` catches whitespace-only (`'   '`, `"\t\n"`).
 *
 * The last test is the one that keeps the fix honest — omitting `title`
 * entirely must still be a valid partial update that leaves the title alone.
 * A guard that broke that would be worse than the bug.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;

class MediaTitleValidationTest extends WP_UnitTestCase {

	/** @var int */
	private int $author;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index_table ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->author );

		do_action( 'rest_api_init' );
	}

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	private function make_media( string $title = 'Original' ): int {
		return (int) $this->repo()->insert(
			array(
				'title'             => $title,
				'post_author'       => $this->author,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'file_path'         => '2026/08/probe.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'title-guard-' . wp_generate_password( 10, false, false ),
			)
		);
	}

	private function stored_title( int $id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", $id ) );
	}

	private function patch_title( int $id, $title ) {
		$req = new WP_REST_Request( 'POST', '/mvs/v1/media/' . $id );
		$req->set_param( 'id', $id );
		$req->set_param( 'title', $title );

		return rest_do_request( $req );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function empty_title_provider(): array {
		return array(
			'empty string'   => array( '' ),
			'single space'   => array( ' ' ),
			'several spaces' => array( '   ' ),
			'tab + newline'  => array( "\t\n" ),
		);
	}

	/**
	 * @dataProvider empty_title_provider
	 *
	 * @param string $title Title value under test.
	 */
	public function test_blank_title_is_rejected_and_nothing_is_written( string $title ): void {
		$id  = $this->make_media();
		$res = $this->patch_title( $id, $title );

		$this->assertSame( 400, $res->get_status(), 'A blank title must be refused, not accepted.' );
		$this->assertSame(
			'Original',
			$this->stored_title( $id ),
			'A refused request must leave the stored title untouched.'
		);
	}

	public function test_a_real_title_still_saves(): void {
		$id  = $this->make_media();
		$res = $this->patch_title( $id, 'Renamed OK' );

		$this->assertLessThan( 300, $res->get_status() );
		$this->assertSame( 'Renamed OK', $this->stored_title( $id ) );
	}

	/**
	 * Titles are trimmed, not rejected, when they merely carry surrounding
	 * whitespace around real content.
	 */
	public function test_surrounding_whitespace_is_kept_usable(): void {
		$id = $this->make_media();
		$this->patch_title( $id, '  Padded Title  ' );

		$this->assertNotSame( '', trim( $this->stored_title( $id ) ) );
		$this->assertStringContainsString( 'Padded Title', $this->stored_title( $id ) );
	}

	/**
	 * The guard must not turn PATCH into a full replace: omitting `title`
	 * is a normal partial update and must leave the existing title alone.
	 */
	public function test_omitting_title_leaves_it_unchanged(): void {
		$id = $this->make_media( 'Untouched' );

		$req = new WP_REST_Request( 'POST', '/mvs/v1/media/' . $id );
		$req->set_param( 'id', $id );
		$req->set_param( 'description', 'only the description changed' );
		$res = rest_do_request( $req );

		$this->assertLessThan( 300, $res->get_status() );
		$this->assertSame( 'Untouched', $this->stored_title( $id ) );
	}
}
