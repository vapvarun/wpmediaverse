<?php
/**
 * ActivityPrivacyFilter — viewer-side filter on BP activity queries.
 *
 * Each activity carries its media's privacy as `_mvs_activity_privacy_level`
 * meta (PrivacyService::privacy_to_level(); rtMedia-parity numbers: 0 public,
 * 20 members, 30 followers, 40 friends, 60 group, 80 private, 90 custom).
 * Who may see each level is decided by PrivacyService::level_audience_sql(),
 * the same audience can_view() applies to a single item.
 *
 * The filter rewrites every BP activity SELECT (legacy + modern paths)
 * to add a LEFT JOIN on bp_activity_meta and a WHERE clause that excludes
 * rows the current viewer is not entitled to see. Same shape rtMedia
 * has used since 2014; choosing the same numeric values means future
 * rtMedia → WPMV imports preserve intent without translation.
 *
 * Without this filter we'd have to fall back to `hide_sitewide=1` for
 * any non-public level, which over-restricts Members / Friends. The
 * filter is what makes the 4-option user-facing dropdown honest.
 *
 * @since 1.2.1
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks viewer-aware privacy gating into BP activity SQL.
 */
class ActivityPrivacyFilter {

	/**
	 * Wire the filter callbacks. Called by BuddyPressManager on init.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		// Structured hooks (BP 2.5.0+) — these fire BEFORE the final SQL is
		// assembled. Cleaner than splicing the full string and naturally
		// covers every query path: site-wide stream, user profile activity,
		// group activity, REST API endpoints, AJAX load-more, RSS feeds.
		add_filter( 'bp_activity_get_join_sql', array( $this, 'append_meta_join' ), 20, 2 );
		add_filter( 'bp_activity_get_where_conditions', array( $this, 'append_privacy_where' ), 20, 2 );

		// Total-count SQL needs the same JOIN+WHERE or pagination breaks
		// (X-WP-Total header would lie, "load more" would over-fetch).
		add_filter( 'bp_activity_total_activities_sql', array( $this, 'append_filter_to_count_sql' ), 20, 2 );

		// Legacy path — sites running older BP / theme template-overrides
		// hit `bp_activity_get_user_join_filter` instead. Mirror the same
		// gating via the original full-SQL splicer.
		add_filter( 'bp_activity_get_user_join_filter', array( $this, 'apply_to_legacy_sql' ), 20, 6 );
	}

	/**
	 * Append our meta JOIN to the structured `bp_activity_get_join_sql` filter.
	 *
	 * NEVER skip on $r['show_hidden']: BP's profile-stream scopes ('just-me'
	 * et al, bp_activity_filter_just_me_scope) override show_hidden=true for
	 * EVERY viewer — including logged-out ones — so it is a hide_sitewide
	 * widening flag, not a moderation/trust signal. Skipping on it disabled
	 * MVS privacy gating on all member-profile activity streams and leaked
	 * members-only media to anonymous visitors.
	 * Admin/owner visibility is granted inside build_where_clause() instead.
	 *
	 * @param string $join_sql Existing JOIN clause.
	 * @param array  $r        Query args (unused).
	 */
	public function append_meta_join( $join_sql, $r ): string {
		unset( $r );
		return (string) $join_sql . ' ' . $this->build_join_sql();
	}

	/**
	 * Append our privacy conditions to the structured WHERE-conditions array.
	 *
	 * No show_hidden skip — see append_meta_join() for why.
	 *
	 * @param array $where_conditions Current conditions array.
	 * @param array $r                Query args (unused).
	 */
	public function append_privacy_where( $where_conditions, $r ): array {
		unset( $r );
		if ( ! is_array( $where_conditions ) ) {
			$where_conditions = array();
		}
		// MUST wrap in `(...)`. BP joins the conditions array with ` AND `
		// (line 669 of class-bp-activity-activity.php). Our body contains
		// OR operators; without the wrapping parens, AND has higher
		// precedence than OR and `OR 1=1` would escape every other gate
		// (admin override would unhide every activity).
		$where_conditions['mvs_activity_privacy'] = '(' . $this->build_where_clause() . ')';
		return $where_conditions;
	}

	/**
	 * Append the JOIN+WHERE to the count SQL so totals match the filtered
	 * row set. Without this the `X-WP-Total` REST header lies.
	 *
	 * @param string $count_sql The full COUNT SQL.
	 * @param string $where_sql The WHERE clause already in the count SQL.
	 */
	public function append_filter_to_count_sql( $count_sql, $where_sql ): string {
		unset( $where_sql );
		$count_sql = (string) $count_sql;
		if ( false !== strpos( $count_sql, 'mvs_priv_lvl_alias' ) ) {
			return $count_sql;
		}
		// Inject JOIN after the activity FROM, append WHERE clause.
		$with_join = $this->insert_join( $count_sql, ' ' . $this->build_join_sql() . ' ' );
		return $this->insert_where( $with_join, ' AND ( ' . $this->build_where_clause() . ' ) ' );
	}

