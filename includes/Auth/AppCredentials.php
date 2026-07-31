<?php
/**
 * Trade a member's ordinary WordPress login for an Application Password.
 *
 * WordPress core will not do this exchange: its Basic auth handler validates
 * against stored application passwords ONLY, so the core route that mints one
 * already requires one, and every core path to a member's FIRST credential
 * runs through a wp-admin screen under cookie auth. Members have a WordPress
 * password and expect to type it — this route is what lets them.
 *
 * NOT a second authentication system. `wp_authenticate()` does the actual
 * authentication, exactly as wp-login.php does, so every `authenticate`
 * filter on the site still runs. This class only decides what to hand back
 * once core has said yes — and it refuses to hand anything to a suspended
 * member (`RestGuards::deny_if_suspended`), because a login gate cannot
 * revoke a credential the member would then hold.
 *
 * THE RISK, STATED PLAINLY: this route accepts a real account password,
 * which makes it a brute-force oracle in a way wp-login.php is not (that
 * page inherits whatever protection the site has installed). It is
 * therefore TLS-gated, rate-limited per IP AND per username before any
 * credential is read (failures only — an honest member's typo is cleared on
 * success), uniform in its failure message so it cannot enumerate accounts,
 * off when the owner turns it off, and silent about the password in every
 * log and error path.
 *
 * Ported from the Learnomy implementation of the Wbcom App Auth standard.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Auth;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_User;
use WPMediaVerse\REST\RestGuards;

/**
 * The credentials-for-app-password exchange.
 *
 * @since 2.3.0
 */
class AppCredentials {

	/**
	 * Failed attempts allowed per bucket before lockout.
	 */
	private const MAX_FAILURES = 5;

	/**
	 * Failure-count window (and lockout length) in seconds.
	 */
	private const FAILURE_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Error codes that mean "those credentials were wrong".
	 *
	 * Anything else from `wp_authenticate()` means the site rejected the
	 * sign-in for its OWN reason — a second factor, a security plugin — which
	 * is a different answer and gets a different status. Collapsed so the
	 * response cannot answer "does this account exist?".
	 */
	private const CREDENTIAL_FAILURE_CODES = array(
		'invalid_username',
		'invalid_email',
		'incorrect_password',
		'authentication_failed',
	);

	/**
	 * Is the credentials exchange available on this site?
	 *
	 * Owner switch, default ON. An owner running 2FA will want every member
	 * on the interactive flow, and turning this off is how they say so.
	 */
	public static function is_enabled(): bool {
		$on = (bool) get_option( 'mvs_app_password_login', true );

		/**
		 * Filter whether members may exchange a WordPress password for an
		 * Application Password.
		 *
		 * @since 2.3.0
		 *
		 * @param bool $on Whether the exchange is available.
		 */
		return (bool) apply_filters( 'mvs_app_password_login_enabled', $on );
	}

