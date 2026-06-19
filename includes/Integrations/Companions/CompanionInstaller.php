<?php
/**
 * WPMediaVerse companion installer.
 *
 * Installs a companion plugin by reusing the EDD delivery channel the
 * companions already speak: POST the store with `edd_action=get_version` +
 * item_id + key, take the signed package URL it returns, and hand it to WP
 * core's Plugin_Upgrader. Free companions install with the baked-in free
 * distribution key (unlimited, no expiry); Pro requires the customer's own
 * valid license.
 *
 * WPMediaVerse's job ends at activation — the companion's own bundled SDK then
 * manages its updates. This never manages a companion's lifecycle after install.
 *
 * @package WPMediaVerse\Integrations\Companions
 */

namespace WPMediaVerse\Integrations\Companions;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CompanionInstaller {

	private const STORE_URL = 'https://wbcomdesigns.com';
	private const TIMEOUT   = 20;

	/**
	 * Install (and activate) a companion.
	 *
	 * @param string $slug    Companion slug.
	 * @param string $tier    'free' | 'pro'.
	 * @param string $license Customer license key (Pro only).
	 * @return true|WP_Error True on success (installed + active), WP_Error otherwise.
	 */
	public static function install( string $slug, string $tier = 'free', string $license = '' ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'wpmediaverse_cap', __( 'You do not have permission to install plugins.', 'wpmediaverse' ) );
		}

		$entry = CompanionRegistry::get( $slug );
		if ( null === $entry ) {
			return new WP_Error( 'wpmediaverse_unknown_companion', __( 'Unknown integration.', 'wpmediaverse' ) );
		}

		// Already live — nothing to do.
		if ( CompanionRegistry::is_active( $slug ) ) {
			return true;
		}

		$tier    = 'pro' === $tier ? 'pro' : 'free';
		$config  = $entry[ $tier ] ?? array();
		$item_id = (int) ( $config['item_id'] ?? 0 );
		if ( $item_id <= 0 ) {
			return new WP_Error( 'wpmediaverse_no_item', __( 'This integration cannot be installed automatically. Visit the store.', 'wpmediaverse' ) );
		}

		// Free uses the baked-in distribution key; Pro requires the customer's.
		$key = 'pro' === $tier ? trim( $license ) : (string) ( $config['key'] ?? '' );
		if ( '' === $key ) {
			return new WP_Error( 'wpmediaverse_no_license', __( 'A license key is required for this download.', 'wpmediaverse' ) );
		}

		// If the plugin is already on disk (installed_inactive), just activate it.
		$basename = (string) ( $config['basename'] ?? ( $entry['free']['basename'] ?? '' ) );
		if ( '' !== $basename && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $basename ) ) {
			return self::activate( $basename );
		}

		// EDD Software Licensing only authorizes package_download once the license
		// is activated for this domain. Activate first, and surface the store's
		// real reason if it refuses (e.g. invalid_item_id / item_name_mismatch)
		// so the failure is diagnosable instead of a generic "Download failed".
		$activation = self::activate_license( $item_id, $key );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}

		$package = self::resolve_package_url( $item_id, $key, $tier );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$installed = self::install_package( $package );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		// Activate by basename if we know it, otherwise by the freshly-installed
		// plugin's destination.
		$activate_target = '' !== $basename ? $basename : (string) $installed;
		return self::activate( $activate_target );
	}

	/**
	 * Activate the license for this domain (required before EDD authorizes the
	 * package download). Returns true when the store reports the license active
	 * for the item; a WP_Error carrying the store's own reason otherwise.
	 *
	 * @param int    $item_id Store product id.
	 * @param string $key     License / free distribution key.
	 * @return true|WP_Error
	 */
	private static function activate_license( int $item_id, string $key ) {
		$response = wp_remote_post(
			self::STORE_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'edd_action'  => 'activate_license',
					'item_id'     => $item_id,
					'license'     => $key,
					'url'         => home_url(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpmediaverse_store_unreachable', __( 'Could not reach the store to activate the license. Please try again.', 'wpmediaverse' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'wpmediaverse_store_bad_response', __( 'The store returned an unexpected response while activating the license.', 'wpmediaverse' ) );
		}

		// Already-active and valid are both fine. Anything else carries the
		// store's machine reason (invalid_item_id / item_name_mismatch / etc.).
		$status = (string) ( $body['license'] ?? '' );
		if ( in_array( $status, array( 'valid', 'active' ), true ) ) {
			return true;
		}
		if ( 'invalid' === $status && ! empty( $body['success'] ) ) {
			return true;
		}

		$reason = (string) ( $body['error'] ?? ( '' !== $status ? $status : 'unknown' ) );
		return new WP_Error(
			'wpmediaverse_license_activation_failed',
			sprintf(
				/* translators: %s: the store's activation error reason. */
				__( 'The store would not activate this free license for your site (reason: %s). This is a store-side license configuration issue, not a site error.', 'wpmediaverse' ),
				$reason
			)
		);
	}

	/**
	 * Ask the store for the signed package URL for an item.
	 *
	 * @param int    $item_id Store product id.
	 * @param string $key     License / free distribution key.
	 * @param string $tier    'free' | 'pro'.
	 * @return string|WP_Error Package URL, or WP_Error.
	 */
	private static function resolve_package_url( int $item_id, string $key, string $tier ) {
		$response = wp_remote_post(
			self::STORE_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'edd_action' => 'get_version',
					'item_id'    => $item_id,
					'license'    => $key,
					'url'        => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpmediaverse_store_unreachable', __( 'Could not reach the store. Please try again.', 'wpmediaverse' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'wpmediaverse_store_bad_response', __( 'The store returned an unexpected response.', 'wpmediaverse' ) );
		}

		// Pro must present a valid license — never auto-install on an
		// invalid/expired key; the UI shows the store link instead.
		if ( 'pro' === $tier && isset( $body['license'] ) && 'valid' !== $body['license'] ) {
			return new WP_Error( 'wpmediaverse_license_invalid', __( 'That license is not valid for this product.', 'wpmediaverse' ) );
		}

		$package = (string) ( $body['download_link'] ?? ( $body['package'] ?? '' ) );
		if ( '' === $package ) {
			return new WP_Error( 'wpmediaverse_no_package', __( 'The store did not return a download for this plugin.', 'wpmediaverse' ) );
		}

		return $package;
	}

	/**
	 * Download + unpack a plugin zip via WP core's Plugin_Upgrader.
	 *
	 * @param string $package Signed package URL.
	 * @return string|WP_Error Installed plugin basename/destination, or WP_Error.
	 */
	private static function install_package( string $package ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Resolve the filesystem method; surface a clear error rather than
		// blocking on a credentials prompt we can't render in this context.
		$creds = request_filesystem_credentials( '', '', false, false, null );
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			return new WP_Error( 'wpmediaverse_fs', __( 'WordPress needs filesystem access to install plugins. Configure direct file access or install from the Plugins screen.', 'wpmediaverse' ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			// WP's generic "download_failed" hides WHY. Probe the package URL once
			// so the message carries the store's real reason (e.g. a 401 "Invalid
			// license supplied"), which is what makes this diagnosable.
			if ( 'download_failed' === $result->get_error_code() ) {
				$probe  = wp_remote_get( $package, array( 'timeout' => self::TIMEOUT ) );
				$code   = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );
				$reason = is_wp_error( $probe ) ? $probe->get_error_message() : trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $probe ) ) );
				if ( $code >= 400 ) {
					return new WP_Error(
						'wpmediaverse_download_rejected',
						sprintf(
							/* translators: 1: HTTP status, 2: store reason text. */
							__( 'The store rejected the download (HTTP %1$d: %2$s). This is a store-side license/entitlement issue.', 'wpmediaverse' ),
							$code,
							'' !== $reason ? mb_substr( $reason, 0, 120 ) : __( 'no reason given', 'wpmediaverse' )
						)
					);
				}
			}
			return $result;
		}
		if ( true !== $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
			return new WP_Error( 'wpmediaverse_install_failed', __( 'The plugin could not be installed.', 'wpmediaverse' ) );
		}

		return (string) $upgrader->plugin_info();
	}

	/**
	 * Activate an installed plugin by basename.
	 *
	 * @param string $basename e.g. "buddynext/buddynext.php".
	 * @return true|WP_Error
	 */
	private static function activate( string $basename ) {
		if ( '' === $basename ) {
			return new WP_Error( 'wpmediaverse_activate', __( 'Installed, but the plugin could not be activated automatically. Activate it from the Plugins screen.', 'wpmediaverse' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$activated = activate_plugin( $basename );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}
		return true;
	}
}
