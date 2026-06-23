<?php
/**
 * TransactionService — append-only per-media-type usage ledger (mvs_transactions).
 *
 * Architecture: materialized balance + immutable ledger.
 *   - Fast "current count" lives in usermeta (`_mvs_<type>_count`, maintained by
 *     the Pro QuotaService) — cheap to read for quota checks.
 *   - This ledger records EVERY usage delta with a running balance_after, so the
 *     plugin keeps an auditable history ("what did this member upload, when, why")
 *     that a bare counter throws away. Powers GET /me/transactions, a member
 *     usage history, and owner-side auditing.
 *
 * The table (mvs_transactions, Migrator v9) was created but never written or read
 * before 1.8.0 — this is the model that finally owns it (Coding Rule: no raw $wpdb
 * outside a model).
 *
 * @package WPMediaVerse\Services
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Owns reads/writes of the mvs_transactions usage ledger.
 */
class TransactionService {

	/**
	 * Hook the upload event so every upload appends a usage row.
	 */
	public function init(): void {
		// Priority 20: after the Pro QuotaService (5) has bumped the counter, so
		// the ledger and the materialized counter stay consistent.
		add_action( 'mvs_media_uploaded', array( $this, 'on_media_uploaded' ), 20, 1 );
	}

	/**
	 * Record a usage transaction when a member uploads media.
	 *
	 * @param int $media_id Newly uploaded media ID.
	 */
	public function on_media_uploaded( int $media_id ): void {
		$repo    = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$user_id = (int) ( $repo->get_author( $media_id ) ?: get_current_user_id() );
		if ( $user_id < 1 ) {
			return;
		}
		$media_type = (string) $repo->get( $media_id, 'media_type' );
		if ( '' === $media_type ) {
			return;
		}
		$this->record( $user_id, $media_type, 1, 'upload' );
	}

	/**
	 * Append a transaction row, computing balance_after from the prior balance.
	 *
	 * @param int    $user_id    Member ID.
	 * @param string $media_type image|video|audio|document.
	 * @param int    $delta      +1 for a usage event, -1 for a reversal.
	 * @param string $reason     Short machine reason ('upload', 'delete', …).
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function record( int $user_id, string $media_type, int $delta, string $reason = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_transactions';

		$prev = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT balance_after FROM {$wpdb->prefix}mvs_transactions WHERE user_id = %d AND media_type = %s ORDER BY id DESC LIMIT 1",
				$user_id,
				$media_type
			)
		);

		$balance_after = max( 0, $prev + $delta );

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'user_id'       => $user_id,
				'media_type'    => $media_type,
				'delta'         => $delta,
				'balance_after' => $balance_after,
				'reason'        => substr( $reason, 0, 100 ),
			),
			array( '%d', '%s', '%d', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A member's transaction history, newest first (paginated).
	 *
	 * @param int   $user_id Member ID.
	 * @param array $args    { @type int $per_page (1-100), @type int $page }
	 * @return array<int, array<string, mixed>>
	 */
	public function get_for_user( int $user_id, array $args = array() ): array {
		global $wpdb;
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, media_type, delta, balance_after, reason, created_at FROM {$wpdb->prefix}mvs_transactions WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Total transaction count for a member (for pagination + the "number" display).
	 *
	 * @param int $user_id Member ID.
	 * @return int
	 */
	public function count_for_user( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_transactions WHERE user_id = %d",
				$user_id
			)
		);
	}
}
