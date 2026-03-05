<?php
/**
 * Payment bridge service.
 *
 * Hook-based abstraction that allows payment providers (Stripe, WooCommerce, etc.)
 * to integrate with the access rules system.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Payment bridge for external payment provider integration.
 */
class PaymentBridgeService {

	/**
	 * Access rules service.
	 *
	 * @var AccessRulesService
	 */
	private $access_rules;

	/**
	 * Constructor.
	 *
	 * @param AccessRulesService $access_rules Access rules service.
	 */
	public function __construct( AccessRulesService $access_rules ) {
		$this->access_rules = $access_rules;
	}

	/**
	 * Initialize payment bridge hooks.
	 */
	public function init(): void {
		// Listen for payment completion from any provider.
		add_action( 'mvs_payment_completed', array( $this, 'handle_payment_completed' ), 10, 3 );

		// Listen for subscription cancellation.
		add_action( 'mvs_subscription_cancelled', array( $this, 'handle_subscription_cancelled' ), 10, 2 );
	}

	/**
	 * Create a checkout intent for a media item.
	 *
	 * This method finds the applicable purchase/subscription rule and fires a hook
	 * that payment providers can intercept to create their own checkout sessions.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return array Checkout response with keys: status, redirect_url?, granted?, message?
	 */
	public function create_checkout( int $media_id, int $user_id ): array {
		// Check if user already has access.
		if ( $this->access_rules->has_access( $media_id, $user_id ) ) {
			return array(
				'status'  => 'already_granted',
				'granted' => true,
				'message' => __( 'You already have access to this media.', 'wpmediaverse' ),
			);
		}

		// Get purchase/subscription rules.
		$rules          = $this->access_rules->get_rules( $media_id );
		$purchase_rules = array_filter(
			$rules,
			function ( $rule ) {
				return in_array( $rule['rule_type'], array( 'purchase', 'subscription' ), true );
			}
		);

		if ( empty( $purchase_rules ) ) {
			return array(
				'status'  => 'no_rules',
				'granted' => false,
				'message' => __( 'This media item is not available for purchase.', 'wpmediaverse' ),
			);
		}

		$purchase_rule = reset( $purchase_rules );

		/**
		 * Filter the checkout response for a media purchase.
		 *
		 * Payment providers should hook here to create their checkout session
		 * and return a redirect URL or handle the payment directly.
		 *
		 * @param array|null $response     Checkout response. Return non-null to handle.
		 * @param int        $media_id     Media post ID.
		 * @param int        $user_id      User ID.
		 * @param array      $rule         The purchase/subscription rule.
		 * @param array      $all_rules    All purchase rules for this media.
		 */
		$response = apply_filters( 'mvs_checkout_process', null, $media_id, $user_id, $purchase_rule, $purchase_rules );

		if ( null !== $response && is_array( $response ) ) {
			return $response;
		}

		// No payment provider handled this — check for free/code rules.
		if ( empty( $purchase_rule['price'] ) || 0.0 === (float) $purchase_rule['price'] ) {
			// Free item — grant immediately.
			$grant_id = $this->access_rules->grant_access( $media_id, $user_id, 'purchase' );

			if ( $grant_id ) {
				return array(
					'status'  => 'granted',
					'granted' => true,
					'message' => __( 'Access granted!', 'wpmediaverse' ),
				);
			}
		}

		return array(
			'status'  => 'pending',
			'granted' => false,
			'message' => __( 'No payment provider is configured. Please contact the site administrator.', 'wpmediaverse' ),
		);
	}

	/**
	 * Handle a completed payment from any provider.
	 *
	 * Payment providers should fire: do_action( 'mvs_payment_completed', $media_id, $user_id, $source )
	 *
	 * @param int    $media_id Media post ID.
	 * @param int    $user_id  User ID.
	 * @param string $source   Payment source identifier (e.g. 'stripe', 'woocommerce').
	 */
	public function handle_payment_completed( int $media_id, int $user_id, string $source ): void {
		$grant_source = in_array( $source, AccessRulesService::GRANT_SOURCES, true ) ? $source : 'purchase';
		$this->access_rules->grant_access( $media_id, $user_id, $grant_source );
	}

	/**
	 * Handle a cancelled subscription.
	 *
	 * Payment providers should fire: do_action( 'mvs_subscription_cancelled', $media_id, $user_id )
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 */
	public function handle_subscription_cancelled( int $media_id, int $user_id ): void {
		$this->access_rules->revoke_access( $media_id, $user_id );
	}

	/**
	 * Redeem an access code for a media item.
	 *
	 * @param int    $media_id Media post ID.
	 * @param int    $user_id  User ID.
	 * @param string $code     Access code.
	 * @return bool True if code was valid and access granted.
	 */
	public function redeem_code( int $media_id, int $user_id, string $code ): bool {
		$rules = $this->access_rules->get_rules( $media_id, 'code' );

		foreach ( $rules as $rule ) {
			if ( $rule['rule_value'] === $code ) {
				$grant_id = $this->access_rules->grant_access( $media_id, $user_id, 'code' );
				if ( $grant_id ) {
					/**
					 * Fires after an access code is redeemed.
					 *
					 * @param int    $media_id Media post ID.
					 * @param int    $user_id  User ID.
					 * @param string $code     The redeemed code.
					 */
					do_action( 'mvs_code_redeemed', $media_id, $user_id, $code );
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get pricing information for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return array{price: float|null, currency: string|null, rule_type: string|null}
	 */
	public function get_pricing( int $media_id ): array {
		$rules = $this->access_rules->get_rules( $media_id );

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['price'] ) ) {
				return array(
					'price'     => (float) $rule['price'],
					'currency'  => ! empty( $rule['currency'] ) ? $rule['currency'] : 'USD',
					'rule_type' => $rule['rule_type'],
				);
			}
		}

		return array(
			'price'     => null,
			'currency'  => null,
			'rule_type' => null,
		);
	}
}
