/**
 * WPMediaVerse — unified REST fetch client.
 *
 * Exposes window.mvsRest.restFetch(path, opts) as the single fetch surface
 * for every front-end REST call. Centralising fetch removes the ~100
 * hand-rolled fetch() sites scattered across the plugin's JS.
 *
 * Behaviour:
 *   - Resolves base URL + nonce from window.mvsRestConfig (localized in PHP).
 *     Falls back to '/wp-json/mvs/v1' when config is absent.
 *   - Always sends `credentials: 'same-origin'` so the auth cookie travels.
 *   - Always sends `X-WP-Nonce` header for cookie-auth mutation requests.
 *   - Accepts path as either (a) an already-absolute URL (http/https or
 *     /wp-json prefix) used as-is, or (b) a path relative to restBase.
 *     This lets Task 5 swap `fetch(` → `mvsRest.restFetch(` without touching
 *     the existing URL argument.
 *   - FormData bodies are sent as-is — browser sets the multipart boundary.
 *     Plain-object bodies are JSON-encoded with Content-Type: application/json.
 *     String bodies pass through untouched.
 *   - Parses JSON response when content-type matches; otherwise data is null.
 *   - On `403 rest_cookie_invalid_nonce`, fetches /auth/nonce once to refresh
 *     and retries. If the refresh endpoint returns 404 (not yet reachable),
 *     returns the original 403 verbatim.
 *   - Never throws on HTTP errors — always resolves to
 *     `{ ok, status, data }` or `{ ok: false, status: 0, error }` on failure.
 *
 * @package WPMediaVerse
 * @since   1.7.1
 */
