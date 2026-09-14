import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
	TextControl,
	Spinner,
	__experimentalNumberControl as NumberControl,
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
 * The preview is a representative sample, not the published page: a block set
 * to 48 per page should not pull 48 rows on every attribute change. The
 * published grid still honours the full Items Per Page value.
 */
const PREVIEW_MAX = 12;

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const {
		layout,
		columns,
		perPage,
		mediaType,
		category,
		tag,
		orderBy,
		order,
		showLightbox,
		showReactions,
		gap,
		userId,
	} = attributes;
	const blockProps = useBlockProps();

	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ failed, setFailed ] = useState( false );

	// Param names are the ones GET /mvs/v1/media actually registers:
	// include, per_page, page, media_type, author, slug, orderby, order, tag,
	// category, s, scope, group_covers. There is no `user_id` or `search`.
	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setFailed( false );

		const params = new URLSearchParams( {
			per_page: String( Math.min( perPage || PREVIEW_MAX, PREVIEW_MAX ) ),
			orderby: orderBy || 'date',
			order: order || 'desc',
		} );
		if ( mediaType ) {
			params.set( 'media_type', mediaType );
		}
		if ( category ) {
			params.set( 'category', category );
		}
		if ( tag ) {
			params.set( 'tag', tag );
		}
		if ( userId ) {
			params.set( 'author', String( userId ) );
		}

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
	}, [ perPage, mediaType, category, tag, orderBy, order, userId ] );

	const gridClass = `mvs-media-grid mvs-cols-${ columns || 3 }`;
	const gridStyle = { '--mvs-grid-gap': `${ gap }px` };

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Grid Settings', 'wpmediaverse' ) }>
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
						help={
							! layout
								? __(
										'Using admin default from Settings → Display.',
										'wpmediaverse'
								  )
								: ''
						}
					/>
					<RangeControl
						label={ __( 'Columns', 'wpmediaverse' ) }
						value={ columns || undefined }
						onChange={ ( val ) =>
							setAttributes( { columns: val } )
						}
						min={ 2 }
						max={ 5 }
						allowReset
						resetFallbackValue={ undefined }
						help={
							! columns
								? __(
										'Using admin default from Settings → Display.',
										'wpmediaverse'
								  )
								: ''
						}
					/>
					<RangeControl
						label={ __( 'Items Per Page', 'wpmediaverse' ) }
						value={ perPage }
						onChange={ ( val ) =>
							setAttributes( { perPage: val } )
						}
						min={ 4 }
						max={ 48 }
					/>
					<RangeControl
						label={ __( 'Gap (px)', 'wpmediaverse' ) }
						value={ gap }
						onChange={ ( val ) => setAttributes( { gap: val } ) }
						min={ 0 }
						max={ 24 }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Filters', 'wpmediaverse' ) }
					initialOpen={ false }
				>
					<NumberControl
						label={ __( 'User ID', 'wpmediaverse' ) }
						value={ userId || '' }
						onChange={ ( val ) =>
							setAttributes( {
								userId: parseInt( val, 10 ) || 0,
							} )
						}
						min={ 0 }
						help={ __(
							"Show only this user's media, or leave empty (0) for no author filter.",
							'wpmediaverse'
						) }
					/>
					<SelectControl
						label={ __( 'Media Type', 'wpmediaverse' ) }
						value={ mediaType }
						options={ [
							{ label: __( 'All', 'wpmediaverse' ), value: '' },
							{
								label: __( 'Images', 'wpmediaverse' ),
								value: 'image',
							},
							{
								label: __( 'Video', 'wpmediaverse' ),
								value: 'video',
							},
							{
								label: __( 'Audio', 'wpmediaverse' ),
								value: 'audio',
							},
						] }
						onChange={ ( val ) =>
							setAttributes( { mediaType: val } )
						}
					/>
					<TextControl
						label={ __( 'Category Slug', 'wpmediaverse' ) }
						value={ category }
						onChange={ ( val ) =>
							setAttributes( { category: val } )
						}
					/>
					<TextControl
						label={ __( 'Tag Slug', 'wpmediaverse' ) }
						value={ tag }
						onChange={ ( val ) => setAttributes( { tag: val } ) }
					/>
					<SelectControl
						label={ __( 'Order By', 'wpmediaverse' ) }
						value={ orderBy }
						options={ [
							{
								label: __( 'Date', 'wpmediaverse' ),
								value: 'date',
							},
							{
								label: __( 'Title', 'wpmediaverse' ),
								value: 'title',
							},
							{
								label: __(
									'Popular (reactions + views)',
									'wpmediaverse'
								),
								value: 'popular',
							},
							{
								label: __( 'Most viewed', 'wpmediaverse' ),
								value: 'views',
							},
							{
								label: __( 'Most reactions', 'wpmediaverse' ),
								value: 'reactions',
							},
							{
								label: __( 'Random', 'wpmediaverse' ),
								value: 'random',
							},
						] }
						onChange={ ( val ) =>
							setAttributes( { orderBy: val } )
						}
					/>
					{ 'random' !== orderBy && (
						<SelectControl
							label={ __( 'Direction', 'wpmediaverse' ) }
							value={ order }
							options={ [
								{
									label: __(
										'Newest / highest first',
										'wpmediaverse'
									),
									value: 'desc',
								},
								{
									label: __(
										'Oldest / lowest first',
										'wpmediaverse'
									),
									value: 'asc',
								},
							] }
							onChange={ ( val ) =>
								setAttributes( { order: val } )
							}
						/>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Features', 'wpmediaverse' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Lightbox', 'wpmediaverse' ) }
						checked={ showLightbox }
						onChange={ ( val ) =>
							setAttributes( { showLightbox: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Show Reactions', 'wpmediaverse' ) }
						checked={ showReactions }
						onChange={ ( val ) =>
							setAttributes( { showReactions: val } )
						}
					/>
				</PanelBody>
				<StandardInspectorPanels
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="mvs-grid-editor-preview">
					{ loading && (
						<p className="mvs-grid-editor-preview__state">
							<Spinner />{ ' ' }
							{ __( 'Loading your media…', 'wpmediaverse' ) }
						</p>
					) }

					{ ! loading && failed && (
						<p className="mvs-grid-editor-preview__state">
							{ __(
								'Could not load a preview. The grid will still render on the published page.',
								'wpmediaverse'
							) }
						</p>
					) }

					{ ! loading && ! failed && 0 === items.length && (
						<p className="mvs-grid-editor-preview__state">
							{ __(
								'No media matches these filters yet. Widen the filters, or upload media first - the published page will show whatever matches at the time.',
								'wpmediaverse'
							) }
						</p>
					) }

					{ ! loading && ! failed && items.length > 0 && (
						<>
							<div className={ gridClass } style={ gridStyle }>
								{ items.map( ( item ) => {
									const src =
										item.large_url ||
										item.thumb_url ||
										item.thumbnail_url ||
										'';
									return (
										<div
											key={ item.id }
											className="mvs-grid-editor-preview__tile"
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
												<span className="mvs-grid-editor-preview__type">
													{ item.media_type ||
														__(
															'media',
															'wpmediaverse'
														) }
												</span>
											) }
											{ item.title && (
												<span className="mvs-grid-editor-preview__label">
													{ item.title }
												</span>
											) }
										</div>
									);
								} ) }
							</div>
							<p className="mvs-grid-editor-preview__note">
								{ items.length < ( perPage || PREVIEW_MAX )
									? sprintf(
											/* translators: 1: number shown in the editor preview, 2: items per page setting. */
											__(
												'Previewing %1$d of up to %2$d items. Live counts update on the published page.',
												'wpmediaverse'
											),
											items.length,
											perPage
									  )
									: sprintf(
											/* translators: %d: items per page setting. */
											__(
												'Preview sample. The published page shows up to %d items with pagination.',
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
