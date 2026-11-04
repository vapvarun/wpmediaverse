import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, ToggleControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	StandardInspectorPanels,
	EntityPicker,
	useEntity,
} from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { albumId, columns, showTitle, showDescription } = attributes;
	const blockProps = useBlockProps();
	const album = useEntity( '/mvs/v1/albums', albumId );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Album Settings', 'wpmediaverse' ) }>
					<p style={ { margin: '0 0 8px' } }>
						<strong>{ __( 'Album', 'wpmediaverse' ) }: </strong>
						{ ! albumId && __( 'None chosen', 'wpmediaverse' ) }
						{ albumId > 0 && album && album.title }
						{ albumId > 0 &&
							false === album &&
							__( 'Not found', 'wpmediaverse' ) }
					</p>
					<EntityPicker
						path="/mvs/v1/albums"
						browseLabel={ __( 'Browse albums', 'wpmediaverse' ) }
						thumb={ ( item ) => item.cover_url || '' }
						onSelect={ ( item ) =>
							setAttributes( { albumId: item.id } )
						}
					/>
					<RangeControl
						label={ __( 'Columns', 'wpmediaverse' ) }
						value={ columns }
						onChange={ ( val ) =>
							setAttributes( { columns: val } )
						}
						min={ 2 }
						max={ 5 }
					/>
					<ToggleControl
						label={ __( 'Show Title', 'wpmediaverse' ) }
						checked={ showTitle }
						onChange={ ( val ) =>
							setAttributes( { showTitle: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Show Description', 'wpmediaverse' ) }
						checked={ showDescription }
						onChange={ ( val ) =>
							setAttributes( { showDescription: val } )
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
						border: '1px dashed #ccc',
						padding: '30px',
						textAlign: 'center',
						borderRadius: '4px',
					} }
				>
					<span
						className="dashicons dashicons-images-alt2"
						style={ { fontSize: '36px', opacity: 0.4 } }
					></span>
					<p>{ __( 'Album Viewer', 'wpmediaverse' ) }</p>
					{ album && album.cover_url && (
						<img
							src={ album.cover_url }
							alt=""
							style={ {
								maxWidth: '100%',
								maxHeight: '180px',
								objectFit: 'cover',
								borderRadius: '4px',
							} }
						/>
					) }
					{ album ? (
						<p style={ { fontSize: '13px' } }>
							<strong>{ album.title }</strong>
							{ ' · ' }
							{ sprintf(
								/* translators: %d: number of items in the album. */ _n(
									'%d item',
									'%d items',
									album.media_count,
									'wpmediaverse'
								),
								album.media_count
							) }
						</p>
					) : (
						<p style={ { fontSize: '12px', color: '#757575' } }>
							{ albumId > 0 && false === album
								? __(
										'This album was not found or is private.',
										'wpmediaverse'
								  )
								: __(
										'Choose an album in the sidebar.',
										'wpmediaverse'
								  ) }
						</p>
					) }
				</div>
			</div>
		</>
	);
}
