import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

function MediaBrowser( { onSelect } ) {
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ searched, setSearched ] = useState( false );

	const doSearch = async () => {
		setLoading( true );
		setSearched( true );
		try {
			const params = new URLSearchParams( { per_page: '10', media_type: 'video,audio' } );
			if ( query ) params.set( 'search', query );
			const res = await apiFetch( { path: `/mvs/v1/media?${ params }` } );
			setResults( Array.isArray( res ) ? res : [] );
		} catch {
			setResults( [] );
		}
		setLoading( false );
	};

	return (
		<div style={ { marginTop: '8px' } }>
			<div style={ { display: 'flex', gap: '4px', marginBottom: '8px' } }>
				<TextControl
					placeholder={ __( 'Search media...', 'wpmediaverse' ) }
					value={ query }
					onChange={ setQuery }
					__nextHasNoMarginBottom
					style={ { flex: 1 } }
				/>
				<Button variant="secondary" onClick={ doSearch } disabled={ loading } size="compact">
					{ loading ? <Spinner /> : __( 'Search', 'wpmediaverse' ) }
				</Button>
			</div>
			{ ! searched && (
				<Button variant="secondary" onClick={ doSearch } disabled={ loading } style={ { width: '100%' } }>
					{ loading ? <Spinner /> : __( 'Browse Video & Audio', 'wpmediaverse' ) }
				</Button>
			) }
			{ searched && results.length === 0 && ! loading && (
				<p style={ { fontSize: '12px', color: '#999', textAlign: 'center' } }>
					{ __( 'No media found.', 'wpmediaverse' ) }
				</p>
			) }
			{ results.length > 0 && (
				<div style={ { maxHeight: '200px', overflowY: 'auto', border: '1px solid #ddd', borderRadius: '4px' } }>
					{ results.map( ( item ) => (
						<button
							key={ item.id }
							onClick={ () => onSelect( item.id ) }
							style={ {
								display: 'flex', alignItems: 'center', gap: '8px', padding: '6px 8px',
								width: '100%', border: 'none', borderBottom: '1px solid #eee',
								background: 'none', cursor: 'pointer', textAlign: 'left', fontSize: '12px',
							} }
						>
							{ item.thumbnail_url ? (
								<img src={ item.thumbnail_url } alt="" style={ { width: '32px', height: '32px', objectFit: 'cover', borderRadius: '2px' } } />
							) : (
								<span className="dashicons dashicons-media-default" style={ { width: '32px', height: '32px', fontSize: '32px', opacity: 0.4 } }></span>
							) }
							<span style={ { flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
								{ item.title || __( 'Untitled', 'wpmediaverse' ) }
							</span>
							<span style={ { color: '#999', fontSize: '11px' } }>ID: { item.id }</span>
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const { mediaId, autoplay, loop, showDownload } = attributes;
	const [ showBrowser, setShowBrowser ] = useState( false );
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Player Settings', 'wpmediaverse' ) }>
					<TextControl
						label={ __( 'Media ID', 'wpmediaverse' ) }
						type="number"
						value={ mediaId || '' }
						onChange={ ( val ) => setAttributes( { mediaId: parseInt( val, 10 ) || 0 } ) }
						help={ __( 'Enter a WPMediaVerse media item ID, or use Browse below.', 'wpmediaverse' ) }
					/>
					<Button
						variant="secondary"
						onClick={ () => setShowBrowser( ! showBrowser ) }
						style={ { width: '100%', justifyContent: 'center', marginBottom: '12px' } }
					>
						{ showBrowser ? __( 'Hide Browser', 'wpmediaverse' ) : __( 'Browse Media', 'wpmediaverse' ) }
					</Button>
					{ showBrowser && (
						<MediaBrowser onSelect={ ( id ) => {
							setAttributes( { mediaId: id } );
							setShowBrowser( false );
						} } />
					) }
					<ToggleControl
						label={ __( 'Autoplay', 'wpmediaverse' ) }
						checked={ autoplay }
						onChange={ ( val ) => setAttributes( { autoplay: val } ) }
					/>
					<ToggleControl
						label={ __( 'Loop', 'wpmediaverse' ) }
						checked={ loop }
						onChange={ ( val ) => setAttributes( { loop: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Download Button', 'wpmediaverse' ) }
						checked={ showDownload }
						onChange={ ( val ) => setAttributes( { showDownload: val } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div style={ { background: '#1a1a1a', borderRadius: '8px', padding: '40px', textAlign: 'center', color: '#fff' } }>
					<span className="dashicons dashicons-controls-play" style={ { fontSize: '48px', opacity: 0.5 } }></span>
					<p>{ __( 'Media Player', 'wpmediaverse' ) }</p>
					{ mediaId > 0 && <p style={ { fontSize: '12px', opacity: 0.7 } }>{ `Media ID: ${ mediaId }` }</p> }
					{ ! mediaId && <p style={ { fontSize: '12px', opacity: 0.5 } }>{ __( 'Set a Media ID in the sidebar.', 'wpmediaverse' ) }</p> }
				</div>
			</div>
		</>
	);
}
