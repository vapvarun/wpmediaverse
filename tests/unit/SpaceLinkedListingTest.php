<?php
/**
 * A space drive's root lists the files LINKED into it, wherever they are
 * filed at home.
 *
 * A linked document keeps the `folder_id` of its HOME drive. The space-root
 * listing applied `folder_id = 0` to the whole WHERE, linked branch included,
 * so a file linked in from a folder was listed nowhere in the space while its
 * members could still open it by id — and the COUNT agreed with the page, so
 * nothing looked wrong.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Repository\MediaSpaceRepository;

/**
 * @since 2.5.1
 */
class SpaceLinkedListingTest extends WP_UnitTestCase {

	private const SPACE = 8811;

	/** @var int */
	private int $author;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mvs_media_spaces' ) ) ) {
			delete_option( \WPMediaVerse\Core\Migrator::VERSION_OPTION );
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	/**
	 * Insert a document row.
	 *
	 * @param array $overrides Column overrides.
	 * @return int
	 */
	private function document( array $overrides = array() ): int {
		return (int) $this->repo()->insert(
			array_merge(
				array(
					'title'             => 'Doc ' . wp_generate_password( 6, false ),
					'post_author'       => $this->author,
					'media_type'        => 'document',
					'file_type'         => 'text/plain',
					'status'            => 'publish',
					'moderation_status' => 'approved',
					'privacy'           => 'private',
					'drive_type'        => 'user',
					'drive_id'          => $this->author,
				),
				$overrides
			)
		);
	}

	/**
	 * The space-root listing.
	 *
	 * @param array $extra Extra args.
	 * @return array{items: array, total: int, pages: int}
	 */
	private function space_root( array $extra = array() ): array {
		return $this->repo()->drive_documents(
			array_merge(
				array(
					'drive_type' => 'space',
					'drive_id'   => self::SPACE,
					'folder_id'  => 0,
					'per_page'   => 50,
				),
				$extra
			)
		);
	}

	/**
	 * Ids in a listing.
	 *
	 * @param array $result drive_documents() result.
	 * @return int[]
	 */
	private function ids( array $result ): array {
		return array_map(
			static function ( $row ) {
				return (int) $row['media_id'];
			},
			$result['items']
		);
	}

	public function test_a_file_linked_from_a_home_folder_is_listed_at_the_space_root_and_counted(): void {
		$native_root   = $this->document(
			array(
				'privacy'    => 'space',
				'drive_type' => 'space',
				'drive_id'   => self::SPACE,
			)
		);
		$native_folder = $this->document(
			array(
				'privacy'    => 'space',
				'drive_type' => 'space',
				'drive_id'   => self::SPACE,
				'folder_id'  => 991,
			)
		);
		$linked_root   = $this->document();
		$linked_folder = $this->document( array( 'folder_id' => 992 ) );
		$unlinked      = $this->document( array( 'folder_id' => 992 ) );

		$links = new MediaSpaceRepository();
		$links->link( $linked_root, self::SPACE, $this->author );
		$links->link( $linked_folder, self::SPACE, $this->author );

		$result = $this->space_root();
		$ids    = $this->ids( $result );

		$this->assertContains( $native_root, $ids );
		$this->assertContains( $linked_root, $ids );
		$this->assertContains( $linked_folder, $ids, 'A file linked in from a folder on its home drive belongs at the space root.' );
		$this->assertNotContains( $native_folder, $ids, 'The space\'s OWN foldered files stay in their folder.' );
		$this->assertNotContains( $unlinked, $ids );
		$this->assertSame( 3, $result['total'], 'COUNT and page share one WHERE, so the total includes the foldered link.' );
	}

	public function test_a_trashed_linked_file_leaves_the_live_listing_and_stays_out_of_the_space_trash(): void {
		$linked = $this->document(
			array(
				'folder_id' => 993,
				'status'    => 'trash',
			)
		);
		( new MediaSpaceRepository() )->link( $linked, self::SPACE, $this->author );

		$this->assertNotContains( $linked, $this->ids( $this->space_root() ) );
		$this->assertNotContains(
			$linked,
			$this->ids(
				$this->space_root(
					array(
						'status'     => 'trash',
						'any_folder' => true,
					)
				)
			),
			'Trashed on its owner\'s drive, it is the owner\'s to restore - not the space\'s.'
		);
	}
}
