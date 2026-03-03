import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, RangeControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { albumId, columns, showTitle, showDescription } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Album Settings', 'wpmediaverse' ) }>
					<TextControl
						label={ __( 'Album ID', 'wpmediaverse' ) }
						type="number"
						value={ albumId || '' }
						onChange={ ( val ) => setAttributes( { albumId: parseInt( val, 10 ) || 0 } ) }
					/>
					<RangeControl
						label={ __( 'Columns', 'wpmediaverse' ) }
						value={ columns }
						onChange={ ( val ) => setAttributes( { columns: val } ) }
						min={ 2 }
						max={ 5 }
					/>
					<ToggleControl
						label={ __( 'Show Title', 'wpmediaverse' ) }
						checked={ showTitle }
						onChange={ ( val ) => setAttributes( { showTitle: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Description', 'wpmediaverse' ) }
						checked={ showDescription }
						onChange={ ( val ) => setAttributes( { showDescription: val } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div style={ { border: '1px dashed #ccc', padding: '30px', textAlign: 'center', borderRadius: '4px' } }>
					<span className="dashicons dashicons-images-alt2" style={ { fontSize: '36px', opacity: 0.4 } }></span>
					<p>{ __( 'Album Viewer', 'wpmediaverse' ) }</p>
					{ albumId > 0
						? <p style={ { fontSize: '12px', color: '#666' } }>{ `Album ID: ${ albumId }` }</p>
						: <p style={ { fontSize: '12px', color: '#999' } }>{ __( 'Set an Album ID in the sidebar.', 'wpmediaverse' ) }</p>
					}
				</div>
			</div>
		</>
	);
}
