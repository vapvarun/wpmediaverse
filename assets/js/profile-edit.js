/**
 * Interactivity API store: mvs/profile-edit
 *
 * Profile editing — first name, last name, display name, bio, avatar upload.
 * Works both as standalone page and inline within the dashboard.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

const { actions } = store( 'mvs/profile-edit', {
	actions: {
		toggleProfileEdit() {
			const ctx = getContext();
			ctx.editingProfile = ! ctx.editingProfile;
			// Clear messages when toggling.
			ctx.profileMessage = '';
			ctx.profileError = '';
			ctx.savedMessage = '';
			ctx.errorMessage = '';
		},

		cancelProfileEdit() {
			// Discard unsaved edits and stay on the profile section. A reload
			// re-seeds the form from the server. Before 2.4.0 Cancel called the
			// dashboard's toggleProfileEdit, which flipped to the media section
			// (Basecamp 10240303858) — the same navigation bug Save had.
			window.location.reload();
		},

		// Unblock a member from the "Blocked members" list. The list is
		// server-rendered (no data-wp-each), so the clicked button carries the
		// id via data-id and we remove its row from the DOM directly — keeps SSR
		// and hydration identical under any theme.
		async unblockMember() {
			const ctx = getContext();
			const { ref } = getElement();
			const id = ref && ref.dataset ? ref.dataset.id : '';
			if ( ! id ) {
				return;
			}
			try {
				await window.mvsRest.restFetch( ctx.restUrl + 'users/' + id + '/block', { method: 'DELETE' } );
				const row = ref.closest( '.mvs-blocked-members__row' );
				const list = row ? row.parentElement : null;
				if ( row ) {
					row.remove();
				}
				// Show the empty state when the last block is removed.
				if ( list && ! list.querySelector( '.mvs-blocked-members__row' ) ) {
					list.hidden = true;
					const empty = list.parentElement
						? list.parentElement.querySelector( '.mvs-blocked-members__empty' )
						: null;
					if ( empty ) {
						empty.hidden = false;
					}
				}
			} catch ( e ) {
				// leave the row in place; the member can retry
			}
		},

		updateFirstName() {
			const ctx = getContext();
			const { ref } = getElement();
			ctx.firstName = ref.value;
		},

		updateLastName() {
			const ctx = getContext();
			const { ref } = getElement();
			ctx.lastName = ref.value;
		},

		updateDisplayName() {
			const ctx = getContext();
			const { ref } = getElement();
			ctx.displayName = ref.value;
		},

		updateBio() {
			const ctx = getContext();
			const { ref } = getElement();
			ctx.bio = ref.value;
		},

		updateDmAccess() {
			const ctx = getContext();
			const { ref } = getElement();
			ctx.dmAccess = ref.value;
		},

		updateOnlineStatus() {
			const ctx = getContext();
			const { ref } = getElement();
			ctx.onlineStatus = ref.value;
		},

		*saveProfile( event ) {
			if ( event && event.preventDefault ) {
				event.preventDefault();
			}
			const ctx = getContext();
			ctx.savingProfile = true;
			ctx.saving = true;
			ctx.profileMessage = '';
			ctx.profileError = '';
			ctx.savedMessage = '';
			ctx.errorMessage = '';

			try {
				const res = yield window.mvsRest.restFetch( ctx.restUrl + 'me/profile', {
					method: 'PUT',
					body: {
						first_name: ctx.firstName,
						last_name: ctx.lastName,
						display_name: ctx.displayName,
						description: ctx.bio,
						dm_access: ctx.dmAccess,
						online_status: ctx.onlineStatus,
					},
				} );

				const data = res.data;

				if ( ! res.ok ) {
					const msg = data.message || 'Failed to save profile.';
					ctx.profileError = msg;
					ctx.errorMessage = msg;
					return;
				}

				ctx.firstName = data.first_name;
				ctx.lastName = data.last_name;
				ctx.displayName = data.display_name;
				ctx.bio = data.bio;
				ctx.avatarUrl = data.avatar;

				const successMsg = 'Profile updated successfully.';
				ctx.profileMessage = successMsg;
				ctx.savedMessage = successMsg;

				// Stay on the profile section. Since 2.4.0 the profile is its own
				// /my-media/profile/ section (shown while state.isProfileTab), not
				// an inline form to close — the fields above already show the saved
				// values and the success message is bound here. Calling the
				// dashboard's toggleProfileEdit flipped activeTab to 'media', which
				// hid this whole panel and the message with it (Basecamp
				// 10240303858). The rail is how the member leaves the section.
			} catch ( err ) {
				const errMsg = 'Network error. Please try again.';
				ctx.profileError = errMsg;
				ctx.errorMessage = errMsg;
			} finally {
				ctx.savingProfile = false;
				ctx.saving = false;
			}
		},

		*uploadAvatar() {
			const ctx = getContext();
			const { ref } = getElement();
			const file = ref.files && ref.files[0];

			if ( ! file ) {
				return;
			}

			ctx.uploadingAvatar = true;
			ctx.profileMessage = '';
			ctx.profileError = '';
			ctx.savedMessage = '';
			ctx.errorMessage = '';

			try {
				const formData = new FormData();
				formData.append( 'avatar', file );

				const res = yield window.mvsRest.restFetch( ctx.restUrl + 'me/avatar', {
					method: 'POST',
					body: formData,
				} );

				const data = res.data;

				if ( ! res.ok ) {
					const msg = data.message || 'Failed to upload avatar.';
					ctx.profileError = msg;
					ctx.errorMessage = msg;
					return;
				}

				ctx.avatarUrl = data.avatar_url;
				ctx.hasCustomAvatar = true;

				const successMsg = 'Avatar updated.';
				ctx.profileMessage = successMsg;
				ctx.savedMessage = successMsg;
			} catch ( err ) {
				const errMsg = 'Upload failed. Please try again.';
				ctx.profileError = errMsg;
				ctx.errorMessage = errMsg;
			} finally {
				ctx.uploadingAvatar = false;
				ref.value = '';
			}
		},

		*deleteAvatar() {
			const ctx = getContext();
			ctx.profileMessage = '';
			ctx.profileError = '';
			ctx.savedMessage = '';
			ctx.errorMessage = '';

			try {
				const res = yield window.mvsRest.restFetch( ctx.restUrl + 'me/avatar', {
					method: 'DELETE',
				} );

				const data = res.data;

				if ( ! res.ok ) {
					const msg = data.message || 'Failed to remove avatar.';
					ctx.profileError = msg;
					ctx.errorMessage = msg;
					return;
				}

				ctx.avatarUrl = data.avatar_url;
				ctx.hasCustomAvatar = false;

				const successMsg = 'Avatar removed. Using Gravatar.';
				ctx.profileMessage = successMsg;
				ctx.savedMessage = successMsg;
			} catch ( err ) {
				const errMsg = 'Network error. Please try again.';
				ctx.profileError = errMsg;
				ctx.errorMessage = errMsg;
			}
		},
	},
} );
