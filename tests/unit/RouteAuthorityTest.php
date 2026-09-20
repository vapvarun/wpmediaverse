<?php
/**
 * Every Free REST route declares who may reach it, and the public ones are probed.
 *
 * Pro carries the same test over its own namespace and its own manifest.
 *
 * Nothing else in this pipeline asks the authorisation question. WPCS, PHPStan,
 * the contract audit, the browser smoke and Plugin Check all pass on a route
 * with no ownership check - which is how GET /media/{id}/group shipped for
 * several releases handing every private item's metadata to anonymous callers,
 * behind a comment claiming a privacy filter that did not exist.
 *
 * So the claim moves out of the comment and into audit/route-authority.json,
 * where it is checked:
 *
 *   1. a route missing from the manifest fails - a new route cannot ship
 *      without someone stating its audience;
 *   2. a route whose callback is __return_true must be declared public AND
 *      carry a reason;
 *   3. a public route that takes an object id is DISPATCHED anonymously
 *      against a private fixture, and must not answer 200 or leak the title.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;

class RouteAuthorityTest extends WP_UnitTestCase {

	private const MANIFEST = __DIR__ . '/../../audit/route-authority.json';

	private const NAMESPACES = array( 'mvs/v1' );

	/** @var array */
	private array $manifest = array();

	/** @var int */
	private int $owner;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mvs_media_index' ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->manifest = (array) ( json_decode( (string) file_get_contents( self::MANIFEST ), true )['routes'] ?? array() );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		do_action( 'rest_api_init' );
	}

	/**
	 * Live routes, keyed exactly as the manifest keys them.
	 *
	 * @return array<string, array{route:string, methods:array, public:bool, object_id:bool}>
	 */
	private function live_routes(): array {
		$out = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			$ns = '';
			foreach ( self::NAMESPACES as $candidate ) {
				if ( 0 === strpos( ltrim( $route, '/' ), $candidate ) ) {
					$ns = $candidate;
					break;
				}
			}

			if ( '' === $ns || rtrim( '/' . $ns, '/' ) === rtrim( $route, '/' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$methods = array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) );
				$cb      = $handler['permission_callback'] ?? null;

				$out[ implode( ',', $methods ) . ' ' . $route ] = array(
					'route'     => $route,
					'methods'   => $methods,
					'public'    => ( is_string( $cb ) && '__return_true' === $cb ),
					'object_id' => (bool) preg_match( '/\(\?P<(id|media_id)>/', $route ),
				);
			}
		}

		return $out;
	}

	public function test_every_live_route_declares_its_audience(): void {
		$undeclared = array_diff_key( $this->live_routes(), $this->manifest );

		$this->assertSame(
			array(),
			array_keys( $undeclared ),
			"These routes are registered but not declared in audit/route-authority.json.\n"
			. "Add each one with its audience (public|member|owner|admin|guarded).\n"
			. 'A route nobody declared is a route nobody reviewed.'
		);
	}

	public function test_the_manifest_has_no_routes_that_no_longer_exist(): void {
		$stale = array_diff_key( $this->manifest, $this->live_routes() );

		$this->assertSame(
			array(),
			array_keys( $stale ),
			'These routes are declared but no longer registered. Remove them so the manifest keeps meaning something.'
		);
	}

	public function test_public_routes_are_declared_public_and_say_why(): void {
		$problems = array();

		foreach ( $this->live_routes() as $key => $live ) {
			$declared = $this->manifest[ $key ] ?? null;
			if ( ! $declared ) {
				continue; // Covered by the declaration test.
			}

			if ( $live['public'] && 'public' !== ( $declared['audience'] ?? '' ) ) {
				$problems[] = $key . ' — permission_callback is __return_true but declared "' . ( $declared['audience'] ?? '?' ) . '"';
				continue;
			}

			if ( 'public' === ( $declared['audience'] ?? '' ) ) {
				$reason = trim( (string) ( $declared['reason'] ?? '' ) );

				if ( '' === $reason || 0 === strpos( $reason, 'TODO' ) ) {
					$problems[] = $key . ' — declared public with no reason. Say what a stranger gets from it.';
				}
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * A public album must not disclose the items inside it that the caller
	 * cannot open.
	 *
	 * The media probe below cannot reach this: an album route fed a media id
	 * answers 404, so the check passes vacuously. That blind spot is exactly
	 * where the albums/{id}/items leak lived until the manifest work found it,
	 * so the album case gets its own fixture: a PUBLIC album holding a PRIVATE
	 * item, which is the shape that leaks.
	 */
	public function test_public_album_routes_do_not_disclose_private_members(): void {
		$repo   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$albums = \WPMediaVerse\Core\Plugin::container()->get( 'albums' );

		$private = (int) $repo->insert(
			array(
				'title'             => 'ALBUM-PROBE-SECRET',
				'post_author'       => $this->owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'private',
				'file_path'         => '2026/09/album-probe.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'album-probe-' . wp_generate_password( 8, false, false ),
			)
		);

		$album = (int) $albums->create( $this->owner, array( 'title' => 'Album probe', 'privacy' => 'public' ) );
		$albums->add_items( $album, array( $private ) );

		wp_set_current_user( 0 );

		$leaks = array();

		foreach ( $this->live_routes() as $key => $live ) {
			$declared = $this->manifest[ $key ] ?? array();

			if ( 'album' !== ( $declared['probe'] ?? '' ) || ! in_array( 'GET', $live['methods'], true ) ) {
				continue;
			}

			// The route carries a literal [\d], so the pattern needs \\\\d - one
			// backslash short and this matches nothing, the loop does nothing,
			// and the check passes while testing exactly zero routes.
			$path = (string) preg_replace( '/\(\?P<id>\[\\\\d\]\+\)/', (string) $album, $live['route'] );
			if ( $path === $live['route'] ) {
				$leaks[] = $key . ' — probe could not build a path for this route, so it proved nothing';
				continue;
			}
			$response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
			$body     = (string) wp_json_encode( $response->get_data() );

			if ( false !== strpos( $body, 'ALBUM-PROBE-SECRET' ) || false !== strpos( $body, '"' . $private . '"' ) || false !== strpos( $body, ':' . $private . ',' ) || false !== strpos( $body, '[' . $private . ']' ) ) {
				$leaks[] = $key . ' — a private album member reached an anonymous caller';
			}
		}

		$this->assertSame(
			array(),
			$leaks,
			"A public album route disclosed an item the caller cannot open:\n" . implode( "\n", $leaks )
		);
	}

	/**
	 * The teeth: a public route carrying a media id must refuse a private item.
	 */
	public function test_public_media_routes_do_not_disclose_a_private_item(): void {
		$private = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'ROUTE-AUTHORITY-SECRET',
				'post_author'       => $this->owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'private',
				'file_path'         => '2026/09/route-authority.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'route-authority-' . wp_generate_password( 8, false, false ),
			)
		);

		wp_set_current_user( 0 );

		$leaks = array();

		foreach ( $this->live_routes() as $key => $live ) {
			if ( ! $live['public'] || ! $live['object_id'] || ! in_array( 'GET', $live['methods'], true ) ) {
				continue;
			}

			$declared = $this->manifest[ $key ] ?? array();
			if ( 'none' === ( $declared['probe'] ?? '' ) ) {
				continue; // Explicitly declared as carrying nothing that can leak.
			}

			$path = (string) preg_replace( '/\(\?P<(id|media_id)>\[\\\\d\]\+\)/', (string) $private, $live['route'] );
			if ( false !== strpos( $path, '(?P<' ) ) {
				continue; // Another id in the path we cannot fill; not this test's business.
			}

			$response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
			$body     = (string) wp_json_encode( $response->get_data() );

			if ( 200 === $response->get_status() && false !== strpos( $body, 'ROUTE-AUTHORITY-SECRET' ) ) {
				$leaks[] = $key . ' — 200 to an anonymous caller, and the private item is in the body';
			}
		}

		$this->assertSame(
			array(),
			$leaks,
			"A public route returned a private item to a signed-out caller:\n" . implode( "\n", $leaks )
		);
	}
}
