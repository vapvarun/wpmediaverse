import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { StandardInspectorPanels } from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { showViews, showDownloads, showReactions, showTopMedia, topCount } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Stats Settings', 'wpmediaverse' ) }>
					<ToggleControl
						label={ __( 'Show Views', 'wpmediaverse' ) }
						checked={ showViews }
						onChange={ ( val ) => setAttributes( { showViews: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Downloads', 'wpmediaverse' ) }
						checked={ showDownloads }
						onChange={ ( val ) => setAttributes( { showDownloads: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Reactions', 'wpmediaverse' ) }
						checked={ showReactions }
						onChange={ ( val ) => setAttributes( { showReactions: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Top Media', 'wpmediaverse' ) }
						checked={ showTopMedia }
						onChange={ ( val ) => setAttributes( { showTopMedia: val } ) }
					/>
					{ showTopMedia && (
						<RangeControl
							label={ __( 'Top Items Count', 'wpmediaverse' ) }
							value={ topCount }
							onChange={ ( val ) => setAttributes( { topCount: val } ) }
							min={ 3 }
							max={ 10 }
						/>
					) }
				</PanelBody>
				<StandardInspectorPanels attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>
			<div { ...blockProps }>
				<div style={ { border: '1px dashed #ccc', borderRadius: '4px', padding: '24px', textAlign: 'center' } }>
					<span className="dashicons dashicons-chart-bar" style={ { fontSize: '36px', opacity: 0.4 } }></span>
					<p>{ __( 'Media Stats Dashboard', 'wpmediaverse' ) }</p>
					<p style={ { fontSize: '12px', color: '#999' } }>{ __( 'Shows user media statistics on the frontend.', 'wpmediaverse' ) }</p>
				</div>
			</div>
		</>
	);
}
