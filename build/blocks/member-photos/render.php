<?php
/**
 * Server-side render for the member-photos block.
 *
 * Auto-detects which user's media to show, in this priority order:
 *   1. Explicit `userId` block attribute (> 0).
 *   2. BuddyPress displayed member, when on a BP profile/group page.
 *   3. Post author, when on a single-author page (single posts/pages).
 *   4. Current logged-in user.
 *   5. None — render an empty state with copy that explains.
 *
 * Renders:
 *   - Optional header (avatar + display name + link to member profile).
 *   - A media grid scoped to that user — delegates to the existing
 *     mvs/media-grid block render so there's only one calling surface
 *     per capability (Coding Rule C).
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$mvs_member_user_id  = isset( $attributes['userId'] ) ? absint( $attributes['userId'] ) : 0;
$mvs_member_columns  = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;
$mvs_member_per_page = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : 12;
$mvs_member_type     = isset( $attributes['mediaType'] ) ? sanitize_text_field( $attributes['mediaType'] ) : '';
$mvs_member_header   = ! empty( $attributes['showHeader'] );
$mvs_member_actions  = ! empty( $attributes['showActions'] );

/**
 * Resolve the user ID to render. Returns 0 when no user can be resolved.
 */
$mvs_member_resolved_user_id = (function () use ( $mvs_member_user_id ) {
	// 1. Explicit attribute.
	if ( $mvs_member_user_id > 0 ) {
		return $mvs_member_user_id;
	}

	// 2. BuddyPress displayed member (profile or group context).
	if ( function_exists( 'bp_displayed_user_id' ) ) {
		$bp_user_id = (int) bp_displayed_user_id();
		if ( $bp_user_id > 0 ) {
			return $bp_user_id;
		}
	}

	// 3. Single-author page (post/page).
	if ( is_singular() ) {
		$post = get_post();
		if ( $post && (int) $post->post_author > 0 ) {
			return (int) $post->post_author;
		}
	}

	// 4. Current logged-in user.
	$current = get_current_user_id();
	if ( $current > 0 ) {
		return $current;
	}

	// 5. Nothing.
	return 0;
})();

$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-member-photos-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper = get_block_wrapper_attributes( array( 'class' => $mvs_classes ) );

// Empty state — no user resolvable.
if ( $mvs_member_resolved_user_id <= 0 ) {
	?>
	<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="mvs-empty-state mvs-member-photos-empty">
			<i data-lucide="user" aria-hidden="true"></i>
			<p>
				<?php esc_html_e( 'This widget shows the displayed member\'s media — visit a BuddyPress profile, set a User ID in the block sidebar, or log in to see it in action.', 'wpmediaverse' ); ?>
			</p>
		</div>
	</div>
	<?php
	return;
}

// Resolve display info for the header (only if we're actually showing it).
$mvs_member_user_obj = $mvs_member_header ? get_userdata( $mvs_member_resolved_user_id ) : null;

// Stats — pulled live from the repositories. Each is a single COUNT
// query so we don't bother caching at the block level.
$mvs_member_stats = array(
	'media'     => 0,
	'followers' => 0,
	'following' => 0,
);
if ( $mvs_member_user_obj ) {
	$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	if ( $repo ) {
		$mvs_member_stats['media'] = (int) $repo->count_by_author( $mvs_member_resolved_user_id );
	}
	$follows = \WPMediaVerse\Core\Plugin::container()->get( 'follows' );
	if ( $follows ) {
		$counts                        = $follows->get_counts( $mvs_member_resolved_user_id );
		$mvs_member_stats['followers'] = (int) ( $counts['followers'] ?? 0 );
		$mvs_member_stats['following'] = (int) ( $counts['following'] ?? 0 );
	}
}

// Profile URL — prefer BP profile when BP is active, else WP author archive.
$mvs_member_profile_url = '';
if ( $mvs_member_user_obj ) {
	if ( function_exists( 'bp_core_get_user_domain' ) ) {
		$mvs_member_profile_url = bp_core_get_user_domain( $mvs_member_resolved_user_id );
	}
	if ( empty( $mvs_member_profile_url ) ) {
		$mvs_member_profile_url = get_author_posts_url( $mvs_member_resolved_user_id );
	}
}

// Follow button: only when actions enabled, viewer logged in, viewer != target,
// and the FollowService is available.
$mvs_member_show_follow  = false;
$mvs_member_is_following = false;
if ( $mvs_member_actions && is_user_logged_in() && get_current_user_id() !== $mvs_member_resolved_user_id ) {
	$follows = \WPMediaVerse\Core\Plugin::container()->get( 'follows' );
	if ( $follows ) {
		$mvs_member_show_follow  = true;
		$mvs_member_is_following = $follows->is_following( get_current_user_id(), $mvs_member_resolved_user_id );
	}
}

