import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	RangeControl,
	ToggleControl,
} from '@wordpress/components';
import { StandardInspectorPanels } from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { mediaId, height, showToolbar } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'PDF', 'wpmediaverse' ) } initialOpen>
					<TextControl
						label={ __( 'Media ID', 'wpmediaverse' ) }
						type="number"
						value={ mediaId || '' }
						onChange={ ( val ) => setAttributes( { mediaId: parseInt( val, 10 ) || 0 } ) }
						help={ __(
							'Numeric ID of an MVS media item whose file is a PDF. Upload your PDF first via the Upload page or shortcode.',
							'wpmediaverse'
						) }
					/>
					<RangeControl
						label={ __( 'Viewer height (px)', 'wpmediaverse' ) }
						value={ height }
						onChange={ ( val ) => setAttributes( { height: val } ) }
						min={ 200 }
						max={ 1400 }
						step={ 20 }
					/>
					<ToggleControl
						label={ __( 'Show browser PDF toolbar', 'wpmediaverse' ) }
						checked={ showToolbar }
						onChange={ ( val ) => setAttributes( { showToolbar: val } ) }
						help={ __(
							'Toggle the native PDF toolbar (page navigation, zoom, download). Browser support varies — Chrome and Firefox honor it, Safari ignores.',
							'wpmediaverse'
						) }
					/>
				</PanelBody>

				<StandardInspectorPanels attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>

			<div { ...blockProps }>
				<div
					className="mvs-pdf-viewer-preview"
					style={ {
						border: '1px dashed #ccc',
						borderRadius: '6px',
						padding: '24px',
						textAlign: 'center',
						background: '#f6f7f7',
						minHeight: '160px',
						display: 'flex',
						flexDirection: 'column',
						alignItems: 'center',
						justifyContent: 'center',
						gap: '8px',
					} }
				>
					<i data-lucide="file-text" style={ { fontSize: '32px', opacity: 0.4 } } />
					<strong>
						{ mediaId
							? __( 'PDF Viewer', 'wpmediaverse' ) + ' (ID: ' + mediaId + ')'
							: __( 'PDF Viewer', 'wpmediaverse' ) }
					</strong>
					<span style={ { fontSize: '12px', color: '#666' } }>
						{ mediaId
							? __( 'PDF will render inline on the frontend.', 'wpmediaverse' )
							: __( 'Set a Media ID in the sidebar — the file must be a PDF.', 'wpmediaverse' ) }
					</span>
				</div>
			</div>
		</>
	);
}
