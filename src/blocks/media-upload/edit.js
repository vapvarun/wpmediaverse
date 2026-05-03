import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { StandardInspectorPanels } from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { maxFiles, showPrivacy } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Upload Settings', 'wpmediaverse' ) }>
					<RangeControl
						label={ __( 'Max Files', 'wpmediaverse' ) }
						value={ maxFiles }
						onChange={ ( val ) => setAttributes( { maxFiles: val } ) }
						min={ 1 }
						max={ 50 }
					/>
					<ToggleControl
						label={ __( 'Show Privacy Selector', 'wpmediaverse' ) }
						checked={ showPrivacy }
						onChange={ ( val ) => setAttributes( { showPrivacy: val } ) }
					/>
				</PanelBody>
				<StandardInspectorPanels attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>
			<div { ...blockProps }>
				<div className="mvs-upload-placeholder">
					<span className="dashicons dashicons-upload" style={ { fontSize: '48px', display: 'block', textAlign: 'center', opacity: 0.5 } }></span>
					<p style={ { textAlign: 'center', color: '#757575' } }>
						{ __( 'Media Upload Form', 'wpmediaverse' ) }
					</p>
					<p style={ { textAlign: 'center', fontSize: '12px', color: '#999' } }>
						{ __( 'Drag & drop upload area will appear on the frontend.', 'wpmediaverse' ) }
					</p>
				</div>
			</div>
		</>
	);
}
