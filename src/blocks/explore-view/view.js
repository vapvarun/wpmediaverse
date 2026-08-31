/**
 * Interactivity API store: explore page tag cloud.
 *
 * Replaces assets/js/mvs-explore.js (52 lines).
 * Server-rendered grid + WP pagination remain untouched (SEO).
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

/**
 * Explore bulk actions work against a SERVER-RENDERED grid, which is the one
 * real difference from the dashboard's client-rendered one. There is no
 * `state.media.items` to map over here, so selection is keyed off the id each
 * tile carries in `data-mvs-bulk-id`, and the pressed look is set on the button
 * itself rather than bound through a per-item context that does not exist.
 *
 * The tiles only HAVE that button when the viewer owns the media
 * (TemplateHelpers::render_grid_item), so `selectAll` cannot reach anyone
 * else's photo — the gate is in the markup, not in this file.
 */

const { state, actions } = store( 'mvs/explore', {
	state: {
		tags: [],
		loaded: false,
		bulkIds: [],
		bulkPrivacy: 'public',
		bulkTags: '',
		bulkAlbum: 0,
		bulkAlbums: [],
		bulkBusy: false,
		get hasBulk() {
			return state.bulkIds.length > 0;
		},
		get bulkLabel() {
			return ( state.i18n?.selected || '%d selected' ).replace( '%d', String( state.bulkIds.length ) );
		},
	},
	actions: {
		toggleExploreBulk( event ) {
			// The tile is a link to the media; selecting must not open it.
			event.preventDefault();
			event.stopPropagation();

			const btn = event.currentTarget;
			const id = parseInt( btn.getAttribute( 'data-mvs-bulk-id' ), 10 ) || 0;

			if ( ! id ) {
				return;
			}

			const on = ! state.bulkIds.includes( id );

			state.bulkIds = on
				? [ ...state.bulkIds, id ]
				: state.bulkIds.filter( ( x ) => x !== id );

			btn.classList.toggle( 'mvs-bulk-check--on', on );
			btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			btn.closest( '.mvs-grid-item' )?.classList.toggle( 'mvs-grid-item--selected', on );
		},
		selectAllExploreBulk() {
			const ids = [];

			document.querySelectorAll( '.mvs-grid-item .mvs-bulk-check' ).forEach( ( btn ) => {
				const id = parseInt( btn.getAttribute( 'data-mvs-bulk-id' ), 10 ) || 0;
				if ( ! id ) return;
				ids.push( id );
				btn.classList.add( 'mvs-bulk-check--on' );
				btn.setAttribute( 'aria-pressed', 'true' );
				btn.closest( '.mvs-grid-item' )?.classList.add( 'mvs-grid-item--selected' );
			} );

			state.bulkIds = ids;
		},
		clearExploreBulk() {
			document.querySelectorAll( '.mvs-grid-item .mvs-bulk-check' ).forEach( ( btn ) => {
				btn.classList.remove( 'mvs-bulk-check--on' );
				btn.setAttribute( 'aria-pressed', 'false' );
				btn.closest( '.mvs-grid-item' )?.classList.remove( 'mvs-grid-item--selected' );
			} );

			state.bulkIds = [];
		},
		setExploreBulkPrivacy( event ) {
			state.bulkPrivacy = event.target.value;
		},
		setExploreBulkTags( event ) {
			state.bulkTags = event.target.value;
		},
		setExploreBulkAlbum( event ) {
			state.bulkAlbum = parseInt( event.target.value, 10 ) || 0;
		},
		/**
		 * Same shortcuts as the dashboard, and the same first rule: never while
		 * the member is typing (10249014961).
		 */
		exploreBulkKeydown( event ) {
			const el = event.target;
			const tag = el && el.tagName ? el.tagName.toLowerCase() : '';

			if ( 'input' === tag || 'textarea' === tag || 'select' === tag || ( el && el.isContentEditable ) ) {
				return;
			}

			if ( ( event.ctrlKey || event.metaKey ) && 'a' === event.key.toLowerCase() ) {
				if ( ! document.querySelector( '.mvs-grid-item .mvs-bulk-check' ) ) return;
				event.preventDefault();
				actions.selectAllExploreBulk();
				return;
			}

			if ( ! state.bulkIds.length ) {
				return;
			}

			if ( 'Escape' === event.key ) {
				actions.clearExploreBulk();
				return;
			}

			if ( 'Delete' === event.key || 'Backspace' === event.key ) {
				event.preventDefault();
				actions.exploreBulkDelete();
			}
		},
		async ensureExploreAlbums() {
			if ( state.bulkAlbums.length ) {
				return;
			}
			const ctx = getContext();
			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'albums?per_page=100' );
				const data = res.data;
				const items = Array.isArray( data ) ? data : ( data?.items || [] );
				state.bulkAlbums = items.map( ( a ) => ( { id: a.id, title: a.title || '' } ) );
			} catch {
				state.bulkAlbums = [];
			}
		},
		/**
		 * One request, one message, and NO page reload.
		 *
		 * Deleted tiles are removed from the DOM directly; a privacy or tag
		 * change alters nothing visible on a tile, so there is nothing to
		 * re-render for. Reloading would also throw away the toast that
		 * reports the result — including the partial-success one, which is the
		 * message the member most needs to read.
		 */
		async exploreBulk( action, body, doneKey, doneFallback ) {
			const ctx = getContext();
			const ids = state.bulkIds.slice();

			if ( ! ids.length || state.bulkBusy ) {
				return;
			}

			state.bulkBusy = true;

			const shared = store( 'mvs/shared-ui' );
			const toast = ( text, type ) => shared?.actions?.showToast?.( text, type );

			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/bulk', {
					method: 'POST',
					body: { action, media_ids: ids, ...body },
				} );

				if ( res.ok ) {
					// The server reports what it actually touched, because
					// filter_allowed_ids drops anything the member may not
					// edit and used to do so silently.
					const skipped = parseInt( res.data?.skipped, 10 ) || 0;

					if ( skipped ) {
						toast(
							( state.i18n?.partial || '%1$d of %2$d updated. %3$d were not yours to change.' )
								.replace( '%1$d', String( parseInt( res.data?.processed, 10 ) || 0 ) )
								.replace( '%2$d', String( parseInt( res.data?.requested, 10 ) || 0 ) )
								.replace( '%3$d', String( skipped ) ),
							'warning'
						);
					} else {
						toast( state.i18n?.[ doneKey ] || doneFallback, 'success' );
					}

					if ( 'delete' === action ) {
						ids.forEach( ( id ) => {
							document
								.querySelector( '.mvs-bulk-check[data-mvs-bulk-id="' + id + '"]' )
								?.closest( '.mvs-grid-item' )
								?.remove();
						} );
					}

					actions.clearExploreBulk();
				} else {
					toast( res.data?.message || ( state.i18n?.failed || 'Bulk action failed.' ), 'error' );
				}
			} catch {
				toast( state.i18n?.failed || 'Bulk action failed.', 'error' );
			}

			state.bulkBusy = false;
		},
		exploreBulkDelete() {
			// Through the shared confirm overlay, never a native confirm()
			// (Pro/Free coding rule), and the same copy the dashboard uses.
			const shared = store( 'mvs/shared-ui' );

			shared?.actions?.showConfirm?.(
				state.i18n?.deleteConfirm || 'Delete the selected items? This cannot be undone.',
				() => {
					actions.exploreBulk( 'delete', {}, 'deleted', 'Selected items deleted.' );
				}
			);
		},
		async exploreBulkPrivacy() {
			await actions.exploreBulk( 'change_privacy', { privacy: state.bulkPrivacy }, 'privacyDone', 'Privacy updated.' );
		},
		async exploreBulkTags() {
			const tags = state.bulkTags.split( ',' ).map( ( t ) => t.trim() ).filter( Boolean );
			if ( ! tags.length ) return;
			await actions.exploreBulk( 'add_tags', { tags }, 'tagsAdded', 'Tags added.' );
		},
		async exploreBulkAlbum() {
			if ( ! state.bulkAlbum ) return;
			await actions.exploreBulk( 'move_to_album', { album_id: state.bulkAlbum }, 'movedToAlbum', 'Moved to album.' );
		},
	},
	callbacks: {
		async init() {
			const ctx = getContext();
			if ( ! ctx.restUrl ) {
				return;
			}
			try {
				// Server-provided, filterable via mvs_explore_tag_cloud_limit.
				// Falls back to 20 for a context rendered before 2.3.0.
				const tagLimit = parseInt( ctx.tagLimit, 10 ) || 20;
				const res = await window.mvsRest.restFetch(
					ctx.restUrl + 'tags/cloud?limit=' + tagLimit
				);
				const data = res.data;
				if ( Array.isArray( data ) ) {
					ctx.tags = data.map( ( tag ) => ( {
						name: tag.name || '',
						slug: tag.slug || '',
						href: ctx.archiveUrl +
							( ctx.archiveUrl.indexOf( '?' ) !== -1 ? '&' : '?' ) +
							'mvs_tag=' + encodeURIComponent( tag.slug || '' ),
						active: ctx.activeTag === ( tag.slug || '' ),
					} ) );
				}
			} catch {
				// Silently fail.
			}
			ctx.loaded = true;
		},
	},
} );
