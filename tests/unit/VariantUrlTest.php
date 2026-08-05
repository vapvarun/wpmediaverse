<?php
/**
 * Regression guards for modern-format variant URL emission (WebP / AVIF).
 *
 * Basecamp #10162798416: on a site serving the JPEG straight from a CDN,
 * `TemplateHelpers::get_webp_variant_url()` returned the stale absolute URL
 * stored in `thumb_<size>_webp`, which points inside
 * wp-content/uploads/wpmediaverse/ — the directory MediaVerse itself locks with
 * `Deny from all`. Every WebP 403'd on Apache (AH01797) and rendered broken.
 *
 * There was no test covering variant URL emission at all, which is why it
 * shipped. These are that net. The invariant under test:
 *
 *   A variant URL is only ever emitted on the SAME HOST as the primary URL
 *   it is paired with.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class VariantUrlTest extends WP_UnitTestCase {

	/** @var int */
	private int $author;

	/** @var \WPMediaVerse\Core\TemplateHelpers */
	private $helpers;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index_table ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->author  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->helpers = new \WPMediaVerse\Core\TemplateHelpers();
	}

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	private function base_url(): string {
		return trailingslashit( wp_upload_dir()['baseurl'] ) . 'wpmediaverse/';
	}

	/**
	 * Media row with a JPEG thumb and its WebP sibling, both recorded the
	 * modern way (`_path` rel meta) and the legacy way (absolute URL meta).
	 *
	 * @param bool $with_path Whether to write the `_path` metas.
	 * @return array{id:int,jpeg_rel:string,webp_rel:string}
	 */
	private function make_media( bool $with_path = true ): array {
		$rel_dir  = '2026/08/';
		$stem     = wp_generate_password( 12, false, false );
		$jpeg_rel = $rel_dir . $stem . '-300x200.jpg';
		$webp_rel = $rel_dir . $stem . '-300x200.webp';

		$id = (int) $this->repo()->insert(
			array(
				'title'             => 'Variant ' . $stem,
				'post_author'       => $this->author,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'file_path'         => $rel_dir . $stem . '.jpg',
				'file_type'         => 'image/jpeg',
			)
		);

		$this->repo()->set( $id, 'thumb_medium', $this->base_url() . $jpeg_rel );
		$this->repo()->set( $id, 'thumb_medium_webp', $this->base_url() . $webp_rel );

		if ( $with_path ) {
			$this->repo()->set( $id, 'thumb_medium_path', $jpeg_rel );
			$this->repo()->set( $id, 'thumb_medium_webp_path', $webp_rel );
		}

		return array(
			'id'       => $id,
			'jpeg_rel' => $jpeg_rel,
			'webp_rel' => $webp_rel,
		);
	}

	/**
	 * THE regression. A CDN-hosted JPEG must never be paired with the stale
	 * local WebP URL — that pairing is the 403.
	 */
	public function test_cdn_primary_never_emits_a_local_variant(): void {
		$m       = $this->make_media();
		$cdn_jpeg = 'https://example.b-cdn.net/wpmediaverse/' . $m['jpeg_rel'];

		$this->assertSame(
			'',
			$this->helpers->get_webp_variant_url( $m['id'], 'medium', $cdn_jpeg ),
			'A local WebP URL beside a CDN JPEG is unreachable and must be suppressed.'
		);
	}

	/**
	 * Same guard for legacy rows that never received a `_path` meta —
	 * imported MediaPress / rtMedia / BuddyBoss records, which
	 * `MediaVariantWriter::path_meta_ok()` deliberately skips.
	 */
	public function test_cdn_primary_never_emits_a_local_variant_without_path_meta(): void {
		$m        = $this->make_media( false );
		$cdn_jpeg = 'https://example.b-cdn.net/wpmediaverse/' . $m['jpeg_rel'];

		$this->assertSame(
			'',
			$this->helpers->get_webp_variant_url( $m['id'], 'medium', $cdn_jpeg ),
			'Legacy rows must obey the host invariant too.'
		);
	}

	/**
	 * Same-origin primary: the WebP resolves through the driver and is emitted.
	 * Guards against "fix" by simply never returning a variant.
	 */
	public function test_same_host_primary_emits_the_variant(): void {
		$m = $this->make_media();

		$this->assertSame(
			$this->base_url() . $m['webp_rel'],
			$this->helpers->get_webp_variant_url( $m['id'], 'medium', $this->base_url() . $m['jpeg_rel'] ),
			'A same-host WebP must still be emitted — the optimisation must keep working.'
		);
	}

	/**
	 * Legacy rows with no `_path` still resolve off the URL meta.
	 */
	public function test_legacy_row_without_path_meta_still_emits(): void {
		$m = $this->make_media( false );

		$this->assertSame(
			$this->base_url() . $m['webp_rel'],
			$this->helpers->get_webp_variant_url( $m['id'], 'medium', $this->base_url() . $m['jpeg_rel'] ),
			'Imported records have no _path and must keep working off the legacy URL meta.'
		);
	}

	/**
	 * The pre-existing /serve guard: that route negotiates WebP itself, and a
	 * sibling URL bypassing it would break privacy enforcement.
	 */
	public function test_serve_primary_suppresses_the_variant(): void {
		$m = $this->make_media();

		$this->assertSame(
			'',
			$this->helpers->get_webp_variant_url( $m['id'], 'medium', home_url( '/wp-json/mvs/v1/serve?media_id=' . $m['id'] ) )
		);
	}

	/**
	 * AVIF shares the implementation; assert the wrapper is wired to it and
	 * obeys the same invariant.
	 */
	public function test_avif_obeys_the_same_host_invariant(): void {
		$m = $this->make_media();
		$this->repo()->set( $m['id'], 'thumb_medium_avif', $this->base_url() . $m['jpeg_rel'] . '.avif' );
		$this->repo()->set( $m['id'], 'thumb_medium_avif_path', $m['jpeg_rel'] . '.avif' );

		$this->assertSame(
			'',
			$this->helpers->get_avif_variant_url(
				$m['id'],
				'medium',
				'https://example.b-cdn.net/wpmediaverse/' . $m['jpeg_rel']
			),
			'AVIF must obey the invariant too — it shares the resolver.'
		);
	}

	/**
	 * `MediaUrl::variant_url()` prefers the `_path` meta over the stored URL,
	 * so a row whose URL meta is stale still resolves correctly.
	 */
	public function test_variant_url_prefers_path_meta_over_stale_url_meta(): void {
		$m = $this->make_media();
		$this->repo()->set( $m['id'], 'thumb_medium_webp', 'https://stale.example.com/old/path.webp' );

		$this->assertSame(
			$this->base_url() . $m['webp_rel'],
			\WPMediaVerse\Core\MediaUrl::variant_url( $m['id'], 'thumb_medium_webp' ),
			'The rel-path meta is the source of truth; the URL meta is legacy fallback only.'
		);
	}

	/**
	 * Free-only site whose library was migrated to a CDN by Pro.
	 *
	 * Cloud drivers ship in Pro, so `get_driver_for_location()` falls back to
	 * LocalDriver and builds a local URL for a file that lives on the CDN —
	 * silently wrong, and a 404 once the local copies are cleaned up.
	 * `file_url` is the authority for where the file is; when the driver
	 * disagrees with it, the stored variant URL (written by whichever driver
	 * DID own the file) wins.
	 */
	public function test_cloud_hosted_media_does_not_fall_back_to_a_local_variant_url(): void {
		$m = $this->make_media();

		$cdn_base = 'https://example.b-cdn.net/wpmediaverse/';
		$this->repo()->set( $m['id'], 'file_url', $cdn_base . $m['jpeg_rel'] );
		$this->repo()->set( $m['id'], 'thumb_medium_webp', $cdn_base . $m['webp_rel'] );

		$this->assertSame(
			$cdn_base . $m['webp_rel'],
			\WPMediaVerse\Core\MediaUrl::variant_url( $m['id'], 'thumb_medium_webp' ),
			'A CDN-hosted item must not resolve its variant to the local uploads dir just because no cloud driver is loaded.'
		);
	}

	/**
	 * An unknown size has no variant mapping and must not guess.
	 */
	public function test_unknown_size_returns_empty(): void {
		$m = $this->make_media();

		$this->assertSame(
			'',
			$this->helpers->get_webp_variant_url( $m['id'], 'nonsense', $this->base_url() . $m['jpeg_rel'] )
		);
	}
}
