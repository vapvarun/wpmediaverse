<?php
/**
 * Media share card — embedded mvs_media in message.
 *
 * Used inside chat-message.php for media_share type messages.
 * Context: context.item.media_share = media data object.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>
<a
	class="mvs-chat-msg__media-card"
	data-wp-bind--href="context.item.media_share.permalink"
	target="_blank"
	rel="noopener"
>
	<img
		class="mvs-chat-msg__media-card-thumb"
		data-wp-bind--src="context.item.media_share.thumbnail"
		data-wp-bind--hidden="!context.item.media_share.thumbnail"
		alt="" data-wp-bind--alt="context.item.media_share.title"
		loading="lazy"
	/>
	<div class="mvs-chat-msg__media-card-body">
		<div class="mvs-chat-msg__media-card-title" data-wp-text="context.item.media_share.title"></div>
		<div class="mvs-chat-msg__media-card-type" data-wp-text="context.item.media_share.type"></div>
	</div>
</a>
