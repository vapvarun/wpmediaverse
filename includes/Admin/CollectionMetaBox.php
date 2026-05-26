<?php
/**
 * Collection meta box.
 *
 * Adds a rule builder meta box to the mvs_collection post editor.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\CollectionService;

/**
 * Registers and renders the Collection Rules meta box.
 */
class CollectionMetaBox {

	/**
	 * Collection service.
	 *
	 * @var CollectionService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param CollectionService $service Collection service instance.
	 */
	public function __construct( CollectionService $service ) {
		$this->service = $service;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post_mvs_collection', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box.
	 */
	public function register(): void {
		add_meta_box(
			'mvs_collection_rules',
			__( 'Collection Settings', 'wpmediaverse' ),
			array( $this, 'render' ),
			'mvs_collection',
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render( $post ): void {
		$collection_type = $this->service->get_type( $post->ID );
		$rules           = $this->service->get_rules( $post->ID );

		wp_enqueue_style(
			'mvs-admin-collection-metabox',
			MVS_PLUGIN_URL . 'assets/css/collection-metabox.css',
			array(),
			MVS_VERSION
		);
		wp_enqueue_script(
			'mvs-admin-collection-metabox',
			MVS_PLUGIN_URL . 'assets/js/admin/collection-metabox.js',
			array(),
			MVS_VERSION,
			array( 'in_footer' => true )
		);
		wp_localize_script(
			'mvs-admin-collection-metabox',
			'mvsCollectionMetabox',
			array( 'ruleCount' => count( $rules ) )
		);

		wp_nonce_field( 'mvs_collection_rules', 'mvs_collection_rules_nonce' );

		// Get tags and categories for dropdowns.
		$tags       = get_terms(
			array(
				'taxonomy'   => 'mvs_tag',
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		$categories = get_terms(
			array(
				'taxonomy'   => 'mvs_category',
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		// Resolve current match count for smart collections.
		$match_count = 0;
		if ( 'smart' === $collection_type && ! empty( $rules ) ) {
			$resolved    = $this->service->resolve( $post->ID, 1, 1 );
			$match_count = $resolved['total'];
		}
		?>

		<div class="mvs-metabox-type-toggle">
			<label>
				<input type="radio" name="mvs_collection_type" value="manual" <?php checked( $collection_type, 'manual' ); ?> />
				<span><?php esc_html_e( 'Manual', 'wpmediaverse' ); ?></span>
			</label>
			<label>
				<input type="radio" name="mvs_collection_type" value="smart" <?php checked( $collection_type, 'smart' ); ?> />
				<span><?php esc_html_e( 'Smart', 'wpmediaverse' ); ?></span>
			</label>
		</div>

		<p class="mvs-metabox-hint<?php echo 'manual' !== $collection_type ? ' mvs-hidden' : ''; ?>" id="mvs-manual-hint">
			<?php esc_html_e( 'Manual collections are populated via the Favorites button on media items.', 'wpmediaverse' ); ?>
		</p>

		<div id="mvs-rules-wrap" class="mvs-metabox-rules-wrap<?php echo 'smart' !== $collection_type ? ' mvs-hidden' : ''; ?>">
			<p><strong><?php esc_html_e( 'Rules (all must match):', 'wpmediaverse' ); ?></strong></p>
			<div id="mvs-rules-list">
				<?php
				if ( empty( $rules ) ) {
					$rules = array(
						array(
							'key'   => '',
							'value' => '',
						),
					);
				}
				foreach ( $rules as $i => $rule ) :
					$this->render_rule_row( $i, $rule, $tags, $categories );
				endforeach;
				?>
			</div>
			<button type="button" class="mvs-btn mvs-metabox-add-rule" id="mvs-add-rule">
				+ <?php esc_html_e( 'Add Rule', 'wpmediaverse' ); ?>
			</button>

			<?php if ( $match_count > 0 ) : ?>
				<div class="mvs-metabox-preview">
					<?php
					printf(
						/* translators: %d: number of matching items */
						esc_html( _n( '%d media item matches', '%d media items match', $match_count, 'wpmediaverse' ) ),
						(int) $match_count
					);
					?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Template for new rule rows (cloned via JS) -->
		<table class="mvs-hidden" id="mvs-rule-template-wrap">
			<tbody>
				<tr>
					<td>
						<?php
						$this->render_rule_row(
							'__INDEX__',
							array(
								'key'   => '',
								'value' => '',
							),
							$tags,
							$categories
						);
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a single rule row.
	 *
	 * @param int|string $index      Row index.
	 * @param array      $rule       Rule data {key, value}.
	 * @param array      $tags       Available tags.
	 * @param array      $categories Available categories.
	 */
	private function render_rule_row( $index, array $rule, array $tags, array $categories ): void {
		$key   = $rule['key'] ?? '';
		$value = $rule['value'] ?? '';
		?>
		<div class="mvs-metabox-rule-row">
			<select name="mvs_rules[<?php echo esc_attr( $index ); ?>][key]" class="mvs-metabox-rule-key"
				aria-label="<?php esc_attr_e( 'Rule field', 'wpmediaverse' ); ?>">
				<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
				<option value="media_type" <?php selected( $key, 'media_type' ); ?>><?php esc_html_e( 'Media Type', 'wpmediaverse' ); ?></option>
				<option value="tag" <?php selected( $key, 'tag' ); ?>><?php esc_html_e( 'Tag', 'wpmediaverse' ); ?></option>
				<option value="category" <?php selected( $key, 'category' ); ?>><?php esc_html_e( 'Category', 'wpmediaverse' ); ?></option>
				<option value="author" <?php selected( $key, 'author' ); ?>><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></option>
				<option value="privacy" <?php selected( $key, 'privacy' ); ?>><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></option>
				<option value="date_after" <?php selected( $key, 'date_after' ); ?>><?php esc_html_e( 'Date After', 'wpmediaverse' ); ?></option>
				<option value="date_before" <?php selected( $key, 'date_before' ); ?>><?php esc_html_e( 'Date Before', 'wpmediaverse' ); ?></option>
			</select>

			<!-- Media Type -->
			<select name="mvs_rules[<?php echo esc_attr( $index ); ?>][value]" class="mvs-metabox-rule-value<?php echo 'media_type' !== $key ? ' mvs-hidden' : ''; ?>"
				data-for-key="media_type" <?php echo 'media_type' !== $key ? 'disabled' : ''; ?>
				aria-label="<?php esc_attr_e( 'Media type value', 'wpmediaverse' ); ?>">
				<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
				<option value="image" <?php selected( $value, 'image' ); ?>><?php esc_html_e( 'Image', 'wpmediaverse' ); ?></option>
				<option value="video" <?php selected( $value, 'video' ); ?>><?php esc_html_e( 'Video', 'wpmediaverse' ); ?></option>
				<option value="audio" <?php selected( $value, 'audio' ); ?>><?php esc_html_e( 'Audio', 'wpmediaverse' ); ?></option>
				<option value="document" <?php selected( $value, 'document' ); ?>><?php esc_html_e( 'Document', 'wpmediaverse' ); ?></option>
			</select>

			<!-- Privacy -->
			<select name="mvs_rules[<?php echo esc_attr( $index ); ?>][value]" class="mvs-metabox-rule-value<?php echo 'privacy' !== $key ? ' mvs-hidden' : ''; ?>"
				data-for-key="privacy" <?php echo 'privacy' !== $key ? 'disabled' : ''; ?>
				aria-label="<?php esc_attr_e( 'Privacy value', 'wpmediaverse' ); ?>">
				<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
				<option value="public" <?php selected( $value, 'public' ); ?>><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
				<option value="members" <?php selected( $value, 'members' ); ?>><?php esc_html_e( 'Members', 'wpmediaverse' ); ?></option>
				<option value="private" <?php selected( $value, 'private' ); ?>><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
			</select>

			<!-- Tag -->
			<select name="mvs_rules[<?php echo esc_attr( $index ); ?>][value]" class="mvs-metabox-rule-value<?php echo 'tag' !== $key ? ' mvs-hidden' : ''; ?>"
				data-for-key="tag" <?php echo 'tag' !== $key ? 'disabled' : ''; ?>
				aria-label="<?php esc_attr_e( 'Tag value', 'wpmediaverse' ); ?>">
				<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
				<?php foreach ( $tags as $tag ) : ?>
					<option value="<?php echo esc_attr( $tag->term_id ); ?>" <?php selected( $value, $tag->term_id ); ?>>
						<?php echo esc_html( $tag->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<!-- Category -->
			<select name="mvs_rules[<?php echo esc_attr( $index ); ?>][value]" class="mvs-metabox-rule-value<?php echo 'category' !== $key ? ' mvs-hidden' : ''; ?>"
				data-for-key="category" <?php echo 'category' !== $key ? 'disabled' : ''; ?>
				aria-label="<?php esc_attr_e( 'Category value', 'wpmediaverse' ); ?>">
				<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
				<?php foreach ( $categories as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $value, $cat->term_id ); ?>>
						<?php echo esc_html( $cat->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<!-- Date -->
			<input type="date" name="mvs_rules[<?php echo esc_attr( $index ); ?>][value]" class="mvs-metabox-rule-value-date"
				value="<?php echo esc_attr( ( 'date_after' === $key || 'date_before' === $key ) ? $value : '' ); ?>"
				class="mvs-metabox-rule-value-date<?php echo ( 'date_after' !== $key && 'date_before' !== $key ) ? ' mvs-hidden' : ''; ?>"
				<?php echo ( 'date_after' !== $key && 'date_before' !== $key ) ? 'disabled' : ''; ?>
				aria-label="<?php esc_attr_e( 'Date value', 'wpmediaverse' ); ?>" />

			<!-- Author (user ID) -->
			<input type="number" name="mvs_rules[<?php echo esc_attr( $index ); ?>][value]" class="mvs-metabox-rule-value-number"
				placeholder="<?php esc_attr_e( 'User ID', 'wpmediaverse' ); ?>"
				value="<?php echo esc_attr( 'author' === $key ? $value : '' ); ?>"
				class="mvs-metabox-rule-value-number<?php echo 'author' !== $key ? ' mvs-hidden' : ''; ?>"
				<?php echo 'author' !== $key ? 'disabled' : ''; ?>
				aria-label="<?php esc_attr_e( 'Author user ID', 'wpmediaverse' ); ?>" />

			<button type="button" class="mvs-metabox-remove-rule" aria-label="<?php esc_attr_e( 'Remove rule', 'wpmediaverse' ); ?>">&times;</button>
		</div>
		<?php
	}

	/**
	 * Save collection rules on post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified on the next line.
		if ( ! isset( $_POST['mvs_collection_rules_nonce'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field + wp_unslash applied below.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mvs_collection_rules_nonce'] ) ), 'mvs_collection_rules' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save collection type.
		$type = isset( $_POST['mvs_collection_type'] ) ? sanitize_key( $_POST['mvs_collection_type'] ) : 'manual';
		update_post_meta( $post_id, '_mvs_collection_type', $type );

		if ( 'smart' !== $type ) {
			return;
		}

		// Parse and save rules.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce verified above, individual fields sanitized below.
		$raw_rules   = isset( $_POST['mvs_rules'] ) && is_array( $_POST['mvs_rules'] ) ? wp_unslash( $_POST['mvs_rules'] ) : array();
		$clean_rules = array();

		foreach ( $raw_rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$key   = sanitize_key( $rule['key'] ?? '' );
			$value = sanitize_text_field( $rule['value'] ?? '' );

			if ( $key && $value ) {
				$clean_rules[] = array(
					'key'   => $key,
					'value' => $value,
				);
			}
		}

		$this->service->save_rules( $post_id, $clean_rules );
	}
}
