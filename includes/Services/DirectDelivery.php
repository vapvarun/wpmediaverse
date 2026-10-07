<?php
/**
 * Direct media delivery: serve local media files straight from the web server.
 *
 * Every image and video used to go through the signed /serve REST route, a full
 * WordPress boot per file (160-220 queries, ~200ms on a typical family site;
 * one customer's small community ran 70 PHP workers). Media files already have
 * unguessable names (16 random hex characters), so a viewer who may see an item
 * can be given the file's own URL, the capability-URL model large social CDNs
 * use. Privacy is kept by never minting a URL for a viewer who cannot see the
 * item, and by renaming an item's files when its privacy is tightened.
 *
 * Fail-safe by design (MediaVerse runs on many live sites): the direct path is
 * used only after a probe proves this site's web server serves a random-named
 * media file from the folder, and only for files whose own names are random.
 * Anything else keeps the signed /serve URL, exactly as before.
 *
 * @package WPMediaVerse
 * @since   2.6.1
 */

declare( strict_types=1 );

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Probe, decision and protection-file content for direct delivery.
 */
final class DirectDelivery {

	/**
	 * Stored probe result: array{ok: bool, status: int, checked_at: int}.
	 */
	public const OPTION = 'mvs_direct_delivery';

	/**
	 * A file name MediaVerse generated: 16+ random hex characters, an optional
	 * size suffix, a media extension. Only such names may be served directly.
	 * One pattern for the PHP check, the Apache .htaccess and the nginx rule.
	 */
	public const NAME_PATTERN = '[0-9a-f]{16,}(-[0-9]+x[0-9]+)?\.(jpe?g|png|gif|webp|avif|mp4|m4v|mov|webm|ogv|mp3|m4a|ogg|oga|opus|wav|flac|aac)';

	/**
	 * NAME_PATTERN as a PHP regex for a whole file name.
	 */
	public const RANDOM_NAME = '/^' . self::NAME_PATTERN . '$/i';

	/**
	 * The .htaccess every older MediaVerse wrote into the folder (deny all).
	 */
	public const LEGACY_HTACCESS = "Order deny,allow\nDeny from all\n";

	/**
	 * First characters of every .htaccess this class writes.
	 */
	private const HTACCESS_MARKER = '# WPMediaVerse:';

	/**
	 * Re-probe at most this often.
	 */
	private const PROBE_EVERY = DAY_IN_SECONDS;

