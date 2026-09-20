<?php
/**
 * Every shortcode and block this plugin registers declares whether it takes an
 * object id, and the ones that do are RENDERED anonymously against a private
 * fixture.
 *
 * This is the render surface's half of RouteAuthorityTest. The two answer the
 * same question about two different doors:
 *
 *   REST    - a caller asks the server for an object by id.
 *   RENDER  - a PAGE asks the server for an object by id, because someone typed
 *             [mvs_album id="7"] into post content.
 *
 * The second door is the one nothing was watching. A shortcode takes its id
 * straight out of post_content and renders server-side on a page any visitor
 * can open; the REST guard never sees it, WPCS and PHPStan cannot ask the
 * question, and the browser smoke only walks pages that exist. So a fix landed
 * on a controller and missed the renderer sitting next to it goes unnoticed -
 * which is exactly what happened to the 2.5.1 albums/{id}/items fix: the
 * controller grew viewable_item_ids(), and the album BLOCK kept its own raw
 * JOIN with no per-item privacy filter at all.
 *
 * What the teeth actually do, per registered renderer that takes an id:
 *
 *   1. seed a private media item, a private document, a private album, and the
 *      shape that leaks - a PUBLIC album and a PUBLIC collection each holding
 *      that private item;
 *   2. feed EVERY one of those ids to EVERY id-taking renderer, which covers
 *      type confusion for free (a media id where an album is expected, and
 *      back) without a case list to keep in sync;
 *   3. render as a signed-out visitor and assert the HTML contains nothing that
 *      belongs to a private item: not its title, not its slug, not its file
 *      path, not a signed URL for it, and - where the renderer DISCOVERED the
 *      id rather than being handed it - not its bare id either.
 *
 * A renderer that genuinely cannot leak (no id, or aggregate counts only) says
 * so in audit/render-authority.json with a reason, the way the route manifest
 * declares its public routes.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

class RenderSurfaceAuthorityTest extends WP_UnitTestCase {

	private const MANIFEST = __DIR__ . '/../../audit/render-authority.json';

	/** Directory whose renderers this suite owns. Pro carries the same test over MVS_PRO_DIR. */
	private function plugin_dir(): string {
		return (string) MVS_PLUGIN_DIR;
	}

	/** @var array<string, array> */
	private array $manifest = array();

	/** @var int */
	private int $owner = 0;

	/**
	 * Fixture ids, keyed by the label used in failure messages.
	 *
	 * @var array<string, int>
	 */
	private array $fixtures = array();

	/**
	 * marker => the fixture label it belongs to. Anything here appearing in an
	 * anonymous render is a leak.
	 *
	 * @var array<string, array{owner:string, kind:string}>
	 */
	private array $secrets = array();

	/**
	 * Ids a renderer must never DISCOVER (album/collection membership).
	 *
	 * @var int[]
	 */
	private array $hidden_ids = array();

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mvs_media_index' ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->manifest = (array) json_decode( (string) file_get_contents( self::MANIFEST ), true );

		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	// -----------------------------------------------------------------
	// The live registries.
	// -----------------------------------------------------------------

	/**
	 * Shortcodes registered by THIS plugin, resolved by reflecting the callback
	 * back to the file that defines it.
	 *
	 * Reflection rather than a name prefix on purpose: Free's [mvs_documents]
	 * and Pro's [mvs_document] differ by one character, so any prefix rule puts
	 * one of them in the wrong suite and the other in neither.
	 *
	 * @return array<string, callable>
	 */
	private function live_shortcodes(): array {
		$out = array();

		foreach ( (array) $GLOBALS['shortcode_tags'] as $tag => $cb ) {
			$file = $this->callback_file( $cb );

			if ( '' !== $file && 0 === strpos( $file, $this->plugin_dir() ) ) {
				$out[ $tag ] = $cb;
			}
		}

		return $out;
	}

	/**
	 * Blocks registered by THIS plugin.
	 *
	 * register_block_type_from_metadata() wraps the block's render.php in a
	 * closure that closes over $template_path, so the static variable names the
	 * owning plugin exactly. Falls back to the callback's own file for a block
	 * registered with a hand-written render_callback.
	 *
	 * @return array<string, \WP_Block_Type>
	 */
	private function live_blocks(): array {
		$out = array();

		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block_type ) {
			$cb = $block_type->render_callback;

			if ( ! $cb ) {
				continue; // Nothing renders server-side; nothing can leak server-side.
			}

			$file = '';
			if ( $cb instanceof \Closure ) {
				$statics = ( new \ReflectionFunction( $cb ) )->getStaticVariables();
				$file    = (string) ( $statics['template_path'] ?? '' );
			}
			if ( '' === $file ) {
				$file = $this->callback_file( $cb );
			}

			if ( '' !== $file && 0 === strpos( $file, $this->plugin_dir() ) ) {
				$out[ $name ] = $block_type;
			}
		}

		return $out;
	}

	/**
	 * The file a callable is defined in, or '' when it cannot be resolved.
	 *
	 * @param mixed $cb Callable.
	 */
	private function callback_file( $cb ): string {
		try {
			if ( is_array( $cb ) && 2 === count( $cb ) ) {
				return (string) ( new \ReflectionMethod( $cb[0], $cb[1] ) )->getFileName();
			}
			if ( $cb instanceof \Closure || is_string( $cb ) ) {
				return (string) ( new \ReflectionFunction( $cb ) )->getFileName();
			}
			if ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
				return (string) ( new \ReflectionMethod( $cb, '__invoke' ) )->getFileName();
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	// -----------------------------------------------------------------
	// Declaration.
	// -----------------------------------------------------------------

	public function test_every_registered_shortcode_declares_whether_it_takes_an_id(): void {
		$undeclared = array_diff_key( $this->live_shortcodes(), (array) ( $this->manifest['shortcodes'] ?? array() ) );

		$this->assertSame(
			array(),
			array_keys( $undeclared ),
			"These shortcodes are registered but not declared in audit/render-authority.json.\n"
			. "Add each one with its id attribute, or null plus a reason it cannot name an object.\n"
			. 'A renderer nobody declared is a renderer nobody probed.'
		);
	}

	public function test_every_registered_block_declares_whether_it_takes_an_id(): void {
		$undeclared = array_diff_key( $this->live_blocks(), (array) ( $this->manifest['blocks'] ?? array() ) );

		$this->assertSame(
			array(),
			array_keys( $undeclared ),
			"These blocks render server-side but are not declared in audit/render-authority.json.\n"
			. 'Add each one with its id attribute, or null plus a reason.'
		);
	}

	public function test_the_manifest_has_nothing_that_is_no_longer_registered(): void {
		$stale = array_merge(
			array_keys( array_diff_key( (array) ( $this->manifest['shortcodes'] ?? array() ), $this->live_shortcodes() ) ),
			array_keys( array_diff_key( (array) ( $this->manifest['blocks'] ?? array() ), $this->live_blocks() ) )
		);

		$this->assertSame(
			array(),
			$stale,
			'Declared but no longer registered. Remove them so the manifest keeps meaning something.'
		);
	}

	public function test_a_renderer_excused_from_the_probe_says_why(): void {
		$problems = array();

		foreach ( $this->declared() as $key => $entry ) {
			$reason = trim( (string) ( $entry['reason'] ?? '' ) );

			if ( null === ( $entry['id_att'] ?? null ) && '' === $reason ) {
				$problems[] = $key . ' — declared as taking no object id with no reason. Say why it cannot name one.';
			}

			if ( ! empty( $entry['discloses'] ) && '' === $reason ) {
				$problems[] = $key . ' — declares a deliberate disclosure with no reason.';
			}

			if ( 0 === strpos( $reason, 'TODO' ) ) {
				$problems[] = $key . ' — the reason is a TODO.';
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * Every declared renderer, keyed "shortcode [tag]" / "block name".
	 *
	 * @return array<string, array>
	 */
	private function declared(): array {
		$out = array();

		foreach ( (array) ( $this->manifest['shortcodes'] ?? array() ) as $tag => $entry ) {
			$out[ 'shortcode [' . $tag . ']' ] = array( 'kind' => 'shortcode', 'name' => $tag ) + (array) $entry;
		}
		foreach ( (array) ( $this->manifest['blocks'] ?? array() ) as $name => $entry ) {
			$out[ 'block ' . $name ] = array( 'kind' => 'block', 'name' => $name ) + (array) $entry;
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Fixtures.
	// -----------------------------------------------------------------

	private function seed_media( string $marker, string $privacy, string $media_type = 'image', string $file_type = 'image/jpeg' ): int {
		$id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => $marker,
				'post_author'       => $this->owner,
				'media_type'        => $media_type,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
				'file_path'         => '2026/09/' . strtolower( $marker ) . '.bin',
				'file_type'         => $file_type,
				'file_size'         => 4321,
				'slug'              => strtolower( $marker ),
			)
		);

		$this->assertGreaterThan( 0, $id, 'fixture: media row was not written' );

		return $id;
	}

	/**
	 * Seed the fixtures and prove the fixture IS the situation under test.
	 *
	 * Without this, a renderer that refuses everything and a fixture that was
	 * never private look identical from the assertions below - which is how a
	 * privacy test goes green while probing a public item.
	 */
	private function seed(): void {
		$albums   = Plugin::container()->get( 'albums' );
		$colls    = Plugin::container()->get( 'collections' );
		$privacy  = Plugin::container()->get( 'privacy' );

		$private_media = $this->seed_media( 'MVSRENDERSECRETMEDIA', 'private' );
		$private_doc   = $this->seed_media( 'MVSRENDERSECRETDOC', 'private', 'document', 'application/pdf' );
		$album_member  = $this->seed_media( 'MVSRENDERSECRETINALBUM', 'private' );
		// mvs_favorites carries a UNIQUE (media_id, user_id), so one item cannot
		// sit in two of this owner's collections - each collection fixture needs
		// its own private member or the second one silently probes an empty list.
		$coll_member   = $this->seed_media( 'MVSRENDERSECRETINCOLLECTION', 'private' );
		$decoy         = $this->seed_media( 'MVSRENDERPUBLICDECOY', 'public' );

		// A PRIVATE album. create() takes the site default when the owner's
		// privacy lock is on, so the level is set explicitly afterwards.
		$private_album = (int) $albums->create( $this->owner, array( 'title' => 'MVSRENDERSECRETALBUM' ) );
		$albums->add_items( $private_album, array( $decoy ) );
		$albums->set_privacy( $private_album, 'private' );

		// THE SHAPE THAT LEAKS: a public album holding a private item. The
		// privacy clamp only ever tightens, so a public album genuinely can
		// hold one - see AlbumController::viewable_item_ids().
		$public_album = (int) $albums->create( $this->owner, array( 'title' => 'MVSRENDERPUBLICALBUM' ) );
		$albums->set_privacy( $public_album, 'public' );
		$albums->add_items( $public_album, array( $album_member ) );

		// The same shape for a manual collection.
		$public_collection = (int) self::factory()->post->create(
			array(
				'post_type'   => 'mvs_collection',
				'post_status' => 'publish',
				'post_author' => $this->owner,
				'post_title'  => 'MVSRENDERPUBLICCOLLECTION',
			)
		);
		$colls->set_privacy( $public_collection, 'public' );

		$members_collection = (int) self::factory()->post->create(
			array(
				'post_type'   => 'mvs_collection',
				'post_status' => 'publish',
				'post_author' => $this->owner,
				'post_title'  => 'MVSRENDERMEMBERSCOLLECTION',
			)
		);
		$colls->set_privacy( $members_collection, 'members' );

		global $wpdb;
		foreach ( array( $public_collection => $private_media, $members_collection => $coll_member ) as $cid => $mid ) {
			$wpdb->insert(
				$wpdb->prefix . 'mvs_favorites',
				array(
					'user_id'       => $this->owner,
					'media_id'      => $mid,
					'collection_id' => $cid,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}

		$this->fixtures = array(
			'private media'                   => $private_media,
			'private document'                => $private_doc,
			'private album'                   => $private_album,
			'public album + private item'     => $public_album,
			'public collection + private item' => $public_collection,
			'members collection'              => $members_collection,
		);

		$privacy->flush_cache();

		// Fixture proof.
		$this->assertFalse( $privacy->can_view( $private_media, 0 ), 'fixture: the private media is viewable by a stranger' );
		$this->assertFalse( $privacy->can_view( $album_member, 0 ), 'fixture: the album member is viewable by a stranger' );
		$this->assertFalse( $privacy->can_view( $private_doc, 0 ), 'fixture: the private document is viewable by a stranger' );
		$this->assertFalse( $privacy->can_view( $coll_member, 0 ), 'fixture: the collection member is viewable by a stranger' );
		$this->assertSame( 'private', $albums->get_privacy( $private_album ), 'fixture: the private album is not private' );
		$this->assertSame( 'public', $albums->get_privacy( $public_album ), 'fixture: the public album is not public' );
		$this->assertSame( 'private', (string) Plugin::container()->get( 'media_repository' )->get( $album_member, 'privacy' ), 'fixture: the album clamp made the member public' );

		$repo = Plugin::container()->get( 'media_repository' );

		$this->secrets    = array();
		$this->hidden_ids = array( $private_media, $album_member, $coll_member );

		foreach ( array( $private_media, $private_doc, $album_member, $coll_member ) as $mid ) {
			$this->secrets[ (string) $repo->get( $mid, 'title' ) ]     = 'title';
			$this->secrets[ (string) $repo->get( $mid, 'slug' ) ]      = 'slug';
			$this->secrets[ (string) $repo->get( $mid, 'file_path' ) ] = 'file path';
			$this->secrets[ 'mvs_id=' . $mid ]                          = 'signed URL';
		}

		$this->secrets['MVSRENDERSECRETALBUM'] = 'title';

		unset( $this->secrets[''] );
	}

	// -----------------------------------------------------------------
	// The teeth.
	// -----------------------------------------------------------------

	/**
	 * Every id-taking renderer, fed every private fixture, as a stranger.
	 */
	public function test_an_id_taking_renderer_discloses_nothing_about_a_private_fixture(): void {
		$this->seed();

		$leaks = array();

		foreach ( $this->declared() as $key => $entry ) {
			$att = $entry['id_att'] ?? null;
			if ( null === $att ) {
				continue;
			}

			$allowed = (array) ( $entry['discloses'] ?? array() );

			foreach ( $this->fixtures as $label => $fixture_id ) {
				$html = $this->render( $entry, array( $att => $fixture_id ) );

				if ( null === $html ) {
					$leaks[] = $key . ' — threw while rendering the ' . $label . ' fixture; a renderer that cannot be probed is not a renderer that was cleared';
					continue;
				}

				foreach ( $this->found( $html, $allowed ) as $what ) {
					$leaks[] = $key . ' fed the ' . $label . ' — a stranger got the private item\'s ' . $what;
				}

				// A bare id counts only where the renderer DISCOVERED it. When
				// the id was handed in as the attribute, echoing it back
				// discloses nothing the page author did not already publish.
				if ( ! in_array( $fixture_id, $this->hidden_ids, true ) ) {
					foreach ( $this->hidden_ids as $hidden ) {
						if ( preg_match( '/\b' . $hidden . '\b/', $html ) ) {
							$leaks[] = $key . ' fed the ' . $label . ' — a stranger got the bare id of an item they cannot open (enumeration handle)';
							break;
						}
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$leaks,
			"A render surface handed a signed-out visitor something that belongs to a private item:\n  " . implode( "\n  ", array_unique( $leaks ) )
		);
	}

	/**
	 * A renderer scoped to a USER must not list that user's private media.
	 */
	public function test_a_user_scoped_renderer_does_not_list_the_owners_private_media(): void {
		$this->seed();

		$leaks = array();

		foreach ( $this->declared() as $key => $entry ) {
			$att = $entry['user_att'] ?? null;
			if ( null === $att ) {
				continue;
			}

			$html = $this->render( $entry, array( $att => $this->owner, 'perPage' => 50, 'per_page' => 50 ) );

			if ( null === $html ) {
				$leaks[] = $key . ' — threw while rendering';
				continue;
			}

			foreach ( $this->found( $html, (array) ( $entry['discloses'] ?? array() ) ) as $what ) {
				$leaks[] = $key . ' scoped to the owner — a stranger got a private item\'s ' . $what;
			}
		}

		$this->assertSame(
			array(),
			$leaks,
			"A user-scoped render surface listed private media to a signed-out visitor:\n  " . implode( "\n  ", $leaks )
		);
	}

	/**
	 * Which secrets are in this HTML, minus the ones declared deliberate.
	 *
	 * @param string   $html    Rendered markup.
	 * @param string[] $allowed Declared deliberate disclosures.
	 * @return string[]
	 */
	private function found( string $html, array $allowed ): array {
		$hits = array();

		foreach ( $this->secrets as $needle => $kind ) {
			if ( in_array( $kind, $allowed, true ) ) {
				continue;
			}
			if ( false !== strpos( $html, (string) $needle ) ) {
				$hits[ $kind ] = $kind;
			}
		}

		return array_values( $hits );
	}

	/**
	 * Render one declared renderer anonymously. Null when it threw.
	 *
	 * @param array                $entry Manifest entry (carries kind + name).
	 * @param array<string, mixed> $atts  Attributes to pass.
	 */
	private function render( array $entry, array $atts ): ?string {
		wp_set_current_user( 0 );
		Plugin::container()->get( 'privacy' )->flush_cache();

		try {
			if ( 'shortcode' === $entry['kind'] ) {
				$pairs = '';
				foreach ( $atts as $k => $v ) {
					$pairs .= ' ' . $k . '="' . $v . '"';
				}

				return (string) do_shortcode( '[' . $entry['name'] . $pairs . ']' );
			}

			return (string) do_blocks( '<!-- wp:' . $entry['name'] . ' ' . wp_json_encode( $atts ) . ' /-->' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
