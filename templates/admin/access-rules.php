<?php
/**
 * Access Rules mini-page template.
 *
 * @deprecated 2.0.0 The admin access-rules UX was retired in 2.0.0 — members get
 * simple Facebook-style privacy only and the site owner uses standard privacy, so
 * no surface creates NEW per-media rules. This file is now ORPHANED dead code: the
 * `MediaListPage::render_access()` / `handle_access_rule_actions()` methods that
 * loaded it were removed, and nothing `require`s it. Kept in place pending a
 * deprecation-tracked cleanup of the whole access-rules backend across a later
 * major (Production Rule #1/#5). ENFORCEMENT is unaffected — `AccessRulesService`
 * still gates any rules already stored, driven by REST (read/`/access/options`)
 * and WP-CLI; only the create/manage UI is gone.
 *
 * Renders the per-media access-rule management surface: the current rules for a
 * media item plus a form to add another (role / group membership / capability).
 * This file is pure presentation per Coding Rule #4 (Admin HTML lives in template
 * files, never inline echo in PHP classes).
 *
 * @package WPMediaVerse
 *
 * @var int    $media_id    Media ID being managed.
 * @var string $title       Media title (raw, escape on output).
 * @var string $list_url    Back-to-list URL.
 * @var string $form_url    Add-rule form action URL (view=access screen).
 * @var string $add_nonce   Nonce field markup for the add-rule form.
 * @var array  $rules       Current rule rows ({id,rule_type,rule_value,...}).
 * @var array  $options     Builder options: rule_types, roles, bp_groups_active.
 * @var array  $role_labels Map of role slug => display label.
 * @var array  $type_labels Map of rule_type => display label.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wpmediaverse-admin">
	<div class="mvs-page-header">
		<div class="mvs-page-header__left">
			<h1 class="mvs-page-header__title">
				<i data-lucide="lock"></i>
				<?php
				/* translators: %d: media id */
				echo esc_html( sprintf( __( 'Access Rules — Media #%d', 'wpmediaverse' ), $media_id ) );
				?>
			</h1>
			<p class="mvs-page-header__desc">
				<a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All media', 'wpmediaverse' ); ?></a>
			</p>
		</div>
	</div>

	<div class="mvs-admin-widget">
		<div class="mvs-widget-body">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Media', 'wpmediaverse' ); ?></th>
						<td><strong><?php echo esc_html( '' !== $title ? $title : __( '(no title)', 'wpmediaverse' ) ); ?></strong></td>
					</tr>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Access rules restrict who can view this media. A viewer who matches ANY rule below may view it; if none match, access falls back to the media’s Privacy setting. With WPMediaVerse Pro active, a ruled image also gets a watermarked preview.', 'wpmediaverse' ); ?>
			</p>

			<h2><?php esc_html_e( 'Current rules', 'wpmediaverse' ); ?></h2>
			<?php if ( empty( $rules ) ) : ?>
				<p class="description"><em><?php esc_html_e( 'No access rules yet. This media uses its Privacy setting alone.', 'wpmediaverse' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped mvs-access-rules-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Value', 'wpmediaverse' ); ?></th>
							<th scope="col" class="mvs-access-rules-table__actions"><?php esc_html_e( 'Actions', 'wpmediaverse' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $rules as $rule ) :
							$rule_id    = (int) $rule['id'];
							$rule_type  = (string) $rule['rule_type'];
							$rule_value = (string) $rule['rule_value'];

							$type_label = $type_labels[ $rule_type ] ?? ucfirst( $rule_type );
							if ( 'role' === $rule_type ) {
								$value_label = $role_labels[ $rule_value ] ?? $rule_value;
							} elseif ( 'membership' === $rule_type ) {
								/* translators: %s: BuddyPress group id */
								$value_label = sprintf( __( 'Group #%s', 'wpmediaverse' ), $rule_value );
							} else {
								$value_label = $rule_value;
							}

							$delete_url = wp_nonce_url(
								add_query_arg(
									array(
										'page'     => 'mvs-media',
										'view'     => 'access',
										'action'   => 'delete_access_rule',
										'media_id' => $media_id,
										'rule_id'  => $rule_id,
									),
									admin_url( 'admin.php' )
								),
								'mvs_delete_access_rule_' . $rule_id
							);
							?>
							<tr>
								<td><?php echo esc_html( $type_label ); ?></td>
								<td><code><?php echo esc_html( $value_label ); ?></code></td>
								<td class="mvs-access-rules-table__actions">
									<a class="button button-small button-link-delete" href="<?php echo esc_url( $delete_url ); ?>"
										data-mvs-confirm="<?php esc_attr_e( 'Remove this access rule?', 'wpmediaverse' ); ?>">
										<?php esc_html_e( 'Remove', 'wpmediaverse' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a rule', 'wpmediaverse' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="mvs-access-rule-add-form">
				<?php
				// Cap + nonce: the nonce field is emitted here; the matching
				// check_admin_referer() + current_user_can( 'manage_mvs_access' )
				// pair lives in MediaListPage::handle_access_rule_actions().
				echo $add_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() returns safe markup.
				?>
				<input type="hidden" name="media_id" value="<?php echo esc_attr( (string) $media_id ); ?>" />
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="mvs-access-rule-type"><?php esc_html_e( 'Rule type', 'wpmediaverse' ); ?></label></th>
							<td>
								<select id="mvs-access-rule-type" name="rule_type">
									<?php foreach ( $options['rule_types'] as $type_opt ) : ?>
										<option value="<?php echo esc_attr( $type_opt['value'] ); ?>"><?php echo esc_html( $type_opt['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mvs-access-rule-value"><?php esc_html_e( 'Rule value', 'wpmediaverse' ); ?></label></th>
							<td>
								<input type="text" id="mvs-access-rule-value" name="rule_value" class="regular-text"
									list="mvs-access-role-list" autocomplete="off" />
								<datalist id="mvs-access-role-list">
									<?php foreach ( $options['roles'] as $role_opt ) : ?>
										<option value="<?php echo esc_attr( $role_opt['value'] ); ?>"><?php echo esc_html( $role_opt['label'] ); ?></option>
									<?php endforeach; ?>
								</datalist>
								<p class="description">
									<?php esc_html_e( 'For a role rule, use the role slug (e.g. subscriber) — start typing to pick one. For group membership, enter the BuddyPress group ID. For a capability, enter the capability name (e.g. read).', 'wpmediaverse' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" name="mvs_add_access_rule" value="1" class="button button-primary"><?php esc_html_e( 'Add rule', 'wpmediaverse' ); ?></button>
				</p>
			</form>
		</div>
	</div>
</div>
