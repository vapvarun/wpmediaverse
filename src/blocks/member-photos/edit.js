import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { StandardInspectorPanels } from '../../shared/components';
import { useUniqueId } from '../../shared/hooks';

export default function Edit( { attributes, setAttributes, clientId } ) {
	useUniqueId( clientId, attributes.uniqueId, setAttributes );
	const { userId, columns, perPage, mediaType, showHeader, showActions } = attributes;
	const blockProps = useBlockProps();

	const isAutoDetect = ! userId || userId <= 0;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Member', 'wpmediaverse' ) } initialOpen>
					<NumberControl
						label={ __( 'User ID', 'wpmediaverse' ) }
						value={ userId || '' }
						onChange={ ( val ) => setAttributes( { userId: parseInt( val, 10 ) || 0 } ) }
						min={ 0 }
						help={ __( 'Specific user ID to show, or leave empty (0) to auto-detect: BuddyPress displayed member → post author → current user.', 'wpmediaverse' ) }
					/>
					<ToggleControl
						label={ __( 'Show member header', 'wpmediaverse' ) }
						checked={ showHeader }
						onChange={ ( val ) => setAttributes( { showHeader: val } ) }
						help={ __( 'Display the member\'s avatar and name above the grid.', 'wpmediaverse' ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Grid', 'wpmediaverse' ) }>
					<RangeControl
						label={ __( 'Columns', 'wpmediaverse' ) }
						value={ columns }
						onChange={ ( val ) => setAttributes( { columns: val } ) }
						min={ 1 }
						max={ 6 }
					/>
					<RangeControl
						label={ __( 'Items per page', 'wpmediaverse' ) }
						value={ perPage }
						onChange={ ( val ) => setAttributes( { perPage: val } ) }
						min={ 1 }
						max={ 48 }
					/>
					<SelectControl
						label={ __( 'Media type', 'wpmediaverse' ) }
						value={ mediaType }
						options={ [
							{ label: __( 'All', 'wpmediaverse' ), value: '' },
							{ label: __( 'Images', 'wpmediaverse' ), value: 'image' },
							{ label: __( 'Videos', 'wpmediaverse' ), value: 'video' },
							{ label: __( 'Audio', 'wpmediaverse' ), value: 'audio' },
						] }
						onChange={ ( val ) => setAttributes( { mediaType: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show item actions', 'wpmediaverse' ) }
						checked={ showActions }
						onChange={ ( val ) => setAttributes( { showActions: val } ) }
					/>
				</PanelBody>

				<StandardInspectorPanels attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>

			<div { ...blockProps }>
				<div className="mvs-member-photos-preview">
					{ showHeader && (
						<div className="mvs-member-photos-preview__header">
							<div className="mvs-member-photos-preview__avatar" aria-hidden="true">
								<i data-lucide="user" />
							</div>
							<div>
								<strong>
									{ isAutoDetect
										? __( 'Auto-detect member', 'wpmediaverse' )
										: __( 'User ID', 'wpmediaverse' ) + ' ' + userId }
								</strong>
								{ isAutoDetect && (
									<p className="mvs-member-photos-preview__hint">
										{ __( 'BP displayed member → post author → current user', 'wpmediaverse' ) }
									</p>
								) }
							</div>
						</div>
					) }
					<div
						className={ `mvs-member-photos-preview__grid mvs-cols-${ columns || 3 }` }
						style={ { display: 'grid', gridTemplateColumns: `repeat(${ columns || 3 }, 1fr)`, gap: '8px' } }
					>
						{ Array.from( { length: Math.min( perPage, 6 ) } ).map( ( _, i ) => (
							<div
								key={ i }
								className="mvs-member-photos-preview__item"
								style={ {
									aspectRatio: '1',
									background: '#f0f0f0',
									borderRadius: '4px',
									display: 'flex',
									alignItems: 'center',
									justifyContent: 'center',
								} }
							>
								<i data-lucide="image" style={ { opacity: 0.3, fontSize: '32px' } } />
							</div>
						) ) }
					</div>
					<p className="mvs-member-photos-preview__caption">
						{ __( 'Member photos load from this user\'s media on the frontend.', 'wpmediaverse' ) }
					</p>
				</div>
			</div>
		</>
	);
}
