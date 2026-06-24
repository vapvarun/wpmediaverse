<?php
/**
 * Functional certification runner.
 *
 * A behavioural gate that complements the static checks (phpcs, phpstan,
 * coding-rules). It runs two disjoint check families against a LIVE,
 * activated site so the result is identical on any machine:
 *
 *   - boot     : dispatch every GET REST route in the manifest and assert
 *                none returns a 500 (a thrown fatal is captured as a 500).
 *                Proves the REST surface boots without a route blowing up.
 *   - contract : for each oracle, flip its gating option OFF then ON and
 *                dispatch the guarded route both times. The oracle passes
 *                only when the OFF dispatch emits the disabled error code
 *                AND the ON dispatch does not. This catches "dead toggle"
 *                bugs — a setting that saves but enforces nothing.
 *
 * The manifest is read from the generated audit files; the oracle list in
 * audit/cert-oracles.json is the only hand-authored input. Toggles declared
 * in that file without a matching oracle are reported as "holes" — uncovered
 * surface that does not fail the gate but is tracked honestly.
 *
 * @package WPMediaVerse\Cert
 * @since   1.8.1
 */

declare( strict_types=1 );

namespace WPMediaVerse\Cert;

/**
 * Runs the functional certification checks for WPMediaVerse.
 */
class CertRunner {

	/**
	 * Plugin root directory (trailing-slashed).
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Decoded route/feature manifest.
	 *
	 * @var array<string,mixed>
	 */
	private array $manifest;

	/**
	 * Decoded cert-oracles.json.
	 *
	 * @var array<string,mixed>
	 */
	private array $oracles;

	/**
	 * Sentinel used to distinguish "option stored as false" from "never set".
	 */
	private const ABSENT = '__cert_absent__';

	/**
	 * @param string|null $dir Plugin root to certify. Defaults to this plugin.
	 *                         Pass another plugin's dir to reuse the engine
	 *                         (the Pro shim does this).
	 */
	public function __construct( ?string $dir = null ) {
		if ( null === $dir ) {
			$dir = defined( 'MVS_PLUGIN_DIR' ) ? (string) constant( 'MVS_PLUGIN_DIR' ) : dirname( __DIR__, 2 ) . '/';
		}
		$this->dir      = trailingslashit( $dir );
		$this->manifest = $this->read_manifest();
		$this->oracles  = $this->read_json( $this->dir . 'audit/cert-oracles.json' );
	}

	/**
	 * Run the requested checks and return a verdict.
	 *
	 * @param string[] $checks Subset of ['contract','boot']. Empty = both.
	 * @return array{summary:array<string,int>,rows:array<int,array<string,string>>,ok:bool}
	 */
	public function run( array $checks = array() ): array {
		if ( empty( $checks ) ) {
			$checks = array( 'contract', 'boot' );
		}

		// Authenticate as the primary admin so permission_callback gates pass
		// and what we actually probe is the FEATURE gate (which runs after
		// auth), not the auth gate. Restore the prior user afterwards.
		$prev_user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( 1 );
		}

