<?php
/**
 * Admin migration page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for importing media from rtMedia, MediaPress, and BuddyBoss Platform.
 *
 * Each platform card shows: detection status, available item count, already-imported
 * count, and a Start Import button. Import runs in AJAX batches so large sites
 * don't time out. Supports dry-run and skip-albums options.
 */
class MigrationPage {

	const PAGE_SLUG  = 'mvs-migration';
	const BATCH_SIZE = 25;

	/**
	 * Constructor. Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'wp_ajax_mvs_migration_detect', array( $this, 'ajax_detect' ) );
		add_action( 'wp_ajax_mvs_migration_batch', array( $this, 'ajax_run_batch' ) );
	}

	/**
	 * Add submenu page under the mvs_media CPT menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=mvs_media',
			__( 'Import Migration', 'wpmediaverse' ),
			__( 'Import Migration', 'wpmediaverse' ),
			'manage_mvs_settings',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the migration page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-migrate" style="font-size:28px;width:28px;height:28px;margin-right:8px;vertical-align:middle;"></span>
				<?php esc_html_e( 'Import Migration', 'wpmediaverse' ); ?>
			</h1>
			<hr class="wp-header-end">
			<p class="description">
				<?php esc_html_e( 'Import your existing media library from rtMedia, MediaPress, or BuddyBoss Platform. The import runs in batches and is safe to pause and resume.', 'wpmediaverse' ); ?>
			</p>

			<div class="notice notice-info inline" style="margin:16px 0;">
				<p>
					<strong><?php esc_html_e( 'Before you start:', 'wpmediaverse' ); ?></strong>
					<?php esc_html_e( 'Run a database backup first. The importer does not delete original data — it only creates new mvs_media posts. Already-imported items are automatically skipped on re-run.', 'wpmediaverse' ); ?>
				</p>
			</div>

			<?php $this->render_platform_card( 'rtmedia' ); ?>
			<?php $this->render_platform_card( 'mediapress' ); ?>
			<?php $this->render_platform_card( 'buddyboss' ); ?>
		</div>

		<style>
		.mvs-migration-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; margin-bottom:20px; max-width:780px; }
		.mvs-migration-card__header { display:flex; align-items:center; gap:12px; padding:16px 20px; border-bottom:1px solid #f0f0f1; }
		.mvs-migration-card__icon { width:36px; height:36px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; background:#2271b1; flex-shrink:0; }
		.mvs-migration-card__title { font-size:15px; font-weight:600; margin:0; }
		.mvs-migration-card__subtitle { font-size:12px; color:#646970; margin:2px 0 0; }
		.mvs-migration-card__body { padding:16px 20px; }
		.mvs-migration-card__footer { padding:12px 20px; background:#f6f7f7; border-top:1px solid #f0f0f1; border-radius:0 0 4px 4px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
		.mvs-migration-stats { display:flex; gap:24px; margin-bottom:14px; }
		.mvs-migration-stat { text-align:center; }
		.mvs-migration-stat__num { font-size:22px; font-weight:700; color:#1d2327; display:block; }
		.mvs-migration-stat__label { font-size:11px; color:#646970; text-transform:uppercase; letter-spacing:.5px; }
		.mvs-migration-progress-wrap { display:none; margin-top:12px; }
		.mvs-migration-progress-bar-bg { background:#dcdcde; border-radius:3px; height:8px; overflow:hidden; }
		.mvs-migration-progress-bar { background:#2271b1; height:8px; width:0%; border-radius:3px; transition:width .3s ease; }
		.mvs-migration-progress-text { font-size:12px; color:#646970; margin-top:6px; }
		.mvs-migration-options { display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
		.mvs-migration-card--inactive .mvs-migration-card__icon { background:#c3c4c7; }
		</style>

		<script>
		(function() {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'mvs_migration' ) ); ?>;
			var batchSize = <?php echo absint( self::BATCH_SIZE ); ?>;

			document.querySelectorAll('.mvs-migration-start').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var card    = this.closest('.mvs-migration-card');
					var source  = this.dataset.source;
					var dryRun  = card.querySelector('.mvs-opt-dryrun') ? card.querySelector('.mvs-opt-dryrun').checked : false;
					var skipAlb = card.querySelector('.mvs-opt-skipalbums') ? card.querySelector('.mvs-opt-skipalbums').checked : false;
					var bbSrc   = card.querySelector('.mvs-opt-bbsource') ? card.querySelector('.mvs-opt-bbsource').value : 'all';
					var total   = parseInt(this.dataset.total, 10) || 0;

					startImport(card, source, dryRun, skipAlb, bbSrc, total, 0, 0, 0, 0);
				});
			});

			function startImport(card, source, dryRun, skipAlbums, bbSource, total, offset, imported, skipped, errors) {
				var btn        = card.querySelector('.mvs-migration-start');
				var progWrap   = card.querySelector('.mvs-migration-progress-wrap');
				var progBar    = card.querySelector('.mvs-migration-progress-bar');
				var progText   = card.querySelector('.mvs-migration-progress-text');
				var footer     = card.querySelector('.mvs-migration-card__footer');

				btn.disabled = true;
				btn.textContent = dryRun
					? <?php echo wp_json_encode( __( 'Running dry run...', 'wpmediaverse' ) ); ?>
					: <?php echo wp_json_encode( __( 'Importing...', 'wpmediaverse' ) ); ?>;
				progWrap.style.display = 'block';

				var data = 'action=mvs_migration_batch'
					+ '&_nonce=' + encodeURIComponent(nonce)
					+ '&source=' + encodeURIComponent(source)
					+ '&offset=' + offset
					+ '&batch_size=' + batchSize
					+ '&dry_run=' + (dryRun ? '1' : '0')
					+ '&skip_albums=' + (skipAlbums ? '1' : '0')
					+ '&bb_source=' + encodeURIComponent(bbSource);

				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function() {
					var resp;
					try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }

					if (!resp || !resp.success) {
						progText.textContent = (resp && resp.data) ? resp.data.message : <?php echo wp_json_encode( __( 'Import failed. Check error logs.', 'wpmediaverse' ) ); ?>;
						progText.style.color = '#d63638';
						btn.disabled = false;
						btn.textContent = <?php echo wp_json_encode( __( 'Retry Import', 'wpmediaverse' ) ); ?>;
						return;
					}

					var r        = resp.data;
					imported    += r.batch_imported;
					skipped     += r.batch_skipped;
					errors      += r.batch_errors;
					var newOffset = r.next_offset;
					var pct      = total > 0 ? Math.min(100, Math.round((newOffset / total) * 100)) : 0;
					progBar.style.width = pct + '%';
					progText.textContent = imported + ' ' + <?php echo wp_json_encode( __( 'imported', 'wpmediaverse' ) ); ?>
						+ ', ' + skipped + ' ' + <?php echo wp_json_encode( __( 'skipped', 'wpmediaverse' ) ); ?>
						+ (errors ? ', ' + errors + ' ' + <?php echo wp_json_encode( __( 'errors', 'wpmediaverse' ) ); ?> : '')
						+ ' — ' + pct + '%';

					if (r.done) {
						progBar.style.width = '100%';
						var summary = dryRun
							? (imported + ' ' + <?php echo wp_json_encode( __( 'items would be imported (dry run — no changes made).', 'wpmediaverse' ) ); ?>)
							: (imported + ' ' + <?php echo wp_json_encode( __( 'items imported successfully.', 'wpmediaverse' ) ); ?>
								+ (skipped ? ' ' + skipped + ' ' + <?php echo wp_json_encode( __( 'skipped.', 'wpmediaverse' ) ); ?> : '')
								+ (errors ? ' ' + errors + ' ' + <?php echo wp_json_encode( __( 'errors.', 'wpmediaverse' ) ); ?> : ''));
						progText.textContent = summary;
						progText.style.color = errors > 0 ? '#b32d2e' : '#00a32a';
						btn.disabled = false;
						btn.textContent = <?php echo wp_json_encode( __( 'Run Again', 'wpmediaverse' ) ); ?>;
					} else {
						startImport(card, source, dryRun, skipAlbums, bbSource, total, newOffset, imported, skipped, errors);
					}
				};
				xhr.send(data);
			}
		}());
		</script>
		<?php
	}

	/**
	 * Render a single platform migration card.
	 *
	 * @param string $source Platform slug: rtmedia, mediapress, or buddyboss.
	 */
	private function render_platform_card( string $source ): void {
		$info = $this->get_platform_info( $source );
		?>
		<div class="mvs-migration-card<?php echo $info['available'] ? '' : ' mvs-migration-card--inactive'; ?>">
			<div class="mvs-migration-card__header">
				<div class="mvs-migration-card__icon">
					<span class="dashicons <?php echo esc_attr( $info['icon'] ); ?>"></span>
				</div>
				<div>
					<h2 class="mvs-migration-card__title"><?php echo esc_html( $info['label'] ); ?></h2>
					<p class="mvs-migration-card__subtitle"><?php echo esc_html( $info['desc'] ); ?></p>
				</div>
				<?php if ( ! $info['available'] ) : ?>
					<span class="mvs-badge" style="margin-left:auto;background:#c3c4c7;color:#1d2327;padding:4px 10px;border-radius:10px;font-size:12px;">
						<?php esc_html_e( 'Not detected', 'wpmediaverse' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="mvs-migration-card__body">
				<?php if ( $info['available'] ) : ?>
					<div class="mvs-migration-stats">
						<div class="mvs-migration-stat">
							<span class="mvs-migration-stat__num"><?php echo esc_html( number_format_i18n( $info['total'] ) ); ?></span>
							<span class="mvs-migration-stat__label"><?php esc_html_e( 'Available', 'wpmediaverse' ); ?></span>
						</div>
						<div class="mvs-migration-stat">
							<span class="mvs-migration-stat__num"><?php echo esc_html( number_format_i18n( $info['imported'] ) ); ?></span>
							<span class="mvs-migration-stat__label"><?php esc_html_e( 'Already imported', 'wpmediaverse' ); ?></span>
						</div>
						<div class="mvs-migration-stat">
							<span class="mvs-migration-stat__num"><?php echo esc_html( number_format_i18n( max( 0, $info['total'] - $info['imported'] ) ) ); ?></span>
							<span class="mvs-migration-stat__label"><?php esc_html_e( 'Remaining', 'wpmediaverse' ); ?></span>
						</div>
					</div>

					<?php if ( 'buddyboss' === $source ) : ?>
						<div style="margin-bottom:12px;">
							<label for="mvs-bb-source" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">
								<?php esc_html_e( 'Import from:', 'wpmediaverse' ); ?>
							</label>
							<select id="mvs-bb-source" class="mvs-opt-bbsource" style="width:200px;">
								<option value="all"><?php esc_html_e( 'All (photos + documents + videos)', 'wpmediaverse' ); ?></option>
								<option value="media"><?php esc_html_e( 'Photos only (bp_media)', 'wpmediaverse' ); ?></option>
								<option value="document"><?php esc_html_e( 'Documents only (bp_document)', 'wpmediaverse' ); ?></option>
								<option value="video"><?php esc_html_e( 'Videos only (bp_video)', 'wpmediaverse' ); ?></option>
							</select>
						</div>
					<?php endif; ?>

					<div class="mvs-migration-progress-wrap">
						<div class="mvs-migration-progress-bar-bg">
							<div class="mvs-migration-progress-bar"></div>
						</div>
						<div class="mvs-migration-progress-text"></div>
					</div>
				<?php else : ?>
					<p style="color:#646970;margin:0;">
						<?php echo esc_html( $info['not_found_msg'] ); ?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $info['available'] ) : ?>
				<div class="mvs-migration-card__footer">
					<button type="button"
						class="button button-primary mvs-migration-start"
						data-source="<?php echo esc_attr( $source ); ?>"
						data-total="<?php echo absint( $info['total'] ); ?>">
						<?php esc_html_e( 'Start Import', 'wpmediaverse' ); ?>
					</button>
					<label style="font-size:13px;">
						<input type="checkbox" class="mvs-opt-dryrun" />
						<?php esc_html_e( 'Dry run (preview only)', 'wpmediaverse' ); ?>
					</label>
					<label style="font-size:13px;">
						<input type="checkbox" class="mvs-opt-skipalbums" />
						<?php esc_html_e( 'Skip albums', 'wpmediaverse' ); ?>
					</label>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get platform detection info and item counts.
	 *
	 * @param string $source Platform slug.
	 * @return array{label:string, desc:string, icon:string, available:bool, total:int, imported:int, not_found_msg:string}
	 */
	private function get_platform_info( string $source ): array {
		switch ( $source ) {
			case 'rtmedia':
				return $this->detect_rtmedia();
			case 'mediapress':
				return $this->detect_mediapress();
			case 'buddyboss':
				return $this->detect_buddyboss();
			default:
				return array(
					'label'         => $source,
					'desc'          => '',
					'icon'          => 'dashicons-format-image',
					'available'     => false,
					'total'         => 0,
					'imported'      => 0,
					'not_found_msg' => '',
				);
		}
	}

	/**
	 * Detect rtMedia and count items.
	 *
	 * @return array
	 */
	private function detect_rtmedia(): array {
		global $wpdb;

		$base = array(
			'label'         => 'rtMedia',
			'desc'          => __( 'Migrate photos, videos, music, and documents from the rtMedia plugin.', 'wpmediaverse' ),
			'icon'          => 'dashicons-format-gallery',
			'available'     => false,
			'total'         => 0,
			'imported'      => 0,
			'not_found_msg' => __( 'rtMedia tables not found. Install and activate rtMedia, or run the migration on a site where rtMedia was previously used.', 'wpmediaverse' ),
		);

		$table = $wpdb->prefix . 'rt_rtm_media';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return $base;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$imported = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_mvs_rtmedia_id'
			)
		);

