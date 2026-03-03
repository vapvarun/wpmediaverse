import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { mediaId, autoplay, loop, showDownload } = attributes;
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
						help={ __( 'Enter the WPMediaVerse media item ID.', 'wpmediaverse' ) }
					/>
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