( function () {
	'use strict';

	/**
	 * Return the configured REST base URL, without trailing slash.
	 *
	 * @return {string}
	 */
	function resolveBase() {
		if ( window.mvsRestConfig && window.mvsRestConfig.restBase ) {
			return String( window.mvsRestConfig.restBase ).replace( /\/+$/, '' );
		}
		return '/wp-json/mvs/v1';
	}

	/**
	 * Return the nonce from the localized config object.
	 *
	 * @return {string}
	 */
	function resolveNonce() {
		if ( window.mvsRestConfig && window.mvsRestConfig.nonce ) {
			return window.mvsRestConfig.nonce;
		}
		return '';
	}

	// In-memory nonce; set when /auth/nonce refresh succeeds. Falls back to
	// the freshly-localized value on every call so a hard page reload always
	// wins over a stale in-memory copy.
	var currentNonce = null;

	/**
	 * Return the best available nonce for the current request.
	 *
	 * @return {string}
	 */
	function activeNonce() {
		return currentNonce || resolveNonce();
	}

	/**
	 * Resolve `path` to an absolute URL.
	 *
	 * Accepts either:
	 *   (a) an already-absolute URL (http/https or /wp-json prefix) → used as-is.
	 *   (b) a path relative to restBase ('me/profile' or '/me/profile').
	 *
	 * @param {string} path
	 * @return {string}
	 */
	function buildUrl( path ) {
		var base = resolveBase();
		if ( ! path ) {
			return base;
		}
		// Already-absolute: pass through so callers can pass full REST URLs unchanged.
		if ( /^https?:\/\//.test( path ) || path.indexOf( '/wp-json' ) === 0 ) {
			return path;
		}
		// Relative path — ensure single slash separator.
		if ( path.charAt( 0 ) !== '/' ) {
			path = '/' + path;
		}
		return base + path;
	}

	/**
	 * Collapse a malformed second query separator.
	 *
	 * A URL may carry only one `?`. When the REST base is query-style
	 * (`/index.php?rest_route=/mvs/v1/`, the shape rest_url() returns without
	 * pretty permalinks) and a caller concatenates a path that has its own query
	 * (`me/media?per_page=20&page=1`), the result has TWO `?` and WordPress
	 * cannot match the route → 404 (Basecamp 9793599140). This turns every `?`
	 * after the first into `&`, which is correct for both URL shapes and a no-op
	 * on a well-formed single-`?` URL. Fixes the class at one layer rather than
	 * at each of the ~dozen concatenation sites across the blocks.
	 *
	 * @param {string} url
	 * @return {string}
	 */
	function normalizeQuery( url ) {
		var i = url.indexOf( '?' );
		if ( i === -1 ) {
			return url;
		}
		return url.slice( 0, i + 1 ) + url.slice( i + 1 ).replace( /\?/g, '&' );
	}

	/**
	 * Return true when val is a plain object (not Array/FormData/null/etc.).
	 *
	 * @param {*} val
	 * @return {boolean}
	 */
	function isPlainObject( val ) {
		return val !== null
			&& typeof val === 'object'
			&& Object.prototype.toString.call( val ) === '[object Object]';
	}

	/**
	 * Parse a JSON response body when the content-type indicates JSON;
	 * otherwise resolve to null.
	 *
	 * @param {Response} response
	 * @return {Promise<*>}
	 */
	function parseBody( response ) {
		var ct = response.headers && response.headers.get
			? ( response.headers.get( 'content-type' ) || '' )
			: '';
		if ( ct.indexOf( 'application/json' ) !== -1 ) {
			return response.json().catch( function () { return null; } );
		}
		return Promise.resolve( null );
	}

	/**
	 * Thin fetch wrapper that drops `signal: undefined` so jsdom and some
	 * older browsers don't choke on it.
	 *
	 * @param {string}  url
	 * @param {Object}  init
	 * @return {Promise<Response>}
	 */
	function doFetch( url, init ) {
		if ( typeof init.signal === 'undefined' ) {
			delete init.signal;
		}
		return fetch( url, init );
	}

	/**
	 * GET /auth/nonce to obtain a fresh nonce after a 403 stale-nonce response.
	 *
	 * Best-effort: a 404 here means the endpoint hasn't shipped yet (non-fatal).
	 *
	 * @return {Promise<{refreshed: boolean, missing: boolean}>}
	 */
	function refreshNonce() {
		var url = resolveBase() + '/auth/nonce';
		return doFetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' }
		} ).then( function ( response ) {
			if ( response.status === 404 ) {
				return { refreshed: false, missing: true };
			}
			if ( ! response.ok ) {
				return { refreshed: false, missing: false };
			}
			return parseBody( response ).then( function ( data ) {
				if ( data && typeof data.nonce === 'string' && data.nonce ) {
					currentNonce = data.nonce;
					// Mirror onto the config object so other consumers see the refresh.
					if ( window.mvsRestConfig ) {
						window.mvsRestConfig.nonce = data.nonce;
					}
					return { refreshed: true, missing: false };
				}
				return { refreshed: false, missing: false };
			} );
		} ).catch( function () {
			return { refreshed: false, missing: false };
		} );
	}

	/**
	 * Core request logic — builds the fetch init, fires the request, handles
	 * the optional nonce-refresh retry on 403.
	 *
	 * @param {string}  path
	 * @param {Object}  opts      - method, headers, body, signal passthrough.
	 * @param {boolean} isRetry   - true when called from the refresh path.
	 * @return {Promise<{ok: boolean, status: number, data: *, headers?: Headers, error?: string}>}
	 */
	function performRequest( path, opts, isRetry ) {
		opts = opts || {};
		var method  = ( opts.method || 'GET' ).toUpperCase();
		var headers = {};
		var k;

		// Copy caller headers first so they can override defaults.
		if ( opts.headers ) {
			for ( k in opts.headers ) {
				if ( Object.prototype.hasOwnProperty.call( opts.headers, k ) ) {
					headers[ k ] = opts.headers[ k ];
				}
			}
		}

		// Inject nonce unless the caller already set it.
		var nonce = activeNonce();
		if ( nonce && ! headers[ 'X-WP-Nonce' ] ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}

		var body = opts.body;
		if ( body instanceof FormData ) {
			// FormData: do NOT set Content-Type — let the browser set the
			// multipart boundary. Do NOT JSON-encode.
		} else if ( isPlainObject( body ) ) {
			if ( ! headers[ 'Content-Type' ] ) {
				headers[ 'Content-Type' ] = 'application/json';
			}
			body = JSON.stringify( body );
		}
		// String bodies pass through unchanged.

		var init = {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			signal: opts.signal
		};
		if ( typeof body !== 'undefined' && method !== 'GET' && method !== 'HEAD' ) {
			init.body = body;
		}

		var url = normalizeQuery( buildUrl( path ) );

		return doFetch( url, init ).then( function ( response ) {
			return parseBody( response ).then( function ( data ) {
				var result = {
					ok: response.ok,
					status: response.status,
					data: data,
					// Expose the response Headers object so callers can read
					// pagination / state headers the REST API sets but does not
					// duplicate in the JSON body (e.g. X-WP-Total,
					// X-WP-TotalPages, X-MVS-Voted-Entries). Use
					// result.headers.get('X-WP-Total'). On a network failure the
					// catch branch below omits headers, so guard with
					// `result.headers && result.headers.get(...)`.
					headers: response.headers
				};

				// 403 + stale nonce → refresh once and retry.
				if (
					! isRetry
					&& response.status === 403
					&& data
					&& data.code === 'rest_cookie_invalid_nonce'
				) {
					return refreshNonce().then( function ( state ) {
						if ( state.refreshed ) {
							return performRequest( path, opts, true );
						}
						// Endpoint missing or refresh failed — return original 403.
						return result;
					} );
				}

				return result;
			} );
		} ).catch( function ( err ) {
			// Network failure or abort. Uniform shape so callers don't need
			// separate try/catch blocks.
			return {
				ok: false,
				status: 0,
				data: null,
				error: err && err.message ? err.message : 'network_error'
			};
		} );
	}

	/**
	 * Public API: perform a REST request against the mvs/v1 namespace.
	 *
	 * @param {string} path  Relative path ('me/profile') or absolute URL.
	 * @param {Object} [opts] fetch-compatible options: method, headers, body, signal.
	 * @return {Promise<{ok: boolean, status: number, data: *, headers?: Headers, error?: string}>}
	 */
	function restFetch( path, opts ) {
		return performRequest( path, opts || {}, false );
	}

	/**
	 * Resolve the gettext `__` at call time. mvs-rest loads in the header
	 * before wp-i18n may be present, so we look it up when the message is
	 * actually needed and fall back to the English string otherwise. Mirrors
	 * the guarded bridge in assets/js/bp-activity-media.js.
	 *
	 * @return {function(string, string=): string}
	 */
	function gettext() {
		return ( window.wp && window.wp.i18n && window.wp.i18n.__ )
			? window.wp.i18n.__
			: function ( s ) { return s; };
	}

	/**
	 * Create an album via `POST mvs/v1/albums`. This is the SINGLE
	 * validate-name + create path shared by every album-create surface
	 * (the upload-modal album mode in the shared-ui block and the BuddyPress
	 * profile/group albums tab). Keeping the validation message and the create
	 * request in one place removes the duplicate implementations that drifted
	 * apart (Basecamp 10069383195).
	 *
	 * Album names are intentionally NOT unique — they are per-member labels
	 * (AlbumService::create runs no name-uniqueness check), so validation only
	 * rejects an empty name.
	 *
	 * @param {string} name              Album title (trimmed internally).
	 * @param {Object} [opts]
	 * @param {string} [opts.description] Album description.
	 * @param {string} [opts.privacy]     Privacy value ('public'|'members'|'friends'|'private').
	 * @param {number} [opts.groupId]     BuddyPress group id when created in a group.
	 * @return {Promise<{ok: boolean, data: *, code: string, message: string}>}
	 *   ok:false, code:'empty_name'    — blank name, no request sent.
	 *   ok:true,  data:<album>         — created (data.id present).
	 *   ok:false, code:'network_error' — request never reached the server.
	 *   ok:false, code:'create_failed' — server declined; message carries why.
	 */
	function createAlbum( name, opts ) {
		opts = opts || {};
		var __ = gettext();
		var title = ( name == null ? '' : String( name ) ).trim();

		if ( ! title ) {
			return Promise.resolve( {
				ok: false,
				data: null,
				code: 'empty_name',
				message: __( 'Please enter an album name.', 'wpmediaverse' )
			} );
		}

		var body = { title: title };
		if ( opts.description ) {
			body.description = String( opts.description ).trim();
		}
		if ( opts.privacy ) {
			body.privacy = opts.privacy;
		}
		if ( opts.groupId ) {
			var groupId = parseInt( opts.groupId, 10 );
			if ( groupId ) {
				body.group_id = groupId;
			}
		}

		return restFetch( 'albums', { method: 'POST', body: body } ).then( function ( r ) {
			var data = ( r && r.data ) ? r.data : null;
			if ( data && data.id ) {
				return { ok: true, data: data, code: '', message: '' };
			}
			if ( r && r.status === 0 ) {
				return {
					ok: false,
					data: null,
					code: 'network_error',
					message: __( 'Network error. Please try again.', 'wpmediaverse' )
				};
			}
			return {
				ok: false,
				data: data,
				code: 'create_failed',
				message: ( data && data.message )
					? data.message
					: __( 'Failed to create album.', 'wpmediaverse' )
			};
		} );
	}

	window.mvsRest = {
		restFetch: restFetch,
		createAlbum: createAlbum
	};
} )();