		$base['available'] = true;
		$base['total']     = $total;
		$base['imported']  = $imported;

		return $base;
	}

	/**
	 * Detect MediaPress and count items.
	 *
	 * @return array
	 */
	private function detect_mediapress(): array {
		$base = array(
			'label'         => 'MediaPress',
			'desc'          => __( 'Migrate photos, videos, audio, and documents from the MediaPress plugin.', 'wpmediaverse' ),
			'icon'          => 'dashicons-images-alt2',
			'available'     => false,
			'total'         => 0,
			'imported'      => 0,
			'not_found_msg' => __( 'MediaPress post types not found. Install and activate MediaPress, or run the migration on a site where MediaPress was previously used.', 'wpmediaverse' ),
		);

		if ( ! post_type_exists( 'mpp-media' ) ) {
			return $base;
		}

		global $wpdb;

		$statuses = array( 'publish', 'private', 'pending', 'draft' );
		$total    = 0;
		foreach ( $statuses as $st ) {
			$counts = wp_count_posts( 'mpp-media' );
			if ( isset( $counts->$st ) ) {
				$total += (int) $counts->$st;
			}
		}

		$imported = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_mvs_mpp_id'
			)
		);

		$base['available'] = true;
		$base['total']     = $total;
		$base['imported']  = $imported;

		return $base;
	}

	/**
	 * Detect BuddyBoss and count items across all three tables.
	 *
	 * @return array
	 */
	private function detect_buddyboss(): array {
		global $wpdb;

		$base = array(
			'label'         => 'BuddyBoss Platform',
			'desc'          => __( 'Migrate photos, documents, and videos from BuddyBoss Platform.', 'wpmediaverse' ),
			'icon'          => 'dashicons-buddicons-buddypress-logo',
			'available'     => false,
			'total'         => 0,
			'imported'      => 0,
			'not_found_msg' => __( 'BuddyBoss media tables (bp_media, bp_document, bp_video) not found. Install and activate BuddyBoss Platform, or run the migration on a site where BuddyBoss was previously used.', 'wpmediaverse' ),
		);

		$tables = array(
			'media'    => $wpdb->prefix . 'bp_media',
			'document' => $wpdb->prefix . 'bp_document',
			'video'    => $wpdb->prefix . 'bp_video',
		);

		$total    = 0;
		$imported = 0;
		$found    = false;

		foreach ( $tables as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			$found = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$meta_key = '_mvs_bb_' . $key . '_id';
			$imported += (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				)
			);
		}

		if ( ! $found ) {
			return $base;
		}

		$base['available'] = true;
		$base['total']     = $total;
		$base['imported']  = $imported;

		return $base;
	}

	// =========================================================================
	// AJAX handlers
	// =========================================================================

	/**
	 * AJAX: Detect platform availability and counts.
	 * Returns JSON with availability data for all three platforms.
	 */
	public function ajax_detect(): void {
		check_ajax_referer( 'mvs_migration', '_nonce' );

		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		wp_send_json_success(
			array(
				'rtmedia'    => $this->detect_rtmedia(),
				'mediapress' => $this->detect_mediapress(),
				'buddyboss'  => $this->detect_buddyboss(),
			)
		);
	}

	/**
	 * AJAX: Run one batch of imports for the requested source.
	 *
	 * Expected POST params:
	 *   source      - rtmedia | mediapress | buddyboss
	 *   offset      - integer, batch start position
	 *   batch_size  - integer (default BATCH_SIZE)
	 *   dry_run     - 0|1
	 *   skip_albums - 0|1
	 *   bb_source   - media|document|video|all (buddyboss only)
	 *
	 * Returns JSON:
	 *   batch_imported, batch_skipped, batch_errors, next_offset, done
	 */
	public function ajax_run_batch(): void {
		check_ajax_referer( 'mvs_migration', '_nonce' );

		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$source      = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$offset      = absint( $_POST['offset'] ?? 0 );
		$batch_size  = absint( $_POST['batch_size'] ?? self::BATCH_SIZE );
		$dry_run     = ! empty( $_POST['dry_run'] ) && '1' === $_POST['dry_run'];
		$skip_albums = ! empty( $_POST['skip_albums'] ) && '1' === $_POST['skip_albums'];
		$bb_source   = sanitize_key( wp_unslash( $_POST['bb_source'] ?? 'all' ) );

		if ( ! in_array( $source, array( 'rtmedia', 'mediapress', 'buddyboss' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid source.' ) );
		}

		$result = array(
			'batch_imported' => 0,
			'batch_skipped'  => 0,
			'batch_errors'   => 0,
			'next_offset'    => $offset,
			'done'           => false,
		);

		switch ( $source ) {
			case 'rtmedia':
				$result = $this->run_batch_rtmedia( $offset, $batch_size, $dry_run, $skip_albums );
				break;
			case 'mediapress':
				$result = $this->run_batch_mediapress( $offset, $batch_size, $dry_run, $skip_albums );
				break;
			case 'buddyboss':
				$result = $this->run_batch_buddyboss( $offset, $batch_size, $dry_run, $skip_albums, $bb_source );
				break;
		}

		wp_send_json_success( $result );
	}

	// =========================================================================
	// Batch import logic — rtMedia
	// =========================================================================

	/**
	 * Run one batch of rtMedia imports.
	 *
	 * @param int  $offset      Batch start offset.
	 * @param int  $batch_size  Items per batch.
	 * @param bool $dry_run     If true, count but don't insert.
	 * @param bool $skip_albums If true, skip album creation.
	 * @return array Batch result.
	 */
	private function run_batch_rtmedia( int $offset, int $batch_size, bool $dry_run, bool $skip_albums ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rt_rtm_media';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		if ( empty( $items ) ) {
			return array( 'batch_imported' => 0, 'batch_skipped' => 0, 'batch_errors' => 0, 'next_offset' => $offset, 'done' => true );
		}

		$imported  = 0;
		$skipped   = 0;
		$errors    = 0;
		$album_map = array();

		foreach ( $items as $item ) {
			$existing = get_posts(
				array(
					'post_type'      => 'mvs_media',
					'meta_key'       => '_mvs_rtmedia_id', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $item->id, // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);

			if ( ! empty( $existing ) ) {
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				++$imported;
				continue;
			}

			$attachment_url = wp_get_attachment_url( (int) $item->media_id );
			if ( ! $attachment_url ) {
				++$errors;
				continue;
			}

			$type_map   = array( 'photo' => 'image', 'video' => 'video', 'music' => 'audio', 'document' => 'document', 'other' => 'document' );
			$media_type = $type_map[ $item->media_type ] ?? 'document';
			$priv_map   = array( 0 => 'public', 20 => 'loggedin', 40 => 'friends', 60 => 'private' );
			$privacy    = $priv_map[ (int) ( $item->privacy ?? 0 ) ] ?? 'public';
			$title      = ! empty( $item->media_title ) ? sanitize_text_field( $item->media_title ) : __( 'Imported Media', 'wpmediaverse' );

			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mvs_media',
					'post_title'  => $title,
					'post_status' => 'publish',
					'post_author' => (int) $item->media_author,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				++$errors;
				continue;
			}

			update_post_meta( $post_id, '_mvs_file_url', esc_url_raw( $attachment_url ) );
			update_post_meta( $post_id, '_mvs_media_type', $media_type );
			update_post_meta( $post_id, '_mvs_privacy', $privacy );
			update_post_meta( $post_id, '_mvs_rtmedia_id', (int) $item->id );
			update_post_meta( $post_id, '_mvs_attachment_id', (int) $item->media_id );
			set_post_thumbnail( $post_id, (int) $item->media_id );

			$mime = get_post_mime_type( (int) $item->media_id );
			if ( $mime ) {
				update_post_meta( $post_id, '_mvs_file_type', sanitize_mime_type( $mime ) );
			}
			$file_path = get_attached_file( (int) $item->media_id );
			if ( $file_path && file_exists( $file_path ) ) {
				update_post_meta( $post_id, '_mvs_file_size', (int) filesize( $file_path ) );
			}

			if ( ! $skip_albums && ! empty( $item->album_id ) && (int) $item->album_id > 0 ) {
				$rtm_album_id = (int) $item->album_id;
				if ( ! isset( $album_map[ $rtm_album_id ] ) ) {
					$album_map[ $rtm_album_id ] = $this->create_album_from_rtmedia( $rtm_album_id, (int) $item->media_author, $table );
				}
				if ( $album_map[ $rtm_album_id ] ) {
					$this->add_to_album( $album_map[ $rtm_album_id ], $post_id );
				}
			}

			++$imported;
		}

		$next_offset = $offset + count( $items );
		$done        = count( $items ) < $batch_size;

		return compact( 'imported', 'skipped', 'errors', 'next_offset', 'done' ) + array( 'batch_imported' => $imported, 'batch_skipped' => $skipped, 'batch_errors' => $errors );
	}

	// =========================================================================
	// Batch import logic — MediaPress
	// =========================================================================

	/**
	 * Run one batch of MediaPress imports.
	 *
	 * @param int  $offset      Batch start offset.
	 * @param int  $batch_size  Items per batch.
	 * @param bool $dry_run     If true, count but don't insert.
	 * @param bool $skip_albums If true, skip album creation.
	 * @return array Batch result.
	 */
	private function run_batch_mediapress( int $offset, int $batch_size, bool $dry_run, bool $skip_albums ): array {
		$statuses = array( 'publish', 'private', 'pending', 'draft' );
		$items    = get_posts(
			array(
				'post_type'      => 'mpp-media',
				'post_status'    => $statuses,
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'all',
			)
		);

		if ( empty( $items ) ) {
			return array( 'batch_imported' => 0, 'batch_skipped' => 0, 'batch_errors' => 0, 'next_offset' => $offset, 'done' => true );
		}

		$imported  = 0;
		$skipped   = 0;
		$errors    = 0;
		$album_map = array();

		foreach ( $items as $item ) {
			$existing = get_posts(
				array(
					'post_type'      => 'mvs_media',
					'meta_key'       => '_mvs_mpp_id', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $item->ID, // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);

			if ( ! empty( $existing ) ) {
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				++$imported;
				continue;
			}

			$file_url = get_post_meta( $item->ID, '_mpp_src', true );
			if ( ! $file_url ) {
				$file_url = wp_get_attachment_url( $item->ID );
			}
			if ( ! $file_url ) {
				++$errors;
				continue;
			}

			$mpp_type   = get_post_meta( $item->ID, '_mpp_media_type', true );
			$type_map   = array( 'photo' => 'image', 'video' => 'video', 'music' => 'audio', 'audio' => 'audio', 'doc' => 'document' );
			$media_type = $type_map[ $mpp_type ] ?? 'document';

			$mpp_priv  = get_post_meta( $item->ID, '_mpp_privacy', true );
			$priv_map  = array( 'public' => 'public', 'loggedin' => 'loggedin', 'friends' => 'friends', 'onlyme' => 'private' );
			$privacy   = 'private' === $item->post_status ? 'private' : ( $priv_map[ $mpp_priv ] ?? 'public' );

			$post_id = wp_insert_post(
				array(
					'post_type'     => 'mvs_media',
					'post_title'    => $item->post_title ?: __( 'Imported Media', 'wpmediaverse' ),
					'post_content'  => $item->post_content,
					'post_status'   => 'publish',
					'post_author'   => $item->post_author,
					'post_date'     => $item->post_date,
					'post_date_gmt' => $item->post_date_gmt,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				++$errors;
				continue;
			}

			update_post_meta( $post_id, '_mvs_file_url', esc_url_raw( $file_url ) );
			update_post_meta( $post_id, '_mvs_media_type', $media_type );
			update_post_meta( $post_id, '_mvs_privacy', $privacy );
			update_post_meta( $post_id, '_mvs_mpp_id', $item->ID );

			$attach_id = (int) get_post_meta( $item->ID, '_mpp_attachment_id', true );
			if ( ! $attach_id && wp_attachment_is_image( $item->ID ) ) {
				$attach_id = $item->ID;
			}
			if ( $attach_id ) {
				update_post_meta( $post_id, '_mvs_attachment_id', $attach_id );
				set_post_thumbnail( $post_id, $attach_id );
			}

			$mime = get_post_mime_type( $item->ID ) ?: get_post_meta( $item->ID, '_mpp_mime_type', true );
			if ( $mime ) {
				update_post_meta( $post_id, '_mvs_file_type', sanitize_mime_type( $mime ) );
			}
			$file_path = get_attached_file( $item->ID );
			if ( $file_path && file_exists( $file_path ) ) {
				update_post_meta( $post_id, '_mvs_file_size', (int) filesize( $file_path ) );
			}

			if ( ! $skip_albums ) {
				$gallery_id = (int) get_post_meta( $item->ID, '_mpp_gallery_id', true );
				if ( $gallery_id > 0 ) {
					if ( ! isset( $album_map[ $gallery_id ] ) ) {
						$album_map[ $gallery_id ] = $this->create_album_from_mpp_gallery( $gallery_id, (int) $item->post_author );
					}
					if ( $album_map[ $gallery_id ] ) {
						$this->add_to_album( $album_map[ $gallery_id ], $post_id );
					}
				}
			}

			++$imported;
		}

		$next_offset = $offset + count( $items );
		$done        = count( $items ) < $batch_size;

		return array( 'batch_imported' => $imported, 'batch_skipped' => $skipped, 'batch_errors' => $errors, 'next_offset' => $next_offset, 'done' => $done );
	}

	// =========================================================================
	// Batch import logic — BuddyBoss
	// =========================================================================

	/**
	 * Run one batch of BuddyBoss imports.
	 *
	 * @param int    $offset      Batch start offset.
	 * @param int    $batch_size  Items per batch.
	 * @param bool   $dry_run     If true, count but don't insert.
	 * @param bool   $skip_albums If true, skip album creation.
	 * @param string $bb_source   One of: all, media, document, video.
	 * @return array Batch result.
	 */
	private function run_batch_buddyboss( int $offset, int $batch_size, bool $dry_run, bool $skip_albums, string $bb_source ): array {
		global $wpdb;

		$tables = array(
			'media'    => $wpdb->prefix . 'bp_media',
			'document' => $wpdb->prefix . 'bp_document',
			'video'    => $wpdb->prefix . 'bp_video',
		);

		if ( 'all' !== $bb_source && isset( $tables[ $bb_source ] ) ) {
			$tables = array( $bb_source => $tables[ $bb_source ] );
		}

		// Find the active table at this offset (each table processed sequentially).
		$cumulative = 0;
		$active_key = null;
		$active_table = null;
		$table_offset = $offset;

		foreach ( $tables as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

			if ( $offset < $cumulative + $count ) {
				$active_key   = $key;
				$active_table = $table;
				$table_offset = $offset - $cumulative;
				break;
			}

			$cumulative += $count;
		}

		if ( ! $active_table ) {
			return array( 'batch_imported' => 0, 'batch_skipped' => 0, 'batch_errors' => 0, 'next_offset' => $offset, 'done' => true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$active_table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$batch_size,
				$table_offset
			)
		);

		if ( empty( $items ) ) {
			return array( 'batch_imported' => 0, 'batch_skipped' => 0, 'batch_errors' => 0, 'next_offset' => $offset, 'done' => true );
		}

		$imported  = 0;
		$skipped   = 0;
		$errors    = 0;
		$album_map = array();
		$meta_key  = '_mvs_bb_' . $active_key . '_id';

		foreach ( $items as $item ) {
			$existing = get_posts(
				array(
					'post_type'      => 'mvs_media',
					'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $item->id, // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);

			if ( ! empty( $existing ) ) {
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				++$imported;
				continue;
			}

			$attach_id = isset( $item->attachment_id ) ? (int) $item->attachment_id : 0;
			if ( ! $attach_id ) {
				++$errors;
				continue;
			}

			$file_url = wp_get_attachment_url( $attach_id );
			if ( ! $file_url ) {
				++$errors;
				continue;
			}

			$mime       = get_post_mime_type( $attach_id );
			$media_type = $this->bb_resolve_media_type( $active_key, $mime );

			$priv_map = array( 'public' => 'public', 'loggedin' => 'loggedin', 'onlyme' => 'private', 'friends' => 'friends', 'groupmembers' => 'loggedin' );
			$privacy  = $priv_map[ $item->privacy ?? 'public' ] ?? 'public';

			$title          = ! empty( $item->title ) ? sanitize_text_field( $item->title ) : __( 'Imported Media', 'wpmediaverse' );
			$post_date_gmt  = ! empty( $item->date_created ) ? gmdate( 'Y-m-d H:i:s', strtotime( $item->date_created ) ) : current_time( 'mysql', true );
			$post_date      = get_date_from_gmt( $post_date_gmt );

			$post_id = wp_insert_post(
				array(
					'post_type'     => 'mvs_media',
					'post_title'    => $title,
					'post_status'   => 'publish',
					'post_author'   => isset( $item->user_id ) ? (int) $item->user_id : 0,
					'post_date'     => $post_date,
					'post_date_gmt' => $post_date_gmt,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				++$errors;
				continue;
			}

			update_post_meta( $post_id, '_mvs_file_url', esc_url_raw( $file_url ) );
			update_post_meta( $post_id, '_mvs_media_type', $media_type );
			update_post_meta( $post_id, '_mvs_privacy', $privacy );
			update_post_meta( $post_id, '_mvs_attachment_id', $attach_id );
			update_post_meta( $post_id, $meta_key, (int) $item->id );
			set_post_thumbnail( $post_id, $attach_id );

			if ( $mime ) {
				update_post_meta( $post_id, '_mvs_file_type', sanitize_mime_type( $mime ) );
			}
			$file_path = get_attached_file( $attach_id );
			if ( $file_path && file_exists( $file_path ) ) {
				update_post_meta( $post_id, '_mvs_file_size', (int) filesize( $file_path ) );
			}
			if ( ! empty( $item->group_id ) ) {
				update_post_meta( $post_id, '_mvs_bb_group_id', (int) $item->group_id );
			}

			if ( ! $skip_albums && ! empty( $item->album_id ) && (int) $item->album_id > 0 ) {
				$bb_alb = (int) $item->album_id;
				if ( ! isset( $album_map[ $bb_alb ] ) ) {
					$album_map[ $bb_alb ] = $this->create_album_from_bb( $bb_alb, isset( $item->user_id ) ? (int) $item->user_id : 0 );
				}
				if ( $album_map[ $bb_alb ] ) {
					$this->add_to_album( $album_map[ $bb_alb ], $post_id );
				}
			}

			++$imported;
		}

		$next_offset = $offset + count( $items );
		$done        = count( $items ) < $batch_size;

		return array( 'batch_imported' => $imported, 'batch_skipped' => $skipped, 'batch_errors' => $errors, 'next_offset' => $next_offset, 'done' => $done );
	}

	// =========================================================================
	// Shared helpers
	// =========================================================================

	/**
	 * Resolve MVS media type from BuddyBoss table context and MIME type.
	 *
	 * @param string $table_key One of: media, document, video.
	 * @param string $mime      MIME type string.
	 * @return string
	 */
	private function bb_resolve_media_type( string $table_key, string $mime ): string {
		if ( 'document' === $table_key ) {
			return 'document';
		}
		if ( 'video' === $table_key ) {
			return 'video';
		}
		if ( str_starts_with( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( str_starts_with( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( str_starts_with( $mime, 'audio/' ) ) {
			return 'audio';
		}
		return 'document';
	}

	/**
	 * Create an MVS album from an rtMedia album row.
	 *
	 * @param int    $rtm_album_id  rtMedia album ID.
	 * @param int    $fallback_author Fallback author ID.
	 * @param string $table         rtMedia table name.
	 * @return int|false
	 */
	private function create_album_from_rtmedia( int $rtm_album_id, int $fallback_author, string $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT media_title, media_author FROM {$table} WHERE id = %d",
				$rtm_album_id
			)
		);

		$title  = ( $row && $row->media_title ) ? sanitize_text_field( $row->media_title ) : __( 'Imported Album', 'wpmediaverse' );
		$author = ( $row && $row->media_author ) ? (int) $row->media_author : $fallback_author;

		$album_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => $author,
			),
			true
		);

		if ( is_wp_error( $album_id ) ) {
			return false;
		}
		update_post_meta( $album_id, '_mvs_rtmedia_album_id', $rtm_album_id );
		return $album_id;
	}

	/**
	 * Create an MVS album from a MediaPress mpp-gallery post.
	 *
	 * @param int $gallery_id     mpp-gallery post ID.
	 * @param int $fallback_author Fallback author ID.
	 * @return int|false
	 */
	private function create_album_from_mpp_gallery( int $gallery_id, int $fallback_author ) {
		$gallery = get_post( $gallery_id );
		$title   = ( $gallery && $gallery->post_title ) ? sanitize_text_field( $gallery->post_title ) : __( 'Imported Album', 'wpmediaverse' );
		$author  = ( $gallery && $gallery->post_author ) ? (int) $gallery->post_author : $fallback_author;

		$album_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => $author,
			),
			true
		);

		if ( is_wp_error( $album_id ) ) {
			return false;
		}
		update_post_meta( $album_id, '_mvs_mpp_gallery_id', $gallery_id );
		return $album_id;
	}

	/**
	 * Create an MVS album from a BuddyBoss bp_media_albums row.
	 *
	 * @param int $bb_album_id     BuddyBoss album ID.
	 * @param int $fallback_author Fallback author ID.
	 * @return int|false
	 */
	private function create_album_from_bb( int $bb_album_id, int $fallback_author ) {
		global $wpdb;

		$albums_table = $wpdb->prefix . 'bp_media_albums';
		$title        = __( 'Imported Album', 'wpmediaverse' );
		$author       = $fallback_author;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $albums_table ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT title, user_id FROM {$albums_table} WHERE id = %d",
					$bb_album_id
				)
			);
			if ( $row ) {
				if ( $row->title ) {
					$title = sanitize_text_field( $row->title );
				}
				if ( $row->user_id ) {
					$author = (int) $row->user_id;
				}
			}
		}

		$album_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => $author,
			),
			true
		);

		if ( is_wp_error( $album_id ) ) {
			return false;
		}
		update_post_meta( $album_id, '_mvs_bb_album_id', $bb_album_id );
		return $album_id;
	}

	/**
	 * Add a media item to an MVS album via mvs_album_items.
	 *
	 * @param int $album_id MVS album post ID.
	 * @param int $media_id MVS media post ID.
	 */
	private function add_to_album( int $album_id, int $media_id ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'mvs_album_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max_pos = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(position) FROM {$table} WHERE album_id = %d", $album_id )
		);
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'album_id' => $album_id,
				'media_id' => $media_id,
				'position' => $max_pos + 1,
				'added_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}
}
