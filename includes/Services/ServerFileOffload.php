<?php
/**
 * Hand file streaming on the signed /serve route to the web server.
 *
 * After 2.6.1's direct delivery, /serve still carries what must be checked per
 * request: message attachments, documents, SVGs, downloads, and every file on a
 * site where direct delivery is off. PHP checked permission and then streamed
 * the bytes itself, holding a worker for the whole transfer (~160 ms for a small
 * file, minutes for a long video).
 *
 * With this on, PHP still makes every decision (permission, download counter,
 * headers, ETag/304) and then sends X-Accel-Redirect (nginx) or X-Sendfile
 * (Apache with mod_xsendfile); the web server sends the file, Range included.
 *
 * Opt-in only, because the server needs configuration: define MVS_SERVE_OFFLOAD
 * as 'x-accel' or 'x-sendfile' (or use the mvs_serve_offload filter). It is used
 * only after a probe proves this server honours the header; until then, and
 * whenever the probe fails, PHP streams exactly as before.
 *
 * @package WPMediaVerse
 * @since   2.6.1
 */

declare( strict_types=1 );

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Mode, probe and header emission for server file offload.
 */
final class ServerFileOffload {

	/**
	 * Stored probe result: array{mode: string, ok: bool, status: int, checked_at: int}.
	 */
	public const OPTION = 'mvs_serve_offload';

	/**
	 * Single-use probe token prefix (transient).
	 */
	private const TOKEN_PREFIX = 'mvs_offload_probe_';

	/**
	 * Re-probe at most this often.
	 */
	private const PROBE_EVERY = DAY_IN_SECONDS;

	/**
	 * The modes this class can emit.
	 */
	private const MODES = array( 'x-accel', 'x-sendfile' );

	/**
	 * The sandbox CSP the probe requires to survive the hand-off (the strictest
	 * one MediaVerse sends, from document delivery).
	 */
	public const PROBE_CSP = "default-src 'none'; sandbox";

	/**
	 * Extensions the probe hands over, each of which must come back. Ones a
	 * stock nginx config already has a regex location for, and that MediaVerse
	 * really serves this way: a photo, and an SVG (always served through PHP).
	 *
	 * @var string[]
	 */
	private const PROBE_EXTENSIONS = array( 'jpg', 'svg' );

	/**
	 * The mode the owner asked for, or '' when offload is not configured.
	 *
	 * @return string 'x-accel', 'x-sendfile' or ''.
	 */
	public static function configured_mode(): string {
		$mode = defined( 'MVS_SERVE_OFFLOAD' ) ? (string) MVS_SERVE_OFFLOAD : '';

		/**
		 * Filter which web server offload /serve uses.
		 *
		 * Return 'x-accel' (nginx, with an internal location; see
		 * nginx_location()) or 'x-sendfile' (Apache with mod_xsendfile and
		 * XSendFilePath set to the uploads folder). Anything else turns it off.
		 * It is used only after the probe proves the server honours it.
		 *
		 * @since 2.6.1
		 *
		 * @param string $mode Mode from the MVS_SERVE_OFFLOAD constant, or ''.
		 */
		$mode = (string) apply_filters( 'mvs_serve_offload', $mode );

		return in_array( $mode, self::MODES, true ) ? $mode : '';
	}

