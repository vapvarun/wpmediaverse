/**
 * Interactivity API store for the story-viewer block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'mvs/story-viewer', {
	state: {
		// A story can be any media type (is_story meta, no type filter), so the
		// fullscreen viewer renders <img>, <video>, or <audio> by type. Each src
		// getter returns the URL only for its own type so the hidden elements
		// never fire a wasted/broken request for the wrong media.
		get storyIsVideo() {
			return getContext().storyType === 'video';
		},
		get storyIsAudio() {
			return getContext().storyType === 'audio';
		},
		get storyIsImage() {
			const t = getContext().storyType;
			return t !== 'video' && t !== 'audio';
		},
		get storyImageSrc() {
			const ctx = getContext();
			return ( ctx.storyType !== 'video' && ctx.storyType !== 'audio' ) ? ctx.storyUrl : '';
		},
		get storyVideoSrc() {
			const ctx = getContext();
			return ctx.storyType === 'video' ? ctx.storyUrl : '';
		},
		get storyAudioSrc() {
			const ctx = getContext();
			return ctx.storyType === 'audio' ? ctx.storyUrl : '';
		},
	},
	actions: {
		openStory() {
			const itemCtx = getContext();
			const ctx = getContext();
			ctx.viewing = true;
			ctx.currentAuthor = itemCtx.authorId;
			ctx.authorName = itemCtx.authorName || '';
			ctx.storyUrl = itemCtx.storyUrl;
			ctx.storyType = itemCtx.storyType || 'image';
			document.body.style.overflow = 'hidden';
		},
		closeStory() {
			const ctx = getContext();
			ctx.viewing = false;
			ctx.storyUrl = '';
			ctx.storyType = '';
			document.body.style.overflow = '';
		},
	},
} );
