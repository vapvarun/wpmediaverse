<?php
/**
 * Direct media delivery (capability URLs), its fail-safes, and revocation.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\DirectDelivery;
use WPMediaVerse\Services\MediaFileRotator;

/**
 * @group direct-delivery
 */
class DirectDeliveryTest extends WP_UnitTestCase {

	/**
	 * Absolute path of uploads/wpmediaverse/ with trailing slash.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Fresh probe state and a clean folder for every test.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( DirectDelivery::OPTION );
		MediaFileRotator::flush(); // Drop anything other tests queued.
		$this->base = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpmediaverse/';
		wp_mkdir_p( $this->base . '2026/10' );
	}

	/**
	 * A media row whose files exist on disk under a random stem.
	 *
	 * @param string $privacy Privacy.
	 * @param string $stem    File stem.
	 * @param array  $extra   Extra index fields.
	 * @return int Media id.
	 */
	private function media( string $privacy, string $stem = '', array $extra = array() ): int {
		$stem = '' === $stem ? bin2hex( random_bytes( 8 ) ) : $stem;
		foreach ( array( '.jpg', '-300x200.jpg', '-300x200.webp' ) as $suffix ) {
			file_put_contents( $this->base . '2026/10/' . $stem . $suffix, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = (int) $repo->insert(
			array_merge(
				array(
					'title'             => 'Photo',
					'post_author'       => self::factory()->user->create(),
					'media_type'        => 'image',
					'status'            => 'publish',
					'moderation_status' => 'approved',
					'privacy'           => $privacy,
					'file_path'         => '2026/10/' . $stem . '.jpg',
					'file_type'         => 'image/jpeg',
				),
				$extra
			)
		);
		$repo->set( $id, 'thumb_medium_path', '2026/10/' . $stem . '-300x200.jpg' );
		$repo->set( $id, 'thumb_medium_webp_path', '2026/10/' . $stem . '-300x200.webp' );
		return $id;
	}

	/**
	 * Mark the probe as passed, as an admin page load would.
	 */
	private function probe_passed(): void {
		update_option(
			DirectDelivery::OPTION,
			array(
				'ok'         => true,
				'status'     => 200,
				'checked_at' => time(),
			)
		);
	}

	/**
	 * Only names MediaVerse generated qualify.
	 */
	public function test_random_name_check(): void {
		$this->assertTrue( DirectDelivery::is_random_name( '2026/10/909cc47421c40b99.jpg' ) );
		$this->assertTrue( DirectDelivery::is_random_name( 'posters/2026/10/909cc47421c40b99-1024x576.WEBP' ) );
		$this->assertFalse( DirectDelivery::is_random_name( 'posters/337.jpg' ), 'Pre-2.6.1 poster, guessable.' );
		$this->assertFalse( DirectDelivery::is_random_name( '2026/10/my-holiday.jpg' ), 'Readable name.' );
		$this->assertFalse( DirectDelivery::is_random_name( '2026/10/909cc47421c40b99.pdf' ), 'Documents stay signed.' );
		$this->assertFalse( DirectDelivery::is_random_name( '2026/10/909cc47421c40b99.svg' ), 'SVG stays signed.' );
		$this->assertFalse( DirectDelivery::is_random_name( '2026/10/909cc47421c40b99.php' ) );
	}

	/**
	 * Until a probe has passed, every URL stays on /serve: an upgraded site
	 * behaves exactly as before until its own server has proven otherwise.
	 */
	public function test_no_probe_means_signed_urls(): void {
		$id  = $this->media( 'public' );
		$url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' )->generate( $id, 0 );
		$this->assertStringContainsString( 'mvs_sig=', $url );
	}

	/**
	 * With the probe passed, a viewer who may see the item gets the file itself.
	 */
	public function test_direct_url_for_allowed_viewer(): void {
		$this->probe_passed();
		$signer = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );

		$public = $this->media( 'public' );
		$this->assertStringContainsString( '/uploads/wpmediaverse/2026/10/', (string) $signer->generate( $public, 0 ) );
		$this->assertStringContainsString( '-300x200.jpg', (string) $signer->generate_thumbnail( $public, 0, 'medium' ) );

		$members = $this->media( 'members' );
		$member  = self::factory()->user->create();
		$this->assertStringContainsString( '/uploads/wpmediaverse/', (string) $signer->generate( $members, $member ) );
		$this->assertFalse( $signer->generate( $members, 0 ), 'A guest gets no URL at all for members media.' );
	}

	/**
	 * A caller that skips the privacy check must not get a direct URL for a viewer
	 * who cannot see the item: /serve re-checks on fetch, a direct URL cannot.
	 */
	public function test_skip_privacy_check_never_mints_direct_for_disallowed_viewer(): void {
		$this->probe_passed();
		$id  = $this->media( 'members' );
		$url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' )->generate_thumbnail( $id, 0, 'medium', 0, true );
		$this->assertStringNotContainsString( '/uploads/wpmediaverse/', $url );
	}

	/**
	 * Message attachments, downloads, readable names and the filter stay signed.
	 */
	public function test_exclusions_stay_signed(): void {
		$this->probe_passed();
		$signer = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );

		$dm = $this->media( 'dm' );
		$this->assertStringNotContainsString( '/uploads/wpmediaverse/', (string) $signer->generate( $dm, (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $dm ) ) );

		$public = $this->media( 'public' );
		$this->assertStringContainsString( 'mvs_sig=', (string) $signer->generate( $public, 0, 0, true ), 'Downloads keep counting and Content-Disposition.' );

		$legacy = $this->media( 'public', '', array( 'file_path' => '2026/10/my-holiday.jpg' ) );
		$this->assertStringContainsString( 'mvs_sig=', (string) $signer->generate( $legacy, 0 ) );

		add_filter( 'mvs_direct_media_delivery', '__return_false' );
		$this->assertStringContainsString( 'mvs_sig=', (string) $signer->generate( $public, 0 ) );
		remove_filter( 'mvs_direct_media_delivery', '__return_false' );
	}