	/**
	 * Exchange credentials for an Application Password.
	 *
	 * @param string $username Username or email.
	 * @param string $password The member's account password.
	 * @param string $app_name Label shown in the member's profile.
	 * @param string $app_id   Stable per-install id; `AppConnect`'s pruner
	 *                         keys reconnect-replacement on it.
	 * @return array{user_login:string,password:string,app_id:string}|WP_Error
	 */
	public static function exchange( string $username, string $password, string $app_name, string $app_id ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'mvs_app_passwords_off',
				__( 'This site has turned off app sign-in. Ask the site owner to enable it.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		// Core refuses to issue Application Passwords without TLS, and so
		// must we: this request carries the real account password. A local
		// dev environment is the documented exception, and it is core's own.
		if ( ! wp_is_application_passwords_available() ) {
			return new WP_Error(
				'mvs_app_passwords_off',
				__( 'This site cannot issue app passwords. It needs a secure (https) connection.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			return self::translate_auth_error( $user );
		}

		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'mvs_login_failed',
				__( 'The username or password you entered is incorrect.', 'wpmediaverse' ),
				array( 'status' => 401 )
			);
		}

		// `wp_authenticate()` does not log anyone in, but a site's own
		// filters may have primed a session. The app is a stateless client;
		// the credential it gets back IS the session.
		wp_clear_auth_cookie();

		// A suspended member must not mint. The REST write guards would stop
		// their writes anyway, but handing a suspended member a fresh live
		// credential is the wrong answer on its face.
		$suspended = RestGuards::deny_if_suspended( (int) $user->ID );
		if ( $suspended instanceof WP_Error ) {
			return $suspended;
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error(
				'mvs_app_passwords_off',
				__( 'App sign-in is not available for this account.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		// No pre-prune needed: AppConnect::forget_older_installs() runs on
		// core's wp_create_application_password action and replaces older
		// rows for this app_id, whichever door minted the new one.
		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name'   => $app_name,
				'app_id' => $app_id,
			)
		);

		if ( is_wp_error( $created ) ) {
			return new WP_Error(
				'mvs_app_passwords_off',
				__( 'This site could not issue an app password. Ask the site owner to check their settings.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Fires after a member exchanges their password for an app
		 * credential. The credential itself is deliberately NOT passed.
		 *
		 * @since 2.3.0
		 *
		 * @param int    $user_id  Member who signed in.
		 * @param string $app_id   Stable per-install id.
		 * @param string $app_name Label stored on the credential.
		 */
		do_action( 'mvs_app_credential_issued', $user->ID, $app_id, $app_name );

		return array(
			'user_login' => $user->user_login,
			'password'   => $created[0],
			'app_id'     => $app_id,
		);
	}

	/**
	 * Is this bucket (ip:… or user:…) currently locked out?
	 *
	 * @param string $bucket Bucket key.
	 */
	public static function is_locked_out( string $bucket ): bool {
		$data = get_transient( self::failure_key( $bucket ) );

		return is_array( $data )
			&& ( $data['count'] ?? 0 ) >= self::MAX_FAILURES
			&& ( time() - (int) ( $data['start'] ?? 0 ) ) < self::FAILURE_WINDOW;
	}

	/**
	 * Count a rejected CREDENTIAL against a bucket.
	 *
	 * Only wrong passwords count — a 2FA hand-off, a suspension or a
	 * disabled switch are all "your password was fine", and counting those
	 * would lock out members who did nothing wrong.
	 *
	 * @param string $bucket Bucket key.
	 */
	public static function record_failure( string $bucket ): void {
		$key  = self::failure_key( $bucket );
		$data = get_transient( $key );

		if ( ! is_array( $data ) || ( time() - (int) ( $data['start'] ?? 0 ) ) >= self::FAILURE_WINDOW ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		++$data['count'];
		set_transient( $key, $data, self::FAILURE_WINDOW );
	}

	/**
	 * An honest member who mistyped twice should not carry those failures.
	 *
	 * @param string $bucket Bucket key.
	 */
	public static function clear_failures( string $bucket ): void {
		delete_transient( self::failure_key( $bucket ) );
	}

	/**
	 * Transient key for a bucket's failure counter.
	 *
	 * @param string $bucket Bucket key.
	 */
	private static function failure_key( string $bucket ): string {
		return 'mvs_apw_fail_' . md5( $bucket );
	}

	/**
	 * Decide what a failed `wp_authenticate()` actually means.
	 *
	 * A wrong password and "this site wants a second factor" are different
	 * answers and must not collapse into one: reporting a 2FA block as a bad
	 * password sends the member round a failing loop; reporting it as
	 * success walks them past a factor the owner configured. Known
	 * credential codes become a uniform 401; everything else becomes a 409
	 * telling the app to hand off to the interactive flow, which CAN
	 * complete a second step.
	 *
	 * @param WP_Error $error The error `wp_authenticate()` returned.
	 * @return WP_Error
	 */
	private static function translate_auth_error( WP_Error $error ): WP_Error {
		foreach ( $error->get_error_codes() as $code ) {
			if ( in_array( $code, self::CREDENTIAL_FAILURE_CODES, true ) ) {
				// Uniform on purpose: `invalid_username` vs
				// `incorrect_password` is exactly the oracle that answers
				// "does this account exist?".
				return new WP_Error(
					'mvs_login_failed',
					__( 'The username or password you entered is incorrect.', 'wpmediaverse' ),
					array( 'status' => 401 )
				);
			}
		}

		return new WP_Error(
			'mvs_second_factor',
			__( 'This site needs another step to sign you in. Use the WordPress approval screen instead.', 'wpmediaverse' ),
			array( 'status' => 409 )
		);
	}
}
