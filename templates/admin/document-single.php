<?php
/**
 * One document, in the admin.
 *
 * The list screen could only destroy things. This is where an owner sees what a
 * document actually is and corrects the things members get wrong — a title
 * typed in a hurry, a slug that reads badly, missing tags.
 *
 * Markup only (Coding Rule 4). Every value arrives escaped or is escaped here.
 *
 * Expects: $mvs_id, $mvs_row, $mvs_user, $mvs_trashed, $mvs_permalink,
 *          $mvs_tags, $mvs_notice, $mvs_extra.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

$mvs_list_url = add_query_arg( 'page', \WPMediaVerse\Admin\DocumentListPage::SLUG, admin_url( 'admin.php' ) );
$mvs_type     = (string) ( $mvs_row['file_type'] ?? '' );
$mvs_privacy  = (string) ( $mvs_row['privacy'] ?? 'private' );
?>
<div class="wrap wpmediaverse-admin mvs-documents-admin">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Edit Document', 'wpmediaverse' ); ?></h1>
	<a href="<?php echo esc_url( $mvs_list_url ); ?>" class="page-title-action">
		<?php esc_html_e( 'Back to Documents', 'wpmediaverse' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( '' !== $mvs_notice ) : ?>
		<div class="notice <?php echo esc_attr( \WPMediaVerse\Admin\DocumentListPage::notice_class() ); ?> is-dismissible">
			<p><?php echo esc_html( $mvs_notice ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $mvs_trashed ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'This document is in the trash. It is not served to anyone, including its owner, until it is restored.', 'wpmediaverse' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// WordPress's own edit-screen structure — #poststuff, a two-column
	// metabox-holder, postboxes. Not a custom layout: an owner already knows
	// how a WordPress edit screen works, and a bespoke one makes them learn a
	// second thing that behaves slightly differently for no gain.
	?>
	<div id="poststuff">
		<div id="post-body" class="metabox-holder columns-2">

			<div id="post-body-content">
				<form method="post">
					<?php wp_nonce_field( 'mvs_save_document_' . $mvs_id ); ?>
					<input type="hidden" name="media_id" value="<?php echo esc_attr( (string) $mvs_id ); ?>" />

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Document details', 'wpmediaverse' ); ?></span></h2>
						<div class="inside">

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mvs-title"><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label></th>
						<td>
							<input id="mvs-title" name="mvs_title" type="text" class="regular-text"
								value="<?php echo esc_attr( (string) ( $mvs_row['title'] ?? '' ) ); ?>" required />
							<p class="description"><?php esc_html_e( 'What the member and everyone they share it with sees.', 'wpmediaverse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mvs-slug"><?php esc_html_e( 'Slug', 'wpmediaverse' ); ?></label></th>
						<td>
							<input id="mvs-slug" name="mvs_slug" type="text" class="regular-text"
								value="<?php echo esc_attr( (string) ( $mvs_row['slug'] ?? '' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'The address of the document. Changing it breaks links anybody already holds, so it is left alone unless you change it here.', 'wpmediaverse' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mvs-description"><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label></th>
						<td>
							<textarea id="mvs-description" name="mvs_description" rows="4" class="large-text"><?php echo esc_textarea( (string) ( $mvs_row['description'] ?? '' ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mvs-tags"><?php esc_html_e( 'Tags', 'wpmediaverse' ); ?></label></th>
						<td>
							<input id="mvs-tags" name="mvs_tags" type="text" class="regular-text"
								value="<?php echo esc_attr( implode( ', ', array_map( 'strval', $mvs_tags ) ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Separated by commas. Clearing the field removes every tag.', 'wpmediaverse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mvs-privacy"><?php esc_html_e( 'Who can see it', 'wpmediaverse' ); ?></label></th>
						<td>
							<select id="mvs-privacy" name="mvs_privacy">
								<?php
								// From the one source, in the OWNER voice — this screen edits
								// somebody else's document, and the description below already
								// speaks about "the owner" in the third person.
								foreach ( \WPMediaVerse\Core\Plugin::document_privacy_labels( 'owner' ) as $mvs_privacy_value => $mvs_privacy_label ) :
									?>
									<option value="<?php echo esc_attr( $mvs_privacy_value ); ?>" <?php selected( $mvs_privacy, $mvs_privacy_value ); ?>>
										<?php echo esc_html( $mvs_privacy_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'People the owner shared it with directly keep their access whatever this says.', 'wpmediaverse' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

						</div>
					</div>

					<p class="submit">
						<button type="submit" name="mvs_save_document" value="1" class="button button-primary">
							<?php esc_html_e( 'Save changes', 'wpmediaverse' ); ?>
						</button>
					</p>
				</form>

				<?php
				/**
				 * Panels for the MAIN column of the document screen.
				 *
				 * The side column is the wrong home for anything a person reads.
				 * The document preview started there and a spreadsheet came out
				 * roughly one character per line — the panel is about 280px, and
				 * no amount of scrolling makes a grid readable at that width.
				 *
				 * So there are two slots: this one for content, and
				 * `mvs_document_admin_panels` for the facts about the file.
				 *
				 * @since 2.4.0
				 *
				 * @param string $html     Markup to append. Must be escaped.
				 * @param int    $media_id Document id.
				 * @param array  $row      The document's index row.
				 */
				echo apply_filters( 'mvs_document_admin_main_panels', '', $mvs_id, $mvs_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered markup; every core implementation escapes on the way in.
				?>
			</div>

			<div id="postbox-container-1" class="postbox-container">
			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'This document', 'wpmediaverse' ); ?></span></h2>
				<div class="inside">
					<table class="mvs-doc-edit__facts">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Owner', 'wpmediaverse' ); ?></th>
								<td><?php echo esc_html( $mvs_user ? $mvs_user->display_name : __( 'Unknown', 'wpmediaverse' ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
								<td><?php echo esc_html( '' !== $mvs_type ? $mvs_type : __( 'Unknown', 'wpmediaverse' ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Size', 'wpmediaverse' ); ?></th>
								<td><?php echo esc_html( ! empty( $mvs_row['file_size'] ) ? size_format( (int) $mvs_row['file_size'] ) : '—' ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Uploaded', 'wpmediaverse' ); ?></th>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) ( $mvs_row['created_at'] ?? '' ) ) ); ?></td>
							</tr>
						</tbody>
					</table>

					<?php if ( $mvs_permalink ) : ?>
						<p>
							<a class="button" href="<?php echo esc_url( $mvs_permalink ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'View on site', 'wpmediaverse' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<?php
			// Pro's panels: the preview, the download, where it lives on the
			// owner's drive. Escaped by whoever supplies it, per the filter's
			// contract.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $mvs_extra;
			?>
			</div>

		</div>
	</div>
</div>
