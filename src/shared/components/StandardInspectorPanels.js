/**
 * Standard inspector panels for every WPMediaVerse block.
 *
 * Mounts the canonical Wbcom block-standard controls (Spacing, Border,
 * Shadow, Visibility) in the standard inspector order so every block
 * gets a uniform sidebar without each edit.js having to repeat the
 * 60-line mount block.
 *
 * Order matches `wp-block-development/references/block-quality-standard.md`
 * Section 11 "Editor UX Requirements" — Style panels before Visibility,
 * each in its own collapsible PanelBody.
 *
 * Block-specific panels (e.g. a "Tournament" panel above the standard
 * ones) are mounted by the block's own edit.js BEFORE
 * `<StandardInspectorPanels>` so they appear first in the sidebar.
 *
 * Usage:
 *
 *   import StandardInspectorPanels from '../../shared/components/StandardInspectorPanels';
 *
 *   <InspectorControls>
 *     <PanelBody title={ __( 'My Block', '...' ) } initialOpen>
 *       …block-specific controls…
 *     </PanelBody>
 *     <StandardInspectorPanels attributes={ attributes } setAttributes={ setAttributes } />
 *   </InspectorControls>
 */

import { __ } from '@wordpress/i18n';
import { PanelBody } from '@wordpress/components';

import SpacingControl from './SpacingControl';
import BorderRadiusControl from './BorderRadiusControl';
import BoxShadowControl from './BoxShadowControl';
import DeviceVisibility from './DeviceVisibility';

export default function StandardInspectorPanels( { attributes, setAttributes } ) {
	const {
		padding,
		paddingUnit,
		margin,
		marginUnit,
		borderRadius,
		borderRadiusUnit,
		boxShadow,
		shadowHorizontal,
		shadowVertical,
		shadowBlur,
		shadowSpread,
		shadowColor,
		hideOnDesktop,
		hideOnTablet,
		hideOnMobile,
	} = attributes;

	return (
		<>
			<PanelBody title={ __( 'Spacing', 'wpmediaverse' ) } initialOpen={ false }>
				<SpacingControl
					label={ __( 'Padding', 'wpmediaverse' ) }
					values={ padding }
					unit={ paddingUnit }
					onChange={ ( value ) => setAttributes( { padding: value } ) }
					onUnitChange={ ( value ) => setAttributes( { paddingUnit: value } ) }
				/>
				<SpacingControl
					label={ __( 'Margin', 'wpmediaverse' ) }
					values={ margin }
					unit={ marginUnit }
					onChange={ ( value ) => setAttributes( { margin: value } ) }
					onUnitChange={ ( value ) => setAttributes( { marginUnit: value } ) }
				/>
			</PanelBody>

			<PanelBody title={ __( 'Border', 'wpmediaverse' ) } initialOpen={ false }>
				<BorderRadiusControl
					values={ borderRadius }
					unit={ borderRadiusUnit }
					onChange={ ( value ) => setAttributes( { borderRadius: value } ) }
					onUnitChange={ ( value ) => setAttributes( { borderRadiusUnit: value } ) }
				/>
			</PanelBody>

			<PanelBody title={ __( 'Shadow', 'wpmediaverse' ) } initialOpen={ false }>
				<BoxShadowControl
					enabled={ boxShadow }
					horizontal={ shadowHorizontal }
					vertical={ shadowVertical }
					blur={ shadowBlur }
					spread={ shadowSpread }
					color={ shadowColor }
					onChange={ setAttributes }
				/>
			</PanelBody>

			<PanelBody title={ __( 'Visibility', 'wpmediaverse' ) } initialOpen={ false }>
				<DeviceVisibility
					hideOnDesktop={ hideOnDesktop }
					hideOnTablet={ hideOnTablet }
					hideOnMobile={ hideOnMobile }
					onChange={ ( value ) => setAttributes( value ) }
				/>
			</PanelBody>
		</>
	);
}
