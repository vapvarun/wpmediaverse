import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { count, avatarSize } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Story Settings', 'wpmediaverse' ) }>
					<RangeControl
						label={ __( 'Max Stories', 'wpmediaverse' ) }
						value={ count }
						onChange={ ( val ) => setAttributes( { count: val } ) }
						min={ 3 }
						max={ 20 }
					/>
					<RangeControl
						label={ __( 'Avatar Size (px)', 'wpmediaverse' ) }
						value={ avatarSize }
						onChange={ ( val ) => setAttributes( { avatarSize: val } ) }
						min={ 40 }
						max={ 96 }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div style={ { display: 'flex', gap: '12px', overflowX: 'auto', padding: '8px 0' } }>
					{ Array.from( { length: 5 } ).map( ( _, i ) => (
						<div key={ i } style={ { textAlign: 'center', flexShrink: 0 } }>
							<div style={ {
								width: `${ avatarSize }px`,
								height: `${ avatarSize }px`,
								borderRadius: '50%',
								background: '#f0f0f0',
								border: '3px solid #0073aa',
								margin: '0 auto 4px',
							} }></div>
							<span style={ { fontSize: '11px', color: '#666' } }>{ __( 'User', 'wpmediaverse' ) }</span>
						</div>
					) ) }
				</div>
				<p style={ { textAlign: 'center', fontSize: '12px', color: '#999' } }>
					{ __( 'Active stories appear here on the frontend.', 'wpmediaverse' ) }
				</p>
			</div>
		</>
	);
}