	/**
	 * Legacy hook — `bp_activity_get_user_join_filter` ($sql, $select_sql,
	 * $from_sql, $where_sql, $sort, $pag_sql).
	 *
	 * Hooked at priority 20 so rtMedia (priority 10) gets the FIRST pass on
	 * sites running both plugins; we layer our filter on top of theirs.
	 *
	 * @param string $sql        Full SQL.
	 * @param string $select_sql SELECT clause (unused).
	 * @param string $from_sql   FROM clause (unused).
	 * @param string $where_sql  WHERE clause (unused).
	 * @param string $sort       ORDER BY clause (unused).
	 * @param string $pag_sql    Pagination clause (unused).
	 * @return string
	 */
	public function apply_to_legacy_sql( $sql, $select_sql, $from_sql, $where_sql, $sort, $pag_sql = '' ): string {
		unset( $select_sql, $from_sql, $where_sql, $sort, $pag_sql );
		return $this->rewrite_sql( (string) $sql );
	}

	/**
	 * Compute the viewer's allowed-privacy WHERE clause and JOIN the
	 * meta table into the supplied SQL.
	 *
	 * Idempotent: if the SQL already mentions `mvs_priv_lvl_alias` we
	 * skip (something else applied us).
	 *
	 * @param string $sql The full activity SELECT.
	 */
	private function rewrite_sql( string $sql ): string {
		if ( false !== strpos( $sql, 'mvs_priv_lvl_alias' ) ) {
			return $sql;
		}
		// Defensive — the activity table reference is always `a` in BP.
		// If the SQL doesn't contain `a.id` we're not looking at a
		// standard activity SELECT and shouldn't try to rewrite.
		if ( false === strpos( $sql, 'a.id' ) ) {
			return $sql;
		}

		$sql = $this->insert_join( $sql, ' ' . $this->build_join_sql() . ' ' );
		$sql = $this->insert_where( $sql, ' AND ( ' . $this->build_where_clause() . ' ) ' );
		return $sql;
	}

	/**
	 * Build the LEFT JOIN clause for the privacy-level meta lookup.
	 * Stable shape — used by both the structured `bp_activity_get_join_sql`
	 * filter and the legacy full-SQL splicer.
	 */
	private function build_join_sql(): string {
		global $wpdb;
		$meta_table = function_exists( 'bp_core_get_table_prefix' )
			? bp_core_get_table_prefix() . 'bp_activity_meta'
			: $wpdb->prefix . 'bp_activity_meta';

		return "LEFT JOIN {$meta_table} mvs_priv_lvl_alias ON ( mvs_priv_lvl_alias.activity_id = a.id AND mvs_priv_lvl_alias.meta_key = '_mvs_activity_privacy_level' )";
	}

	/**
	 * Build the viewer-permitted WHERE clause body (without the leading
	 * `AND ( ... )` wrapping; the appender adds that). Same logic
	 * regardless of which hook called us.
	 *
	 * The audience per level is core's (PrivacyService::level_audience_sql());
	 * this class only says where BuddyPress keeps the level, the author and the
	 * group. BuddyPress moderators keep the full stream so the wp-admin
	 * activity screen stays complete.
	 */
	private function build_where_clause(): string {
		$viewer_id = get_current_user_id();
		if ( $viewer_id > 0 && user_can( $viewer_id, 'bp_moderate' ) ) {
			return '1 = 1';
		}

		return \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->level_audience_sql(
			'mvs_priv_lvl_alias.meta_value',
			'a.user_id',
			$viewer_id,
			array( 'group_expr' => "IF( a.component = 'groups', a.item_id, 0 )" )
		);
	}

	/**
	 * Splice the meta JOIN into the FROM clause. BP's SELECTs always
	 * reference the activity table as `… a` (with that exact alias), so we
	 * insert immediately after the first occurrence of `a ` in the FROM.
	 */
	private function insert_join( string $sql, string $join ): string {
		// Anchor on the actual prefixed table name (`{prefix}bp_activity a`).
		// `\b` doesn't fire between `wp_` and `bp_` (both sides are word chars),
		// so we match the prefix explicitly via $wpdb.
		global $wpdb;
		$table_name = function_exists( 'bp_core_get_table_prefix' )
			? bp_core_get_table_prefix() . 'bp_activity'
			: $wpdb->prefix . 'bp_activity';

		$pattern = '/' . preg_quote( $table_name, '/' ) . '\s+a\b/i';
		if ( ! preg_match( $pattern, $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			// Couldn't find the anchor — return unchanged rather than splicing
			// blindly (would corrupt the SQL).
			return $sql;
		}
		$pos = $m[0][1] + strlen( $m[0][0] );
		return substr( $sql, 0, $pos ) . $join . substr( $sql, $pos );
	}

	/**
	 * Splice our WHERE addition. BP always emits at least one WHERE clause
	 * (default: hide_sitewide=0), so we append at the end of the existing
	 * WHERE block — before any GROUP BY / ORDER BY / LIMIT.
	 */
	private function insert_where( string $sql, string $where_addition ): string {
		// Find the start of GROUP BY / ORDER BY / LIMIT — whichever comes first.
		$pattern = '/\s+(GROUP\s+BY|ORDER\s+BY|LIMIT)\b/i';
		if ( preg_match( $pattern, $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1];
			return substr( $sql, 0, $pos ) . $where_addition . substr( $sql, $pos );
		}
		// No tail clauses — append.
		return rtrim( $sql ) . $where_addition;
	}
}
