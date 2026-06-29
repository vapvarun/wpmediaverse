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
		unset( $request );

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
				'messaging'     => ! in_array( (string) $dm_access, array( 'nobody', 'disabled', 'none' ), true ),
				'reactions'     => true,
				'comments'      => true,
				'favorites'     => true,
				'albums'        => true,
				'collections'   => true,
				'follows'       => true,
				'notifications' => true,
				'activity'      => true,
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

		$config = array(
			'accent_color'      => $branding['accent_color'] ?? null,
			'logo_url'          => $branding['logo_url'] ?? null,
			'login_bg_url'      => $branding['login_bg_url'] ?? null,
			'dark_mode_default' => (bool) ( $branding['dark_mode_default'] ?? false ),
			'pro_active'        => defined( 'MVS_PRO_VERSION' ),
			'features'          => array_map( 'boolval', $features ),
		);

		return rest_ensure_response( $config );
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