	/**
	 * May local files be served directly on this site?
	 *
	 * True only when the last probe proved it. Never probes itself: probing is a
	 * loopback HTTP request, so it runs from admin and cron (maybe_probe()), not
	 * on a visitor's request.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$state = get_option( self::OPTION, array() );
		$ok    = is_array( $state ) && ! empty( $state['ok'] );

		/**
		 * Filter whether MediaVerse serves local media files directly.
		 *
		 * Defaults to the probe result. Return false to keep every file on the
		 * signed /serve route.
		 *
		 * @since 2.6.1
		 *
		 * @param bool $ok Whether the probe proved direct delivery works here.
		 */
		return (bool) apply_filters( 'mvs_direct_media_delivery', $ok );
	}

	/**
	 * Is this a file name MediaVerse may serve directly?
	 *
	 * @param string $rel_path Path relative to uploads/wpmediaverse/.
	 * @return bool
	 */
	public static function is_random_name( string $rel_path ): bool {
		return 1 === preg_match( self::RANDOM_NAME, basename( $rel_path ) );
	}

	/**
	 * Re-run the probe when it is due, from admin page loads and cron only.
	 *
	 * @return void
	 */
	public static function maybe_probe(): void {
		if ( ! self::is_scheduling_request() ) {
			return;
		}
		$state = get_option( self::OPTION, array() );
		if ( is_array( $state ) && (int) ( $state['checked_at'] ?? 0 ) > time() - self::PROBE_EVERY ) {
			return;
		}
		self::probe();
	}

	/**
	 * Admin page loads and cron only: where loopback probes and job checks may
	 * run without costing a visitor's request.
	 *
	 * @return bool
	 */
	public static function is_scheduling_request(): bool {
		return wp_doing_cron() || ( is_admin() && ! wp_doing_ajax() );
	}

	/**
	 * Prove that the web server serves a random-named media file from the folder.
	 *
	 * Writes a 1x1 GIF under a random name, fetches it over HTTP, deletes it.
	 * Anything but a 200 with the exact bytes (denied by an old nginx rule or a
	 * customised .htaccess, a blocked loopback, a redirect) leaves direct
	 * delivery off, so the site keeps today's /serve URLs.
	 *
	 * @return array{ok: bool, status: int, checked_at: int}
	 */
	public static function probe(): array {
		$result = array(
			'ok'         => false,
			'status'     => 0,
			'checked_at' => time(),
		);
		$upload = wp_upload_dir();
		$dir    = empty( $upload['error'] ) ? trailingslashit( $upload['basedir'] ) . 'wpmediaverse/' : '';
		if ( '' !== $dir && wp_mkdir_p( $dir ) ) {
			self::ensure_htaccess( $dir );
			$name  = bin2hex( random_bytes( 8 ) ) . '.gif';
			$bytes = base64_decode( 'R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixed 1x1 GIF.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $dir . $name, $bytes ) ) {
				$response = wp_remote_get(
					trailingslashit( $upload['baseurl'] ) . 'wpmediaverse/' . $name,
					array(
						'timeout'     => 5,
						'sslverify'   => false,
						'redirection' => 0,
					)
				);
				wp_delete_file( $dir . $name );
				if ( ! is_wp_error( $response ) ) {
					$result['status'] = (int) wp_remote_retrieve_response_code( $response );
					$result['ok']     = 200 === $result['status'] && wp_remote_retrieve_body( $response ) === $bytes;
				}
			}
		}
		update_option( self::OPTION, $result, true );
		return $result;
	}

	/**
	 * The folder's .htaccess: deny everything except random-named media files.
	 *
	 * Readable names (legacy uploads, old posters/<id>.jpg, imports) stay as
	 * protected as before; only names MediaVerse generated are reachable. No
	 * `Options` line: hosts that disallow it answer 500 for the whole folder,
	 * and the deny already refuses folder listings.
	 *
	 * @return string
	 */
	public static function htaccess(): string {
		$allow = '"(?i)^' . self::NAME_PATTERN . '$"';
		return self::HTACCESS_MARKER . " only files with MediaVerse's random names are served directly.\n"

			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "\t<FilesMatch {$allow}>\n"
			. "\t\tRequire all granted\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder deny,allow\n"
			. "\tDeny from all\n"
			. "\t<FilesMatch {$allow}>\n"
			. "\t\tAllow from all\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n";
	}

	/**
	 * The nginx equivalent of htaccess(): deny the folder except random-named
	 * media. nginx ignores .htaccess, so owners add this to the server config.
	 *
	 * `^~` stops nginx from trying the site's own regex locations (a common
	 * `location ~* \.(jpg|png)$` block would otherwise serve the folder openly),
	 * and the nested location must re-allow because access rules are inherited.
	 *
	 * @return string
	 */
	public static function nginx_rule(): string {
		$upload = wp_upload_dir();
		$path   = (string) wp_parse_url( (string) $upload['baseurl'], PHP_URL_PATH );
		return 'location ^~ ' . untrailingslashit( '' === $path ? '/wp-content/uploads' : $path ) . "/wpmediaverse/ {\n"
			. "\tdeny all;\n"
			. "\tlocation ~* \"/" . self::NAME_PATTERN . "$\" {\n"
			. "\t\tallow all;\n"
			. "\t}\n"
			. '}';
	}

	/**
	 * Write the protection .htaccess when it is missing, is the deny-all file
	 * older versions wrote, or is one this class wrote (marker line) that has
	 * since changed. A file an owner customised is never touched.
	 *
	 * @param string $dir Absolute folder path with trailing slash.
	 * @return void
	 */
	public static function ensure_htaccess( string $dir ): void {
		$path    = $dir . '.htaccess';
		$current = file_exists( $path ) ? (string) file_get_contents( $path ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$ours    = null === $current || self::LEGACY_HTACCESS === $current || 0 === strpos( $current, self::HTACCESS_MARKER );
		if ( $ours && self::htaccess() !== $current ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, self::htaccess() );
		}
	}
}
