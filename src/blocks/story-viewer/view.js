/**
 * Interactivity API store for the story-viewer block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'mvs/story-viewer', {
	actions: {
		openStory() {
			const itemCtx = getContext();
			const ctx = getContext();
			ctx.viewing = true;
			ctx.currentAuthor = itemCtx.authorId;
			ctx.storyUrl = itemCtx.storyUrl;
			document.body.style.overflow = 'hidden';
		},
		closeStory() {
			const ctx = getContext();
			ctx.viewing = false;
			ctx.storyUrl = '';
			document.body.style.overflow = '';
		},
	},
} );
