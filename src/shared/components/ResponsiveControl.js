import { __ } from '@wordpress/i18n';
import { ButtonGroup, Button, BaseControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { desktop, tablet, mobile } from '@wordpress/icons';

const DEVICES = [
	{ label: __( 'Desktop', 'wpmediaverse' ), value: 'Desktop', icon: desktop },
	{ label: __( 'Tablet', 'wpmediaverse' ), value: 'Tablet', icon: tablet },
	{ label: __( 'Mobile', 'wpmediaverse' ), value: 'Mobile', icon: mobile },
];

export default function ResponsiveControl( { label, children, device, onDeviceChange } ) {
	const currentDevice = useSelect( ( select ) => {
		const { getDeviceType } = select( 'core/editor' ) || {};
		return getDeviceType ? getDeviceType() : 'Desktop';
	}, [] );

	const { setDeviceType } = useDispatch( 'core/editor' ) || {};

	const activeDevice = device || currentDevice;

	const handleDeviceChange = ( newDevice ) => {
		if ( onDeviceChange ) {
			onDeviceChange( newDevice );
		}
		if ( setDeviceType ) {
			setDeviceType( newDevice );
		}
	};

	return (
		<BaseControl
			label={ label }
			className="mvs-responsive-control"
		>
			<div className="mvs-responsive-control__header">
				<ButtonGroup className="mvs-responsive-control__devices">
					{ DEVICES.map( ( { label: deviceLabel, value, icon } ) => (
						<Button
							key={ value }
							icon={ icon }
							label={ deviceLabel }
							isPressed={ activeDevice === value }
							onClick={ () => handleDeviceChange( value ) }
							size="small"
						/>
					) ) }
				</ButtonGroup>
			</div>
			<div className="mvs-responsive-control__content">
				{ typeof children === 'function'
					? children( activeDevice )
					: children }
			</div>
		</BaseControl>
	);
}
