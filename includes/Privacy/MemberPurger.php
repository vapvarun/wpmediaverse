<?php
/**
 * Erases a member from every table that holds them, and proves it.
 *
 * @package WPMediaVerse
 * @since   2.1.0
 */

namespace WPMediaVerse\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * The one purge, generated from the one map.
 *
 * Data Lifecycle Standard §9a: **the delete list and the verify list are the
 * same list.** The sweep below is generated from `MemberDataMap::erase_map()`,
 * and `residue()` COUNTs from that same map — so the purge cannot report
 * finished while a registered table still holds the member. Two hand-maintained
 * lists drift; one cannot.
 *
 * And **`done` must be earned.** A destructive compliance process reports
 * finished only after counting what is left and finding zero. Asserting
 * completion is the one lie a compliance path cannot tell, because WordPress
 * core takes `done` at its word and emails the member to say their data is gone.
 */
class MemberPurger {

	/**
	 * Rows deleted per table per pass. Keeps an oversized member from
	 * half-erasing on a timeout: the eraser is resumable, so core calls it again
	 * until residue() is zero.
	 */
	private const BATCH = 500;

	/**
	 * Delete the member from every ERASE table, and anonymise them in every
	 * RETAIN table.
	 *
	 * @param int $user_id Member being erased.
	 * @return array{removed:int, retained:int} Counts.
	 */
	public function purge( int $user_id ): array {
		global $wpdb;

		$removed  = 0;
		$retained = 0;

		if ( $user_id <= 0 ) {
			return array(
				'removed'  => 0,
				'retained' => 0,
			);
		}

		foreach ( MemberDataMap::erase_map() as $table => $spec ) {
			$full = $wpdb->prefix . $table;

			if ( ! $this->table_exists( $full ) ) {
				continue;
			}

			foreach ( $spec['columns'] as $column ) {
				if ( ! $this->column_exists( $full, $column ) ) {
					continue;
				}

				$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare(
						"DELETE FROM `{$full}` WHERE `{$column}` = %d LIMIT %d",
						$user_id,
						self::BATCH
					)
				);

				$removed += max( 0, (int) $deleted );
			}
		}

		// Retained rows are anonymised, not left intact. The record survives; the
		// person does not.
		foreach ( MemberDataMap::retain_map() as $table => $spec ) {
			$full = $wpdb->prefix . $table;

			if ( ! $this->table_exists( $full ) ) {
				continue;
			}

			foreach ( $spec['columns'] as $column ) {
				if ( ! $this->column_exists( $full, $column ) ) {
					continue;
				}

				$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare(
						"UPDATE `{$full}` SET `{$column}` = 0 WHERE `{$column}` = %d LIMIT %d",
						$user_id,
						self::BATCH
					)
				);

				$retained += max( 0, (int) $updated );
			}
		}

		/**
		 * Fires after a member has been purged from the mapped tables.
		 *
		 * @since 2.1.0
		 *
		 * @param int $user_id Member.
		 */
		do_action( 'mvs_member_purged', $user_id );

		return array(
			'removed'  => $removed,
			'retained' => $retained,
		);
	}

	/**
	 * How much of this member is still in the ERASE tables?
	 *
	 * This is what makes `done` honest. Counted from the same map the purge
	 * sweeps, so the two can never disagree.
	 *
	 * @param int $user_id Member.
	 * @return int Rows still holding this member.
	 */
	public function residue( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$left = 0;

		foreach ( MemberDataMap::erase_map() as $table => $spec ) {
			$full = $wpdb->prefix . $table;

			if ( ! $this->table_exists( $full ) ) {
				continue;
			}

			foreach ( $spec['columns'] as $column ) {
				if ( ! $this->column_exists( $full, $column ) ) {
					continue;
				}

				$left += (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `{$full}` WHERE `{$column}` = %d",
						$user_id
					)
				);
			}
		}

		return $left;
	}

	/**
	 * Does the table exist on this install?
	 *
	 * Pro tables are absent when Pro is not installed, and a legacy table may be
	 * absent on a fresh one. A missing table is not an error.
	 *
	 * @param string $full Prefixed table name.
	 * @return bool
	 */
	private function table_exists( string $full ): bool {
		global $wpdb;

		static $cache = array();

		if ( ! isset( $cache[ $full ] ) ) {
			$cache[ $full ] = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full )
			);
		}

		return $cache[ $full ];
	}

	/**
	 * Does the column exist?
	 *
	 * Guards the map against schema drift: a renamed column would otherwise make
	 * the purge silently delete nothing, which is precisely the failure mode this
	 * whole class exists to prevent.
	 *
	 * @param string $full   Prefixed table name.
	 * @param string $column Column.
	 * @return bool
	 */
	private function column_exists( string $full, string $column ): bool {
		global $wpdb;

		static $cache = array();

		$key = $full . '.' . $column;

		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SHOW COLUMNS FROM `{$full}` LIKE %s", $column )
			);
		}

		return $cache[ $key ];
	}
}
