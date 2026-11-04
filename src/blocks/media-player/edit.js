import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	StandardInspectorPanels,
	EntityPicker,
	useEntity,
} from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { mediaId, autoplay, loop, showDownload } = attributes;
	const media = useEntity( '/mvs/v1/media', mediaId );
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Player Settings', 'wpmediaverse' ) }>
					<p style={ { margin: '0 0 8px' } }>
						<strong>{ __( 'Media', 'wpmediaverse' ) }: </strong>
						{ ! mediaId && __( 'None chosen', 'wpmediaverse' ) }
						{ mediaId > 0 &&
							media &&
							( media.title ||
								__( 'Untitled', 'wpmediaverse' ) ) }
						{ mediaId > 0 &&
							false === media &&
							__( 'Not found', 'wpmediaverse' ) }
					</p>
					<EntityPicker
						path="/mvs/v1/media"
						params={ { media_type: 'video,audio' } }
						browseLabel={ __(
							'Browse video & audio',
							'wpmediaverse'
						) }
						thumb={ ( item ) => item.thumbnail_url || '' }
						onSelect={ ( item ) =>
							setAttributes( { mediaId: item.id } )
						}
					/>
					<ToggleControl
						label={ __( 'Autoplay', 'wpmediaverse' ) }
						checked={ autoplay }
						onChange={ ( val ) =>
							setAttributes( { autoplay: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Loop', 'wpmediaverse' ) }
						checked={ loop }
						onChange={ ( val ) => setAttributes( { loop: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Download Button', 'wpmediaverse' ) }
						checked={ showDownload }
						onChange={ ( val ) =>
							setAttributes( { showDownload: val } )
						}
					/>
				</PanelBody>
				<StandardInspectorPanels
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
			</InspectorControls>
			<div { ...blockProps }>
				<div
					style={ {
						background: '#1a1a1a',
						borderRadius: '8px',
						padding: '40px',
						textAlign: 'center',
						color: '#fff',
					} }
				>
					<span
						className="dashicons dashicons-controls-play"
						style={ { fontSize: '48px', opacity: 0.5 } }
					></span>
					<p>{ __( 'Media Player', 'wpmediaverse' ) }</p>
					{ media && (
						<p style={ { fontSize: '13px', opacity: 0.85 } }>
							{ media.title || __( 'Untitled', 'wpmediaverse' ) }
						</p>
					) }
					{ ! media && (
						<p style={ { fontSize: '12px', opacity: 0.6 } }>
							{ mediaId > 0 && false === media
								? __(
										'This media was not found or is private.',
										'wpmediaverse'
								  )
								: __(
										'Choose a video or audio file in the sidebar.',
										'wpmediaverse'
								  ) }
						</p>
					) }
				</div>
			</div>
		</>
	);
}
