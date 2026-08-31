<?php
/**
 * GDPR compliance — personal data exporter and eraser.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;


use WPMediaVerse\Privacy\MemberDataMap;
use WPMediaVerse\Privacy\MemberPurger;

class GDPRService {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy' ) );
	}

	/**
	 * Register data exporters.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporters( array $exporters ): array {
		// Everything the map knows about, including the tables the two hand-written
		// exporters below never mentioned (the usage ledger, the error log, and
		// every Pro table). Article 15 is not satisfied by exporting the parts of
		// someone we happened to remember.
		$exporters['wpmediaverse-all'] = array(
			'exporter_friendly_name' => __( 'WPMediaVerse (all data)', 'wpmediaverse' ),
			'callback'               => array( $this, 'export_mapped' ),
		);

		$exporters['wpmediaverse-media'] = array(
			'exporter_friendly_name' => __( 'WPMediaVerse Media', 'wpmediaverse' ),
			'callback'               => array( $this, 'export_media' ),
		);

		$exporters['wpmediaverse-social'] = array(
			'exporter_friendly_name' => __( 'WPMediaVerse Social Data', 'wpmediaverse' ),
			'callback'               => array( $this, 'export_social' ),
		);

		return $exporters;
	}

	/**
	 * Register data erasers.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_erasers( array $erasers ): array {
		// One eraser, generated from MemberDataMap — the same map the export, the
		// account-deletion purge and the residue verifier read.
		//
		// This replaces the previous pair of hand-written erasers, which between
		// them missed nine member-bearing tables (the usage ledger, the error log,
		// and every Pro table: push devices, boosts, credits, play analytics,
		// competition entries and votes). Worse, they hard-coded `'done' => true`.
		// Core takes `done` at its word and emails the member to say their data is
		// gone — so a request could be reported complete while the member's push
		// tokens were still live. `done` is now earned by counting what is left.
		$erasers['wpmediaverse'] = array(
			'eraser_friendly_name' => __( 'WPMediaVerse', 'wpmediaverse' ),
			'callback'             => array( $this, 'erase_member' ),
		);

		return $erasers;
	}

	/**
	 * Erase a member from every mapped table, and prove it.
	 *
	 * Batched and resumable: core calls this again while `done` is false, so an
	 * oversized member cannot half-erase on a timeout.
	 *
	 * @param string $email_address Member's email.
	 * @param int    $page          Page (core increments while done is false).
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase_member( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$purger = new MemberPurger();
		$counts = $purger->purge( (int) $user->ID );

		// `done` is EARNED, not asserted: count what is still there and only
		// report finished at zero. The delete list and the verify list are the
		// same list, so the two cannot disagree.
		$left = $purger->residue( (int) $user->ID );

		$messages = array();

		if ( $counts['retained'] > 0 ) {
			foreach ( MemberDataMap::retain_map() as $spec ) {
				$messages[] = sprintf(
					/* translators: 1: data category, 2: why it is kept. */
					__( '%1$s was kept but anonymised. %2$s', 'wpmediaverse' ),
					$spec['label'],
					$spec['reason']
				);
			}
		}

		return array(
			'items_removed'  => $counts['removed'] > 0,
			'items_retained' => $counts['retained'] > 0,
			'messages'       => array_unique( $messages ),
			'done'           => 0 === $left,
		);
	}

	/**
	 * Export user's media items.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{data: array, done: bool}
	 */
	public function export_media( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;

		// UNFILTERED on purpose — an export must disclose everything the person
		// authored, whatever its privacy. See
		// MediaRepository::author_media_export_rows().
		$rows = \WPMediaVerse\Core\Plugin::container()
			->get( 'media_repository' )
			->author_media_export_rows( (int) $user->ID, $per_page, $offset );

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'wpmediaverse-media',
				'group_label' => __( 'Media Items', 'wpmediaverse' ),
				'item_id'     => "mvs-media-{$row['media_id']}",
				'data'        => array(
					array(
						'name'  => __( 'Title', 'wpmediaverse' ),
						'value' => $row['title'] ?? '',
					),
					array(
						'name'  => __( 'Description', 'wpmediaverse' ),
						'value' => $row['description'] ?? '',
					),
					array(
						'name'  => __( 'Type', 'wpmediaverse' ),
						'value' => $row['media_type'] ?? '',
					),
					array(
						'name'  => __( 'Privacy', 'wpmediaverse' ),
						'value' => $row['privacy'] ?? '',
					),
					array(
						'name'  => __( 'Date', 'wpmediaverse' ),
						'value' => $row['created_at'] ?? '',
					),
					array(
						'name'  => __( 'File URL', 'wpmediaverse' ),
						'value' => $row['file_url'] ?? '',
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Export user's social data (reactions, comments, follows).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{data: array, done: bool}
	 */
	public function export_social( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		$export_items = array();

		if ( 1 === $page ) {
			// Reactions.
			$reactions = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, reaction_type, created_at FROM {$wpdb->prefix}mvs_reactions WHERE user_id = %d",
					$user->ID
				)
			);
			foreach ( $reactions as $r ) {
				$export_items[] = array(
					'group_id'    => 'wpmediaverse-reactions',
					'group_label' => __( 'Reactions', 'wpmediaverse' ),
					'item_id'     => "mvs-reaction-{$r->media_id}",
					'data'        => array(
						array(
							'name'  => __( 'Media ID', 'wpmediaverse' ),
							'value' => $r->media_id,
						),
						array(
							'name'  => __( 'Type', 'wpmediaverse' ),
							'value' => $r->reaction_type,
						),
						array(
							'name'  => __( 'Date', 'wpmediaverse' ),
							'value' => $r->created_at,
						),
					),
				);
			}

			// Follows.
			$follows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT following_id, created_at FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d",
					$user->ID
				)
			);
			foreach ( $follows as $f ) {
				$followed_user  = get_userdata( $f->following_id );
				$export_items[] = array(
					'group_id'    => 'wpmediaverse-follows',
					'group_label' => __( 'Following', 'wpmediaverse' ),
					'item_id'     => "mvs-follow-{$f->following_id}",
					'data'        => array(
						array(
							'name'  => __( 'User', 'wpmediaverse' ),
							'value' => $followed_user ? $followed_user->display_name : "#{$f->following_id}",
						),
						array(
							'name'  => __( 'Date', 'wpmediaverse' ),
							'value' => $f->created_at,
						),
					),
				);
			}

			// Favorites.
			$favorites = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, created_at FROM {$wpdb->prefix}mvs_favorites WHERE user_id = %d",
					$user->ID
				)
			);
			foreach ( $favorites as $fav ) {
				$export_items[] = array(
					'group_id'    => 'wpmediaverse-favorites',
					'group_label' => __( 'Favorites', 'wpmediaverse' ),
					'item_id'     => "mvs-fav-{$fav->media_id}",
					'data'        => array(
						array(
							'name'  => __( 'Media ID', 'wpmediaverse' ),
							'value' => $fav->media_id,
						),
						array(
							'name'  => __( 'Date', 'wpmediaverse' ),
							'value' => $fav->created_at,
						),
					),
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Erase user's social data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
	 */
	public function erase_social( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;
		$removed = 0;

		$tables = array(
			'mvs_reactions'                 => 'user_id',
			'mvs_favorites'                 => 'user_id',
			'mvs_follows'                   => 'follower_id',
			'mvs_notifications'             => 'user_id',
			'mvs_reports'                   => 'reporter_id',
			'mvs_blocks'                    => 'blocker_id',
			'mvs_activity'                  => 'user_id',
			'mvs_mentions'                  => 'mentioned_user_id',
			// Additions — previously missed by GDPR erasure, causing orphaned rows to
			// survive a user wipe. `mvs_conversations` is intentionally retained so
			// threads with other participants keep working; we only erase the
			// requesting user's participation + their sent messages.
			'mvs_media_views'               => 'user_id',
			'mvs_access_grants'             => 'user_id',
			'mvs_conversation_participants' => 'user_id',
			'mvs_messages'                  => 'sender_id',
			'mvs_message_reactions'         => 'user_id', // audit 2026-06-04 — drifted from UserDeletionService.
		);

		foreach ( $tables as $table => $column ) {
			$count    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user->ID
				)
			);
			$removed += (int) $count;
		}

		// Also remove as following target.
		$removed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_follows WHERE following_id = %d",
				$user->ID
			)
		);

		// Also remove as block target (reverse side). UserDeletionService
		// already cleaned both sides; this path only cleaned blocker_id, so
		// the two erase routines had drifted (audit 2026-06-04).
		$removed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_blocks WHERE blocked_id = %d",
				$user->ID
			)
		);

		// Remove as notification actor.
		$removed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_notifications WHERE actor_id = %d",
				$user->ID
			)
		);

		// Delete comments.
		$comments = get_comments(
			array(
				'user_id' => $user->ID,
				'type'    => 'mvs_comment',
				'fields'  => 'ids',
			)
		);
		foreach ( $comments as $cid ) {
			wp_delete_comment( $cid, true );
			++$removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase user's media (anonymize rather than delete).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
	 */
	public function erase_media( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;

		$per_page = 50;

		// UNFILTERED on purpose — an erasure must reach everything the person
		// authored. See MediaRepository::author_media_ids().
		$media_ids = \WPMediaVerse\Core\Plugin::container()
			->get( 'media_repository' )
			->author_media_ids( (int) $user->ID, $per_page );

		$removed = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;

			// Remove all custom table data (index + meta).
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_all( $media_id );

			// Clean up stats row.
			$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			++$removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => count( $media_ids ) < $per_page,
		);
	}

	/**
	 * Add privacy policy suggested text.
	 */
	public function add_privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<h2>%s</h2><p>%s</p><p>%s</p><p>%s</p>',
			__( 'WPMediaVerse', 'wpmediaverse' ),
			__( 'When you upload media to this site using WPMediaVerse, we store the file and associated metadata (title, description, tags, privacy settings). Images may be processed for EXIF data removal.', 'wpmediaverse' ),
			__( 'Social interactions (reactions, comments, follows, favorites) are stored in our database and linked to your user account. You can manage these through your profile settings.', 'wpmediaverse' ),
			__( 'When you request deletion of your personal data, all media files, social interactions, and associated metadata will be permanently removed from our systems.', 'wpmediaverse' )
		);

		wp_add_privacy_policy_content( 'WPMediaVerse', $content );
	}

	/**
	 * Export every mapped table's rows for this member.
	 *
	 * Generated from MemberDataMap, so a table can never be erasable but not
	 * exportable — the same list drives both. Retained (anonymised) tables are
	 * exported too, with the reason they are kept stated in the export itself:
	 * the member is entitled to know what survives and why.
	 *
	 * @param string $email_address Member's email.
	 * @param int    $page          Page number.
	 * @return array{data: array, done: bool}
	 */
	public function export_mapped( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id = (int) $user->ID;
		$export  = array();

		$groups = array(
			'erase'  => MemberDataMap::erase_map(),
			'retain' => MemberDataMap::retain_map(),
		);

		foreach ( $groups as $kind => $map ) {
			foreach ( $map as $table => $spec ) {
				$full = $wpdb->prefix . $table;

				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( ! $exists ) {
					continue;
				}

				$where = array();
				foreach ( $spec['columns'] as $column ) {
					$has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$full}` LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( $has ) {
						$where[] = $wpdb->prepare( "`{$column}` = %d", $user_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
				}

				if ( empty( $where ) ) {
					continue;
				}

				// The WHERE clause is assembled from $wpdb->prepare()'d fragments
				// (each `column = %d`), and $full/$column come from a fixed table
				// map, never user input - so this is safe. Plugin Check attributes
				// the sniff to the SQL-string line, which a single-line phpcs:ignore
				// on the call doesn't cover, so disable/enable spans the statement.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results(
					"SELECT * FROM `{$full}` WHERE " . implode( ' OR ', $where ) . ' LIMIT 500',
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

				foreach ( (array) $rows as $i => $row ) {
					$data = array();

					foreach ( $row as $key => $value ) {
						$data[] = array(
							'name'  => $key,
							'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
						);
					}

					if ( 'retain' === $kind ) {
						$data[] = array(
							'name'  => __( 'Why this is kept', 'wpmediaverse' ),
							'value' => $spec['reason'] . ' ' . $spec['basis'],
						);
					}

					$export[] = array(
						'group_id'    => 'mvs-' . $table,
						'group_label' => $spec['label'],
						'item_id'     => $table . '-' . $i,
						'data'        => $data,
					);
				}
			}
		}

		return array(
			'data' => $export,
			'done' => true,
		);
	}
}
