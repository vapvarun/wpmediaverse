<?php
/**
 * AI service.
 *
 * Orchestrates AI providers for media analysis, tagging, and moderation.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Orchestrates AI-powered media analysis via pluggable providers.
 */
class AIService {

	/**
	 * Registered AI providers.
	 *
	 * @var AIProviderInterface[]
	 */
	private $providers = array();

	/**
	 * Register an AI provider.
	 *
	 * @param AIProviderInterface $provider Provider instance.
	 */
	public function register_provider( AIProviderInterface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get the active provider based on settings.
	 *
	 * @return AIProviderInterface|null
	 */
	public function get_active_provider(): ?AIProviderInterface {
		$provider_id = get_option( 'mvs_ai_provider', 'openai' );

		// Back-compat: legacy stored value `google` predates the registered
		// provider id `google_vision`. Without this, an admin who picked Google
		// Vision silently got the OpenAI fallback below. Saved option values do
		// not re-run the sanitizer, so normalize on read too.
		if ( 'google' === $provider_id ) {
			$provider_id = 'google_vision';
		}

		if ( isset( $this->providers[ $provider_id ] ) && $this->providers[ $provider_id ]->is_available() ) {
			return $this->providers[ $provider_id ];
		}

		// Fallback to any available provider.
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_available() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Analyze a media item's image.
	 *
	 * @param int $media_id Media post ID.
	 * @return array|WP_Error Analysis result or error.
	 */
	public function analyze( int $media_id ) {
		$provider = $this->get_active_provider();
		if ( ! $provider ) {
			return new WP_Error( 'mvs_no_ai_provider', __( 'No AI provider is configured.', 'wpmediaverse' ) );
		}

		if ( ! $this->check_budget() ) {
			return new WP_Error( 'mvs_ai_budget_exceeded', __( 'Monthly AI budget has been exceeded.', 'wpmediaverse' ) );
		}

		// External providers fetch the image over HTTP — must be a signed URL
		// that bypasses the .htaccess deny-all on /wp-content/uploads/wpmediaverse/.
		// A raw URL would 403 against the gate, so we don't fall back to one.
		$image_url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' );
		if ( ! $image_url ) {
			return new WP_Error( 'mvs_no_image', __( 'Media item has no signed file URL.', 'wpmediaverse' ) );
		}

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'ai_status', 'processing' );

		$result = $provider->analyze_image( $image_url );

		if ( ! $result ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'ai_status', 'failed' );
			$this->track_usage( $provider->get_id(), 'analyze', false );
			return new WP_Error( 'mvs_ai_failed', __( 'AI analysis failed.', 'wpmediaverse' ) );
		}

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'ai_description', sanitize_text_field( $result['description'] ) );
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'ai_confidence', (float) $result['confidence'] );

		$this->track_usage( $provider->get_id(), 'analyze', true );

		return $result;
	}

	/**
	 * Auto-tag a media item using AI.
	 *
	 * @param int $media_id Media post ID.
	 * @return string[]|WP_Error Array of tags or error.
	 */
	public function auto_tag( int $media_id ) {
		$provider = $this->get_active_provider();
		if ( ! $provider ) {
			return new WP_Error( 'mvs_no_ai_provider', __( 'No AI provider is configured.', 'wpmediaverse' ) );
		}

		if ( ! $this->check_budget() ) {
			return new WP_Error( 'mvs_ai_budget_exceeded', __( 'Monthly AI budget has been exceeded.', 'wpmediaverse' ) );
		}

		// External providers (OpenAI Vision etc.) fetch the image over HTTP — must be a signed URL
		// that bypasses the .htaccess deny-all on /wp-content/uploads/wpmediaverse/.
		$image_url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' );
		if ( ! $image_url ) {
			return new WP_Error( 'mvs_no_image', __( 'Media item has no signed file URL.', 'wpmediaverse' ) );
		}
		$description = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'ai_description' );

		$tags = $provider->generate_tags( $image_url, $description ? $description : '' );

		if ( ! empty( $tags ) ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'ai_tags', $tags );

			// Apply tags to the mvs_tag taxonomy if auto-apply is enabled.
			if ( get_option( 'mvs_ai_auto_apply_tags', false ) ) {
				wp_set_object_terms( $media_id, $tags, 'mvs_tag', true );
				$all_terms = get_the_terms( $media_id, 'mvs_tag' );
				if ( $all_terms && ! is_wp_error( $all_terms ) ) {
					\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'tags', wp_json_encode( array_values( wp_list_pluck( $all_terms, 'name' ) ) ) );
				}
			}
		}

		$this->track_usage( $provider->get_id(), 'tags', true );

		return $tags;
	}

	/**
	 * Moderate a media item using AI.
	 *
	 * @param int $media_id Media post ID.
	 * @return array|WP_Error Moderation result or error.
	 */
	public function moderate( int $media_id ) {
		$provider = $this->get_active_provider();
		if ( ! $provider ) {
			return new WP_Error( 'mvs_no_ai_provider', __( 'No AI provider is configured.', 'wpmediaverse' ) );
		}

		// Moderation is a billable provider call like analyze/auto_tag, so it
		// must respect the same monthly spend cap — otherwise auto-moderation
		// runs unbounded against the budget the owner set.
		if ( ! $this->check_budget() ) {
			return new WP_Error( 'mvs_ai_budget_exceeded', __( 'Monthly AI budget has been exceeded.', 'wpmediaverse' ) );
		}

		// External providers fetch the image over HTTP — must be a signed URL.
		$image_url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' );
		if ( ! $image_url ) {
			return new WP_Error( 'mvs_no_image', __( 'Media item has no signed file URL.', 'wpmediaverse' ) );
		}

		$result = $provider->moderate_content( $image_url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || ! isset( $result['safe'] ) ) {
			return new WP_Error( 'mvs_moderation_failed', __( 'AI moderation returned an invalid response.', 'wpmediaverse' ) );
		}

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'ai_moderation', $result );

		/**
		 * Filters the AI moderation result before the flagging decision.
		 *
		 * @since 1.1.0
		 *
		 * @param array $result   Moderation result with 'safe' key.
		 * @param int   $media_id Media post ID.
		 */
		$result = apply_filters( 'mvs_ai_moderation_result', $result, $media_id );

		if ( ! $result['safe'] ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'moderation_status', 'flagged' );

			/**
			 * Fires when AI flags media content.
			 *
			 * @param int   $media_id Media post ID.
			 * @param array $result   Moderation result.
			 */
			do_action( 'mvs_media_flagged', $media_id, $result );
		}

		$this->track_usage( $provider->get_id(), 'moderate', true );

		return $result;
	}

	/**
	 * Run full AI pipeline on a media item (analyze + tag + moderate).
	 *
	 * @param int $media_id Media post ID.
	 * @return array{description: string|null, tags: string[], moderation: array|null}
	 */
	public function process( int $media_id ): array {
		$output = array(
			'description' => null,
			'tags'        => array(),
			'moderation'  => null,
		);

		/**
		 * Filters whether AI analysis should run on a media item.
		 *
		 * Return false to skip AI processing entirely.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $should_analyze Whether to analyze the media.
		 * @param int  $media_id       Media post ID.
		 */
		if ( ! apply_filters( 'mvs_should_ai_analyze', true, $media_id ) ) {
			return $output;
		}

		$analysis = $this->analyze( $media_id );
		if ( ! is_wp_error( $analysis ) ) {
			$output['description'] = $analysis['description'];
		}

		$tags = $this->auto_tag( $media_id );
		if ( ! is_wp_error( $tags ) ) {
			$output['tags'] = $tags;
		}

		if ( get_option( 'mvs_ai_auto_moderate', false ) ) {
			$moderation = $this->moderate( $media_id );
			if ( ! is_wp_error( $moderation ) ) {
				$output['moderation'] = $moderation;
			}
		}

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'ai_status', 'complete' );

		/**
		 * Filters the combined AI processing result before returning.
		 *
		 * @since 1.1.0
		 *
		 * @param array $output   Combined AI results (description, tags, moderation).
		 * @param int   $media_id Media post ID.
		 */
		return apply_filters( 'mvs_ai_result', $output, $media_id );
	}

	/**
	 * Check if the monthly AI budget allows more requests.
	 *
	 * @return bool
	 */
	private function check_budget(): bool {
		$budget = (float) get_option( 'mvs_ai_monthly_budget', 0 );
		if ( $budget <= 0 ) {
			return true; // No budget limit set.
		}

		$usage = get_option( 'mvs_ai_usage', array() );
		$month = gmdate( 'Y-m' );

		$spent = isset( $usage[ $month ]['cost'] ) ? (float) $usage[ $month ]['cost'] : 0;

		return $spent < $budget;
	}

	/**
	 * Track AI API usage for cost monitoring.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $action      Action type (analyze, tags, moderate).
	 * @param bool   $success     Whether the call succeeded.
	 */
	private function track_usage( string $provider_id, string $action, bool $success ): void {
		$usage = get_option( 'mvs_ai_usage', array() );
		$month = gmdate( 'Y-m' );

		if ( ! isset( $usage[ $month ] ) ) {
			$usage[ $month ] = array(
				'calls'   => 0,
				'success' => 0,
				'failed'  => 0,
				'cost'    => 0,
			);
		}

		++$usage[ $month ]['calls'];

		if ( $success ) {
			++$usage[ $month ]['success'];
		} else {
			++$usage[ $month ]['failed'];
		}

		// Estimated cost per call (configurable per provider).
		$cost_per_call = (float) get_option( 'mvs_ai_cost_per_call', 0.01 );
		if ( $success ) {
			$usage[ $month ]['cost'] += $cost_per_call;
		}

		update_option( 'mvs_ai_usage', $usage );
	}

	/**
	 * Get usage stats for the current month.
	 *
	 * @return array{calls: int, success: int, failed: int, cost: float, budget: float}
	 */
	public function get_usage_stats(): array {
		$usage  = get_option( 'mvs_ai_usage', array() );
		$month  = gmdate( 'Y-m' );
		$budget = (float) get_option( 'mvs_ai_monthly_budget', 0 );

		$current = isset( $usage[ $month ] ) ? $usage[ $month ] : array(
			'calls'   => 0,
			'success' => 0,
			'failed'  => 0,
			'cost'    => 0,
		);

		$current['budget'] = $budget;
		return $current;
	}
}