		$rows = array();
		if ( in_array( 'contract', $checks, true ) ) {
			$rows = array_merge( $rows, $this->contract() );
		}
		if ( in_array( 'boot', $checks, true ) ) {
			$rows = array_merge( $rows, $this->boot() );
		}

		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $prev_user );
		}

		$summary = array(
			'pass' => 0,
			'fail' => 0,
			'hole' => 0,
		);
		foreach ( $rows as $row ) {
			$status = $row['status'] ?? 'hole';
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}

		// Holes do NOT fail the gate — only outright failures do.
		$ok = ( 0 === $summary['fail'] );

		$this->write_ledger( $summary, $rows, $ok );

		return array(
			'summary' => $summary,
			'rows'    => $rows,
			'ok'      => $ok,
		);
	}

	/**
	 * Dead-toggle contract check: flip each oracle's option OFF then ON and
	 * prove the guarded route's behaviour changes accordingly.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function contract(): array {
		$rows    = array();
		$oracles = isset( $this->oracles['contract'] ) && is_array( $this->oracles['contract'] ) ? $this->oracles['contract'] : array();
		$covered = array();

		foreach ( $oracles as $oracle ) {
			$id       = (string) ( $oracle['id'] ?? '' );
			$kind     = (string) ( $oracle['kind'] ?? 'option' );
			$route    = (string) ( $oracle['route'] ?? '' );
			$method   = strtoupper( (string) ( $oracle['method'] ?? 'GET' ) );
			$params   = isset( $oracle['params'] ) && is_array( $oracle['params'] ) ? $oracle['params'] : array();
			$off_code = (string) ( $oracle['off_code'] ?? '' );

			if ( '' === $id || '' === $route || '' === $off_code ) {
				continue;
			}
			$covered[ $id ] = true;

			$snapshot = $this->snapshot( $id, $kind );
			$this->set_state( $id, $kind, false );
			$off = $this->dispatch( $route, $method, $params );
			$this->set_state( $id, $kind, true );
			$on = $this->dispatch( $route, $method, $params );
			$this->restore( $id, $kind, $snapshot );

			$off_enforced = ( $off['code'] === $off_code );
			$on_allows    = ( $on['code'] !== $off_code );
			$pass         = $off_enforced && $on_allows;

			$rows[] = array(
				'check'  => 'contract',
				'entity' => $id,
				'status' => $pass ? 'pass' : 'fail',
				'detail' => sprintf(
					'off=%s(%d) on=%s(%d) expect off=%s',
					'' === $off['code'] ? '-' : $off['code'],
					$off['status'],
					'' === $on['code'] ? '-' : $on['code'],
					$on['status'],
					$off_code
				),
			);
		}

		// Honesty: every declared toggle WITHOUT an oracle is a tracked hole.
		$toggles = isset( $this->oracles['toggles'] ) && is_array( $this->oracles['toggles'] ) ? $this->oracles['toggles'] : array();
		foreach ( $toggles as $toggle ) {
			$slug = is_array( $toggle ) ? (string) ( $toggle['id'] ?? '' ) : (string) $toggle;
			if ( '' === $slug || isset( $covered[ $slug ] ) ) {
				continue;
			}
			$rows[] = array(
				'check'  => 'contract',
				'entity' => $slug,
				'status' => 'hole',
				'detail' => 'no oracle — enforcement unproven (covered by journey or by design)',
			);
		}

		return $rows;
	}

	/**
	 * REST boot smoke: dispatch every GET route and assert no 500.
	 *
	 * Routes are discovered LIVE from the running REST server (the source of
	 * truth — always current, no manifest to keep in sync). The generated
	 * manifest is used only as a fallback when live discovery is unavailable.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function boot(): array {
		$routes = $this->live_routes();
		if ( empty( $routes ) ) {
			$routes = $this->manifest_routes();
		}

		$rows = array();
		foreach ( $routes as $entry ) {
			$route   = $entry['route'];
			$methods = $entry['methods'];
			if ( ! in_array( 'GET', $methods, true ) ) {
				continue;
			}
			$res    = $this->dispatch( $route, 'GET', array() );
			$pass   = ( $res['status'] < 500 );
			$rows[] = array(
				'check'  => 'boot',
				'entity' => $route,
				'status' => $pass ? 'pass' : 'fail',
				'detail' => sprintf( 'GET -> %d%s', $res['status'], '' === $res['code'] ? '' : ' (' . $res['code'] . ')' ),
			);
		}
		return $rows;
	}

	/**
	 * Discover registered routes from the live REST server, scoped to the
	 * namespace(s) declared in cert-oracles.json ("namespaces": ["mvs/v1"]).
	 *
	 * @return array<int,array{route:string,methods:string[]}>
	 */
	private function live_routes(): array {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}
		$namespaces = isset( $this->oracles['namespaces'] ) && is_array( $this->oracles['namespaces'] ) ? $this->oracles['namespaces'] : array();
		if ( empty( $namespaces ) ) {
			return array();
		}

		$prefixes = array();
		foreach ( $namespaces as $ns ) {
			$prefixes[] = '/' . trim( (string) $ns, '/' );
		}

		$out = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			$in_ns = false;
			foreach ( $prefixes as $p ) {
				if ( $route === $p || 0 === strpos( $route, $p . '/' ) ) {
					$in_ns = true;
					break;
				}
			}
			if ( ! $in_ns ) {
				continue;
			}

			$methods = array();
			foreach ( $handlers as $handler ) {
				foreach ( (array) ( $handler['methods'] ?? array() ) as $verb => $enabled ) {
					if ( $enabled ) {
						$methods[] = strtoupper( (string) $verb );
					}
				}
			}
			$out[] = array(
				'route'   => $route,
				'methods' => array_values( array_unique( $methods ) ),
			);
		}
		return $out;
	}

	/**
	 * Flatten the manifest into a list of {route (fully-qualified), methods[]}.
	 *
	 * Supports two shapes:
	 *   - v3 scanner: manifest.features.restRoutes[] with namespace + route.
	 *   - onboard:    manifest.rest.{namespace, endpoints[]} with relative routes.
	 *
	 * @return array<int,array{route:string,methods:string[]}>
	 */
	private function manifest_routes(): array {
		$out = array();

		// v3 scanner format.
		$v3 = $this->manifest['features']['restRoutes'] ?? null;
		if ( is_array( $v3 ) ) {
			foreach ( $v3 as $r ) {
				$ns    = (string) ( $r['namespace'] ?? '' );
				$route = (string) ( $r['route'] ?? '' );
				if ( '' === $route ) {
					continue;
				}
				$out[] = array(
					'route'   => $this->qualify( $route, $ns ),
					'methods' => array_map( 'strtoupper', (array) ( $r['methods'] ?? array() ) ),
				);
			}
			if ( ! empty( $out ) ) {
				return $out;
			}
		}

		// Onboard format: rest.{namespace, endpoints|routes}.
		$rest = $this->manifest['rest'] ?? array();
		$ns   = (string) ( $rest['namespace'] ?? '' );
		$eps  = $rest['endpoints'] ?? $rest['routes'] ?? array();
		foreach ( (array) $eps as $e ) {
			$route = (string) ( $e['route'] ?? '' );
			if ( '' === $route ) {
				continue;
			}
			$out[] = array(
				'route'   => $this->qualify( $route, $ns ),
				'methods' => array_map( 'strtoupper', (array) ( $e['methods'] ?? array( 'GET' ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Prepend the namespace to a relative route when it isn't already qualified.
	 *
	 * @param string $route Route, possibly relative ("/media") or qualified ("/mvs/v1/media").
	 * @param string $ns    Namespace ("mvs/v1").
	 * @return string Fully-qualified route ("/mvs/v1/media").
	 */
	private function qualify( string $route, string $ns ): string {
		$route = '/' . ltrim( $route, '/' );
		if ( '' === $ns ) {
			return $route;
		}
		$ns_path = '/' . trim( $ns, '/' );
		if ( 0 === strpos( $route, $ns_path . '/' ) || $route === $ns_path ) {
			return $route;
		}
		return $ns_path . $route;
	}

	/**
	 * In-process REST dispatch. Never throws — a fatal becomes a synthetic 500.
	 *
	 * @param string              $route  Fully-qualified route (regex params allowed).
	 * @param string              $method HTTP verb.
	 * @param array<string,mixed> $params Request params.
	 * @return array{status:int,code:string}
	 */
	private function dispatch( string $route, string $method, array $params ): array {
		// Substitute a concrete "1" for any named regex path segment.
		$route = (string) preg_replace( '/\(\?P<[^>]+>[^)]*\)/', '1', $route );
		try {
			$req = new \WP_REST_Request( $method, $route );
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
			$res    = rest_do_request( $req );
			$status = (int) $res->get_status();
			$data   = $res->get_data();
			$code   = ( is_array( $data ) && isset( $data['code'] ) ) ? (string) $data['code'] : '';
			return array(
				'status' => $status,
				'code'   => $code,
			);
		} catch ( \Throwable $t ) {
			return array(
				'status' => 500,
				'code'   => 'throwable:' . $t->getMessage(),
			);
		}
	}

	/**
	 * Read the current stored value of a toggle (sentinel when never set).
	 *
	 * @param string $id   Option name (or feature slug for kind 'feature').
	 * @param string $kind 'option' | 'feature'.
	 * @return mixed
	 */
	private function snapshot( string $id, string $kind ) {
		if ( 'feature' === $kind ) {
			$store = get_option( $this->feature_store(), array() );
			return ( is_array( $store ) && array_key_exists( $id, $store ) ) ? $store[ $id ] : self::ABSENT;
		}
		$raw = get_option( $id, self::ABSENT );
		return $raw;
	}

	/**
	 * Force a toggle on or off.
	 *
	 * Options are written as the STRING '1'/'0' rather than a bool: WordPress
	 * cannot distinguish update_option($k,false) from "absent", so get_option
	 * would return the default; the string form persists deterministically and
	 * matches the admin hidden-0 toggle convention.
	 *
	 * @param string $id   Option name (or feature slug).
	 * @param string $kind 'option' | 'feature'.
	 * @param bool   $on   Desired state.
	 */
	private function set_state( string $id, string $kind, bool $on ): void {
		if ( 'feature' === $kind ) {
			$store = get_option( $this->feature_store(), array() );
			if ( ! is_array( $store ) ) {
				$store = array();
			}
			$store[ $id ] = $on;
			update_option( $this->feature_store(), $store );
			return;
		}
		update_option( $id, $on ? '1' : '0' );
	}

	/**
	 * Restore a toggle to its captured value (delete when it was never set).
	 *
	 * @param string $id       Option name (or feature slug).
	 * @param string $kind     'option' | 'feature'.
	 * @param mixed  $snapshot Value from snapshot().
	 */
	private function restore( string $id, string $kind, $snapshot ): void {
		if ( 'feature' === $kind ) {
			$store = get_option( $this->feature_store(), array() );
			if ( ! is_array( $store ) ) {
				$store = array();
			}
			if ( self::ABSENT === $snapshot ) {
				unset( $store[ $id ] );
			} else {
				$store[ $id ] = $snapshot;
			}
			update_option( $this->feature_store(), $store );
			return;
		}
		if ( self::ABSENT === $snapshot ) {
			delete_option( $id );
		} else {
			update_option( $id, $snapshot );
		}
	}

	/**
	 * Aggregate option name for kind 'feature' toggles (configurable via oracles).
	 *
	 * @return string
	 */
	private function feature_store(): string {
		$name = $this->oracles['feature_store'] ?? 'mvs_features';
		return is_string( $name ) && '' !== $name ? $name : 'mvs_features';
	}

	/**
	 * Locate and decode the route manifest from the known candidate paths.
	 *
	 * @return array<string,mixed>
	 */
	private function read_manifest(): array {
		$candidates = array(
			'audit/cert-manifest.json',
			'audit/manifests/manifest.rest.json',
			'audit/manifests/manifest.json',
			'audit/manifest.json',
		);
		foreach ( $candidates as $rel ) {
			$data = $this->read_json( $this->dir . $rel );
			if ( ! empty( $data ) ) {
				return $data;
			}
		}
		return array();
	}

	/**
	 * Decode a JSON file, returning [] on any problem.
	 *
	 * @param string $path Absolute path.
	 * @return array<string,mixed>
	 */
	private function read_json( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read in a CLI gate, not a remote request.
		if ( false === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist the machine-readable verdict to audit/cert-ledger.json.
	 *
	 * @param array<string,int>               $summary Pass/fail/hole tally.
	 * @param array<int,array<string,string>> $rows    Per-check rows.
	 * @param bool                            $ok      Overall verdict.
	 */
	private function write_ledger( array $summary, array $rows, bool $ok ): void {
		$dir = $this->dir . 'audit';
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return;
		}
		$payload = wp_json_encode(
			array(
				'ok'      => $ok,
				'summary' => $summary,
				'rows'    => $rows,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( false !== $payload ) {
			file_put_contents( $dir . '/cert-ledger.json', $payload . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local ledger write in a CLI gate.
		}
	}
}
