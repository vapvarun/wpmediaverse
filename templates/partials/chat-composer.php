<?php
/**
 * Chat composer — text input, attachment picker, voice recorder, send button.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mvs-chat-composer">
	<!-- Reply preview -->
	<div class="mvs-chat-composer__reply-preview" data-wp-bind--hidden="!state.hasReplyTo">
		<div class="mvs-chat-composer__reply-text">
			<strong data-wp-text="state.replyToName"></strong>
			<span data-wp-text="state.replyToContent"></span>
		</div>
		<button class="mvs-chat-composer__reply-close" data-wp-on--click="actions.clearReply" type="button">&times;</button>
	</div>

	<!-- Attachment preview / upload state (image thumb, or a chip for video/PDF/other files) -->
	<div class="mvs-chat-composer__attachment-preview" data-wp-bind--hidden="!state.hasAttachmentBar">
		<img data-wp-bind--src="state.attachmentPreview" data-wp-bind--hidden="!state.attachmentPreview" alt="" />
		<span class="mvs-chat-composer__attachment-chip" data-wp-bind--hidden="!state.attachmentChipLabel">
			<span class="mvs-chat-composer__attachment-spinner" data-wp-bind--hidden="!state.uploadingAttachment" aria-hidden="true"></span>
			<span class="mvs-chat-composer__attachment-name" data-wp-text="state.attachmentChipLabel"></span>
		</span>
		<button class="mvs-chat-composer__reply-close" data-wp-on--click="actions.cancelAttachment" type="button" aria-label="<?php esc_attr_e( 'Remove attachment', 'wpmediaverse' ); ?>">&times;</button>
	</div>

	<!-- Normal composer row -->
	<div class="mvs-chat-composer__row" data-wp-bind--hidden="state.isRecordingVoice">
		<div class="mvs-chat-composer__actions">
			<!-- Attachment button -->
			<button class="mvs-chat-composer__btn" data-wp-on--click="actions.openAttachmentPicker" type="button" aria-label="<?php esc_attr_e( 'Attach file', 'wpmediaverse' ); ?>">
				<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z" fill="currentColor"/></svg>
			</button>

			<!-- Voice record button -->
			<button class="mvs-chat-composer__btn" data-wp-on--click="actions.startVoiceRecording" type="button" aria-label="<?php esc_attr_e( 'Record voice message', 'wpmediaverse' ); ?>">
				<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z" fill="currentColor"/></svg>
			</button>
		</div>

		<div class="mvs-chat-composer__input-wrap">
			<textarea
				class="mvs-chat-composer__input"
				rows="1"
				placeholder="<?php esc_attr_e( 'Type a message...', 'wpmediaverse' ); ?>"
				data-wp-on--input="actions.onComposerInput"
				data-wp-on--keydown="actions.handleComposerKeydown"
				data-wp-bind--value="state.composerText"
			></textarea>
		</div>

		<button
			class="mvs-chat-composer__send"
			data-wp-on--click="actions.sendMessage"
			data-wp-bind--disabled="!state.canSend"
			type="button"
			aria-label="<?php esc_attr_e( 'Send message', 'wpmediaverse' ); ?>"
		>
			<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/></svg>
		</button>
	</div>

	<!-- Voice recording row -->
	<div class="mvs-chat-composer__row" data-wp-bind--hidden="!state.isRecordingVoice">
		<div class="mvs-chat-composer__voice-recording">
			<span class="mvs-recording-dot"></span>
			<span class="mvs-chat-composer__voice-timer" data-wp-text="state.voiceDurationFormatted"></span>
			<button class="mvs-chat-composer__voice-cancel" data-wp-on--click="actions.cancelVoiceRecording" type="button"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
		</div>
		<button
			class="mvs-chat-composer__send"
			data-wp-on--click="actions.stopVoiceRecording"
			type="button"
			aria-label="<?php esc_attr_e( 'Send voice message', 'wpmediaverse' ); ?>"
		>
			<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/></svg>
		</button>
	</div>
</div>
