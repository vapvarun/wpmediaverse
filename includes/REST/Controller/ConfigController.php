<?php
/**
 * App config REST controller.
 *
 * @package WPMediaVerse
 * @since   1.9.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public, pre-login app configuration endpoint.
 *
 * The single call a native/headless client makes before theming itself and
 * deciding which feature surfaces to mount. Returns only what the core
 * `/wp-json/` index cannot express (branding + feature flags); site name,
 * description, icon and auth come from the core index, never restated here.
 *
 * Branding has no Free settings today, so it is filter-driven
 * (`mvs_app_config_branding`) — Pro / a white-label add-on supplies values.
 * Feature flags are the union of Free's always-on capabilities and whatever
 * Pro contributes through `mvs_app_config_features`, so the contract stays
 * additive: a new plugin joins the map without any change here.
 */
final class ConfigController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'app';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => '__return_true',
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Build the app config payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_config( $request ) {
		// The request WAS discarded here — nothing in the payload depended on it.
		// The ETag does: `If-None-Match` is the whole point of P4.4.
		$dm_access = get_option( 'mvs_dm_access', 'everyone' );

		/**
		 * App feature flags exposed to clients via /app/config.
		 *
		 * Free seeds its always-on capabilities plus the messaging gate. Pro
		 * filters in its own toggles (battles, challenges, tournaments, boosts,
		 * streaks, video, stories, ...). Keep values boolean.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string,bool> $features Feature flag map.
		 */
		$features = (array) apply_filters(
			'mvs_app_config_features',
			array(
				'messaging'        => ! in_array( (string) $dm_access, array( 'nobody', 'disabled', 'none' ), true ),
				'reactions'        => true,
				'comments'         => true,
				'favorites'        => true,
				'albums'           => true,
				'collections'      => true,
				'follows'          => true,
				'notifications'    => true,
				'activity'         => true,

				// Safety. A native client cannot guess these, and it must not
				// render a Report control that will 403 — App Store guideline 1.2
				// wants a report path that works, and a blocked-list the member
				// can manage.
				'reporting'        => \WPMediaVerse\Social\ReportService::reports_enabled(),
				'blocking'         => true,

				// Guideline 5.1.1(v): an app that lets you create an account must
				// let you begin deleting it in-app. The client needs to know
				// whether this site's plugin is new enough to offer the endpoint,
				// so an older site degrades to a web fallback instead of showing a
				// button that 404s.
				'account_deletion' => true,

				// May a member sign in by typing their WordPress password
				// (POST /auth/app-password), or must they go through the
				// interactive approval flow? Owner switch, default on. The
				// app needs to know BEFORE it renders the control, so it
				// never offers a path this site will refuse.
				'password_login'   => \WPMediaVerse\Auth\AppCredentials::is_enabled(),
			)
		);

		/**
		 * White-label branding exposed to clients via /app/config.
		 *
		 * No Free settings drive this yet; Pro or a white-label add-on supplies
		 * values. Do NOT add site name/description/icon — those come from the
		 * core `/wp-json/` index.
		 *
		 * @since 1.9.0
		 *
		 * @param array $branding Branding values.
		 */
		$branding = (array) apply_filters(
			'mvs_app_config_branding',
			array(
				'accent_color'      => null,
				'logo_url'          => null,
				'login_bg_url'      => null,
				'dark_mode_default' => false,
			)
		);

		/**
		 * Feed layout the site owner chose for presenting their media
		 * (grid|instagram|pinterest|flickr|dribbble). Pro supplies it from
		 * `mvs_pro_feed_layout`; Free defaults to grid. The app renders the
		 * matching presentation so the app mirrors the site owner's intent.
		 *
		 * @since 1.9.0
		 *
		 * @param string $layout Layout slug.
		 */
		$layout = (string) apply_filters( 'mvs_app_config_layout', 'grid' );

		$config = array(
			'accent_color'      => $branding['accent_color'] ?? null,
			'logo_url'          => $branding['logo_url'] ?? null,
			'login_bg_url'      => $branding['login_bg_url'] ?? null,
			'dark_mode_default' => (bool) ( $branding['dark_mode_default'] ?? false ),
			'layout'            => $layout,
			'pro_active'        => defined( 'MVS_PRO_VERSION' ),
			'features'          => array_map( 'boolval', $features ),
			'legal'             => $this->get_legal(),

			// How this SITE signs a member into the app (Wbcom App Auth
			// standard shape — one reader in the app serves every Wbcom
			// product). On combined sites `connect_url` is BuddyNext's
			// bridge; standalone it is empty and the app routes through
			// core's authorize screen or the credentials exchange
			// (`features.password_login` above is that switch).
			'auth'              => \WPMediaVerse\Auth\AppConnect::auth_block(),
		);

		/**
		 * The whole app config, after Free has assembled its own.
		 *
		 * Pro adds its `documents` block here so the app never hardcodes the
		 * format list, the size ceiling or the preview tiers — a client that
		 * guesses those disagrees with the server the moment an owner changes a
		 * setting, and disagreeing about the size ceiling means uploading a file
		 * only to be told 413 at the end (design §9).
		 *
		 * @since 2.4.0
		 *
		 * @param array $config Assembled config payload.
		 */
		$config = (array) apply_filters( 'mvs_app_config', $config );

		return $this->respond_with_etag( $config, $request );
	}

	/**
	 * Send the config with an ETag, answering 304 when nothing changed.
	 *
	 * The app fetches this on every cold start. It is a handful of options and a
	 * few filters, so the cost is not the query — it is the payload crossing a
	 * mobile connection to say nothing new. An ETag turns that into an empty 304.
	 *
	 * The tag is derived from the RESPONSE ITSELF rather than from a version
	 * constant or a timestamp, so it changes exactly when what the client would
	 * receive changes — including when a filter somewhere alters it. A tag keyed
	 * on anything else eventually claims "unchanged" about a payload that did.
	 *
	 * @since 2.4.0
	 *
	 * @param array           $config  Config payload.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function respond_with_etag( array $config, WP_REST_Request $request ) {
		$etag = '"' . md5( (string) wp_json_encode( $config ) ) . '"';

		// The route always hands us a WP_REST_Request, so no defensive branch.
		$candidates = (string) $request->get_header( 'if_none_match' );

		// A client may send several tags, and some proxies weaken them with a
		// `W/` prefix. Compare each candidate rather than the raw header.
		foreach ( array_map( 'trim', explode( ',', $candidates ) ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			if ( ltrim( $candidate, 'W/' ) === ltrim( $etag, 'W/' ) ) {
				$response = new \WP_REST_Response( null, 304 );
				$response->header( 'ETag', $etag );
				$response->header( 'Cache-Control', 'private, must-revalidate' );

				return $response;
			}
		}

		$response = rest_ensure_response( $config );
		$response->header( 'ETag', $etag );
		// must-revalidate, not a max-age: a config the client keeps for an hour
		// is a config an owner cannot turn off for an hour.
		$response->header( 'Cache-Control', 'private, must-revalidate' );

		return $response;
	}

	/**
	 * The site's legal and safety surface, for a native client.
	 *
	 * App Store guideline 1.2 expects a UGC app to make its privacy policy
	 * reachable *inside* the app and to publish a contact for abuse reports.
	 * These belong to the community, not to us: each site owns its own data, its
	 * own moderators and its own terms, so a single hardcoded Wbcom policy would
	 * be a lie.
	 *
	 * `privacy_policy_url` defaults to WordPress core's own
	 * get_privacy_policy_url(). Every install already has that field, and most
	 * owners have filled it in — which is what makes this scale to thousands of
	 * sites with zero configuration.
	 *
	 * `abuse_contact_email` defaults to the site admin's address: on a UGC site
	 * somebody must receive abuse reports, and the owner is that somebody until
	 * they nominate a moderator.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string, string|null>
	 */
	private function get_legal(): array {
		$privacy = get_privacy_policy_url();

		$abuse = (string) get_option( 'mvs_abuse_contact_email', '' );

		$legal = array(
			'privacy_policy_url'       => '' !== $privacy ? $privacy : null,
			'terms_url'                => (string) get_option( 'mvs_terms_url', '' ),
			'eula_url'                 => (string) get_option( 'mvs_eula_url', '' ),
			'community_guidelines_url' => (string) get_option( 'mvs_guidelines_url', '' ),
			'abuse_contact_email'      => '' !== $abuse ? $abuse : (string) get_option( 'admin_email' ),
		);

		/**
		 * Filter the legal/safety URLs handed to native clients.
		 *
		 * A site with no privacy policy set returns null for it, and the client
		 * falls back to its own. Never return a placeholder: a policy link that
		 * goes nowhere is worse than one the client can substitute.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string, string|null> $legal Legal URLs and the abuse contact.
		 */
		$legal = (array) apply_filters( 'mvs_app_config_legal', $legal );

		foreach ( array( 'privacy_policy_url', 'terms_url', 'eula_url', 'community_guidelines_url' ) as $key ) {
			$legal[ $key ] = ! empty( $legal[ $key ] ) ? esc_url_raw( (string) $legal[ $key ] ) : null;
		}

		$legal['abuse_contact_email'] = ! empty( $legal['abuse_contact_email'] )
			? sanitize_email( (string) $legal['abuse_contact_email'] )
			: null;

		return $legal;
	}

	/**
	 * Response schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'mvs_app_config',
			'type'       => 'object',
			'properties' => array(
				'accent_color'      => array(
					'type'    => array( 'string', 'null' ),
					'context' => array( 'view' ),
				),
				'logo_url'          => array(
					'type'    => array( 'string', 'null' ),
					'context' => array( 'view' ),
				),
				'login_bg_url'      => array(
					'type'    => array( 'string', 'null' ),
					'context' => array( 'view' ),
				),
				'dark_mode_default' => array(
					'type'    => 'boolean',
					'context' => array( 'view' ),
				),
				'pro_active'        => array(
					'type'    => 'boolean',
					'context' => array( 'view' ),
				),
				'features'          => array(
					'type'                 => 'object',
					'context'              => array( 'view' ),
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
