/**
 * Interactivity API store: shared UI components (toast, confirm, tag autocomplete).
 *
 * Other stores import via: store( 'mvs/shared-ui' ).actions.showToast( msg, type )
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

let toastTimer = null;
let tagSearchTimer = null;

const { state, actions } = store( 'mvs/shared-ui', {
	state: {
		toast: {
			message: '',
			type: 'success',
			visible: false,
		},
		confirm: {
			message: '',
			visible: false,
			onConfirm: null,
		},
		get isToastSuccess() { return state.toast.type === 'success'; },
		get isToastError() { return state.toast.type === 'error'; },
		tagAutocomplete: {
			query: '',
			results: [],
			visible: false,
		},
	},
	actions: {
		showToast( msg, type = 'success' ) {
			state.toast.message = msg;
			state.toast.type = type;
			state.toast.visible = true;
			clearTimeout( toastTimer );
			toastTimer = setTimeout( () => {
				state.toast.visible = false;
			}, 3000 );
		},
		hideToast() {
			state.toast.visible = false;
		},
		showConfirm( msg, callback ) {
			state.confirm.message = msg;
			state.confirm.onConfirm = callback;
			state.confirm.visible = true;
		},
		handleConfirmYes() {
			const cb = state.confirm.onConfirm;
			state.confirm.visible = false;
			state.confirm.onConfirm = null;
			if ( typeof cb === 'function' ) {
				cb();
			}
		},
		handleConfirmCancel() {
			state.confirm.visible = false;
			state.confirm.onConfirm = null;
		},
		searchTags( query, restUrl ) {
			state.tagAutocomplete.query = query;
			if ( query.length < 2 ) {
				state.tagAutocomplete.results = [];
				state.tagAutocomplete.visible = false;
				return;
			}
			clearTimeout( tagSearchTimer );
			tagSearchTimer = setTimeout( async () => {
				try {
					const res = await fetch(
						restUrl + 'tags?search=' + encodeURIComponent( query ) + '&per_page=8',
						{ credentials: 'same-origin' }
					);
					const data = await res.json();
					state.tagAutocomplete.results = data.map( ( t ) => t.name || t );
					state.tagAutocomplete.visible = state.tagAutocomplete.results.length > 0;
				} catch {
					state.tagAutocomplete.results = [];
					state.tagAutocomplete.visible = false;
				}
			}, 300 );
		},
		hideTagAutocomplete() {
			state.tagAutocomplete.visible = false;
			state.tagAutocomplete.results = [];
		},
	},
} );
