const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// View-only Interactivity API stores that only have view.js (no index.js).
// wp-scripts only discovers index.js entries, so we add these manually.
// All blocks with view.js (Interactivity API stores).
// wp-scripts doesn't always auto-discover view.js entries,
// so we add them all explicitly.
const viewOnlyStores = [
	'dashboard-view',
	'media-social',
	'explore-view',
	'shared-ui',
	'explore-feed',
	'lock-overlay',
	'media-grid',
	'media-player',
	'media-upload',
];

const viewEntries = {};
viewOnlyStores.forEach( ( block ) => {
	viewEntries[ `blocks/${ block }/view` ] = path.resolve(
		__dirname,
		`src/blocks/${ block }/view.js`
	);
} );

// defaultConfig may be an array (multi-compiler) when --webpack-src-dir is used.
const configs = Array.isArray( defaultConfig )
	? defaultConfig
	: [ defaultConfig ];

// Add view-only entries to the first config.
const mainConfig = configs[ 0 ];
const originalEntry = mainConfig.entry;

configs[ 0 ] = {
	...mainConfig,
	entry: async () => {
		const resolved = typeof originalEntry === 'function'
			? await originalEntry()
			: originalEntry || {};
		return {
			...resolved,
			...viewEntries,
		};
	},
};

module.exports = configs;
