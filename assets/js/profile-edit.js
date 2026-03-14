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
				const res = yield fetch( ctx.restUrl + 'me/profile', {
					method: 'PUT',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ctx.nonce,
					},
					body: JSON.stringify( {
						first_name: ctx.firstName,
						last_name: ctx.lastName,
						display_name: ctx.displayName,
						description: ctx.bio,
					} ),
				} );

				const data = yield res.json();

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

				const res = yield fetch( ctx.restUrl + 'me/avatar', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': ctx.nonce,
					},
					body: formData,
				} );

				const data = yield res.json();

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
				const res = yield fetch( ctx.restUrl + 'me/avatar', {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': ctx.nonce,
					},
				} );

				const data = yield res.json();

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
