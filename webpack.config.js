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

// Shared script modules MediaVerse registers itself (Core\Plugin::register_shared_modules()):
// imported by id, resolved by WordPress's import map, never bundled into a view.
const SHARED_MODULES = [ '@wpmediaverse/sync-events' ];
configs.forEach( ( config ) => {
	if ( ! config.output || ! config.output.module ) {
		return;
	}
	( config.plugins || [] ).forEach( ( plugin ) => {
		if ( 'DependencyExtractionWebpackPlugin' !== plugin.constructor.name ) {
			return;
		}
		const previous = plugin.options.requestToExternalModule;
		plugin.options.requestToExternalModule = ( request ) =>
			SHARED_MODULES.includes( request ) ? true : ( previous ? previous( request ) : undefined );
	} );
} );

module.exports = configs;
