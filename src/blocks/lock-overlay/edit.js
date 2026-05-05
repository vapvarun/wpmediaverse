import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { StandardInspectorPanels } from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { mediaId, blurAmount, showPrice, unlockLabel, overlayOpacity } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Lock Settings', 'wpmediaverse' ) }>
					<TextControl
						label={ __( 'Media ID', 'wpmediaverse' ) }
						type="number"
						value={ mediaId || '' }
						onChange={ ( val ) => setAttributes( { mediaId: parseInt( val, 10 ) || 0 } ) }
						help={ __( 'Enter the ID of the gated media item.', 'wpmediaverse' ) }
					/>
					<RangeControl
						label={ __( 'Blur Amount (px)', 'wpmediaverse' ) }
						value={ blurAmount }
						onChange={ ( val ) => setAttributes( { blurAmount: val } ) }
						min={ 0 }
						max={ 50 }
					/>
					<RangeControl
						label={ __( 'Overlay Opacity (%)', 'wpmediaverse' ) }
						value={ overlayOpacity }
						onChange={ ( val ) => setAttributes( { overlayOpacity: val } ) }
						min={ 0 }
						max={ 100 }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Display', 'wpmediaverse' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Show Price', 'wpmediaverse' ) }
						checked={ showPrice }
						onChange={ ( val ) => setAttributes( { showPrice: val } ) }
					/>
					<TextControl
						label={ __( 'Unlock Button Label', 'wpmediaverse' ) }
						value={ unlockLabel }
						onChange={ ( val ) => setAttributes( { unlockLabel: val } ) }
						placeholder={ __( 'Unlock This Media', 'wpmediaverse' ) }
					/>
				</PanelBody>
				<StandardInspectorPanels attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>
			<div { ...blockProps }>
				<div className="mvs-lock-overlay-preview" style={ { position: 'relative', background: '#f0f0f0', aspectRatio: '16/9', display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: '8px', overflow: 'hidden' } }>
					<div style={ { filter: `blur(${ blurAmount }px)`, position: 'absolute', inset: 0, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', opacity: 0.5 } }></div>
					<div style={ { position: 'relative', textAlign: 'center', color: '#333' } }>
						<span className="dashicons dashicons-lock" style={ { fontSize: '48px', display: 'block', marginBottom: '8px' } }></span>
						<p style={ { margin: 0, fontWeight: 600 } }>
							{ __( 'Lock Overlay', 'wpmediaverse' ) }
							{ mediaId ? ` (ID: ${ mediaId })` : '' }
						</p>
						<p style={ { margin: '4px 0 0', fontSize: '12px', color: '#666' } }>
							{ __( 'Gated content renders on the frontend.', 'wpmediaverse' ) }
						</p>
					</div>
				</div>
			</div>
		</>
	);
}