	/**
	 * The mode to use right now: configured AND proven by the last probe.
	 *
	 * @return string
	 */
	public static function active_mode(): string {
		$mode = self::configured_mode();
		if ( '' === $mode ) {
			return '';
		}
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) && ! empty( $state['ok'] ) && $mode === ( $state['mode'] ?? '' ) ? $mode : '';
	}

	/**
	 * Hand the file to the web server, if offload is active.
	 *
	 * Call after every header (type, disposition, cache, ETag) is set and the
	 * 304 check has run. Returns true when the caller must exit without
	 * sending a body; false means "stream it yourself, as before".
	 *
	 * @param string $abs_path Absolute path of the file to send.
	 * @return bool
	 */
	public static function send( string $abs_path ): bool {
		$mode = self::active_mode();

		return '' !== $mode && self::emit( $mode, $abs_path );
	}

	/**
	 * The nginx internal location prefix X-Accel-Redirect points at.
	 *
	 * @return string With leading and trailing slash.
	 */
	public static function nginx_prefix(): string {
		/**
		 * Filter the internal nginx location used for X-Accel-Redirect.
		 *
		 * @since 2.6.1
		 *
		 * @param string $prefix Location name. Default 'mvs-internal-files'.
		 */
		$prefix = trim( (string) apply_filters( 'mvs_serve_offload_nginx_prefix', 'mvs-internal-files' ), '/' );

		return '/' . ( '' === $prefix ? 'mvs-internal-files' : $prefix ) . '/';
	}

	/**
	 * The nginx block an owner adds for x-accel mode.
	 *
	 * @return string
	 */
	public static function nginx_location(): string {
		$upload = wp_upload_dir();

		// `^~`: a plain prefix location loses to the regex locations nearly every
		// WordPress nginx config has for images, CSS and fonts, and the redirect
		// for a .jpg or .svg would fall through to WordPress. The alias is quoted
		// because an uploads path with a space is otherwise two arguments, which
		// fails `nginx -t` and takes the site down on reload.
		//
		// nginx forwards only a few upstream headers on an internal redirect, so
		// the security headers PHP sets (SVGs, documents) are added here. A CSP
		// on an embedded image or video has no effect; it only sandboxes a file
		// opened directly. The probe refuses offload without them.
		return 'location ^~ ' . self::nginx_prefix() . " {\n"
			. "\tinternal;\n"
			. "\talias \"" . addcslashes( trailingslashit( (string) $upload['basedir'] ), '"\\' ) . "\";\n"
			. "\tadd_header X-Content-Type-Options \"nosniff\" always;\n"
			. "\tadd_header Content-Security-Policy \"" . self::PROBE_CSP . "\" always;\n"
			. '}';
	}

	/**
	 * Re-run the probe when offload is configured and the probe is due.
	 * Admin page loads and cron only, like DirectDelivery::maybe_probe().
	 *
	 * @return void
	 */
	public static function maybe_probe(): void {
		$mode = self::configured_mode();
		if ( '' === $mode || ! DirectDelivery::is_scheduling_request() ) {
			return;
		}
		$state = get_option( self::OPTION, array() );
		if ( is_array( $state ) && $mode === ( $state['mode'] ?? '' ) && (int) ( $state['checked_at'] ?? 0 ) > time() - self::PROBE_EVERY ) {
			return;
		}
		self::probe();
	}

	/**
	 * Prove the web server sends a file when PHP hands it over.
	 *
	 * Writes random bytes to a random file in the uploads folder, asks the probe
	 * route (single-use token) for it over HTTP and compares the body. PHP sends
	 * no body on that route, so the bytes can only arrive if the server honoured
	 * the header. Anything else leaves offload off.
	 *
	 * @return array{mode: string, ok: bool, status: int, checked_at: int}
	 */
	public static function probe(): array {
		$result = array(
			'mode'       => self::configured_mode(),
			'ok'         => false,
			'status'     => 0,
			'checked_at' => time(),
		);
		$upload = wp_upload_dir();
		$dir    = empty( $upload['error'] ) ? trailingslashit( $upload['basedir'] ) . 'wpmediaverse/' : '';

		if ( '' !== $result['mode'] && '' !== $dir && wp_mkdir_p( $dir ) ) {
			// One file per extension, and every one must come back. A typical
			// nginx config has regex locations for images, CSS and fonts (SVG
			// among them); without `^~` on the internal location those win the
			// redirect and the real download fails. A probe file with a neutral
			// extension passed on exactly that setup, so the probe uses the
			// extensions real media has.
			foreach ( self::PROBE_EXTENSIONS as $extension ) {
				$one              = self::probe_one( $dir, $extension );
				$result['status'] = $one['status'];
				$result['ok']     = $one['ok'];
				if ( ! $one['ok'] ) {
					break;
				}
			}
		}

		update_option( self::OPTION, $result, true );

		return $result;
	}

	/**
	 * Hand one random file over to the web server and check what comes back.
	 *
	 * @param string $dir       Folder for the probe file, with a trailing slash.
	 * @param string $extension File extension to probe with.
	 * @return array{ok: bool, status: int}
	 */
	private static function probe_one( string $dir, string $extension ): array {
		$one   = array(
			'ok'     => false,
			'status' => 0,
		);
		$file  = $dir . bin2hex( random_bytes( 12 ) ) . '.' . $extension;
		$bytes = random_bytes( 64 );
		$token = bin2hex( random_bytes( 16 ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file, $bytes ) ) {
			return $one;
		}

		set_transient( self::TOKEN_PREFIX . $token, $file, MINUTE_IN_SECONDS );
		$response = wp_remote_get(
			rest_url( 'mvs/v1/offload-probe/' . $token ),
			array(
				'timeout'     => 5,
				'sslverify'   => false,
				'redirection' => 0,
			)
		);
		delete_transient( self::TOKEN_PREFIX . $token );
		wp_delete_file( $file );

		if ( is_wp_error( $response ) ) {
			return $one;
		}

		$one['status'] = (int) wp_remote_retrieve_response_code( $response );
		// The bytes, AND the security headers: SVGs and documents rely on
		// nosniff + a sandbox CSP, and nginx drops PHP's on the redirect
		// unless its location adds them. No headers, no offload.
		$csp       = (string) wp_remote_retrieve_header( $response, 'content-security-policy' );
		$nosniff   = 'nosniff' === strtolower( trim( (string) wp_remote_retrieve_header( $response, 'x-content-type-options' ) ) );
		$one['ok'] = 200 === $one['status']
			&& wp_remote_retrieve_body( $response ) === $bytes
			&& $nosniff
			&& false !== strpos( $csp, 'sandbox' );

		return $one;
	}

	/**
	 * Register the probe route.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			'mvs/v1',
			'/offload-probe/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'serve_probe' ),
				// Public by design: the loopback has no session. It serves only the
				// probe file a 32-hex single-use token maps to, for one minute.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answer the probe: headers only, never the bytes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Error Only when the token is unknown.
	 */
	public static function serve_probe( \WP_REST_Request $request ) {
		$file = get_transient( self::TOKEN_PREFIX . (string) $request['token'] );
		$mode = self::configured_mode();

		if ( ! is_string( $file ) || ! is_file( $file ) || '' === $mode ) {
			return new \WP_Error( 'mvs_offload_probe', 'Unknown probe.', array( 'status' => 404 ) );
		}

		header( 'Content-Type: application/octet-stream' );
		// The headers real responses carry; the probe checks they survive.
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: ' . self::PROBE_CSP );
		if ( self::emit( $mode, $file ) ) {
			exit;
		}

		return new \WP_Error( 'mvs_offload_probe', 'Unknown probe.', array( 'status' => 404 ) );
	}

	/**
	 * Emit the offload header for a file inside the uploads folder.
	 *
	 * @param string $mode     x-accel|x-sendfile.
	 * @param string $abs_path Absolute path.
	 * @return bool False when the file is outside uploads (never offloaded).
	 */
	private static function emit( string $mode, string $abs_path ): bool {
		$real = realpath( $abs_path );
		$base = realpath( (string) wp_upload_dir()['basedir'] );

		if ( false === $real || false === $base || 0 !== strpos( $real, trailingslashit( $base ) ) ) {
			return false;
		}

		// The server works out the length (and the range); a PHP Content-Length
		// would describe a body PHP never sends.
		header_remove( 'Content-Length' );

		if ( 'x-sendfile' === $mode ) {
			header( 'X-Sendfile: ' . $real );
		} else {
			$relative = ltrim( str_replace( '\\', '/', substr( $real, strlen( $base ) ) ), '/' );
			header( 'X-Accel-Redirect: ' . self::nginx_prefix() . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) ) );
		}

		return true;
	}
}
