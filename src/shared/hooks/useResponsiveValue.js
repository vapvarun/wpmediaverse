import { useSelect } from '@wordpress/data';

/**
 * Get the current editor preview device type.
 *
 * @return {string} 'Desktop', 'Tablet', or 'Mobile'.
 */
export function useDeviceType() {
	return useSelect( ( select ) => {
		const { getDeviceType } = select( 'core/editor' ) || {};
		return getDeviceType ? getDeviceType() : 'Desktop';
	}, [] );
}

/**
 * Get the responsive value based on the current device preview.
 * Falls back from mobile -> tablet -> desktop.
 *
 * @param {*} desktop - Desktop value.
 * @param {*} tablet  - Tablet value (optional).
 * @param {*} mobile  - Mobile value (optional).
 * @return {*} The value for the current device.
 */
export function useResponsiveValue( desktop, tablet, mobile ) {
	const deviceType = useDeviceType();

	switch ( deviceType ) {
		case 'Mobile':
			return mobile ?? tablet ?? desktop;
		case 'Tablet':
			return tablet ?? desktop;
		default:
			return desktop;
	}
}
