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

?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $mvs_member_header && $mvs_member_user_obj ) : ?>
		<header class="mvs-member-photos-header">
			<a class="mvs-member-photos-header__link" href="<?php echo esc_url( get_author_posts_url( $mvs_member_resolved_user_id ) ); ?>">
				<?php echo get_avatar( $mvs_member_resolved_user_id, 48, '', '', array( 'class' => 'mvs-member-photos-header__avatar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar returns escaped IMG tag. ?>
				<span class="mvs-member-photos-header__name">
					<?php echo esc_html( $mvs_member_user_obj->display_name ); ?>
				</span>
			</a>
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
