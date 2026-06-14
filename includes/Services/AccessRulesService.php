<?php
/**
 * Access rules and grants service.
 *
 * Manages access rules (who can view gated media) and grants (who has been given access).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Access rules engine + grants tracking.
 */
class AccessRulesService {

	/**
	 * Valid rule types.
	 *
	 * @var string[]
	 */
	const RULE_TYPES = array( 'role', 'capability', 'membership', 'code' );

	/**
	 * Valid grant sources.
	 *
	 * @var string[]
	 */
	const GRANT_SOURCES = array( 'manual', 'code' );

	/**
	 * Rule types that require an explicit grant row.
	 *
	 * @var string[]
	 */
	const EXPLICIT_GRANT_TYPES = array( 'code' );

	/**
	 * Per-request access cache.
	 *
	 * @var array<string, bool>
	 */
	private $access_cache = array();

	/**
	 * Per-request cache of which media IDs have active access rules.
	 *
	 * Grid/feed renders check has_active_rules() several times per tile (the
	 * privacy filter + the direct-serve gate), which without a cache is 3+
	 * COUNT queries per tile against mvs_access_rules. Primed in bulk by
	 * prefetch_active_rules(). Static so the singleton's cache survives across
	 * the request; cleared on any rule write. (1.7.0)
	 *
	 * @var array<int, bool>
	 */
	private static $rules_presence_cache = array();

	/**
	 * Add an access rule to a media item.
	 *
	 * @param int         $media_id   Media post ID.
	 * @param string      $rule_type  Rule type (one of RULE_TYPES).
	 * @param string      $rule_value Rule value (role name, cap name, product ID, etc).
	 * @param float|null  $price      Reserved for future use.
	 * @param string|null $currency   Optional currency code (e.g. USD).
	 * @return int|false Inserted rule ID or false on failure.
	 */
	public function add_rule( int $media_id, string $rule_type, string $rule_value, ?float $price = null, ?string $currency = null ) {
		if ( ! in_array( $rule_type, self::RULE_TYPES, true ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'mvs_access_rules',
			array(
				'media_id'   => $media_id,
				'rule_type'  => $rule_type,
				'rule_value' => $rule_value,
				'price'      => $price,
				'currency'   => $currency,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		self::flush_rules_presence_cache();

		$rule_id = (int) $wpdb->insert_id;

		/**
		 * Fires after an access rule is created.
		 *
		 * @param int    $rule_id    Rule ID.
		 * @param int    $media_id   Media post ID.
		 * @param string $rule_type  Rule type.
		 * @param string $rule_value Rule value.
		 */
		do_action( 'mvs_access_rule_created', $rule_id, $media_id, $rule_type, $rule_value );

		return $rule_id;
	}

	/**
	 * Get access rules for a media item.
	 *
	 * @param int         $media_id  Media post ID.
	 * @param string|null $rule_type Optional filter by rule type.
	 * @return array
	 */
	public function get_rules( int $media_id, ?string $rule_type = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_rules';

		if ( $rule_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE media_id = %d AND rule_type = %s ORDER BY id ASC",
					$media_id,
					$rule_type
				),
				ARRAY_A
			);
			return $results ? $results : array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE media_id = %d ORDER BY id ASC",
				$media_id
			),
			ARRAY_A
		);
		return $results ? $results : array();
	}