// Initials — visible until the avatar IMG loads, and a graceful fallback
// when get_avatar returns the default gray ghost (Gravatar unreachable).
$mvs_member_initials = '';
if ( $mvs_member_user_obj ) {
	$parts                = preg_split( '/\s+/', trim( (string) $mvs_member_user_obj->display_name ) );
	$mvs_member_initials  = mb_strtoupper(
		mb_substr( $parts[0] ?? '', 0, 1 ) . mb_substr( $parts[1] ?? '', 0, 1 )
	);
}
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $mvs_member_header && $mvs_member_user_obj ) : ?>
		<header class="mvs-member-photos-card">
			<a class="mvs-member-photos-card__avatar-link"
				href="<?php echo esc_url( $mvs_member_profile_url ); ?>"
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s: member display name. */
					__( 'View %s\'s profile', 'wpmediaverse' ),
					$mvs_member_user_obj->display_name
				) ); ?>">
				<span class="mvs-member-photos-card__initials" aria-hidden="true"><?php echo esc_html( $mvs_member_initials ); ?></span>
				<?php
				echo get_avatar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar returns escaped IMG tag.
					$mvs_member_resolved_user_id,
					128,
					'',
					$mvs_member_user_obj->display_name,
					array( 'class' => 'mvs-member-photos-card__avatar' )
				);
				?>
			</a>

			<div class="mvs-member-photos-card__body">
				<h2 class="mvs-member-photos-card__name">
					<a href="<?php echo esc_url( $mvs_member_profile_url ); ?>"><?php echo esc_html( $mvs_member_user_obj->display_name ); ?></a>
				</h2>

				<?php if ( $mvs_member_user_obj->user_login && $mvs_member_user_obj->user_login !== $mvs_member_user_obj->display_name ) : ?>
					<p class="mvs-member-photos-card__handle">@<?php echo esc_html( $mvs_member_user_obj->user_login ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $mvs_member_user_obj->description ) ) : ?>
					<p class="mvs-member-photos-card__bio"><?php echo esc_html( wp_trim_words( $mvs_member_user_obj->description, 24, '…' ) ); ?></p>
				<?php endif; ?>

				<dl class="mvs-member-photos-card__stats" aria-label="<?php esc_attr_e( 'Member statistics', 'wpmediaverse' ); ?>">
					<div class="mvs-member-photos-card__stat">
						<dd><?php echo esc_html( number_format_i18n( $mvs_member_stats['media'] ) ); ?></dd>
						<dt><?php esc_html_e( 'Photos', 'wpmediaverse' ); ?></dt>
					</div>
					<div class="mvs-member-photos-card__stat">
						<dd><?php echo esc_html( number_format_i18n( $mvs_member_stats['followers'] ) ); ?></dd>
						<dt><?php esc_html_e( 'Followers', 'wpmediaverse' ); ?></dt>
					</div>
					<div class="mvs-member-photos-card__stat">
						<dd><?php echo esc_html( number_format_i18n( $mvs_member_stats['following'] ) ); ?></dd>
						<dt><?php esc_html_e( 'Following', 'wpmediaverse' ); ?></dt>
					</div>
				</dl>

				<?php if ( $mvs_member_actions ) : ?>
					<div class="mvs-member-photos-card__actions">
						<a class="mvs-member-photos-card__btn mvs-member-photos-card__btn--secondary" href="<?php echo esc_url( $mvs_member_profile_url ); ?>">
							<?php esc_html_e( 'View profile', 'wpmediaverse' ); ?>
						</a>
						<?php if ( $mvs_member_show_follow ) : ?>
							<button
								type="button"
								class="mvs-member-photos-card__btn mvs-member-photos-card__btn--primary mvs-follow-toggle <?php echo $mvs_member_is_following ? 'is-following' : ''; ?>"
								data-target-user-id="<?php echo esc_attr( $mvs_member_resolved_user_id ); ?>"
								aria-pressed="<?php echo $mvs_member_is_following ? 'true' : 'false'; ?>">
								<span class="mvs-follow-toggle__follow"><?php esc_html_e( 'Follow', 'wpmediaverse' ); ?></span>
								<span class="mvs-follow-toggle__following"><?php esc_html_e( 'Following', 'wpmediaverse' ); ?></span>
							</button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</header>
	<?php endif; ?>

	<?php
	// Delegate the actual grid rendering to mvs/media-grid via render_block.
	// Per Coding Rule C — one calling surface per capability — never copy-paste
	// the grid query / template.
	$grid_attrs = array(
		'userId'        => $mvs_member_resolved_user_id,
		'columns'       => $mvs_member_columns,
		'perPage'       => $mvs_member_per_page,
		'mediaType'     => $mvs_member_type,
		'showLightbox'  => true,
		'showReactions' => true,
		'gap'           => 8,
		'orderBy'       => 'date',
	);

	echo do_blocks( '<!-- wp:mvs/media-grid ' . wp_json_encode( $grid_attrs ) . ' /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks output is the rendered block markup.
	?>
</div>
