import { __ } from '@wordpress/i18n';
import { ToggleControl, BaseControl } from '@wordpress/components';

export default function DeviceVisibility( {
	hideOnDesktop = false,
	hideOnTablet = false,
	hideOnMobile = false,
	onChange,
} ) {
	return (
		<BaseControl
			label={ __( 'Device Visibility', 'wpmediaverse' ) }
			className="mvs-device-visibility"
		>
			<ToggleControl
				label={ __( 'Hide on Desktop', 'wpmediaverse' ) }
				checked={ hideOnDesktop }
				onChange={ ( val ) => onChange( { hideOnDesktop: val } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Hide on Tablet', 'wpmediaverse' ) }
				checked={ hideOnTablet }
				onChange={ ( val ) => onChange( { hideOnTablet: val } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Hide on Mobile', 'wpmediaverse' ) }
				checked={ hideOnMobile }
				onChange={ ( val ) => onChange( { hideOnMobile: val } ) }
				__nextHasNoMarginBottom
			/>
		</BaseControl>
	);
}
