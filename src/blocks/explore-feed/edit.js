import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	ToggleControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { StandardInspectorPanels } from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';
import './editor.css';

/**
 * How many real items the editor preview fetches.
 *
 * A representative sample, not the published page: a block set to 48 per page
 * should not pull 48 rows on every attribute change. The published feed still
 * honours the full Items Per Page value.
 */
const PREVIEW_MAX = 12;

/*
 * This block has no media-type / category / tag / author attributes - its
 * whole point is the unfiltered explore feed, filtered by the VISITOR at
 * runtime via the type buttons. So the preview fetch takes per_page only.
 * Do not copy media-grid's filter params here; they do not exist on this
 * block and would invent controls the customer never set.
 */

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { layout, perPage, showFilters, showSearch, columns } = attributes;
	const blockProps = useBlockProps();

	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setFailed( false );

		const params = new URLSearchParams( {
			per_page: String( Math.min( perPage || PREVIEW_MAX, PREVIEW_MAX ) ),
		} );

		apiFetch( { path: `/mvs/v1/media?${ params }` } )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setItems( Array.isArray( res ) ? res : [] );
				setLoading( false );
			} )
			.catch( () => {
				if ( cancelled ) {
					return;
				}
				setItems( [] );
				setFailed( true );
				setLoading( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [ perPage ] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Explore Settings', 'wpmediaverse' ) }>
					<SelectControl
						label={ __( 'Layout', 'wpmediaverse' ) }
						value={ layout }
						options={ [
							{
								label: __( 'Site default', 'wpmediaverse' ),
								value: '',
							},
							{
								label: __( 'Grid', 'wpmediaverse' ),
								value: 'grid',
							},
							{
								label: __( 'Justified rows', 'wpmediaverse' ),
								value: 'masonry',
							},
							{
								label: __( 'List', 'wpmediaverse' ),
								value: 'list',
							},
						] }
						onChange={ ( val ) => setAttributes( { layout: val } ) }
					/>
					<RangeControl
						label={ __( 'Columns', 'wpmediaverse' ) }
						value={ columns }
						onChange={ ( val ) =>
							setAttributes( { columns: val } )
						}
						min={ 2 }
						max={ 5 }
					/>
					<RangeControl
						label={ __( 'Items Per Page', 'wpmediaverse' ) }
						value={ perPage }
						onChange={ ( val ) =>
							setAttributes( { perPage: val } )
						}
						min={ 6 }
						max={ 48 }
					/>
					<ToggleControl
						label={ __( 'Show Type Filters', 'wpmediaverse' ) }
						checked={ showFilters }
						onChange={ ( val ) =>
							setAttributes( { showFilters: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Show Search', 'wpmediaverse' ) }
						checked={ showSearch }
						onChange={ ( val ) =>
							setAttributes( { showSearch: val } )
						}
					/>
				</PanelBody>
				<StandardInspectorPanels
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="mvs-explore-editor-preview">
					{ showSearch && (
						<div className="mvs-explore-editor-preview__search">
							{ __( 'Search media…', 'wpmediaverse' ) }
						</div>
					) }

					{ showFilters && (
						<div className="mvs-explore-editor-preview__filters">
							{ [
								__( 'All', 'wpmediaverse' ),
								__( 'Images', 'wpmediaverse' ),
								__( 'Video', 'wpmediaverse' ),
								__( 'Audio', 'wpmediaverse' ),
							].map( ( type ) => (
								<span
									key={ type }
									className="mvs-explore-editor-preview__chip"
								>
									{ type }
								</span>
							) ) }
						</div>
					) }

					{ loading && (
						<p className="mvs-explore-editor-preview__state">
							<Spinner />{ ' ' }
							{ __( 'Loading your media…', 'wpmediaverse' ) }
						</p>
					) }

					{ ! loading && failed && (
						<p className="mvs-explore-editor-preview__state">
							{ __(
								'Could not load a preview. The feed will still render on the published page.',
								'wpmediaverse'
							) }
						</p>
					) }

					{ ! loading && ! failed && 0 === items.length && (
						<p className="mvs-explore-editor-preview__state">
							{ __(
								'No media to show yet. Upload media first - the published page shows whatever exists at the time.',
								'wpmediaverse'
							) }
						</p>
					) }

					{ ! loading && ! failed && items.length > 0 && (
						<>
							<div
								className={ `mvs-media-grid mvs-cols-${
									columns || 3
								}` }
							>
								{ items.map( ( item ) => {
									const src =
										item.large_url ||
										item.thumb_url ||
										item.thumbnail_url ||
										'';
									return (
										<div
											key={ item.id }
											className="mvs-explore-editor-preview__tile"
											style={
												item.placeholder_color
													? {
															background:
																item.placeholder_color,
													  }
													: undefined
											}
										>
											{ src ? (
												<img
													src={ src }
													alt={ item.title || '' }
													loading="lazy"
												/>
											) : (
												<span className="mvs-explore-editor-preview__type">
													{ item.media_type ||
														__(
															'media',
															'wpmediaverse'
														) }
												</span>
											) }
											{ item.title && (
												<span className="mvs-explore-editor-preview__label">
													{ item.title }
												</span>
											) }
										</div>
									);
								} ) }
							</div>
							<p className="mvs-explore-editor-preview__note">
								{ sprintf(
									/* translators: %d: items per page setting. */
									__(
										'Preview sample. Visitors filter and search this feed live; the published page shows up to %d items.',
										'wpmediaverse'
									),
									perPage
								) }
							</p>
						</>
					) }
				</div>
			</div>
		</>
	);
}
