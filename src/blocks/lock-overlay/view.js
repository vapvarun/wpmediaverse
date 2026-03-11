/**
 * Interactivity API store for the lock-overlay block.
 *
 * Shows access restriction info for gated media.
 * Access is controlled via role, capability, membership, or code rules.
 *
 * @package WPMediaVerse
 */

import { store } from '@wordpress/interactivity';

store( 'mvs/lock-overlay', {
	actions: {
		/**
		 * Placeholder action for future access-request flows.
		 */
		requestAccess() {
			// No-op: access is granted via role, capability, membership, or code.
			// This action exists as an extension point for Pro features.
		},
	},
} );
