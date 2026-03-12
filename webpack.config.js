const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// View-only Interactivity API stores that only have view.js (no index.js).
// wp-scripts only discovers index.js entries, so we add these manually.
const viewOnlyStores = [
	'dashboard-view',
	'shared-ui',
	'media-social',
	'explore-view',
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
const existingEntry =
	typeof mainConfig.entry === 'function'
		? mainConfig.entry()
		: mainConfig.entry || {};

configs[ 0 ] = {
	...mainConfig,
	entry: {
		...existingEntry,
		...viewEntries,
	},
};

module.exports = configs;
