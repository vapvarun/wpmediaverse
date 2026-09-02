<?php
/**
 * Site Health integration.
 *
 * Registers custom health checks for WPMediaVerse.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

class HealthCheckService {

	/**
	 * Cache key for the public-readability probe.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	public const PROBE_TRANSIENT = 'mvs_media_public_probe';

	/**
	 * Canary filename written into the media directory during the probe.
	 *
	 * Deliberately NOT a dotfile. Most nginx configurations carry a blanket
	 * `location ~ /\.` deny rule, so a hidden canary answers 404 on exactly the
	 * hosts this probe exists to catch and the directory reads as protected when
	 * it is wide open. Matches Pro's `Documents\StorageResolver::CANARY`.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	public const CANARY = 'mvs-access-probe.txt';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
	}

	/**
	 * Register health check tests.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function register_tests( array $tests ): array {
		$tests['direct']['wpmediaverse_tables'] = array(
			'label' => __( 'WPMediaVerse Database Tables', 'wpmediaverse' ),
			'test'  => array( $this, 'test_tables' ),
		);

		$tests['direct']['wpmediaverse_uploads'] = array(
			'label' => __( 'WPMediaVerse Upload Directory', 'wpmediaverse' ),
			'test'  => array( $this, 'test_uploads' ),
		);

		$tests['direct']['wpmediaverse_pages'] = array(
			'label' => __( 'WPMediaVerse Required Pages', 'wpmediaverse' ),
			'test'  => array( $this, 'test_pages' ),
		);

		$tests['direct']['wpmediaverse_media_privacy'] = array(
			'label' => __( 'WPMediaVerse Media Privacy', 'wpmediaverse' ),
			'test'  => array( $this, 'test_media_privacy' ),
		);

		return $tests;
	}

	/**
	 * Test that all required DB tables exist.
	 *
	 * @return array
	 */
	public function test_tables(): array {
		global $wpdb;

		$required_tables = array(
			'mvs_reactions',
			'mvs_favorites',
			'mvs_media_views',
			'mvs_media_stats',
			'mvs_access_rules',
			'mvs_access_grants',
			'mvs_mentions',
			'mvs_album_items',
			'mvs_media_index',
			'mvs_follows',
			'mvs_notifications',
			'mvs_reports',
			'mvs_blocks',
			'mvs_activity',
			'mvs_error_log',
		);

		$missing = array();
		foreach ( $required_tables as $table ) {
			$full_table = $wpdb->prefix . $table;
			$exists     = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table )
			);
			if ( ! $exists ) {
				$missing[] = $table;
			}
		}

		if ( empty( $missing ) ) {
			return array(
				'label'       => __( 'WPMediaVerse database tables are present', 'wpmediaverse' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => 'WPMediaVerse',
					'color' => 'blue',
				),
				'description' => sprintf( '<p>%s</p>', __( 'All required database tables exist.', 'wpmediaverse' ) ),
				'test'        => 'wpmediaverse_tables',
			);
		}

		return array(
			'label'       => __( 'WPMediaVerse database tables are missing', 'wpmediaverse' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => 'WPMediaVerse',
				'color' => 'red',
			),
			'description' => sprintf(
				'<p>%s: %s</p><p>%s</p>',
				__( 'Missing tables', 'wpmediaverse' ),
				esc_html( implode( ', ', $missing ) ),
				__( 'Try deactivating and reactivating the plugin to recreate tables.', 'wpmediaverse' )
			),
			'actions'     => '',
			'test'        => 'wpmediaverse_tables',
		);
	}

	/**
	 * Test that the upload directory is writable.
	 *
	 * @return array
	 */
	public function test_uploads(): array {
		$upload_dir = wp_upload_dir();

		if ( $upload_dir['error'] ) {
			return array(
				'label'       => __( 'Upload directory is not writable', 'wpmediaverse' ),
				'status'      => 'critical',
				'badge'       => array(
					'label' => 'WPMediaVerse',
					'color' => 'red',
				),
				'description' => sprintf( '<p>%s</p>', esc_html( $upload_dir['error'] ) ),
				'test'        => 'wpmediaverse_uploads',
			);
		}

		$mvs_dir = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse';
		if ( is_dir( $mvs_dir ) && ! wp_is_writable( $mvs_dir ) ) {
			return array(
				'label'       => __( 'WPMediaVerse upload directory is not writable', 'wpmediaverse' ),
				'status'      => 'critical',
				'badge'       => array(
					'label' => 'WPMediaVerse',
					'color' => 'red',
				),
				'description' => sprintf( '<p>%s: %s</p>', __( 'Directory not writable', 'wpmediaverse' ), esc_html( $mvs_dir ) ),
				'test'        => 'wpmediaverse_uploads',
			);
		}

		return array(
			'label'       => __( 'WPMediaVerse upload directory is writable', 'wpmediaverse' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => 'WPMediaVerse',
				'color' => 'blue',
			),
			'description' => sprintf( '<p>%s</p>', __( 'Media files can be uploaded successfully.', 'wpmediaverse' ) ),
			'test'        => 'wpmediaverse_uploads',
		);
	}

	/**
	 * Test that auto-created pages exist.
	 *
	 * @return array
	 */
	public function test_pages(): array {
		$pages = array(
			'mvs_page_explore'   => __( 'Explore Media', 'wpmediaverse' ),
			'mvs_page_dashboard' => __( 'My Media', 'wpmediaverse' ),
		);

		$missing = array();
		foreach ( $pages as $option => $label ) {
			$page_id = (int) get_option( $option, 0 );
			if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
				$missing[] = $label;
			}
		}

		if ( empty( $missing ) ) {
			return array(
				'label'       => __( 'WPMediaVerse pages are set up', 'wpmediaverse' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => 'WPMediaVerse',
					'color' => 'blue',
				),
				'description' => sprintf( '<p>%s</p>', __( 'All required pages exist and are published.', 'wpmediaverse' ) ),
				'test'        => 'wpmediaverse_pages',
			);
		}

		return array(
			'label'       => __( 'WPMediaVerse pages are missing', 'wpmediaverse' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => 'WPMediaVerse',
				'color' => 'orange',
			),
			'description' => sprintf(
				'<p>%s: %s</p><p>%s</p>',
				__( 'Missing pages', 'wpmediaverse' ),
				esc_html( implode( ', ', $missing ) ),
				__( 'Try deactivating and reactivating the plugin to recreate pages.', 'wpmediaverse' )
			),
			'test'        => 'wpmediaverse_pages',
		);
	}

	/**
	 * ASK THE SERVER whether the media directory is actually protected.
	 *
	 * Writing `.htaccess` is not the same as being protected, and the difference
	 * is not theoretical: **nginx ignores `.htaccess` entirely**, so on an nginx
	 * host the `Deny from all` that Activator and LocalDriver write does nothing
	 * and a file-presence check still reports healthy. Found on the reference
	 * install by uploading an "Only me" image and fetching its stored path with
	 * no cookies: the REST route answered 403 while the file itself answered 200
	 * with its full contents.
	 *
	 * A deny rule is always safe here. Every URL MediaVerse emits — public media
	 * included, for anonymous visitors — is a signed `mvs/v1/serve` URL, so
	 * nothing on the site loads from this directory by path.
	 *
	 * This is the media-side twin of `Documents\StorageResolver::probe_public_access()`
	 * in Pro. Pro cannot call into Free-only code and Free must not depend on Pro,
	 * so the two live side by side; if a third caller ever appears, this is the
	 * copy to promote into a shared service.
	 *
	 * Cached, because it costs a loopback request: this runs from Site Health,
	 * never on a page load.
	 *
	 * @since 2.4.0
	 *
	 * @param bool $force Skip the cache.
	 * @return array{checked:bool, public:bool, status:int}
	 */
	public function probe_public_access( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::PROBE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$unchecked = array(
			'checked' => false,
			'public'  => false,
			'status'  => 0,
		);

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return $unchecked;
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'wpmediaverse';
		if ( ! wp_mkdir_p( $dir ) ) {
			return $unchecked;
		}

		$path = trailingslashit( $dir ) . self::CANARY;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, 'mvs-probe' ) ) {
			return $unchecked;
		}

		$response = wp_remote_get(
			trailingslashit( $upload['baseurl'] ) . 'wpmediaverse/' . self::CANARY,
			array(
				'timeout'     => 5,
				'sslverify'   => false,
				// A redirect to a login or a 404 page is not "readable", but
				// following it would turn a 302 into whatever it lands on.
				'redirection' => 0,
			)
		);

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $path );
		}

		if ( is_wp_error( $response ) ) {
			// Loopback blocked. Report "unchecked" rather than guessing either
			// way — claiming protection we did not verify is the failure mode
			// this whole method exists to remove.
			$result = $unchecked;
		} else {
			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );

			$result = array(
				'checked' => true,
				'public'  => ( 200 === $status && false !== strpos( $body, 'mvs-probe' ) ),
				'status'  => $status,
			);
		}

		set_transient( self::PROBE_TRANSIENT, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * The nginx rule an owner needs when the probe finds the directory public.
	 *
	 * @since 2.4.0
	 *
	 * @return string
	 */
	public function nginx_rule(): string {
		return "location ~* /wp-content/uploads/wpmediaverse/ {\n\tdeny all;\n\treturn 403;\n}";
	}

	/**
	 * Test that stored media cannot be downloaded by guessing its URL.
	 *
	 * Deliberately separate from `test_uploads()`, which answers a different
	 * question (can we WRITE here) and must keep answering it.
	 *
	 * @since 2.4.0
	 *
	 * @return array
	 */
	public function test_media_privacy(): array {
		$probe = $this->probe_public_access();

		$result = array(
			'label'       => __( 'Media files are private', 'wpmediaverse' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'wpmediaverse' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'Stored media cannot be downloaded by guessing its address. Every request goes through a permission check.', 'wpmediaverse' )
			),
			'actions'     => '',
			'test'        => 'wpmediaverse_media_privacy',
		);

		if ( ! $probe['checked'] ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Media privacy could not be confirmed', 'wpmediaverse' );
			$result['badge']       = array(
				'label' => __( 'Security', 'wpmediaverse' ),
				'color' => 'orange',
			);
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'WPMediaVerse could not reach this site over HTTP to check whether stored media is readable by anyone. This usually means loopback requests are blocked. The deny rules are in place, but on nginx they are ignored, so this is worth confirming by hand.', 'wpmediaverse' )
			);

			return $result;
		}

		if ( ! $probe['public'] ) {
			return $result;
		}

		$result['status']      = 'critical';
		$result['label']       = __( 'Media files can be downloaded by anyone with the link', 'wpmediaverse' );
		$result['badge']       = array(
			'label' => __( 'Security', 'wpmediaverse' ),
			'color' => 'red',
		);
		$result['description'] = sprintf(
			'<p>%s</p><p>%s</p>',
			__( 'Anyone can open a stored file directly by its address, without signing in and without a permission check. Media set to Only me, Members or Friends is affected, and so is anything a member made private after sharing it — the older address keeps working.', 'wpmediaverse' ),
			__( 'The deny rules WPMediaVerse writes are only read by Apache and IIS. This server appears to be nginx, which ignores them, so the rule has to be added to the server configuration instead. Nothing on the site loads media by that address, so the rule is safe to add.', 'wpmediaverse' )
		);
		$result['actions']     = '<p>' . esc_html__( 'Add this to the site\'s nginx configuration, then reload nginx:', 'wpmediaverse' )
			. '</p><pre class="mvs-health-snippet"><code>' . esc_html( $this->nginx_rule() ) . '</code></pre>';

		return $result;
	}
}
