import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	__experimentalNumberControl as NumberControl,
	Button,
	SelectControl,
	Flex,
	FlexItem,
	BaseControl,
} from '@wordpress/components';
import { link, linkOff } from '@wordpress/icons';

const SIDES = [
	{ key: 'top', label: __( 'Top', 'wpmediaverse' ) },
	{ key: 'right', label: __( 'Right', 'wpmediaverse' ) },
	{ key: 'bottom', label: __( 'Bottom', 'wpmediaverse' ) },
	{ key: 'left', label: __( 'Left', 'wpmediaverse' ) },
];

const UNITS = [
	{ label: 'px', value: 'px' },
	{ label: 'em', value: 'em' },
	{ label: 'rem', value: 'rem' },
	{ label: '%', value: '%' },
];

export default function SpacingControl( {
	label,
	values = { top: 0, right: 0, bottom: 0, left: 0 },
	unit = 'px',
	onChange,
	onUnitChange,
} ) {
	const [ linked, setLinked ] = useState( true );

	const handleChange = ( side, val ) => {
		const num = val !== '' ? Number( val ) : 0;
		if ( linked ) {
			onChange( { top: num, right: num, bottom: num, left: num } );
		} else {
			onChange( { ...values, [ side ]: num } );
		}
	};

	return (
		<BaseControl label={ label } className="mvs-spacing-control">
			<Flex align="flex-end" gap={ 2 }>
				{ linked ? (
					<FlexItem>
						<NumberControl
							label={ __( 'All', 'wpmediaverse' ) }
							value={ values.top }
							onChange={ ( val ) => handleChange( 'top', val ) }
							min={ -200 }
							max={ 500 }
							hideLabelFromVision
						/>
					</FlexItem>
				) : (
					SIDES.map( ( { key, label: sideLabel } ) => (
						<FlexItem key={ key }>
							<NumberControl
								label={ sideLabel }
								value={ values[ key ] }
								onChange={ ( val ) => handleChange( key, val ) }
								min={ -200 }
								max={ 500 }
								hideLabelFromVision
								placeholder={ sideLabel[ 0 ] }
							/>
						</FlexItem>
					) )
				) }
				<FlexItem>
					<Button
						icon={ linked ? link : linkOff }
						label={ linked
							? __( 'Unlink sides', 'wpmediaverse' )
							: __( 'Link sides', 'wpmediaverse' )
						}
						onClick={ () => setLinked( ! linked ) }
						isPressed={ linked }
						size="small"
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						value={ unit }
						options={ UNITS }
						onChange={ onUnitChange }
						hideLabelFromVision
						label={ __( 'Unit', 'wpmediaverse' ) }
						__nextHasNoMarginBottom
					/>
				</FlexItem>
			</Flex>
		</BaseControl>
	);
}
