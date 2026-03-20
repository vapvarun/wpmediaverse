<?php
/**
 * Chat message bubble — text, media, voice, system, reply-to, reactions.
 *
 * Used inside data-wp-each="state.messages" template.
 * Context: context.item = message object with pre-computed boolean flags.
 *
 * IMPORTANT: Interactivity API does not track underscore-prefixed properties
 * on context.item inside data-wp-each. All flags use plain camelCase names
 * (e.g. notDeleted, notText) set by enrichMessage() in messaging.js.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	class="mvs-chat-msg"
	data-wp-class--mvs-chat-msg--sent="context.item.isSent"
	data-wp-class--mvs-chat-msg--received="context.item.isReceived"
	data-wp-class--mvs-chat-msg--system="context.item.isSystem"
	data-wp-class--mvs-chat-msg--deleted="context.item.isDeleted"
	data-wp-on--contextmenu="actions.showContextMenu"
>
	<!-- Context Menu (reactions + actions) -->
	<div class="mvs-chat-msg__context-menu" data-wp-bind--hidden="context.item.noMenu">
		<?php
		$quick_reactions = array(
			"\xE2\x9D\xA4\xEF\xB8\x8F",
			"\xF0\x9F\x98\x82",
			"\xF0\x9F\x98\xAE",
			"\xF0\x9F\x98\xA2",
			"\xF0\x9F\x98\xA1",
			"\xF0\x9F\x91\x8D",
		);
		foreach ( $quick_reactions as $emoji ) :
			?>
			<button
				class="mvs-chat-msg__context-btn"
				data-wp-on--click="actions.addReaction"
				<?php echo wp_interactivity_data_wp_context( array( 'emoji' => $emoji ) ); ?>
				type="button"
			><?php echo esc_html( $emoji ); ?></button>
		<?php endforeach; ?>
		<button class="mvs-chat-msg__context-btn" data-wp-on--click="actions.setReplyTo" type="button" title="Reply">
			<svg viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" fill="currentColor"/></svg>
		</button>
		<button class="mvs-chat-msg__context-btn mvs-chat-msg__context-btn--delete" data-wp-on--click="actions.deleteMessage" data-wp-bind--hidden="context.item.isReceived" type="button" title="Delete for me">
			<svg viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/></svg>
		</button>
		<button class="mvs-chat-msg__context-btn mvs-chat-msg__context-btn--unsend" data-wp-on--click="actions.unsendMessage" data-wp-bind--hidden="state.hideUnsend" type="button" title="Unsend for everyone">
			<svg viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11H7v-2h10v2z" fill="currentColor"/></svg>
		</button>
	</div>

	<div class="mvs-chat-msg__bubble" data-wp-on--click="actions.showContextMenu">
		<!-- Reply-to preview -->
		<div class="mvs-chat-msg__reply" data-wp-bind--hidden="context.item.noReply">
			<div class="mvs-chat-msg__reply-sender" data-wp-text="context.item.parent_preview.sender"></div>
			<div data-wp-text="context.item.parent_preview.content"></div>
		</div>

		<!-- Deleted message -->
		<span data-wp-bind--hidden="context.item.notDeleted">This message was deleted</span>

		<!-- Text content -->
		<span data-wp-bind--hidden="context.item.notText" data-wp-text="context.item.content"></span>

		<!-- Image attachment -->
		<img
			class="mvs-chat-msg__image"
			data-wp-bind--hidden="context.item.notImage"
			data-wp-bind--src="context.item.attachment.thumbnail"
			alt=""
			loading="lazy"
		/>

		<!-- Video attachment -->
		<video
			class="mvs-chat-msg__video"
			data-wp-bind--hidden="context.item.notVideo"
			data-wp-bind--src="context.item.attachment.url"
			controls
			preload="none"
		></video>

		<!-- Voice message -->
		<div class="mvs-chat-msg__voice" data-wp-bind--hidden="context.item.notVoice">
			<button class="mvs-chat-msg__voice-play" data-wp-on--click="actions.toggleVoicePlayback" type="button">
				<svg viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>
			</button>
			<div class="mvs-chat-msg__voice-waveform">
				<canvas width="140" height="32"></canvas>
			</div>
			<span class="mvs-chat-msg__voice-duration" data-wp-text="state.messageDuration"></span>
			<button class="mvs-chat-msg__voice-speed" data-wp-on--click="actions.setVoiceSpeed" data-wp-text="state.voiceSpeedLabel" type="button">1x</button>
		</div>

		<!-- File attachment -->
		<a
			class="mvs-chat-msg__file"
			data-wp-bind--hidden="context.item.notFile"
			data-wp-bind--href="context.item.attachment.url"
			target="_blank"
			rel="noopener"
		>
			<span class="mvs-chat-msg__file-icon">&#128196;</span>
			<span>
				<span class="mvs-chat-msg__file-name" data-wp-text="context.item.attachment.name"></span>
			</span>
		</a>

		<!-- Media share card -->
		<div data-wp-bind--hidden="context.item.notMediaShare">
			<?php include __DIR__ . '/chat-media-card.php'; ?>
		</div>
	</div>

	<!-- Timestamp + read receipt -->
	<div class="mvs-chat-msg__meta" data-wp-bind--hidden="context.item.isSystem">
		<span data-wp-text="context.item.created_at"></span>
		<span class="mvs-chat-msg__check" data-wp-bind--hidden="context.item.isReceived">&#10003;&#10003;</span>
	</div>

	<!-- Reactions -->
	<div class="mvs-chat-msg__reactions" data-wp-bind--hidden="context.item.noReactions">
		<template data-wp-each="context.item.reactions">
			<button class="mvs-chat-msg__reaction" data-wp-on--click="actions.addReaction" type="button">
				<span data-wp-text="context.item.emoji"></span>
				<span class="mvs-chat-msg__reaction-count" data-wp-text="context.item.count"></span>
			</button>
		</template>
	</div>
</div>
