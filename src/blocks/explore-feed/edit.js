import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { layout, perPage, showFilters, showSearch, columns } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Explore Settings', 'wpmediaverse' ) }>
					<SelectControl
						label={ __( 'Layout', 'wpmediaverse' ) }
						value={ layout }
						options={ [
							{ label: __( 'Grid', 'wpmediaverse' ), value: 'grid' },
							{ label: __( 'Masonry', 'wpmediaverse' ), value: 'masonry' },
							{ label: __( 'List', 'wpmediaverse' ), value: 'list' },
						] }
						onChange={ ( val ) => setAttributes( { layout: val } ) }
					/>
					<RangeControl
						label={ __( 'Columns', 'wpmediaverse' ) }
						value={ columns }
						onChange={ ( val ) => setAttributes( { columns: val } ) }
						min={ 2 }
						max={ 5 }
					/>
					<RangeControl
						label={ __( 'Items Per Page', 'wpmediaverse' ) }
						value={ perPage }
						onChange={ ( val ) => setAttributes( { perPage: val } ) }
						min={ 6 }
						max={ 48 }
					/>
					<ToggleControl
						label={ __( 'Show Type Filters', 'wpmediaverse' ) }
						checked={ showFilters }
						onChange={ ( val ) => setAttributes( { showFilters: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Search', 'wpmediaverse' ) }
						checked={ showSearch }
						onChange={ ( val ) => setAttributes( { showSearch: val } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div style={ { border: '1px dashed #ccc', borderRadius: '4px', padding: '20px' } }>
					{ showSearch && (
						<div style={ { marginBottom: '12px' } }>
							<input type="text" disabled placeholder={ __( 'Search media...', 'wpmediaverse' ) } style={ { width: '100%', padding: '8px 16px', borderRadius: '20px', border: '1px solid #ddd' } } />
						</div>
					) }
					{ showFilters && (
						<div style={ { display: 'flex', gap: '8px', marginBottom: '12px' } }>
							{ [ 'All', 'Images', 'Video', 'Audio' ].map( ( type ) => (
								<span key={ type } style={ { padding: '4px 12px', border: '1px solid #ddd', borderRadius: '20px', fontSize: '12px' } }>{ type }</span>
							) ) }
						</div>
					) }
					<div className={ `mvs-media-grid mvs-cols-${ columns }` } style={ { gap: '8px' } }>
						{ Array.from( { length: 6 } ).map( ( _, i ) => (
							<div key={ i } style={ { background: '#f0f0f0', aspectRatio: '1', borderRadius: '4px' } }></div>
						) ) }
					</div>
					<p style={ { textAlign: 'center', fontSize: '12px', color: '#999', marginTop: '8px' } }>
						{ __( 'Explore Feed. Loads dynamically with filtering on the frontend.', 'wpmediaverse' ) }
					</p>
				</div>
			</div>
		</>
	);
}