	/**
	 * The folder .htaccess: written when missing, upgraded from the old deny-all
	 * and from an older MediaVerse version of itself, never over an owner's file.
	 */
	public function test_htaccess_upgrade_rules(): void {
		$dir  = trailingslashit( get_temp_dir() ) . 'mvs-ht-' . wp_generate_password( 6, false ) . '/';
		$file = $dir . '.htaccess';
		wp_mkdir_p( $dir );

		DirectDelivery::ensure_htaccess( $dir );
		$this->assertSame( DirectDelivery::htaccess(), file_get_contents( $file ), 'Written when missing.' );

		file_put_contents( $file, DirectDelivery::LEGACY_HTACCESS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		DirectDelivery::ensure_htaccess( $dir );
		$this->assertSame( DirectDelivery::htaccess(), file_get_contents( $file ), 'Old deny-all upgraded.' );

		file_put_contents( $file, "# WPMediaVerse: an older version\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		DirectDelivery::ensure_htaccess( $dir );
		$this->assertSame( DirectDelivery::htaccess(), file_get_contents( $file ), 'Own older version refreshed.' );

		$custom = "# my host rules\nOrder deny,allow\nDeny from all\n";
		file_put_contents( $file, $custom ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		DirectDelivery::ensure_htaccess( $dir );
		$this->assertSame( $custom, file_get_contents( $file ), 'An owner-edited file is never touched.' );
	}

	/**
	 * Tightening privacy renames every file of the item and its stored paths, so
	 * earlier direct URLs stop working; widening leaves the names alone.
	 */
	public function test_tighten_rotates_widen_does_not(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$stem = bin2hex( random_bytes( 8 ) );
		$id   = $this->media( 'public', $stem );

		$repo->set( $id, 'privacy', 'private' );
		MediaFileRotator::flush(); // Runs on shutdown in a real request.

		$paths = $repo->get_stored_file_paths( $id );
		$this->assertCount( 3, $paths );
		foreach ( $paths as $path ) {
			$this->assertStringNotContainsString( $stem, $path );
			$this->assertFileExists( $this->base . $path );
		}
		$this->assertFileDoesNotExist( $this->base . '2026/10/' . $stem . '.jpg', 'The old address is gone.' );
		$this->assertSame( array(), glob( $this->base . '2026/10/' . $stem . '*' ), 'No sibling left behind.' );

		$before = $repo->get_stored_file_paths( $id );
		$repo->set( $id, 'privacy', 'public' );
		MediaFileRotator::flush();
		$this->assertSame( $before, $repo->get_stored_file_paths( $id ), 'Widening keeps the names.' );
	}

	/**
	 * An approved item that is flagged or rejected is revoked; an item that was
	 * never approved was never shown, so it is not renamed.
	 */
	public function test_moderation_rotation(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		$approved = $this->media( 'public' );
		$before   = $repo->get_stored_file_paths( $approved );
		MediaFileRotator::on_moderation_changed( $approved, 'flagged', 'approved' );
		MediaFileRotator::flush();
		$this->assertNotSame( $before, $repo->get_stored_file_paths( $approved ) );

		$pending = $this->media( 'public' );
		$before  = $repo->get_stored_file_paths( $pending );
		MediaFileRotator::on_moderation_changed( $pending, 'rejected', 'pending' );
		MediaFileRotator::flush();
		$this->assertSame( $before, $repo->get_stored_file_paths( $pending ) );
	}

	/**
	 * New posters are dated and random, never the flat, guessable posters/<id>.
	 */
	public function test_new_posters_are_dated_and_random(): void {
		$path = ( new \WPMediaVerse\Services\PosterService() )->stage_bytes(
			42,
			array(
				'data' => 'x',
				'mime' => 'image/jpeg',
			)
		);
		$this->assertMatchesRegularExpression( '~/wpmediaverse/posters/\d{4}/\d{2}/[0-9a-f]{16}\.jpg$~', (string) $path );
	}

	/**
	 * Imported files get a random name; the original name is kept for downloads.
	 */
	public function test_imports_get_random_names(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = (int) $repo->insert(
			array(
				'title'       => 'Imported',
				'post_author' => self::factory()->user->create(),
				'media_type'  => 'image',
				'status'      => 'publish',
				'privacy'     => 'public',
			)
		);
		$src = trailingslashit( get_temp_dir() ) . 'Family Holiday.jpg';
		copy( DIR_TESTDATA . '/images/canola.jpg', $src );

		$rel = \WPMediaVerse\Core\Plugin::container()->get( 'upload' )->sideload_external_file( $id, $src, 'image', 'image/jpeg' );

		$this->assertTrue( DirectDelivery::is_random_name( $rel ), $rel );
		$this->assertStringStartsWith( 'imported/', $rel );
		$this->assertSame( 'Family-Holiday.jpg', (string) $repo->get_raw( $id, 'original_filename' ) );
	}

	/**
	 * A big batch (an album made private) leaves the request at once and is
	 * rotated in background chunks.
	 */
	public function test_large_batch_goes_to_background(): void {
		for ( $i = 1; $i <= 25; $i++ ) {
			MediaFileRotator::queue( 900000 + $i );
		}
		MediaFileRotator::flush();
		$queued = function_exists( 'as_get_scheduled_actions' )
			? count( as_get_scheduled_actions( array( 'hook' => MediaFileRotator::HOOK, 'status' => 'pending' ), 'ids' ) )
			: count( array_filter( (array) _get_cron_array(), static fn( $e ) => isset( $e[ MediaFileRotator::HOOK ] ) ) );
		$this->assertSame( 1, $queued, 'One background chunk for 25 items.' );
	}

	/**
	 * Create a file under uploads/wpmediaverse/.
	 *
	 * @param string $rel Relative path.
	 */
	private function put_file( string $rel ): void {
		wp_mkdir_p( dirname( $this->base . $rel ) );
		file_put_contents( $this->base . $rel, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Replacing a whole path never touches a longer path that contains it.
	 */
	public function test_replace_file_paths_is_exact(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = $this->media( 'public' );
		$repo->set( $id, 'a_path', 'photo.jpg' );
		$repo->set( $id, 'b_path', '2026/10/my-photo.jpg' );
		$repo->set( $id, 'b_url', 'http://example.org/wp-content/uploads/wpmediaverse/2026/10/my-photo.jpg' );
		$repo->set( $id, 'a_url', 'http://example.org/wp-content/uploads/wpmediaverse/photo.jpg' );

		$repo->replace_file_paths( $id, array( 'photo.jpg' => '2026/10/abcdef0123456789.jpg' ) );

		$this->assertSame( '2026/10/abcdef0123456789.jpg', $repo->get_raw( $id, 'a_path' ) );
		$this->assertSame( 'http://example.org/wp-content/uploads/wpmediaverse/2026/10/abcdef0123456789.jpg', $repo->get_raw( $id, 'a_url' ) );
		$this->assertSame( '2026/10/my-photo.jpg', $repo->get_raw( $id, 'b_path' ), 'A path that merely ends the same way is untouched.' );
		$this->assertSame( 'http://example.org/wp-content/uploads/wpmediaverse/2026/10/my-photo.jpg', $repo->get_raw( $id, 'b_url' ) );
	}

	/**
	 * A pre-2.6.1 cover (posters/<id>*) moves into posters/YYYY/MM/ under a
	 * random name, source image included; another id's cover is not touched.
	 */
	public function test_legacy_poster_is_converted(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = $this->media( 'public' );
		foreach ( array( '', '-1024x576', '-300x169' ) as $size ) {
			$this->put_file( "posters/{$id}{$size}.jpg" );
		}
		$this->put_file( "posters/{$id}0.jpg" ); // Media {$id}0's cover: must stay.
		$repo->set( $id, 'thumb_large_path', "posters/{$id}-1024x576.jpg" );
		$repo->set( $id, 'thumb_large', "http://example.org/wp-content/uploads/wpmediaverse/posters/{$id}-1024x576.jpg" );
		$repo->set( $id, 'thumb_thumb_path', "posters/{$id}-300x169.jpg" );

		$this->assertTrue( \WPMediaVerse\Services\MediaFileRotator::convert_legacy( $id ) );

		$large = (string) $repo->get_raw( $id, 'thumb_large_path' );
		$this->assertMatchesRegularExpression( '~^posters/\d{4}/\d{2}/[0-9a-f]{16}-1024x576\.jpg$~', $large );
		$this->assertFileExists( $this->base . $large );
		$this->assertStringEndsWith( '/wpmediaverse/' . $large, (string) $repo->get_raw( $id, 'thumb_large' ) );
		$this->assertFileExists( $this->base . dirname( $large ) . '/' . strtok( basename( $large ), '-' ) . '.jpg', 'Cover source moved too.' );
		$this->assertSame( array(), glob( $this->base . "posters/{$id}{.jpg,-*}", GLOB_BRACE ), 'No old cover left.' );
		$this->assertFileExists( $this->base . "posters/{$id}0.jpg" );
		$source = (string) $repo->get_raw( $id, 'poster_source_path' );
		$this->assertMatchesRegularExpression( '~^posters/\d{4}/\d{2}/[0-9a-f]{16}\.jpg$~', $source, 'The moved cover source is now recorded.' );
		$this->assertFileExists( $this->base . $source );
	}

	/**
	 * A readable-named original and the sizes its meta records get a random
	 * name; a lookalike file the item does not record, and documents, stay.
	 */
	public function test_legacy_original_is_converted_without_touching_neighbours(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = $this->media( 'public', '', array( 'file_path' => '2026/10/my-holiday.jpg' ) );
		$this->put_file( '2026/10/my-holiday.jpg' );
		$this->put_file( '2026/10/my-holiday-300x200.jpg' );
		$this->put_file( '2026/10/my-holiday-640x480.jpg' ); // Not recorded by this item.
		$repo->set( $id, 'thumb_medium_path', '2026/10/my-holiday-300x200.jpg' );
		$repo->delete( $id, 'thumb_medium_webp_path' );

		$this->assertTrue( \WPMediaVerse\Services\MediaFileRotator::convert_legacy( $id ) );

		$this->assertTrue( DirectDelivery::is_random_name( (string) $repo->get_raw( $id, 'file_path' ) ) );
		$this->assertTrue( DirectDelivery::is_random_name( (string) $repo->get_raw( $id, 'thumb_medium_path' ) ) );
		$this->assertFileDoesNotExist( $this->base . '2026/10/my-holiday.jpg' );
		$this->assertFileExists( $this->base . '2026/10/my-holiday-640x480.jpg', 'A file this item does not record is never moved.' );
		$this->assertSame( 'my-holiday.jpg', (string) $repo->get_raw( $id, 'original_filename' ) );

		$doc = $this->media( 'public', '', array( 'file_path' => '2026/10/contract.pdf', 'file_type' => 'application/pdf' ) );
		$this->put_file( '2026/10/contract.pdf' );
		$this->assertFalse( \WPMediaVerse\Services\MediaFileRotator::convert_legacy( $doc ), 'Documents keep their names.' );
	}

	/**
	 * The job walks newest first, remembers where it is, and finishes.
	 */
	public function test_legacy_job_newest_first_and_finishes(): void {
		$repo  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$older = $this->media( 'public', '', array( 'file_path' => '2026/10/older-photo.jpg' ) );
		$newer = $this->media( 'public', '', array( 'file_path' => '2026/10/newer-photo.jpg' ) );
		$this->put_file( '2026/10/older-photo.jpg' );
		$this->put_file( '2026/10/newer-photo.jpg' );
		$ids = $repo->media_ids_before( 0, 2 );
		$this->assertSame( array( $newer, $older ), $ids, 'Newest first.' );

		delete_option( \WPMediaVerse\Services\MediaFileRotator::LEGACY_OPTION );
		$runs = 0;
		do {
			\WPMediaVerse\Services\MediaFileRotator::run_legacy_batch();
			$state = get_option( \WPMediaVerse\Services\MediaFileRotator::LEGACY_OPTION );
		} while ( empty( $state['done'] ) && ++$runs < 500 );
		$this->assertTrue( $state['done'], 'The job finishes.' );

		$this->assertGreaterThanOrEqual( 2, $state['converted'] );
		$this->assertTrue( DirectDelivery::is_random_name( (string) $repo->get_raw( $older, 'file_path' ) ) );
		$this->assertTrue( DirectDelivery::is_random_name( (string) $repo->get_raw( $newer, 'file_path' ) ) );
		$before = $state;
		\WPMediaVerse\Services\MediaFileRotator::run_legacy_batch();
		$this->assertSame( $before, get_option( \WPMediaVerse\Services\MediaFileRotator::LEGACY_OPTION ), 'A finished job does nothing.' );
	}

	/**
	 * A staged cover source is one of the item's files, so delete cleanup,
	 * renames and cloud moves all find it (it used to be left on disk forever).
	 */
	public function test_cover_source_is_recorded(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = $this->media( 'public' );
		$abs  = ( new \WPMediaVerse\Services\PosterService() )->stage_bytes(
			$id,
			array(
				'data' => 'x',
				'mime' => 'image/jpeg',
			)
		);
		$rel  = (string) $repo->get_raw( $id, 'poster_source_path' );
		$this->assertStringEndsWith( $rel, (string) $abs );
		$this->assertContains( $rel, $repo->get_stored_file_paths( $id ) );
	}
}