	/**
	 * Delete an access rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return bool
	 */
	public function delete_rule( int $rule_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_rules';

		// Fetch rule before deleting for the action hook.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rule = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d",
				$rule_id
			),
			ARRAY_A
		);

		if ( ! $rule ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete( $table, array( 'id' => $rule_id ), array( '%d' ) );

		if ( $deleted ) {
			self::flush_rules_presence_cache();
			/**
			 * Fires after an access rule is deleted.
			 *
			 * @param int    $rule_id   Rule ID.
			 * @param int    $media_id  Media post ID.
			 * @param string $rule_type Rule type.
			 */
			do_action( 'mvs_access_rule_deleted', $rule_id, (int) $rule['media_id'], $rule['rule_type'] );
		}

		return (bool) $deleted;
	}

	/**
	 * Replace all rules for a media item (set-rules semantics).
	 *
	 * @param int   $media_id Media post ID.
	 * @param array $rules    Array of rule arrays with keys: rule_type, rule_value, price?, currency?.
	 * @return int Number of rules created.
	 */
	public function set_rules( int $media_id, array $rules ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_rules';

		// Delete existing rules.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'media_id' => $media_id ), array( '%d' ) );
		self::flush_rules_presence_cache();

		$count = 0;
		foreach ( $rules as $rule ) {
			$result = $this->add_rule(
				$media_id,
				$rule['rule_type'] ?? '',
				$rule['rule_value'] ?? '',
				$rule['price'] ?? null,
				$rule['currency'] ?? null
			);
			if ( $result ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Check if a media item has active access rules.
	 *
	 * @param int $media_id Media post ID.
	 * @return bool
	 */
	public function has_active_rules( int $media_id ): bool {
		if ( isset( self::$rules_presence_cache[ $media_id ] ) ) {
			return self::$rules_presence_cache[ $media_id ];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_rules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE media_id = %d",
				$media_id
			)
		);

		self::$rules_presence_cache[ $media_id ] = $count > 0;
		return self::$rules_presence_cache[ $media_id ];
	}

	/**
	 * Prime the has_active_rules() cache for many media IDs in one query.
	 *
	 * Grids check has_active_rules() multiple times per tile; this collapses
	 * the whole page to a single DISTINCT query. Idempotent — already-cached
	 * IDs are skipped. (1.7.0)
	 *
	 * @param int[] $media_ids Media IDs.
	 */
	public function prefetch_active_rules( array $media_ids ): void {
		$ids = array();
		foreach ( $media_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( self::$rules_presence_cache[ $id ] ) ) {
				$ids[ $id ] = true;
			}
		}
		if ( empty( $ids ) ) {
			return;
		}
		$id_list = array_keys( $ids );

		// Default every requested ID to "no rules", then flip the ones found.
		foreach ( $id_list as $id ) {
			self::$rules_presence_cache[ $id ] = false;
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'mvs_access_rules';
		$placeholders = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$with_rules = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT media_id FROM {$table} WHERE media_id IN ({$placeholders})",
				...$id_list
			)
		);
		foreach ( $with_rules as $id ) {
			self::$rules_presence_cache[ (int) $id ] = true;
		}
	}

	/**
	 * Clear the has_active_rules() presence cache (call after any rule write).
	 *
	 * @since 1.7.0
	 */
	private static function flush_rules_presence_cache(): void {
		self::$rules_presence_cache = array();
	}

	/**
	 * Grant access to a media item for a user (idempotent).
	 *
	 * If the user already has an active grant, updates the existing one.
	 *
	 * @param int         $media_id   Media post ID.
	 * @param int         $user_id    User ID.
	 * @param string      $source     Grant source (one of GRANT_SOURCES).
	 * @param string|null $expires_at Optional expiration datetime (MySQL format).
	 * @return int|false Grant ID or false on failure.
	 */
	public function grant_access( int $media_id, int $user_id, string $source, ?string $expires_at = null ) {
		if ( ! in_array( $source, self::GRANT_SOURCES, true ) ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_grants';

		// Check for existing active grant (idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE media_id = %d AND user_id = %d AND revoked_at IS NULL",
				$media_id,
				$user_id
			)
		);

		if ( $existing ) {
			// Update existing grant.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'source'     => $source,
					'expires_at' => $expires_at,
					'granted_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			$this->flush_access_cache( $media_id, $user_id );

			return (int) $existing->id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'media_id'   => $media_id,
				'user_id'    => $user_id,
				'source'     => $source,
				'expires_at' => $expires_at,
				'granted_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$grant_id = (int) $wpdb->insert_id;

		$this->flush_access_cache( $media_id, $user_id );

		/**
		 * Fires after access is granted to a user.
		 *
		 * @param int    $grant_id Grant ID.
		 * @param int    $media_id Media post ID.
		 * @param int    $user_id  User ID.
		 * @param string $source   Grant source.
		 */
		do_action( 'mvs_access_granted', $grant_id, $media_id, $user_id, $source );

		return $grant_id;
	}

	/**
	 * Revoke access for a user on a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	public function revoke_access( int $media_id, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_grants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET revoked_at = %s WHERE media_id = %d AND user_id = %d AND revoked_at IS NULL",
				current_time( 'mysql', true ),
				$media_id,
				$user_id
			)
		);

		if ( $updated ) {
			$this->flush_access_cache( $media_id, $user_id );

			/**
			 * Fires after access is revoked for a user.
			 *
			 * @param int $media_id Media post ID.
			 * @param int $user_id  User ID.
			 */
			do_action( 'mvs_access_revoked', $media_id, $user_id );
		}

		return (bool) $updated;
	}

	/**
	 * Check if a user has an active grant for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	public function has_access( int $media_id, int $user_id ): bool {
		$cache_key = "{$media_id}:{$user_id}";

		if ( isset( $this->access_cache[ $cache_key ] ) ) {
			return $this->access_cache[ $cache_key ];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_grants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$grant = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table}
				WHERE media_id = %d AND user_id = %d AND revoked_at IS NULL
				AND (expires_at IS NULL OR expires_at > %s)",
				$media_id,
				$user_id,
				current_time( 'mysql', true )
			)
		);

		$result = ( (int) $grant ) > 0;

		$this->access_cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Get paginated grants for a user.
	 *
	 * @param int  $user_id     User ID.
	 * @param int  $mvs_per_page Items per page.
	 * @param int  $page        Page number.
	 * @param bool $active_only Only return active (non-revoked, non-expired) grants.
	 * @return array{items: array, total: int}
	 */
	public function get_user_grants( int $user_id, int $mvs_per_page = 20, int $page = 1, bool $active_only = true ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'mvs_access_grants';
		$offset = ( $page - 1 ) * $mvs_per_page;

		if ( $active_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > %s)",
					$user_id,
					current_time( 'mysql', true )
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE user_id = %d AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > %s) ORDER BY granted_at DESC LIMIT %d OFFSET %d",
					$user_id,
					current_time( 'mysql', true ),
					$mvs_per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
					$user_id
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE user_id = %d ORDER BY granted_at DESC LIMIT %d OFFSET %d",
					$user_id,
					$mvs_per_page,
					$offset
				),
				ARRAY_A
			);
		}

		$items = $results ? $results : array();

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Clean up expired grants by setting revoked_at.
	 *
	 * @param int $batch_size Number of grants to process.
	 * @return int Number of grants cleaned up.
	 */
	public function cleanup_expired( int $batch_size = 100 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_access_grants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET revoked_at = %s
				WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= %s
				LIMIT %d",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$batch_size
			)
		);

		return (int) $updated;
	}

	/**
	 * Privacy filter: determines access based on rules and grants.
	 *
	 * Hooks into mvs_privacy_can_view at priority 20.
	 *
	 * @param bool|null $result   Current filter result.
	 * @param int       $media_id Media post ID.
	 * @param int       $user_id  User ID.
	 * @param string    $privacy  Privacy level (unused; kept for filter signature).
	 * @return bool|null True if access granted, false if blocked, null for passthrough.
	 */
	public function filter_privacy_can_view( $result, int $media_id, int $user_id, string $privacy ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// If a previous filter already decided, respect it.
		if ( null !== $result ) {
			return $result;
		}

		// No rules = passthrough to default privacy logic. Checked FIRST and
		// from the request cache (prefetch_active_rules) so the overwhelmingly
		// common rule-less media skips the owner lookup (get_post) entirely —
		// that lookup fired once per grid tile before this reorder. Owner/admin
		// access is already granted upstream in PrivacyService::check_access()
		// (before this filter runs), so returning null here is equivalent to the
		// old owner-true path for rule-less media. (1.7.0)
		if ( ! $this->has_active_rules( $media_id ) ) {
			return null;
		}

		// Rule-bearing media: owner and admin always have access — don't let
		// rules block them. (Redundant with check_access()'s own owner gate, but
		// kept as defense in depth for any caller that invokes this filter
		// directly.)
		$author_id = 0;
		$post      = get_post( $media_id );
		if ( $post ) {
			$author_id = (int) $post->post_author;
		}
		if ( ! $author_id ) {
			// Try mvs_media_index for non-CPT media.
			$author_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'post_author' );
		}
		if ( $user_id && ( $author_id === $user_id || user_can( $user_id, 'moderate_mvs_media' ) ) ) {
			return true;
		}

		// Check explicit grant first.
		if ( $user_id && $this->has_access( $media_id, $user_id ) ) {
			return true;
		}

		// Check implicit access for role/capability/membership rules.
		$rules = $this->get_rules( $media_id );
		foreach ( $rules as $rule ) {
			if ( in_array( $rule['rule_type'], self::EXPLICIT_GRANT_TYPES, true ) ) {
				continue;
			}

			if ( $this->check_implicit_rule( $rule, $user_id ) ) {
				return true;
			}
		}

		// Has rules but no access — paywall case.
		return false;
	}

	/**
	 * Check if a user satisfies an implicit rule (no grant row needed).
	 *
	 * @param array $rule    Rule data.
	 * @param int   $user_id User ID.
	 * @return bool
	 */
	private function check_implicit_rule( array $rule, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		switch ( $rule['rule_type'] ) {
			case 'role':
				$user = get_userdata( $user_id );
				return $user && in_array( $rule['rule_value'], (array) $user->roles, true );

			case 'capability':
				return user_can( $user_id, $rule['rule_value'] );

			case 'membership':
				if ( function_exists( 'groups_is_user_member' ) ) {
					return groups_is_user_member( $user_id, (int) $rule['rule_value'] );
				}
				return false;

			default:
				return false;
		}
	}

	/**
	 * Flush the per-request access cache for a specific media+user pair.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 */
	private function flush_access_cache( int $media_id, int $user_id ): void {
		unset( $this->access_cache[ "{$media_id}:{$user_id}" ] );
	}
}
