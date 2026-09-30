<?php
/**
 * Album settings meta box for the wp-admin album editor.
 *
 * An album's real state (privacy, type, cover) is written by the front end and
 * the REST API, but wp-admin offered only WordPress's own title/content/
 * featured-image controls, none of which the plugin reads. This is the admin
 * entry point for the same three values, going through AlbumService so it
 * cannot drift from the other two (Basecamp 10351283087).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Services\AlbumService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and saves the "Album Settings" meta box.
 */
class AlbumMetaBox {

	/**
	 * Most items offered in the cover picker. An album can hold thousands, and a
	 * select of every one is unusable; the pinned cover is always included.
	 * ponytail: first 100 items only, add a media search field if owners ask to
	 * pick a cover beyond them.
	 */
	private const COVER_CHOICES = 100;

	/**
	 * Album service.
	 *
	 * @var AlbumService
	 */
	private AlbumService $albums;

	/**
	 * Constructor.
	 *
	 * @param AlbumService $albums Album service.
	 */
	public function __construct( AlbumService $albums ) {
		$this->albums = $albums;
	}

	/**
	 * Hook into WordPress.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes_mvs_album', array( $this, 'register' ) );
		add_action( 'save_post_mvs_album', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box.
	 */
	public function register(): void {
		add_meta_box(
			'mvs_album_settings',
			__( 'Album Settings', 'wpmediaverse' ),
			array( $this, 'render' ),
			'mvs_album',
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Album post.
	 */
	public function render( $post ): void {
		$privacy = $this->albums->get_privacy( $post->ID );
		$choices = TemplateHelpers::privacy_choices();
		$labels  = TemplateHelpers::privacy_labels();
		// A stored level the picker no longer offers (group, custom) stays visible
		// and selected, so saving an unrelated field cannot silently change it.
		if ( ! isset( $choices[ $privacy ] ) ) {
			$choices[ $privacy ] = $labels[ $privacy ] ?? $privacy;
		}

		$type   = $this->albums->get_album_type( $post->ID );
		$total  = $this->albums->get_item_count( $post->ID );
		$pinned = $this->albums->get_cover_media_id( $post->ID );
		$items  = $total > 0 ? $this->albums->get_items_with_data( $post->ID, 'publish', self::COVER_CHOICES ) : array();

		wp_nonce_field( 'mvs_album_settings', 'mvs_album_settings_nonce' );
		?>
		<p class="mvs-metabox-field">
			<label for="mvs_album_privacy"><strong><?php esc_html_e( 'Who can see this album', 'wpmediaverse' ); ?></strong></label><br />
			<select id="mvs_album_privacy" name="mvs_album_privacy">
				<?php foreach ( $choices as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( $privacy, (string) $slug ); ?>><?php echo esc_html( (string) $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="mvs-metabox-field">
			<label for="mvs_album_type"><strong><?php esc_html_e( 'Album type', 'wpmediaverse' ); ?></strong></label><br />
			<input type="text" id="mvs_album_type" name="mvs_album_type" class="regular-text" value="<?php echo esc_attr( $type ); ?>" />
		</p>

		<p class="mvs-metabox-field">
			<label for="mvs_album_cover"><strong><?php esc_html_e( 'Cover', 'wpmediaverse' ); ?></strong></label><br />
			<?php if ( empty( $items ) ) : ?>
				<?php esc_html_e( 'Add media to this album to choose a cover.', 'wpmediaverse' ); ?>
			<?php else : ?>
				<select id="mvs_album_cover" name="mvs_album_cover">
					<option value="0" <?php selected( $pinned, 0 ); ?>><?php esc_html_e( 'Automatic (first image)', 'wpmediaverse' ); ?></option>
					<?php foreach ( $items as $item ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $item['media_id'] ); ?>" <?php selected( $pinned, (int) $item['media_id'] ); ?>><?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $total > self::COVER_CHOICES ) : ?>
					<span class="description">
						<?php
						/* translators: %d: number of items offered. */
						echo esc_html( sprintf( __( 'Showing the first %d items.', 'wpmediaverse' ), self::COVER_CHOICES ) );
						?>
					</span>
				<?php endif; ?>
			<?php endif; ?>
		</p>

		<p class="mvs-metabox-field">
			<strong><?php esc_html_e( 'Items', 'wpmediaverse' ); ?></strong><br />
			<?php echo esc_html( number_format_i18n( $total ) ); ?>
		</p>
		<?php
	}

	/**
	 * Save the meta box.
	 *
	 * @param int      $post_id Album post ID.
	 * @param \WP_Post $post    Album post.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['mvs_album_settings_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mvs_album_settings_nonce'] ) ), 'mvs_album_settings' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Privacy cascades to every item in the album, so write it only when it
		// changed and only to a level the picker (or the stored value) offers.
		if ( isset( $_POST['mvs_album_privacy'] ) ) {
			$privacy = sanitize_key( wp_unslash( $_POST['mvs_album_privacy'] ) );
			$current = $this->albums->get_privacy( $post_id );
			if ( $privacy !== $current && ( isset( TemplateHelpers::privacy_choices()[ $privacy ] ) ) ) {
				$this->albums->set_privacy( $post_id, $privacy );
			}
		}

		if ( isset( $_POST['mvs_album_type'] ) ) {
			$type = sanitize_text_field( wp_unslash( $_POST['mvs_album_type'] ) );
			$this->albums->set_album_type( $post_id, '' !== $type ? $type : 'default' );
		}

		if ( isset( $_POST['mvs_album_cover'] ) ) {
			$this->albums->set_cover( $post_id, absint( wp_unslash( $_POST['mvs_album_cover'] ) ) );
		}
	}
}
