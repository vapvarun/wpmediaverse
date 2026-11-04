import {
	TextControl,
	Button,
	Spinner,
	ComboboxControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Search-and-pick list over a MediaVerse REST collection, so no block asks an
 * editor to type a database id (card 10470288160). Used by Media Player (media)
 * and Album Viewer (albums). Both routes take the search term as `s`.
 *
 * @param {Object}   props
 * @param {string}   props.path        Collection route, e.g. '/mvs/v1/albums'.
 * @param {Object}   props.params      Extra query args (media_type, ...).
 * @param {string}   props.browseLabel Label of the button that lists without a search.
 * @param {Function} props.thumb       item => thumbnail URL or ''.
 * @param {Function} props.onSelect    item => void.
 */
export function EntityPicker( {
	path,
	params = {},
	browseLabel,
	thumb,
	onSelect,
} ) {
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ searched, setSearched ] = useState( false );

	const doSearch = async () => {
		setLoading( true );
		setSearched( true );
		try {
			const res = await apiFetch( {
				path: addQueryArgs( path, {
					per_page: 20,
					...params,
					...( query ? { s: query } : {} ),
				} ),
			} );
			setResults( Array.isArray( res ) ? res : [] );
		} catch {
			setResults( [] );
		}
		setLoading( false );
	};

	return (
		<div style={ { marginBottom: '12px' } }>
			<div style={ { display: 'flex', gap: '4px', marginBottom: '8px' } }>
				<TextControl
					placeholder={ __( 'Search by title…', 'wpmediaverse' ) }
					value={ query }
					onChange={ setQuery }
					onKeyDown={ ( e ) => 'Enter' === e.key && doSearch() }
					__nextHasNoMarginBottom
					style={ { flex: 1 } }
				/>
				<Button
					variant="secondary"
					onClick={ doSearch }
					disabled={ loading }
					size="compact"
				>
					{ loading ? <Spinner /> : __( 'Search', 'wpmediaverse' ) }
				</Button>
			</div>
			{ ! searched && (
				<Button
					variant="secondary"
					onClick={ doSearch }
					disabled={ loading }
					style={ { width: '100%', justifyContent: 'center' } }
				>
					{ browseLabel }
				</Button>
			) }
			{ searched && ! loading && results.length === 0 && (
				<p
					style={ {
						fontSize: '12px',
						color: '#757575',
						textAlign: 'center',
					} }
				>
					{ __( 'Nothing found.', 'wpmediaverse' ) }
				</p>
			) }
			{ results.length > 0 && (
				<div
					role="listbox"
					style={ {
						maxHeight: '220px',
						overflowY: 'auto',
						border: '1px solid #ddd',
						borderRadius: '4px',
					} }
				>
					{ results.map( ( item ) => (
						<button
							key={ item.id }
							type="button"
							role="option"
							aria-selected="false"
							onClick={ () => onSelect( item ) }
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '8px',
								padding: '6px 8px',
								minHeight: '44px',
								width: '100%',
								border: 'none',
								borderBottom: '1px solid #eee',
								background: 'none',
								cursor: 'pointer',
								textAlign: 'left',
								fontSize: '13px',
							} }
						>
							{ thumb( item ) ? (
								<img
									src={ thumb( item ) }
									alt=""
									style={ {
										width: '32px',
										height: '32px',
										objectFit: 'cover',
										borderRadius: '2px',
									} }
								/>
							) : (
								<span
									className="dashicons dashicons-format-gallery"
									style={ {
										width: '32px',
										height: '32px',
										fontSize: '28px',
										opacity: 0.4,
									} }
								></span>
							) }
							<span
								style={ {
									flex: 1,
									overflow: 'hidden',
									textOverflow: 'ellipsis',
									whiteSpace: 'nowrap',
								} }
							>
								{ item.title ||
									__( 'Untitled', 'wpmediaverse' ) }
							</span>
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

/**
 * Fetch one item of a collection by id, for the "Selected" line and the canvas
 * preview. Returns null while loading or when the id is 0 / gone / private.
 *
 * @param {string} path Collection route.
 * @param {number} id   Item id.
 */
export function useEntity( path, id ) {
	const [ item, setItem ] = useState( null );
	useEffect( () => {
		setItem( null );
		if ( ! id ) {
			return;
		}
		let live = true;
		apiFetch( { path: `${ path }/${ id }` } )
			.then( ( res ) => live && setItem( res && res.id ? res : false ) )
			.catch( () => live && setItem( false ) );
		return () => {
			live = false;
		};
	}, [ path, id ] );
	return item;
}

/**
 * Pick a term by name, store its slug. Searches the server as you type, so a
 * site with thousands of tags works; a slug saved before this picker existed
 * stays selectable even if it is not in the first page of results.
 *
 * @param {Object}   props
 * @param {string}   props.label    Field label.
 * @param {string}   props.path     Terms route ('/wp/v2/mvs_category', '/mvs/v1/tags').
 * @param {string}   props.value    Saved slug.
 * @param {Function} props.onChange slug => void ('' clears).
 */
export function TermPicker( { label, path, value, onChange } ) {
	const [ options, setOptions ] = useState( [] );
	const [ search, setSearch ] = useState( '' );

	useEffect( () => {
		let live = true;
		apiFetch( { path: addQueryArgs( path, { per_page: 50, search } ) } )
			.then( ( res ) => {
				if ( ! live ) {
					return;
				}
				const list = ( Array.isArray( res ) ? res : [] ).map(
					( t ) => ( { value: t.slug, label: t.name } )
				);
				if ( value && ! list.some( ( o ) => o.value === value ) ) {
					list.unshift( { value, label: value } );
				}
				setOptions( list );
			} )
			.catch(
				() =>
					live &&
					setOptions( value ? [ { value, label: value } ] : [] )
			);
		return () => {
			live = false;
		};
	}, [ path, search, value ] );

	return (
		<ComboboxControl
			label={ label }
			value={ value || null }
			options={ options }
			onChange={ ( slug ) => onChange( slug || '' ) }
			onFilterValueChange={ setSearch }
			help={ __(
				'Type to search. Leave empty for all.',
				'wpmediaverse'
			) }
			__nextHasNoMarginBottom
		/>
	);
}
