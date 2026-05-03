import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl, ToggleControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { StandardInspectorPanels } from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { columns, perPage, mediaType, category, tag, orderBy, showLightbox, showReactions, gap } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Grid Settings', 'wpmediaverse' ) }>
					<RangeControl
						label={ __( 'Columns', 'wpmediaverse' ) }
						value={ columns || undefined }
						onChange={ ( val ) => setAttributes( { columns: val } ) }
						min={ 2 }
						max={ 5 }
						allowReset
						resetFallbackValue={ undefined }
						help={ ! columns ? __( 'Using admin default from Settings → Display.', 'wpmediaverse' ) : '' }
					/>
					<RangeControl
						label={ __( 'Items Per Page', 'wpmediaverse' ) }
						value={ perPage }
						onChange={ ( val ) => setAttributes( { perPage: val } ) }
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
				<PanelBody title={ __( 'Filters', 'wpmediaverse' ) } initialOpen={ false }>
					<SelectControl
						label={ __( 'Media Type', 'wpmediaverse' ) }
						value={ mediaType }
						options={ [
							{ label: __( 'All', 'wpmediaverse' ), value: '' },
							{ label: __( 'Images', 'wpmediaverse' ), value: 'image' },
							{ label: __( 'Video', 'wpmediaverse' ), value: 'video' },
							{ label: __( 'Audio', 'wpmediaverse' ), value: 'audio' },
						] }
						onChange={ ( val ) => setAttributes( { mediaType: val } ) }
					/>
					<TextControl
						label={ __( 'Category Slug', 'wpmediaverse' ) }
						value={ category }
						onChange={ ( val ) => setAttributes( { category: val } ) }
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
							{ label: __( 'Date', 'wpmediaverse' ), value: 'date' },
							{ label: __( 'Title', 'wpmediaverse' ), value: 'title' },
							{ label: __( 'Popular', 'wpmediaverse' ), value: 'popular' },
						] }
						onChange={ ( val ) => setAttributes( { orderBy: val } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Features', 'wpmediaverse' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Lightbox', 'wpmediaverse' ) }
						checked={ showLightbox }
						onChange={ ( val ) => setAttributes( { showLightbox: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Reactions', 'wpmediaverse' ) }
						checked={ showReactions }
						onChange={ ( val ) => setAttributes( { showReactions: val } ) }
					/>
				</PanelBody>
				<StandardInspectorPanels attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>
			<div { ...blockProps }>
				<div className={ `mvs-media-grid mvs-cols-${ columns || 3 }` } style={ { gap: `${ gap }px` } }>
					{ Array.from( { length: Math.min( perPage, 6 ) } ).map( ( _, i ) => (
						<div key={ i } className="mvs-grid-item" style={ { background: '#f0f0f0', aspectRatio: '1' } }>
							<span className="dashicons dashicons-format-image" style={ { fontSize: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', opacity: 0.3 } }></span>
						</div>
					) ) }
				</div>
				<p style={ { textAlign: 'center', fontSize: '12px', color: '#999', marginTop: '8px' } }>
					{ __( 'Media Grid. Items load dynamically on the frontend.', 'wpmediaverse' ) }
				</p>
			</div>
		</>
	);
}
